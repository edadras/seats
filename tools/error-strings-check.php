<?php

/**
 * Every refusal the API can give a person must exist in the catalogue (ADR-0005).
 *
 * ApiException documents that the code *is* the translation key: a failure with code
 * `seat_unavailable` renders `errors.seat_unavailable` in the reader's language, falling back to
 * the English sentence the call site wrote. That fallback is a safety net for the minute between
 * writing a refusal and translating it — not a place to live. Nothing noticed when eighty-four
 * codes took up residence there, because a fallback looks exactly like a translation to anybody
 * reading English.
 *
 * So this reads every throw site with PHP's own tokeniser rather than a regular expression — these
 * are PHP files, and a second half-parser would disagree with the real one on the day it mattered
 * — works out which key each one would resolve to, and checks both directions:
 *
 *   1. every key a refusal can ask for exists in `lang/en/errors.php`;
 *   2. every key in that file is one some refusal can actually ask for, so a rename does not leave
 *      six translations of a sentence nobody will ever read.
 *
 * Run: php tools/error-strings-check.php
 */

$root = dirname(__DIR__);

/* ------------------------------------------------------------------ what the code can throw */

/**
 * The factory methods, and where the code and the explicit key sit in each one's arguments.
 *
 * `null` for `code` means the method has a fixed code of its own. `key` is the argument that
 * overrides it, where the method offers one — `not_found` says several different things, so its
 * call sites name a key instead.
 *
 * @var array<string, array{code: ?int, fixed?: string, key: ?int}>
 */
const FACTORIES = [
    'notFound' => ['code' => null, 'fixed' => 'not_found', 'key' => 1],
    'forbidden' => ['code' => null, 'fixed' => 'forbidden', 'key' => 1],
    'denied' => ['code' => 0, 'key' => null],
    'unauthorized' => ['code' => 0, 'key' => 2],
    'conflict' => ['code' => 0, 'key' => null],
    'unprocessable' => ['code' => 0, 'key' => null],
    'seatsUnavailable' => ['code' => null, 'fixed' => 'seat_unavailable', 'key' => null],
];

/** `new ApiException($code, $message, $status, $details, $messageKey)`. */
const CONSTRUCTOR = ['code' => 0, 'key' => 4];

/** @return list<array{key: string, file: string, line: int, dynamic: bool}> */
function throwSites(string $root): array
{
    $sites = [];

    foreach (sources($root) as $file) {
        $tokens = token_get_all(file_get_contents($file));
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $shape = shapeAt($tokens, $i);

            if (! $shape) {
                continue;
            }

            [$spec, $open] = $shape;
            $arguments = argumentsFrom($tokens, $open);

            $code = isset($spec['fixed'])
                ? ['values' => [$spec['fixed']], 'dynamic' => false]
                : ($arguments[$spec['code']] ?? null);

            /*
             * A factory with a code of its own can always be called without naming a key —
             * `ApiException::forbidden('…')` is a legal line to write tomorrow — so its own
             * sentence stays reachable even when every call site today names something else.
             */
            if (isset($spec['fixed'])) {
                $sites[] = [
                    'key' => $spec['fixed'],
                    'dynamic' => false,
                    'file' => substr($file, strlen($root) + 1),
                    'line' => lineAt($tokens, $i),
                ];
            }

            $key = null !== $spec['key'] ? ($arguments[$spec['key']] ?? null) : null;
            $resolved = $key ?? $code;

            if (! $resolved) {
                continue;
            }

            foreach ($resolved['values'] ?: [''] as $value) {
                $sites[] = [
                    'key' => $value,
                    'dynamic' => $resolved['dynamic'],
                    'file' => substr($file, strlen($root) + 1),
                    'line' => lineAt($tokens, $i),
                ];
            }
        }
    }

    return $sites;
}

/**
 * Whether a call to one of the factories, or to the constructor, starts here.
 *
 * @return array{0: array{code: ?int, fixed?: string, key: ?int}, 1: int}|null  the shape, and the
 *                                                                             index of its `(`
 */
function shapeAt(array $tokens, int $i): ?array
{
    // ApiException::method(
    if (isName($tokens[$i], 'ApiException')
        && isToken($tokens[$i + 1] ?? null, T_DOUBLE_COLON)
        && is_array($tokens[$i + 2] ?? null)
        && isset(FACTORIES[$tokens[$i + 2][1]])
        && '(' === ($tokens[$i + 3] ?? null)
    ) {
        return [FACTORIES[$tokens[$i + 2][1]], $i + 3];
    }

    // new ApiException(
    if (isToken($tokens[$i] ?? null, T_NEW)) {
        $j = $i + 1;

        while (isToken($tokens[$j] ?? null, T_WHITESPACE)) {
            $j++;
        }

        if (isName($tokens[$j] ?? null, 'ApiException') && '(' === ($tokens[$j + 1] ?? null)) {
            return [CONSTRUCTOR, $j + 1];
        }
    }

    return null;
}

/**
 * The top-level arguments of a call, as literals where they are literals.
 *
 * Nesting is tracked so that a `['a' => 'b']` argument counts as one, and a string that is built
 * rather than written — `'hold_'.$kind` — is reported as dynamic rather than as the literal half
 * of itself.
 *
 * @return array<int, array{value: string, dynamic: bool}>
 */
function argumentsFrom(array $tokens, int $open): array
{
    $depth = 0;
    $index = 0;
    $arguments = [];
    $current = blank();

    for ($i = $open; $i < count($tokens); $i++) {
        $token = $tokens[$i];
        $text = is_array($token) ? $token[1] : $token;

        if (in_array($text, ['(', '[', '{'], true)) {
            $depth++;

            if (1 === $depth) {
                continue;
            }
        }

        if (in_array($text, [')', ']', '}'], true)) {
            $depth--;

            if (0 === $depth) {
                $arguments[$index] = settle($current);

                break;
            }
        }

        if (1 !== $depth) {
            continue;
        }

        if (',' === $text) {
            $arguments[$index] = settle($current);
            $index++;
            $current = blank();

            continue;
        }

        if (isToken($token, T_WHITESPACE) || isToken($token, T_COMMENT) || isToken($token, T_DOC_COMMENT)) {
            continue;
        }

        $current['pieces']++;

        if (isToken($token, T_CONSTANT_ENCAPSED_STRING)) {
            $current['strings'][] = trim($text, "'\"");
        } elseif ('null' === strtolower($text)) {
            $current['null'] = true;
        } else {
            $current['other'] = true;
        }
    }

    return array_filter($arguments);
}

/**
 * What one argument turns out to be.
 *
 * `null` written out means the argument was not given — `new ApiException($code, $m, 401, [], null)`
 * is the long way of saying "no key", and the code stands. A ternary of two literals is two keys,
 * both real, so every literal is kept rather than the last one.
 *
 * @return array{values: list<string>, dynamic: bool}|null
 */
function settle(array $current): ?array
{
    if ($current['null'] && ! $current['strings']) {
        return null;
    }

    if (! $current['strings']) {
        return $current['pieces'] ? ['values' => [], 'dynamic' => true] : null;
    }

    return ['values' => $current['strings'], 'dynamic' => $current['other']];
}

function blank(): array
{
    return ['pieces' => 0, 'strings' => [], 'other' => false, 'null' => false];
}

function lineAt(array $tokens, int $i): int
{
    for ($j = $i; $j >= 0; $j--) {
        if (is_array($tokens[$j])) {
            return $tokens[$j][2];
        }
    }

    return 0;
}

function isToken($token, int $type): bool
{
    return is_array($token) && $token[0] === $type;
}

function isName($token, string $name): bool
{
    return is_array($token)
        && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
        && str_ends_with($token[1], $name);
}

/** @return list<string> */
function sources(string $root): array
{
    $files = [];

    foreach (['api/app', 'api/routes', 'api/bootstrap', 'modules'] as $where) {
        $path = $root.'/'.$where;

        if (! is_dir($path)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));

        foreach ($iterator as $file) {
            if ($file->isFile() && 'php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }
    }

    sort($files);

    return $files;
}

/**
 * Keys named directly rather than thrown.
 *
 * The exception handler picks a code and a message in a `match` and passes them on, so no call to
 * ApiException carries the literal — but `__('errors.server_error')` is right there, and a key
 * somebody writes out in full is a key somebody uses.
 *
 * @return list<string>
 */
function namedKeys(string $root): array
{
    $keys = [];

    foreach (sources($root) as $file) {
        preg_match_all("/'errors\\.([a-z0-9_]+)'/", file_get_contents($file), $matches);

        foreach ($matches[1] as $key) {
            $keys[] = $key;
        }
    }

    return array_values(array_unique($keys));
}

/* -------------------------------------------------------------------------------- the check */

$catalogue = require $root.'/api/lang/en/errors.php';
$known = [];

foreach ($catalogue as $key => $value) {
    $known[] = (string) $key;
}

$sites = throwSites($root);
$asked = [];

foreach (namedKeys($root) as $key) {
    $asked[$key] = true;
}

$missing = [];
$prefixes = [];

foreach ($sites as $site) {
    /*
     * `'hold_'.$hold->currentState()` decides its key while it runs, so which entries it can ask
     * for is not knowable from here. The literal half is kept as a prefix: everything under it is
     * treated as reachable, which is why `hold_expired` is not reported as dead. It is a weaker
     * guarantee than the rest of this file makes, and it is the honest one.
     */
    if ($site['dynamic']) {
        if ('' !== $site['key']) {
            $prefixes[$site['key']] = true;
        }

        continue;
    }

    if ('' === $site['key']) {
        continue;
    }

    $asked[$site['key']] = true;

    if (! in_array($site['key'], $known, true)) {
        $missing[$site['key']][] = $site['file'].':'.$site['line'];
    }
}

$dead = array_values(array_filter(
    array_diff($known, array_keys($asked)),
    function (string $key) use ($prefixes) {
        foreach (array_keys($prefixes) as $prefix) {
            if (str_contains($key, $prefix)) {
                return false;
            }
        }

        return true;
    }
));

$dynamic = array_values(array_unique(array_map(
    fn (array $site) => ($site['key'] ? $site['key'].'…  ' : '').$site['file'].':'.$site['line'],
    array_filter($sites, fn (array $site) => $site['dynamic'])
)));

echo 'Throw sites: '.count($sites)."\n";
echo 'Codes asked for: '.count($asked)."\n";
echo 'Catalogue keys:  '.count($known)."\n";

if ($dynamic) {
    echo "\nBuilt at run time — the prefix is taken as covering everything under it:\n";

    foreach ($dynamic as $where) {
        echo "  $where\n";
    }
}

echo str_repeat('-', 68)."\n";

if (! $missing && ! $dead) {
    echo "EVERY REFUSAL HAS A SENTENCE, AND EVERY SENTENCE A REFUSAL\n";

    exit(0);
}

if ($missing) {
    echo "\nRefusals with no entry in lang/en/errors.php — these reach a reader in English:\n";

    foreach ($missing as $key => $where) {
        echo sprintf("  %-34s %s\n", $key, implode(', ', array_slice($where, 0, 2)));
    }
}

if ($dead) {
    echo "\nEntries nothing can ask for — six translations of a sentence nobody reads:\n";

    foreach ($dead as $key) {
        echo "  $key\n";
    }
}

exit(1);
