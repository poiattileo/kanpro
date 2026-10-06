<?php
// Contatos por entidade (e-mails/telefones p/ futuras notificações).
// Cadastro simples: adicionar, ativar/desativar, excluir. Só quem edita o plugin.
include('../../../inc/includes.php');
include_once(GLPI_ROOT . '/plugins/kanpro/inc/acting.php');
Session::checkRight('plugin_kanpro', UPDATE);

global $DB, $CFG_GLPI;

$table = 'glpi_plugin_kanpro_entities_contacts';

if (isset($_POST['add'])) {
    $parsed = function_exists('kanpro_entitycontact_validate')
        ? kanpro_entitycontact_validate($_POST)
        : ['values' => [], 'errors' => ['Validador indisponível (inc/acting.php).']];
    if (!empty($parsed['errors'])) {
        foreach ($parsed['errors'] as $e) {
            Session::addMessageAfterRedirect($e, false, ERROR);
        }
    } else {
        $v = $parsed['values'];
        $now = date('Y-m-d H:i:s');
        try {
            $id = $DB->insert($table, [
                'entities_id'   => $v['entities_id'],
                'kind'          => $v['kind'],
                'name'          => $v['name'],
                'email'         => $v['email'],
                'phone'         => $v['phone'],
                'is_active'     => $v['is_active'],
                'users_id'      => (int)Session::getLoginUserID(),
                'date_creation' => $now,
            ]);
            Session::addMessageAfterRedirect($id !== false ? 'Contato cadastrado.' : 'Falha ao cadastrar.', $id !== false, $id !== false ? INFO : ERROR);
        } catch (Throwable $e) {
            Session::addMessageAfterRedirect('Falha ao cadastrar: ' . $e->getMessage(), false, ERROR);
        }
    }
    Html::back();
}

if (isset($_POST['delete'])) {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        try {
            $DB->delete($table, ['id' => $id]);
            Session::addMessageAfterRedirect('Contato excluído.');
        } catch (Throwable $e) {
            Session::addMessageAfterRedirect('Falha ao excluir: ' . $e->getMessage(), false, ERROR);
        }
    }
    Html::back();
}

if (isset($_POST['toggle'])) {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        try {
            $row = $DB->request(['SELECT' => ['is_active'], 'FROM' => $table, 'WHERE' => ['id' => $id], 'LIMIT' => 1])->current();
            if (is_array($row)) {
                $DB->update($table, ['is_active' => empty($row['is_active']) ? 1 : 0, 'date_mod' => date('Y-m-d H:i:s')], ['id' => $id]);
                Session::addMessageAfterRedirect('Contato ' . (empty($row['is_active']) ? 'ativado.' : 'desativado.'));
            }
        } catch (Throwable $e) {
            Session::addMessageAfterRedirect('Falha ao alternar: ' . $e->getMessage(), false, ERROR);
        }
    }
    Html::back();
}

Html::header('KanPro - Contatos por entidade', $_SERVER['PHP_SELF'], 'tools', 'PluginKanproBoard');

// Entidades p/ o select e p/ exibir nome na lista.
// Sem filtro is_deleted fixo: nem todo GLPI tem a coluna em glpi_entities.
$entities = [];
try {
    if ($DB->tableExists('glpi_entities')) {
        $hasDeleted = false;
        try {
            $hasDeleted = $DB->fieldExists('glpi_entities', 'is_deleted');
        } catch (Throwable $e) {}
        $where = $hasDeleted ? ['is_deleted' => 0] : [];
        foreach ($DB->request(['SELECT' => ['id', 'completename'], 'FROM' => 'glpi_entities', 'WHERE' => $where, 'ORDER' => 'completename ASC']) as $e) {
            $entities[(int)$e['id']] = trim((string)($e['completename'] ?? '')) !== '' ? (string)$e['completename'] : ('Entidade #' . (int)$e['id']);
        }
    }
} catch (Throwable $e) {}

$rows = [];
try {
    if ($DB->tableExists($table)) {
        foreach ($DB->request(['FROM' => $table, 'ORDER' => 'entities_id ASC, name ASC']) as $r) {
            $rows[] = $r;
        }
    }
} catch (Throwable $e) {}

$csrf = Session::getNewCSRFToken();
$form_action = $CFG_GLPI['root_doc'] . '/plugins/kanpro/front/entitycontacts.php';

echo "<div class='spaced' style='max-width:1100px;margin:0 auto'>";

// ---- adicionar ----
echo "<form method='post' action='{$form_action}'>";
echo "<table class='tab_cadre_fixehov'>";
echo "<tr class='headerRow'><th colspan='2'>📇 Novo contato por entidade</th></tr>";
echo "<tr class='tab_bg_1'><td width='30%'><strong>Entidade</strong></td><td><select name='entities_id' style='width:100%'>";
echo "<option value='0'>— selecione —</option>";
foreach ($entities as $eid => $ename) {
    echo "<option value='{$eid}'>" . htmlspecialchars($ename) . "</option>";
}
echo "</select></td></tr>";
echo "<tr class='tab_bg_1'><td><strong>E-mail</strong><br><small>Só um por vez: ou e-mail, ou telefone</small></td><td><input type='text' name='email' maxlength='255' style='width:100%' placeholder='contato@exemplo'></td></tr>";
echo "<tr class='tab_bg_1'><td><strong>Telefone</strong><br><small>Só dígitos (ex: 11999998888)</small></td><td><input type='text' name='phone' maxlength='30' style='width:100%' placeholder='DDD + número'></td></tr>";
echo "<tr class='tab_bg_1'><td><strong>Ativo</strong></td><td><input type='hidden' name='is_active' value='0'><input type='checkbox' name='is_active' value='1' checked> recebe futuras notificações</td></tr>";
echo "<tr class='tab_bg_2'><td colspan='2' class='center' style='padding:12px'>";
echo Html::hidden('_glpi_csrf_token', ['value' => $csrf]);
echo "<button type='submit' name='add' value='1' class='btn btn-primary'><i class='ti ti-plus'></i> Cadastrar</button>";
echo "</td></tr>";
echo "</table>";
Html::closeForm();

// ---- lista ----
echo "<table class='tab_cadre_fixehov' style='margin-top:16px'>";
echo "<tr class='headerRow'><th colspan='7'>Contatos cadastrados (" . count($rows) . ")</th></tr>";
echo "<tr class='tab_bg_1 center'><th>Entidade</th><th>Tipo</th><th>Nome</th><th>E-mail</th><th>Telefone</th><th>Ativo</th><th>Ações</th></tr>";
if (empty($rows)) {
    echo "<tr class='tab_bg_1 center'><td colspan='7' style='color:#6b778c'>Nenhum contato cadastrado.</td></tr>";
}
foreach ($rows as $r) {
    $rid = (int)($r['id'] ?? 0);
    $reid = (int)($r['entities_id'] ?? 0);
    $active = !empty($r['is_active']);
    echo "<tr class='tab_bg_1 center'>";
    echo "<td style='text-align:left'>" . htmlspecialchars($entities[$reid] ?? ('Entidade #' . $reid)) . "</td>";
    $kindBadge = (($r['kind'] ?? 'email') === 'phone') ? '📱 WhatsApp' : '✉️ E-mail';
    echo "<td style='white-space:nowrap'>" . $kindBadge . "</td>";
    echo "<td style='text-align:left'>" . htmlspecialchars((string)($r['name'] ?? '')) . "</td>";
    echo "<td style='text-align:left'>" . htmlspecialchars((string)($r['email'] ?? '')) . "</td>";
    echo "<td>" . htmlspecialchars((string)($r['phone'] ?? '')) . "</td>";
    echo "<td>" . ($active ? '✅' : '⛔') . "</td>";
    echo "<td style='white-space:nowrap'>";
    echo "<form method='post' action='{$form_action}' style='display:inline'>";
    echo Html::hidden('_glpi_csrf_token', ['value' => $csrf]);
    echo Html::hidden('id', ['value' => $rid]);
    echo "<button type='submit' name='toggle' value='1' class='btn btn-sm btn-outline-secondary' title='" . ($active ? 'Desativar' : 'Ativar') . "'>" . ($active ? 'Desativar' : 'Ativar') . "</button> ";
    echo "</form>";
    echo "<form method='post' action='{$form_action}' style='display:inline' onsubmit=\"return confirm('Excluir este contato?')\">";
    echo Html::hidden('_glpi_csrf_token', ['value' => $csrf]);
    echo Html::hidden('id', ['value' => $rid]);
    echo "<button type='submit' name='delete' value='1' class='btn btn-sm btn-outline-secondary' style='color:#eb5a46' title='Excluir'>Excluir</button>";
    echo "</form>";
    echo "</td></tr>";
}
echo "</table>";
echo "</div>";

Html::footer();
