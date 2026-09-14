<?php
header('Content-Type: text/plain');
$r = @file_put_contents('/tmp/kanpro_kanban_top.log', "PHP TEST WRITE ".date('c')."\n", FILE_APPEND);
echo "resultado file_put_contents: ";
var_dump($r);
echo "ultimo erro php: ";
var_dump(error_get_last());
echo "usuario rodando o php: ";
echo exec('whoami');
echo "\n";
echo "consegue escrever em /tmp direto?: ";
var_dump(is_writable('/tmp'));
