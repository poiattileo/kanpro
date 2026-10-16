<?php
/**
 * Compatibilidade KanPro entre GLPI 11 e GLPI 12.
 *
 * Helpers guardados por function_exists/method_exists para funcionar nas duas
 * versões. No GLPI 12: Plugin::getWebDir() foi removido, Session::getNewCSRFToken()
 * e Session::checkCSRF() viraram no-ops (só emitem deprecation) e o CSRF passou a
 * ser validado pelo CheckCsrfListener (Sec-Fetch-Site/Origin).
 */
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

if (!function_exists('kanpro_glpi_version')) {
    function kanpro_glpi_version(): string {
        return defined('GLPI_VERSION') ? (string) GLPI_VERSION : '';
    }
}

if (!function_exists('kanpro_glpi_major')) {
    function kanpro_glpi_major(): int {
        $v = kanpro_glpi_version();
        if ($v === '') {
            return 0;
        }
        return (int) explode('.', $v)[0];
    }
}

if (!function_exists('kanpro_is_glpi12_plus')) {
    function kanpro_is_glpi12_plus(): bool {
        return kanpro_glpi_major() >= 12;
    }
}

if (!function_exists('kanpro_web_dir')) {
    /**
     * URL base do plugin (ex.: "/glpi/plugins/kanpro").
     * GLPI <=11: usa Plugin::getWebDir(); GLPI 12 (método removido): monta via root_doc.
     */
    function kanpro_web_dir(string $plugin = 'kanpro'): string {
        if (method_exists('Plugin', 'getWebDir')) {
            return (string) Plugin::getWebDir($plugin);
        }
        global $CFG_GLPI;
        $root = (is_array($CFG_GLPI) && isset($CFG_GLPI['root_doc'])) ? (string) $CFG_GLPI['root_doc'] : '';
        return $root . '/plugins/' . $plugin;
    }
}

if (!function_exists('kanpro_csrf_token')) {
    /**
     * Token CSRF da página. No GLPI 12 não há token (retorna ''); o CSRF é
     * tratado pelo listener do core. No GLPI 11 mantém o comportamento atual.
     */
    function kanpro_csrf_token(): string {
        if (kanpro_is_glpi12_plus()) {
            return '';
        }
        return (string) Session::getNewCSRFToken();
    }
}

if (!function_exists('kanpro_check_csrf')) {
    /**
     * Validação CSRF explícita. No GLPI 12 é no-op (o CheckCsrfListener cuida);
     * no GLPI 11 delega para Session::checkCSRF() preservando o token.
     */
    function kanpro_check_csrf(array $data): void {
        if (kanpro_is_glpi12_plus()) {
            return;
        }
        Session::checkCSRF($data, true);
    }
}

if (!function_exists('kanpro_mail_queue_input')) {
    /**
     * Monta a linha da fila oficial de e-mails do GLPI (glpi_queuednotifications).
     * Puro e testável; o envio real é kanpro_queue_email(). Nunca joga exceção.
     *
     * @param array{to_name?:string,itemtype?:string,items_id?:int} $extra
     * @return array{ok:bool,row?:array<string,mixed>,error?:string}
     */
    function kanpro_mail_queue_input(string $to, string $subject, string $htmlBody, int $entities_id = 0, array $extra = []): array {
        $to = trim($to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'e-mail inválido'];
        $subject = trim($subject);
        if ($subject === '') return ['ok' => false, 'error' => 'assunto vazio'];
        if (function_exists('mb_strlen') && function_exists('mb_substr') && mb_strlen($subject) > 255) $subject = mb_substr($subject, 0, 255);
        elseif (strlen($subject) > 255) $subject = substr($subject, 0, 255);
        $text = html_entity_decode(strip_tags((string)preg_replace('/<(br|p|div|li|tr)[^>]*>/i', "\n", $htmlBody)), ENT_QUOTES, 'UTF-8');
        $text = trim((string)preg_replace("/[ \t]+/", ' ', $text));
        $sender = ''; $sendername = 'KanPro';
        try {
            if (class_exists('Config') && method_exists('Config', 'getAdminEmailSender')) {
                $adm = Config::getAdminEmailSender();
                if (is_array($adm)) {
                    if (trim((string)($adm['email'] ?? '')) !== '') $sender = trim((string)$adm['email']);
                    if (trim((string)($adm['name'] ?? '')) !== '') $sendername = trim((string)$adm['name']);
                }
            }
        } catch (Throwable $e) {}
        if ($sender === '') {
            try {
                global $CFG_GLPI;
                $sender = trim((string)($CFG_GLPI['admin_email'] ?? ''));
                $nm = trim((string)($CFG_GLPI['admin_email_name'] ?? ''));
                if ($nm !== '') $sendername = $nm;
            } catch (Throwable $e) {}
        }
        $attachNoDoc = 0;
        try {
            if (class_exists('NotificationSetting') && defined('NotificationSetting::ATTACH_NO_DOCUMENT')) $attachNoDoc = (int)constant('NotificationSetting::ATTACH_NO_DOCUMENT');
        } catch (Throwable $e) {}
        $now = date('Y-m-d H:i:s');
        return ['ok' => true, 'row' => [
            'name' => $subject,
            'sender' => $sender, 'sendername' => $sendername,
            'recipient' => $to, 'recipientname' => trim((string)($extra['to_name'] ?? '')),
            'replyto' => '', 'replytoname' => '',
            'headers' => '',
            'body_html' => $htmlBody, 'body_text' => $text,
            'messageid' => '',
            'mode' => 'mailing',
            'itemtype' => (string)($extra['itemtype'] ?? 'PluginKanproCard'),
            'items_id' => (int)($extra['items_id'] ?? 0),
            'notificationtemplates_id' => 0,
            'entities_id' => $entities_id,
            'event' => null, // evita getMessageReferenceEvent (caminho suportado p/ evento nulo)
            'attach_documents' => $attachNoDoc,
            'is_deleted' => 0,
            'create_time' => $now, 'send_time' => $now,
        ]];
    }
}

if (!function_exists('kanpro_queue_email')) {
    /**
     * Enfileira e-mail na fila oficial do GLPI (o cron queuednotification entrega).
     * Nunca joga exceção.
     *
     * @return array{ok:bool,id?:int,error?:string}
     */
    function kanpro_queue_email(string $to, string $subject, string $htmlBody, int $entities_id = 0, array $extra = []): array {
        $built = kanpro_mail_queue_input($to, $subject, $htmlBody, $entities_id, $extra);
        if (empty($built['ok'])) return $built;
        try {
            if (!class_exists('QueuedNotification')) return ['ok' => false, 'error' => 'fila de e-mail indisponível (ative o cron queuednotification no GLPI)'];
            $qn = new QueuedNotification();
            $id = $qn->add($built['row']);
            if (!$id) return ['ok' => false, 'error' => 'falha ao enfileirar e-mail'];
            return ['ok' => true, 'id' => (int)$id];
        } catch (Throwable $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
    }
}
