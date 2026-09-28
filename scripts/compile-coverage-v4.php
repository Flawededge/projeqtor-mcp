<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/coverage-v4.php';

if ($argc !== 7) {
    pqV4Fail('Usage: php compile-coverage-v4.php <source-inventory> <class-policy-v3> <modules-dir> <handler-output> <class-output> <module-catalog>', 2);
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

$catalog = pqV4ReadJson($argv[6]);
if (($catalog['catalogVersion'] ?? null) !== 4 || !is_array($catalog['actions'] ?? null)
    || !is_array($catalog['handlers'] ?? null) || !is_array($catalog['tests'] ?? null)) {
    pqV4Fail('Invalid v4 runtime module catalog', 2);
}
pqV4ValidateCatalogHandlerClaims($inventory['files'], $catalog['handlers']);
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


$handlerOwners = [];
$classOwners = [];
$secondaryClassModules = [];
$explicitMappings = [];
$explicitReadOnly = [];
$explicitCrud = [];
$claim = static function (array &$claims, string $key, string $module, string $kind): void {
    if (isset($claims[$key]) && $claims[$key] !== $module) pqV4Fail("Duplicate exact $kind claim for $key");
    $claims[$key] = $module;
};
$canonicalHandler = static function (string $value): string {
    if (preg_match('/^(?:tool|view|report|api|sso|plugin):[A-Za-z0-9_\/.+-]+$/', $value) === 1) return $value;
    if (preg_match('#^(?:tool|view|report|api|sso|plugin)/.+\.php$#', $value) !== 1) {
        pqV4Fail("Invalid handler identifier or source path: $value");
    }
    return pqV4HandlerId($value);
};
foreach ($fragments as $module => $fragment) {
    foreach ($fragment['ownedHandlers'] ?? [] as $handler) $claim($handlerOwners, $canonicalHandler((string) $handler), $module, 'handler');
    foreach ($fragment['ownedClasses'] ?? [] as $class) $claim($classOwners, (string) $class, $module, 'class');
    foreach ($fragment['secondaryClasses'] ?? [] as $class) {
        $secondaryClassModules[(string) $class][] = $module;
    }
    foreach ($fragment['excludedOwnership'] ?? [] as $class => $target) {
        if (!isset($fragments[$target])) pqV4Fail("Unknown excluded-ownership target $target");
        $claim($classOwners, (string) $class, (string) $target, 'class');
    }
    foreach ($fragment['readOnlyHandlers'] ?? [] as $path) {
        $handler = $canonicalHandler((string) $path);
        $claim($handlerOwners, $handler, $module, 'handler');
        $explicitReadOnly[$handler] = true;
    }
    foreach ($fragment['genericCrudHandlers'] ?? [] as $path => $classes) {
        $handler = $canonicalHandler((string) $path);
        $claim($handlerOwners, $handler, $module, 'handler');
        $explicitCrud[$handler] = array_values($classes);
    }
    foreach ($fragment['handlerMappings'] ?? [] as $path => $mapping) {
        $handler = $canonicalHandler((string) $path);
        $action = (string) ($mapping['action'] ?? '');
        if (!isset($catalog['actions'][$action])) pqV4Fail("Invalid explicit mapping for $path");
        $claim($handlerOwners, $handler, $module, 'handler');
        $explicitMappings[$handler] = ['action' => $action, 'module' => $module, 'testContract' => $catalog['actions'][$action]['testContract'], 'classes' => array_values($mapping['classes'] ?? [])];
    }
}
foreach ($fragments as $fragment) {
    foreach (['handlerHandoffs', 'ownershipCorrections'] as $field) {
        foreach ($fragment[$field] ?? [] as $handler => $target) {
            if (!isset($fragments[$target])) pqV4Fail("Unknown handoff target $target");
            $handlerOwners[$canonicalHandler((string) $handler)] = (string) $target;
        }
    }
}
foreach ($catalog['handlers'] as $handler => $mapping) {
    $module = (string) ($mapping['module'] ?? '');
    if (!isset($fragments[$module])) pqV4Fail("Runtime handler $handler has an unknown module");
    if (isset($handlerOwners[$handler]) && $handlerOwners[$handler] !== $module) pqV4Fail("Runtime/policy ownership mismatch for $handler");
    $handlerOwners[$handler] = $module;
    if (isset($explicitMappings[$handler]) && $explicitMappings[$handler]['action'] !== ($mapping['action'] ?? null)) pqV4Fail("Runtime/policy action mismatch for $handler");
}

$handlers = [];
$classificationCounts = array_fill_keys(['generic_crud', 'registered_action', 'read_only', 'intentional_exclusion', 'unknown'], 0);
$moduleCounts = array_fill_keys(PQ_V4_MODULES, 0);
$mutationCandidates = 0;
foreach ($inventory['files'] as $file) {
    if (($file['sourceRole'] ?? null) !== 'http_entrypoint') {
        continue;
    }
    $relative = (string) $file['path'];
    $handlerId = (string) $file['id'];
    $name = basename($relative, '.php');
    $modules = pqV4ModulesFor($relative, $fragments, 'pathPatterns');
    $preferred = $handlerOwners[$handlerId] ?? null;
    if ($preferred === null && $file['surface'] === 'report') $preferred = 'reports';
    if ($preferred === null && in_array($file['surface'], ['api', 'sso', 'plugin'], true)) $preferred = 'configuration';
    if ($preferred !== null) $modules = array_values(array_unique(array_merge([$preferred], $modules)));
    $module = $modules[0];
    $mutationTypes = $file['detectedMutationTypes'];
    $exclusion = pqV4Exclusion($relative);
    $mapping = $catalog['handlers'][$handlerId] ?? $explicitMappings[$handlerId] ?? null;
    $mappedActions = [];
    $mappedClasses = [];
    $testId = null;
    $mappingSource = null;
    if ($mutationTypes !== []) $mutationCandidates++;
    if ($exclusion !== null) {
        $classification = 'intentional_exclusion';
        $risk = 'excluded';
        $mappingSource = 'intentional_exclusion';
    } elseif ($mapping !== null) {
        $action = (string) $mapping['action'];
        $classification = 'registered_action';
        $risk = (string) ($catalog['actions'][$action]['risk'] ?? 'write');
        $mappedActions = [$action];
        $testId = (string) $mapping['testContract'];
        $mappedClasses = $explicitMappings[$handlerId]['classes'] ?? [];
        $mappingSource = isset($explicitMappings[$handlerId]) ? 'explicit_action_equivalence' : 'runtime_action';
    } elseif (isset($explicitCrud[$handlerId])) {
        $classification = 'generic_crud';
        $risk = in_array('object_delete', $mutationTypes, true) ? 'destructive' : 'write';
        $mappedClasses = $explicitCrud[$handlerId];
        $testId = 'coverage.core.generic_crud';
        $mappingSource = 'explicit_fixed_class_crud';
    } elseif (isset($explicitReadOnly[$handlerId])) {
        $classification = 'read_only';
        $risk = 'read';
        $mappingSource = 'explicit_session_or_library';
    } elseif ($relative === 'api/index.php' || pqV4KnownGenericCrud($name)) {
        $classification = 'generic_crud';
        $risk = str_starts_with($name, 'delete') ? 'destructive' : 'write';
        $mappedClasses = ['*'];
        $testId = 'coverage.core.generic_crud';
        $mappingSource = 'generic_crud';
    } elseif ($mutationTypes === []) {
        $classification = 'read_only';
        $risk = 'read';
        $mappingSource = 'scanner';
    } else {
        $classification = 'unknown';
        $risk = 'unknown';
        $mappingSource = 'unmapped_mutation';
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
        'mappingSource' => $mappingSource,
        'coverageStatus' => $classification === 'unknown' ? 'unknown' : 'covered',
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
    'unknownCount' => $classificationCounts['unknown'],
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
    if (isset($classOwners[$className])) $modules = array_values(array_unique(array_merge([$classOwners[$className]], $modules)));
    foreach ($secondaryClassModules[$className] ?? [] as $secondary) {
        if (!in_array($secondary, $modules, true)) $modules[] = $secondary;
    }
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
    'unknown' => $handlerManifest['unknownCount'] + $classManifest['unknownCount'],
    'deferred' => 0,
    'handlerManifestHash' => $handlerManifest['manifestHash'],
    'classManifestHash' => $classManifest['manifestHash'],
    'catalogValidated' => $catalogStatus['present'],
]) . "\n");
