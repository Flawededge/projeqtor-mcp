import { structuredToolResult } from './tool-results.mjs';

export function actionsFromListResult(result) {
  const document = structuredToolResult(result, 'projeqtor_list_actions');
  const items = document.items ?? document.actions;
  if (!Array.isArray(items) || !Number.isInteger(document.returned) || document.returned !== items.length) {
    throw new Error('projeqtor_list_actions returned a malformed action page');
  }
  if (document.hasMore !== false || document.nextCursor != null) {
    throw new Error('projeqtor_list_actions returned an incomplete paginated result');
  }
  const actionIds = items.map(item => item?.action ?? item?.id ?? item?.name);
  if (actionIds.some(action => typeof action !== 'string' || action.length === 0)) {
    throw new Error('projeqtor_list_actions returned an action without an identifier');
  }
  if (new Set(actionIds).size !== actionIds.length) {
    throw new Error('projeqtor_list_actions returned duplicate action identifiers');
  }
  return items.map((item, index) => ({ ...item, action: actionIds[index] }));
}

export function actionIdsFromListResult(result) {
  return actionsFromListResult(result).map(item => item.action);
}
