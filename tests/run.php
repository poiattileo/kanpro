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
// Validador de contato por entidade (kanpro_entitycontact_validate — puro)
// Modelo: 1 linha = 1 valor; o tipo é inferido pelo campo preenchido
// (só e-mail ou só telefone por vez) — sem select de tipo no form.
// ---------------------------------------------------------------------------
kanpro_test('entitycontact_validate existe', function () {
    assert_true(function_exists('kanpro_entitycontact_validate'));
});

kanpro_test('entitycontact_validate aceita e-mail', function () {
    $r = kanpro_entitycontact_validate([
        'entities_id' => '3', 'name' => 'Diretoria',
        'email' => '  Diretoria@Exemplo.com ', 'phone' => '', 'is_active' => '1',
    ]);
    assert_same([], $r['errors']);
    assert_same(3, $r['values']['entities_id']);
    assert_same('email', $r['values']['kind']);
    assert_same('Diretoria', $r['values']['name']);
    assert_same('diretoria@exemplo.com', $r['values']['email']);
    assert_same('', $r['values']['phone']);
    assert_same(1, $r['values']['is_active']);
});

kanpro_test('entitycontact_validate aceita whatsapp', function () {
    $r = kanpro_entitycontact_validate([
        'entities_id' => '1', 'name' => 'Plantão',
        'email' => '', 'phone' => '(11) 99999-9999',
    ]);
    assert_same([], $r['errors']);
    assert_same('phone', $r['values']['kind']);
    assert_same('11999999999', $r['values']['phone']);
    assert_same('', $r['values']['email']);
    assert_same(0, $r['values']['is_active'], 'ausente = inativo (checkbox desmarcado)');
});

kanpro_test('entitycontact_validate exige entidade', function () {
    $r = kanpro_entitycontact_validate(['entities_id' => '0', 'name' => 'X', 'email' => 'a@b.com', 'phone' => '']);
    assert_true(count($r['errors']) > 0, 'deveria ter erro de entidade');
});

kanpro_test('entitycontact_validate exige nome', function () {
    $r = kanpro_entitycontact_validate(['entities_id' => '1', 'name' => '  ', 'email' => 'a@b.com', 'phone' => '']);
    assert_true(count($r['errors']) > 0, 'deveria ter erro de nome');
});

kanpro_test('entitycontact_validate rejeita os dois preenchidos', function () {
    $r = kanpro_entitycontact_validate(['entities_id' => '1', 'name' => 'X', 'email' => 'a@b.com', 'phone' => '11999999999']);
    assert_true(count($r['errors']) > 0, '1 linha = 1 valor: deveria falhar');
});

kanpro_test('entitycontact_validate email exige e-mail válido', function () {
    $r = kanpro_entitycontact_validate(['entities_id' => '1', 'name' => 'X', 'email' => '', 'phone' => '']);
    assert_true(count($r['errors']) > 0, 'nenhum preenchido deveria falhar');
    $r = kanpro_entitycontact_validate(['entities_id' => '1', 'name' => 'X', 'email' => 'nao-email', 'phone' => '']);
    assert_true(count($r['errors']) > 0, 'deveria ter erro de e-mail');
});

kanpro_test('entitycontact_validate phone exige telefone válido', function () {
    $r = kanpro_entitycontact_validate(['entities_id' => '1', 'name' => 'X', 'email' => '', 'phone' => '123']);
    assert_true(count($r['errors']) > 0, 'deveria ter erro de telefone');
});

// ---------------------------------------------------------------------------
// Merge de destinatários + enqueue force (Retirada)
// ---------------------------------------------------------------------------
kanpro_test('mergeNotifyPhones existe e deduplica (primeiro vence)', function () {
    $merged = PluginKanproMaintenanceZap::mergeNotifyPhones([
        ['phone' => '5511999999999', 'label' => 'Diretoria'],
        ['phone' => '5511999999999', 'label' => 'Telefone da escola'],
        ['phone' => '', 'label' => 'Vazio'],
        ['phone' => '5511888888888', 'label' => 'Plantão'],
    ]);
    assert_same(['5511999999999' => 'Diretoria', '5511888888888' => 'Plantão'], $merged);
});

kanpro_test('enqueue force ignora alreadySent', function () {
    global $DB;
    $DB->createTable('glpi_plugin_kanpro_zapqueue');
    $DB->createTable('glpi_plugin_kanpro_maintenance_zaplog');
    foreach (['glpi_plugin_kanpro_zapqueue', 'glpi_plugin_kanpro_maintenance_zaplog'] as $t) {
        $DB->delete($t, []);
    }
    $DB->insert('glpi_plugin_kanpro_maintenance_zaplog', [
        'plugin_kanpro_cards_id' => 77, 'milestone' => 'retirada_notify', 'phone' => '5511999999999',
        'success' => 1, 'detail' => '', 'date_creation' => date('Y-m-d H:i:s'),
    ]);
    $normal = PluginKanproMaintenanceZap::enqueue('retirada', 77, 'retirada_notify', '5511999999999', 't');
    assert_true(empty($normal['ok']), 'sem force deveria barrar');
    $forced = PluginKanproMaintenanceZap::enqueue('retirada', 77, 'retirada_notify', '5511999999999', 't', true, true);
    assert_true(!empty($forced['ok']), 'com force deveria aceitar: ' . json_encode($forced));
});

// ---------------------------------------------------------------------------
exit(kanpro_summary());
