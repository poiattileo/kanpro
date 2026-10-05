<?php
// Configurações globais do KanPro (aprovador, destinatários, mapa de login).
// Tabela glpi_plugin_kanpro_configs (name/value). Só quem edita o plugin.
include('../../../inc/includes.php');
include_once(GLPI_ROOT . '/plugins/kanpro/inc/acting.php');
Session::checkRight('plugin_kanpro', UPDATE);

global $DB, $CFG_GLPI;

if (isset($_POST['update'])) {
    $parsed = kanpro_config_parse_post($_POST);
    if (!empty($parsed['errors'])) {
        foreach ($parsed['errors'] as $e) {
            Session::addMessageAfterRedirect($e, false, ERROR);
        }
    } elseif (function_exists('kanpro_config_set')) {
        foreach ($parsed['values'] as $k => $v) {
            kanpro_config_set($k, (string)$v);
        }
        Session::addMessageAfterRedirect('Configurações KanPro salvas.');
    } else {
        Session::addMessageAfterRedirect('Camada de config indisponível (inc/acting.php).', false, ERROR);
    }
    Html::back();
}

Html::header('KanPro - Configurações', $_SERVER['PHP_SELF'], 'tools', 'PluginKanproBoard');

$approver = function_exists('kanpro_config_get') ? kanpro_config_get('zap_approver', '') : '';

/** @return string linhas p/ textarea a partir do JSON do config */
function kanpro_cfg_lines(string $key): string {
    if (!function_exists('kanpro_config_get')) {
        return '';
    }
    $decoded = json_decode(kanpro_config_get($key, ''), true);
    if (!is_array($decoded)) {
        return '';
    }
    return implode("\n", array_map('strval', $decoded));
}

$reminderLines = kanpro_cfg_lines('zap_reminder_recipients');
$chamadoLines  = kanpro_cfg_lines('zap_chamado_recipients');
$mapRaw        = function_exists('kanpro_config_get') ? kanpro_config_get('acting_map', '') : '';
if ($mapRaw !== '') {
    $tmp = json_decode($mapRaw, true);
    if (is_array($tmp)) {
        $mapRaw = json_encode($tmp, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}

$form_action = $CFG_GLPI['root_doc'] . '/plugins/kanpro/front/config.form.php';
echo "<form method='post' action='{$form_action}'>";
echo "<div class='spaced'><table class='tab_cadre_fixehov'>";
echo "<tr class='headerRow'><th colspan='2'>⚙️ Configurações — KanPro (notificações e identidade)</th></tr>";

echo "<tr class='tab_bg_1'><td width='35%'><strong>Aprovador WhatsApp</strong><br><small>Login ou e-mail de quem recebe os alertas de quadro/cartão/pendência. Vazio = envios desligados (fail closed).</small></td><td>";
echo "<input type='text' name='zap_approver' value='" . htmlspecialchars($approver, ENT_QUOTES) . "' style='width:100%' maxlength='255' placeholder='login ou e-mail'>";
echo "</td></tr>";

echo "<tr class='tab_bg_1'><td><strong>Destinatários do lembrete</strong><br><small>Um por linha. Recebem o CARDS AGUARDANDO (8h/10h/13h em dias úteis). Linhas com # são ignoradas.</small></td><td>";
echo "<textarea name='zap_reminder_recipients' rows='4' style='width:100%' placeholder='um por linha'>" . htmlspecialchars($reminderLines, ENT_QUOTES) . "</textarea>";
echo "</td></tr>";

echo "<tr class='tab_bg_1'><td><strong>Destinatários do chamado</strong><br><small>Um por linha. Recebem o aviso de novo chamado em Abrir Chamado.</small></td><td>";
echo "<textarea name='zap_chamado_recipients' rows='4' style='width:100%' placeholder='um por linha'>" . htmlspecialchars($chamadoLines, ENT_QUOTES) . "</textarea>";
echo "</td></tr>";

echo "<tr class='tab_bg_1'><td><strong>Mapa de login compartilhado</strong><br><small>JSON {\"login_usado\":\"pessoa_real\"}. Usado quando a equipe divide um login. Vazio = sem migração.</small></td><td>";
echo "<textarea name='acting_map' rows='4' style='width:100%;font-family:monospace' placeholder='{\"glpi\":\"pessoa@exemplo\"}'>" . htmlspecialchars($mapRaw, ENT_QUOTES) . "</textarea>";
echo "</td></tr>";

echo "<tr class='tab_bg_2'><td colspan='2' class='center' style='padding:12px'>";
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
echo "<button type='submit' name='update' value='1' class='btn btn-primary'><i class='ti ti-device-floppy'></i> Salvar configurações</button>";
echo "</td></tr>";
echo "</table></div>";
Html::closeForm();

Html::footer();
