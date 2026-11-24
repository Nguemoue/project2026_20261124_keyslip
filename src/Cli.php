<?php

declare(strict_types=1);

namespace Keyslip;

final class Cli
{
    /** @param list<string> $argv */
    public static function main(array $argv): int
    {
        $args = array_slice($argv, 1);
        if ($args === [] || $args[0] === '-h' || $args[0] === '--help' || $args[0] === 'help') {
            self::help();

            return $args === [] ? 2 : 0;
        }

        $command = array_shift($args);
        try {
            return match ($command) {
                'demo' => self::demo(),
                'explain' => self::explain($args),
                'scan' => self::scan($args),
                default => self::unknown($command),
            };
        } catch (\InvalidArgumentException $e) {
            fwrite(STDERR, "keyslip: " . $e->getMessage() . "\n");

            return 2;
        } catch (\JsonException $e) {
            fwrite(STDERR, "keyslip: " . $e->getMessage() . "\n");

            return 2;
        }
    }

    public static function demo(): int
    {
        $invoices = [1042 => 'paid', 1043 => 'open'];
        $paid = [1042 => 'paid'];
        $refund = [1042 => 'refunded', 1044 => 'open'];
        $stringKeys = array_merge(['id_1042' => 'paid'], ['id_1044' => 'open']);

        $out = "keyslip  the merge that deletes your ids\n\n";
        $out .= "Invoice 1042 is paid. array_merge() does not override it and does not keep it.\n";
        $out .= "The spread operator copies a map the same way. json_encode() then ships a list,\n";
        $out .= "so the id never reaches the client.\n\n";
        $out .= self::row('input', Keys::dump($invoices));
        $out .= self::row('json input', Keys::json($invoices));
        $out .= "\n";
        $out .= self::row('array_merge', Keys::dump(array_merge($invoices)) . '   KEYS LOST');
        $out .= self::row('json merged', Keys::json(array_merge($invoices)));
        $out .= self::row('spread ...', Keys::dump([...$invoices]) . '   KEYS LOST');
        $out .= self::row('json spread', Keys::json([...$invoices]));
        $out .= self::row('array_replace', Keys::dump(array_replace($invoices)) . '   keys kept');
        $out .= self::row('json replace', Keys::json(array_replace($invoices)));
        $out .= "\n";
        $out .= "The refund does not replace the payment. Both rows survive, and the id is gone:\n\n";
        $out .= self::row('left', Keys::dump($paid));
        $out .= self::row('right', Keys::dump($refund));
        $out .= self::row('array_merge', Keys::dump(array_merge($paid, $refund)) . '   KEYS LOST');
        $out .= self::row('json merged', Keys::json(array_merge($paid, $refund)));
        $out .= self::row('array_replace', Keys::dump(array_replace($paid, $refund)) . '   keys kept, right wins');
        $out .= self::row('union +', Keys::dump($paid + $refund) . '   keys kept, left wins');
        $out .= "\n";
        $out .= "String keys are left alone:\n\n";
        $out .= self::row('string keys', Keys::dump($stringKeys) . '   keys kept');
        $out .= "\n";
        $out .= "Fix: array_replace() when the right map should win, or + when the left map should.\n";
        $out .= "Do not copy a map with [...]. A list (0, 1, 2, …) can still use array_merge().\n";

        fwrite(STDOUT, $out);

        return 0;
    }

    /** @param list<string> $args */
    private static function explain(array $args): int
    {
        $json = false;
        $literals = [];
        foreach ($args as $arg) {
            if ($arg === '--json') {
                $json = true;
                continue;
            }
            if (str_starts_with($arg, '-')) {
                throw new \InvalidArgumentException('unknown option ' . $arg);
            }
            $literals[] = $arg;
        }
        if ($literals === []) {
            throw new \InvalidArgumentException('explain needs one or more array literals');
        }
        if (count($literals) > 8) {
            throw new \InvalidArgumentException('too many arrays (max 8)');
        }

        $inputs = [];
        foreach ($literals as $literal) {
            $inputs[] = Literal::parse($literal);
        }

        $merged = array_merge(...$inputs);
        $replaced = array_replace(...$inputs);
        $union = $inputs[0];
        for ($i = 1, $n = count($inputs); $i < $n; $i++) {
            $union += $inputs[$i];
        }
        $single = count($inputs) === 1;
        $spread = $single ? [...$inputs[0]] : null;
        $lost = $single
            ? Keys::losesKeys($inputs[0])
            : array_keys($merged) !== array_keys($replaced);

        if ($json) {
            $payload = [
                'lost' => $lost,
                'inputs' => array_map(static fn (array $a): string => Keys::dump($a), $inputs),
                'merge' => Keys::dump($merged),
                'replace' => Keys::dump($replaced),
                'union' => Keys::dump($union),
                'spread' => $spread === null ? null : Keys::dump($spread),
                'json_merge' => Keys::json($merged),
                'json_replace' => Keys::json($replaced),
            ];
            fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");

            return $lost ? 1 : 0;
        }

        $out = '';
        if ($single) {
            $out .= self::row('input', Keys::dump($inputs[0]));
            $out .= self::row('json input', Keys::json($inputs[0]));
            $out .= self::row('array_merge', Keys::dump($merged) . ($lost ? '   KEYS LOST' : '   keys kept'));
            $out .= self::row('json merged', Keys::json($merged));
            $out .= self::row('spread ...', Keys::dump($spread ?? []) . ($lost ? '   KEYS LOST' : '   keys kept'));
            $out .= self::row('json spread', Keys::json($spread ?? []));
            $out .= self::row('array_replace', Keys::dump($replaced) . '   keys kept');
        } else {
            foreach ($inputs as $index => $input) {
                $label = $index === 0 ? 'left' : ($index === count($inputs) - 1 && $index === 1 ? 'right' : 'input ' . ($index + 1));
                if (count($inputs) > 2) {
                    $label = 'input ' . ($index + 1);
                }
                $out .= self::row($label, Keys::dump($input));
            }
            $out .= self::row('array_merge', Keys::dump($merged) . ($lost ? '   KEYS LOST' : '   keys kept'));
            $out .= self::row('json merged', Keys::json($merged));
            $out .= self::row('array_replace', Keys::dump($replaced) . '   keys kept, right wins');
            $out .= self::row('union +', Keys::dump($union) . '   keys kept, left wins');
        }
        $out .= $lost
            ? "\nInteger keys were renumbered from 0. The id is gone, and a later value did not override an earlier one.\nUse array_replace() (right wins) or + (left wins). Keep array_merge() for lists.\n"
            : "\nKeys survived. String keys are safe, and a dense list (0..n-1) is what array_merge() is for.\n";

        fwrite(STDOUT, $out);

        return $lost ? 1 : 0;
    }

    /** @param list<string> $args */
    private static function scan(array $args): int
    {
        $json = false;
        $failOn = 'high';
        $paths = [];
        foreach ($args as $arg) {
            if ($arg === '--json') {
                $json = true;
                continue;
            }
            if (str_starts_with($arg, '--fail-on=')) {
                $failOn = substr($arg, strlen('--fail-on='));
                if (!in_array($failOn, ['high', 'medium', 'none'], true)) {
                    throw new \InvalidArgumentException('fail-on must be high, medium, or none');
                }
                continue;
            }
            if (str_starts_with($arg, '-')) {
                throw new \InvalidArgumentException('unknown option ' . $arg);
            }
            $paths[] = $arg;
        }
        if ($paths === []) {
            throw new \InvalidArgumentException('scan needs a file or directory');
        }

        $findings = [];
        foreach ($paths as $path) {
            foreach (Scanner::scan($path) as $finding) {
                $findings[] = $finding;
            }
        }

        $high = 0;
        $medium = 0;
        foreach ($findings as $finding) {
            if ($finding->severity === 'high') {
                $high++;
            } else {
                $medium++;
            }
        }

        if ($json) {
            $payload = [
                'high' => $high,
                'medium' => $medium,
                'findings' => array_map(static fn (Finding $f): array => [
                    'file' => $f->file,
                    'line' => $f->line,
                    'severity' => $f->severity,
                    'rule' => $f->rule,
                    'excerpt' => $f->excerpt,
                ], $findings),
            ];
            fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
        } elseif ($findings === []) {
            fwrite(STDOUT, "no key slips\n");
        } else {
            $out = '';
            foreach ($findings as $finding) {
                $out .= $finding->file . ':' . $finding->line . '  ' . $finding->severity . '  ' . $finding->rule . "\n";
                $out .= '  ' . $finding->excerpt . "\n";
            }
            $out .= "\n" . $high . " high, " . $medium . " medium\n";
            fwrite(STDOUT, $out);
        }

        if ($failOn === 'none') {
            return 0;
        }
        if ($failOn === 'medium') {
            return ($high + $medium) > 0 ? 1 : 0;
        }

        return $high > 0 ? 1 : 0;
    }

    private static function row(string $label, string $value): string
    {
        return sprintf("  %-14s %s\n", $label, $value);
    }

    private static function unknown(string $command): int
    {
        fwrite(STDERR, "keyslip: unknown command {$command}\n");
        self::help(STDERR);

        return 2;
    }

    private static function help($stream = STDOUT): void
    {
        fwrite($stream, <<<TXT
keyslip  the merge that deletes your ids

  php keyslip.php demo
  php keyslip.php explain '[1042 => "paid"]' '[1042 => "refunded", 1044 => "open"]'
  php keyslip.php scan src/ [--fail-on=high|medium|none] [--json]

array_merge() and [...] renumber integer keys. Invoice 1042 becomes index 0,
the old row is not overridden, and json_encode() ships a list.
String keys and real lists (0..n-1) are left alone.

scan flags array_merge / array_merge_recursive / spread when a literal
still holds an id, or a variable is named *ById, *Map, *Index, *lookup.
A line with keyslip:ignore is skipped. vendor/, node_modules/ and .git/ too.

TXT);
    }
}
