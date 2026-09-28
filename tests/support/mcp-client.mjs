import { readFile } from 'node:fs/promises';
import { redactString, sanitize } from './redact.mjs';

export const TEST_ACTOR_TOKEN_ENV = Object.freeze({
  'beta4-admin': 'PROJEQTOR_TEST_ADMIN_TOKEN_FILE',
  'beta4-manager': 'PROJEQTOR_TEST_MANAGER_TOKEN_FILE',
  'beta4-member': 'PROJEQTOR_TEST_MEMBER_TOKEN_FILE',
  'beta4-denied': 'PROJEQTOR_TEST_DENIED_TOKEN_FILE'
});

function parseSse(text) {
  const data = text.split(/\r?\n/).filter(line => line.startsWith('data:')).map(line => line.slice(5).trim()).join('');
  return data ? JSON.parse(data) : null;
}

export class McpTestClient {
  constructor({ url, token, fetchImpl = fetch, timeoutMs = 30_000, logger = () => {} }) {
    this.url = new URL(url);
    if (!['http:', 'https:'].includes(this.url.protocol)) throw new Error('MCP test URL must use HTTP or HTTPS');
    this.token = token;
    this.fetch = fetchImpl;
    this.timeoutMs = timeoutMs;
    this.logger = event => logger(sanitize(event));
    this.id = 0;
    this.sessionId = null;
  }

  static async fromEnvironment() {
    const tokenFile = process.env.PROJEQTOR_TEST_TOKEN_FILE ?? process.env.PROJEQTOR_TEST_ADMIN_TOKEN_FILE;
    if (!tokenFile) throw new Error('PROJEQTOR_TEST_TOKEN_FILE is required');
    const token = (await readFile(tokenFile, 'utf8')).trim();
    if (!token) throw new Error('MCP test token file is empty');
    return new McpTestClient({ url: process.env.PROJEQTOR_MCP_URL, token });
  }

  static async forActor(actor, options = {}) {
    const environmentName = TEST_ACTOR_TOKEN_ENV[actor];
    if (!environmentName) throw new Error(`Unsupported disposable MCP actor ${actor}`);
    const tokenFile = options.tokenFile ?? process.env[environmentName];
    if (!tokenFile) throw new Error(`${environmentName} is required`);
    const token = (await readFile(tokenFile, 'utf8')).trim();
    if (!token) throw new Error(`Disposable MCP token file for ${actor} is empty`);
    return new McpTestClient({
      url: options.url ?? process.env.PROJEQTOR_MCP_URL,
      token,
      fetchImpl: options.fetchImpl,
      timeoutMs: options.timeoutMs,
      logger: options.logger
    });
  }

  async request(method, params = {}, notification = false) {
    const id = notification ? undefined : ++this.id;
    const request = { jsonrpc: '2.0', ...(id === undefined ? {} : { id }), method, params };
    this.logger({ direction: 'request', method, id });
    const headers = {
      Accept: 'application/json, text/event-stream',
      'Content-Type': 'application/json',
      Authorization: `Bearer ${this.token}`,
      Origin: this.url.origin
    };
    if (this.sessionId) headers['Mcp-Session-Id'] = this.sessionId;
    const response = await this.fetch(this.url, {
      method: 'POST', headers, body: JSON.stringify(request), signal: AbortSignal.timeout(this.timeoutMs)
    });
    this.sessionId = response.headers.get('mcp-session-id') ?? this.sessionId;
    if (notification && response.status === 202) return null;
    const text = await response.text();
    let message;
    try {
      message = response.headers.get('content-type')?.includes('text/event-stream') ? parseSse(text) : JSON.parse(text);
    } catch {
      throw new Error(`MCP returned non-JSON HTTP ${response.status}: ${redactString(text).slice(0, 200)}`);
    }
    if (!response.ok) throw new Error(`MCP HTTP ${response.status}: ${redactString(message?.error?.message ?? 'request failed')}`);
    if (message?.error) throw new Error(`MCP ${message.error.code}: ${redactString(message.error.message)}`);
    this.logger({ direction: 'response', method, id, ok: true });
    return message?.result;
  }

  async initialize() {
    const result = await this.request('initialize', {
      protocolVersion: '2025-03-26', capabilities: {}, clientInfo: { name: 'projeqtor-beta4-tests', version: '2.0.0-beta.4' }
    });
    await this.request('notifications/initialized', {}, true);
    return result;
  }

  tools() { return this.request('tools/list'); }
  callTool(name, args = {}) { return this.request('tools/call', { name, arguments: args }); }
}
