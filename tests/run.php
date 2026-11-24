<?php

declare(strict_types=1);

require dirname(__DIR__) . '/keyslip.php';

use Keyslip\Keys;
use Keyslip\Literal;
use Keyslip\Scanner;

$fail = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $fail;
    if ($ok) {
        fwrite(STDOUT, "  ok  {$name}\n");

        return;
    }
    $fail++;
    fwrite(STDOUT, "  FAIL {$name}" . ($detail !== '' ? "  {$detail}" : '') . "\n");
}

function rulesOf(string $path): array
{
    $rules = array_map(static fn ($f) => $f->rule . ':' . $f->severity, Scanner::scan($path));
    sort($rules);

    return $rules;
}

fwrite(STDOUT, "literals\n");
$paid = Literal::parse('[1042 => "paid", 1043 => "open"]');
check('int keys', array_keys($paid) === [1042, 1043], json_encode(array_keys($paid)));
check('php cast quoted', array_keys(Literal::parse("['1042' => 'paid']")) === [1042]);
check('leading zero stays', array_keys(Literal::parse("['08' => 'a']")) === ['08']);
check('string keys', array_keys(Literal::parse('["id_1042" => "paid"]')) === ['id_1042']);
check('json object casts', array_keys(Literal::parse('{"1042":"paid","1043":"open"}')) === [1042, 1043]);
check('json 08', array_keys(Literal::parse('{"08":"a"}')) === ['08']);
check('trailing comma', Literal::parse("array(1042 => 'paid',)") === [1042 => 'paid']);
check('list json', Literal::parse('[1, 2, 3]') === [1, 2, 3]);
check('nested', Literal::parse('[[1042 => "a"]]') === [[1042 => 'a']]);
check('negative', array_keys(Literal::parse('[-2 => "a", -1 => "b"]')) === [-2, -1]);
check('separator', Literal::parse('[1_042 => "paid"]') === [1042 => 'paid']);
check('loses id', Keys::losesKeys($paid) === true);
check('keeps string', Keys::losesKeys(['id_1042' => 'paid']) === false);
check('keeps list', Keys::losesKeys(['paid', 'open']) === false);
check('loses hole', Keys::losesKeys([0 => 'a', 2 => 'b']) === true);
check('map name by id', Keys::isMapName('ordersById'));
check('map name snake', Keys::isMapName('invoice_map'));
check('not a list name', Keys::isMapName('rows') === false);
check('not userId', Keys::isMapName('userId') === false);
check('not mapping', Keys::isMapName('mapping') === false);

$bad = false;
try {
    Literal::parse('$ordersById');
} catch (InvalidArgumentException) {
    $bad = true;
}
check('rejects variables', $bad);

fwrite(STDOUT, "scanner\n");
$dirty = rulesOf(dirname(__DIR__) . '/examples/dirty');
check(
    'dirty rules',
    $dirty === [
        'merge-literal:high',
        'merge-literal:high',
        'merge-map:high',
        'merge-map:high',
        'merge-unresolved:medium',
        'spread-literal:high',
        'spread-map:high',
        'spread-unresolved:medium',
    ],
    json_encode($dirty)
);
check('clean silent', rulesOf(dirname(__DIR__) . '/examples/clean') === []);

$tree = Scanner::scan(dirname(__DIR__) . '/examples');
$files = array_map(static fn ($f) => $f->file, $tree);
check('skips vendor', !array_filter($files, static fn (string $f) => str_contains($f, 'Hidden.php')));
check('tree still sees dirty', array_filter($files, static fn (string $f) => str_contains($f, 'dirty')) !== []);

$hole = Scanner::scanSource('hole.php', "<?php\n\$a = array_merge([0 => 'a', 2 => 'b']);\n");
check('hole literal', count($hole) === 1 && $hole[0]->rule === 'merge-literal');

$arrow = Scanner::scanSource('fn.php', "<?php\n\$a = array_merge([fn () => 1], [fn () => 2]);\n");
check('arrow functions are a list', $arrow === []);

$unpack = Scanner::scanSource('up.php', "<?php\nfoo(...\$ordersById);\n");
check('argument unpack is not a copy', $unpack === []);

$variadic = Scanner::scanSource('var.php', "<?php\n\$a = array_merge(...\$ordersById);\n");
check('merge variadic map', count($variadic) === 1 && $variadic[0]->rule === 'merge-map');

$ns = Scanner::scanSource('ns.php', "<?php\n\$a = Foo\\array_merge(\$ordersById);\n");
check('namespaced call ignored', $ns === []);

$root = Scanner::scanSource('root.php', "<?php\n\$a = \\array_merge(\$ordersById);\n");
check('root array_merge flagged', count($root) === 1 && $root[0]->rule === 'merge-map');

$certain = Scanner::scanSource('mix.php', "<?php\n\$a = array_merge([1042 => 'paid', \$id => \$row]);\n");
check('known id plus dynamic is high', count($certain) === 1 && $certain[0]->severity === 'high');

$prop = Scanner::scanSource('prop.php', "<?php\n\$a = [...\$this->ordersById];\n");
check('property map', count($prop) === 1 && $prop[0]->rule === 'spread-map');

$method = Scanner::scanSource('meth.php', "<?php\n\$a = [...\$this->ordersById()];\n");
check('method result not guessed', $method === []);

$self = rulesOf(dirname(__DIR__) . '/src');
check('scanner does not flag itself', $self === [], json_encode($self));

fwrite(STDOUT, "cli\n");

function run(array $args): array
{
    $cmd = array_merge([PHP_BINARY, dirname(__DIR__) . '/keyslip.php'], $args);
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
    if (!is_resource($proc)) {
        return [127, '', 'proc_open failed'];
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($proc), $out, $err];
}

[$code, $out, $err] = run(['demo']);
check('demo exit', $code === 0, (string) $code);
check('demo shows the loss', str_contains($out, '[0 => "paid", 1 => "refunded", 2 => "open"]') && str_contains($out, 'KEYS LOST'));
check('demo json list', str_contains($out, '["paid","refunded","open"]'));
check('demo keeps replace', str_contains($out, '[1042 => "refunded", 1044 => "open"]'));
check('demo stderr quiet', $err === '');

[$code, $out] = run(['explain', '[1042 => "paid"]', '[1042 => "refunded", 1044 => "open"]']);
check('explain lost exit', $code === 1, (string) $code);
check('explain lost text', str_contains($out, 'KEYS LOST') && str_contains($out, 'did not override'));

[$code, $out] = run(['explain', '["id_1042" => "paid"]', '["id_1044" => "open"]']);
check('explain string exit', $code === 0, (string) $code . ' ' . $out);
check('explain string text', str_contains($out, 'keys kept') && str_contains($out, 'Keys survived'));

[$code, $out] = run(['explain', '--json', '{"1042":"paid"}']);
check('explain json exit', $code === 1);
$payload = json_decode($out, true);
check('explain json payload', is_array($payload) && $payload['lost'] === true && $payload['json_merge'] === '["paid"]');

[$code, $out] = run(['scan', 'examples/dirty']);
check('scan dirty exit', $code === 1);
check('scan dirty body', str_contains($out, 'merge-map') && str_contains($out, 'high'));

[$code, $out] = run(['scan', 'examples/clean']);
check('scan clean exit', $code === 0, $out);
check('scan clean text', str_contains($out, 'no key slips'));

[$code, $out] = run(['scan', 'examples/dirty', '--fail-on=none']);
check('fail-on none', $code === 0 && str_contains($out, 'merge-literal'));

[$code, $out] = run(['scan', 'examples/dirty/Dynamic.php', '--json']);
$payload = json_decode($out, true);
check('dynamic json', $code === 0 && is_array($payload) && $payload['high'] === 0 && $payload['medium'] === 2);

[$code, $out] = run(['scan', 'examples/dirty/Dynamic.php', '--fail-on=medium']);
check('fail-on medium', $code === 1);

[$code] = run(['nope']);
check('unknown command', $code === 2);

[$code] = run(['explain']);
check('explain usage', $code === 2);

fwrite(STDOUT, $fail === 0 ? "\nall passed\n" : "\n{$fail} failed\n");
exit($fail === 0 ? 0 : 1);
