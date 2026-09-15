<?php

// Build contract: preserve rollback and dispatch; permit only declared ENG-2084 read changes.
$root = $argv[1] ?? '/usr/src/code';
$manifest = json_decode(file_get_contents(__DIR__ . '/../manifest.json'), true, flags: JSON_THROW_ON_ERROR);
$patch = array_values(array_filter($manifest['patches'], fn ($item) => $item['name'] === 'transaction-event-reads'))[0];
$source = file_get_contents($root . '/' . $patch['target']);
if (hash('sha256', $source) !== $patch['after_sha256']) {
    throw new RuntimeException('ENG-2083 action source is not the pinned candidate');
}
// ENG-2084 deliberately changes only the guarded old-row read arguments.
// Reverse those exact reviewed additions before checking the ENG-2083 boundary.
$deltas = json_decode(file_get_contents(__DIR__ . '/eng2084-update-read-delta.json'), true, flags: JSON_THROW_ON_ERROR);
foreach (array_reverse($deltas) as $delta) {
    if (substr_count($source, $delta['after']) !== 1) {
        throw new RuntimeException('ENG-2084 read boundary does not match');
    }
    $source = str_replace($delta['after'], $delta['before'], $source);
}
if (hash('sha256', $source) !== '9fdd3cee6d0dbfc17d1aad91d1d5cbe2c43c5b0433198dfaed2da155e40b5b12') {
    throw new RuntimeException('ENG-2084 changed code outside the declared read boundary');
}
$prefix = explode('            $dbCache = [];', $source, 2)[0];
$suffix = explode('                $eventString = ', $source, 2)[1] ?? '';
if (hash('sha256', $prefix) !== 'bf37daac78b2f83d3ba2a607dfbc5732fd49eb5b35a80ccff140823c3a229616' || hash('sha256', $suffix) !== 'ae2a4d09a5cd8d853fcd1cd11ea994b0ccd8907eee16a506545564c02e9bc2f4') {
    throw new RuntimeException('ENG-2083 changed the write phase or event dispatch/rollback contract');
}
echo "ENG-2083 boundary verified after reversing declared ENG-2084 read changes\n";
