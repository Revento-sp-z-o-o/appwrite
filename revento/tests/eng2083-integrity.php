<?php

// Build contract: the event optimization must not alter writes, rollback, or dispatch.
$root = $argv[1] ?? '/usr/src/code';
$manifest = json_decode(file_get_contents(__DIR__ . '/../manifest.json'), true, flags: JSON_THROW_ON_ERROR);
$patch = array_values(array_filter($manifest['patches'], fn ($item) => $item['name'] === 'transaction-event-reads'))[0];
$source = file_get_contents($root . '/' . $patch['target']);
if (hash('sha256', $source) !== $patch['after_sha256']) {
    throw new RuntimeException('ENG-2083 action source is not the pinned candidate');
}
$prefix = explode('            $dbCache = [];', $source, 2)[0];
$suffix = explode('                $eventString = ', $source, 2)[1] ?? '';
if (hash('sha256', $prefix) !== 'bf37daac78b2f83d3ba2a607dfbc5732fd49eb5b35a80ccff140823c3a229616' || hash('sha256', $suffix) !== 'ae2a4d09a5cd8d853fcd1cd11ea994b0ccd8907eee16a506545564c02e9bc2f4') {
    throw new RuntimeException('ENG-2083 changed the write phase or event dispatch/rollback contract');
}
echo "ENG-2083 unchanged write phase, event dispatch and rollback verified\n";
