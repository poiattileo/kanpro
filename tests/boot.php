<?php
/**
 * tests/boot.php — boot comum das suítes (CLI only, sem dependências).
 *
 * Cria $DB dublado (FakeDB), carrega stubs do GLPI e a classe sob teste
 * (que puxa inc/acting.php sozinha). Cada suíte tem seus contadores —
 * os processos são separados.
 */
if (php_sapi_name() !== 'cli') {
    die("CLI only\n");
}
error_reporting(E_ALL);

// O GLPI exige mbstring; o PHP CLI local pode não ter. Polyfill mínimo,
// só p/ os testes rodarem aqui (dados ASCII — semântica preservada).
if (!function_exists('mb_substr')) {
    function mb_substr(string $string, int $start, ?int $length = null, ?string $encoding = null): string {
        return $length === null ? substr($string, $start) : substr($string, $start, $length);
    }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $string, ?string $encoding = null): string {
        return strtolower($string);
    }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $string, ?string $encoding = null): int {
        return strlen($string);
    }
}

// Os includes do plugin barram acesso direto sem GLPI_ROOT.
if (!defined('GLPI_ROOT')) {
    define('GLPI_ROOT', __DIR__ . '/fixtures');
}

require_once __DIR__ . '/FakeDB.php';
require_once __DIR__ . '/stubs.php';

global $DB;
$DB = new FakeKanproDB();

require_once dirname(__DIR__) . '/inc/maintenancezap.class.php';

$failures = 0;
$passes = 0;

/** @param callable():void $fn */
function kanpro_test(string $name, callable $fn): void {
    global $failures, $passes;
    try {
        $fn();
        $passes++;
        echo "ok - {$name}\n";
    } catch (Throwable $e) {
        $failures++;
        echo "NOT OK - {$name} (" . get_class($e) . ': ' . $e->getMessage() . ")\n";
    }
}

function assert_true(bool $cond, string $msg = 'assertion failed'): void {
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}

function assert_same(mixed $expected, mixed $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        throw new RuntimeException(
            ($msg !== '' ? $msg . ' — ' : '') .
            'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

function kanpro_exit_code(int $failures): int {
    return $failures > 0 ? 1 : 0;
}

/** Imprime o resumo e devolve o exit code (a suíte chama exit com ele). */
function kanpro_summary(): int {
    global $failures, $passes;
    echo "\n{$passes} passed, {$failures} failed\n";
    return kanpro_exit_code($failures);
}
