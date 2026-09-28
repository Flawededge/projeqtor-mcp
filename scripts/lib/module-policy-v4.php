<?php
declare(strict_types=1);

function pqV4ValidateFragmentGraph(array $fragments): void
{
    foreach ($fragments as $module => $fragment) {
        if (($fragment['version'] ?? null) !== '4.0.0') {
            pqV4Fail("Module $module must declare policy version 4.0.0");
        }
        $dependencies = $fragment['dependsOn'] ?? null;
        if (!is_array($dependencies) || !array_is_list($dependencies)) {
            pqV4Fail("Module $module has invalid dependencies");
        }
        if (count($dependencies) !== count(array_unique($dependencies))) {
            pqV4Fail("Module $module has duplicate dependencies");
        }
        foreach ($dependencies as $dependency) {
            if (!is_string($dependency) || !isset($fragments[$dependency]) || $dependency === $module) {
                pqV4Fail("Module $module has invalid dependency: " . (string) $dependency);
            }
        }
        foreach (['pathPatterns', 'classPatterns'] as $patternKey) {
            if (!is_array($fragment[$patternKey] ?? null)) {
                pqV4Fail("Module $module is missing $patternKey");
            }
            foreach ($fragment[$patternKey] as $pattern) {
                if (!is_string($pattern) || @preg_match($pattern, '') === false) {
                    pqV4Fail("Module $module has invalid $patternKey pattern");
                }
            }
        }
        foreach (['legacyAliases', 'ownedHandlers', 'ownedClasses', 'secondaryClasses', 'contractTests', 'readOnlyHandlers'] as $listKey) {
            $values = $fragment[$listKey] ?? [];
            if (!is_array($values) || !array_is_list($values)) {
                pqV4Fail("Module $module has invalid $listKey");
            }
            foreach ($values as $value) {
                if (!is_string($value) || trim($value) === '') {
                    pqV4Fail("Module $module has an invalid $listKey entry");
                }
            }
            if (count($values) !== count(array_unique($values))) {
                pqV4Fail("Module $module has duplicate $listKey entries");
            }
        }
        foreach (['handlerHandoffs', 'ownershipCorrections', 'excludedOwnership'] as $mapKey) {
            $values = $fragment[$mapKey] ?? [];
            if (!is_array($values) || (array_is_list($values) && $values !== [])) {
                pqV4Fail("Module $module has invalid $mapKey");
            }
            foreach ($values as $source => $target) {
                if (!is_string($source) || trim($source) === '' || !is_string($target) || !isset($fragments[$target])) {
                    pqV4Fail("Module $module has an invalid $mapKey entry");
                }
            }
        }
        $mappings = $fragment['handlerMappings'] ?? [];
        if (!is_array($mappings) || (array_is_list($mappings) && $mappings !== [])) {
            pqV4Fail("Module $module has invalid handlerMappings");
        }
        foreach ($mappings as $handler => $mapping) {
            if (!is_string($handler) || trim($handler) === '' || !is_array($mapping)
                || !is_string($mapping['action'] ?? null) || trim($mapping['action']) === '') {
                pqV4Fail("Module $module has an invalid handlerMappings entry");
            }
            if (isset($mapping['test']) && (!is_string($mapping['test']) || trim($mapping['test']) === '')) {
                pqV4Fail("Module $module has an invalid handlerMappings test");
            }
            $classes = $mapping['classes'] ?? [];
            if (!is_array($classes) || !array_is_list($classes) || count($classes) !== count(array_filter($classes, static fn($class): bool => is_string($class) && trim($class) !== ''))) {
                pqV4Fail("Module $module has invalid handlerMappings classes");
            }
        }
        $crudMappings = $fragment['genericCrudHandlers'] ?? [];
        if (!is_array($crudMappings) || (array_is_list($crudMappings) && $crudMappings !== [])) {
            pqV4Fail("Module $module has invalid genericCrudHandlers");
        }
        foreach ($crudMappings as $handler => $classes) {
            if (!is_string($handler) || trim($handler) === '' || !is_array($classes) || !array_is_list($classes)
                || $classes === [] || count($classes) !== count(array_filter($classes, static fn($class): bool => is_string($class) && trim($class) !== ''))
                || count($classes) !== count(array_unique($classes))) {
                pqV4Fail("Module $module has an invalid genericCrudHandlers entry");
            }
        }
    }

    $states = [];
    $visit = function (string $module) use (&$visit, &$states, $fragments): void {
        if (($states[$module] ?? 0) === 1) {
            pqV4Fail("Module dependency cycle includes $module");
        }
        if (($states[$module] ?? 0) === 2) {
            return;
        }
        $states[$module] = 1;
        foreach ($fragments[$module]['dependsOn'] as $dependency) {
            $visit($dependency);
        }
        $states[$module] = 2;
    };
    foreach (array_keys($fragments) as $module) {
        $visit($module);
    }
}
