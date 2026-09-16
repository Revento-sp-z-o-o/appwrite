<?php

use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Pool as DatabasePool;
use Utopia\Database\Adapter\Postgres;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\PDO;
use Utopia\Database\Validator\Authorization;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Pool;

$source = getenv('REVENTO_DATABASE_SOURCE');
$manifest = json_decode(file_get_contents(__DIR__ . '/../manifest.json'), true, flags: JSON_THROW_ON_ERROR);
$expectedHash = null;
foreach ($manifest['patches'] as $patch) {
    if ($patch['target'] === 'vendor/utopia-php/database/src/Database/Database.php') {
        $expectedHash = $patch['after_sha256'];
    }
}
if (!$source || !$expectedHash || hash_file('sha256', $source) !== $expectedHash || !str_starts_with(getenv('RELATION_PG_DSN') ?: '', 'pgsql:host=127.0.0.1;')) {
    throw new RuntimeException('Pinned candidate source and owned loopback PostgreSQL required');
}
require getenv('REVENTO_DATABASE_AUTOLOAD');
require $source;
$schema = 'eng2091_' . bin2hex(random_bytes(6));
$pdo = new PDO(getenv('RELATION_PG_DSN'), getenv('RELATION_PG_USER'), getenv('RELATION_PG_PASSWORD'), [\PDO::ATTR_PERSISTENT => false] + Postgres::getPDOAttributes());
$pdo->exec('CREATE SCHEMA "' . $schema . '"');
$pdo->exec("CREATE COLLATION IF NOT EXISTS utf8_ci_ai (provider=icu, locale='und-u-ks-level1', deterministic=false)");
$cacheMode = getenv('RELATION_CACHE') ?: 'memory';
if (!in_array($cacheMode, ['memory', 'redis'], true)) {
    throw new RuntimeException('Unsupported cache');
}
$adapter = new Memory();
if ($cacheMode === 'redis') {
    $redis = new \Redis();
    requireEqual(getenv('RELATION_OWNED_REDIS'), '127.0.0.1:6379', 'Explicit owned Redis required');
    $redis->connect('127.0.0.1', 6379, 2);
    $adapter = new \Utopia\Cache\Adapter\Redis($redis);
}
class ControlledCache extends Cache
{
    public bool $failNextIndexPurge = false;

    public bool $failNextDocumentPurge = false;
    public bool $delayDocumentSave = false;
    public array $delayedSaves = [];

    public function purge(string $key, string $hash = ''): bool
    {
        if ($this->failNextIndexPurge && $hash !== '') {
            $this->failNextIndexPurge = false;
            throw new RuntimeException('owned index purge failure');
        }
        if ($this->failNextDocumentPurge && $hash === '') {
            $this->failNextDocumentPurge = false;
            throw new RuntimeException('owned document purge failure');
        }
        return parent::purge($key, $hash);
    }

    public function saveWithLease(string $key, mixed $data, string $hash, string $generation): bool|string|array
    {
        if ($this->delayDocumentSave && is_array($data) && ($data['$collection'] ?? '') === 'states') {
            $this->delayedSaves[] = [$key, $data, $hash, $generation];
            return false;
        }
        return parent::saveWithLease($key, $data, $hash, $generation);
    }

    public function releaseDelayedSaves(): array
    {
        $this->delayDocumentSave = false;
        $results = [];
        foreach ($this->delayedSaves as $arguments) {
            $results[] = parent::saveWithLease(...$arguments);
        }
        $this->delayedSaves = [];
        return $results;
    }
}
$cache = new ControlledCache($adapter);
function connection(string $name, string $schema, Cache $cache): array
{
    $auth = new Authorization();
    $auth->addRole(Role::user('owner')->toString());
    $pool = new Pool(new Stack(), $name, 1, static fn () => new Postgres(new PDO(
        getenv('RELATION_PG_DSN'),
        getenv('RELATION_PG_USER'),
        getenv('RELATION_PG_PASSWORD'),
        [\PDO::ATTR_PERSISTENT => false] + Postgres::getPDOAttributes()
    )), timeout: 5);
    $database = new Database(new DatabasePool($pool), $cache);
    $database->setDatabase($schema)->setNamespace('probe')->setCacheName($schema)->setAuthorization($auth);
    return [$database, $auth];
}
[$writer, $writerAuth] = connection($schema . '_writer', $schema, $cache);
[$reader, $readerAuth] = connection($schema . '_reader', $schema, $cache);
$permissions = [Permission::read(Role::user('owner')), Permission::update(Role::user('owner')), Permission::delete(Role::user('owner'))];
$results = [];
function readState(Database $db, string $id): mixed
{
    try {
        $row = $db->getDocument('states', $id);
        return $row->isEmpty() ? 'missing' : $row->getAttribute('value');
    } catch (\Utopia\Database\Exception\Authorization $error) {
        return 'denied';
    }
}
function requireEqual(mixed $actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) {
        throw new RuntimeException($message . ': ' . json_encode([$actual, $expected]));
    }
}
try {
    $writer->create();
    $writer->createCollection('states', permissions: [], documentSecurity: true);
    $writer->createAttribute('states', 'value', Database::VAR_INTEGER, 8, true);
    foreach (['update', 'revoke', 'delete', 'counter', 'rollback', 'bulk'] as $id) {
        $writerAuth->skip(fn () => $writer->createDocument('states', new Document(['$id' => $id, '$permissions' => $permissions, 'value' => 1])));
        requireEqual(readState($reader, $id), 1, 'Initial owner read');
    }
    foreach (['update', 'revoke', 'delete', 'counter', 'create', 'bulk', 'rollback'] as $case) {
        $inside = null;
        try {
            $writer->withTransaction(function () use ($writer, $writerAuth, $reader, $permissions, $case, &$inside): void {
                switch ($case) {
                    case 'update': $writer->updateDocument('states', $case, new Document(['value' => 2]));
                        break;
                    case 'revoke': $writerAuth->skip(fn () => $writer->updateDocument('states', $case, new Document(['$permissions' => []])));
                        break;
                    case 'delete': $writer->deleteDocument('states', $case);
                        break;
                    case 'counter': $writer->increaseDocumentAttribute('states', $case, 'value', 1);
                        break;
                    case 'create': $writerAuth->skip(fn () => $writer->createDocument('states', new Document(['$id' => $case, '$permissions' => $permissions, 'value' => 2])));
                        break;
                    case 'bulk': $writer->updateDocuments('states', new Document(['value' => 2]), [\Utopia\Database\Query::equal('$id', [$case])]);
                        break;
                    case 'rollback': $writer->updateDocument('states', $case, new Document(['value' => 2]));
                        break;
                }
                $inside = readState($reader, $case);
                requireEqual($inside, $case === 'create' ? 'missing' : 1, 'Reader saw uncommitted change');
                if ($case === 'rollback') {
                    throw new RuntimeException('owned rollback');
                }
            });
        } catch (RuntimeException $error) {
            if ($case !== 'rollback' || $error->getMessage() !== 'owned rollback') {
                throw $error;
            }
        }
        $expected = match ($case) {
            'revoke' => 'missing', 'delete' => 'missing', 'rollback' => 1, default => 2
        };
        $actual = readState($reader, $case);
        $results[] = ['case' => $case,'inside' => $inside,'expected' => $expected,'actual' => $actual,'passed' => $actual === $expected];
    }
    // The callback may restore/change adapter context before outer cleanup.
    // SQL remains in one namespace; the captured purge key must not change.
    $writer->withTransaction(function () use ($writer, $reader): void {
        $writer->updateDocument('states', 'rollback', new Document(['value' => 2]));
        requireEqual(readState($reader, 'rollback'), 1, 'Read before context change');
        $writer->setNamespace('different_context_at_commit');
    });
    $writer->setNamespace('probe');
    $actual = readState($reader, 'rollback');
    $results[] = ['case' => 'namespace-context-at-commit', 'expected' => 2, 'actual' => $actual, 'passed' => $actual === 2];
    $writer->updateDocument('states', 'rollback', new Document(['value' => 1]));
    // A later successful transaction must not inherit failed-transaction state.
    $writer->withTransaction(function () use ($writer, $reader): void {
        $writer->withTransaction(fn () => $writer->updateDocument('states', 'rollback', new Document(['value' => 3])));
        requireEqual(readState($reader, 'rollback'), 1, 'Nested transaction leaked before outer commit');
    });
    $actual = readState($reader, 'rollback');
    $results[] = ['case' => 'nested-after-rollback', 'expected' => 3, 'actual' => $actual, 'passed' => $actual === 3];
    $writer->withTransaction(function () use ($writer, $reader): void {
        try {
            $writer->withTransaction(function () use ($writer): void {
                $writer->updateDocument('states', 'rollback', new Document(['value' => 99]));
                throw new \Utopia\Database\Exception\Conflict('owned inner rollback');
            });
        } catch (\Utopia\Database\Exception\Conflict $error) {
            requireEqual($error->getMessage(), 'owned inner rollback', 'Caught inner rollback');
        }
        $writer->updateDocument('states', 'rollback', new Document(['value' => 6]));
        requireEqual(readState($reader, 'rollback'), 3, 'Reader before commit following inner rollback');
    });
    $actual = readState($reader, 'rollback');
    $results[] = ['case' => 'caught-inner-rollback-outer-commit', 'expected' => 6, 'actual' => $actual, 'passed' => $actual === 6];
    foreach (['purge-failure-commit', 'purge-failure-rollback'] as $case) {
        $writerAuth->skip(fn () => $writer->createDocument('states', new Document(['$id' => $case, '$permissions' => $permissions, 'value' => 1])));
        $observedError = null;
        try {
            $writer->withTransaction(function () use ($writer, $writerAuth, $reader, $cache, $case): void {
                $writerAuth->skip(fn () => $writer->updateDocument('states', $case, new Document(['value' => 2, '$permissions' => []])));
                requireEqual(readState($reader, $case), 1, 'Reader during pending revoke');
                $cache->failNextIndexPurge = true;
                if ($case === 'purge-failure-rollback') {
                    throw new \Utopia\Database\Exception\Conflict('owned rollback');
                }
            });
        } catch (\Throwable $error) {
            $observedError = $error->getMessage();
        }
        $expectedError = $case === 'purge-failure-commit' ? 'owned index purge failure' : 'owned rollback';
        requireEqual($observedError, $expectedError, 'Cleanup preserves correct error');
        $expected = $case === 'purge-failure-commit' ? 'missing' : 1;
        $actual = readState($reader, $case);
        $results[] = ['case' => $case, 'expected' => $expected, 'actual' => $actual, 'passed' => $actual === $expected];
    }
    foreach (['failNextIndexPurge', 'failNextDocumentPurge'] as $failureFlag) {
        foreach (['first_dirty', 'later_dirty'] as $id) {
            $writerAuth->skip(fn () => $writer->upsertDocument('states', new Document(['$id' => $id, '$permissions' => $permissions, 'value' => 1])));
        }
        $observedError = null;
        try {
            $writer->withTransaction(function () use ($writer, $reader, $cache, $failureFlag): void {
                foreach (['first_dirty', 'later_dirty'] as $id) {
                    $writer->updateDocument('states', $id, new Document(['value' => 2]));
                    requireEqual(readState($reader, $id), 1, 'Dirty key reader before commit');
                }
                $cache->$failureFlag = true;
            });
        } catch (RuntimeException $error) {
            $observedError = $error->getMessage();
        }
        $expectedError = $failureFlag === 'failNextIndexPurge' ? 'owned index purge failure' : 'owned document purge failure';
        requireEqual($observedError, $expectedError, 'Purge failure reported after committed SQL');
        $actual = readState($reader, 'later_dirty');
        $results[] = ['case' => 'later-key-after-' . $failureFlag, 'expected' => 2, 'actual' => $actual, 'passed' => $actual === 2];
        // A failed document purge cannot guarantee that key is fresh. Repair only
        // this owned test key before the next case; never flush shared caches.
        $writer->purgeCachedDocument('states', 'first_dirty');
    }
    $writer->withTransaction(function () use ($writer, $reader): void {
        $writer->updateDocument('states', 'update', new Document(['value' => 4]));
        $selected = $reader->getDocument('states', 'update', [\Utopia\Database\Query::select(['value'])]);
        requireEqual($selected->getAttribute('value'), 2, 'Projected reader before commit');
    });
    $actual = $reader->getDocument('states', 'update', [\Utopia\Database\Query::select(['value'])])->getAttribute('value');
    $results[] = ['case' => 'projected-read-after-commit', 'expected' => 4, 'actual' => $actual, 'passed' => $actual === 4];
    if ($cacheMode === 'redis') {
        $writer->updateDocument('states', 'update', new Document(['value' => 4]));
        $writer->withTransaction(function () use ($writer, $reader, $cache): void {
            $writer->updateDocument('states', 'update', new Document(['value' => 5]));
            $cache->delayDocumentSave = true;
            requireEqual(readState($reader, 'update'), 4, 'Delayed reader captured previous committed row');
        });
        requireEqual(count($cache->delayedSaves), 1, 'Exactly one delayed row save');
        requireEqual($cache->releaseDelayedSaves(), [false], 'Commit invalidates old reader lease');
        $actual = readState($reader, 'update');
        $results[] = ['case' => 'delayed-reader-lease', 'expected' => 5, 'actual' => $actual, 'passed' => $actual === 5];
    }
    $readerAuth->removeRole(Role::user('owner')->toString());
    $readerAuth->addRole(Role::user('outsider')->toString());
    $actual = readState($reader, 'update');
    $results[] = ['case' => 'outsider-after-owner-cache', 'expected' => 'inaccessible', 'actual' => $actual, 'passed' => in_array($actual, ['missing','denied'], true)];
    echo json_encode(['cache' => $cacheMode, 'source_sha256' => hash_file('sha256', $source),'results' => $results], JSON_PRETTY_PRINT) . "\n";
    if (in_array(false, array_column($results, 'passed'), true)) {
        throw new RuntimeException('Committed document cache consistency failed');
    }
} finally {
    $pdo->exec('DROP SCHEMA "' . $schema . '" CASCADE');
}
