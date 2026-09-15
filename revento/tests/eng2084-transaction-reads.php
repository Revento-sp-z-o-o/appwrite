<?php

use Appwrite\Databases\TransactionState;
use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Pool as DatabasePool;
use Utopia\Database\Adapter\Postgres;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Operator;
use Utopia\Database\PDO;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Pool;

// Real-library regression, not a unit test of the HTTP actions. Original mode
// exercises the deployed reads and must fail the same final amplification gate.
$variant = $argv[1] ?? '';
function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
check(in_array($variant, ['original', 'candidate'], true), 'Explicit variant required');
$costOnly = ($argv[2] ?? '') === '--cost-only';
$extrasOnly = ($argv[2] ?? '') === '--extras-only';
check(count($argv) === ($costOnly || $extrasOnly ? 3 : 2), 'Only --cost-only or --extras-only is supported');

function checkReadBudget(array $metrics): void
{
    check($metrics['staging']['related_rows'] === 0 && $metrics['updates']['related_rows'] <= 75000, 'ENG-2084 read amplification exceeds staging=0/update<=75000 related-row budget');
}
$source = getenv('REVENTO_DATABASE_SOURCE');
$autoload = getenv('REVENTO_DATABASE_AUTOLOAD');
$transactionSource = getenv('REVENTO_TRANSACTION_SOURCE');
check($source && $autoload && $transactionSource && str_starts_with(getenv('RELATION_PG_DSN') ?: '', 'pgsql:host=127.0.0.1;'), 'Pinned sources and isolated loopback PostgreSQL required');
$manifest = json_decode(file_get_contents(__DIR__ . '/../manifest.json'), true, flags: JSON_THROW_ON_ERROR);
$databaseHash = '517ff7043de41921f984b1efc42d3a15679ac3f86eca80535a57d45478fc0cde';
$transactionHash = '88430aa3db4605018de6721451e2346893aba1d80cd37b7def8dc17b8d45998e';
foreach ($manifest['patches'] as $patch) {
    if ($variant === 'candidate' && $patch['target'] === 'vendor/utopia-php/database/src/Database/Database.php') {
        $databaseHash = $patch['after_sha256'];
    }
    if ($variant === 'candidate' && $patch['target'] === 'src/Appwrite/Databases/TransactionState.php') {
        $transactionHash = $patch['after_sha256'];
    }
}
check(hash_file('sha256', $source) === $databaseHash, 'Unpinned database source');
check($transactionHash && hash_file('sha256', $transactionSource) === $transactionHash, 'Unpinned transaction source');
require $autoload;
require $source;
require $transactionSource;

class CountingMemory extends Memory
{
    public int $loads = 0;

    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        $this->loads++;
        return parent::load($key, $ttl, $hash);
    }
}

class MeasuredDatabase extends Database
{
    public int $relatedRows = 0;
    public array $measuredCollections = [];

    public function find(string $collection, array $queries = [], string $forPermission = Database::PERMISSION_READ): array
    {
        $rows = parent::find($collection, $queries, $forPermission);
        if (in_array($collection, $this->measuredCollections, true)) {
            $this->relatedRows += count($rows);
        }
        return $rows;
    }
}

function update(MeasuredDatabase $database, string $collection, string $id, array $data): Document
{
    global $variant;
    return $variant === 'original'
        ? $database->updateDocument($collection, $id, new Document($data))
        : $database->updateDocument($collection, $id, new Document($data), fetchOldRelationshipIdsOnly: true);
}

function identity(mixed $value): ?string
{
    return $value instanceof Document ? $value->getId() : $value;
}

function identities(array $documents): array
{
    $ids = array_map('identity', $documents);
    sort($ids);
    return $ids;
}

function checkAdditionalUpdates(MeasuredDatabase $database, Authorization $authorization, array $permissions): void
{
    foreach (['extra_records', 'extra_parents', 'extra_tags'] as $collection) {
        $database->createCollection($collection, permissions: [], documentSecurity: true);
        $database->createAttribute($collection, 'label', Database::VAR_STRING, 128, false);
    }
    $database->createRelationship('extra_records', 'extra_parents', Database::RELATION_MANY_TO_ONE, true, 'parent', 'records');
    $database->createRelationship('extra_records', 'extra_tags', Database::RELATION_MANY_TO_MANY, true, 'tags', 'records');
    $authorization->skip(function () use ($database, $permissions): void {
        $database->createDocument('extra_parents', new Document(['$id' => 'parent', '$permissions' => $permissions, 'label' => 'parent']));
        foreach (['a', 'b'] as $id) {
            $database->createDocument('extra_tags', new Document(['$id' => $id, '$permissions' => $permissions, 'label' => $id]));
        }
        $database->createDocument('extra_records', new Document(['$id' => 'record', '$permissions' => $permissions, 'label' => 'before', 'parent' => 'parent']));
    });

    // Both nested maps and lists of Documents must retain nested write behavior.
    update($database, 'extra_records', 'record', ['parent' => ['$id' => 'parent', 'label' => 'nested-parent'], 'tags' => [new Document(['$id' => 'a', 'label' => 'nested-tag'])]]);
    check($database->getDocument('extra_parents', 'parent')->getAttribute('label') === 'nested-parent', 'Nested map write lost');
    check($database->getDocument('extra_tags', 'a')->getAttribute('label') === 'nested-tag', 'Nested relation-list write lost');
    $appended = update($database, 'extra_records', 'record', ['tags' => Operator::arrayAppend(['b'])]);
    check(identities($appended->getAttribute('tags')) === ['a', 'b'], 'Relationship append return differs');
    check(identities($database->getDocument('extra_tags', 'b')->getAttribute('records')) === ['record'], 'Relationship append inverse missing');
    $removed = update($database, 'extra_records', 'record', ['tags' => Operator::arrayRemove('a')]);
    check(identities($removed->getAttribute('tags')) === ['b'], 'Relationship remove return differs');
    check($database->getDocument('extra_tags', 'a')->getAttribute('records') === [], 'Relationship remove inverse retained');

    // Mirror the library boundary used by a subsequent dependent upsert: the
    // transaction action saves the update's complete return, merges sparse data,
    // and passes that Document into upsertDocument in the same transaction.
    $database->withTransaction(function () use ($database): void {
        $returned = update($database, 'extra_records', 'record', ['label' => 'updated', 'parent' => 'parent', 'tags' => ['b']]);
        check($returned->getAttribute('parent')->getAttribute('label') === 'nested-parent', 'Optimized return lacks dependent-upsert state');
        $returned->setAttribute('label', 'dependent-upsert');
        $upserted = $database->upsertDocument('extra_records', $returned);
        // Pinned Utopia's dependent upsert repeats this many-to-many link.
        // Preserve its observed return and subsequent-read behavior here;
        // repairing the underlying upsert is separate from this read change.
        check($upserted->getAttribute('label') === 'dependent-upsert' && identity($upserted->getAttribute('parent')) === 'parent' && identities($upserted->getAttribute('tags')) === ['b', 'b'], 'Dependent upsert changed the pinned return graph');
    });
    $persisted = $database->getDocument('extra_records', 'record');
    check($persisted->getAttribute('label') === 'dependent-upsert', 'Dependent upsert did not persist');
    check(identity($persisted->getAttribute('parent')) === 'parent' && identities($persisted->getAttribute('tags')) === ['b', 'b'], 'Dependent upsert changed pinned subsequent-read relationships');
    try {
        $database->withRequestTimestamp(new \DateTime('2000-01-01T00:00:00.000+00:00'), fn () => update($database, 'extra_records', 'record', ['label' => 'stale']));
        throw new RuntimeException('Stale request update accepted');
    } catch (\Utopia\Database\Exception\Conflict $expected) {
    }
    check($database->getDocument('extra_records', 'record')->getAttribute('label') === 'dependent-upsert', 'Conflict left a partial write');
    check(update($database, 'extra_records', 'missing', ['label' => 'absent'])->isEmpty(), 'Missing update created a row');

    // update permission is sufficient for a real mutation; an unchanged write
    // uses the original read-permission branch and must remain denied.
    $database->createCollection('extra_writeonly', permissions: [Permission::update(Role::user('owner'))], documentSecurity: false);
    $database->createAttribute('extra_writeonly', 'label', Database::VAR_STRING, 128, false);
    $authorization->skip(fn () => $database->createDocument('extra_writeonly', new Document(['$id' => 'record', 'label' => 'before'])));
    check(update($database, 'extra_writeonly', 'record', ['label' => 'after'])->getAttribute('label') === 'after', 'Write-only mutation denied');
    try {
        update($database, 'extra_writeonly', 'record', ['label' => 'after']);
        throw new RuntimeException('Write-only no-op read-permission branch changed');
    } catch (\Utopia\Database\Exception\Authorization $expected) {
    }
    check($authorization->skip(fn () => $database->getDocument('extra_writeonly', 'record'))->getAttribute('label') === 'after', 'Write-only failure changed persisted state');
}

$schema = 'eng2084_' . $variant . '_' . bin2hex(random_bytes(4));
$pdo = new PDO(getenv('RELATION_PG_DSN'), getenv('RELATION_PG_USER'), getenv('RELATION_PG_PASSWORD'), Postgres::getPDOAttributes());
$pdo->exec('CREATE SCHEMA "' . $schema . '"');
$pdo->exec("CREATE COLLATION IF NOT EXISTS utf8_ci_ai (provider=icu, locale='und-u-ks-level1', deterministic=false)");
$cache = new CountingMemory();
$authorization = new Authorization();
$authorization->addRole(Role::user('owner')->toString());
$pool = new Pool(new Stack(), $schema, 1, static fn () => new Postgres(new PDO(
    getenv('RELATION_PG_DSN'), getenv('RELATION_PG_USER'), getenv('RELATION_PG_PASSWORD'), Postgres::getPDOAttributes()
)), timeout: 5);
$database = new MeasuredDatabase(new DatabasePool($pool), new Cache($cache));
$database->setDatabase($schema)->setNamespace('probe')->setAuthorization($authorization);
$permissions = [Permission::read(Role::user('owner')), Permission::update(Role::user('owner')), Permission::delete(Role::user('owner'))];
$checks = [];
$metrics = [];

try {
    $database->create();
    if ($extrasOnly) {
        checkAdditionalUpdates($database, $authorization, $permissions);
        echo json_encode(['issue' => 'ENG-2084', 'variant' => $variant, 'extras_only' => true, 'semantic_checks_passed' => true], JSON_THROW_ON_ERROR) . "\n";
        return;
    }
    $database->createCollection('databases');
    $metadata = $authorization->skip(fn () => $database->createDocument('databases', new Document(['$id' => 'event'])));
    $internalId = $metadata->getSequence();
    $drafts = "database_{$internalId}_collection_11";
    $mirrors = "database_{$internalId}_collection_12";
    foreach ([$drafts, $mirrors, 'types', 'threads', 'locations'] as $collection) {
        $database->createCollection($collection, permissions: [], documentSecurity: true);
        $database->createAttribute($collection, 'label', Database::VAR_STRING, 128, false);
    }
    $database->createRelationship('types', 'threads', Database::RELATION_MANY_TO_ONE, true, 'default_thread', 'types');
    $database->createRelationship('locations', 'threads', Database::RELATION_MANY_TO_ONE, true, 'thread', 'locations');
    foreach (['types' => 'type_definition', 'threads' => 'thread', 'locations' => 'location'] as $target => $key) {
        $database->createRelationship($drafts, $target, Database::RELATION_MANY_TO_ONE, true, $key, 'activities');
        $database->createRelationship($mirrors, $target, Database::RELATION_MANY_TO_ONE, false, $key, 'published_rows');
    }
    $authorization->skip(function () use ($database, $permissions, $drafts, $mirrors): void {
        $database->createDocument('threads', new Document(['$id' => 'shared', '$permissions' => $permissions, 'label' => 'thread']));
        $database->createDocument('types', new Document(['$id' => 'shared', '$permissions' => $permissions, 'label' => 'type', 'default_thread' => 'shared']));
        $database->createDocument('locations', new Document(['$id' => 'shared', '$permissions' => $permissions, 'label' => 'location', 'thread' => 'shared']));
        for ($i = 0; $i < 150; $i++) {
            foreach ([$drafts, $mirrors] as $collection) {
                $database->createDocument($collection, new Document([
                    '$id' => 'r' . $i, '$permissions' => $permissions, 'label' => 'before' . $i,
                    'type_definition' => 'shared', 'thread' => 'shared', 'location' => 'shared',
                ]));
            }
        }
    });

    $database->createCollection('transactions');
    $database->createAttribute('transactions', 'status', Database::VAR_STRING, 32, true);
    $database->createCollection('transactionLogs');
    foreach (['transactionInternalId', 'databaseInternalId', 'collectionInternalId', 'action', 'documentId'] as $attribute) {
        $database->createAttribute('transactionLogs', $attribute, Database::VAR_STRING, 128, true);
    }
    // Match app/config/collections/projects.php: transaction log data is a
    // JSON-filtered string, not a PostgreSQL object attribute.
    $database->createAttribute('transactionLogs', 'data', Database::VAR_STRING, 5000000, true, filters: ['json']);
    $database->createIndex('transactionLogs', 'transaction', Database::INDEX_KEY, ['transactionInternalId']);
    $transaction = $authorization->skip(fn () => $database->createDocument('transactions', new Document(['$id' => 'pending', 'status' => 'pending'])));
    $state = new TransactionState($database, $authorization, function (Document $db) use ($database): Database {
        check($db->getId() === 'event', 'Unexpected database routing');
        return $database;
    });
    $stage = function (string $id) use ($state, $metadata, $mirrors, $variant): Document {
        return $variant === 'candidate'
            ? $state->getDocumentForOperation($metadata, $mirrors, $id, 'pending')
            : $state->getDocument($metadata, $mirrors, $id, 'pending');
    };
    $database->measuredCollections = [$drafts, 'types', 'threads', 'locations'];
    $database->relatedRows = 0;
    $cache->loads = 0;
    $start = hrtime(true);
    for ($i = 0; $i < 150; $i++) {
        $row = $stage('r' . $i);
        check($row->getId() === 'r' . $i && $row->getPermissions() === $permissions, 'Stage identity or permissions differ');
    }
    $metrics['staging'] = ['seconds' => (hrtime(true) - $start) / 1e9, 'cache_loads' => $cache->loads, 'related_rows' => $database->relatedRows];
    $database->relatedRows = 0;
    $cache->loads = 0;
    $start = hrtime(true);
    $database->withTransaction(function () use ($database, $mirrors): void {
        for ($i = 0; $i < 150; $i++) {
            $row = update($database, $mirrors, 'r' . $i, ['label' => 'after' . $i, 'type_definition' => 'shared', 'thread' => 'shared', 'location' => 'shared']);
            check($row->getAttribute('label') === 'after' . $i, 'Snapshot scalar update missing');
            foreach (['type_definition' => 'type', 'thread' => 'thread', 'location' => 'location'] as $key => $label) {
                check($row->getAttribute($key) instanceof Document && $row->getAttribute($key)->getAttribute('label') === $label, 'Update return lost expanded relationship data');
            }
        }
    });
    $metrics['updates'] = ['seconds' => (hrtime(true) - $start) / 1e9, 'cache_loads' => $cache->loads, 'related_rows' => $database->relatedRows];
    $database->relatedRows = 0;
    $database->updateDocument($mirrors, 'r0', new Document(['label' => 'public-default']));
    check($database->relatedRows >= 900, 'Public update default was narrowed');
    $checks[] = '150 staged identities and snapshot updates; full returned graph and public default';
    if ($costOnly) {
        echo json_encode(['issue' => 'ENG-2084', 'variant' => $variant, 'cost_only' => true, 'metrics' => $metrics], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        checkReadBudget($metrics);
        echo "ENG-2084 cost regression passed\n";
        return;
    }

    // Actual persisted transaction logs exercise the public state overlay path.
    $log = function (string $action, string $id, array $data) use ($database, $authorization, $transaction, $internalId): void {
        $authorization->skip(fn () => $database->createDocument('transactionLogs', new Document([
            '$id' => bin2hex(random_bytes(8)), 'transactionInternalId' => $transaction->getSequence(),
            'databaseInternalId' => $internalId, 'collectionInternalId' => '12',
            'action' => $action, 'documentId' => $id, 'data' => $data,
        ])));
    };
    $changedPermissions = [Permission::read(Role::user('owner')), Permission::delete(Role::user('owner'))];
    $log('create', 'dependent', ['$id' => 'dependent', '$permissions' => $permissions, 'label' => 'created']);
    check($stage('dependent')->getPermissions() === $permissions, 'Pending create lost permissions');
    $log('update', 'dependent', ['$permissions' => $changedPermissions, 'label' => 'changed']);
    check($stage('dependent')->getPermissions() === $changedPermissions, 'Prior-batch dependent update lost staged ACL');
    $log('update', 'r1', ['$permissions' => $changedPermissions]);
    // Existing TransactionState merges getAttributes(), which excludes system
    // permissions for committed-row overlays. Preserve that baseline behavior;
    // changing its ACL semantics is separate from narrowing internal reads.
    check($stage('r1')->getPermissions() === $permissions, 'Committed row overlay changed existing ACL behavior');
    foreach (['increment', 'decrement'] as $action) {
        $log($action, 'r1', ['attribute' => 'count', 'value' => 1]);
        check($stage('r1')->getPermissions() === $permissions, 'Counter overlay changed ACL');
    }
    $log('delete', 'dependent', []);
    check($stage('dependent')->isEmpty(), 'Deleted pending document remained visible');
    $log('upsert', 'dependent', ['$id' => 'dependent', '$permissions' => $permissions, 'label' => 'upsert']);
    check($stage('dependent')->getId() === 'dependent' && $stage('dependent')->getPermissions() === $permissions, 'Dependent upsert lost identity or ACL');
    check($stage('missing')->isEmpty(), 'Missing staged document appeared');
    $authorization->removeRole(Role::user('owner')->toString());
    check($stage('r0')->isEmpty(), 'Anonymous staging exposed a private committed row');
    $authorization->addRole(Role::user('outsider')->toString());
    check($stage('r0')->isEmpty(), 'Outsider staging exposed a warmed private row');
    $authorization->removeRole(Role::user('outsider')->toString());
    $authorization->addRole(Role::user('owner')->toString());
    $checks[] = 'pending create/update/delete/upsert/counter overlays preserve identity and staged ACL';

    foreach (['records', 'parents', 'details', 'tags'] as $collection) {
        $database->createCollection($collection, permissions: [], documentSecurity: true);
        $database->createAttribute($collection, 'label', Database::VAR_STRING, 128, false);
    }
    $database->createAttribute('records', 'count', Database::VAR_INTEGER, 0, false, default: 0);
    $database->createAttribute('records', 'values', Database::VAR_STRING, 64, false, array: true);
    $database->createRelationship('records', 'parents', Database::RELATION_MANY_TO_ONE, true, 'parent', 'records');
    $database->createRelationship('records', 'details', Database::RELATION_ONE_TO_ONE, true, 'detail', 'record');
    $database->createRelationship('records', 'tags', Database::RELATION_MANY_TO_MANY, true, 'tags', 'records');
    $authorization->skip(function () use ($database, $permissions): void {
        foreach (['parents', 'details', 'tags'] as $collection) {
            foreach (['a', 'b'] as $id) {
                $database->createDocument($collection, new Document(['$id' => $id, '$permissions' => $permissions, 'label' => $collection . $id]));
            }
        }
        foreach (['first', 'second'] as $id) {
            $database->createDocument('records', new Document(['$id' => $id, '$permissions' => $permissions, 'label' => $id, 'parent' => 'a', 'values' => ['initial']]));
        }
    });
    update($database, 'records', 'first', ['parent' => 'b', 'detail' => 'a', 'tags' => ['a', 'b']]);
    check(identities($database->getDocument('parents', 'a')->getAttribute('records')) === ['second'], 'Old many-to-one inverse retained');
    check(identities($database->getDocument('parents', 'b')->getAttribute('records')) === ['first'], 'New many-to-one inverse missing');
    check(identity($database->getDocument('details', 'a')->getAttribute('record')) === 'first', 'One-to-one inverse missing');
    check(identities($database->getDocument('tags', 'b')->getAttribute('records')) === ['first'], 'Many-to-many inverse missing');
    // PostgreSQL's existing one-to-one path rejects direct reassignment while
    // the old inverse still holds the unique key. Preserve its atomic failure.
    try {
        update($database, 'records', 'first', ['detail' => 'b']);
        throw new RuntimeException('Existing one-to-one duplicate behavior changed');
    } catch (\Utopia\Database\Exception\Duplicate $expected) {
    }
    check(identity($database->getDocument('records', 'first')->getAttribute('detail')) === 'a', 'Rejected reassignment changed original relation');
    update($database, 'records', 'first', ['detail' => null]);
    update($database, 'records', 'first', ['detail' => 'b', 'tags' => ['b']]);
    check($database->getDocument('details', 'a')->getAttribute('record') === null, 'Old one-to-one inverse retained');
    check($database->getDocument('tags', 'a')->getAttribute('records') === [], 'Removed junction retained');
    update($database, 'records', 'first', ['detail' => null, 'tags' => []]);
    check($database->getDocument('details', 'b')->getAttribute('record') === null, 'One-to-one null did not clear inverse');
    check($database->getDocument('tags', 'b')->getAttribute('records') === [], 'Empty many-to-many did not clear inverse');
    update($database, 'parents', 'a', ['records' => ['first', 'second']]);
    check(identity($database->getDocument('records', 'first')->getAttribute('parent')) === 'a', 'Reverse-side reassign lost child link');
    $checks[] = 'many-to-one, one-to-one, many-to-many reassign/remove/null and inverse-side writes';

    update($database, 'records', 'first', ['parent' => new Document(['$id' => 'a', 'label' => 'nested'])]);
    check($database->getDocument('parents', 'a')->getAttribute('label') === 'nested', 'Same-ID nested Document update lost');
    $operator = update($database, 'records', 'first', ['count' => Operator::increment(3), 'values' => Operator::arrayAppend(['next'])]);
    check($operator->getAttribute('count') === 3 && $operator->getAttribute('values') === ['initial', 'next'], 'Operator return does not reflect write');
    $before = $database->getDocument('records', 'first');
    $noop = update($database, 'records', 'first', ['label' => $before->getAttribute('label'), 'parent' => 'a']);
    check($noop->getUpdatedAt() === $before->getUpdatedAt(), 'No-op changed updatedAt');
    check($noop->getCreatedAt() === $before->getCreatedAt() && $noop->getSequence() === $before->getSequence(), 'Identity dates changed');
    try {
        $database->withTransaction(function () use ($database): void {
            update($database, 'records', 'first', ['label' => 'rollback', 'parent' => 'b', 'tags' => ['a']]);
            throw new RuntimeException('deliberate rollback');
        });
    } catch (RuntimeException $error) {
        check($error->getMessage() === 'deliberate rollback', 'Unexpected rollback error');
    }
    $after = $database->getDocument('records', 'first');
    check($after->getAttribute('label') === $before->getAttribute('label') && identity($after->getAttribute('parent')) === 'a' && $after->getAttribute('tags') === [], 'Rollback lost scalar or relationship state');
    $checks[] = 'nested same-ID Document, operators, no-op timestamps and transaction rollback';

    $authorization->skip(fn () => $database->createDocument('records', new Document([
        '$id' => 'public', '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())], 'label' => 'public', 'parent' => 'a',
    ])));
    try {
        $database->withTransaction(function () use ($database, $authorization): void {
            update($database, 'records', 'first', ['label' => 'partial']);
            $authorization->removeRole(Role::user('owner')->toString());
            try {
                update($database, 'records', 'public', ['parent' => new Document(['$id' => 'a', 'label' => 'forbidden'])]);
            } finally {
                $authorization->addRole(Role::user('owner')->toString());
            }
        });
        throw new RuntimeException('Denied nested write accepted');
    } catch (\Utopia\Database\Exception\Authorization $expected) {
    }
    check($database->getDocument('records', 'first')->getAttribute('label') === $before->getAttribute('label'), 'Denied nested write left partial write');
    check($database->getDocument('parents', 'a')->getAttribute('label') === 'nested', 'Denied nested data persisted');
    $authorization->removeRole(Role::user('owner')->toString());
    try {
        update($database, 'records', 'first', ['label' => 'denied']);
        throw new RuntimeException('Private root write accepted');
    } catch (\Utopia\Database\Exception\Authorization $expected) {
    }
    $public = update($database, 'records', 'public', ['label' => 'visible']);
    check($public->getAttribute('parent') instanceof Document && $public->getAttribute('parent')->isEmpty(), 'Returned document exposed inaccessible related parent or changed its empty shape');
    $authorization->addRole(Role::user('owner')->toString());
    check(identity($database->getDocument('records', 'public')->getAttribute('parent')) === 'a', 'Unreadable related row was detached by scalar update');
    $checks[] = 'root and nested authorization, warmed-cache relation privacy, denied-write rollback';

    checkAdditionalUpdates($database, $authorization, $permissions);
    $checks[] = 'nested maps/lists, relationship operators, dependent upsert, conflict, missing row and write-only no-op';

    echo json_encode(['issue' => 'ENG-2084', 'variant' => $variant, 'metrics' => $metrics, 'checks' => $checks, 'semantic_checks_passed' => true], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    // Fanout, not wall-clock timing, is the deterministic performance oracle.
    checkReadBudget($metrics);
    echo "ENG-2084 passed\n";
} finally {
    // Only the randomly named schema created by this process is removed.
    $pdo->exec('DROP SCHEMA "' . $schema . '" CASCADE');
}
