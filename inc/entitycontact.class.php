<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * PluginKanproEntityContact — contatos (e-mail/telefone) por entidade.
 *
 * Cadastro feito em front/entitycontacts.php. As notificações por entidade
 * (passo futuro) consomem via getForEntity().
 */
class PluginKanproEntityContact extends CommonDBTM {
    static $rightname = 'plugin_kanpro';

    static function getTypeName($nb = 0) {
        return 'Contatos por entidade';
    }

    /**
     * Contatos de uma entidade (só ativos por padrão), ordenados por nome.
     *
     * @return array<int,array<string,mixed>>
     */
    static function getForEntity(int $entities_id, bool $activeOnly = true): array {
        global $DB;
        $out = [];
        try {
            if ($entities_id <= 0) return $out;
            if (!isset($DB) || !$DB->tableExists('glpi_plugin_kanpro_entities_contacts')) return $out;
            $where = ['entities_id' => $entities_id];
            if ($activeOnly) $where['is_active'] = 1;
            foreach ($DB->request(['FROM' => 'glpi_plugin_kanpro_entities_contacts', 'WHERE' => $where, 'ORDER' => 'name ASC']) as $r) {
                $out[] = $r;
            }
        } catch (Throwable $e) {}
        return $out;
    }
}
