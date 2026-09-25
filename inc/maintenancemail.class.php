<?php
if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * PluginKanproMaintenanceMail — e-mails da manutenção de TI (KanPro)
 *
 * Templates em plugins/kanpro/templates_email/*.html com placeholders {nome}.
 * Não envia sozinho: renderiza [assunto, corpo] — o disparo (NotificationMailing
 * do GLPI) é ligado depois em cada gatilho.
 *
 * Gatilhos previstos:
 *   entrada   convert_to_maintenance / setup_maintenance_machines
 *             → "recebemos as máquinas" + lista quais
 *   retirada  retirada_machine / finalize_maintenance (transferência)
 *             → "máquinas prontas" + lista quais
 *   atraso    cron (7/14/30 dias após maintenance_date)
 *             → "há {dias} dias aqui, pronto p/ buscar"
 *   cancelado revert_maintenance / exclusão do card de manutenção
 *             → "houve engano, desconsidere o recebimento"
 *
 * Uso:
 *   $data = PluginKanproMaintenanceMail::baseData($cards_id);
 *   $data['observacao'] = '...';
 *   [$subject, $body] = [PluginKanproMaintenanceMail::subject('entrada', $data),
 *                        PluginKanproMaintenanceMail::render('entrada', $data)];
 */
class PluginKanproMaintenanceMail extends CommonDBTM {
    static $rightname = 'plugin_kanpro';

    static function getTypeName($nb = 0) {
        return 'E-mails de manutenção';
    }

    static function allowedTypes(): array {
        return ['entrada', 'retirada', 'atraso', 'cancelado'];
    }

    static function templateDir(): ?string {
        $base = method_exists('Plugin', 'getPhpDir') ? Plugin::getPhpDir('kanpro') : (defined('GLPI_ROOT') ? GLPI_ROOT . '/plugins/kanpro' : null);
        if (!$base) return null;
        $dir = $base . '/templates_email';
        return is_dir($dir) ? $dir : null;
    }

    /** Rótulo amigável do status final da máquina */
    static function statusLabel($st): string {
        $s = mb_strtolower(trim((string)$st), 'UTF-8');
        $map = [
            'garantia'   => 'Garantia',
            'ok'         => 'OK',
            'inservivel' => 'Inservível',
            'inservível' => 'Inservível',
            'pendente'   => 'Pendente',
            'pending'    => 'Pendente',
            ''           => 'Sem status',
        ];
        return $map[$s] ?? (string)$st;
    }

    /** <ul> com as máquinas do card: "#seq — modelo (Status)" (já escapado) */
    static function machinesListHtml(int $cards_id): string {
        global $DB;
        $items = [];
        try {
            if (!$DB->tableExists('glpi_plugin_kanpro_maintenance_machines')) return '<em>Nenhuma máquina vinculada.</em>';
            $iter = $DB->request([
                'FROM'  => 'glpi_plugin_kanpro_maintenance_machines',
                'WHERE' => ['plugin_kanpro_cards_id' => $cards_id],
                'ORDER' => 'seq ASC, id ASC',
            ]);
            foreach ($iter as $m) {
                $seq   = (int)($m['seq'] ?? 0);
                $model = htmlspecialchars((string)($m['model'] ?? ('Máquina #' . $seq)), ENT_QUOTES, 'UTF-8');
                $st    = htmlspecialchars(self::statusLabel($m['status'] ?? ''), ENT_QUOTES, 'UTF-8');
                $items[] = "<li>#{$seq} &mdash; {$model} ({$st})</li>";
            }
        } catch (Throwable $e) {
            return '<em>Não foi possível listar as máquinas.</em>';
        }
        if (empty($items)) return '<em>Nenhuma máquina vinculada.</em>';
        return '<ul style="margin:6px 0;padding-left:20px;">' . implode('', $items) . '</ul>';
    }

    static function machinesCount(int $cards_id): int {
        global $DB;
        try {
            if (!$DB->tableExists('glpi_plugin_kanpro_maintenance_machines')) return 0;
            return countElementsInTable('glpi_plugin_kanpro_maintenance_machines', ['plugin_kanpro_cards_id' => $cards_id]);
        } catch (Throwable $e) { return 0; }
    }

    /**
     * Dados base a partir do card (a escola = nome do card na manutenção).
     * Completa com: escola, card_id, card_nome, data_recebimento (maintenance_date),
     * quantidade, maquinas (HTML). O chamador adiciona o resto (dias, motivo...).
     */
    static function baseData(int $cards_id): array {
        $card = new PluginKanproCard();
        $escola = '';
        $nome = '';
        $receb = '';
        if ($card->getFromDB($cards_id)) {
            $escola = (string)($card->fields['name'] ?? '');
            $nome   = $escola;
            $receb  = (string)($card->fields['maintenance_date'] ?? $card->fields['date_creation'] ?? '');
            if ($receb !== '') {
                try {
                    $dt = new DateTime($receb);
                    $receb = $dt->format('d/m/Y H:i');
                } catch (Throwable $e) {}
            }
        }
        return [
            'escola'           => $escola,
            'card_id'          => (string)$cards_id,
            'card_nome'        => $nome,
            'data_recebimento' => $receb,
            'data_pronto'      => date('d/m/Y H:i'),
            'data_cancelamento'=> date('d/m/Y H:i'),
            'quantidade'       => (string)self::machinesCount($cards_id),
            'maquinas'         => self::machinesListHtml($cards_id),
            'observacao'       => '',
            'motivo'           => '',
            'dias'             => '',
        ];
    }

    static function subject(string $type, array $data): string {
        $esc = (string)($data['escola'] ?? '');
        $cid = (string)($data['card_id'] ?? '');
        switch ($type) {
            case 'entrada':   return "[KanPro] Máquinas recebidas — {$esc} (card #{$cid})";
            case 'retirada':  return "[KanPro] Máquinas prontas para retirada — {$esc} (card #{$cid})";
            case 'atraso':    return "[KanPro] Há " . ($data['dias'] ?? '?') . " dias aguardando retirada — {$esc} (card #{$cid})";
            case 'cancelado': return "[KanPro] DESCONSIDERE o recebimento — {$esc} (card #{$cid})";
            default:          return "[KanPro] Manutenção — {$esc} (card #{$cid})";
        }
    }

    /** Renderiza o template trocando {chave} pelos dados (tudo escapado, menos {maquinas}) */
    static function render(string $type, array $data): ?string {
        if (!in_array($type, self::allowedTypes(), true)) return null;
        $dir = self::templateDir();
        if (!$dir) return null;
        $file = $dir . '/' . $type . '.html';
        if (!is_file($file)) return null;
        $html = file_get_contents($file);
        if ($html === false) return null;
        $rawKeys = ['maquinas']; // HTML pré-montado pelo helper
        foreach ($data as $k => $v) {
            $k = preg_replace('/[^a-z_]/', '', (string)$k);
            if ($k === '') continue;
            $rep = in_array($k, $rawKeys, true)
                ? (string)$v
                : htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
            $html = str_replace('{' . $k . '}', $rep, $html);
        }
        return $html;
    }
}
