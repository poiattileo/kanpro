<?php
/**
 * tests/run.php — suíte do KanPro sem dependências (só PHP CLI).
 *
 * Uso: php tests/run.php
 * Saída: uma linha "ok - <nome>" por teste; exit 1 se qualquer teste falhar.
 */
require_once __DIR__ . '/boot.php';

global $DB;
$DB->createTable('glpi_plugin_kanpro_configs');

// ---------------------------------------------------------------------------
// Camada de config (kanpro_config_get/set)
// ---------------------------------------------------------------------------
kanpro_test('kanpro_config_get existe', function () {
    assert_true(function_exists('kanpro_config_get'));
});

kanpro_test('kanpro_config_set existe', function () {
    assert_true(function_exists('kanpro_config_set'));
});

kanpro_test('get em chave ausente devolve default', function () {
    assert_same('dflt', kanpro_config_get('chave_que_nao_existe', 'dflt'));
});

kanpro_test('set + get roundtrip', function () {
    assert_true(kanpro_config_set('zap_approver', 'teste@exemplo.com'));
    assert_same('teste@exemplo.com', kanpro_config_get('zap_approver', ''));
});

kanpro_test('set duas vezes atualiza (não duplica)', function () {
    global $DB;
    kanpro_config_set('zap_approver', 'um@exemplo.com');
    kanpro_config_set('zap_approver', 'dois@exemplo.com');
    assert_same('dois@exemplo.com', kanpro_config_get('zap_approver', ''));
    $n = 0;
    foreach ($DB->request(['FROM' => 'glpi_plugin_kanpro_configs', 'WHERE' => ['name' => 'zap_approver']]) as $r) {
        $n++;
    }
    assert_same(1, $n, 'deveria existir exatamente 1 linha por chave');
});

kanpro_test('get sem tabela devolve default (fail closed)', function () {
    global $DB;
    $backup = $DB;
    $DB = new FakeKanproDB(); // sem nenhuma tabela
    try {
        assert_same('dflt', kanpro_config_get('zap_approver', 'dflt'));
        assert_true(kanpro_config_set('zap_approver', 'x') === false, 'set sem tabela deve falhar');
    } finally {
        $DB = $backup;
    }
});

// ---------------------------------------------------------------------------
// Mapa de atuação (login compartilhado -> pessoa real)
// ---------------------------------------------------------------------------
kanpro_test('kanpro_acting_map existe', function () {
    assert_true(function_exists('kanpro_acting_map'));
});

kanpro_test('acting_map vazio usa legado KANPRO_ACTING_MAP', function () {
    global $DB;
    $DB->delete_rows_for_test('glpi_plugin_kanpro_configs', ['name' => 'acting_map']);
    $map = kanpro_acting_map();
    assert_same(KANPRO_ACTING_MAP, $map, 'sem config, cai no legado');
});

kanpro_test('acting_map do config sobrescreve legado', function () {
    $json = json_encode(['glpi' => 'alguem@exemplo.com']);
    if (!is_string($json)) {
        throw new RuntimeException('json_encode falhou');
    }
    kanpro_config_set('acting_map', $json);
    assert_same(['glpi' => 'alguem@exemplo.com'], kanpro_acting_map());
    global $DB;
    $DB->delete_rows_for_test('glpi_plugin_kanpro_configs', ['name' => 'acting_map']);
});

kanpro_test('acting_map com JSON inválido cai no legado', function () {
    kanpro_config_set('acting_map', '{json quebrado');
    assert_same(KANPRO_ACTING_MAP, kanpro_acting_map());
    global $DB;
    $DB->delete_rows_for_test('glpi_plugin_kanpro_configs', ['name' => 'acting_map']);
});

// ---------------------------------------------------------------------------
// WhatsApp: aprovador e destinatários via config
// ---------------------------------------------------------------------------
kanpro_test('pendenciaApprover lê zap_approver do config', function () {
    kanpro_config_set('zap_approver', 'aprovador@exemplo.com');
    assert_same('aprovador@exemplo.com', PluginKanproMaintenanceZap::pendenciaApprover());
    global $DB;
    $DB->delete_rows_for_test('glpi_plugin_kanpro_configs', ['name' => 'zap_approver']);
});

kanpro_test('pendenciaApprover sem config devolve vazio (fail closed)', function () {
    global $DB;
    $DB->delete_rows_for_test('glpi_plugin_kanpro_configs', ['name' => 'zap_approver']);
    assert_same('', PluginKanproMaintenanceZap::pendenciaApprover());
});

kanpro_test('lembreteRecipients lê zap_reminder_recipients do config', function () {
    $json = json_encode(['a@exemplo.com', 'b@exemplo.com']);
    if (!is_string($json)) {
        throw new RuntimeException('json_encode falhou');
    }
    kanpro_config_set('zap_reminder_recipients', $json);
    assert_same(['a@exemplo.com', 'b@exemplo.com'], PluginKanproMaintenanceZap::lembreteRecipients());
    global $DB;
    $DB->delete_rows_for_test('glpi_plugin_kanpro_configs', ['name' => 'zap_reminder_recipients']);
});

kanpro_test('lembreteRecipients sem config devolve vazio', function () {
    global $DB;
    $DB->delete_rows_for_test('glpi_plugin_kanpro_configs', ['name' => 'zap_reminder_recipients']);
    assert_same([], PluginKanproMaintenanceZap::lembreteRecipients());
});

kanpro_test('chamadoAbrirRecipients lê zap_chamado_recipients do config', function () {
    $json = json_encode(['c@exemplo.com']);
    if (!is_string($json)) {
        throw new RuntimeException('json_encode falhou');
    }
    kanpro_config_set('zap_chamado_recipients', $json);
    assert_same(['c@exemplo.com'], PluginKanproMaintenanceZap::chamadoAbrirRecipients());
    global $DB;
    $DB->delete_rows_for_test('glpi_plugin_kanpro_configs', ['name' => 'zap_chamado_recipients']);
});

kanpro_test('chamadoAbrirRecipients sem config devolve vazio', function () {
    global $DB;
    $DB->delete_rows_for_test('glpi_plugin_kanpro_configs', ['name' => 'zap_chamado_recipients']);
    assert_same([], PluginKanproMaintenanceZap::chamadoAbrirRecipients());
});

// ---------------------------------------------------------------------------
// Parser do POST de Configurações (kanpro_config_parse_post — puro, testável)
// ---------------------------------------------------------------------------
kanpro_test('parse_post existe', function () {
    assert_true(function_exists('kanpro_config_parse_post'));
});

kanpro_test('parse_post válido não gera erros', function () {
    $parsed = kanpro_config_parse_post([
        'zap_approver'            => 'aprovador@exemplo.com',
        'zap_reminder_recipients' => "a@exemplo.com\nb@exemplo.com\n# comentario\n\na@exemplo.com",
        'zap_chamado_recipients'  => "c@exemplo.com\r\n",
        'acting_map'              => '{"glpi":"alguem@exemplo.com"}',
    ]);
    assert_same([], $parsed['errors']);
    assert_same('aprovador@exemplo.com', $parsed['values']['zap_approver']);
    assert_same(['a@exemplo.com', 'b@exemplo.com'], json_decode($parsed['values']['zap_reminder_recipients'], true));
    assert_same(['c@exemplo.com'], json_decode($parsed['values']['zap_chamado_recipients'], true));
    assert_same(['glpi' => 'alguem@exemplo.com'], json_decode($parsed['values']['acting_map'], true));
});

kanpro_test('parse_post rejeita aprovador com espaço', function () {
    $parsed = kanpro_config_parse_post(['zap_approver' => 'nome com espaco']);
    assert_true(count($parsed['errors']) === 1, 'deveria ter 1 erro, teve ' . count($parsed['errors']));
});

kanpro_test('parse_post rejeita destinatário com espaço', function () {
    $parsed = kanpro_config_parse_post(['zap_reminder_recipients' => "ok@exemplo.com\nruim com espaco"]);
    assert_true(count($parsed['errors']) === 1, 'deveria ter 1 erro, teve ' . count($parsed['errors']));
});

kanpro_test('parse_post rejeita mapa com JSON quebrado', function () {
    $parsed = kanpro_config_parse_post(['acting_map' => '{quebrado']);
    assert_true(count($parsed['errors']) === 1, 'deveria ter 1 erro, teve ' . count($parsed['errors']));
});

kanpro_test('parse_post rejeita mapa com valor vazio', function () {
    $parsed = kanpro_config_parse_post(['acting_map' => '{"glpi":""}']);
    assert_true(count($parsed['errors']) === 1, 'deveria ter 1 erro, teve ' . count($parsed['errors']));
});

kanpro_test('parse_post tudo vazio zera (desliga envios)', function () {
    $parsed = kanpro_config_parse_post([]);
    assert_same([], $parsed['errors']);
    assert_same('', $parsed['values']['zap_approver']);
    assert_same([], json_decode($parsed['values']['zap_reminder_recipients'], true));
    assert_same([], json_decode($parsed['values']['zap_chamado_recipients'], true));
    assert_same('', $parsed['values']['acting_map']);
});

// ---------------------------------------------------------------------------
exit(kanpro_summary());
