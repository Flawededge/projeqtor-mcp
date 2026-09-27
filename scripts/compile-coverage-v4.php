<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/coverage-v4.php';

if ($argc < 6 || $argc > 7) {
    pqV4Fail('Usage: php compile-coverage-v4.php <source-inventory> <class-policy-v3> <modules-dir> <handler-output> <class-output> [module-catalog]', 2);
}

$inventory = pqV4ReadJson($argv[1]);
$classV3 = pqV4ReadJson($argv[2]);
$fragments = pqV4LoadFragments($argv[3]);
if (($inventory['inventoryVersion'] ?? null) !== 4 || !is_array($inventory['files'] ?? null)) {
    pqV4Fail('Invalid v4 source inventory', 2);
}
if (!is_array($classV3['classes'] ?? null)) {
    pqV4Fail('Invalid v3 class policy', 2);
}

$catalogPath = $argv[6] ?? dirname(rtrim($argv[3], '/')) . '/module-catalog-v4.json';
$catalog = is_file($catalogPath) ? pqV4ReadJson($catalogPath) : null;
if ($catalog !== null) {
    foreach (['actions', 'tests'] as $key) {
        if (isset($catalog[$key]) && is_array($catalog[$key]) && !array_is_list($catalog[$key])) {
            $catalog[$key] = array_keys($catalog[$key]);
        }
    }
}
$modulePolicies = [];
foreach ($fragments as $module => $fragment) {
    unset($fragment['_path']);
    $modulePolicies[$module] = [
        'version' => $fragment['version'],
        'dependsOn' => $fragment['dependsOn'],
        'legacyAliases' => $fragment['legacyAliases'] ?? [],
        'fragmentHash' => pqV4StableHash($fragment),
    ];
}
$modulePolicyHash = pqV4StableHash($modulePolicies);

$legacyActions = [
    'copyObject' => 'object.copy', 'copyObjectTo' => 'object.copy', 'copyProjectTo' => 'object.copy',
    'changeObjectStatus' => 'workflow.transition', 'saveStatus' => 'workflow.transition',
    'saveBaseline' => 'planning.baseline.create', 'savePlanningBaseline' => 'planning.baseline.create',
    'deleteBaseline' => 'planning.baseline.delete', 'removePlanningBaseline' => 'planning.baseline.delete',
    'startPlanningCalculation' => 'planning.calculate', 'planningCalculation' => 'planning.calculate', 'plan' => 'planning.calculate',
    'importData' => 'import.start', 'importDataFromFile' => 'import.start',
    'exportData' => 'export.start', 'exportPlanning' => 'export.start',
    'saveAttachment' => 'attachment.upload.commit', 'deleteAttachment' => 'attachment.upload.abort',
    'sendRequestResetPassword' => 'user.trigger_password_reset',
    'cronCheck' => 'cron.check', 'cronActivation' => 'cron.start', 'cronStop' => 'cron.stop',
    'cronRelaunch' => 'cron.restart', 'cronRun' => 'cron.restart',
];

$handlers = [];
$classificationCounts = array_fill_keys(['generic_crud', 'registered_action', 'read_only', 'intentional_exclusion'], 0);
$moduleCounts = array_fill_keys(PQ_V4_MODULES, 0);
$mutationCandidates = 0;
foreach ($inventory['files'] as $file) {
    if (($file['sourceRole'] ?? null) !== 'http_entrypoint') {
        continue;
    }
    $relative = (string) $file['path'];
    $name = basename($relative, '.php');
    $modules = pqV4ModulesFor($relative, $fragments, 'pathPatterns');
    if ($file['surface'] === 'report') {
        $modules = array_values(array_unique(array_merge(['reports'], $modules)));
    } elseif (in_array($file['surface'], ['api', 'sso', 'plugin'], true)) {
        $modules = array_values(array_unique(array_merge(['configuration'], $modules)));
    }
    $module = $modules[0];
    $mutationTypes = $file['detectedMutationTypes'];
    $exclusion = pqV4Exclusion($relative);
    $mappedActions = [];
    $mappedClasses = [];
    $testId = null;
    if ($mutationTypes !== []) {
        $mutationCandidates++;
    }
    if ($exclusion !== null) {
        $classification = 'intentional_exclusion';
        $risk = 'excluded';
    } elseif ($mutationTypes === []) {
        $classification = 'read_only';
        $risk = 'read';
    } elseif ($relative === 'api/index.php' || pqV4KnownGenericCrud($name)) {
        $classification = 'generic_crud';
        $risk = str_starts_with($name, 'delete') ? 'destructive' : 'write';
        $mappedClasses = ['*'];
        $testId = 'coverage.core.generic_crud';
    } else {
        $classification = 'registered_action';
        $risk = preg_match('/^(?:delete|remove|purge|uninstall)/i', $name) === 1 ? 'destructive' : 'write';
        $mappedActions = [$legacyActions[$name] ?? sprintf('%s.native.%s.%s', $module, $file['surface'], pqV4Snake($name))];
        $testId = sprintf('coverage.%s.%s.%s', $module, $file['surface'], pqV4Snake($name));
    }
    $classificationCounts[$classification]++;
    $moduleCounts[$module]++;
    $handlers[] = [
        'id' => $file['id'],
        'path' => $relative,
        'sourceHash' => $file['sourceHash'],
        'sourceRole' => $file['sourceRole'],
        'surface' => $file['surface'],
        'module' => $module,
        'modules' => $modules,
        'mutationTypes' => $mutationTypes,
        'classification' => $classification,
        'mappedClasses' => $mappedClasses,
        'mappedActions' => $mappedActions,
        'coverageTestId' => $testId,
        'risk' => $risk,
        'availability' => 'installed',
        'exclusionReason' => $exclusion,
    ];
}
usort($handlers, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));

$catalogStatus = pqV4ValidateCatalog($handlers, $catalog);
$legacyAliases = [];
foreach ($fragments as $module => $fragment) {
    foreach ($fragment['legacyAliases'] ?? [] as $alias) {
        $legacyAliases[$alias][] = $module;
    }
}
ksort($legacyAliases, SORT_STRING);
$handlerManifest = [
    'policyVersion' => 4,
    'sourceVersion' => $inventory['sourceVersion'],
    'sourceArchiveSha256' => $inventory['sourceArchiveSha256'],
    'sourceTreeHash' => $inventory['sourceTreeHash'],
    'sourceInventoryHash' => $inventory['inventoryHash'],
    'entrypointCount' => count($handlers),
    'mutationCandidateCount' => $mutationCandidates,
    'unknownCount' => 0,
    'deferredCount' => 0,
    'classificationCounts' => $classificationCounts,
    'modulePolicyHash' => $modulePolicyHash,
    'modules' => $modulePolicies,
    'moduleCounts' => $moduleCounts,
    'legacyModuleAliases' => $legacyAliases,
    'catalogValidation' => $catalogStatus,
    'handlers' => $handlers,
];
$handlerManifest['manifestHash'] = pqV4StableHash($handlers);

$classes = [];
$classModuleCounts = array_fill_keys(PQ_V4_MODULES, 0);
foreach ($classV3['classes'] as $className => $classPolicy) {
    $modules = pqV4ModulesFor($className, $fragments, 'classPatterns');
    $module = $modules[0];
    $classPolicy['module'] = $module;
    $classPolicy['modules'] = $modules;
    $classes[$className] = $classPolicy;
    $classModuleCounts[$module]++;
}
ksort($classes, SORT_STRING);
$classManifest = [
    'policyVersion' => 4,
    'sourceVersion' => $classV3['sourceVersion'] ?? PQ_V4_SOURCE_VERSION,
    'sourceArchiveSha256' => $classV3['sourceArchiveSha256'] ?? PQ_V4_SOURCE_ARCHIVE_SHA256,
    'sourcePolicyVersion' => 3,
    'expectedInstalledClassCount' => count($classes),
    'unknownCount' => 0,
    'modulePolicyHash' => $modulePolicyHash,
    'modules' => $modulePolicies,
    'moduleCounts' => $classModuleCounts,
    'legacyModuleAliases' => $legacyAliases,
    'classes' => $classes,
];
$classManifest['manifestHash'] = pqV4StableHash($classes);

pqV4WriteJson($argv[4], $handlerManifest);
pqV4WriteJson($argv[5], $classManifest);
fwrite(STDOUT, json_encode([
    'handlers' => count($handlers),
    'mutations' => $mutationCandidates,
    'classes' => count($classes),
    'unknown' => 0,
    'deferred' => 0,
    'handlerManifestHash' => $handlerManifest['manifestHash'],
    'classManifestHash' => $classManifest['manifestHash'],
    'catalogValidated' => $catalogStatus['present'],
]) . "\n");
