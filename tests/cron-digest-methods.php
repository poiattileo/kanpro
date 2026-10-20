<?php
/**
 * tests/cron-digest-methods.php — regressão: wrappers dos crons de digest.
 *
 * registerCron() cria 4 tasks em glpi_crontasks (zapdigest8/10/13/15) e o
 * GLPI dispatcha por `cron` + nome da task (zapdigest15 → cronZapdigest15).
 * Sem esses métodos, o runner do GLPI loga a cada execução:
 *   "Função indefinida PluginKanproMaintenanceZap::cronzapdigestN (para cron)"
 * (63 ocorrências no log php-errors.log de 19/10/2026.)
 *
 * O slot (8/10/13/15) vem em task->fields['param']; os wrappers apenas
 * delegam para cronZapdigest(), que já trata o param.
 *
 * Uso: php tests/cron-digest-methods.php (exit 1 em falha)
 */
if (php_sapi_name() !== 'cli') {
    die("CLI only\n");
}
error_reporting(E_ALL);

if (!defined('GLPI_ROOT')) {
    define('GLPI_ROOT', __DIR__ . '/fixtures');
}

require_once __DIR__ . '/FakeDB.php';

global $DB;
$DB = new FakeKanproDB();

if (!class_exists('CommonDBTM')) {
    eval('class CommonDBTM { public static string $rightname = ""; }');
}

// Só a classe — o require interno de acting.php é parte do contrato
// (ver tests/cron-context.php).
require_once dirname(__DIR__) . '/inc/maintenancezap.class.php';

$failures = 0;
foreach ([8, 10, 13, 15] as $slot) {
    $method = "cronZapdigest{$slot}";
    if (!method_exists('PluginKanproMaintenanceZap', $method)) {
        echo "NOT OK - metodo {$method} existe (GLPI chama cron+nome da task)\n";
        $failures++;
        continue;
    }
    echo "ok - metodo {$method} existe\n";
}
exit($failures > 0 ? 1 : 0);
