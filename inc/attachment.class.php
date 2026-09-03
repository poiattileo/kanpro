<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginKanproAttachment extends CommonDBTM {
    static $rightname = 'plugin_kanpro';
    static function getTypeName($nb = 0) { return _n('Anexo', 'Anexos', $nb); }

    static function handleUpload($cards_id, array $file): ?int {
        if ($file['error'] !== UPLOAD_ERR_OK) return null;
        $allowed = ['image/png','image/jpeg','image/gif','image/webp','image/svg+xml','application/pdf','text/plain','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/zip','text/csv'];
        // não bloqueia estritamente, só limita tamanho
        if ($file['size'] > 20*1024*1024) {
            Session::addMessageAfterRedirect('Arquivo muito grande (máx 20MB)', false, ERROR);
            return null;
        }
        $dir = GLPI_PLUGIN_DOC_DIR . '/kanpro/cards/' . intval($cards_id) . '/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $filename = uniqid('att_', true) . '.' . $ext;
        $dest = $dir . $filename;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            // fallback copy
            if (!copy($file['tmp_name'], $dest)) return null;
        }
        $rel = 'cards/' . intval($cards_id) . '/' . $filename;
        $att = new self();
        $id = $att->add([
            'plugin_kanpro_cards_id' => $cards_id,
            'name'       => $file['name'],
            'filename'   => $filename,
            'filepath'   => $rel,
            'filesize'   => $file['size'],
            'mime'       => $file['type'],
            'users_id'   => Session::getLoginUserID(),
            'date_creation' => date('Y-m-d H:i:s'),
        ]);
        if ($id) {
            $card = new PluginKanproCard();
            if ($card->getFromDB($cards_id)) {
                PluginKanproBoard::logActivity($card->fields['plugin_kanpro_boards_id'], $cards_id, $card->fields['plugin_kanpro_lists_id'], 'attachment_add', $file['name']);
            }
        }
        return $id ?: null;
    }

    static function getForCard($cards_id): array {
        global $DB;
        $iter = $DB->request(['FROM' => 'glpi_plugin_kanpro_attachments', 'WHERE' => ['plugin_kanpro_cards_id' => $cards_id], 'ORDER' => 'date_creation DESC']);
        $out = [];
        foreach ($iter as $r) $out[] = $r;
        return $out;
    }
}
