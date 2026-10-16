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
    $idA = (int)($a['id'] ?? 0);
    assert_true($idA > 0, 'deveria voltar id');
    PluginKanproNotifyRule::setActive($idA, 3, false);
    $b = PluginKanproNotifyRule::addRule(3, 'pending', 'ze.regra', true, false);
    assert_true(!empty($b['ok']), 'recriação deveria passar');
    $idB = (int)($b['id'] ?? 0);
    assert_same($idA, $idB, 'deveria reaproveitar o id');
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

function kanpro_rule_send_reset(): void {
    global $DB;
    kanpro_notify_reset();
    $DB->createTable('glpi_plugin_kanpro_zapqueue');
    $DB->createTable('glpi_plugin_kanpro_maintenance_zaplog');
    $DB->delete('glpi_plugin_kanpro_zapqueue', []);
    $DB->delete('glpi_plugin_kanpro_maintenance_zaplog', []);
}

kanpro_test('sendRuleEntry segunda chamada é duplicate', function () {
    kanpro_rule_send_reset();
    $add = PluginKanproNotifyRule::addRule(3, 'pending', 'ze.regra', true, false);
    assert_true(!empty($add['ok']), 'regra deveria criar');
    $rules = PluginKanproNotifyRule::getRulesForBoard(3);
    assert_same(1, count($rules));
    $r1 = PluginKanproMaintenanceZap::sendRuleEntry($rules[0], 55, 9);
    assert_true(!empty($r1['ok']), 'primeiro envio deveria enfileirar: ' . ($r1['error'] ?? ''));
    $r2 = PluginKanproMaintenanceZap::sendRuleEntry($rules[0], 55, 9);
    assert_true(empty($r2['ok']), 'segunda chamada não deveria enviar');
    assert_same('duplicate', $r2['error'] ?? '', 'deveria ser duplicate');
});

kanpro_test('sendRuleEntry sem telefone pula sem excepcionar', function () {
    global $DB;
    kanpro_rule_send_reset();
    $DB->insert('glpi_users', ['name' => 'so.fone.falta', 'realname' => 'Falta', 'firstname' => 'So', 'phone' => '', 'mobile' => '', 'email' => 'so.falta@exemplo.com', 'is_deleted' => 0, 'is_active' => 1]);
    $add = PluginKanproNotifyRule::addRule(3, 'pending', 'so.fone.falta', true, false);
    assert_true(!empty($add['ok']), 'regra deveria criar');
    $rules = PluginKanproNotifyRule::getRulesForBoard(3);
    $r = PluginKanproMaintenanceZap::sendRuleEntry($rules[0], 56, 9);
    assert_true(empty($r['ok']), 'sem telefone não deveria enviar');
    assert_true(str_contains((string)($r['error'] ?? ''), 'sem telefone'), 'erro deveria citar sem telefone');
});

kanpro_test('mail render notify_entry contém card e lista', function () {
    $html = PluginKanproMaintenanceMail::render('notify_entry', [
        'card_id' => '55', 'card_nome' => 'Escola Teste XYZ', 'escola' => 'Escola Teste XYZ',
        'quadro' => 'Quadro Q', 'lista' => 'Pendente', 'descricao' => 'd', 'ticket' => '-',
        'autor' => 'a', 'quantidade' => '0', 'maquinas' => '<ul></ul>', 'data' => 'x',
    ]);
    assert_true(is_string($html) && str_contains($html, 'Escola Teste XYZ') && str_contains($html, 'Pendente'), 'template deveria trazer card e lista');
});

kanpro_test('mail render tipo desconhecido devolve null', function () {
    assert_same(null, PluginKanproMaintenanceMail::render('banana', []));
});

kanpro_test('mail subject notify_entry cita card novo', function () {
    $s = PluginKanproMaintenanceMail::subject('notify_entry', ['card_id' => '55', 'card_nome' => 'Escola X', 'lista' => 'Pendente']);
    assert_true(str_contains($s, 'Card novo') && str_contains($s, '#55'), 'assunto deveria citar card novo: ' . $s);
});

kanpro_test('queue_email e-mail inválido falha sem excepcionar', function () {
    $r = kanpro_queue_email('nao-email', 'assunto', '<p>oi</p>');
    assert_true(empty($r['ok']), 'e-mail inválido deveria falhar');
});

kanpro_test('queue_email sem fila do GLPI falha limpo', function () {
    $r = kanpro_queue_email('a@exemplo.com', 'assunto', '<p>oi</p>');
    assert_true(empty($r['ok']), 'sem QueuedNotification deveria falhar');
    assert_true(str_contains((string)($r['error'] ?? ''), 'fila'), 'erro deveria citar a fila');
});

function kanpro_digest_reset(): void {
    global $DB;
    kanpro_notify_reset();
    foreach (['glpi_plugin_kanpro_lists', 'glpi_plugin_kanpro_cards', 'glpi_plugin_kanpro_maintenance_zaplog', 'glpi_plugin_kanpro_zapqueue'] as $t) {
        $DB->createTable($t);
        $DB->delete($t, []);
    }
}

kanpro_test('digest sem cards falha silencioso', function () {
    global $DB;
    kanpro_digest_reset();
    $DB->insert('glpi_plugin_kanpro_lists', ['plugin_kanpro_boards_id' => 3, 'name' => 'Pendente', 'list_type' => '', 'is_archived' => 0]);
    $add = PluginKanproNotifyRule::addRule(3, 'pending', 'ze.regra', true, false);
    assert_true(!empty($add['ok']), 'regra deveria criar');
    $rules = PluginKanproNotifyRule::getRulesForBoard(3);
    $r = PluginKanproMaintenanceZap::sendRuleDigest($rules[0], 8, true);
    assert_true(empty($r['ok']), 'sem cards não deveria enviar');
    assert_same('nada pendente', $r['error'] ?? '');
});

kanpro_test('digest segunda chamada no turno é duplicate', function () {
    global $DB;
    kanpro_digest_reset();
    $lid = (int)$DB->insert('glpi_plugin_kanpro_lists', ['plugin_kanpro_boards_id' => 3, 'name' => 'Pendente', 'list_type' => 'pending', 'is_archived' => 0]);
    $DB->insert('glpi_plugin_kanpro_cards', ['plugin_kanpro_boards_id' => 3, 'plugin_kanpro_lists_id' => $lid, 'name' => 'C1', 'is_archived' => 0]);
    $DB->insert('glpi_plugin_kanpro_cards', ['plugin_kanpro_boards_id' => 3, 'plugin_kanpro_lists_id' => $lid, 'name' => 'C2', 'is_archived' => 0]);
    $add = PluginKanproNotifyRule::addRule(3, 'pending', 'ze.regra', true, false);
    assert_true(!empty($add['ok']), 'regra deveria criar');
    $rules = PluginKanproNotifyRule::getRulesForBoard(3);
    $r1 = PluginKanproMaintenanceZap::sendRuleDigest($rules[0], 8, true);
    assert_true(!empty($r1['ok']), 'primeiro resumo deveria enfileirar: ' . ($r1['error'] ?? ''));
    $r2 = PluginKanproMaintenanceZap::sendRuleDigest($rules[0], 8, true);
    assert_true(empty($r2['ok']), 'segunda chamada não deveria enviar');
    assert_same('duplicate', $r2['error'] ?? '');
});

kanpro_test('notify_global_summary tem estrutura', function () {
    $s = kanpro_notify_global_summary();
    foreach (['chamado_recipients', 'approver', 'lembrete_recipients', 'lembrete_slots'] as $k) {
        assert_true(array_key_exists($k, $s), 'faltando ' . $k);
    }
    assert_same([8, 10, 13], $s['lembrete_slots']);
});

function kanpro_mig_reset(): void {
    global $DB;
    foreach (['glpi_plugin_kanpro_configs', 'glpi_plugin_kanpro_boards', 'glpi_plugin_kanpro_lists', 'glpi_plugin_kanpro_notify_rules', 'glpi_users', 'glpi_useremails'] as $t) {
        $DB->createTable($t);
        $DB->delete($t, []);
    }
    $DB->insert('glpi_users', ['name' => 'ana.migra', 'realname' => 'Migra', 'firstname' => 'Ana', 'phone' => '', 'mobile' => '11977776666', 'email' => 'ana.migra@exemplo.com', 'is_deleted' => 0, 'is_active' => 1]);
}

kanpro_test('migracao move quem ganha regra e mantém o resto', function () {
    global $DB;
    kanpro_mig_reset();
    $json = json_encode(['ana.migra', 'fantasma']);
    if (!is_string($json)) {
        throw new RuntimeException('json_encode falhou');
    }
    kanpro_config_set('zap_chamado_recipients', $json);
    $bA = (int)$DB->insert('glpi_plugin_kanpro_boards', ['name' => 'Quadro A', 'is_archived' => 0]);
    $bB = (int)$DB->insert('glpi_plugin_kanpro_boards', ['name' => 'Quadro B', 'is_archived' => 0]);
    $DB->insert('glpi_plugin_kanpro_lists', ['plugin_kanpro_boards_id' => $bA, 'name' => 'Pendente', 'list_type' => 'pending', 'is_archived' => 0]);
    $DB->insert('glpi_plugin_kanpro_lists', ['plugin_kanpro_boards_id' => $bA, 'name' => 'Chamados Espera', 'list_type' => '', 'is_archived' => 0]);
    $DB->insert('glpi_plugin_kanpro_lists', ['plugin_kanpro_boards_id' => $bB, 'name' => 'Backlog', 'list_type' => '', 'is_archived' => 0]);
    $r = kanpro_migrate_chamado_recipients_to_rules();
    assert_same(['ana.migra'], $r['moved'] ?? [], 'ana deveria migrar');
    assert_true(array_key_exists('fantasma', $r['kept'] ?? []), 'fantasma deveria ficar');
    assert_same(2, $r['rules'] ?? -1, 'ana deveria ganhar 2 regras no quadro A');
    assert_same(['fantasma'], json_decode(kanpro_config_get('zap_chamado_recipients', ''), true), 'global deveria ficar só com fantasma');
    assert_same(2, countElementsInTable('glpi_plugin_kanpro_notify_rules', []), 'deveria existir 2 regras');
});

kanpro_test('migracao é idempotente', function () {
    global $DB;
    kanpro_mig_reset();
    $json = json_encode(['ana.migra']);
    if (!is_string($json)) {
        throw new RuntimeException('json_encode falhou');
    }
    kanpro_config_set('zap_chamado_recipients', $json);
    $bA = (int)$DB->insert('glpi_plugin_kanpro_boards', ['name' => 'Quadro A', 'is_archived' => 0]);
    $DB->insert('glpi_plugin_kanpro_lists', ['plugin_kanpro_boards_id' => $bA, 'name' => 'Pendente', 'list_type' => 'pending', 'is_archived' => 0]);
    kanpro_migrate_chamado_recipients_to_rules();
    $r2 = kanpro_migrate_chamado_recipients_to_rules();
    assert_same([], json_decode(kanpro_config_get('zap_chamado_recipients', ''), true), 'global deveria esvaziar');
    assert_same(1, countElementsInTable('glpi_plugin_kanpro_notify_rules', []), 'segunda rodada não deveria duplicar');
    assert_same([], $r2['moved'] ?? null, 'segunda rodada não deveria mover ninguém');
});

function kanpro_greset(): void {
    global $DB;
    $DB->createTable('glpi_plugin_kanpro_configs');
    $DB->delete('glpi_plugin_kanpro_configs', []);
}

kanpro_test('global add anexa sem duplicar', function () {
    kanpro_greset();
    $a = kanpro_notify_global_add('zap_chamado_recipients', 'ze.regra');
    assert_true(!empty($a['ok']), 'add deveria passar');
    $b = kanpro_notify_global_add('zap_chamado_recipients', 'ze.regra');
    assert_true(!empty($b['ok']), 're-add deveria passar');
    assert_same(['ze.regra'], json_decode(kanpro_config_get('zap_chamado_recipients', ''), true), 'não deveria duplicar');
});

kanpro_test('global add rejeita inválido e chave estranha', function () {
    kanpro_greset();
    assert_true(empty(kanpro_notify_global_add('zap_chamado_recipients', 'nome com espaco')['ok']), 'com espaço deveria falhar');
    assert_true(empty(kanpro_notify_global_add('zap_chamado_recipients', '')['ok']), 'vazio deveria falhar');
    assert_true(empty(kanpro_notify_global_add('chave_qualquer', 'ze.regra')['ok']), 'chave estranha deveria falhar');
});

kanpro_test('global del remove e ausente falha', function () {
    kanpro_greset();
    kanpro_notify_global_add('zap_reminder_recipients', 'ze.regra');
    $d = kanpro_notify_global_del('zap_reminder_recipients', 'ze.regra');
    assert_true(!empty($d['ok']), 'del deveria passar');
    assert_same([], json_decode(kanpro_config_get('zap_reminder_recipients', ''), true), 'deveria esvaziar');
    assert_true(empty(kanpro_notify_global_del('zap_reminder_recipients', 'ze.regra')['ok']), 'del repetido deveria falhar');
});

kanpro_test('global approver salva e limpa', function () {
    kanpro_greset();
    $s = kanpro_notify_global_approver('chefia@exemplo.com');
    assert_true(!empty($s['ok']), 'save deveria passar');
    assert_same('chefia@exemplo.com', kanpro_config_get('zap_approver', ''));
    assert_true(empty(kanpro_notify_global_approver('nome com espaco')['ok']), 'com espaço deveria falhar');
    $c = kanpro_notify_global_approver('');
    assert_true(!empty($c['ok']), 'limpar deveria passar');
    assert_same('', kanpro_config_get('zap_approver', ''));
});

function kanpro_sug_reset(): void {
    global $DB;
    $DB->createTable('glpi_users');
    $DB->delete('glpi_users', []);
    $DB->insert('glpi_users', ['name' => 'ana.silva', 'realname' => 'Silva', 'firstname' => 'Ana', 'is_deleted' => 0, 'is_active' => 1]);
    $DB->insert('glpi_users', ['name' => 'bruno.souza', 'realname' => 'Souza', 'firstname' => 'Bruno', 'is_deleted' => 0, 'is_active' => 1]);
    $DB->insert('glpi_users', ['name' => 'ex.cluido', 'realname' => 'X', 'firstname' => 'Ex', 'is_deleted' => 1, 'is_active' => 1]);
}

kanpro_test('suggest filtra por nome e login', function () {
    kanpro_sug_reset();
    $a = kanpro_notify_user_suggest('ana');
    assert_same(1, count($a), 'ana deveria achar 1');
    assert_same('ana.silva', $a[0]['login'] ?? null);
    $b = kanpro_notify_user_suggest('souza');
    assert_same(1, count($b), 'souza deveria achar 1');
    assert_same('bruno.souza', $b[0]['login'] ?? null);
    assert_same([], kanpro_notify_user_suggest('zzz'), 'zzz não deveria achar nada');
});

kanpro_test('suggest sem termo lista ativos e respeita limite', function () {
    kanpro_sug_reset();
    assert_same(2, count(kanpro_notify_user_suggest('')), 'vazio deveria listar os 2 ativos (sem deletado)');
    assert_same(1, count(kanpro_notify_user_suggest('', 1)), 'limite 1 deveria cortar');
});

kanpro_test('updaterule edita categoria e canais', function () {
    kanpro_notify_reset();
    $a = PluginKanproNotifyRule::addRule(3, 'pending', 'ze.regra', true, false);
    assert_true(!empty($a['ok']), 'criação deveria passar');
    $idA = (int)($a['id'] ?? 0);
    $u = PluginKanproNotifyRule::updateRule($idA, 3, 'andamento', 'ze.regra', false, true);
    assert_true(!empty($u['ok']), 'update deveria passar: ' . ($u['error'] ?? ''));
    $rules = PluginKanproNotifyRule::getRulesForBoard(3);
    assert_same(1, count($rules));
    assert_same('andamento', $rules[0]['list_category'] ?? null);
    assert_same(0, (int)($rules[0]['notify_whats'] ?? 1));
    assert_same(1, (int)($rules[0]['notify_mail'] ?? 0));
});

kanpro_test('updaterule para combinação duplicada falha', function () {
    global $DB;
    kanpro_notify_reset();
    $a = PluginKanproNotifyRule::addRule(3, 'pending', 'ze.regra', true, true);
    assert_true(!empty($a['ok']), 'criação deveria passar');
    $DB->insert('glpi_users', ['name' => 'outro.user', 'realname' => 'User', 'firstname' => 'Outro', 'phone' => '1133334444', 'mobile' => '', 'email' => 'outro@exemplo.com', 'is_deleted' => 0, 'is_active' => 1]);
    $b = PluginKanproNotifyRule::addRule(3, 'andamento', 'outro.user', true, true);
    assert_true(!empty($b['ok']), 'criação deveria passar');
    $u = PluginKanproNotifyRule::updateRule((int)($b['id'] ?? 0), 3, 'pending', 'ze.regra', true, true);
    assert_true(empty($u['ok']), 'duplicado deveria falhar');
});

kanpro_test('updaterule inválido falha', function () {
    kanpro_notify_reset();
    assert_true(empty(PluginKanproNotifyRule::updateRule(0, 3, 'pending', 'ze.regra', true, false)['ok']), 'id zero deveria falhar');
    assert_true(empty(PluginKanproNotifyRule::updateRule(999, 3, 'banana', 'ze.regra', true, false)['ok']), 'categoria ruim deveria falhar');
    $a = PluginKanproNotifyRule::addRule(3, 'pending', 'ze.regra', true, false);
    assert_true(!empty($a['ok']), 'criação deveria passar');
    assert_true(empty(PluginKanproNotifyRule::updateRule((int)($a['id'] ?? 0), 3, 'pending', 'ninguem', true, false)['ok']), 'usuário inexistente deveria falhar');
    assert_true(empty(PluginKanproNotifyRule::updateRule((int)($a['id'] ?? 0), 3, 'pending', 'ze.regra', false, false)['ok']), 'sem canal deveria falhar');
});

function kanpro_lib_reset(): void {
    global $DB;
    foreach (['glpi_plugin_kanpro_cards', 'glpi_plugin_kanpro_cards_members', 'glpi_users', 'glpi_plugin_kanpro_maintenance_machines', 'glpi_plugin_kanpro_maintenance_zaplog', 'glpi_plugin_kanpro_zapqueue'] as $t) {
        $DB->createTable($t);
        $DB->delete($t, []);
    }
    PluginKanproCard::$rows = [];
    User::$rows = [];
    User::$rows[7] = ['name' => 'tec.nico', 'firstname' => 'Tec', 'realname' => 'Nico', 'phone' => '11999998888', 'mobile' => '', 'is_deleted' => 0, 'is_active' => 1];
    $DB->insert('glpi_plugin_kanpro_cards_members', ['plugin_kanpro_cards_id' => 325, 'users_id' => 7]);
}

function kanpro_lib_queued_text(): string {
    global $DB;
    foreach ($DB->request(['FROM' => 'glpi_plugin_kanpro_zapqueue']) as $r) {
        return (string)($r['message'] ?? '');
    }
    return '';
}

kanpro_test('liberado mostra ticket resolvido pelo clone', function () {
    global $DB;
    kanpro_lib_reset();
    PluginKanproCard::$rows[400] = ['id' => 400, 'plugin_kanpro_boards_id' => 3, 'plugin_kanpro_lists_id' => 9, 'name' => '', 'chamado_source_id' => 325, 'chamado_status' => 'liberado', 'chamado_by' => 7, 'chamado_machines' => '[]', 'date_creation' => '2026-10-16 14:00:00'];
    PluginKanproCard::$rows[325] = ['id' => 325, 'plugin_kanpro_boards_id' => 3, 'plugin_kanpro_lists_id' => 9, 'name' => 'Escola X', 'tickets_id' => 0, 'users_id' => 7];
    $DB->insert('glpi_plugin_kanpro_cards', ['id' => 325, 'plugin_kanpro_boards_id' => 3, 'plugin_kanpro_lists_id' => 9, 'name' => 'Escola X', 'tickets_id' => 0, 'is_archived' => 0]);
    $DB->insert('glpi_plugin_kanpro_cards', ['id' => 410, 'plugin_kanpro_boards_id' => 3, 'plugin_kanpro_lists_id' => 10, 'name' => 'Escola X', 'tickets_id' => 777, 'chamado_source_id' => 325, 'is_archived' => 0]);
    $r = PluginKanproMaintenanceZap::sendLiberado(400);
    assert_true(!empty($r['ok']), 'deveria enfileirar: ' . ($r['error'] ?? ''));
    $txt = kanpro_lib_queued_text();
    assert_true(str_contains($txt, '#777'), 'mensagem deveria trazer o ticket do clone: ' . mb_substr($txt, 0, 120));
    assert_true(str_contains($txt, 'Um chamado foi criado'), 'mensagem deveria usar o texto novo');
    $row = $DB->request(['SELECT' => ['tickets_id'], 'FROM' => 'glpi_plugin_kanpro_cards', 'WHERE' => ['id' => 325]])->current();
    assert_same(777, (int)($row['tickets_id'] ?? 0), 'origem deveria ser carimbada (autocura)');
});

kanpro_test('liberado sem ticket mostra traço', function () {
    global $DB;
    kanpro_lib_reset();
    PluginKanproCard::$rows[400] = ['id' => 400, 'plugin_kanpro_boards_id' => 3, 'plugin_kanpro_lists_id' => 9, 'name' => '', 'chamado_source_id' => 325, 'chamado_status' => 'liberado', 'chamado_by' => 7, 'chamado_machines' => '[]', 'date_creation' => '2026-10-16 14:00:00'];
    PluginKanproCard::$rows[325] = ['id' => 325, 'plugin_kanpro_boards_id' => 3, 'plugin_kanpro_lists_id' => 9, 'name' => 'Escola X', 'tickets_id' => 0, 'users_id' => 7];
    $DB->insert('glpi_plugin_kanpro_cards', ['id' => 325, 'plugin_kanpro_boards_id' => 3, 'plugin_kanpro_lists_id' => 9, 'name' => 'Escola X', 'tickets_id' => 0, 'is_archived' => 0]);
    $r = PluginKanproMaintenanceZap::sendLiberado(400);
    assert_true(!empty($r['ok']), 'deveria enfileirar mesmo sem ticket: ' . ($r['error'] ?? ''));
    assert_true(str_contains(kanpro_lib_queued_text(), '#—'), 'sem ticket deveria mostrar traço');
});

exit(kanpro_summary());
