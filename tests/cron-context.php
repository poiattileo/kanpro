<?php
/**
 * tests/cron-context.php — regressão do contexto cron do GLPI.
 *
 * O cron autoloada SÓ a classe (sem include prévio de inc/acting.php).
 * Este processo replica isso: se maintenancezap.class.php não puxar acting.php
 * sozinho, pendenciaApprover() cai no fail closed e os lembretes param.
 *
 * Uso: php tests/cron-context.php (exit 1 em falha)
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
$DB->createTable('glpi_plugin_kanpro_configs');

if (!class_exists('CommonDBTM')) {
    eval('class CommonDBTM { public static $rightname = ""; }');
}

// Só a classe — NADA de inc/acting.php aqui de propósito.
require_once dirname(__DIR__) . '/inc/maintenancezap.class.php';

// Seed direto no banco dublado (sem usar kanpro_config_set de propósito:
// ele só existe se o require dentro da classe funcionou).
$DB->insert('glpi_plugin_kanpro_configs', [
    'name' => 'zap_approver',
    'value' => 'cron@exemplo.com',
    'date_creation' => date('Y-m-d H:i:s'),
]);

$got = PluginKanproMaintenanceZap::pendenciaApprover();
if ($got === 'cron@exemplo.com') {
    echo "ok - cron context lê config sem include prévio\n";
    exit(0);
}
echo 'NOT OK - cron context: esperado cron@exemplo.com, veio ' . var_export($got, true) . "\n";
exit(1);
