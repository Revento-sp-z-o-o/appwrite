<?php

$source = getenv('REVENTO_DATABASE_SOURCE');
if (!$source || !is_file($source)) {
    throw new RuntimeException('Original Utopia Database.php path required');
}
$maintenance = dirname(__DIR__);
$appwrite = getenv('REVENTO_APPWRITE_SOURCE') ?: dirname($maintenance);
$manifest = json_decode(file_get_contents($maintenance . '/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
if (hash_file('sha256', $source) !== $manifest['patches'][1]['before_sha256']) {
    throw new RuntimeException('Original Utopia source does not match manifest');
}
$temporary = sys_get_temp_dir() . '/revento-patch-test-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
$checks = [];
try {
    $scenarios = ['valid' => null];
    foreach ($manifest['patches'] as $index => $patch) {
        $scenarios['changed-' . $patch['name']] = $index;
    }
    foreach ($scenarios as $scenario => $changedIndex) {
        $root = $temporary . '/' . $scenario;
        foreach ($manifest['patches'] as $patch) {
            $target = $root . '/' . $patch['target'];
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0700, true);
            }
            $original = $patch['name'] === 'relationship-lookups' ? $source : $appwrite . '/' . $patch['target'];
            copy($original, $target);
        }
        if ($changedIndex !== null) {
            file_put_contents($root . '/' . $manifest['patches'][$changedIndex]['target'], "\n// unreviewed change\n", FILE_APPEND);
        }
        $before = [];
        foreach ($manifest['patches'] as $patch) {
            $before[$patch['target']] = hash_file('sha256', $root . '/' . $patch['target']);
        }
        $process = proc_open([PHP_BINARY, $maintenance . '/apply.php', $root], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $root . '/output.log', 'w'], 2 => ['file', $root . '/error.log', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Installer process could not start');
        }
        $code = proc_close($process);
        if (($code === 0) !== ($scenario === 'valid')) {
            throw new RuntimeException('Unexpected installer result: ' . $scenario);
        }
        foreach ($manifest['patches'] as $patch) {
            $expected = $scenario === 'valid' ? $patch['after_sha256'] : $before[$patch['target']];
            if (hash_file('sha256', $root . '/' . $patch['target']) !== $expected) {
                throw new RuntimeException('Installer changed unexpected bytes: ' . $scenario);
            }
        }
        $checks[] = $scenario;
    }
} finally {
    // This directory was created exclusively by this process; never traverse links.
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($temporary);
}
echo json_encode(['passed' => true, 'checks' => $checks]) . "\n";
