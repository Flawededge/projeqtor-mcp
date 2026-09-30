import { createRemoteJWKSet, jwtVerify } from 'jose';

const DEFAULT_SCOPES = Object.freeze(['projeqtor:read', 'projeqtor:write']);
const ENTRA_OBJECT_ID = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

function requiredUrl(value, name) {
  if (!value) throw new Error(`${name} is required when MCP_OAUTH_ENABLED=true`);
  let url;
  try {
    url = new URL(value);
  } catch {
    throw new Error(`${name} must be an absolute URL`);
  }
  if (url.protocol !== 'https:') throw new Error(`${name} must use https`);
  return url.toString().replace(/\/$/, '');
}

function jwksUrl(value, name) {
  if (!value) throw new Error(`${name} is required when MCP_OAUTH_ENABLED=true`);
  let url;
  try {
    url = new URL(value);
  } catch {
    throw new Error(`${name} must be an absolute URL`);
  }
  const privateHttpHosts = new Set(['keycloak', 'localhost', '127.0.0.1', '[::1]']);
  if (url.protocol !== 'https:' && !(url.protocol === 'http:' && privateHttpHosts.has(url.hostname))) {
    throw new Error(`${name} must use https or the isolated keycloak service name`);
  }
  return url.toString();
}

function requiredUuid(value, name) {
  if (!ENTRA_OBJECT_ID.test(value ?? '')) throw new Error(`${name} must be a UUID`);
  return value.toLowerCase();
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
  return Object.freeze({
    enabled: true,
    issuer,
    resource: requiredUrl(env.MCP_OAUTH_RESOURCE, 'MCP_OAUTH_RESOURCE'),
    metadataUrl: resourceMetadataUrl(requiredUrl(env.MCP_OAUTH_RESOURCE, 'MCP_OAUTH_RESOURCE')),
    jwksUrl: jwksUrl(env.MCP_OAUTH_JWKS_URL ?? `${issuer}/protocol/openid-connect/certs`, 'MCP_OAUTH_JWKS_URL'),
    entraTenantId: requiredUuid(env.MCP_OAUTH_ENTRA_TENANT_ID, 'MCP_OAUTH_ENTRA_TENANT_ID'),
    allowedDomains: Object.freeze(normalizedDomains(env.MCP_OAUTH_ALLOWED_DOMAINS)),
    requiredScopes: Object.freeze(normalizedScopes(env.MCP_OAUTH_REQUIRED_SCOPES)),
    clockToleranceSeconds: Number.parseInt(env.MCP_OAUTH_CLOCK_TOLERANCE_SECONDS ?? '5', 10)
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

  const objectId = claimString(payload, ['entra_oid']);
  const tenantId = claimString(payload, ['entra_tid']);
  const email = claimString(payload, ['upn', 'preferred_username', 'email']);
  const displayName = claimString(payload, ['name', 'display_name']) ?? email;

  if (!ENTRA_OBJECT_ID.test(objectId ?? '')) throw new Error('The token lacks an immutable Microsoft object ID');
  if ((tenantId ?? '').toLowerCase() !== config.entraTenantId) throw new Error('The token belongs to the wrong Microsoft tenant');
  if (!email || email.includes('#EXT#')) throw new Error('Guest and unidentified Microsoft users are not eligible');

  const normalizedEmail = email.toLowerCase();
  const eligible = config.allowedDomains.some(domain => normalizedEmail.endsWith(`@${domain}`));
  if (!eligible) throw new Error('The Microsoft account domain is not eligible');

  const scopes = tokenScopes(payload);
  const missing = config.requiredScopes.filter(scope => !scopes.includes(scope));
  if (missing.length) throw new Error(`The token lacks required scopes: ${missing.join(', ')}`);

  return Object.freeze({
    username: `entra-${objectId.toLowerCase()}`,
    displayName,
    email: normalizedEmail,
    scopes: Object.freeze(scopes),
    authenticationProvider: 'microsoft'
  });
}

export function protectedResourceMetadata(config) {
  return {
    resource: config.resource,
    authorization_servers: [config.issuer],
    bearer_methods_supported: ['header'],
    scopes_supported: [...config.requiredScopes],
    resource_name: 'ProjeQtOr MCP'
  };
}

export function oauthChallenge(config, error = 'invalid_token', description) {
  const fields = [
    `resource_metadata="${config.metadataUrl}"`,
    `scope="${config.requiredScopes.join(' ')}"`,
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
      requiredClaims: ['exp', 'iat', 'entra_oid', 'entra_tid']
    });
    return validateOAuthClaims(payload, protectedHeader, config);
  };
}
