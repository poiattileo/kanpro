<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginKanproAttachment extends CommonDBTM {
    static $rightname = 'plugin_kanpro';
    static function getTypeName($nb = 0) { return _n('Anexo', 'Anexos', $nb); }

    static function handleUpload($cards_id, array $file): ?int {
        $cards_id = (int)$cards_id;
        if ($cards_id <= 0) return null;
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
        if (!is_uploaded_file($file['tmp_name'] ?? '')) return null;
        $allowedExts = ['png','jpg','jpeg','gif','webp','svg','pdf','txt','doc','docx','xls','xlsx','zip','csv'];
        $allowedMimes = ['image/png','image/jpeg','image/gif','image/webp','image/svg+xml','application/pdf','text/plain','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/zip','text/csv','text/plain'];
        // bloqueia executáveis / dupla extensão (ex: foto.jpg.php)
        $blockedExts = ['php','phtml','phar','php3','php4','php5','php7','phps','exe','sh','bat','cmd','com','msi','js','html','htm','htaccess'];
        if (($file['size'] ?? 0) > 20*1024*1024) {
            Session::addMessageAfterRedirect('Arquivo muito grande (máx 20MB)', false, ERROR);
            return null;
        }
        $origName = (string)($file['name'] ?? 'arquivo');
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $allowedExts, true) || in_array($ext, $blockedExts, true)) {
            Session::addMessageAfterRedirect('Tipo de arquivo não permitido', false, ERROR);
            return null;
        }
        // checa dupla extensão: qualquer parte intermediária bloqueada barra
        $parts = explode('.', strtolower($origName));
        foreach ($parts as $p) {
            if (in_array($p, $blockedExts, true)) {
                Session::addMessageAfterRedirect('Tipo de arquivo não permitido', false, ERROR);
                return null;
            }
        }
        // MIME real via finfo (não confia em $file['type'] do client)
        $realMime = null;
        if (function_exists('finfo_open')) {
            try {
                $f = finfo_open(FILEINFO_MIME_TYPE);
                if ($f) { $realMime = finfo_file($f, $file['tmp_name']); finfo_close($f); }
            } catch (Throwable $e) {}
        }
        if ($realMime && !in_array($realMime, $allowedMimes, true)) {
            // tolera text/csv detectado como text/plain e zip como octet-stream
            $tolerant = ($ext === 'csv' && in_array($realMime, ['text/plain','text/csv'], true))
                || ($ext === 'zip' && in_array($realMime, ['application/zip','application/octet-stream'], true))
                || ($ext === 'txt' && strpos($realMime, 'text/') === 0);
            if (!$tolerant) {
                Session::addMessageAfterRedirect('Conteúdo do arquivo incompatível (' . $realMime . ')', false, ERROR);
                return null;
            }
        }
        $dir = GLPI_PLUGIN_DOC_DIR . '/kanpro/cards/' . $cards_id . '/';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) return null;
        $filename = uniqid('att_', true) . '.' . $ext;
        $dest = $dir . $filename;
        // só move_uploaded_file — sem fallback copy (evita LFI)
        if (!move_uploaded_file($file['tmp_name'], $dest)) return null;
        $rel = 'cards/' . $cards_id . '/' . $filename;
        $att = new self();
        $id = $att->add([
            'plugin_kanpro_cards_id' => $cards_id,
            'name'       => mb_substr($origName, 0, 255),
            'filename'   => $filename,
            'filepath'   => $rel,
            'filesize'   => (int)($file['size'] ?? @filesize($dest) ?: 0),
            'mime'       => $realMime ?: 'application/octet-stream',
            'users_id'   => function_exists('kanpro_acting_user_id') ? kanpro_acting_user_id() : (int)Session::getLoginUserID(),
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
