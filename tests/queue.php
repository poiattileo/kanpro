<?php
/**
 * tests/queue.php — fila de envios WhatsApp (enqueue + consumer com retry).
 *
 * Uso: php tests/queue.php
 * FakeKanproZap sobrescreve o HTTP (static::) com respostas programadas —
 * nenhum teste encosta na rede.
 */
require_once __DIR__ . '/boot.php';

class FakeKanproZap extends PluginKanproMaintenanceZap {
    /** @var array<int,array{resp:string,code:int,err:string}> */
    public static array $httpScript = [];

    /** @var array<int,array<string,mixed>> */
    public static array $httpLog = [];

    /** @return array<string,string>|null */
    public static function evoConfig(): ?array {
        return ['server_url' => 'http://evo.test', 'api_token' => 'tok', 'instance_name' => 'inst'];
    }

    /** @param array<int,string> $headers */
    public static function evoHttpPost(string $endpoint, array $headers, string $body, int $timeout): array {
        self::$httpLog[] = ['endpoint' => $endpoint, 'headers' => $headers, 'body' => $body, 'timeout' => $timeout];
        $next = array_shift(self::$httpScript);
        if (is_array($next)) {
            return $next;
        }
        return ['resp' => '', 'code' => 500, 'err' => 'sem resposta programada'];
    }

    /** @param array<int,array{resp:string,code:int,err:string}> $script */
    public static function scriptHttp(array $script): void {
        self::$httpScript = $script;
        self::$httpLog = [];
    }
}

/** @return array{resp:string,code:int,err:string} */
function http_ok(): array {
    return ['resp' => '{"ok":true}', 'code' => 200, 'err' => ''];
}

/** @return array{resp:string,code:int,err:string} */
function http_fail(): array {
    return ['resp' => 'boom', 'code' => 500, 'err' => ''];
}

function queue_reset(): void {
    global $DB;
    foreach (['glpi_plugin_kanpro_zapqueue', 'glpi_plugin_kanpro_maintenance_zaplog'] as $t) {
        if ($DB->tableExists($t)) {
            $DB->delete($t, []);
        }
    }
    PluginKanproBoard::resetActivityLog();
    FakeKanproZap::scriptHttp([]);
}

/** @return array<int,array<string,mixed>> */
function queue_rows(): array {
    global $DB;
    $out = [];
    foreach ($DB->request(['FROM' => 'glpi_plugin_kanpro_zapqueue', 'ORDER' => 'id ASC']) as $r) {
        $out[] = $r;
    }
    return $out;
}

/** @return array<int,array<string,mixed>> */
function zaplog_rows(): array {
    global $DB;
    $out = [];
    if (!$DB->tableExists('glpi_plugin_kanpro_maintenance_zaplog')) {
        return $out;
    }
    foreach ($DB->request(['FROM' => 'glpi_plugin_kanpro_maintenance_zaplog', 'ORDER' => 'id ASC']) as $r) {
        $out[] = $r;
    }
    return $out;
}

// ---------------------------------------------------------------------------
// enqueue (a existência da API foi o teste RED inicial — métodos verificados
// pelo próprio uso abaixo)
// ---------------------------------------------------------------------------
kanpro_test('queue: enqueue sem tabela falha (fail closed)', function () {
    $r = FakeKanproZap::enqueue('lembrete', 0, 'm1', '5511999999999', 'texto');
    assert_true(empty($r['ok']), 'sem tabela deveria falhar');
});

kanpro_test('queue: processQueue sem tabela processa zero', function () {
    $r = FakeKanproZap::processQueue(10);
    assert_same(0, (int)($r['processed'] ?? -1));
});

global $DB;
$DB->createTable('glpi_plugin_kanpro_zapqueue');
$DB->createTable('glpi_plugin_kanpro_maintenance_zaplog');

// ---------------------------------------------------------------------------
// enqueue
// ---------------------------------------------------------------------------
kanpro_test('queue: enqueue insere pendente', function () {
    queue_reset();
    $r = FakeKanproZap::enqueue('lembrete', 0, 'lembrete_2026-01-01_08', '5511999999999', 'texto do lembrete');
    assert_true(!empty($r['ok']), 'enqueue deveria aceitar: ' . json_encode($r));
    assert_true(!empty($r['queued']));
    $rows = queue_rows();
    assert_same(1, count($rows));
    assert_same('pending', (string)($rows[0]['status'] ?? ''));
    assert_same('lembrete', (string)($rows[0]['kind'] ?? ''));
    assert_same('texto do lembrete', (string)($rows[0]['message'] ?? ''));
});

kanpro_test('queue: enqueue rejeita kind/phone/texto inválido', function () {
    queue_reset();
    assert_true(empty(FakeKanproZap::enqueue('nao_existe', 1, 'm', '5511999999999', 't')['ok']));
    assert_true(empty(FakeKanproZap::enqueue('lembrete', 1, 'm', '', 't')['ok']));
    assert_true(empty(FakeKanproZap::enqueue('lembrete', 1, 'm', '5511999999999', '')['ok']));
    assert_same(0, count(queue_rows()));
});

kanpro_test('queue: enqueue barra duplicado já enviado', function () {
    global $DB;
    queue_reset();
    $DB->insert('glpi_plugin_kanpro_maintenance_zaplog', [
        'plugin_kanpro_cards_id' => 5, 'milestone' => 'entrada', 'phone' => '5511999999999',
        'success' => 1, 'detail' => '', 'date_creation' => date('Y-m-d H:i:s'),
    ]);
    $r = FakeKanproZap::enqueue('entrada', 5, 'entrada', '5511999999999', 'texto');
    assert_true(empty($r['ok']), 'já enviado deveria barrar');
    assert_same('duplicate', (string)($r['error'] ?? ''));
    assert_same(0, count(queue_rows()));
});

kanpro_test('queue: enqueue barra duplicado já na fila', function () {
    queue_reset();
    assert_true(!empty(FakeKanproZap::enqueue('pendencia', 7, 'pendencia', '5511999999999', 't1')['ok']));
    $r = FakeKanproZap::enqueue('pendencia', 7, 'pendencia', '5511999999999', 't2');
    assert_true(empty($r['ok']), 'segundo enqueue deveria barrar');
    assert_same('duplicate', (string)($r['error'] ?? ''));
    assert_same(1, count(queue_rows()));
    assert_true(FakeKanproZap::hasQueued(7, 'pendencia'));
    assert_true(!FakeKanproZap::hasQueued(7, 'outro'));
});

kanpro_test('queue: enqueue com replace troca pendente', function () {
    queue_reset();
    assert_true(!empty(FakeKanproZap::enqueue('lembrete', 0, 'm9', '5511999999999', 'velho')['ok']));
    $r = FakeKanproZap::enqueue('lembrete', 0, 'm9', '5511999999999', 'novo', true);
    assert_true(!empty($r['ok']), 'replace deveria aceitar');
    $rows = queue_rows();
    assert_same(1, count($rows));
    assert_same('novo', (string)($rows[0]['message'] ?? ''));
});

// ---------------------------------------------------------------------------
// consumer: sucesso
// ---------------------------------------------------------------------------
kanpro_test('queue: consumer envia, marca done + zaplog + atividade', function () {
    queue_reset();
    FakeKanproZap::scriptHttp([http_ok()]);
    FakeKanproZap::enqueue('pendencia', 42, 'pendencia', '5511999999999', 'texto pendencia');
    $r = FakeKanproZap::processQueue(10);
    assert_same(1, (int)($r['processed'] ?? -1));
    assert_same(1, (int)($r['sent'] ?? -1));
    assert_same(1, count(FakeKanproZap::$httpLog), 'deveria ter 1 chamada HTTP');
    $rows = queue_rows();
    assert_same('done', (string)($rows[0]['status'] ?? ''));
    $log = zaplog_rows();
    assert_same(1, count($log), 'deveria ter 1 linha de zaplog');
    assert_same(1, (int)($log[0]['success'] ?? 0));
    assert_true(count(PluginKanproBoard::$activityLog) > 0, 'deveria ter logado atividade');
});

kanpro_test('queue: consumer ignora com next_try_at futuro', function () {
    global $DB;
    queue_reset();
    $DB->insert('glpi_plugin_kanpro_zapqueue', [
        'kind' => 'lembrete', 'plugin_kanpro_cards_id' => 0, 'milestone' => 'fut',
        'phone' => '5511999999999', 'message' => 't', 'attempts' => 0, 'max_attempts' => 5,
        'status' => 'pending', 'next_try_at' => date('Y-m-d H:i:s', time() + 3600),
        'last_error' => null, 'date_creation' => date('Y-m-d H:i:s'),
    ]);
    FakeKanproZap::scriptHttp([http_ok()]);
    $r = FakeKanproZap::processQueue(10);
    assert_same(0, (int)($r['processed'] ?? -1));
    assert_same(0, count(FakeKanproZap::$httpLog), 'não deveria chamar HTTP');
});

kanpro_test('queue: consumer pula duplicate tardio sem HTTP', function () {
    global $DB;
    queue_reset();
    FakeKanproZap::enqueue('entrada', 9, 'entrada', '5511999999999', 't');
    // enviado por outro caminho depois do enqueue
    $DB->insert('glpi_plugin_kanpro_maintenance_zaplog', [
        'plugin_kanpro_cards_id' => 9, 'milestone' => 'entrada', 'phone' => '5511999999999',
        'success' => 1, 'detail' => '', 'date_creation' => date('Y-m-d H:i:s'),
    ]);
    FakeKanproZap::scriptHttp([http_ok()]);
    $r = FakeKanproZap::processQueue(10);
    assert_same(1, (int)($r['processed'] ?? -1));
    assert_same(0, (int)($r['sent'] ?? -1));
    assert_same(0, count(FakeKanproZap::$httpLog), 'não deveria chamar HTTP');
    assert_same('done', (string)(queue_rows()[0]['status'] ?? ''));
});

// ---------------------------------------------------------------------------
// consumer: retry, backoff, falha final, reclaim
// ---------------------------------------------------------------------------
kanpro_test('queue: falha agenda retry com backoff (sem spam de zaplog)', function () {
    queue_reset();
    FakeKanproZap::scriptHttp([http_fail()]);
    FakeKanproZap::enqueue('retirada', 11, 'retirada', '5511999999999', 't');
    $r = FakeKanproZap::processQueue(10);
    assert_same(1, (int)($r['processed'] ?? -1));
    assert_same(1, (int)($r['failed'] ?? -1));
    $rows = queue_rows();
    assert_same('pending', (string)($rows[0]['status'] ?? ''), 'deveria voltar p/ pending');
    assert_same(1, (int)($rows[0]['attempts'] ?? -1));
    assert_true(strtotime((string)($rows[0]['next_try_at'] ?? '')) > time(), 'next_try_at deveria ser futuro');
    assert_same(0, count(zaplog_rows()), 'retry transitório não grava zaplog');
});

kanpro_test('queue: backoff cresce e 5a falha encerra como failed', function () {
    queue_reset();
    FakeKanproZap::scriptHttp([http_fail()]);
    FakeKanproZap::enqueue('retirada', 12, 'retirada', '5511999999999', 't');
    $first = null;
    for ($i = 1; $i <= 5; $i++) {
        // volta o relógio: força o job a estar devido de novo
        global $DB;
        $rows = queue_rows();
        $DB->update('glpi_plugin_kanpro_zapqueue', ['next_try_at' => date('Y-m-d H:i:s', time() - 1)], ['id' => (int)$rows[0]['id']]);
        FakeKanproZap::scriptHttp([http_fail()]);
        FakeKanproZap::processQueue(10);
        $rows = queue_rows();
        if ($i === 1) {
            $first = (string)($rows[0]['next_try_at'] ?? '');
        }
        if ($i === 2) {
            assert_true(strtotime((string)($rows[0]['next_try_at'] ?? '')) > strtotime((string)$first), 'backoff deveria crescer');
        }
    }
    $rows = queue_rows();
    assert_same('failed', (string)($rows[0]['status'] ?? ''));
    assert_same(5, (int)($rows[0]['attempts'] ?? -1));
    $log = zaplog_rows();
    assert_same(1, count($log), 'falha final grava 1 zaplog');
    assert_same(0, (int)($log[0]['success'] ?? -1));
    assert_true(count(PluginKanproBoard::$activityLog) > 0, 'falha final loga atividade');
});

kanpro_test('queue: consumer recupera sending travado', function () {
    global $DB;
    queue_reset();
    $DB->insert('glpi_plugin_kanpro_zapqueue', [
        'kind' => 'cancelado', 'plugin_kanpro_cards_id' => 13, 'milestone' => 'cancelado',
        'phone' => '5511999999999', 'message' => 't', 'attempts' => 0, 'max_attempts' => 5,
        'status' => 'sending', 'next_try_at' => date('Y-m-d H:i:s', time() - 7200),
        'last_error' => null, 'date_creation' => date('Y-m-d H:i:s', time() - 7200),
        'date_mod' => date('Y-m-d H:i:s', time() - 7200),
    ]);
    FakeKanproZap::scriptHttp([http_ok()]);
    $r = FakeKanproZap::processQueue(10);
    assert_same(1, (int)($r['processed'] ?? -1));
    assert_same(1, (int)($r['sent'] ?? -1));
    assert_same('done', (string)(queue_rows()[0]['status'] ?? ''));
});

// ---------------------------------------------------------------------------
exit(kanpro_summary());
