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

export function verifyWhoami(result, expectedActor) {
  const identity = structuredToolResult(result, 'projeqtor_whoami');
  if (identity.username !== expectedActor) {
    throw new Error(`projeqtor_whoami actor mismatch: expected ${expectedActor}`);
  }
  return identity;
}
