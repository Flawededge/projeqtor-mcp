function structured(result) {
  return result?.structuredContent ?? result?.content?.find(item => item.type === 'text' && item.text)?.text;
}

function failureMessage(label, document) {
  const error = document?.error;
  const code = typeof error?.code === 'string' ? error.code : 'tool_error';
  const message = typeof error?.message === 'string' ? `: ${error.message}` : '';
  return `${label} failed (${code})${message}`;
}

export function structuredToolResult(result, label = 'MCP tool') {
  const value = structured(result);
  let document;
  try {
    document = typeof value === 'string' ? JSON.parse(value) : value;
  } catch {
    throw new Error(`${label} returned malformed JSON`);
  }
  if (!document || typeof document !== 'object' || Array.isArray(document)) {
    throw new Error(`${label} returned no structured object`);
  }
  if (result?.isError || document.ok === false || document.error) {
    throw new Error(failureMessage(label, document));
  }
  return document;
}

export function structuredToolError(result, expectedCode, label = 'MCP tool') {
  const value = structured(result);
  let document;
  try {
    document = typeof value === 'string' ? JSON.parse(value) : value;
  } catch {
    throw new Error(`${label} returned malformed JSON while an error was expected`);
  }
  if (!document || typeof document !== 'object' || Array.isArray(document)) {
    throw new Error(`${label} returned no structured error object`);
  }
  if (!result?.isError && document.ok !== false && !document.error) {
    throw new Error(`${label} unexpectedly succeeded`);
  }
  if (!document.error || typeof document.error.code !== 'string') {
    throw new Error(`${label} returned an unstructured error`);
  }
  if (expectedCode && document.error.code !== expectedCode) {
    throw new Error(`${label} failed with ${document.error.code}, expected ${expectedCode}`);
  }
  return document.error;
}

export function verifyWhoami(result, expectedActor) {
  const identity = structuredToolResult(result, 'projeqtor_whoami');
  if (identity.username !== expectedActor) {
    throw new Error(`projeqtor_whoami actor mismatch: expected ${expectedActor}`);
  }
  return identity;
}
