<?php

declare(strict_types=1);

namespace Keyslip;

final class Finding
{
    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly string $severity,
        public readonly string $rule,
        public readonly string $excerpt,
    ) {
    }
}

/**
 * Token scan. Strings and comments are not code, so a mention of
 * array_merge() inside either one is ignored. Not a type checker:
 * array_merge($data, $more) is silent unless the name looks like a map
 * or a literal still has an id sitting in a key.
 */
final class Scanner
{
    private const SKIP_DIRS = ['vendor', 'node_modules', '.git'];

    /** @return list<Finding> */
    public static function scan(string $path): array
    {
        if (is_dir($path)) {
            $findings = [];
            $root = rtrim($path, '/');
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );
            $files = [];
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $full = $file->getPathname();
                if (self::skipped($full)) {
                    continue;
                }
                $files[] = $full;
            }
            sort($files);
            foreach ($files as $full) {
                $display = self::relative($full);
                foreach (self::scanSource($display, (string) file_get_contents($full)) as $finding) {
                    $findings[] = $finding;
                }
            }

            return $findings;
        }

        if (!is_file($path)) {
            throw new \InvalidArgumentException('path not found: ' . $path);
        }

        return self::scanSource(self::relative($path), (string) file_get_contents($path));
    }

    /** @return list<Finding> */
    public static function scanSource(string $file, string $code): array
    {
        if ($code === '' || (!str_contains($code, 'array_merge') && !str_contains($code, '...'))) {
            return [];
        }

        $tokens = token_get_all($code);
        $ignore = [];
        foreach ($tokens as $token) {
            if (!is_array($token)) {
                continue;
            }
            if (($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) && str_contains($token[1], 'keyslip:ignore')) {
                $ignore[$token[2]] = true;
            }
        }

        $findings = [];
        $stack = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $text = is_array($token) ? $token[1] : $token;

            if ($text === '[') {
                $stack[] = 'square';
                continue;
            }
            if ($text === '{') {
                $stack[] = 'block';
                continue;
            }
            if ($text === '(') {
                $prevIndex = self::prevSignificantIndex($tokens, $i);
                $prev = $prevIndex === null ? null : $tokens[$prevIndex];
                $beforeIndex = $prevIndex === null ? null : self::prevSignificantIndex($tokens, $prevIndex);
                $beforeName = $beforeIndex === null ? null : $tokens[$beforeIndex];
                $name = is_array($prev) ? strtolower($prev[1]) : '';
                $isArrayLiteral = is_array($prev)
                    && $prev[0] === T_STRING
                    && $name === 'array'
                    && !self::isCallTarget($beforeName);
                $stack[] = $isArrayLiteral ? 'php-array' : 'paren';
                continue;
            }
            if ($text === ']' || $text === ')' || $text === '}') {
                array_pop($stack);
                continue;
            }

            if (is_array($token) && self::isMergeName($token)) {
                $prev = self::prevSignificant($tokens, $i);
                if (self::isCallTarget($prev)) {
                    continue;
                }
                if (is_array($prev) && $prev[0] === T_NS_SEPARATOR) {
                    $nsIndex = self::prevSignificantIndex($tokens, $i);
                    $beforeNsIndex = $nsIndex === null ? null : self::prevSignificantIndex($tokens, $nsIndex);
                    $beforeNs = $beforeNsIndex === null ? null : $tokens[$beforeNsIndex];
                    if (is_array($beforeNs) && ($beforeNs[0] === T_STRING || $beforeNs[0] === T_NS_SEPARATOR)) {
                        continue;
                    }
                }
                $open = self::nextSignificantIndex($tokens, $i + 1);
                if ($open === null || (is_array($tokens[$open]) ? $tokens[$open][1] : $tokens[$open]) !== '(') {
                    continue;
                }
                if (isset($ignore[$token[2]])) {
                    continue;
                }
                $close = self::matchDelimiter($tokens, $open);
                $finding = self::analyzeMerge($file, $token[2], $code, $tokens, $open + 1, $close - 1);
                if ($finding !== null) {
                    $findings[] = $finding;
                }
                continue;
            }

            if (is_array($token) && $token[0] === T_ELLIPSIS) {
                $top = $stack[array_key_last($stack)] ?? null;
                if ($top !== 'square' && $top !== 'php-array') {
                    continue;
                }
                if (isset($ignore[$token[2]])) {
                    continue;
                }
                $limit = self::enclosingEnd($tokens, $i);
                $end = self::expressionEnd($tokens, $i + 1, $limit);
                $finding = self::analyzeSpread($file, $token[2], $code, $tokens, $i + 1, $end);
                if ($finding !== null) {
                    $findings[] = $finding;
                }
            }
        }

        return $findings;
    }

    /** @param list<mixed> $tokens */
    private static function analyzeMerge(string $file, int $line, string $code, array $tokens, int $from, int $to): ?Finding
    {
        if ($from > $to) {
            return null;
        }

        $literal = false;
        $named = false;
        $unresolved = false;
        foreach (self::splitArgs($tokens, $from, $to) as [$argFrom, $argTo]) {
            $kind = self::classifyExpression($tokens, $argFrom, $argTo);
            if ($kind === 'literal') {
                $literal = true;
            } elseif ($kind === 'map') {
                $named = true;
            } elseif ($kind === 'unresolved') {
                $unresolved = true;
            }
        }

        if ($literal) {
            return self::finding($file, $line, $code, 'high', 'merge-literal');
        }
        if ($named) {
            return self::finding($file, $line, $code, 'high', 'merge-map');
        }
        if ($unresolved) {
            return self::finding($file, $line, $code, 'medium', 'merge-unresolved');
        }

        return null;
    }

    /** @param list<mixed> $tokens */
    private static function analyzeSpread(string $file, int $line, string $code, array $tokens, int $from, int $to): ?Finding
    {
        $start = self::nextSignificantIndex($tokens, $from);
        if ($start === null || $start > $to) {
            return null;
        }
        $kind = self::classifyExpression($tokens, $start, $to);
        if ($kind === 'literal') {
            return self::finding($file, $line, $code, 'high', 'spread-literal');
        }
        if ($kind === 'map') {
            return self::finding($file, $line, $code, 'high', 'spread-map');
        }
        if ($kind === 'unresolved') {
            return self::finding($file, $line, $code, 'medium', 'spread-unresolved');
        }

        return null;
    }

    /**
     * @param list<mixed> $tokens
     * @return 'literal'|'map'|'unresolved'|'safe'
     */
    private static function classifyExpression(array $tokens, int $from, int $to): string
    {
        $start = self::nextSignificantIndex($tokens, $from);
        if ($start === null || $start > $to) {
            return 'safe';
        }
        $token = $tokens[$start];
        $text = is_array($token) ? $token[1] : $token;

        if ($text === '[') {
            return self::classifyLiteral($tokens, $start, self::matchDelimiter($tokens, $start));
        }
        if (is_array($token) && $token[0] === T_STRING && strtolower($token[1]) === 'array') {
            $open = self::nextSignificantIndex($tokens, $start + 1);
            if ($open !== null && (is_array($tokens[$open]) ? $tokens[$open][1] : $tokens[$open]) === '(') {
                return self::classifyLiteral($tokens, $open, self::matchDelimiter($tokens, $open));
            }
        }
        if ($text === '...') {
            return self::classifyExpression($tokens, $start + 1, $to);
        }

        $name = self::rootName($tokens, $start, $to);
        if ($name !== null && Keys::isMapName($name)) {
            return 'map';
        }

        return 'safe';
    }

    /**
     * @param list<mixed> $tokens
     * @return 'literal'|'unresolved'|'safe'
     */
    private static function classifyLiteral(array $tokens, int $open, int $close): string
    {
        [$built, $dynamic, $elementCount, $knownInts] = self::readLiteral($tokens, $open, $close);
        if (!$dynamic) {
            return Keys::losesKeys($built) ? 'literal' : 'safe';
        }

        foreach ($knownInts as $key) {
            if ($key < 0 || $key >= $elementCount) {
                return 'literal';
            }
        }

        return 'unresolved';
    }

    /**
     * @param list<mixed> $tokens
     * @return array{0: array<int|string, true>, 1: bool, 2: int, 3: list<int>}
     */
    private static function readLiteral(array $tokens, int $open, int $close): array
    {
        $built = [];
        $dynamic = false;
        $elements = 0;
        $knownInts = [];
        $i = $open + 1;
        while ($i < $close) {
            $i = self::nextSignificantIndex($tokens, $i) ?? $close;
            if ($i >= $close) {
                break;
            }
            $text = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
            if ($text === ',') {
                $i++;
                continue;
            }

            $arrow = self::findArrow($tokens, $i, $close);
            $comma = self::findComma($tokens, $i, $close);
            $boundary = $comma ?? $close;
            if ($arrow !== null && $arrow < $boundary && !self::isArrowFunction($tokens, $i, $arrow)) {
                $resolved = self::resolveKey($tokens, $i, $arrow - 1);
                if ($resolved === null) {
                    $dynamic = true;
                } else {
                    $built[$resolved] = true;
                    if (is_int($resolved)) {
                        $knownInts[] = $resolved;
                    } else {
                        $cast = [$resolved => true];
                        $castKey = array_key_first($cast);
                        if (is_int($castKey)) {
                            $knownInts[] = $castKey;
                        }
                    }
                }
                $elements++;
                $i = $boundary + 1;
                continue;
            }

            $built[] = true;
            $elements++;
            $i = $boundary + 1;
        }

        return [$built, $dynamic, $elements, $knownInts];
    }

    /** @param list<mixed> $tokens */
    private static function isArrowFunction(array $tokens, int $from, int $arrow): bool
    {
        for ($i = $from; $i < $arrow; $i++) {
            $token = $tokens[$i];
            if (is_array($token) && ($token[0] === T_FN || $token[0] === T_FUNCTION)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<mixed> $tokens */
    private static function resolveKey(array $tokens, int $from, int $to): int|string|null
    {
        $significant = [];
        for ($i = $from; $i <= $to; $i++) {
            if (!self::isSkippable($tokens[$i])) {
                $significant[] = $tokens[$i];
            }
        }
        if ($significant === []) {
            return null;
        }

        if (count($significant) === 2) {
            $op = is_array($significant[0]) ? $significant[0][1] : $significant[0];
            if ($op === '-' && is_array($significant[1]) && ($significant[1][0] === T_LNUMBER || $significant[1][0] === T_DNUMBER)) {
                $number = self::numberToken($significant[1]);

                return $number === null ? null : -$number;
            }
        }
        if (count($significant) !== 1 || !is_array($significant[0])) {
            return null;
        }
        $token = $significant[0];
        if ($token[0] === T_LNUMBER || $token[0] === T_DNUMBER) {
            return self::numberToken($token);
        }
        if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
            return self::unquote($token[1]);
        }

        return null;
    }

    /** @param array{0:int,1:string,2:int} $token */
    private static function numberToken(array $token): ?int
    {
        $text = str_replace('_', '', $token[1]);
        if (preg_match('/^0x[0-9a-f]+$/i', $text) === 1) {
            return (int) hexdec($text);
        }
        if (preg_match('/^[0-9]+$/', $text) === 1) {
            return (int) $text;
        }
        if ($token[0] === T_DNUMBER && is_numeric($text)) {
            return (int) (float) $text;
        }

        return null;
    }

    private static function unquote(string $token): string
    {
        $quote = $token[0];
        $body = substr($token, 1, -1);
        if ($quote === "'") {
            return str_replace(["\\\\", "\\'"], ['\\', "'"], $body);
        }

        return stripcslashes($body);
    }

    /** @param list<mixed> $tokens */
    private static function rootName(array $tokens, int $from, int $to): ?string
    {
        $i = self::nextSignificantIndex($tokens, $from);
        if ($i === null || $i > $to || !is_array($tokens[$i]) || $tokens[$i][0] !== T_VARIABLE) {
            return null;
        }
        $name = $tokens[$i][1];
        $i++;
        while (true) {
            $next = self::nextSignificantIndex($tokens, $i);
            if ($next === null || $next > $to || !is_array($tokens[$next])) {
                break;
            }
            if ($tokens[$next][0] !== T_OBJECT_OPERATOR && $tokens[$next][0] !== T_NULLSAFE_OBJECT_OPERATOR) {
                break;
            }
            $prop = self::nextSignificantIndex($tokens, $next + 1);
            if ($prop === null || $prop > $to || !is_array($tokens[$prop]) || $tokens[$prop][0] !== T_STRING) {
                return null;
            }
            $name = $tokens[$prop][1];
            $i = $prop + 1;
        }
        $after = self::nextSignificantIndex($tokens, $i);
        if ($after !== null && $after <= $to) {
            $text = is_array($tokens[$after]) ? $tokens[$after][1] : $tokens[$after];
            if ($text === '(') {
                return null;
            }
        }

        return $name;
    }

    /** @param list<mixed> $tokens */
    private static function findArrow(array $tokens, int $from, int $limit): ?int
    {
        $depth = 0;
        for ($i = $from; $i < $limit; $i++) {
            $text = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
            if ($text === '(' || $text === '[' || $text === '{') {
                $depth++;
                continue;
            }
            if ($text === ')' || $text === ']' || $text === '}') {
                if ($depth === 0) {
                    return null;
                }
                $depth--;
                continue;
            }
            if ($depth === 0 && $text === ',') {
                return null;
            }
            if ($depth === 0 && is_array($tokens[$i]) && $tokens[$i][0] === T_DOUBLE_ARROW) {
                return $i;
            }
        }

        return null;
    }

    /** @param list<mixed> $tokens */
    private static function findComma(array $tokens, int $from, int $limit): ?int
    {
        $depth = 0;
        for ($i = $from; $i < $limit; $i++) {
            $text = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
            if ($text === '(' || $text === '[' || $text === '{') {
                $depth++;
                continue;
            }
            if ($text === ')' || $text === ']' || $text === '}') {
                if ($depth === 0) {
                    return null;
                }
                $depth--;
                continue;
            }
            if ($depth === 0 && $text === ',') {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param list<mixed> $tokens
     * @return list<array{0:int,1:int}>
     */
    private static function splitArgs(array $tokens, int $from, int $to): array
    {
        $args = [];
        $start = $from;
        $depth = 0;
        $seen = false;
        for ($i = $from; $i <= $to; $i++) {
            $text = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
            if ($text === '(' || $text === '[' || $text === '{') {
                $depth++;
                $seen = true;
                continue;
            }
            if ($text === ')' || $text === ']' || $text === '}') {
                $depth--;
                $seen = true;
                continue;
            }
            if (!self::isSkippable($tokens[$i])) {
                $seen = true;
            }
            if ($depth === 0 && $text === ',') {
                if ($seen) {
                    $args[] = [$start, $i - 1];
                }
                $start = $i + 1;
                $seen = false;
            }
        }
        if ($seen) {
            $args[] = [$start, $to];
        }

        return $args;
    }

    /** @param list<mixed> $tokens */
    private static function expressionEnd(array $tokens, int $from, int $limit): int
    {
        $depth = 0;
        $last = $from;
        $count = count($tokens);
        $limit = min($limit, $count - 1);
        for ($i = $from; $i <= $limit; $i++) {
            $text = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
            if ($text === '(' || $text === '[' || $text === '{') {
                $depth++;
                $last = $i;
                continue;
            }
            if ($text === ')' || $text === ']' || $text === '}') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
                $last = $i;
                continue;
            }
            if ($depth === 0 && $text === ',') {
                break;
            }
            if (!self::isSkippable($tokens[$i])) {
                $last = $i;
            }
        }

        return $last;
    }

    /** @param list<mixed> $tokens */
    private static function enclosingEnd(array $tokens, int $ellipsis): int
    {
        $depth = 0;
        $count = count($tokens);
        for ($i = $ellipsis; $i < $count; $i++) {
            $text = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
            if ($text === '(' || $text === '[' || $text === '{') {
                $depth++;
                continue;
            }
            if ($text === ')' || $text === ']' || $text === '}') {
                if ($depth === 0) {
                    return $i;
                }
                $depth--;
            }
        }

        return $count - 1;
    }

    /** @param list<mixed> $tokens */
    private static function matchDelimiter(array $tokens, int $open): int
    {
        $depth = 0;
        $count = count($tokens);
        for ($i = $open; $i < $count; $i++) {
            $text = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
            if ($text === '(' || $text === '[' || $text === '{') {
                $depth++;
                continue;
            }
            if ($text === ')' || $text === ']' || $text === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return $count - 1;
    }

    /** @param list<mixed> $tokens */
    private static function nextSignificantIndex(array $tokens, int $from): ?int
    {
        $count = count($tokens);
        for ($i = $from; $i < $count; $i++) {
            if (!self::isSkippable($tokens[$i])) {
                return $i;
            }
        }

        return null;
    }

    /** @param list<mixed> $tokens */
    private static function prevSignificantIndex(array $tokens, int $index): ?int
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            if (!self::isSkippable($tokens[$i])) {
                return $i;
            }
        }

        return null;
    }

    /** @param list<mixed> $tokens */
    private static function prevSignificant(array $tokens, int $index): mixed
    {
        $found = self::prevSignificantIndex($tokens, $index);

        return $found === null ? null : $tokens[$found];
    }

    private static function isMergeName(array $token): bool
    {
        $names = ['array_merge', 'array_merge_recursive'];
        if ($token[0] === T_STRING && in_array(strtolower($token[1]), $names, true)) {
            return true;
        }
        if (defined('T_NAME_FULLY_QUALIFIED') && $token[0] === T_NAME_FULLY_QUALIFIED) {
            return in_array(strtolower(ltrim($token[1], '\\')), $names, true);
        }

        return false;
    }

    private static function isCallTarget(mixed $token): bool
    {
        return is_array($token) && in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true);
    }

    private static function isSkippable(mixed $token): bool
    {
        return is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }

    private static function finding(string $file, int $line, string $code, string $severity, string $rule): Finding
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $code));
        $excerpt = preg_replace('/\s+/', ' ', trim($lines[$line - 1] ?? '')) ?? '';
        if (strlen($excerpt) > 140) {
            $excerpt = substr($excerpt, 0, 137) . '...';
        }

        return new Finding($file, $line, $severity, $rule, $excerpt);
    }

    private static function skipped(string $path): bool
    {
        $parts = explode('/', str_replace('\\', '/', $path));
        foreach ($parts as $part) {
            if (in_array($part, self::SKIP_DIRS, true)) {
                return true;
            }
        }

        return false;
    }

    private static function relative(string $path): string
    {
        $real = realpath($path) ?: $path;
        $cwd = getcwd();
        $realCwd = $cwd !== false ? (realpath($cwd) ?: $cwd) : '';
        $prefix = $realCwd . DIRECTORY_SEPARATOR;
        if ($realCwd !== '' && str_starts_with($real, $prefix)) {
            return substr($real, strlen($prefix));
        }

        return $path;
    }
}
