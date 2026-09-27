<?php
declare(strict_types=1);

/** Minimal PHP lexer for installations built without ext-tokenizer. */
function pqV4FallbackExecutableTokens(string $source): array
{
    $tokens = [];
    $length = strlen($source);
    $offset = 0;
    $inPhp = false;
    while ($offset < $length) {
        if (!$inPhp) {
            $open = strpos($source, '<?', $offset);
            if ($open === false) {
                break;
            }
            $offset = $open + 2;
            if (strncasecmp(substr($source, $offset, 3), 'php', 3) === 0) {
                $offset += 3;
            } elseif (($source[$offset] ?? '') === '=') {
                $offset++;
            } else {
                continue;
            }
            $inPhp = true;
            continue;
        }
        if (substr($source, $offset, 2) === '?>') {
            $inPhp = false;
            $offset += 2;
            continue;
        }
        $char = $source[$offset];
        if (ctype_space($char)) {
            $offset++;
            continue;
        }
        if (substr($source, $offset, 2) === '//' || $char === '#') {
            $newline = strpos($source, "\n", $offset + 1);
            $offset = $newline === false ? $length : $newline + 1;
            continue;
        }
        if (substr($source, $offset, 2) === '/*') {
            $end = strpos($source, '*/', $offset + 2);
            $offset = $end === false ? $length : $end + 2;
            continue;
        }
        if (substr($source, $offset, 3) === '<<<') {
            $lineEnd = strpos($source, "\n", $offset + 3);
            if ($lineEnd === false) {
                break;
            }
            $declaration = trim(substr($source, $offset + 3, $lineEnd - $offset - 3), " \t\r'\"");
            $marker = preg_replace('/[^A-Za-z0-9_].*$/', '', $declaration) ?: '';
            $pattern = $marker === '' ? null : '/^' . preg_quote($marker, '/') . ';?\s*$/m';
            if ($pattern !== null && preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE, $lineEnd + 1) === 1) {
                $offset = $match[0][1] + strlen($match[0][0]);
            } else {
                $offset = $length;
            }
            continue;
        }
        if ($char === "'" || $char === '"' || $char === '`') {
            $quote = $char;
            $offset++;
            while ($offset < $length) {
                if ($source[$offset] === '\\') {
                    $offset += 2;
                    continue;
                }
                if ($source[$offset] === $quote) {
                    $offset++;
                    break;
                }
                $offset++;
            }
            continue;
        }
        $pair = substr($source, $offset, 2);
        if ($pair === '->' || $pair === '::') {
            $tokens[] = ['id' => null, 'text' => $pair];
            $offset += 2;
            continue;
        }
        if (preg_match('/\G[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*/A', $source, $match, 0, $offset) === 1) {
            $tokens[] = ['id' => null, 'text' => strtolower($match[0])];
            $offset += strlen($match[0]);
            continue;
        }
        if (str_contains('(){}[],;=.!+-*/<>?:', $char)) {
            $tokens[] = ['id' => null, 'text' => strtolower($char)];
        }
        $offset++;
    }
    return $tokens;
}
