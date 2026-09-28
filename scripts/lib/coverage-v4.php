<?php
declare(strict_types=1);

require_once __DIR__ . '/fallback-tokenizer-v4.php';
require_once __DIR__ . '/module-policy-v4.php';
const PQ_V4_SOURCE_VERSION = '13.1.0';
const PQ_V4_SOURCE_ARCHIVE_SHA256 = '221c2a0b2facbdfc0b5af9878e030cdd7ded6e3f989b1da9cd609eccebaa0a69';
const PQ_V4_MODULES = [
    'core', 'planning', 'ticketing', 'scrum', 'follow_up', 'steering',
    'financial', 'products', 'hr', 'environment', 'tools', 'reports', 'configuration',
];

function pqV4Fail(string $message, int $code = 1): never
{
    fwrite(STDERR, $message . "\n");
    exit($code);
}

function pqV4ReadJson(string $path): array
{
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        pqV4Fail("Invalid JSON: $path");
    }
    return $decoded;
}

function pqV4WriteJson(string $path, array $value): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        pqV4Fail("Cannot create output directory: $directory");
    }
    $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false || file_put_contents($path, $encoded . "\n") === false) {
        pqV4Fail("Cannot write JSON: $path");
    }
}

function pqV4CanonicalJson(mixed $value): string
{
    if (is_array($value)) {
        if (array_is_list($value)) {
            $value = array_map('pqV4Canonicalize', $value);
        } else {
            ksort($value, SORT_STRING);
            foreach ($value as $key => $item) {
                $value[$key] = pqV4Canonicalize($item);
            }
        }
    }
    return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function pqV4Canonicalize(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    if (array_is_list($value)) {
        return array_map('pqV4Canonicalize', $value);
    }
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) {
        $value[$key] = pqV4Canonicalize($item);
    }
    return $value;
}

function pqV4StableHash(array $value): string
{
    return hash('sha256', pqV4CanonicalJson($value));
}

/**
 * Return only executable PHP tokens. Strings, inline HTML, comments and heredoc
 * contents are deliberately omitted so HTML/JavaScript and prose cannot create
 * false mutation candidates.
 */
function pqV4ExecutableTokens(string $source): array
{
    if (!function_exists('token_get_all')) {
        return pqV4FallbackExecutableTokens($source);
    }
    $ignoredNames = ['T_WHITESPACE', 'T_COMMENT', 'T_DOC_COMMENT', 'T_OPEN_TAG',
        'T_OPEN_TAG_WITH_ECHO', 'T_CLOSE_TAG', 'T_INLINE_HTML',
        'T_CONSTANT_ENCAPSED_STRING', 'T_ENCAPSED_AND_WHITESPACE'];
    $ignored = array_map(static fn(string $name): int => constant($name), $ignoredNames);
    $tokens = [];
    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], $ignored, true)) {
                continue;
            }
            $tokens[] = ['id' => $token[0], 'text' => strtolower($token[1])];
        } else {
            $tokens[] = ['id' => null, 'text' => strtolower($token)];
        }
    }
    return $tokens;
}

function pqV4TokenText(array $tokens): string
{
    return implode(' ', array_column($tokens, 'text'));
}

function pqV4MutationKinds(array $tokens): array
{
    $joined = pqV4TokenText($tokens);
    $patterns = [
        'object_create_update' => '/(?:->|::)\s*(?:save|savework|savewithplanning|savewithrefresh|simplesave|saveforced|create|insert)\s*\(/',
        'object_delete' => '/(?:->|::)\s*(?:delete|purge|remove)\s*\(/',
        'sql_write' => '/\bsql\s*::\s*(?:execute|exec)\s*\(/',
        'file_write' => '/\b(?:file_put_contents|fwrite|fputcsv|rename|unlink|move_uploaded_file|mkdir|rmdir|copy)\s*\(/',
        'mail_or_notification' => '/\b(?:sendmail|sendnotification|sendalert|sendmailgroup)\s*\(/',
        'workflow_or_calculation' => '/(?:->|::)\s*(?:plan|calculate|approve|reject|submit|validate|close|reopen|cancel|copy)\s*\(/',
        'session_or_configuration' => '/\b(?:setsessionuser|setglobalparameter|setuserparameter)\s*\(/',
    ];
    $kinds = [];
    foreach ($patterns as $kind => $pattern) {
        if (preg_match($pattern, $joined) === 1) {
            $kinds[] = $kind;
        }
    }
    return $kinds;
}

function pqV4SurfaceForPath(string $relative): ?string
{
    $surface = strtok($relative, '/');
    return in_array($surface, ['tool', 'view', 'report', 'api', 'sso', 'plugin'], true) ? $surface : null;
}

function pqV4SourceRole(string $relative): string
{
    $knownLibraries = [
        'tool/projeqtor.php', 'tool/projeqtor-hr.php', 'tool/projeqtor_string.php',
        'tool/formatter.php', 'tool/jsonFunctions.php', 'tool/planningListFunction.php',
        'tool/imputationListFunction.php', 'tool/liveMeetingFunc.php', 'tool/file.php',
        'report/header.php', 'report/headerFunctions.php', 'plugin/loadPlugin.php',
    ];
    if (in_array($relative, $knownLibraries, true)) {
        return 'included_library';
    }
    if (preg_match('#^(?:tool|view|report|api|plugin)/[^/]+\.php$#', $relative) === 1) {
        return 'http_entrypoint';
    }
    if (preg_match('#^view/plugin/[^/]+\.php$#', $relative) === 1) {
        return 'http_entrypoint';
    }
    if (in_array($relative, ['sso/index.php', 'sso/projeqtor/index.php', 'sso/projeqtor/metadata.php'], true)) {
        return 'http_entrypoint';
    }
    return 'included_library';
}

function pqV4HandlerId(string $relative): string
{
    return str_replace('/', ':', substr($relative, 0, -4));
}

function pqV4Snake(string $value): string
{
    $value = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $value) ?? $value;
    $value = preg_replace('/[^A-Za-z0-9]+/', '_', $value) ?? $value;
    return trim(strtolower($value), '_');
}

function pqV4MatchesAny(string $value, array $patterns): bool
{
    foreach ($patterns as $pattern) {
        if (@preg_match($pattern, '') === false) {
            pqV4Fail("Invalid policy pattern: $pattern");
        }
        if (preg_match($pattern, $value) === 1) {
            return true;
        }
    }
    return false;
}

function pqV4LoadFragments(string $directory): array
{
    $fragments = [];
    foreach (glob(rtrim($directory, '/') . '/*.json') ?: [] as $path) {
        $fragment = pqV4ReadJson($path);
        $module = $fragment['module'] ?? null;
        if (!is_string($module) || !in_array($module, PQ_V4_MODULES, true)) {
            pqV4Fail("Invalid module fragment: $path");
        }
        if (isset($fragments[$module])) {
            pqV4Fail("Duplicate module fragment: $module");
        }
        $fragment['_path'] = $path;
        $fragments[$module] = $fragment;
    }
    $missing = array_values(array_diff(PQ_V4_MODULES, array_keys($fragments)));
    if ($missing !== []) {
        pqV4Fail('Missing module fragments: ' . implode(', ', $missing));
    }
    pqV4ValidateFragmentGraph($fragments);
    uksort($fragments, static fn(string $a, string $b): int => array_search($a, PQ_V4_MODULES, true) <=> array_search($b, PQ_V4_MODULES, true));
    return $fragments;
}

function pqV4ModuleFor(string $value, array $fragments, string $key): string
{
    return pqV4ModulesFor($value, $fragments, $key)[0];
}

function pqV4ModulesFor(string $value, array $fragments, string $key): array
{
    $matches = [];
    foreach ($fragments as $module => $fragment) {
        if (pqV4MatchesAny($value, $fragment[$key] ?? [])) {
            $matches[] = $module;
        }
    }
    if ($matches === []) {
        return ['core'];
    }
    // Fragment order is intentional precedence; core is always the fallback.
    return $matches;
}

function pqV4Exclusion(string $relative): ?string
{
    $name = strtolower(basename($relative, '.php'));
    if (in_array($name, ['jsonquery', 'sqlquery', 'sqlsearch'], true)) {
        return 'raw_sql';
    }
    if (in_array($name, [
        'changepassword', 'passwordchange', 'resetpasswordchangeview', 'resetpasswordrequestview',
        'saveobjectmultiplepwd', 'saveoauthclient', 'sendchangeresetpassword',
    ], true)) {
        return 'secrets_or_credentials';
    }
    if (preg_match('/^(?:installauto|subscriptioninstallupdate|subscriptiondownloadupdate|uploadplugin|deleteplugin|uninstallplugin|loadplugin)/', $name) === 1) {
        return 'plugin_installation';
    }
    if (in_array($name, ['backupdatabase', 'restoredatabase', 'databaserepair', 'configcheck'], true)) {
        return 'host_container_database_or_backup_administration';
    }
    return null;
}

function pqV4KnownGenericCrud(string $name): bool
{
    return in_array($name, ['saveObject', 'deleteObject', 'deleteObjectMultiple', 'deleteObjectMultipleControl'], true);
}

function pqV4ValidateCatalog(array $handlers, array $catalog): array
{
    if (!is_array($catalog['actions'] ?? null) || array_is_list($catalog['actions'])) pqV4Fail('Module catalog actions must be an object map');
    if (!is_array($catalog['handlers'] ?? null) || array_is_list($catalog['handlers'])) pqV4Fail('Module catalog handlers must be an object map');
    if (!is_array($catalog['tests'] ?? null) || !array_is_list($catalog['tests'])) pqV4Fail('Module catalog tests must be a list');
    foreach ($catalog['actions'] as $identifier => $metadata) {
        if (!is_string($identifier) || $identifier === '' || !is_array($metadata)
            || !is_string($metadata['module'] ?? null) || !is_string($metadata['testContract'] ?? null)) {
            pqV4Fail('Module catalog contains an invalid action');
        }
    }
    $tests = array_fill_keys($catalog['tests'], true);
    foreach ($catalog['tests'] as $identifier) if (!is_string($identifier) || $identifier === '') pqV4Fail('Module catalog contains an invalid test');
    $validatedHandlers = 0;
    foreach ($handlers as $handler) {
        foreach ($handler['mappedActions'] as $action) {
            $metadata = $catalog['actions'][$action] ?? null;
            $runtime = $catalog['handlers'][$handler['id']] ?? null;
            if ($metadata === null || ($metadata['testContract'] ?? null) !== $handler['coverageTestId']) {
                pqV4Fail('Module catalog mismatch for ' . $handler['id']);
            }
            if (($handler['mappingSource'] ?? null) === 'runtime_action') {
                if ($runtime === null || ($runtime['action'] ?? null) !== $action
                    || ($runtime['module'] ?? null) !== $handler['module']
                    || ($runtime['testContract'] ?? null) !== $handler['coverageTestId']) {
                    pqV4Fail('Runtime module catalog mismatch for ' . $handler['id']);
                }
            } elseif ($runtime !== null && ($runtime['action'] ?? null) !== $action) {
                pqV4Fail('Policy/runtime action mismatch for ' . $handler['id']);
            }
            $validatedHandlers++;
        }
        if ($handler['coverageTestId'] !== null && !isset($tests[$handler['coverageTestId']])) {
            pqV4Fail('Module catalog test is missing: ' . $handler['coverageTestId']);
        }
    }
    return [
        'present' => true, 'validatedActions' => count($catalog['actions']),
        'validatedTests' => count($tests), 'validatedHandlers' => $validatedHandlers,
    ];
}
