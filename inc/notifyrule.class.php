<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * PluginKanproNotifyRule — regras de notificação por quadro/categoria.
 *
 * Uma regra = (quadro, categoria de lista, usuário GLPI, canais whats/mail,
 * imediato on/off, resumo on/off, ativa on/off). O usuário é resolvido do
 * login na gravação e guardado por users_id (+ login desnormalizado p/ exibir).
 * Nunca joga exceção: erro vira ['ok'=>false] + string.
 */
class PluginKanproNotifyRule extends CommonDBTM {
    static string $rightname = 'plugin_kanpro';

    const TABLE = 'glpi_plugin_kanpro_notify_rules';

    static function getTypeName($nb = 0) {
        return 'Regras de notificação';
    }

    /**
     * Resolve login (ou e-mail) p/ usuário GLPI com contatos.
     * Telefone/e-mail crus (sem normalizar — normalização é no envio).
     *
     * @return array{users_id:int,login:string,name:string,phone:string,email:string}|null
     */
    static function resolveUser(string $login): ?array {
        global $DB;
        try {
            $login = trim($login);
            if ($login === '' || !isset($DB) || !$DB->tableExists('glpi_users')) return null;
            $cols = ['id', 'name', 'realname', 'firstname', 'phone', 'mobile', 'email'];
            $row = $DB->request(['SELECT' => $cols, 'FROM' => 'glpi_users', 'WHERE' => ['name' => $login, 'is_deleted' => 0], 'LIMIT' => 1])->current();
            if (!$row && $DB->tableExists('glpi_useremails')) {
                $em = $DB->request(['SELECT' => ['users_id'], 'FROM' => 'glpi_useremails', 'WHERE' => ['email' => $login], 'LIMIT' => 1])->current();
                if ($em && (int)($em['users_id'] ?? 0) > 0) {
                    $row = $DB->request(['SELECT' => $cols, 'FROM' => 'glpi_users', 'WHERE' => ['id' => (int)$em['users_id'], 'is_deleted' => 0], 'LIMIT' => 1])->current();
                }
            }
            if (!$row) return null;
            if (array_key_exists('is_active', $row) && !(int)$row['is_active']) return null;
            $email = '';
            if ($DB->tableExists('glpi_useremails')) {
                $em2 = $DB->request(['SELECT' => ['email'], 'FROM' => 'glpi_useremails', 'WHERE' => ['users_id' => (int)($row['id'] ?? 0)], 'LIMIT' => 1])->current();
                if ($em2) $email = trim((string)($em2['email'] ?? ''));
            }
            if ($email === '' && array_key_exists('email', $row)) $email = trim((string)($row['email'] ?? ''));
            $phone = trim((string)($row['phone'] ?? ''));
            if ($phone === '') $phone = trim((string)($row['mobile'] ?? ''));
            $display = trim(trim((string)($row['firstname'] ?? '')) . ' ' . trim((string)($row['realname'] ?? '')));
            if ($display === '') $display = trim((string)($row['name'] ?? ''));
            return [
                'users_id' => (int)($row['id'] ?? 0),
                'login'    => trim((string)($row['name'] ?? '')),
                'name'     => $display,
                'phone'    => $phone,
                'email'    => $email,
            ];
        } catch (Throwable $e) { return null; }
    }

    /** Regras do quadro (só ativas por padrão). */
    static function getRulesForBoard(int $bid, bool $activeOnly = true): array {
        global $DB;
        $out = [];
        try {
            if ($bid <= 0 || !isset($DB) || !$DB->tableExists(self::TABLE)) return $out;
            $where = ['plugin_kanpro_boards_id' => $bid];
            if ($activeOnly) $where['is_active'] = 1;
            foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => $where, 'ORDER' => 'id ASC']) as $r) {
                $out[] = $r;
            }
        } catch (Throwable $e) {}
        return $out;
    }

    /**
     * Cria (ou reativa) regra. Recriar a mesma combinação reaproveita o id.
     * @return array{ok:bool,id?:int,warnings?:array<int,string>,error?:string}
     */
    static function addRule(int $bid, string $category, string $login, bool $whats, bool $mail): array {
        global $DB;
        $fail = function (string $e) { return ['ok' => false, 'error' => $e]; };
        try {
            if ($bid <= 0) return $fail('Quadro inválido');
            $cat = function_exists('kanpro_valid_list_type') ? kanpro_valid_list_type($category) : trim(strtolower($category));
            if ($cat === '' || $cat === 'none') return $fail('Categoria inválida');
            if (!$whats && !$mail) return $fail('Escolha ao menos um canal (WhatsApp ou e-mail)');
            if (!isset($DB) || !$DB->tableExists(self::TABLE)) return $fail('Tabela indisponível');
            $u = self::resolveUser($login);
            if ($u === null) return $fail('Usuário GLPI não encontrado');
            $warnings = [];
            if ($whats && trim($u['phone']) === '') $warnings[] = 'sem telefone';
            if ($mail && trim($u['email']) === '') $warnings[] = 'sem e-mail';
            $now = date('Y-m-d H:i:s');
            $found = $DB->request(['SELECT' => ['id'], 'FROM' => self::TABLE, 'WHERE' => ['plugin_kanpro_boards_id' => $bid, 'list_category' => $cat, 'users_id' => $u['users_id']], 'LIMIT' => 1])->current();
            if ($found && (int)($found['id'] ?? 0) > 0) {
                $rid = (int)$found['id'];
                $DB->update(self::TABLE, ['login' => $u['login'], 'notify_whats' => $whats ? 1 : 0, 'notify_mail' => $mail ? 1 : 0, 'is_active' => 1, 'date_mod' => $now], ['id' => $rid]);
                return ['ok' => true, 'id' => $rid, 'warnings' => $warnings];
            }
            $creator = 0;
            try { $creator = (int)Session::getLoginUserID(); } catch (Throwable $e) {}
            $id = $DB->insert(self::TABLE, [
                'plugin_kanpro_boards_id' => $bid,
                'list_category'           => $cat,
                'users_id'                => $u['users_id'],
                'login'                   => $u['login'],
                'notify_whats'            => $whats ? 1 : 0,
                'notify_mail'             => $mail ? 1 : 0,
                'notify_entry'            => 1,
                'notify_digest'           => 1,
                'is_active'               => 1,
                'users_id_creator'        => $creator,
                'date_creation'           => $now,
                'date_mod'                => $now,
            ]);
            if ($id === false) return $fail('Falha ao salvar');
            return ['ok' => true, 'id' => (int)$id, 'warnings' => $warnings];
        } catch (Throwable $e) { return $fail($e->getMessage()); }
    }

    static function deleteRule(int $id, int $bid): array {
        global $DB;
        try {
            if ($id <= 0 || $bid <= 0 || !isset($DB) || !$DB->tableExists(self::TABLE)) return ['ok' => false, 'error' => 'Regra inválida'];
            $ok = $DB->delete(self::TABLE, ['id' => $id, 'plugin_kanpro_boards_id' => $bid]);
            return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'Regra não encontrada'];
        } catch (Throwable $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
    }

    static function setActive(int $id, int $bid, bool $on): array {
        global $DB;
        try {
            if ($id <= 0 || $bid <= 0 || !isset($DB) || !$DB->tableExists(self::TABLE)) return ['ok' => false, 'error' => 'Regra inválida'];
            $ok = $DB->update(self::TABLE, ['is_active' => $on ? 1 : 0, 'date_mod' => date('Y-m-d H:i:s')], ['id' => $id, 'plugin_kanpro_boards_id' => $bid]);
            return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'Regra não encontrada'];
        } catch (Throwable $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
    }
}
