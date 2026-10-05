<?php
/**
 * ci/check-secrets.php — trava contra e-mail institucional hardcoded.
 *
 * Varre código + JS + templates atrás de @*.gov.br e falha se achar
 * qualquer ocorrência SEM o marcador LEGACY-SEED-ALLOW na mesma linha.
 *
 * Pontos permitidos (migração do comportamento anterior, nunca lógica nova):
 *   - hook.php: seeds da tabela glpi_plugin_kanpro_configs
 *   - inc/acting.php: fallback legado KANPRO_ACTING_MAP
 */
if (php_sapi_name() !== 'cli') {
    die("CLI only\n");
}
$root = dirname(__DIR__);
$scanDirs = ['inc', 'front', 'public', 'locales'];
$scanFiles = ['hook.php', 'setup.php'];
$extensions = ['php' => true, 'js' => true, 'txt' => true, 'html' => true];

$targets = [];
foreach ($scanDirs as $dir) {
    $path = $root . DIRECTORY_SEPARATOR . $dir;
    if (!is_dir($path)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if ($file->isFile() && isset($extensions[$file->getExtension()])) {
            $targets[] = $file->getPathname();
        }
    }
}
foreach ($scanFiles as $f) {
    $path = $root . DIRECTORY_SEPARATOR . $f;
    if (is_file($path)) {
        $targets[] = $path;
    }
}

$violations = [];
foreach ($targets as $target) {
    $lines = @file($target, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        continue;
    }
    foreach ($lines as $i => $line) {
        if (preg_match('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]*gov\.br/i', $line)
            && strpos($line, 'LEGACY-SEED-ALLOW') === false
        ) {
            $rel = substr($target, strlen($root) + 1);
            $violations[] = "{$rel}:" . ($i + 1) . ': ' . trim($line);
        }
    }
}

if ($violations !== []) {
    echo "E-MAIL INSTITUCIONAL HARDCODED (mova p/ Configurações do KanPro):\n";
    echo implode("\n", $violations) . "\n";
    exit(1);
}
echo 'secrets ok (' . count($targets) . " arquivos varridos)\n";
exit(0);
