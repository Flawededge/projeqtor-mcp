import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(dirname(dirname(fileURLToPath(import.meta.url))));
const resolverPath = join(root, 'bridge/core/job-artifact-resource.php');
const routerPath = join(root, 'bridge/router.php');
const php = source => execFileSync('php', ['-r', source], { encoding: 'utf8' });
const requirePhp = path => `require ${JSON.stringify(path)};`;

test('job artifacts resolve only canonical allowed files with exact MIME types', () => {
  const script = [
    '$root=sys_get_temp_dir()."/mcp-job-resource-".bin2hex(random_bytes(5));mkdir($root,0700,true);define("MCP_JOB_ARTIFACT_ROOT",$root);',
    requirePhp(resolverPath),
    '$expected=mcpJobArtifactMimeTypes();$actual=array();try{foreach($expected as $extension=>$mime){$path=$root."/job-42.".$extension;file_put_contents($path,"artifact");$actual[$extension]=mcpResolveJobArtifact(42,$path);}',
    'echo json_encode(array("expected"=>$expected,"actual"=>$actual),JSON_THROW_ON_ERROR);',
    '}finally{foreach(array_keys($expected) as $extension){$path=$root."/job-42.".$extension;if(is_file($path))unlink($path);}rmdir($root);}'
  ].join('');
  const value = JSON.parse(php(script));
  assert.deepEqual(
    Object.fromEntries(Object.entries(value.actual).map(([extension, artifact]) => [extension, artifact.mimeType])),
    value.expected
  );
  for (const [extension, artifact] of Object.entries(value.actual)) {
    assert.equal(artifact.path.endsWith(`/job-42.${extension}`), true);
  }
  assert.deepEqual(value.expected, {
    json: 'application/json',
    ndjson: 'application/x-ndjson',
    csv: 'text/csv',
    pdf: 'application/pdf',
    png: 'image/png',
    jpg: 'image/jpeg',
    jpeg: 'image/jpeg',
    zip: 'application/zip',
    xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
  });
});

test('job artifact resolver rejects poisoned database paths and symlinks', () => {
  const script = [
    '$root=sys_get_temp_dir()."/mcp-job-resource-".bin2hex(random_bytes(5));mkdir($root,0700,true);define("MCP_JOB_ARTIFACT_ROOT",$root);',
    requirePhp(resolverPath),
    '$nested=$root."/nested";$sibling=$root."-evil";mkdir($nested);mkdir($sibling);',
    '$paths=array("wrongJob"=>$root."/job-41.json","nested"=>$nested."/job-42.json","unsupported"=>$root."/job-42.txt","uppercase"=>$root."/job-42.JSON","extraSuffix"=>$root."/job-42.pdf.tmp","sibling"=>$sibling."/job-42.json");',
    'foreach($paths as $path)file_put_contents($path,"poison");$symlink=$root."/job-43.json";symlink("/etc/hosts",$symlink);',
    '$checks=array("empty"=>mcpResolveJobArtifact(42,""),"relative"=>mcpResolveJobArtifact(42,"job-42.json"),"missing"=>mcpResolveJobArtifact(44,$root."/job-44.json"),"symlink"=>mcpResolveJobArtifact(43,$symlink));',
    'foreach($paths as $name=>$path)$checks[$name]=mcpResolveJobArtifact(42,$path);',
    'try{echo json_encode($checks,JSON_THROW_ON_ERROR);}finally{unlink($symlink);foreach($paths as $path)unlink($path);rmdir($nested);rmdir($sibling);rmdir($root);}'
  ].join('');
  const checks = JSON.parse(php(script));
  for (const [name, result] of Object.entries(checks)) assert.equal(result, null, name);
});

test('router uses the resolver without weakening legacy object-resource ACLs', () => {
  const router = readFileSync(routerPath, 'utf8');
  assert.match(router, /core\/job-artifact-resource\.php/);
  assert.match(router, /mcpResolveJobArtifact\(\$id,\(string\)\$row\['result_path'\]\)/);
  assert.match(router, /Security::checkValidAccessForUser\(\$object,'read'/);
  assert.match(router, /mcpContextualReadAllowed\(\$object\)/);
  assert.doesNotMatch(router, /str_ends_with\(\$path,'\.ndjson'\)/);
});
