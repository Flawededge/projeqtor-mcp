import { createHash } from 'node:crypto';

const SECRET_KEY = /(?:authorization|cookie|password|passwd|secret|signature|token|api[-_]?key|credential|private[-_]?key|smtp)/i;
const OBJECT_KEY = /^(?:data|payload|object|body|content|description|resultObject)$/i;
const BEARER = /\bBearer\s+[A-Za-z0-9._~+/=-]+/gi;
const EMAIL = /\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/gi;
const LONG_SECRET = /\b[a-f0-9]{48,}\b/gi;

export function fingerprint(value) {
  return createHash('sha256').update(String(value)).digest('hex').slice(0, 12);
}

export function redactString(value) {
  return String(value)
    .replace(BEARER, 'Bearer [REDACTED]')
    .replace(EMAIL, '[REDACTED_EMAIL]')
    .replace(LONG_SECRET, '[REDACTED_SECRET]');
}

export function sanitize(value, key = '', seen = new WeakSet()) {
  if (SECRET_KEY.test(key)) return '[REDACTED]';
  if (OBJECT_KEY.test(key) && value && typeof value === 'object') return '[REDACTED_OBJECT]';
  if (typeof value === 'string') return redactString(value);
  if (value === null || typeof value !== 'object') return value;
  if (seen.has(value)) return '[CIRCULAR]';
  seen.add(value);
  if (Array.isArray(value)) return value.map(item => sanitize(item, '', seen));
  const output = {};
  for (const [childKey, childValue] of Object.entries(value)) {
    output[childKey] = sanitize(childValue, childKey, seen);
  }
  return output;
}
