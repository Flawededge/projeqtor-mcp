import { createHash, createHmac, timingSafeEqual } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { serve } from '@hono/node-server';
import { createMcpHonoApp } from '@modelcontextprotocol/hono';
import { createMcpHandler } from '@modelcontextprotocol/server';
import { DomainError, cleanApiMessage } from './domain.mjs';
import { SERVER_VERSION, LIMITS } from './contracts.mjs';
import { createProjeqtorServer } from './tools.mjs';
import {
  createOAuthAuthenticator,
  loadOAuthConfig,
  oauthChallenge,
  protectedResourceMetadata
} from './oauth.mjs';

const port = Number.parseInt(process.env.PORT ?? '3000', 10);
const apiBase = (process.env.PROJEQTOR_API_URL ?? 'http://app/mcp-api').replace(/\/$/, '');
const signingKey = readSecret('PROJEQTOR_SIGNING_KEY_FILE');
const principals = readPrincipals('MCP_USERS_FILE');
const allowedHosts = readList('MCP_ALLOWED_HOSTS', ['projeqtor', 'mcp', 'localhost', '127.0.0.1']);
const allowedOrigins = readList('MCP_ALLOWED_ORIGINS', ['projeqtor', 'mcp', 'localhost', '127.0.0.1']);
const oauthConfig = loadOAuthConfig();
const authenticateOAuth = createOAuthAuthenticator(oauthConfig);

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
    return { username: entry.username, tokenSha256: Buffer.from(entry.tokenSha256, 'hex') };
  });
}

function authenticate(authorization) {
  if (typeof authorization !== 'string' || !authorization.startsWith('Bearer ')) return null;
  const token = authorization.slice(7);
  if (!token || token.length > 4096) return null;
  const digest = createHash('sha256').update(token, 'utf8').digest();
  const principal = principals.find(entry => timingSafeEqual(digest, entry.tokenSha256));
  if (!principal) return null;
  return { username: principal.username, digest: digest.toString('hex') };
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
function oauthError(status, config, error, description) {
  return new Response(JSON.stringify({ error, error_description: description }), {
    status,
    headers: {
      'Content-Type': 'application/json; charset=utf-8',
      'WWW-Authenticate': oauthChallenge(config, error, description),
      'Cache-Control': 'no-store'
    }
  });
}


async function apiRequest(path, username, method = 'GET', body, options = {}) {
  const timeoutMs = options.timeoutMs ?? (path === '__mcp/v2/actions/commit' ? 900_000 : (['__mcp/v2/operations/execute', '__mcp/v2/changes/commit'].includes(path) ? 300_000 : 30_000));
  const payload = body === undefined ? '' : JSON.stringify(body);
  const timestamp = String(Math.floor(Date.now() / 1000));
  const bodyDigest = createHash('sha256').update(payload, 'utf8').digest('hex');
  const signature = createHmac('sha256', signingKey)
    .update(`${timestamp}\n${username}\n${method}\n${path}\n${bodyDigest}`, 'utf8')
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
    signal: AbortSignal.timeout(timeoutMs)
  });
  const text = await response.text();
  let data;
  try {
    data = JSON.parse(text);
  } catch {
    throw new DomainError('invalid_api_response', 'ProjeQtOr API returned invalid JSON', { httpStatus: response.status });
  }
  if (data.error) {
    const bridgeError = typeof data.error === 'object' && data.error !== null ? data.error : {};
    const { code, message, ...details } = bridgeError;
    throw new DomainError(
      typeof code === 'string' ? code : 'api_error',
      cleanApiMessage(typeof message === 'string' ? message : (data.message ?? String(data.error))),
      { httpStatus: response.status, ...details }
    );
  }
  if (!response.ok) {
    throw new DomainError('api_http_error', `ProjeQtOr API returned HTTP ${response.status}`, { httpStatus: response.status });
  }
  if (!options.allowItemErrors && Array.isArray(data.items)) {
    const failed = data.items.find(item => item?.apiResult && item.apiResult !== 'OK');
    if (failed) throw new DomainError('validation_failed', cleanApiMessage(failed.apiResultMessage));
  }
  return data;
}

function buildServer(context) {
  const username = context.authInfo?.extra?.projeqtorUsername;
  if (typeof username !== 'string') throw new Error('Authenticated ProjeQtOr identity is missing');
  return createProjeqtorServer({ username, apiRequest });
}

const handler = createMcpHandler(buildServer, { responseMode: 'json' });
const app = createMcpHonoApp({
  host: '0.0.0.0',
  allowedHosts,
  allowedOrigins,
  maxRequestBodySize: LIMITS.requestBytes
});

app.get('/health', context => context.json({ status: 'ok', mode: 'full-control', version: SERVER_VERSION }));
app.get('/.well-known/oauth-protected-resource/mcp_projeqtor', context => {
  if (!oauthConfig.enabled) return context.json({ error: 'OAuth is not configured' }, 404);
  return context.json(protectedResourceMetadata(oauthConfig), 200, { 'Cache-Control': 'public, max-age=300' });
});
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
app.all('/mcp/oauth', async context => {
  if (!oauthConfig.enabled || !authenticateOAuth) {
    return new Response(JSON.stringify({ error: 'temporarily_unavailable' }), {
      status: 503,
      headers: { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' }
    });
  }
  let principal;
  try {
    principal = await authenticateOAuth(context.req.header('authorization'));
  } catch {
    return oauthError(401, oauthConfig, 'invalid_token', 'The OAuth access token is invalid or no longer eligible');
  }
  if (!principal) return oauthError(401, oauthConfig, 'invalid_token', 'An Auth0 OAuth access token is required');

  try {
    await apiRequest('__mcp/v2/oauth/provision', principal.username, 'POST', {
      username: principal.username,
      displayName: principal.displayName,
      email: principal.email,
      provider: principal.authenticationProvider
    });
  } catch (error) {
    const status = error instanceof DomainError && error.code === 'oauth_user_unavailable' ? 403 : 502;
    return oauthError(status, oauthConfig, status === 403 ? 'insufficient_scope' : 'temporarily_unavailable',
      status === 403 ? 'The mapped ProjeQtOr user is unavailable' : 'ProjeQtOr account resolution failed');
  }

  return handler.fetch(context.req.raw, {
    parsedBody: context.get('parsedBody'),
    authInfo: {
      token: 'oauth-redacted', clientId: oauthConfig.clientId, scopes: principal.scopes,
      extra: { projeqtorUsername: principal.username, authenticationProvider: principal.authenticationProvider }
    }
  });
});

serve({ fetch: app.fetch, port, hostname: '0.0.0.0' }, () => {
  console.error(`ProjeQtOr MCP listening on port ${port} in per-user read-write mode`);
});
