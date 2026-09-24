<?php
declare(strict_types=1);

if (!function_exists('cleanApiMessage')) {
  function cleanApiMessage(mixed $value): string {
    $message = (string)($value ?? 'Unknown ProjeQtOr validation error');
    $decoded = json_decode($message, true);
    if (is_string($decoded)) $message = $decoded;
    $message = preg_replace('/<br\s*\/?\s*>/i', ' ', $message);
    $message = strip_tags((string)$message);
    $message = html_entity_decode($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim((string)preg_replace('/\s+/', ' ', $message));
  }
}
