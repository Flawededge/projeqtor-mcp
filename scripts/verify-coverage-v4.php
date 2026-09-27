<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/coverage-v4.php';

if ($argc !== 2) {
    pqV4Fail('Usage: php verify-coverage-v4.php <ProjeQtOr-source>', 2);
}

function verify(bool $condition, string $message): void
{
    if (!$condition) {
        pqV4Fail("Verification failed: $message");
    }
}

function runCommand(array $arguments, array &$output = []): int
{
    $command = implode(' ', array_map('escapeshellarg', $arguments));
    exec($command . ' 2>&1', $output, $status);
    return $status;
}

$repo = realpath(__DIR__ . '/..');
$source = realpath($argv[1]);
verify($repo !== false && $source !== false, 'repository and source paths must exist');
$temporary = sys_get_temp_dir() . '/projeqtor-coverage-v4-' . bin2hex(random_bytes(6));
verify(mkdir($temporary, 0700), 'temporary directory must be created');

try {
    $inventoryPath = "$temporary/source-inventory-v4.json";
    $handlersPath = "$temporary/ui-handler-policy-v4.json";
    $classesPath = "$temporary/class-policy-v4.json";
    $output = [];
    verify(runCommand([PHP_BINARY, "$repo/scripts/generate-source-inventory-v4.php", $source, $inventoryPath], $output) === 0, implode("\n", $output));
    $output = [];
    verify(runCommand([
        PHP_BINARY, "$repo/scripts/compile-coverage-v4.php", $inventoryPath,
        "$repo/bridge/class-policy-v3.json", "$repo/policy/modules", $handlersPath, $classesPath,
    ], $output) === 0, implode("\n", $output));

    foreach ([
        "$repo/bridge/source-inventory-v4.json" => $inventoryPath,
        "$repo/bridge/ui-handler-policy-v4.json" => $handlersPath,
        "$repo/bridge/class-policy-v4.json" => $classesPath,
    ] as $tracked => $generated) {
        verify(hash_file('sha256', $tracked) === hash_file('sha256', $generated), basename($tracked) . ' is stale');
    }

    $inventory = pqV4ReadJson($inventoryPath);
    $handlers = pqV4ReadJson($handlersPath);
    $classes = pqV4ReadJson($classesPath);
    verify($inventory['sourceFileCount'] === count($inventory['files']), 'source inventory count');
    verify($handlers['entrypointCount'] === count($handlers['handlers']), 'handler count');
    verify($handlers['unknownCount'] === 0 && $handlers['deferredCount'] === 0, 'zero unknown/deferred handlers');
    verify($classes['expectedInstalledClassCount'] === 640 && $classes['unknownCount'] === 0, '640 known classes');
    verify(array_keys($handlers['moduleCounts']) === PQ_V4_MODULES, 'all modules are present in deterministic order');
    foreach (PQ_V4_MODULES as $module) {
        verify($handlers['moduleCounts'][$module] > 0, "module $module owns a handler");
    }

    $byPath = [];
    foreach ($handlers['handlers'] as $handler) {
        $byPath[$handler['path']] = $handler;
        verify(in_array($handler['classification'], ['generic_crud', 'registered_action', 'read_only', 'intentional_exclusion'], true), 'valid classification');
        if ($handler['classification'] === 'registered_action') {
            verify(count($handler['mappedActions']) === 1 && $handler['coverageTestId'] !== null, $handler['path'] . ' action/test mapping');
        }
    }
    foreach (['tool/backupFilter.php', 'tool/backupLayout.php', 'tool/backupReportLayout.php'] as $path) {
        verify(($byPath[$path]['classification'] ?? null) === 'registered_action', "$path is a supported action");
    }
    foreach (['tool/saveWorkTokenClientContract.php', 'tool/saveWorkTokenMarkup.php'] as $path) {
        verify(($byPath[$path]['module'] ?? null) === 'financial', "$path belongs to financial");
        verify(($byPath[$path]['classification'] ?? null) === 'registered_action', "$path is supported");
    }
    verify(($byPath['tool/saveObjectMultiplePwd.php']['exclusionReason'] ?? null) === 'secrets_or_credentials', 'password bulk write excluded');
    verify(($byPath['tool/installAutoInstall.php']['exclusionReason'] ?? null) === 'plugin_installation', 'auto installer excluded');
    verify(($byPath['tool/sendRequestResetPassword.php']['mappedActions'][0] ?? null) === 'user.trigger_password_reset', 'reset email remains supported');
    verify(($byPath['api/index.php']['classification'] ?? null) === 'generic_crud', 'native API is generic CRUD');
    verify(($byPath['report/ticketReport.php']['module'] ?? null) === 'reports', 'reports own native renderers');

    $synthetic = <<<'PHP'
<script>object.save();</script>
<?php
$text = '$object->delete()';
// $object->remove();
/* $object->create(); */
$object->save();
?>
<script>object.remove();</script>
PHP;
    verify(pqV4MutationKinds(pqV4ExecutableTokens($synthetic)) === ['object_create_update'], 'lexer ignores strings, comments, HTML, and JavaScript');

    $actions = [];
    $tests = [];
    foreach ($handlers['handlers'] as $handler) {
        foreach ($handler['mappedActions'] as $action) {
            $actions[$action] = true;
        }
        if ($handler['coverageTestId'] !== null) {
            $tests[$handler['coverageTestId']] = true;
        }
    }
    $catalogPath = "$temporary/module-catalog-v4.json";
    pqV4WriteJson($catalogPath, ['actions' => array_keys($actions), 'tests' => array_keys($tests)]);
    $output = [];
    verify(runCommand([
        PHP_BINARY, "$repo/scripts/compile-coverage-v4.php", $inventoryPath,
        "$repo/bridge/class-policy-v3.json", "$repo/policy/modules",
        "$temporary/catalog-handlers.json", "$temporary/catalog-classes.json", $catalogPath,
    ], $output) === 0, 'complete module catalog must validate');
    verify(pqV4ReadJson("$temporary/catalog-handlers.json")['catalogValidation']['present'] === true, 'catalog validation status');
    array_pop($actions);
    pqV4WriteJson($catalogPath, ['actions' => array_keys($actions), 'tests' => array_keys($tests)]);
    $output = [];
    verify(runCommand([
        PHP_BINARY, "$repo/scripts/compile-coverage-v4.php", $inventoryPath,
        "$repo/bridge/class-policy-v3.json", "$repo/policy/modules",
        "$temporary/incomplete-handlers.json", "$temporary/incomplete-classes.json", $catalogPath,
    ], $output) !== 0, 'incomplete module catalog must fail closed');


    fwrite(STDOUT, json_encode([
        'ok' => true,
        'sourceFiles' => $inventory['sourceFileCount'],
        'entrypoints' => $handlers['entrypointCount'],
        'mutations' => $handlers['mutationCandidateCount'],
        'classes' => $classes['expectedInstalledClassCount'],
        'unknown' => 0,
        'deferred' => 0,
    ]) . "\n");
} finally {
    foreach (glob($temporary . '/*') ?: [] as $path) {
        unlink($path);
    }
    rmdir($temporary);
}
