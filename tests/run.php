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

kanpro_test('listaPendenteRecipients lê zap_chamado_recipients do config', function () {
    $json = json_encode(['wellington.paiva', 'marco.masson@apoiofde.sp.gov.br']);
    if (!is_string($json)) {
        throw new RuntimeException('json_encode falhou');
    }
    kanpro_config_set('zap_chamado_recipients', $json);
    assert_same(['wellington.paiva', 'marco.masson@apoiofde.sp.gov.br'], PluginKanproMaintenanceZap::listaPendenteRecipients());
    global $DB;
    $DB->delete_rows_for_test('glpi_plugin_kanpro_configs', ['name' => 'zap_chamado_recipients']);
});

kanpro_test('lista_pendente é tipo permitido e enfileirável', function () {
    assert_true(in_array('lista_pendente', PluginKanproMaintenanceZap::allowedTypes(), true));
    assert_true(in_array('lista_pendente', PluginKanproMaintenanceZap::queueableKinds(), true));
});

kanpro_test('recipientMilestone de lista_pendente cabe em 30 chars', function () {
    $ms = PluginKanproMaintenanceZap::recipientMilestone('lista_pendente', 'gabriel.siqueira@apoiofde.sp.gov.br');
    assert_true(mb_strlen($ms) <= 30, 'milestone estourou 30 chars: ' . $ms);
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

kanpro_test('entitycontact_validate aceita sem nome', function () {
    $r = kanpro_entitycontact_validate(['entities_id' => '1', 'name' => '  ', 'email' => 'a@b.com', 'phone' => '']);
    assert_same([], $r['errors']);
    assert_same('', $r['values']['name']);
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
// Nome curto da entidade (kanpro_entity_short_name — puro)
// ---------------------------------------------------------------------------
kanpro_test('entity_short_name prefere name', function () {
    assert_same('EE "Coripheu"', kanpro_entity_short_name(['name' => 'EE "Coripheu"', 'completename' => 'URE Jales > EE "Coripheu"']));
});

kanpro_test('entity_short_name cai no último nível do completename', function () {
    assert_same('EE "Coripheu"', kanpro_entity_short_name(['name' => '', 'completename' => 'URE Jales > EE "Coripheu"']));
    assert_same('Sozinha', kanpro_entity_short_name(['completename' => 'Sozinha']));
    assert_same('', kanpro_entity_short_name([]));
});

// ---------------------------------------------------------------------------
// Agrupamento da lista de máquinas (stackMachineLines — puro)
// ---------------------------------------------------------------------------
kanpro_test('stackMachineLines agrupa iguais', function () {
    $items = [];
    for ($i = 1; $i <= 70; $i++) {
        $items[] = ['seq' => $i, 'model' => 'Tablet Positivo', 'status' => 'OK'];
    }
    assert_same(['70x Tablets Positivo (OK)'], PluginKanproMaintenanceZap::stackMachineLines($items));
});

kanpro_test('stackMachineLines separa por status e unitário mantém #seq', function () {
    $lines = PluginKanproMaintenanceZap::stackMachineLines([
        ['seq' => 1, 'model' => 'Tablet Positivo', 'status' => 'OK'],
        ['seq' => 2, 'model' => 'Tablet Positivo', 'status' => 'OK'],
        ['seq' => 3, 'model' => 'Tablet Positivo', 'status' => 'Garantia'],
        ['seq' => 4, 'model' => 'Notebook Lenovo', 'status' => 'OK'],
    ]);
    assert_same([
        '2x Tablets Positivo (OK)',
        '#3 — Tablet Positivo (Garantia)',
        '#4 — Notebook Lenovo (OK)',
    ], $lines);
});

kanpro_test('stackMachineLines vazio devolve vazio', function () {
    assert_same([], PluginKanproMaintenanceZap::stackMachineLines([]));
});

// ---------------------------------------------------------------------------
// ---------------------------------------------------------------------------
// Trava por vínculo do card de manutenção (kanpro_can_edit_linked_card)
// Só card de manutenção com ≥1 membro vinculado trava; gerente/criador do
// quadro ignoram a trava; admin comum NÃO ganha bypass.
// ---------------------------------------------------------------------------
function kanpro_linked_reset(): void {
    global $DB;
    $DB->createTable('glpi_plugin_kanpro_cards_members');
    $DB->createTable('glpi_plugin_kanpro_boards_members');
    $DB->delete('glpi_plugin_kanpro_cards_members', []);
    $DB->delete('glpi_plugin_kanpro_boards_members', []);
    PluginKanproCard::$rows = [];
    PluginKanproBoard::$rows = [];
    Session::$userId = 0;
}

kanpro_test('can_edit_linked_card existe', function () {
    assert_true(function_exists('kanpro_can_edit_linked_card'));
});

kanpro_test('can_edit_linked_card: id inválido devolve false', function () {
    kanpro_linked_reset();
    assert_true(kanpro_can_edit_linked_card(0) === false);
});

kanpro_test('can_edit_linked_card: card não-manutenção devolve true', function () {
    kanpro_linked_reset();
    PluginKanproCard::$rows[101] = ['id' => 101, 'plugin_kanpro_boards_id' => 7, 'is_maintenance' => 0, 'users_id' => 55];
    assert_true(kanpro_can_edit_linked_card(101) === true);
});

kanpro_test('can_edit_linked_card: manutenção sem membros devolve true (sem trava)', function () {
    kanpro_linked_reset();
    PluginKanproCard::$rows[102] = ['id' => 102, 'plugin_kanpro_boards_id' => 7, 'is_maintenance' => 1, 'users_id' => 55];
    assert_true(kanpro_can_edit_linked_card(102) === true);
});

kanpro_test('can_edit_linked_card: manutenção com membro e não-vinculado devolve false', function () {
    global $DB;
    kanpro_linked_reset();
    Session::$userId = 99;
    PluginKanproCard::$rows[103] = ['id' => 103, 'plugin_kanpro_boards_id' => 7, 'is_maintenance' => 1, 'users_id' => 55];
    $DB->insert('glpi_plugin_kanpro_cards_members', ['plugin_kanpro_cards_id' => 103, 'users_id' => 42]);
    assert_true(kanpro_can_edit_linked_card(103) === false);
});

kanpro_test('can_edit_linked_card: membro vinculado devolve true', function () {
    global $DB;
    kanpro_linked_reset();
    Session::$userId = 42;
    PluginKanproCard::$rows[103] = ['id' => 103, 'plugin_kanpro_boards_id' => 7, 'is_maintenance' => 1, 'users_id' => 55];
    $DB->insert('glpi_plugin_kanpro_cards_members', ['plugin_kanpro_cards_id' => 103, 'users_id' => 42]);
    assert_true(kanpro_can_edit_linked_card(103) === true);
});

kanpro_test('can_edit_linked_card: criador do card devolve true', function () {
    global $DB;
    kanpro_linked_reset();
    Session::$userId = 55;
    PluginKanproCard::$rows[104] = ['id' => 104, 'plugin_kanpro_boards_id' => 7, 'is_maintenance' => 1, 'users_id' => 55];
    $DB->insert('glpi_plugin_kanpro_cards_members', ['plugin_kanpro_cards_id' => 104, 'users_id' => 42]);
    assert_true(kanpro_can_edit_linked_card(104) === true);
});

kanpro_test('can_edit_linked_card: gerente do quadro devolve true', function () {
    global $DB;
    kanpro_linked_reset();
    Session::$userId = 99;
    PluginKanproCard::$rows[103] = ['id' => 103, 'plugin_kanpro_boards_id' => 7, 'is_maintenance' => 1, 'users_id' => 55];
    $DB->insert('glpi_plugin_kanpro_cards_members', ['plugin_kanpro_cards_id' => 103, 'users_id' => 42]);
    $DB->insert('glpi_plugin_kanpro_boards_members', ['plugin_kanpro_boards_id' => 7, 'users_id' => 99, 'role' => 'gerente']);
    assert_true(kanpro_can_edit_linked_card(103) === true);
});

kanpro_test('can_edit_linked_card: criador do quadro devolve true', function () {
    global $DB;
    kanpro_linked_reset();
    Session::$userId = 99;
    PluginKanproBoard::$rows[7] = ['id' => 7, 'users_id' => 99];
    PluginKanproCard::$rows[103] = ['id' => 103, 'plugin_kanpro_boards_id' => 7, 'is_maintenance' => 1, 'users_id' => 55];
    $DB->insert('glpi_plugin_kanpro_cards_members', ['plugin_kanpro_cards_id' => 103, 'users_id' => 42]);
    assert_true(kanpro_can_edit_linked_card(103) === true);
});

kanpro_test('can_edit_linked_card: admin não-vinculado devolve false (sem bypass)', function () {
    global $DB;
    kanpro_linked_reset();
    Session::$userId = 99;
    PluginKanproCard::$rows[103] = ['id' => 103, 'plugin_kanpro_boards_id' => 7, 'is_maintenance' => 1, 'users_id' => 55];
    $DB->insert('glpi_plugin_kanpro_cards_members', ['plugin_kanpro_cards_id' => 103, 'users_id' => 42]);
    $DB->insert('glpi_plugin_kanpro_boards_members', ['plugin_kanpro_boards_id' => 7, 'users_id' => 99, 'role' => 'admin']);
    assert_true(kanpro_can_edit_linked_card(103) === false);
});

// ---------------------------------------------------------------------------
// Split 1/2+2/2 (kanpro_is_split_pending / kanpro_split_sibling)
// ---------------------------------------------------------------------------
function kanpro_split_reset(): void {
    global $DB;
    $DB->createTable('glpi_plugin_kanpro_cards');
    $DB->delete('glpi_plugin_kanpro_cards', []);
    PluginKanproCard::$rows = [];
}

kanpro_test('split helpers existem', function () {
    assert_true(function_exists('kanpro_is_split_pending'));
    assert_true(function_exists('kanpro_split_sibling'));
});

kanpro_test('is_split_pending: id inválido devolve false', function () {
    kanpro_split_reset();
    assert_true(kanpro_is_split_pending(0) === false);
});

kanpro_test('is_split_pending: sem flag devolve false', function () {
    kanpro_split_reset();
    PluginKanproCard::$rows[301] = ['id' => 301, 'plugin_kanpro_boards_id' => 7, 'is_maintenance' => 1];
    assert_true(kanpro_is_split_pending(301) === false);
});

kanpro_test('is_split_pending: com flag devolve true', function () {
    kanpro_split_reset();
    PluginKanproCard::$rows[301] = ['id' => 301, 'plugin_kanpro_boards_id' => 7, 'is_maintenance' => 1, 'split_pending' => 1, 'split_group' => 301, 'split_index' => 1];
    PluginKanproCard::$rows[302] = ['id' => 302, 'plugin_kanpro_boards_id' => 7, 'is_maintenance' => 1, 'split_pending' => 1, 'split_group' => 301, 'split_index' => 2];
    assert_true(kanpro_is_split_pending(301) === true);
    assert_true(kanpro_is_split_pending(302) === true);
});

kanpro_test('split_sibling: pai acha filho e filho acha pai', function () {
    global $DB;
    kanpro_split_reset();
    PluginKanproCard::$rows[301] = ['id' => 301, 'split_group' => 301, 'split_index' => 1];
    PluginKanproCard::$rows[302] = ['id' => 302, 'split_group' => 301, 'split_index' => 2];
    $DB->insert('glpi_plugin_kanpro_cards', ['id' => 301, 'split_group' => 301, 'split_index' => 1, 'is_archived' => 0]);
    $DB->insert('glpi_plugin_kanpro_cards', ['id' => 302, 'split_group' => 301, 'split_index' => 2, 'is_archived' => 0]);
    assert_same(302, kanpro_split_sibling(301));
    assert_same(301, kanpro_split_sibling(302));
});

kanpro_test('split_sibling: fora de split ou irmão arquivado devolve 0', function () {
    global $DB;
    kanpro_split_reset();
    PluginKanproCard::$rows[303] = ['id' => 303, 'split_group' => 0, 'split_index' => 0];
    $DB->insert('glpi_plugin_kanpro_cards', ['id' => 303, 'split_group' => 0, 'split_index' => 0, 'is_archived' => 0]);
    assert_same(0, kanpro_split_sibling(303));
    assert_same(0, kanpro_split_sibling(0));
    PluginKanproCard::$rows[301] = ['id' => 301, 'split_group' => 301, 'split_index' => 1];
    PluginKanproCard::$rows[302] = ['id' => 302, 'split_group' => 301, 'split_index' => 2];
    $DB->insert('glpi_plugin_kanpro_cards', ['id' => 301, 'split_group' => 301, 'split_index' => 1, 'is_archived' => 0]);
    $DB->insert('glpi_plugin_kanpro_cards', ['id' => 302, 'split_group' => 301, 'split_index' => 2, 'is_archived' => 1]);
    assert_same(0, kanpro_split_sibling(301));
});

// ---------------------------------------------------------------------------
// Regras de notificação por quadro (PluginKanproNotifyRule)
// ---------------------------------------------------------------------------
function kanpro_notify_reset(): void {
    global $DB;
    $DB->createTable('glpi_plugin_kanpro_notify_rules');
    $DB->createTable('glpi_users');
    $DB->createTable('glpi_useremails');
    $DB->delete('glpi_plugin_kanpro_notify_rules', []);
    $DB->delete('glpi_users', []);
    $DB->delete('glpi_useremails', []);
    $DB->insert('glpi_users', ['name' => 'ze.regra', 'realname' => 'Regra', 'firstname' => 'Zé', 'phone' => '', 'mobile' => '11988887777', 'email' => 'ze.regra@exemplo.com', 'is_deleted' => 0, 'is_active' => 1]);
    $uid2 = (int)$DB->insert('glpi_users', ['name' => 'sem.email.direto', 'realname' => 'Direto', 'firstname' => 'Sem', 'phone' => '1133334444', 'mobile' => '', 'is_deleted' => 0, 'is_active' => 1]);
    $DB->insert('glpi_useremails', ['users_id' => $uid2, 'email' => 'indireto@exemplo.com']);
}

kanpro_test('notifyrule resolveUser com login inexistente devolve null', function () {
    kanpro_notify_reset();
    assert_same(null, PluginKanproNotifyRule::resolveUser('nao.existe'));
});

kanpro_test('notifyrule addRule com categoria inválida falha', function () {
    kanpro_notify_reset();
    $r = PluginKanproNotifyRule::addRule(3, 'banana', 'ze.regra', true, false);
    assert_true(empty($r['ok']), 'categoria banana deveria falhar');
});

kanpro_test('notifyrule addRule sem canal falha', function () {
    kanpro_notify_reset();
    $r = PluginKanproNotifyRule::addRule(3, 'pending', 'ze.regra', false, false);
    assert_true(empty($r['ok']), 'sem canal deveria falhar');
});

kanpro_test('notifyrule addRule duplicado reativa o mesmo id', function () {
    global $DB;
    kanpro_notify_reset();
    $a = PluginKanproNotifyRule::addRule(3, 'pending', 'ze.regra', true, true);
    assert_true(!empty($a['ok']), 'primeira criação deveria passar');
    PluginKanproNotifyRule::setActive((int)$a['id'], 3, false);
    $b = PluginKanproNotifyRule::addRule(3, 'pending', 'ze.regra', true, false);
    assert_true(!empty($b['ok']), 'recriação deveria passar');
    assert_same((int)$a['id'], (int)$b['id'], 'deveria reaproveitar o id');
    assert_same(1, countElementsInTable('glpi_plugin_kanpro_notify_rules', ['plugin_kanpro_boards_id' => 3]), 'deveria existir 1 linha');
    $rules = PluginKanproNotifyRule::getRulesForBoard(3);
    assert_same(1, count($rules));
    assert_same(1, (int)$rules[0]['is_active'], 'deveria reativar');
});

kanpro_test('notifyrule addRule login vazio falha', function () {
    kanpro_notify_reset();
    $r = PluginKanproNotifyRule::addRule(3, 'pending', '   ', true, false);
    assert_true(empty($r['ok']), 'login vazio deveria falhar');
});

kanpro_test('notifyrule addRule sem telefone avisa mas salva', function () {
    global $DB;
    kanpro_notify_reset();
    $DB->insert('glpi_users', ['name' => 'sem.fone', 'realname' => 'Fone', 'firstname' => 'Sem', 'phone' => '', 'mobile' => '', 'email' => 'sem.fone@exemplo.com', 'is_deleted' => 0, 'is_active' => 1]);
    $r = PluginKanproNotifyRule::addRule(3, 'pending', 'sem.fone', true, false);
    assert_true(!empty($r['ok']), 'deveria salvar com aviso');
    assert_true(in_array('sem telefone', $r['warnings'] ?? [], true), 'deveria avisar sem telefone');
});

kanpro_test('notify_rule_parse login vazio falha', function () {
    $r = kanpro_notify_rule_parse(['boards_id' => 3, 'category' => 'pending', 'login' => '  ', 'whats' => '1']);
    assert_true(empty($r['ok']), 'login vazio deveria falhar');
});

kanpro_test('notify_rule_parse normaliza categoria e canais', function () {
    $r = kanpro_notify_rule_parse(['boards_id' => '3', 'category' => 'PENDING', 'login' => ' ze.regra ', 'whats' => '1']);
    assert_true(!empty($r['ok']), 'parse válido deveria passar');
    assert_same(3, $r['bid']);
    assert_same('pending', $r['category']);
    assert_same('ze.regra', $r['login']);
    assert_same(true, $r['whats']);
    assert_same(false, $r['mail']);
});

kanpro_test('list_type_label cobre pending e rejeita desconhecido', function () {
    assert_same('Pendente', kanpro_list_type_label('pending'));
    assert_same('Chamados Espera', kanpro_list_type_label('chamados_espera'));
    assert_same('', kanpro_list_type_label('banana'));
});

exit(kanpro_summary());
