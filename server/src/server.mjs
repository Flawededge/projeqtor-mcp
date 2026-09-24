import { createHash, createHmac, timingSafeEqual } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { serve } from '@hono/node-server';
import { createMcpHonoApp } from '@modelcontextprotocol/hono';
import { createMcpHandler, McpServer } from '@modelcontextprotocol/server';
import * as z from 'zod/v4';

const port = Number.parseInt(process.env.PORT ?? '3000', 10);
const apiBase = (process.env.PROJEQTOR_API_URL ?? 'http://app/mcp-api').replace(/\/$/, '');
const signingKey = readSecret('PROJEQTOR_SIGNING_KEY_FILE');
const principals = readPrincipals('MCP_USERS_FILE');
const allowedHosts = readList('MCP_ALLOWED_HOSTS', ['projeqtor', 'mcp', 'localhost', '127.0.0.1']);
const allowedOrigins = readList('MCP_ALLOWED_ORIGINS', ['projeqtor', 'mcp', 'localhost', '127.0.0.1']);

const readClasses = [
  'Action', 'Activity', 'ActivityType', 'Affectation', 'Assignment',
  'CalendarDefinition', 'Document', 'Issue', 'Meeting', 'Milestone',
  'Opportunity', 'PlanningMode', 'Project', 'ProjectType', 'Requirement',
  'Resource', 'Risk', 'Role', 'Status', 'TestCase', 'TestSession', 'Ticket',
  'TicketType', 'Version', 'Work'
];
const writeClasses = [
  'Action', 'Activity', 'Affectation', 'Assignment', 'Document', 'Issue',
  'Meeting', 'Milestone', 'Opportunity', 'Project', 'Requirement', 'Resource',
  'Risk', 'TestCase', 'TestSession', 'Ticket', 'Version', 'Work'
];
const readClassSchema = z.enum(readClasses);
const writeClassSchema = z.enum(writeClasses);
const fieldsSchema = z.array(z.string().regex(/^[A-Za-z][A-Za-z0-9_]*$/)).max(40).optional();
const objectDataSchema = z.record(
  z.string().regex(/^[A-Za-z][A-Za-z0-9_]*$/),
  z.unknown()
).refine(value => Object.keys(value).length <= 200, 'At most 200 fields may be supplied');

function readSecret(variable) {
  const path = process.env[variable];
  if (!path) throw new Error(`${variable} is required`);
  const value = readFileSync(path, 'utf8').trim();
  if (!value) throw new Error(`${variable} points to an empty file`);
  return value;
}

function readList(variable, fallback) {
  const value = process.env[variable];
  if (!value) return fallback;
  const entries = [...new Set(value.split(',').map(entry => entry.trim()).filter(Boolean))];
  if (entries.length === 0) throw new Error(`${variable} must contain at least one value`);
  return entries;
}

function readPrincipals(variable) {
  const path = process.env[variable];
  if (!path) throw new Error(`${variable} is required`);

  const document = JSON.parse(readFileSync(path, 'utf8'));
  if (document?.version !== 1 || !Array.isArray(document.users) || document.users.length === 0) {
    throw new Error(`${variable} has an unsupported or empty format`);
  }

  const usernames = new Set();
  return document.users.map(entry => {
    if (!entry || typeof entry.username !== 'string' ||
        !/^[A-Za-z0-9_.@-]{1,100}$/.test(entry.username) ||
        typeof entry.tokenSha256 !== 'string' ||
        !/^[a-f0-9]{64}$/.test(entry.tokenSha256) ||
        usernames.has(entry.username)) {
      throw new Error(`${variable} contains an invalid or duplicate user entry`);
    }
    usernames.add(entry.username);
    return {
      username: entry.username,
      tokenSha256: Buffer.from(entry.tokenSha256, 'hex')
    };
  });
}

function authenticate(authorization) {
  if (typeof authorization !== 'string' || !authorization.startsWith('Bearer ')) return null;
  const token = authorization.slice(7);
  if (!token || token.length > 4096) return null;

  const digest = createHash('sha256').update(token, 'utf8').digest();
  const principal = principals.find(entry => timingSafeEqual(digest, entry.tokenSha256));
  if (!principal) return null;

  return {
    username: principal.username,
    digest: digest.toString('hex')
  };
}

function unauthorized() {
  return new Response(JSON.stringify({ error: 'A valid ProjeQtOr MCP bearer token is required' }), {
    status: 401,
    headers: {
      'Content-Type': 'application/json; charset=utf-8',
      'WWW-Authenticate': 'Bearer realm="projeqtor-mcp"',
      'Cache-Control': 'no-store'
    }
  });
}

function apiPath(objectClass, selector, fields) {
  const encoded = [objectClass, selector].map(encodeURIComponent).join('/');
  if (!fields?.length) return encoded;
  return `${encoded}/select=${fields.join(',')}`;
}

async function apiRequest(path, username, method = 'GET', body) {
  const payload = body === undefined ? '' : JSON.stringify(body);
  const timestamp = String(Math.floor(Date.now() / 1000));
  const bodyDigest = createHash('sha256').update(payload, 'utf8').digest('hex');
  const signature = createHmac('sha256', signingKey)
    .update(`${timestamp}\n${username}\n${method}\n${bodyDigest}`, 'utf8')
    .digest('hex');
  const headers = {
    Accept: 'application/json',
    'X-Projeqtor-User': username,
    'X-Projeqtor-Timestamp': timestamp,
    'X-Projeqtor-Signature': signature
  };
  if (body !== undefined) headers['Content-Type'] = 'application/json';

  const response = await fetch(`${apiBase}/${path}`, {
    method,
    headers,
    body: body === undefined ? undefined : payload,
    signal: AbortSignal.timeout(30_000)
  });
  const text = await response.text();
  if (!response.ok) throw new Error(`ProjeQtOr API returned HTTP ${response.status}`);

  let data;
  try {
    data = JSON.parse(text);
  } catch {
    throw new Error('ProjeQtOr API returned invalid JSON');
  }
  if (data.error) throw new Error(`ProjeQtOr API error: ${data.message ?? data.error}`);
  if (Array.isArray(data.items)) {
    const failed = data.items.find(item => item?.apiResult && item.apiResult !== 'OK');
    if (failed) throw new Error(`ProjeQtOr rejected the change: ${failed.apiResultMessage ?? 'unknown reason'}`);
  }
  return data;
}

function result(value) {
  return {
    content: [{ type: 'text', text: JSON.stringify(value, null, 2) }],
    structuredContent: value
  };
}

function failure(error) {
  const message = error instanceof Error ? error.message : 'Unexpected ProjeQtOr bridge error';
  return { isError: true, content: [{ type: 'text', text: message }] };
}

function activeResourceChoices(data) {
  const items = Array.isArray(data.items) ? data.items : [];
  return items
    .filter(item => Number(item?.idle ?? 0) === 0)
    .map(item => ({ id: item.id, name: item.name }));
}

function buildServer(context) {
  const username = context.authInfo?.extra?.projeqtorUsername;
  if (typeof username !== 'string') throw new Error('Authenticated ProjeQtOr identity is missing');
  const server = new McpServer({ name: 'projeqtor', version: '1.2.0' });

  server.registerTool('projeqtor_get_item', {
    description: 'Get one accessible ProjeQtOr object by class and numeric ID.',
    inputSchema: z.object({
      objectClass: readClassSchema,
      id: z.number().int().positive(),
      fields: fieldsSchema
    }),
    annotations: { readOnlyHint: true, destructiveHint: false }
  }, async ({ objectClass, id, fields }) => {
    try {
      return result(await apiRequest(apiPath(objectClass, String(id), fields), username));
    } catch (error) {
      return failure(error);
    }
  });

  server.registerTool('projeqtor_list_items', {
    description: 'List accessible ProjeQtOr objects from an allow-listed class. Results are capped in the MCP response.',
    inputSchema: z.object({
      objectClass: readClassSchema,
      fields: fieldsSchema,
      maxItems: z.number().int().min(1).max(200).default(50)
    }),
    annotations: { readOnlyHint: true, destructiveHint: false }
  }, async ({ objectClass, fields, maxItems }) => {
    try {
      const data = await apiRequest(apiPath(objectClass, 'all', fields), username);
      const source = Array.isArray(data.items) ? data.items : [];
      const items = source.slice(0, maxItems);
      return result({
        identifier: data.identifier ?? 'id',
        returned: items.length,
        truncated: source.length > items.length,
        items
      });
    } catch (error) {
      return failure(error);
    }
  });

  server.registerTool('projeqtor_list_resource_choices', {
    description: 'List active standard calendars and main functions required when creating or enabling a ProjeQtOr resource.',
    inputSchema: z.object({}),
    annotations: { readOnlyHint: true, destructiveHint: false }
  }, async () => {
    try {
      const [calendarData, roleData] = await Promise.all([
        apiRequest(apiPath('CalendarDefinition', 'all', ['id', 'name', 'idle']), username),
        apiRequest(apiPath('Role', 'all', ['id', 'name', 'idle']), username)
      ]);
      return result({
        calendars: activeResourceChoices(calendarData),
        mainFunctions: activeResourceChoices(roleData)
      });
    } catch (error) {
      return failure(error);
    }
  });

  server.registerTool('projeqtor_create_item', {
    description: 'Create an allow-listed ProjeQtOr project-management object as the authenticated user. Do not include an id.',
    inputSchema: z.object({
      objectClass: writeClassSchema,
      data: objectDataSchema
    }),
    annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false }
  }, async ({ objectClass, data }) => {
    try {
      if (Object.hasOwn(data, 'id')) throw new Error('Create data must not contain id; use projeqtor_update_item');
      return result(await apiRequest(encodeURIComponent(objectClass), username, 'PUT', data));
    } catch (error) {
      return failure(error);
    }
  });

  server.registerTool('projeqtor_update_item', {
    description: 'Update an allow-listed ProjeQtOr project-management object as the authenticated user. Only supplied writable fields are changed.',
    inputSchema: z.object({
      objectClass: writeClassSchema,
      id: z.number().int().positive(),
      changes: objectDataSchema
    }),
    annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true }
  }, async ({ objectClass, id, changes }) => {
    try {
      return result(await apiRequest(encodeURIComponent(objectClass), username, 'POST', { ...changes, id }));
    } catch (error) {
      return failure(error);
    }
  });

  return server;
}

const handler = createMcpHandler(buildServer, { responseMode: 'json' });
const app = createMcpHonoApp({
  host: '0.0.0.0',
  allowedHosts,
  allowedOrigins,
  maxRequestBodySize: 1_048_576
});

app.get('/health', context => context.json({ status: 'ok', mode: 'read-write' }));
app.all('/mcp', context => {
  const principal = authenticate(context.req.header('authorization'));
  if (!principal) return unauthorized();

  return handler.fetch(context.req.raw, {
    parsedBody: context.get('parsedBody'),
    authInfo: {
      token: principal.digest,
      clientId: principal.username,
      scopes: ['projeqtor:read', 'projeqtor:write'],
      extra: { projeqtorUsername: principal.username }
    }
  });
});

serve({ fetch: app.fetch, port, hostname: '0.0.0.0' }, () => {
  console.error(`ProjeQtOr MCP listening on port ${port} in per-user read-write mode`);
});
