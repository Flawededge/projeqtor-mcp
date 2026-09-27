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
