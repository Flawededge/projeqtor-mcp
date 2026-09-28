<?php
declare(strict_types=1);

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php generate-module-catalog-v4.php <output>\n");
    exit(2);
}

$repo = realpath(__DIR__ . '/..');
if ($repo === false) throw new RuntimeException('Repository root is unavailable');
require_once __DIR__ . '/lib/coverage-v4.php';
require_once $repo . '/bridge/core/module-graph.php';
require_once $repo . '/bridge/core/action-domains.php';
require_once $repo . '/bridge/core/module-registry.php';

$modules = [];
$actions = [];
$tests = ['coverage.core.generic_crud' => true];
$handlers = [];
foreach (mcpModuleCatalog() as $moduleId => $module) {
    $modules[$moduleId] = ['version' => $module['version'], 'dependencies' => $module['dependencies']];
}
foreach (mcpActionRegistry() as $actionId => $action) {
    $test = (string) $action['testContract'];
    if ($test === '') pqV4Fail("Action $actionId has no contract test");
    $mapped = array_values(array_unique($action['mappedHandlers'] ?? []));
    $actions[$actionId] = [
        'module' => $action['module'], 'testContract' => $test,
        'mappedHandlers' => $mapped, 'risk' => $action['risk'], 'async' => $action['async'],
    ];
    $tests[$test] = true;
    foreach ($mapped as $handler) {
        if (isset($handlers[$handler])) pqV4Fail("Handler $handler is mapped by more than one action");
        $handlers[$handler] = ['action' => $actionId, 'module' => $action['module'], 'testContract' => $test];
    }
}
ksort($modules, SORT_STRING);
ksort($actions, SORT_STRING);
ksort($tests, SORT_STRING);
ksort($handlers, SORT_STRING);
pqV4WriteJson($argv[1], [
    'catalogVersion' => 4, 'modules' => $modules, 'actions' => $actions,
    'tests' => array_keys($tests), 'handlers' => $handlers,
]);
fwrite(STDOUT, json_encode(['modules' => count($modules), 'actions' => count($actions), 'handlers' => count($handlers), 'tests' => count($tests)]) . "\n");
