<?php
/**
 * tests/stubs.php — stubs mínimos do GLPI p/ testes e análise estática.
 *
 * O núcleo do GLPI não existe neste repo; os stubs dão aos testes e ao
 * PHPStan os símbolos necessários. NUNCA carregar em produção (lá as
 * classes reais existem e o class_exists abaixo impede redeclaração).
 *
 * PluginKanproBoard::logActivity captura as chamadas em $activityLog
 * para os testes verificarem o log sem banco real.
 */
if (!class_exists('CommonDBTM')) {
    // $rightname tipado como string: as classes do plugin declaram
    // `static string $rightname` (compat GLPI 12); o pai precisa bater o tipo,
    // senão o PHP 8.1+ aborta com "Type of ... must not be defined".
    class CommonDBTM {
        public static string $rightname = '';
    }
}

if (!class_exists('PluginKanproBoard')) {
    class PluginKanproBoard {
        /** @var array<int,array<int|string,mixed>> */
        public static array $activityLog = [];

        /** @var array<int,array<string,mixed>> linhas por id p/ getFromDB */
        public static array $rows = [];

        /** @var array<string,mixed> */
        public array $fields = [];

        /** @param mixed ...$args */
        public static function logActivity(...$args): void {
            self::$activityLog[] = $args;
        }

        public static function resetActivityLog(): void {
            self::$activityLog = [];
        }

        public function getFromDB(int $id): bool {
            if ($id <= 0) {
                return false;
            }
            if (isset(self::$rows[$id])) {
                $this->fields = self::$rows[$id];
                return true;
            }
            return false;
        }
    }
}

if (!class_exists('PluginKanproCard')) {
    class PluginKanproCard {
        /** @var array<string,mixed> */
        public array $fields = ['plugin_kanpro_boards_id' => 7, 'plugin_kanpro_lists_id' => 9];

        /** @var array<int,array<string,mixed>> linhas por id p/ getFromDB */
        public static array $rows = [];

        public function getFromDB(int $id): bool {
            if ($id <= 0) {
                return false;
            }
            if (isset(self::$rows[$id])) {
                $this->fields = self::$rows[$id];
                return true;
            }
            return true; // legado: id válido existe com os fields padrão
        }
    }
}

if (!class_exists('Session')) {
    // Sessão mínima: o id logado é controlável pelos testes.
    class Session {
        /** @var int usuário logado (0 = ninguém) */
        public static int $userId = 0;

        public static function getLoginUserID(): int {
            return self::$userId;
        }

        /** @param mixed $rights */
        public static function haveRight(string $name, $rights = null): bool {
            return false;
        }

        /** @param mixed $rights */
        public static function haveRightsOr(string $name, $rights = null): bool {
            return false;
        }
    }
}

if (!class_exists('User')) {
    // Usuário mínimo: getFromDB falha (cai no login da sessão).
    class User {
        /** @var array<string,mixed> */
        public array $fields = [];

        public function getFromDB(int $id): bool {
            return false;
        }
    }
}

if (!function_exists('countElementsInTable')) {
    /**
     * Conta linhas (igualdade simples; operadores do QueryBuilder real
     * como LIKE não são suportados — os caminhos testados usam igualdade).
     *
     * @param array<string,mixed> $conditions
     */
    function countElementsInTable(string $table, array $conditions = []): int {
        global $DB;
        $n = 0;
        foreach ($DB->request(['FROM' => $table, 'WHERE' => $conditions]) as $row) {
            $n++;
        }
        return $n;
    }
}
