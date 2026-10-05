<?php
/**
 * ci/lint.php — php -l em todos os .php do plugin (portátil: Win/Linux).
 * Exit 1 se qualquer arquivo tiver erro de sintaxe.
 */
if (php_sapi_name() !== 'cli') {
    die("CLI only\n");
}
$root = dirname(__DIR__);
$fail = 0;
$checked = 0;
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
foreach ($it as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    if (strpos($file->getPathname(), DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR) !== false) {
        continue;
    }
    $checked++;
    $out = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        $fail++;
        echo implode("\n", $out) . "\n";
    }
}
echo $fail === 0 ? "lint ok ({$checked} arquivos)\n" : "lint: {$fail} arquivo(s) com erro\n";
exit($fail === 0 ? 0 : 1);
