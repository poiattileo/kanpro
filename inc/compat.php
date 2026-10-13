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
