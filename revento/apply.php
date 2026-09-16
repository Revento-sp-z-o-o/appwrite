<?php

// Build-time only. Refuse a different base or modified patch instead of applying
// a context match to an unreviewed upstream version.
$root = realpath($argv[1] ?? '/usr/src/code');
if ($root === false) {
    throw new RuntimeException('Source root does not exist');
}
$manifest = json_decode(file_get_contents(__DIR__ . '/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
foreach ($manifest['patches'] as $patch) {
    $target = $root . '/' . $patch['target'];
    $file = __DIR__ . '/patches/' . $patch['file'];
    if (hash_file('sha256', $target) !== $patch['before_sha256'] || hash_file('sha256', $file) !== $patch['patch_sha256']) {
        throw new RuntimeException('Unreviewed source or patch: ' . $patch['name']);
    }
}
foreach ($manifest['patches'] as $patch) {
    $process = proc_open(['patch', '-p1', '--batch', '--fuzz=0'], [
        0 => ['file', __DIR__ . '/patches/' . $patch['file'], 'r'],
        1 => STDOUT,
        2 => STDERR,
    ], $pipes, $root);
    if (!is_resource($process) || proc_close($process) !== 0) {
        throw new RuntimeException('Patch failed: ' . $patch['name']);
    }
    if (hash_file('sha256', $root . '/' . $patch['target']) !== $patch['after_sha256']) {
        throw new RuntimeException('Patched source differs: ' . $patch['name']);
    }
    echo 'Verified ' . $patch['name'] . "\n";
}
