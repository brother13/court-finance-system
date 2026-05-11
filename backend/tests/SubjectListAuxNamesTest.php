<?php

$subject = file_get_contents(__DIR__ . '/../app/finance/model/Subject.php');
$types = file_get_contents(__DIR__ . '/../../frontend/src/types/api.ts');

$checks = [
    [$subject, 'appendSubjectAuxNames', 'Subject list should append auxiliary accounting item names'],
    [$subject, "'aux_names'", 'Subject list should return aux_names'],
    [$subject, 'fin_subject_aux_config', 'Subject list should read subject auxiliary config'],
    [$subject, 'fin_aux_type', 'Subject list should read auxiliary type names'],
    [$types, 'aux_names?: string[]', 'Subject type should include aux_names'],
];

foreach ($checks as [$haystack, $needle, $message]) {
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

echo 'Subject list aux names test passed' . PHP_EOL;
