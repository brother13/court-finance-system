<?php

$root = realpath(__DIR__ . '/../..');
$reserved = [
    'con', 'prn', 'aux', 'nul',
    'com1', 'com2', 'com3', 'com4', 'com5', 'com6', 'com7', 'com8', 'com9',
    'lpt1', 'lpt2', 'lpt3', 'lpt4', 'lpt5', 'lpt6', 'lpt7', 'lpt8', 'lpt9',
];
$skipDirs = ['.git', 'node_modules', 'dist', 'runtime', 'releases'];
$failures = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        function ($file) use ($skipDirs) {
            if (!$file->isDir()) {
                return true;
            }
            return !in_array($file->getFilename(), $skipDirs, true);
        }
    )
);

foreach ($iterator as $file) {
    $filename = strtolower($file->getFilename());
    $base = strtolower(pathinfo($filename, PATHINFO_FILENAME));
    if (in_array($base, $reserved, true)) {
        $failures[] = str_replace($root . DIRECTORY_SEPARATOR, '', $file->getPathname());
    }
}

if (!empty($failures)) {
    fwrite(STDERR, 'Windows reserved filenames are not allowed:' . PHP_EOL);
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'Windows reserved filename test passed' . PHP_EOL;
