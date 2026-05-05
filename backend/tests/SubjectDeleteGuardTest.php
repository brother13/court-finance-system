<?php

$subjectPath = __DIR__ . '/../app/finance/model/Subject.php';
$source = file_get_contents($subjectPath);

if (strpos($source, "parent_code'] = \$before['subject_code']") === false) {
    fwrite(STDERR, 'Expected subject delete to check child subjects by parent_code' . PHP_EOL);
    exit(1);
}

if (strpos($source, '科目存在下级科目，不允许删除') === false) {
    fwrite(STDERR, 'Expected subject delete to reject subjects that have children' . PHP_EOL);
    exit(1);
}

echo 'Subject delete guard test passed' . PHP_EOL;

