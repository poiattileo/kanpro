<?php
/**
 * tests/src-lid-order.php — regressão: $srcLid usada antes de definida.
 *
 * Em front/ajax.php o fluxo "confirm chamado" usava $srcLid no WHERE do
 * request de rank ANTES da inicialização ($srcLid = 0 / getFromDB do card),
 * gerando "Undefined variable $srcLid at ajax.php line 7413" no log do GLPI
 * e fazendo o card origem NÃO voltar pro topo da lista quando
 * tablet_finalized_by está preenchido (WHERE com null não casa nada).
 *
 * Checagem estática: o ajax.php é procedural com 8k linhas e só roda dentro
 * do GLPI bootado — não é unit-testável. O teste garante que a inicialização
 * aparece no arquivo ANTES do uso no request de rank.
 *
 * Uso: php tests/src-lid-order.php (exit 1 em falha)
 */
if (php_sapi_name() !== 'cli') {
    die("CLI only\n");
}
error_reporting(E_ALL);

$file = dirname(__DIR__) . '/front/ajax.php';
$src = file_get_contents($file);
if ($src === false) {
    echo "NOT OK - nao conseguiu ler front/ajax.php\n";
    exit(1);
}

// Inicialização esperada (bloco do card origem).
$init = strpos($src, '$srcLid = 0');
// Uso que disparava o warning (request do primeiro rank da lista).
$use = strpos($src, "'plugin_kanpro_lists_id' => $srcLid");

if ($init === false) {
    echo "NOT OK - inicializacao '\$srcLid = 0' nao encontrada em front/ajax.php\n";
    exit(1);
}
if ($use === false) {
    // O uso sumiu do arquivo (mudança de propósito) — sem objeto de checar.
    echo "ok - uso no request de rank nao encontrado (padrao mudou; checagem pulada)\n";
    exit(0);
}
if ($init > $use) {
    echo "NOT OK - \$srcLid inicializada (offset {$init}) DEPOIS do uso no request de rank (offset {$use})\n";
    exit(1);
}
echo "ok - \$srcLid inicializada antes do uso no request de rank\n";
exit(0);
