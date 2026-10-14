<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * KanPro no Telegram (polling via cron — GLPI interno, sem webhook).
 *
 * Leitura (v1): /quadros, /quadro <nome|#id>, /cartao <busca|#id>, /ajuda, /id.
 *
 * Auth: allowlist em glpi_plugin_kanpro_configs (telegram_allowlist, JSON
 * {"chat_id":"login_glpi"}). Cada chat é vinculado a um usuário GLPI e só
 * enxerga quadros das entidades dele + listas sem restrição de viewers
 * (mesma regra de kanpro_can_view_list, porém com users_id explícito,
 * pois o cron roda sem sessão). Fail closed: sem token ou sem allowlist,
 * nada é processado.
 *
 * Polling: cronTelegram() roda via tarefa externa (SO) a cada 1min e consome
 * getUpdates com offset persistido em telegram_update_offset.
 */
class PluginKanproTelegram extends CommonDBTM {

    const API_BASE = 'https://api.telegram.org/bot';

    static function getTypeName($nb = 0) {
        return 'Telegram';
    }

    /**
     * Garante os helpers de acting.php (o cron não inclui os fronts,
     * então as funções precisam ser carregadas aqui). Idempotente.
     */
    protected static function boot(): void {
        try {
            if (!function_exists('kanpro_config_get') || !function_exists('kanpro_resolve_user_by_login') || !function_exists('kanpro_list_viewer_ids')) {
                $f = (defined('GLPI_ROOT') ? (string)GLPI_ROOT : '') . '/plugins/kanpro/inc/acting.php';
                if ($f !== '' && is_file($f)) {
                    require_once $f;
                }
            }
        } catch (Throwable $e) {}
    }

    /** Config: ['token'=>string, 'allow'=>[chat_id=>login], 'offset'=>int]. */
    public static function cfg(): array {
        $token = '';
        $allow = [];
        $offset = 0;
        try {
            self::boot();
            if (!function_exists('kanpro_config_get')) {
                return ['token' => '', 'allow' => [], 'offset' => 0];
            }
            $token = trim((string)kanpro_config_get('telegram_bot_token', ''));
            $raw = trim((string)kanpro_config_get('telegram_allowlist', ''));
            if ($raw !== '') {
                $dec = json_decode($raw, true);
                if (is_array($dec)) {
                    foreach ($dec as $chat => $login) {
                        $chat = trim((string)$chat);
                        $login = trim((string)$login);
                        if ($chat !== '' && ctype_digit($chat) && $login !== '') {
                            $allow[$chat] = $login;
                        }
                    }
                }
            }
            $offset = (int)kanpro_config_get('telegram_update_offset', '0');
        } catch (Throwable $e) {}
        return ['token' => $token, 'allow' => $allow, 'offset' => $offset];
    }

    public static function isEnabled(): bool {
        $c = self::cfg();
        return $c['token'] !== '' && !empty($c['allow']);
    }

    /** POST JSON na Bot API. Devolve ['ok'=>bool,'result'=>mixed,'error'=>string]. */
    public static function tgApi(string $token, string $method, array $params = [], int $timeout = 25): array {
        $out = ['ok' => false, 'result' => null, 'error' => ''];
        try {
            $url = self::API_BASE . $token . '/' . $method;
            $body = (string)json_encode($params, JSON_UNESCAPED_UNICODE);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_TIMEOUT        => $timeout,
            ]);
            $resp = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($resp === false) {
                $out['error'] = 'cURL: ' . $err;
                return $out;
            }
            if ($code < 200 || $code >= 300) {
                $out['error'] = 'HTTP ' . $code;
                return $out;
            }
            $dec = json_decode((string)$resp, true);
            if (!is_array($dec) || empty($dec['ok'])) {
                $out['error'] = 'Resposta inválida do Telegram';
                return $out;
            }
            $out['ok'] = true;
            $out['result'] = $dec['result'] ?? null;
            return $out;
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();
            return $out;
        }
    }

    protected static function esc(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    /** Quebra texto longo em partes (limite do Telegram: 4096). */
    protected static function splitMessage(string $text, int $limit = 4000): array {
        if (mb_strlen($text) <= $limit) {
            return [$text];
        }
        $out = [];
        $rest = $text;
        while (mb_strlen($rest) > $limit) {
            $cut = mb_strrpos(mb_substr($rest, 0, $limit + 1), "\n");
            if ($cut === false || $cut < 200) {
                $cut = $limit;
            }
            $out[] = mb_substr($rest, 0, (int)$cut);
            $rest = ltrim(mb_substr($rest, (int)$cut));
        }
        if ($rest !== '') {
            $out[] = $rest;
        }
        return $out;
    }

    /** Envia texto (HTML). Tenta com parse e cai para texto puro se quebrar. */
    public static function sendMessage(string $token, $chat_id, string $text): bool {
        $text = trim($text);
        if ($text === '') {
            return false;
        }
        $sent = 0;
        foreach (self::splitMessage($text) as $part) {
            $r = self::tgApi($token, 'sendMessage', [
                'chat_id'                => $chat_id,
                'text'                   => $part,
                'parse_mode'             => 'HTML',
                'disable_web_page_preview' => true,
            ], 20);
            if ($r['ok']) {
                $sent++;
                continue;
            }
            $r2 = self::tgApi($token, 'sendMessage', [
                'chat_id'                => $chat_id,
                'text'                   => $part,
                'disable_web_page_preview' => true,
            ], 20);
            if ($r2['ok']) {
                $sent++;
            }
        }
        return $sent > 0;
    }

    /**
     * Ponto de entrada do polling (chamado pelo cron).
     * Devolve ['ok'=>bool,'updates'=>int,'replies'=>int,'error'=>string].
     */
    public static function processPoll(): array {
        $stats = ['ok' => false, 'updates' => 0, 'replies' => 0, 'error' => ''];
        try {
            $c = self::cfg();
            if ($c['token'] === '' || empty($c['allow'])) {
                $stats['error'] = 'Telegram não configurado (token/allowlist).';
                return $stats;
            }
            $params = ['timeout' => 15, 'allowed_updates' => ['message']];
            if ($c['offset'] > 0) {
                $params['offset'] = $c['offset'];
            }
            $r = self::tgApi($c['token'], 'getUpdates', $params, 25);
            if (!$r['ok']) {
                $stats['error'] = (string)$r['error'];
                return $stats;
            }
            $updates = is_array($r['result']) ? $r['result'] : [];
            $maxId = $c['offset'];
            foreach ($updates as $u) {
                if (!is_array($u)) {
                    continue;
                }
                $uid = (int)($u['update_id'] ?? 0);
                if ($uid > $maxId) {
                    $maxId = $uid;
                }
                try {
                    if (self::handleUpdate($c, $u)) {
                        $stats['replies']++;
                    }
                    $stats['updates']++;
                } catch (Throwable $e) {
                    error_log('[KanPro][telegram] update ' . $uid . ': ' . $e->getMessage());
                }
            }
            if ($maxId > $c['offset'] && function_exists('kanpro_config_set')) {
                kanpro_config_set('telegram_update_offset', (string)($maxId + 1));
            }
            $stats['ok'] = true;
            return $stats;
        } catch (Throwable $e) {
            $stats['error'] = $e->getMessage();
            return $stats;
        }
    }

    /** Interpreta um update e responde. Devolve true se respondeu. */
    protected static function handleUpdate(array $c, array $u): bool {
        $msg = $u['message'] ?? null;
        if (!is_array($msg)) {
            return false;
        }
        // ignora mensagens de bots (evita loop) e sem texto
        $from = $msg['from'] ?? [];
        if (is_array($from) && !empty($from['is_bot'])) {
            return false;
        }
        $chat = $msg['chat'] ?? [];
        $chatId = trim((string)(is_array($chat) ? ($chat['id'] ?? '') : ''));
        if ($chatId === '') {
            return false;
        }
        $text = trim((string)($msg['text'] ?? ''));
        if ($text === '') {
            return false;
        }

        if (!isset($c['allow'][$chatId])) {
            self::sendMessage($c['token'], $chatId, "⛔ <b>Acesso restrito.</b>\nSeu chat id é <code>" . self::esc($chatId) . "</code> — peça ao administrador para liberar.");
            return true;
        }
        $login = (string)$c['allow'][$chatId];
        $users_id = function_exists('kanpro_resolve_user_by_login') ? (int)kanpro_resolve_user_by_login($login) : 0;

        $parts = preg_split('/\s+/', $text, 2);
        if (!is_array($parts)) {
            $parts = [$text];
        }
        $cmd = strtolower((string)($parts[0] ?? ''));
        $cmd = (string)preg_replace('/@.+$/', '', $cmd);
        $arg = trim((string)($parts[1] ?? ''));

        switch ($cmd) {
            case '/start':
            case '/ajuda':
            case '/help':
                $reply = self::helpText();
                break;
            case '/id':
                $reply = "Seu chat id é <code>" . self::esc($chatId) . "</code>"
                    . ($users_id > 0 ? " (vinculado a <b>" . self::esc($login) . "</b>)" : " (sem vínculo GLPI — avise o administrador)");
                break;
            case '/quadros':
                $reply = self::cmdBoards($users_id, $login);
                break;
            case '/quadro':
                $reply = self::cmdBoard($users_id, $login, $arg);
                break;
            case '/cartao':
            case '/card':
                $reply = self::cmdCard($users_id, $login, $arg);
                break;
            default:
                $reply = "Comando não reconhecido. Use /ajuda.";
                break;
        }
        return self::sendMessage($c['token'], $chatId, (string)$reply);
    }

    protected static function helpText(): string {
        return "<b>KanPro no Telegram</b>\n"
            . "Consultas (somente leitura):\n"
            . "/quadros — lista seus quadros\n"
            . "/quadro &lt;nome ou #id&gt; — resumo do quadro (listas, totais, atrasados)\n"
            . "/cartao &lt;busca ou #id&gt; — detalhe do cartão\n"
            . "/id — mostra seu chat id\n"
            . "/ajuda — esta mensagem";
    }

    protected static function needUser(int $users_id, string $login): ?string {
        if ($users_id > 0) {
            return null;
        }
        return "⚠ Seu chat está liberado, mas o login <b>" . self::esc($login) . "</b> não foi encontrado no GLPI. Avise o administrador.";
    }

    /** Entidades do usuário (via perfis). Sem vínculo válido, devolve [0]. */
    protected static function userEntities(int $users_id): array {
        global $DB;
        $out = [];
        try {
            if ($users_id <= 0) {
                return [0];
            }
            if (!$DB->tableExists('glpi_profiles_users')) {
                return [0];
            }
            foreach ($DB->request(['SELECT' => ['entities_id'], 'FROM' => 'glpi_profiles_users', 'WHERE' => ['users_id' => $users_id]]) as $r) {
                $out[(int)$r['entities_id']] = true;
            }
        } catch (Throwable $e) {}
        $ids = array_keys($out);
        return !empty($ids) ? $ids : [0];
    }

    /** Quadros não arquivados das entidades do usuário. */
    protected static function myBoards(int $users_id): array {
        $out = [];
        try {
            if (!class_exists('PluginKanproBoard')) {
                return [];
            }
            foreach (PluginKanproBoard::getBoardsForEntity(self::userEntities($users_id), false) as $b) {
                $out[] = $b;
            }
        } catch (Throwable $e) {}
        return $out;
    }

    /** Regra de viewers com users_id explícito (cron não tem sessão). */
    protected static function canSeeList($list, int $users_id): bool {
        try {
            $lid = (int)(is_array($list) ? ($list['id'] ?? 0) : $list);
            if ($lid <= 0) {
                return false;
            }
            if (!function_exists('kanpro_list_viewer_ids')) {
                return true;
            }
            $viewers = kanpro_list_viewer_ids($lid);
            if (empty($viewers)) {
                return true;
            }
            return $users_id > 0 && in_array($users_id, $viewers, true);
        } catch (Throwable $e) {
            return true;
        }
    }

    protected static function boardName(int $boards_id): string {
        global $DB;
        try {
            $row = $DB->request(['SELECT' => ['name'], 'FROM' => 'glpi_plugin_kanpro_boards', 'WHERE' => ['id' => $boards_id], 'LIMIT' => 1])->current();
            if (is_array($row) && isset($row['name'])) {
                return (string)$row['name'];
            }
        } catch (Throwable $e) {}
        return 'Quadro #' . $boards_id;
    }

    protected static function listName(int $lists_id): string {
        global $DB;
        try {
            $row = $DB->request(['SELECT' => ['name'], 'FROM' => 'glpi_plugin_kanpro_lists', 'WHERE' => ['id' => $lists_id], 'LIMIT' => 1])->current();
            if (is_array($row) && isset($row['name'])) {
                return (string)$row['name'];
            }
        } catch (Throwable $e) {}
        return 'Lista #' . $lists_id;
    }

    protected static function cmdBoards(int $users_id, string $login): string {
        $err = self::needUser($users_id, $login);
        if ($err !== null) {
            return $err;
        }
        $boards = self::myBoards($users_id);
        if (empty($boards)) {
            return "Nenhum quadro visível para você.";
        }
        $lines = ["<b>Quadros</b> (" . count($boards) . "):", ""];
        foreach ($boards as $b) {
            $bid = (int)($b['id'] ?? 0);
            $nm = self::esc((string)($b['name'] ?? ('Quadro #' . $bid)));
            $n = 0;
            try {
                if (class_exists('PluginKanproBoard')) {
                    $n = (int)PluginKanproBoard::countCardsInBoard($bid);
                }
            } catch (Throwable $e) {}
            $lines[] = "• {$nm} <code>#{$bid}</code> — {$n} " . ($n === 1 ? 'cartão' : 'cartões');
        }
        $lines[] = "";
        $lines[] = "Detalhes: <code>/quadro &lt;nome ou #id&gt;</code>";
        return implode("\n", $lines);
    }

    protected static function cmdBoard(int $users_id, string $login, string $arg): string {
        $err = self::needUser($users_id, $login);
        if ($err !== null) {
            return $err;
        }
        if ($arg === '') {
            return "Uso: <code>/quadro &lt;nome ou #id&gt;</code>\nEx.: <code>/quadro obras</code> ou <code>/quadro #12</code>";
        }
        $boards = self::myBoards($users_id);
        $byId = 0;
        if (preg_match('/^#?(\d+)$/', $arg, $m)) {
            $byId = (int)$m[1];
        }
        $found = [];
        foreach ($boards as $b) {
            $bid = (int)($b['id'] ?? 0);
            if ($byId > 0) {
                if ($bid === $byId) {
                    $found[] = $b;
                }
                continue;
            }
            if (mb_stripos((string)($b['name'] ?? ''), $arg) !== false) {
                $found[] = $b;
            }
        }
        if (empty($found)) {
            return "Quadro não encontrado para <b>" . self::esc($arg) . "</b>.";
        }
        if (count($found) > 1) {
            $lines = ["Encontrei " . count($found) . " quadros — refine:", ""];
            foreach (array_slice($found, 0, 10) as $b) {
                $lines[] = "• " . self::esc((string)($b['name'] ?? '')) . " <code>#" . (int)($b['id'] ?? 0) . "</code>";
            }
            return implode("\n", $lines);
        }
        return self::formatBoard($found[0], $users_id);
    }

    protected static function formatBoard(array $b, int $users_id): string {
        $bid = (int)($b['id'] ?? 0);
        $lines = ["<b>" . self::esc((string)($b['name'] ?? ('Quadro #' . $bid))) . "</b> <code>#{$bid}</code>", ""];
        $lists = [];
        try {
            if (class_exists('PluginKanproList')) {
                foreach (PluginKanproList::getListsForBoard($bid, false) as $l) {
                    $lists[] = $l;
                }
            }
        } catch (Throwable $e) {}
        $total = 0;
        $done = 0;
        $late = 0;
        $lateNames = [];
        $now = time();
        foreach ($lists as $l) {
            $lid = (int)($l['id'] ?? 0);
            if (!self::canSeeList($lid, $users_id)) {
                continue;
            }
            $cards = [];
            try {
                if (class_exists('PluginKanproCard')) {
                    foreach (PluginKanproCard::getCardsForList($lid, false) as $cd) {
                        $cards[] = $cd;
                    }
                }
            } catch (Throwable $e) {}
            $n = count($cards);
            $total += $n;
            $ldone = 0;
            foreach ($cards as $cd) {
                if (!empty($cd['is_completed'])) {
                    $done++;
                    $ldone++;
                    continue;
                }
                if (!empty($cd['due_date'])) {
                    $ts = strtotime((string)$cd['due_date']);
                    if ($ts && $ts < $now) {
                        $late++;
                        if (count($lateNames) < 5) {
                            $lateNames[] = (string)($cd['name'] ?? ('#' . (int)($cd['id'] ?? 0)));
                        }
                    }
                }
            }
            $lines[] = "▪ " . self::esc((string)($l['name'] ?? ('Lista #' . $lid))) . " — <b>{$n}</b>" . ($ldone > 0 ? " ({$ldone} ✓)" : "");
        }
        $lines[] = "";
        $lines[] = "Total: <b>{$total}</b> • concluídos: <b>{$done}</b>" . ($late > 0 ? " • ⚠ <b>{$late} atrasado(s)</b>" : " • sem atrasos 🎉");
        foreach ($lateNames as $ln) {
            $lines[] = "  ⚠ " . self::esc($ln);
        }
        return implode("\n", $lines);
    }

    protected static function cmdCard(int $users_id, string $login, string $arg): string {
        $err = self::needUser($users_id, $login);
        if ($err !== null) {
            return $err;
        }
        if ($arg === '') {
            return "Uso: <code>/cartao &lt;busca ou #id&gt;</code>\nEx.: <code>/cartao bomba</code> ou <code>/cartao #45</code>";
        }
        global $DB;
        $boards = self::myBoards($users_id);
        $bids = [];
        foreach ($boards as $b) {
            $bid = (int)($b['id'] ?? 0);
            if ($bid > 0) {
                $bids[$bid] = true;
            }
        }
        $bids = array_keys($bids);
        if (empty($bids)) {
            return "Nenhum quadro visível para você.";
        }
        $byId = 0;
        if (preg_match('/^#?(\d+)$/', $arg, $m)) {
            $byId = (int)$m[1];
        }
        $rows = [];
        try {
            if ($byId > 0) {
                $where = ['id' => $byId, 'plugin_kanpro_boards_id' => $bids, 'is_archived' => 0];
            } else {
                $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $arg) . '%';
                $where = ['plugin_kanpro_boards_id' => $bids, 'is_archived' => 0, 'name' => ['LIKE', $like]];
            }
            foreach ($DB->request(['FROM' => 'glpi_plugin_kanpro_cards', 'WHERE' => $where, 'ORDER' => 'date_mod DESC', 'LIMIT' => 20]) as $r) {
                if (!self::canSeeList((int)($r['plugin_kanpro_lists_id'] ?? 0), $users_id)) {
                    continue;
                }
                $rows[] = $r;
            }
        } catch (Throwable $e) {
            return "Erro ao buscar. Tente de novo em instantes.";
        }
        if (empty($rows)) {
            return "Nenhum cartão para <b>" . self::esc($arg) . "</b>.";
        }
        if (count($rows) === 1) {
            return self::formatCard((int)$rows[0]['id']);
        }
        $lines = [count($rows) . " cartões — refine a busca:", ""];
        foreach (array_slice($rows, 0, 10) as $r) {
            $lines[] = "• " . self::esc((string)($r['name'] ?? '')) . " <code>#" . (int)$r['id'] . "</code>";
        }
        $lines[] = "";
        $lines[] = "Detalhe: <code>/cartao #id</code>";
        return implode("\n", $lines);
    }

    protected static function formatCard(int $cards_id): string {
        try {
            if (!class_exists('PluginKanproCard')) {
                return "Indisponível no momento.";
            }
            $d = PluginKanproCard::getFullData($cards_id);
        } catch (Throwable $e) {
            return "Erro ao abrir o cartão.";
        }
        if (!is_array($d)) {
            return "Cartão não encontrado.";
        }
        $lines = [];
        $lines[] = "<b>" . self::esc((string)($d['name'] ?? ('Cartão #' . $cards_id))) . "</b> <code>#{$cards_id}</code>";
        $bn = self::boardName((int)($d['plugin_kanpro_boards_id'] ?? 0));
        $ln = self::listName((int)($d['plugin_kanpro_lists_id'] ?? 0));
        $lines[] = self::esc($bn) . " › " . self::esc($ln);

        $status = !empty($d['is_completed']) ? '✅ Concluído' : '🔹 Aberto';
        $dueTxt = '';
        if (!empty($d['due_date'])) {
            $ts = strtotime((string)$d['due_date']);
            $ds = $ts ? date('d/m/Y', $ts) : (string)$d['due_date'];
            if (empty($d['is_completed']) && $ts && $ts < time()) {
                $dueTxt = " • ⚠ <b>atrasado (" . self::esc($ds) . ")</b>";
            } else {
                $dueTxt = " • vence " . self::esc($ds);
            }
        }
        $lines[] = $status . $dueTxt;

        $mem = [];
        foreach ((array)($d['members'] ?? []) as $m) {
            if (!is_array($m)) {
                continue;
            }
            $nm = trim((string)(($m['firstname'] ?? '') . ' ' . ($m['realname'] ?? '')));
            if ($nm === '') {
                $nm = trim((string)($m['name'] ?? ''));
            }
            if ($nm !== '') {
                $mem[] = $nm;
            }
        }
        if (!empty($mem)) {
            $lines[] = "👤 " . self::esc(implode(', ', $mem));
        }

        $lbs = [];
        foreach ((array)($d['labels'] ?? []) as $l) {
            if (!is_array($l)) {
                continue;
            }
            $t = trim((string)($l['name'] ?? ''));
            if ($t !== '') {
                $lbs[] = $t;
            }
        }
        if (!empty($lbs)) {
            $lines[] = "🏷 " . self::esc(implode(', ', $lbs));
        }

        $tot = 0;
        $okc = 0;
        foreach ((array)($d['checklists'] ?? []) as $cl) {
            if (!is_array($cl)) {
                continue;
            }
            foreach ((array)($cl['items'] ?? []) as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $tot++;
                if (!empty($it['is_checked'])) {
                    $okc++;
                }
            }
        }
        if ($tot > 0) {
            $lines[] = "☑ Checklist {$okc}/{$tot}";
        }

        $desc = trim(strip_tags((string)($d['description'] ?? '')));
        if ($desc !== '') {
            if (mb_strlen($desc) > 300) {
                $desc = mb_substr($desc, 0, 300) . '…';
            }
            $lines[] = "";
            $lines[] = self::esc($desc);
        }

        $coms = array_values((array)($d['comments'] ?? []));
        if (!empty($coms)) {
            $shown = 0;
            $clines = [];
            foreach (array_slice($coms, -2) as $cm) {
                if (!is_array($cm)) {
                    continue;
                }
                $who = trim((string)(($cm['firstname'] ?? '') . ' ' . ($cm['realname'] ?? '')));
                if ($who === '') {
                    $who = trim((string)($cm['user_name'] ?? 'alguém'));
                }
                $txt = trim(strip_tags((string)($cm['content'] ?? '')));
                if (mb_strlen($txt) > 200) {
                    $txt = mb_substr($txt, 0, 200) . '…';
                }
                if ($txt === '') {
                    continue;
                }
                $clines[] = "– <i>" . self::esc($who) . ":</i> " . self::esc($txt);
                $shown++;
            }
            if ($shown > 0) {
                $lines[] = "";
                $lines[] = "<b>Últimos comentários:</b>";
                foreach ($clines as $cl) {
                    $lines[] = $cl;
                }
            }
        }
        return implode("\n", $lines);
    }

    /** Tarefa cron: consome a fila do Telegram (getUpdates). */
    public static function cronTelegram($task = null): int {
        try {
            if (!self::isEnabled()) {
                return 1;
            }
            $stats = self::processPoll();
            if (!$stats['ok']) {
                error_log('[KanPro][telegram] poll: ' . $stats['error']);
            }
            return 1;
        } catch (Throwable $e) {
            error_log('[KanPro][telegram] cron: ' . $e->getMessage());
            return 1;
        }
    }

    public static function registerCron(): void {
        global $DB;
        if (!$DB->tableExists('glpi_crontasks')) {
            return;
        }
        try {
            $found = null;
            foreach ($DB->request(['FROM' => 'glpi_crontasks', 'WHERE' => ['itemtype' => 'PluginKanproTelegram', 'name' => 'telegram'], 'LIMIT' => 1]) as $r) {
                $found = $r;
                break;
            }
            $fields = [
                'frequency'     => 60, // polling a cada 1min
                'param'         => 20,
                'state'         => 1,
                'mode'          => 2, // MODE_EXTERNAL: exige cron do SO (igual zapqueue)
                'allowmode'     => 3,
                'logs_lifetime' => 30,
                'hourmin'       => 0,
                'hourmax'       => 24,
                'comment'       => 'KanPro: Telegram polling (comandos /quadros /quadro /cartao)',
            ];
            if (!$found) {
                $DB->insert('glpi_crontasks', array_merge([
                    'itemtype' => 'PluginKanproTelegram',
                    'name'     => 'telegram',
                ], $fields));
            } else {
                $DB->update('glpi_crontasks', $fields, ['id' => (int)$found['id']]);
            }
        } catch (Throwable $e) {
            error_log('[KanPro] registerCron telegram: ' . $e->getMessage());
        }
    }

    /** Garante a tarefa em instalações já existentes (chamado no init). */
    public static function ensureCron(): void {
        global $DB;
        try {
            if (!$DB->tableExists('glpi_crontasks')) {
                return;
            }
            foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_crontasks', 'WHERE' => ['itemtype' => 'PluginKanproTelegram', 'name' => 'telegram'], 'LIMIT' => 1]) as $r) {
                return;
            }
            self::registerCron();
        } catch (Throwable $e) {}
    }

    public static function unregisterCron(): void {
        global $DB;
        try {
            if ($DB->tableExists('glpi_crontasks')) {
                $DB->delete('glpi_crontasks', ['itemtype' => 'PluginKanproTelegram', 'name' => ['telegram']]);
            }
        } catch (Throwable $e) {}
    }
}
