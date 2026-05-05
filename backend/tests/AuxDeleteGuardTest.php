<?php

$auxPath = __DIR__ . '/../app/finance/model/Aux.php';
$source = file_get_contents($auxPath);

if (strpos($source, "case 'typeDel':") === false) {
    fwrite(STDERR, 'Expected auxiliary type delete action to be routed' . PHP_EOL);
    exit(1);
}

if (strpos($source, '标准辅助维度不允许删除') === false) {
    fwrite(STDERR, 'Expected standard auxiliary dimensions to be protected from delete' . PHP_EOL);
    exit(1);
}

if (strpos($source, '辅助维度已被科目或凭证使用，不允许删除') === false) {
    fwrite(STDERR, 'Expected used auxiliary dimensions to be protected from delete' . PHP_EOL);
    exit(1);
}

if (strpos($source, "case 'archiveDel':") === false) {
    fwrite(STDERR, 'Expected auxiliary archive delete action to be routed' . PHP_EOL);
    exit(1);
}

if (strpos($source, '辅助档案已被期初或凭证使用，不允许删除') === false) {
    fwrite(STDERR, 'Expected used auxiliary archives to be protected from delete' . PHP_EOL);
    exit(1);
}

if (strpos($source, "'del_flag' => 1") === false) {
    fwrite(STDERR, 'Expected auxiliary delete to soft-delete records' . PHP_EOL);
    exit(1);
}

echo 'Auxiliary delete guard test passed' . PHP_EOL;
