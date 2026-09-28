import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { createProjeqtorServer } from '../src/tools.mjs';
import scrum, {
  SCRUM_ACTIONS, SCRUM_HANDLERS, SCRUM_OWNERSHIP_CORRECTIONS
} from '../src/modules/scrum/index.mjs';

const phpDescriptor = () => {
  const source = "function mcpObjectSchema($properties=[],$required=[],$additional=false){return ['type'=>'object','properties'=>$properties,'required'=>$required,'additionalProperties'=>$additional];}"
    + "function mcpActionSpec($schema,$resultSchema,$risk,$async,$callable,$handlers,$test,$options=[]){return array_merge(['schema'=>$schema,'resultSchema'=>$resultSchema,'risk'=>$risk,'async'=>$async,'mappedHandlers'=>$handlers,'testContract'=>$test,'callable'=>$callable],$options);}"
    + 'echo json_encode(require ' + JSON.stringify(new URL('../../bridge/modules/scrum/module.php', import.meta.url).pathname) + ');';
  return JSON.parse(execFileSync('php', ['-r', source], { encoding: 'utf8' }));
};

test('Scrum exposes all requested workflow families and preserves the convenience tool', () => {
  assert.equal(scrum.id, 'scrum');
  assert.deepEqual(scrum.dependencies, ['core', 'planning']);
  assert.deepEqual(scrum.claims.actions, SCRUM_ACTIONS);
  assert.deepEqual(scrum.claims.tools, ['projeqtor_manage_sprint']);
  assert.equal(SCRUM_ACTIONS.length, 15);
  for (const family of ['story', 'backlog', 'sprint', 'kanban', 'poker']) {
    assert.ok(SCRUM_ACTIONS.some(action => action.includes(family)), family);
  }
});

test('Scrum maps all native mutation handlers except explicit Tools note corrections', () => {
  const descriptor = phpDescriptor();
  const mapped = new Set(Object.values(descriptor.actions).flatMap(action => action.mappedHandlers));
  assert.deepEqual(mapped, new Set(SCRUM_HANDLERS));
  assert.equal(mapped.size, 23);
  const manifest = JSON.parse(readFileSync(new URL('../../bridge/ui-handler-policy-v4.json', import.meta.url)));
  const inventoried = new Set(manifest.handlers
    .filter(handler => handler.module === 'scrum' && handler.classification === 'registered_action')
    .map(handler => handler.id)
    .filter(id => !(id in SCRUM_OWNERSHIP_CORRECTIONS)));
  assert.deepEqual(mapped, inventoried);
  assert.deepEqual(SCRUM_OWNERSHIP_CORRECTIONS, {
    'tool:saveNoteStreamBacklog': 'tools',
    'tool:saveNoteStreamKanban': 'tools'
  });
});

test('Scrum action contracts are closed, bounded, typed, and permission-aware', () => {
  const descriptor = phpDescriptor();
  for (const [id, action] of Object.entries(descriptor.actions)) {
    assert.equal(action.schema.type, 'object', id);
    assert.equal(action.schema.additionalProperties, false, id);
    assert.equal(action.resultSchema.type, 'object', id);
    assert.equal(action.resultSchema.additionalProperties, false, id);
    assert.equal(action.availability, 'mcpScrumActionAvailable', id);
    assert.ok(Array.isArray(action.permissionClasses) && action.permissionClasses.length > 0, id);
    assert.ok(['read', 'write', 'destructive'].includes(action.risk), id);
    if (action.batchLimit !== undefined) assert.equal(action.batchLimit, 200, id);
    for (const property of Object.values(action.schema.properties)) {
      if (property?.type === 'array' && property.items?.type === 'object') {
        assert.equal(property.maxItems, 200, id);
      }
    }
    const resultItem = action.resultSchema.properties.items?.items;
    if (resultItem?.properties?.saved && resultItem?.properties?.error) {
      assert.equal(resultItem.properties.saved.additionalProperties, false, id);
      assert.equal(resultItem.properties.error.additionalProperties, false, id);
    }
    if (action.risk === 'destructive') assert.equal(typeof action.preview, 'string', id);
  }
});

test('Scrum requires caller versions for every existing-object mutation', () => {
  const actions = phpDescriptor().actions;
  assert.ok(actions['scrum.backlog.prioritize'].schema.properties.items.items.required.includes('expectedVersion'));
  assert.ok(actions['scrum.sprint.lifecycle'].schema.properties.sprints.items.required.includes('expectedVersion'));
  assert.ok(actions['scrum.kanban.columns.replace'].schema.properties.boards.items.required.includes('expectedVersion'));
  assert.ok(actions['scrum.kanban.card.move'].schema.properties.cards.items.required.includes('expectedVersion'));
  assert.ok(actions['scrum.poker.item.state'].schema.properties.items.items.required.includes('sessionExpectedVersion'));
  assert.ok(actions['scrum.poker.item.state'].schema.properties.items.items.required.includes('itemExpectedVersion'));
  assert.ok(actions['scrum.poker.vote.visibility'].schema.properties.items.items.required.includes('expectedVoteSetVersion'));
  const itemSchema = actions['scrum.poker.item.manage'].schema.properties.items.items.properties;
  assert.ok(itemSchema.expectedVersion);
  assert.ok(itemSchema.sessionExpectedVersion);
  const source = readFileSync(new URL('../../bridge/modules/scrum/actions.php', import.meta.url), 'utf8');
  assert.match(source, /expected_version_required/);
  assert.match(source, /version_conflict/);
  assert.match(source, /vote_set_conflict/);
  assert.doesNotMatch(source, /mcpObjectArray\(\$object\)/);
  assert.match(source, /'rolled_back'/);
  assert.match(source, /\$GLOBALS\['mcpCaptureErrors'\]=\$previousCapture/);
});

test('Scrum private model state has permission-checked semantic readbacks', () => {
  const actions = phpDescriptor().actions;
  assert.equal(actions['scrum.kanban.board.get'].risk, 'read');
  assert.equal(actions['scrum.poker.state.get'].risk, 'read');
  const source = readFileSync(new URL('../../bridge/modules/scrum/actions.php', import.meta.url), 'utf8');
  assert.match(source, /mcpScrumBoardAccess\(\$board,'read'\).*mcpScrumBoardPayload/s);
  assert.match(source, /mcpScrumTarget\('PokerSession'.*'read'\)/s);
  assert.match(source, /if\(\$item->flipped\)\$row\['revealedVotes'\]/);
  assert.match(source, /idResource'=?>\(int\)\$user->id/);
});

test('Scrum convenience schema remains bounded and requires optimistic concurrency', () => {
  const server = createProjeqtorServer({ username: 'tester', apiRequest: async () => ({ ok: true }) });
  const schema = server._registeredTools.projeqtor_manage_sprint.inputSchema;
  assert.equal(schema.safeParse({
    operations: [{ operation: 'start_sprint', sprintId: 11, expectedVersion: 'v2' }]
  }).success, true);
  assert.equal(schema.safeParse({
    operations: [{ operation: 'start_sprint', sprintId: 11 }]
  }).success, false);
  assert.equal(schema.safeParse({
    operations: Array.from({ length: 201 }, (_, index) => ({
      operation: 'start_sprint', sprintId: index + 1, expectedVersion: 'v'
    }))
  }).success, false);
});

test('Scrum schemas never accept credential material', () => {
  const text = JSON.stringify(phpDescriptor().actions);
  for (const forbidden of ['password', 'apiKey', 'token', 'oauthSecret', 'smtpPassword', 'credential']) {
    assert.equal(text.includes('"' + forbidden + '"'), false, forbidden);
  }
});
