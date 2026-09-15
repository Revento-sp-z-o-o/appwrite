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
$expectedHash = $manifest['patches'][1][$variant === 'original' ? 'before_sha256' : 'after_sha256'];
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
$schema = 'relation_' . $variant . '_' . $cacheMode;
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

foreach (['events', 'activities', 'mirrors', 'details', 'people'] as $collection) {
    $database->createCollection($collection, permissions: [], documentSecurity: true);
    $database->createAttribute($collection, 'label', Database::VAR_STRING, 128, false);
}
$database->createRelationship('activities', 'events', Database::RELATION_MANY_TO_ONE, true, 'event', 'activities');
$database->createRelationship('activities', 'details', Database::RELATION_ONE_TO_ONE, true, 'detail', 'activity');
$database->createRelationship('activities', 'people', Database::RELATION_MANY_TO_ONE, false, 'person', 'activities');
$database->createRelationship('mirrors', 'activities', Database::RELATION_MANY_TO_ONE, true, 'activity', 'mirrors');
$database->createRelationship('mirrors', 'events', Database::RELATION_MANY_TO_ONE, false, 'event', 'mirrors');
$authorization->skip(function () use ($database, $permissions): void {
    foreach (['first', 'second'] as $id) {
        $database->createDocument('events', new Document(['$id' => $id, '$permissions' => $permissions, 'label' => $id]));
    }
    $database->createDocument('details', new Document(['$id' => 'detail', '$permissions' => $permissions, 'label' => 'private']));
    for ($i = 0; $i < 35; $i++) {
        $database->createDocument('activities', new Document(['$id' => 'activity' . $i, '$permissions' => $permissions, 'label' => 'draft', 'event' => 'first', 'detail' => $i === 0 ? 'detail' : null]));
    }
});

$cache->loads = 0;
$start = hrtime(true);
$database->withTransaction(function () use ($database, $permissions, $authorization): void {
    for ($i = 0; $i < 35; $i++) {
        $database->updateDocument('activities', 'activity' . $i, new Document(['label' => 'published']));
        $authorization->skip(fn () => $database->createDocument('mirrors', new Document(['$id' => 'mirror' . $i, '$permissions' => $permissions, 'label' => 'mirror', 'activity' => 'activity' . $i, 'event' => 'first'])));
    }
});
$metrics = ['seconds' => (hrtime(true) - $start) / 1e9, 'cache_loads' => $cache->loads];

$checks = [];
$activity = $database->getDocument('activities', 'activity0');
check($activity->getAttribute('label') === 'published', 'Scalar update missing');
check($activity->getAttribute('event')->getId() === 'first', 'Forward relationship lost');
check($activity->getAttribute('detail')->getId() === 'detail', 'One-to-one lost');
check(count($activity->getAttribute('mirrors')) === 1, 'Inverse mirror missing');
$checks[] = 'scalar and forward/inverse relationships';
$plain = $database->getDocument('activities', 'activity0', [Query::select(['*'])]);
check($plain->getAttribute('event') === 'first', 'Explicit wildcard changed');
$selected = $database->getDocument('activities', 'activity0', [Query::select(['label', 'event.label'])]);
check($selected->getAttribute('event')->getAttribute('label') === 'first', 'Nested selection changed');
$checks[] = 'raw wildcard and nested selections';
$database->updateDocument('activities', 'activity0', new Document(['event' => 'second']));
check($database->getDocument('activities', 'activity0')->getAttribute('event')->getId() === 'second', 'Relationship reassignment failed');
check(count($database->getDocument('events', 'first')->getAttribute('activities')) === 34, 'Old inverse retained');
check(count($database->getDocument('events', 'second')->getAttribute('activities')) === 1, 'New inverse missing');
$checks[] = 'relationship reassignment and inverse maintenance';
$database->updateDocument('activities', 'activity0', new Document(['detail' => null]));
check($database->getDocument('activities', 'activity0')->getAttribute('detail') === null, 'Null relationship failed');
check($database->getDocument('details', 'detail')->getAttribute('activity') === null, 'Null inverse failed');
$checks[] = 'null relationship and inverse maintenance';

// Readable parents must never reveal related rows that the active role cannot read,
// including when the same database/cache was previously used by the owner.
$publicRead = [Permission::read(Role::any())];
$authorization->skip(function () use ($database, $permissions, $publicRead): void {
    foreach (['parents', 'children', 'otherchildren', 'tags'] as $collection) {
        $database->createCollection($collection, permissions: [], documentSecurity: true);
        $database->createAttribute($collection, 'label', Database::VAR_STRING, 128, false);
    }
    $database->createRelationship('parents', 'children', Database::RELATION_ONE_TO_MANY, true, 'children', 'parent');
    $database->createRelationship('parents', 'otherchildren', Database::RELATION_ONE_TO_MANY, false, 'oneway', 'parent');
    $database->createRelationship('parents', 'tags', Database::RELATION_MANY_TO_MANY, true, 'tags', 'parents');
    $database->createDocument('parents', new Document(['$id' => 'parent', '$permissions' => $publicRead, 'label' => 'public']));
    foreach (['visible' => $publicRead, 'hidden' => $permissions] as $id => $access) {
        $database->createDocument('children', new Document(['$id' => $id, '$permissions' => $access, 'label' => $id, 'parent' => 'parent']));
        $database->createDocument('otherchildren', new Document(['$id' => $id, '$permissions' => $access, 'label' => $id]));
        $database->createDocument('tags', new Document(['$id' => $id, '$permissions' => $access, 'label' => $id]));
    }
    $database->updateDocument('parents', 'parent', new Document(['oneway' => ['visible', 'hidden'], 'tags' => ['visible', 'hidden']]));
    $database->createDocument('mirrors', new Document(['$id' => 'publicmirror', '$permissions' => $publicRead, 'label' => 'public', 'activity' => 'activity0']));
});
$ownerParent = $database->getDocument('parents', 'parent');
foreach (['children', 'oneway', 'tags'] as $attribute) {
    check(count($ownerParent->getAttribute($attribute)) === 2, 'Owner relationship fixture incomplete: ' . $attribute);
}
check($database->getDocument('children', 'visible')->getAttribute('parent')->getId() === 'parent', 'One-to-many child side missing');
check(!$database->getDocument('otherchildren', 'visible')->offsetExists('parent'), 'One-way back-reference exposed');
$checks[] = 'one-to-many both sides, one-way relation and many-to-many controls';

$authorization->removeRole(Role::user('owner')->toString());
$authorization->addRole(Role::user('outsider')->toString());
$outsiderParent = $database->getDocument('parents', 'parent');
foreach (['children', 'oneway', 'tags'] as $attribute) {
    $related = $outsiderParent->getAttribute($attribute);
    check(count($related) === 1 && $related[0]->getId() === 'visible', 'Hidden related data exposed: ' . $attribute);
}
check($database->getDocument('mirrors', 'publicmirror')->getAttribute('activity')->isEmpty(), 'Private forward relationship leaked');
$checks[] = 'readable root filters inaccessible relations after cache/role switch';
check($database->getDocument('activities', 'activity0')->isEmpty(), 'Outsider can read private activity');
try {
    $database->updateDocument('activities', 'activity0', new Document(['label' => 'forbidden']));
    throw new RuntimeException('Outsider write unexpectedly accepted');
} catch (\Utopia\Database\Exception\Authorization $expected) {
}
$authorization->removeRole(Role::user('outsider')->toString());
check($database->find('activities') === [], 'Unauthenticated activity read leaked');
check(count($database->getDocument('parents', 'parent')->getAttribute('children')) === 1, 'Unauthenticated related data leaked');
$authorization->addRole(Role::user('owner')->toString());
check($database->getDocument('activities', 'activity0')->getAttribute('label') === 'published', 'Rejected write persisted');
$checks[] = 'outsider/unauthenticated denial and rejected-write persistence';

try {
    $database->withTransaction(function () use ($database): void {
        $database->updateDocument('activities', 'activity1', new Document(['label' => 'rolledback', 'event' => 'second']));
        throw new RuntimeException('deliberate rollback');
    });
} catch (RuntimeException $expected) {
    check($expected->getMessage() === 'deliberate rollback', 'Unexpected transaction failure');
}
$rolledBack = $database->getDocument('activities', 'activity1');
check($rolledBack->getAttribute('label') === 'published' && $rolledBack->getAttribute('event')->getId() === 'first', 'Rollback lost atomicity');
check(count($database->getDocument('events', 'first')->getAttribute('activities')) === 34, 'Rollback old inverse changed');
check(count($database->getDocument('events', 'second')->getAttribute('activities')) === 1, 'Rollback new inverse changed');
$database->purgeCachedDocument('activities', 'activity1');
check($database->getDocument('activities', 'activity1')->getAttribute('event')->getId() === 'first', 'Durable rollback lost relationship');
$checks[] = 'transaction rollback retains scalar and relationship values';

try {
    $database->deleteDocument('events', 'first');
    throw new RuntimeException('Restricted parent deletion accepted');
} catch (\Utopia\Database\Exception\Restricted $expected) {
}
check(!$database->getDocument('events', 'first')->isEmpty(), 'Restricted parent deleted');
check($database->getDocument('activities', 'activity1')->getAttribute('event')->getId() === 'first', 'Restricted delete removed relation');
$checks[] = 'restricted deletion preserves parent and relationship';

// A permitted parent mutation must not make a forbidden nested child update stick.
$authorization->skip(fn () => $database->createDocument('activities', new Document([
    '$id' => 'nested',
    '$permissions' => [Permission::read(Role::any()), Permission::update(Role::any())],
    'label' => 'publicly editable',
    'event' => 'first',
])));
try {
    $database->withTransaction(function () use ($database, $authorization): void {
        $database->updateDocument('activities', 'activity1', new Document(['label' => 'partial']));
        $authorization->removeRole(Role::user('owner')->toString());
        try {
            $database->updateDocument('activities', 'nested', new Document(['event' => new Document(['$id' => 'first', 'label' => 'forbidden'])]));
        } finally {
            $authorization->addRole(Role::user('owner')->toString());
        }
    });
    throw new RuntimeException('Unauthorized nested mutation accepted');
} catch (\Utopia\Database\Exception\Authorization $expected) {
}
check($database->getDocument('activities', 'activity1')->getAttribute('label') === 'published', 'Failure left a partial scalar write');
check($database->getDocument('events', 'first')->getAttribute('label') === 'first', 'Forbidden nested write persisted');
$checks[] = 'forbidden nested relation write rolls back preceding scalar write';

echo json_encode(['variant' => $variant, 'cache' => $cacheMode, 'metrics' => $metrics, 'checks' => $checks, 'passed' => true], JSON_PRETTY_PRINT) . "\n";
