<?php

use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Postgres;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\PDO;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;

$variant = $argv[1] ?? '';
if (!in_array($variant, ['original', 'candidate'], true)) {
    throw new RuntimeException('Explicit variant required');
}
$autoload = getenv('REVENTO_DATABASE_AUTOLOAD');
$source = getenv('REVENTO_DATABASE_SOURCE');
if (!$autoload || !$source || !str_starts_with(getenv('RELATION_PG_DSN') ?: '', 'pgsql:host=127.0.0.1;')) {
    throw new RuntimeException('Explicit autoloader, source and isolated loopback PostgreSQL required');
}
$manifest = json_decode(file_get_contents(__DIR__ . '/../manifest.json'), true, flags: JSON_THROW_ON_ERROR);
$expectedHash = $manifest['patches'][1]['after_sha256'];
if (hash_file('sha256', $source) !== $expectedHash) {
    throw new RuntimeException('Test source does not match pinned variant');
}
require $autoload;
require $source;

class CountingMemory extends Memory
{
    public int $loads = 0;

    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        $this->loads++;
        return parent::load($key, $ttl, $hash);
    }
}

class CountingRedis extends \Utopia\Cache\Adapter\Redis
{
    public int $loads = 0;

    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        $this->loads++;
        return parent::load($key, $ttl, $hash);
    }
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO(getenv('RELATION_PG_DSN'), getenv('RELATION_PG_USER'), getenv('RELATION_PG_PASSWORD'), Postgres::getPDOAttributes());
// Only schema and collation bootstrap are supplied locally. The unmodified
// PostgreSQL adapter performs every collection, relationship and document operation.
// Vector/spatial extensions are not needed by this relationship-only fixture.
$cacheMode = getenv('RELATION_CACHE') ?: 'memory';
check(in_array($cacheMode, ['memory', 'redis'], true), 'Unsupported cache mode');
$schema = 'eng2083_' . $variant . '_' . $cacheMode;
$pdo->exec('CREATE SCHEMA "' . $schema . '"');
$pdo->exec("CREATE COLLATION IF NOT EXISTS utf8_ci_ai (provider=icu, locale='und-u-ks-level1', deterministic=false)");
$cache = new CountingMemory();
if ($cacheMode === 'redis') {
    $redis = new \Redis();
    $redis->connect('127.0.0.1', 6379, 2);
    $cache = new CountingRedis($redis);
}
$authorization = new Authorization();
$database = new Database(new Postgres($pdo), new Cache($cache));
$database->setDatabase($schema)->setNamespace('probe_' . $variant . '_' . $cacheMode)->setAuthorization($authorization);
$database->create();
$permissions = [Permission::read(Role::user('owner')), Permission::create(Role::user('owner')), Permission::update(Role::user('owner')), Permission::delete(Role::user('owner'))];
$authorization->addRole(Role::user('owner')->toString());


$transactionSource = getenv('REVENTO_TRANSACTION_SOURCE');
$transactionPatch = array_values(array_filter($manifest['patches'], fn ($patch) => $patch['name'] === 'transaction-event-documents'))[0];
check($transactionSource && hash_file('sha256', $transactionSource) === $transactionPatch[$variant === 'original' ? 'before_sha256' : 'after_sha256'], 'Unpinned transaction source');
require $transactionSource;
if (!defined('APP_DATABASES_SUBQUERIES')) {
    define('APP_DATABASES_SUBQUERIES', ['subQueryPolicies', 'subQueryArchives']);
}

$database->createCollection('databases', permissions: [], documentSecurity: false);
$database->createAttribute('databases', 'label', Database::VAR_STRING, 128, false);
$metadata = $authorization->skip(function () use ($database): array {
    return [
        $database->createDocument('databases', new Document(['$id' => 'first', 'label' => 'first'])),
        $database->createDocument('databases', new Document(['$id' => 'second', 'label' => 'second'])),
    ];
});
$first = $metadata[0]->getSequence();
$second = $metadata[1]->getSequence();
$drafts = "database_{$first}_collection_11";
$mirrors = "database_{$first}_collection_12";
$other = "database_{$second}_collection_11";
$writeOnly = "database_{$first}_collection_13";
foreach ([$drafts, $mirrors, $other, 'types', 'threads', 'locations'] as $collection) {
    $database->createCollection($collection, permissions: [], documentSecurity: true);
    $database->createAttribute($collection, 'label', Database::VAR_STRING, 128, false);
}
$database->createCollection($writeOnly, permissions: [Permission::update(Role::user('owner'))], documentSecurity: false);
$database->createAttribute($writeOnly, 'label', Database::VAR_STRING, 128, false);
$database->createRelationship('types', 'threads', Database::RELATION_MANY_TO_ONE, true, 'default_thread', 'types');
$database->createRelationship('locations', 'threads', Database::RELATION_MANY_TO_ONE, true, 'thread', 'locations');
foreach (['types' => 'type_definition', 'threads' => 'thread', 'locations' => 'location'] as $target => $key) {
    $database->createRelationship($drafts, $target, Database::RELATION_MANY_TO_ONE, true, $key, 'activities');
    $database->createRelationship($mirrors, $target, Database::RELATION_MANY_TO_ONE, false, $key, 'published_rows');
}
$authorization->skip(function () use ($database, $permissions, $drafts, $mirrors, $other, $writeOnly): void {
    $database->createDocument('threads', new Document(['$id' => 'shared', '$permissions' => $permissions, 'label' => 'thread']));
    $database->createDocument('types', new Document(['$id' => 'shared', '$permissions' => $permissions, 'label' => 'type', 'default_thread' => 'shared']));
    $database->createDocument('locations', new Document(['$id' => 'shared', '$permissions' => $permissions, 'label' => 'location', 'thread' => 'shared']));
    for ($i = 0; $i < 150; $i++) {
        foreach ([$drafts, $mirrors] as $collection) {
            $database->createDocument($collection, new Document([
                '$id' => 'r' . $i, '$permissions' => $permissions, 'label' => 'final' . $i,
                'type_definition' => 'shared', 'thread' => 'shared', 'location' => 'shared',
            ]));
        }
    }
    $database->createDocument($other, new Document(['$id' => 'r0', '$permissions' => $permissions, 'label' => 'other database']));
    $database->createDocument($writeOnly, new Document(['$id' => 'r0', 'label' => 'before']));
});
$state = new \Appwrite\Databases\TransactionState($database, $authorization, function (Document $metadata) use ($database): Database {
    check(in_array($metadata->getId(), ['first', 'second'], true), 'Wrong database metadata');
    return $database;
});
function operation(string $databaseId, int $collectionId, string $action, ?string $id, array|Document $data = []): array
{
    return ['databaseInternalId' => $databaseId, 'collectionInternalId' => $collectionId, 'action' => $action, 'documentId' => $id, 'data' => $data];
}
// Independent oracle: upstream's final per-operation getDocument behavior.
function individual(array $operations): array
{
    global $database;
    $out = [];
    foreach ($operations as $operation) {
        $data = $operation['data'] instanceof Document ? $operation['data']->getArrayCopy() : $operation['data'];
        switch ($operation['action']) {
            case 'create':
            case 'upsert':
                $id = $operation['documentId'] ?? $data['$id'] ?? null;
                break;
            case 'update':
            case 'increment':
            case 'decrement':
                $id = $operation['documentId'];
                break;
            default:
                continue 2;
        }
        if (!$id) {
            continue;
        }
        $collection = "database_{$operation['databaseInternalId']}_collection_{$operation['collectionInternalId']}";
        $doc = $database->getDocument($collection, $id);
        if (!$doc->isEmpty()) {
            $out[$collection][$doc->getId()] = $doc;
        }
    }
    return $out;
}
function batch(array $operations): array
{
    global $variant, $state;
    return $variant === 'original' ? individual($operations) : $state->getCommittedDocuments($operations);
}
function canonical(mixed $value): mixed
{
    if ($value instanceof Document) {
        $value = $value->getArrayCopy();
    }
    if (is_array($value)) {
        $value = array_map('canonical', $value);
        if (!array_is_list($value)) {
            ksort($value);
        }
    }
    return $value;
}
function fingerprints(array $documents): array
{
    $out = [];
    foreach ($documents as $collection => $rows) {
        foreach ($rows as $id => $doc) {
            $out[$collection][$id] = hash('sha256', json_encode(canonical($doc), JSON_THROW_ON_ERROR));
        }
    }
    return canonical($out);
}
$operations = [];
for ($i = 0; $i < 150; $i++) {
    $operations[] = operation($first, 11, 'update', 'r' . $i);
    $operations[] = operation($first, 12, 'create', 'r' . $i);
}
$checks = [];
$metrics = ['seconds' => 0, 'cache_loads' => 0, 'operations' => count($operations)];
foreach (array_chunk($operations, 100) as $chunk) {
    $expected = fingerprints(individual($chunk));
    $cache->loads = 0;
    $start = hrtime(true);
    $actual = batch($chunk);
    $metrics['seconds'] += (hrtime(true) - $start) / 1e9;
    $metrics['cache_loads'] += $cache->loads;
    check(fingerprints($actual) === $expected, 'ENG-2083 final payloads differ, including relationship array ordering');
    unset($actual);
}
$checks[] = '300 final payloads, all nested values and relationship order';
foreach ([0, 1, 25, 99, 100, 101] as $size) {
    foreach (array_chunk(array_slice($operations, 0, $size), 100) as $chunk) {
        check(fingerprints(batch($chunk)) === fingerprints(individual($chunk)), 'Batch boundary ' . $size);
    }
}
check(batch([]) === [], 'Empty batch queried documents');
if ($variant === 'candidate') {
    try {
        batch(array_slice($operations, 0, 101));
        throw new RuntimeException('Unbounded input accepted');
    } catch (\InvalidArgumentException $expected) {
    }
}
$checks[] = 'empty/1/25/99/100/101 boundaries and hard bound';
$mixed = [
    operation($first, 11, 'create', null, new Document(['$id' => 'r0'])),
    operation($second, 11, 'update', 'r0'),
    operation($first, 12, 'upsert', null, ['$id' => 'r0']),
    operation($first, 11, 'increment', 'r0'),
    operation($first, 11, 'decrement', 'r0'),
    operation($first, 11, 'delete', 'r0', ['label' => 'staged snapshot']),
    operation($first, 11, 'create', 'missing'),
    operation($first, 11, 'bulkCreate', null, [['$id' => 'r1']]),
    operation($first, 11, 'bulkUpdate', null, []),
    operation($first, 11, 'bulkUpsert', null, []),
    operation($first, 11, 'bulkDelete', null, []),
    operation($first, 11, 'update', null),
];
check(fingerprints(batch($mixed)) === fingerprints(individual($mixed)), 'Mixed operations or namespaces differ');
$cache->loads = 0;
check(batch([operation($first, 11, 'delete', 'r0', ['label' => 'snapshot'])]) === [], 'Delete read final state');
check($cache->loads === 0, 'Delete-only batch performed a read');
$checks[] = 'mixed logical databases/collections, duplicate IDs, missing rows, ID fallback, deletes/bulk excluded';
$derived = batch([operation($first, 12, 'update', 'r0')]);
$before = fingerprints($derived);
$payload = $derived[$mirrors]['r0']->getArrayCopy();
$payload['thread']['label'] = 'mutated derived payload';
check(fingerprints($derived) === $before, 'Derived event payload changed shared document');
$derived[$mirrors]['r0']->getAttribute('thread')->setAttribute('label', 'mutated returned document');
$reread = batch([operation($first, 12, 'update', 'r0')]);
check(fingerprints($reread) === $before, 'Returned document mutation changed a subsequent read');
$checks[] = 'derived event payload mutation isolation';

$database->updateDocument($writeOnly, 'r0', new Document(['label' => 'committed write']));
check($database->getDocument($writeOnly, 'r0')->isEmpty(), 'Write-only fixture unexpectedly readable');
check(batch([operation($first, 13, 'update', 'r0')]) === [], 'Write-only caller failed final read');
$authorization->skip(function () use ($database, $writeOnly): void {
    check($database->getDocument($writeOnly, 'r0')->getAttribute('label') === 'committed write', 'Authorized write missing');
});
$checks[] = 'write-only collection preserves successful write and empty final read';

$database->updateDocument($mirrors, 'r0', new Document(['$permissions' => [...$permissions, Permission::read(Role::any())]]));
$authorization->removeRole(Role::user('owner')->toString());
$authorization->addRole(Role::user('outsider')->toString());
$outsider = [operation($first, 12, 'update', 'r0'), operation($first, 12, 'update', 'r1'), operation($second, 11, 'update', 'r0')];
$actual = batch($outsider);
check(fingerprints($actual) === fingerprints(individual($outsider)), 'Outsider payload differs');
check(count($actual) === 1 && count($actual[$mirrors]) === 1, 'Private root exposed');
foreach (['thread', 'type_definition', 'location'] as $key) {
    check($actual[$mirrors]['r0']->getAttribute($key)->isEmpty(), 'Private related parent exposed');
}
$authorization->removeRole(Role::user('outsider')->toString());
check(fingerprints(batch($outsider)) === fingerprints($actual), 'Anonymous visibility differs');
$authorization->addRole(Role::user('owner')->toString());
$checks[] = 'warmed owner cache to outsider/anonymous filters roots and related parents';

try {
    $database->withTransaction(function () use ($database, $drafts): void {
        $database->updateDocument($drafts, 'r0', new Document(['label' => 'rolledback']));
        throw new RuntimeException('deliberate rollback');
    });
} catch (RuntimeException $error) {
    check($error->getMessage() === 'deliberate rollback', 'Unexpected write failure');
}
$rolledBack = batch([operation($first, 11, 'update', 'r0')]);
check($rolledBack[$drafts]['r0']->getAttribute('label') === 'final0', 'Read uncommitted/rolled-back state');
$database->updateDocument($drafts, 'r0', new Document(['label' => 'concurrent change']));
$fresh = batch([operation($first, 11, 'update', 'r0')]);
check($fresh[$drafts]['r0']->getAttribute('label') === 'concurrent change', 'Retained snapshot across batches');
$checks[] = 'rollback durable values and fresh reads between batches';

check($variant === 'original' || $metrics['cache_loads'] < 1000, 'ENG-2083 batched read cost exceeds budget');
echo json_encode(['passed' => true, 'variant' => $variant, 'cache' => $cacheMode, 'metrics' => $metrics, 'checks' => $checks, 'scope' => 'Actual TransactionState/Utopia/PostgreSQL library; HTTP and queue integration require separate API acceptance'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
