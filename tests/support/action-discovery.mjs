function structured(result) {
  return result?.structuredContent ?? result?.content?.find(item => item.type === 'text' && item.text)?.text;
}

export function actionIdsFromListResult(result) {
  const value = structured(result);
  const document = typeof value === 'string' ? JSON.parse(value) : value;
  return (document?.items ?? document?.actions ?? [])
    .map(item => item.action ?? item.id ?? item.name)
    .filter(Boolean);
}
