<?php
/**
 * tests/stubs.php — stubs mínimos do GLPI p/ análise estática e testes.
 *
 * O núcleo do GLPI não existe neste repo; os stubs dão ao PHPStan os
 * símbolos-pai necessários na descoberta (scan) das classes do plugin.
 * NUNCA carregar em produção (lá as classes reais existem).
 */
if (!class_exists('CommonDBTM')) {
    eval('class CommonDBTM { public static $rightname = ""; }');
}
