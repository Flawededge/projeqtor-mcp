import { createRemoteJWKSet, jwtVerify } from 'jose';
import { createHash } from 'node:crypto';

const DEFAULT_SCOPES = Object.freeze(['projeqtor:read', 'projeqtor:write']);
export const CLAIM_NAMESPACE = 'https://conceptpower.ddns.net/projeqtor/';
const LOGIN_SCOPES = Object.freeze(['openid', 'email', 'offline_access']);

function requiredUrl(value, name) {
  if (!value) throw new Error(`${name} is required when MCP_OAUTH_ENABLED=true`);
  let url;
  try {
    url = new URL(value);
  } catch {
    throw new Error(`${name} must be an absolute URL`);
  }
  if (url.protocol !== 'https:') throw new Error(`${name} must use https`);
  if (url.username || url.password || url.search || url.hash || value !== value.trim()) {
    throw new Error(`${name} must not contain credentials, query, fragment or whitespace`);
  }
  return value;
}

function jwksUrl(value, name) {
  if (!value) throw new Error(`${name} is required when MCP_OAUTH_ENABLED=true`);
  let url;
  try {
    url = new URL(value);
  } catch {
    throw new Error(`${name} must be an absolute URL`);
  }
  const privateHttpHosts = new Set(['localhost', '127.0.0.1', '[::1]']);
  if (url.protocol !== 'https:' && !(url.protocol === 'http:' && privateHttpHosts.has(url.hostname))) {
    throw new Error(`${name} must use https or a loopback test address`);
  }
  return url.toString();
}

function requiredClientId(value) {
  if (!/^[A-Za-z0-9_-]{1,128}$/.test(value ?? '')) throw new Error('MCP_OAUTH_CLIENT_ID is required and must be an Auth0 client ID');
  return value;
}

function normalizedDomains(value) {
  const domains = [...new Set(String(value ?? '')
    .split(',')
    .map(domain => domain.trim().toLowerCase().replace(/^@/, ''))
    .filter(Boolean))];
  if (domains.length === 0 || domains.some(domain => !/^[a-z0-9.-]+\.[a-z]{2,}$/i.test(domain))) {
    throw new Error('MCP_OAUTH_ALLOWED_DOMAINS must contain one or more DNS domains');
  }
  return domains;
}

function normalizedScopes(value) {
  const scopes = [...new Set(String(value ?? DEFAULT_SCOPES.join(' '))
    .split(/[\s,]+/)
    .map(scope => scope.trim())
    .filter(Boolean))];
  if (scopes.length === 0) throw new Error('MCP_OAUTH_REQUIRED_SCOPES must not be empty');
  return scopes;
}

function resourceMetadataUrl(resource) {
  const url = new URL(resource);
  const path = url.pathname === '/' ? '' : url.pathname.replace(/\/$/, '');
  url.pathname = `/.well-known/oauth-protected-resource${path}`;
  return url.toString();
}

export function loadOAuthConfig(env = process.env) {
  const enabled = String(env.MCP_OAUTH_ENABLED ?? 'false').toLowerCase() === 'true';
  if (!enabled) return Object.freeze({ enabled: false });
  const issuer = requiredUrl(env.MCP_OAUTH_ISSUER, 'MCP_OAUTH_ISSUER');
  const clockToleranceSeconds = Number(env.MCP_OAUTH_CLOCK_TOLERANCE_SECONDS ?? '5');
  if (!Number.isInteger(clockToleranceSeconds) || clockToleranceSeconds < 0 || clockToleranceSeconds > 60) {
    throw new Error('MCP_OAUTH_CLOCK_TOLERANCE_SECONDS must be an integer between 0 and 60');
  }
  return Object.freeze({
    enabled: true,
    issuer,
    resource: requiredUrl(env.MCP_OAUTH_RESOURCE, 'MCP_OAUTH_RESOURCE'),
    metadataUrl: resourceMetadataUrl(requiredUrl(env.MCP_OAUTH_RESOURCE, 'MCP_OAUTH_RESOURCE')),
    jwksUrl: jwksUrl(env.MCP_OAUTH_JWKS_URL ?? new URL('.well-known/jwks.json', `${issuer.replace(/\/$/, '')}/`).href, 'MCP_OAUTH_JWKS_URL'),
    clientId: requiredClientId(env.MCP_OAUTH_CLIENT_ID),
    allowedDomains: Object.freeze(normalizedDomains(env.MCP_OAUTH_ALLOWED_DOMAINS)),
    requiredScopes: Object.freeze(normalizedScopes(env.MCP_OAUTH_REQUIRED_SCOPES)),
    clockToleranceSeconds
  });
}

function claimString(payload, names) {
  for (const name of names) {
    const value = payload?.[name];
    if (typeof value === 'string' && value.trim()) return value.trim();
  }
  return null;
}

function tokenScopes(payload) {
  const values = [];
  if (typeof payload?.scope === 'string') values.push(...payload.scope.split(/\s+/));
  if (Array.isArray(payload?.scp)) values.push(...payload.scp);
  else if (typeof payload?.scp === 'string') values.push(...payload.scp.split(/\s+/));
  return [...new Set(values.filter(Boolean))];
}

export function validateOAuthClaims(payload, protectedHeader, config) {
  if (!config?.enabled) throw new Error('OAuth is disabled');
  if (protectedHeader?.alg !== 'RS256') throw new Error('Only RS256 access tokens are accepted');

  const subject = payload?.sub;
  if (typeof subject !== 'string' || !subject || subject.length > 255 || /[\s\x00-\x1f\x7f]/.test(subject)) {
    throw new Error('The token lacks an immutable Auth0 subject');
  }
  if (payload.azp !== config.clientId) throw new Error('The token belongs to the wrong OAuth client');
  if (!Number.isFinite(payload.iat) || !Number.isFinite(payload.exp) || payload.exp <= payload.iat ||
      payload.iat > Date.now() / 1000 + config.clockToleranceSeconds) throw new Error('Invalid token timestamps');
  const email = claimString(payload, [`${CLAIM_NAMESPACE}email`]);
  if (payload[`${CLAIM_NAMESPACE}email_verified`] !== true || !email || email.length > 100 ||
      !/^[^\s@]+@[^\s@]+$/.test(email)) throw new Error('A verified email is required');
  const normalizedEmail = email.toLowerCase();
  if (!config.allowedDomains.includes(normalizedEmail.split('@')[1])) throw new Error('The email domain is not eligible');
  const displayName = (claimString(payload, ['name']) ?? normalizedEmail).slice(0, 100);

  const scopes = tokenScopes(payload);
  const missing = config.requiredScopes.filter(scope => !scopes.includes(scope));
  if (missing.length) throw new Error(`The token lacks required scopes: ${missing.join(', ')}`);

  return Object.freeze({
    username: `auth0-${createHash('sha256').update(`${config.issuer}\0${subject}`).digest('hex')}`,
    displayName,
    email: normalizedEmail,
    scopes: Object.freeze(scopes),
    authenticationProvider: 'auth0'
  });
}

export function protectedResourceMetadata(config) {
  return {
    resource: config.resource,
    authorization_servers: [config.issuer],
    bearer_methods_supported: ['header'],
    scopes_supported: [...config.requiredScopes, ...LOGIN_SCOPES],
    resource_name: 'ProjeQtOr MCP'
  };
}

export function oauthChallenge(config, error = 'invalid_token', description) {
  const fields = [
    `resource_metadata="${config.metadataUrl}"`,
    `scope="${[...config.requiredScopes, ...LOGIN_SCOPES].join(' ')}"`,
    `error="${String(error).replace(/["\\]/g, '')}"`
  ];
  if (description) fields.push(`error_description="${String(description).replace(/["\\]/g, '')}"`);
  return `Bearer ${fields.join(', ')}`;
}

export function createOAuthAuthenticator(config, options = {}) {
  if (!config?.enabled) return null;
  const jwks = options.jwks ?? createRemoteJWKSet(new URL(config.jwksUrl), {
    timeoutDuration: 5000,
    cooldownDuration: 30000,
    cacheMaxAge: 600000
  });
  const verify = options.jwtVerify ?? jwtVerify;
  return async authorization => {
    if (typeof authorization !== 'string' || !authorization.startsWith('Bearer ')) return null;
    const token = authorization.slice(7);
    if (!token || token.length > 16384) return null;
    const { payload, protectedHeader } = await verify(token, jwks, {
      issuer: config.issuer,
      audience: config.resource,
      algorithms: ['RS256'],
      clockTolerance: config.clockToleranceSeconds,
      requiredClaims: ['exp', 'iat', 'sub', 'azp', `${CLAIM_NAMESPACE}email`, `${CLAIM_NAMESPACE}email_verified`]
    });
    return validateOAuthClaims(payload, protectedHeader, config);
  };
}
