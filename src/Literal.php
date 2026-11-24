<?php

declare(strict_types=1);

namespace Keyslip;

/**
 * Parses a PHP array literal or a JSON array/object. No eval.
 * Keys go through real PHP assignment, so "1042" becomes int 1042
 * and "08" stays a string — the same cast array_merge() will see.
 */
final class Literal
{
    private string $s;

    private int $i = 0;

    private int $n;

    private function __construct(string $src)
    {
        $this->s = $src;
        $this->n = strlen($src);
    }

    /** @return array<int|string, mixed> */
    public static function parse(string $src): array
    {
        $trim = trim($src);
        if ($trim === '') {
            throw new \InvalidArgumentException('empty input');
        }

        $phpish = str_contains($trim, '=>') || preg_match('/\barray\s*\(/i', $trim) === 1;
        $jsonError = null;
        if (!$phpish) {
            try {
                $decoded = json_decode($trim, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($decoded)) {
                    throw new \InvalidArgumentException('expected a JSON array or object');
                }

                return $decoded;
            } catch (\JsonException $e) {
                $jsonError = $e->getMessage();
            }
        }

        try {
            $parser = new self($trim);
            $value = $parser->parseValue();
            $parser->skip();
            if ($parser->i < $parser->n) {
                throw new \InvalidArgumentException('trailing characters');
            }
            if (!is_array($value)) {
                throw new \InvalidArgumentException('expected an array');
            }

            return $value;
        } catch (\InvalidArgumentException $e) {
            if ($jsonError !== null) {
                throw new \InvalidArgumentException($e->getMessage() . ' (also not JSON: ' . $jsonError . ')');
            }
            throw $e;
        }
    }

    private function parseValue(): mixed
    {
        $this->skip();
        if ($this->i >= $this->n) {
            throw new \InvalidArgumentException('unexpected end of input');
        }

        $c = $this->s[$this->i];
        if ($c === '[') {
            return $this->parseArray(']');
        }
        if (preg_match('/\Garray\b/i', $this->s, $match, 0, $this->i) === 1) {
            $this->i += strlen($match[0]);
            $this->skip();
            if (($this->s[$this->i] ?? '') !== '(') {
                throw new \InvalidArgumentException('expected "(" after array');
            }

            return $this->parseArray(')');
        }
        if ($c === '"' || $c === "'") {
            return $this->parseString();
        }
        if ($c === '-' || $c === '+' || ctype_digit($c)) {
            return $this->parseNumber();
        }
        if (preg_match('/\G(true|false|null)\b/i', $this->s, $word, 0, $this->i) === 1) {
            $this->i += strlen($word[0]);

            return match (strtolower($word[0])) {
                'true' => true,
                'false' => false,
                default => null,
            };
        }
        if ($c === '$') {
            throw new \InvalidArgumentException('variables are not allowed here; pass a literal or JSON');
        }

        throw new \InvalidArgumentException('unexpected character at offset ' . $this->i);
    }

    /** @return array<int|string, mixed> */
    private function parseArray(string $end): array
    {
        $this->i++; // [ or (
        $array = [];
        while (true) {
            $this->skip();
            if ($this->i >= $this->n) {
                throw new \InvalidArgumentException('unclosed array');
            }
            if ($this->s[$this->i] === $end) {
                $this->i++;
                break;
            }
            if ($this->s[$this->i] === ',') {
                $this->i++;
                continue;
            }

            if ($this->hasExplicitKey()) {
                $key = $this->parseKey();
                $this->skip();
                if (substr($this->s, $this->i, 2) !== '=>') {
                    throw new \InvalidArgumentException('expected =>');
                }
                $this->i += 2;
                $array[$key] = $this->parseValue();
            } else {
                $array[] = $this->parseValue();
            }

            $this->skip();
            if ($this->i < $this->n && $this->s[$this->i] === ',') {
                $this->i++;
                continue;
            }
            if ($this->i < $this->n && $this->s[$this->i] === $end) {
                $this->i++;
                break;
            }

            throw new \InvalidArgumentException('expected "," or end of array');
        }

        return $array;
    }

    private function hasExplicitKey(): bool
    {
        $i = $this->i;
        $depth = 0;
        while ($i < $this->n) {
            $c = $this->s[$i];
            if ($c === '"' || $c === "'") {
                $quote = $c;
                $i++;
                while ($i < $this->n) {
                    if ($this->s[$i] === '\\') {
                        $i += 2;
                        continue;
                    }
                    if ($this->s[$i] === $quote) {
                        $i++;
                        break;
                    }
                    $i++;
                }
                continue;
            }
            if ($c === '/' && ($this->s[$i + 1] ?? '') === '/') {
                while ($i < $this->n && $this->s[$i] !== "\n") {
                    $i++;
                }
                continue;
            }
            if ($c === '/' && ($this->s[$i + 1] ?? '') === '*') {
                $i += 2;
                while ($i < $this->n && !($this->s[$i] === '*' && ($this->s[$i + 1] ?? '') === '/')) {
                    $i++;
                }
                $i += 2;
                continue;
            }
            if ($c === '(' || $c === '[') {
                $depth++;
                $i++;
                continue;
            }
            if ($c === ')' || $c === ']') {
                if ($depth === 0) {
                    return false;
                }
                $depth--;
                $i++;
                continue;
            }
            if ($depth === 0 && $c === ',') {
                return false;
            }
            if ($depth === 0 && $c === '=' && ($this->s[$i + 1] ?? '') === '>') {
                return true;
            }
            $i++;
        }

        return false;
    }

    private function parseKey(): int|string
    {
        $this->skip();
        $c = $this->s[$this->i] ?? '';
        if ($c === '"' || $c === "'") {
            $raw = $this->parseString();
            $cast = [$raw => true];

            return array_key_first($cast);
        }

        return $this->parseNumber();
    }

    private function parseString(): string
    {
        $quote = $this->s[$this->i];
        $this->i++;
        $body = '';
        while ($this->i < $this->n) {
            $c = $this->s[$this->i];
            if ($c === '\\') {
                $next = $this->s[$this->i + 1] ?? '';
                if ($quote === "'") {
                    $body .= ($next === "'" || $next === '\\') ? $next : ('\\' . $next);
                    $this->i += 2;
                    continue;
                }
                $body .= match ($next) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    '"', '\\', '$', '/' => $next,
                    default => $next,
                };
                $this->i += 2;
                continue;
            }
            if ($c === $quote) {
                $this->i++;

                return $body;
            }
            $body .= $c;
            $this->i++;
        }

        throw new \InvalidArgumentException('unclosed string');
    }

    private function parseNumber(): int
    {
        $this->skip();
        $sign = 1;
        if (($this->s[$this->i] ?? '') === '+' || ($this->s[$this->i] ?? '') === '-') {
            if ($this->s[$this->i] === '-') {
                $sign = -1;
            }
            $this->i++;
        }
        if ($this->i >= $this->n || !ctype_digit($this->s[$this->i])) {
            throw new \InvalidArgumentException('expected an integer key or value');
        }
        $start = $this->i;
        while ($this->i < $this->n && (ctype_digit($this->s[$this->i]) || $this->s[$this->i] === '_')) {
            $this->i++;
        }
        $digits = str_replace('_', '', substr($this->s, $start, $this->i - $start));
        if ($digits === '' || !ctype_digit($digits)) {
            throw new \InvalidArgumentException('bad integer');
        }

        return $sign * (int) $digits;
    }

    private function skip(): void
    {
        while ($this->i < $this->n) {
            $c = $this->s[$this->i];
            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") {
                $this->i++;
                continue;
            }
            if ($c === '/' && ($this->s[$this->i + 1] ?? '') === '/') {
                while ($this->i < $this->n && $this->s[$this->i] !== "\n") {
                    $this->i++;
                }
                continue;
            }
            if ($c === '/' && ($this->s[$this->i + 1] ?? '') === '*') {
                $this->i += 2;
                while ($this->i < $this->n && !($this->s[$this->i] === '*' && ($this->s[$this->i + 1] ?? '') === '/')) {
                    $this->i++;
                }
                $this->i = min($this->n, $this->i + 2);
                continue;
            }
            if ($c === '#') {
                while ($this->i < $this->n && $this->s[$this->i] !== "\n") {
                    $this->i++;
                }
                continue;
            }
            break;
        }
    }
}
