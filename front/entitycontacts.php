<?php
// Contatos por entidade (e-mails/telefones p/ futuras notificações).
// Cadastro simples: adicionar, ativar/desativar, excluir. Só quem edita o plugin.
include('../../../inc/includes.php');
include_once(GLPI_ROOT . '/plugins/kanpro/inc/acting.php');
Session::checkRight('plugin_kanpro', UPDATE);

global $DB, $CFG_GLPI;

$table = 'glpi_plugin_kanpro_entities_contacts';

if (isset($_POST['save'])) {
    $parsed = function_exists('kanpro_entitycontact_validate')
        ? kanpro_entitycontact_validate($_POST)
        : ['values' => [], 'errors' => ['Validador indisponível (inc/acting.php).']];
    // volta p/ o form mantendo a entidade (o resto reseta p/ próximo cadastro)
    $backEntity = (int)(($parsed['values']['entities_id'] ?? 0) ?: ($_POST['entities_id'] ?? 0));
    $backUrl = $CFG_GLPI['root_doc'] . '/plugins/kanpro/front/entitycontacts.php' . ($backEntity > 0 ? '?entities_id=' . $backEntity : '');
    if (!empty($parsed['errors'])) {
        foreach ($parsed['errors'] as $e) {
            Session::addMessageAfterRedirect($e, false, ERROR);
        }
    } else {
        $v = $parsed['values'];
        $now = date('Y-m-d H:i:s');
        $editId = (int)($_POST['id'] ?? 0);
        try {
            if ($editId > 0) {
                $DB->update($table, [
                    'entities_id' => $v['entities_id'],
                    'kind'        => $v['kind'],
                    'name'        => $v['name'],
                    'email'       => $v['email'],
                    'phone'       => $v['phone'],
                    'is_active'   => $v['is_active'],
                    'date_mod'    => $now,
                ], ['id' => $editId]);
                Session::addMessageAfterRedirect('Contato atualizado.');
            } else {
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
            }
        } catch (Throwable $e) {
            Session::addMessageAfterRedirect('Falha ao salvar: ' . $e->getMessage(), false, ERROR);
        }
    }
    Html::redirect($backUrl);
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
        foreach ($DB->request(['SELECT' => ['id', 'name', 'completename'], 'FROM' => 'glpi_entities', 'WHERE' => $where, 'ORDER' => 'name ASC']) as $e) {
            $short = function_exists('kanpro_entity_short_name') ? kanpro_entity_short_name($e) : trim((string)($e['name'] ?? ''));
            $entities[(int)$e['id']] = $short !== '' ? $short : ('Entidade #' . (int)$e['id']);
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
// entidade mantida após cadastrar (volta na URL) — resto do form reseta
$selEntity = (int)($_GET['entities_id'] ?? 0);

// modo edição: ?edit_id=N preenche o form
$editRow = null;
$editId = (int)($_GET['edit_id'] ?? 0);
if ($editId > 0) {
    try {
        $found = $DB->request(['FROM' => $table, 'WHERE' => ['id' => $editId], 'LIMIT' => 1])->current();
        if (is_array($found)) {
            $editRow = $found;
        }
    } catch (Throwable $e) {}
}
$fv = ['id' => 0, 'entities_id' => $selEntity, 'name' => '', 'email' => '', 'phone' => '', 'is_active' => 1];
if (is_array($editRow)) {
    $fv = [
        'id'          => (int)$editRow['id'],
        'entities_id' => (int)$editRow['entities_id'],
        'name'        => (string)($editRow['name'] ?? ''),
        'email'       => (string)($editRow['email'] ?? ''),
        'phone'       => (string)($editRow['phone'] ?? ''),
        'is_active'   => !empty($editRow['is_active']) ? 1 : 0,
    ];
    $selEntity = $fv['entities_id'];
}
// dobra p/ busca (minúsculas sem acento — o JS normaliza igual do outro lado)
$foldSearch = function ($s) {
    $s = strtolower((string)$s);
    return strtr($s, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);
};

echo "<div class='spaced' style='max-width:1100px;margin:0 auto'>";

// ---- adicionar / editar ----
echo "<form method='post' action='{$form_action}'>";
echo "<table class='tab_cadre_fixehov'>";
if ($fv['id'] > 0) {
    echo "<tr class='headerRow'><th colspan='2'>✏️ Editando contato #{$fv['id']} <a href='{$form_action}' style='color:#fff;font-weight:400;font-size:12px;margin-left:12px'>(cancelar)</a></th></tr>";
} else {
    echo "<tr class='headerRow'><th colspan='2'>📇 Novo contato por entidade</th></tr>";
}
echo Html::hidden('id', ['value' => $fv['id']]);
echo "<tr class='tab_bg_1'><td width='30%'><strong>Entidade</strong></td><td><select name='entities_id' style='width:100%'>";
echo "<option value='0'>— selecione —</option>";
foreach ($entities as $eid => $ename) {
    $sel = ($eid === $selEntity && $eid > 0) ? ' selected' : '';
    echo "<option value='{$eid}'{$sel}>" . htmlspecialchars($ename) . "</option>";
}
echo "</select></td></tr>";
echo "<tr class='tab_bg_1'><td><strong>Nome</strong><br><small>Opcional — vazio usa o próprio contato</small></td><td><input type='text' name='name' maxlength='255' style='width:100%' placeholder='Ex: Diretoria, Responsável TI' value='" . htmlspecialchars($fv['name'], ENT_QUOTES) . "'></td></tr>";
echo "<tr class='tab_bg_1'><td><strong>E-mail</strong><br><small>Só um por vez: ou e-mail, ou telefone</small></td><td><input type='text' name='email' maxlength='255' style='width:100%' placeholder='contato@exemplo' value='" . htmlspecialchars($fv['email'], ENT_QUOTES) . "'></td></tr>";
echo "<tr class='tab_bg_1'><td><strong>Telefone</strong><br><small>Só dígitos (ex: 11999998888)</small></td><td><input type='text' id='kp-contact-phone' name='phone' maxlength='30' style='width:100%' placeholder='DDD + número' value='" . htmlspecialchars($fv['phone'], ENT_QUOTES) . "'></td></tr>";
echo "<tr class='tab_bg_1'><td><strong>Ativo</strong></td><td><input type='hidden' name='is_active' value='0'><input type='checkbox' name='is_active' value='1'" . ($fv['is_active'] ? ' checked' : '') . "> recebe futuras notificações</td></tr>";
echo "<tr class='tab_bg_2'><td colspan='2' class='center' style='padding:12px'>";
echo Html::hidden('_glpi_csrf_token', ['value' => $csrf]);
if ($fv['id'] > 0) {
    echo "<button type='submit' name='save' value='1' class='btn btn-primary'><i class='ti ti-device-floppy'></i> Salvar alterações</button> ";
    echo "<a href='{$form_action}' class='btn btn-outline-secondary'>Cancelar</a>";
} else {
    echo "<button type='submit' name='save' value='1' class='btn btn-primary'><i class='ti ti-plus'></i> Cadastrar</button>";
}
echo "</td></tr>";
echo "</table>";
Html::closeForm();
echo "<script>document.getElementById('kp-contact-phone')?.addEventListener('input', function(){ var d = this.value.replace(/[^0-9]/g, '').slice(0, 15); if (this.value !== d) this.value = d; });</script>";

// ---- busca + lista ----
echo "<div style='margin:16px 0 8px;display:flex;gap:8px;align-items:center'>";
echo "<input type='text' id='kp-contact-search' placeholder='🔍 Buscar por entidade, nome, e-mail ou telefone...' style='flex:1;padding:8px 12px;border:1px solid #dfe1e6;border-radius:6px'>";
echo "<span id='kp-contacts-count' style='font-size:12px;color:#6b778c;white-space:nowrap'>" . count($rows) . " de " . count($rows) . "</span>";
echo "</div>";
echo "<table class='tab_cadre_fixehov' id='kp-contacts-table'>";
echo "<tr class='headerRow'><th colspan='7'>Contatos cadastrados</th></tr>";
echo "<tr class='tab_bg_1 center'><th>Entidade</th><th>Tipo</th><th>Nome</th><th>E-mail</th><th>Telefone</th><th>Ativo</th><th>Ações</th></tr>";
if (empty($rows)) {
    echo "<tr class='tab_bg_1 center'><td colspan='7' style='color:#6b778c'>Nenhum contato cadastrado.</td></tr>";
}
foreach ($rows as $r) {
    $rid = (int)($r['id'] ?? 0);
    $reid = (int)($r['entities_id'] ?? 0);
    $active = !empty($r['is_active']);
    $rname = trim((string)($r['name'] ?? ''));
    if ($rname === '') {
        $rname = trim((string)($r['email'] ?? '')) !== '' ? (string)$r['email'] : (string)($r['phone'] ?? '');
    }
    $kindBadge = (($r['kind'] ?? 'email') === 'phone') ? '📱 WhatsApp' : '✉️ E-mail';
    $searchHay = htmlspecialchars($foldSearch(($entities[$reid] ?? '') . ' ' . $kindBadge . ' ' . $rname . ' ' . ($r['email'] ?? '') . ' ' . ($r['phone'] ?? '')), ENT_QUOTES);
    echo "<tr class='tab_bg_1 center' data-search='{$searchHay}'>";
    echo "<td style='text-align:left'>" . htmlspecialchars($entities[$reid] ?? ('Entidade #' . $reid)) . "</td>";
    echo "<td style='white-space:nowrap'>" . $kindBadge . "</td>";
    echo "<td style='text-align:left'>" . htmlspecialchars($rname) . "</td>";
    echo "<td style='text-align:left'>" . htmlspecialchars((string)($r['email'] ?? '')) . "</td>";
    echo "<td>" . htmlspecialchars((string)($r['phone'] ?? '')) . "</td>";
    echo "<td>" . ($active ? '✅' : '⛔') . "</td>";
    echo "<td style='white-space:nowrap'>";
    echo "<a href='{$form_action}?edit_id={$rid}' class='btn btn-sm btn-outline-secondary' title='Editar'>Editar</a> ";
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
echo "<script>(function(){var box=document.getElementById('kp-contact-search');if(!box)return;var count=document.getElementById('kp-contacts-count');function norm(s){return (s||'').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'');}function apply(){var q=norm(box.value.trim());var shown=0,total=0;document.querySelectorAll('#kp-contacts-table tr[data-search]').forEach(function(tr){total++;var hit=(q===''||tr.getAttribute('data-search').indexOf(q)!==-1);tr.style.display=hit?'':'none';if(hit)shown++;});if(count)count.textContent=shown+' de '+total;}box.addEventListener('input',apply);apply();})();</script>";
echo "</div>";

Html::footer();
