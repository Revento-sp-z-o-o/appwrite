<?php

use Utopia\Database\Query;

/** Actual-adapter/PostgreSQL prefix control in an owned loopback database. */
$options = getopt('', ['execute-local', 'variant:', 'adapter-source:', 'autoload:', 'output:', 'baseline:', 'group:']);
if (!isset($options['execute-local'])) {
    fwrite(STDOUT, "INERT: explicit isolated local PostgreSQL execution required.\n");
    exit(0);
}
function need(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
foreach (['variant', 'adapter-source', 'autoload', 'output'] as $key) {
    need(isset($options[$key]) && is_string($options[$key]), "Missing explicit option: {$key}");
}
$variant = $options['variant'];
$group = $options['group'] ?? 'all';
need(in_array($group, ['all', 'prefix-dictionary'], true), 'Unknown control group');
need(in_array($variant, ['original', 'candidate'], true), 'Unknown source variant');
$patches = json_decode(file_get_contents(__DIR__ . '/../manifest.json'), true, flags: JSON_THROW_ON_ERROR)['patches'];
$patch = array_values(array_filter($patches, static fn (array $item): bool => $item['name'] === 'postgres-explicit-prefix'))[0];
$manifest = ['adapter_sha256' => ['original' => $patch['before_sha256'], 'candidate' => $patch['after_sha256']],
    'sql_sha256' => '851354d8a55c1a831aea1e01700b49c9c5d3ee377904f2db397bd55805f1de2e'];
$source = $options['adapter-source'];
need(is_file($source) && !is_link($source) && hash_file('sha256', $source) === $manifest['adapter_sha256'][$variant], 'Adapter bytes do not match frozen source');
need(is_file($options['autoload']) && !is_link($options['autoload']), 'Exact local dependency autoload required');
need(!file_exists($options['output']) && !is_link($options['output']), 'Output must not already exist');
$baseline = null;
if ($variant !== 'original') {
    need(isset($options['baseline']) && is_file($options['baseline']) && !is_link($options['baseline']), 'Preserved original engine result required');
    $baseline = json_decode(file_get_contents($options['baseline']), true, flags: JSON_THROW_ON_ERROR);
    need($baseline['variant'] === 'original' && $baseline['adapter_sha256'] === $manifest['adapter_sha256']['original'], 'Wrong original comparison receipt');
}
$dsn = getenv('ENG2114_PREFIX_PG_DSN') ?: '';
need(preg_match('/\Apgsql:host=127\.0\.0\.1;port=[0-9]{1,5};dbname=eng2114_prefix_[a-z0-9_]+\z/', $dsn) === 1, 'Only explicitly isolated loopback PostgreSQL database permitted');
require $options['autoload'];
require $source;
need(hash_file('sha256', (new ReflectionClass(Utopia\Database\Adapter\SQL::class))->getFileName()) === $manifest['sql_sha256'], 'Parent SQL adapter differs from pinned release');

class PrefixProbeAdapter extends Utopia\Database\Adapter\Postgres
{
    public function permissionPredicate(array $roles): string
    {
        return $this->getSQLPermissionsCondition('eng2114_prefix', $roles, Utopia\Database\Query::DEFAULT_ALIAS);
    }
}


$pdo = new PDO($dsn, getenv('ENG2114_PREFIX_PG_USER') ?: '', getenv('ENG2114_PREFIX_PG_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$adapter = new PrefixProbeAdapter($pdo);
$record = [
    'variant' => $variant,
    'control_group' => $group,
    'test_sha256' => hash_file('sha256', __FILE__),
    'database_locale' => $pdo->query("SELECT datcollate, datctype, datlocprovider, datlocale FROM pg_catalog.pg_database WHERE datname = current_database()")->fetch(PDO::FETCH_ASSOC),
    'adapter_sha256' => hash_file('sha256', $source),
    'sql_sha256' => $manifest['sql_sha256'],
    'php_version' => PHP_VERSION,
    'postgres_version' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
    'text_search_config' => $pdo->query('SHOW default_text_search_config')->fetchColumn(),
    'checks' => [], 'controls' => [], 'failures' => [],
    'scope' => 'Actual adapter SQL and permission predicate against transaction-local temporary fixtures; no Appwrite API/native deployment proof.',
];
if ($baseline !== null) {
    need($baseline['text_search_config'] === $record['text_search_config'] && $baseline['postgres_version'] === $record['postgres_version'] && ($baseline['test_sha256'] ?? null) === $record['test_sha256'] && ($baseline['control_group'] ?? null) === $group && ($baseline['database_locale'] ?? null) === $record['database_locale'], 'Original/candidate engines differ');
}
$pdo->beginTransaction();
try {
    $pdo->exec('CREATE TEMP TABLE eng2114_prefix (id integer, text text, dictionary_text text, tokens text, n integer, _permissions jsonb) ON COMMIT DROP');
    $category = 'dfv_' . substr(hash('sha256', "level\0beginner"), 0, 28);
    $number = 'dfn_' . substr(hash('sha256', "age\0participant_num_0"), 0, 28);
    $rows = [
        [1, 'zolty smok', "$category $number", 12, 'user:reader'],
        [2, 'zolty slon', '', 8, 'user:reader'],
        [3, 'niebieski smok', $category, 20, 'user:reader'],
        [4, 'zolty smok', "$category $number", 12, 'user:other'],
        [5, null, '', 0, 'user:reader'],
        [6, 'smok zolty', "$category $number", 12, 'user:reader'],
        [7, 'żółty smok', '', 12, 'user:reader'],
        [8, '123456', '', 30, 'user:reader'],
        [9, 'добрый', '', 30, 'user:reader'],
        [10, 'καλος', '', 30, 'user:reader'],
        [11, '١٢٣٤', '', 30, 'user:reader'],
        [12, 'a١٢٣٤', '', 30, 'user:reader'],
        [13, 'Ⅷalpha ²beta', '', 30, 'user:reader'],
        [14, 'alphabet za١٢٣٤', '', 30, 'user:reader'],
        [15, 'foo-boundary foo_boundary Добрый ΚΑΛΟΣ', '', 30, 'user:reader'],
    ];
    $insert = $pdo->prepare('INSERT INTO eng2114_prefix (id, text, tokens, n, _permissions) VALUES (?, ?, ?, ?, ?::jsonb)');
    foreach ($rows as [$id, $text, $tokens, $n, $role]) {
        $insert->execute([$id, $text, $tokens, $n, json_encode(["read(\"{$role}\")"], JSON_THROW_ON_ERROR)]);
    }
    // Separate text column leaves all original fixture values and 44 controls intact.
    $dictionaryTexts = [1 => 'theme', 2 => 'running', 3 => 'the', 4 => 'theme',
        5 => null, 6 => 'runner', 7 => 'żółty', 8 => 'running theme'];
    $dictionaryInsert = $pdo->prepare('UPDATE eng2114_prefix SET dictionary_text = ? WHERE id = ?');
    foreach ($dictionaryTexts as $id => $text) {
        $dictionaryInsert->execute([$text, $id]);
    }
    $alias = '"' . Query::DEFAULT_ALIAS . '"';
    $run = function (string $name, array $queries, ?array $expected, array $roles = ['user:reader'], bool $unchangedControl = false) use ($pdo, $adapter, $alias, &$record, $baseline): void {
        try {
            $binds = [];
            $condition = $adapter->getSQLConditions($queries, $binds);
            $permission = $adapter->permissionPredicate($roles);
            $sql = "SELECT id FROM eng2114_prefix AS {$alias} WHERE ({$condition}) AND ({$permission}) ORDER BY id";
            $statement = $pdo->prepare($sql);
            $statement->execute($binds);
            $actual = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
            $record['checks'][$name] = $actual;
            if ($unchangedControl) {
                $record['controls'][$name] = $actual;
                if ($baseline !== null) {
                    need(array_key_exists($name, $baseline['controls']) && $actual === $baseline['controls'][$name], 'Existing non-prefix semantics changed');
                }
            }
            if ($expected !== null) {
                need($actual === $expected, 'Actual SQL result differs from expected exact IDs');
            }
        } catch (Throwable $error) {
            $record['failures'][$name] = get_class($error) . ': ' . $error->getMessage();
        }
    };
    $run('complete_word', [Query::search('text', 'zolty')], [1, 2, 6], unchangedControl: true);
    $run('three_character_prefix', [Query::search('text', 'zol*')], [1, 2, 6]);
    $run('four_character_prefix', [Query::search('text', 'zolt*')], [1, 2, 6]);
    $run('second_partial_word', [Query::search('text', 'smo*')], [1, 3, 6, 7]);
    $run('nonmatching_prefix', [Query::search('text', 'absentprefix*')], []);
    $run('unicode_token_prefix', [Query::search('text', 'żół*')], [7]);
    $run('numeric_token_prefix', [Query::search('text', '123*')], [8]);
    $run('cyrillic_prefix', [Query::search('text', 'доб*')], [9, 15]);
    $run('greek_prefix', [Query::search('text', 'καλ*')], [10, 15]);
    $run('arabic_numeric_prefix', [Query::search('text', '١٢٣*')], [11]);
    $run('mixed_numeric_prefix', [Query::search('text', 'a١٢٣*')], [12]);
    $run('mixed_numeric_mismatch', [Query::search('text', 'a١٢٤*')], []);
    $run('numeric_interior_is_not_prefix', [Query::search('text', '٢٣*')], []);
    $run('letter_number_prefix', [Query::search('text', 'Ⅷal*')], [13]);
    $run('other_number_prefix', [Query::search('text', '²be*')], [13]);
    $run('letter_number_is_not_boundary', [Query::search('text', 'alpha*')], [14]);
    $run('other_number_is_not_boundary', [Query::search('text', 'beta*')], []);
    $run('punctuation_and_underscore_boundary', [Query::search('text', 'boundary*')], [15]);
    $run('not_search_complement_excludes_null', [Query::notSearch('text', 'zol*')], [3, 7, 8, 9, 10, 11, 12, 13, 14, 15]);
    $run('two_prefixes_and', [Query::and([Query::search('text', 'zol*'), Query::search('text', 'smo*')])], [1, 6]);
    $run('prefix_token_range_conjunction', [Query::and([
        Query::search('text', 'zol*'), Query::search('text', 'smo*'),
        Query::search('tokens', '"' . $category . '"'), Query::search('tokens', '"' . $number . '"'),
        Query::greaterThanEqual('n', 10), Query::lessThanEqual('n', 15),
    ])], [1, 6]);
    $run('quoted_phrase_unchanged', [Query::search('text', '"zolty smok"')], null, unchangedControl: true);
    $run('quoted_category_token', [Query::search('tokens', '"' . $category . '"')], [1, 3, 6], unchangedControl: true);
    $run('quoted_numeric_token', [Query::search('tokens', '"' . $number . '"')], [1, 6], unchangedControl: true);
    $run('ordinary_multiword_unchanged', [Query::search('text', 'zolty niebieski')], null, unchangedControl: true);
    $run('boolean_or_unchanged', [Query::search('text', 'zolty OR niebieski')], null, unchangedControl: true);
    $run('ordinary_not_search_unchanged', [Query::notSearch('text', 'smok')], null, unchangedControl: true);
    $run('outsider_cannot_read_prefix_matches', [Query::search('text', 'zol*')], [], ['user:outsider']);
    $run('anonymous_without_read_role', [Query::search('text', 'zol*')], [], []);
    $run('stopword_literal_prefix', [Query::search('dictionary_text', 'the*')], [1, 3, 8]);
    $run('stem_literal_prefix', [Query::search('dictionary_text', 'runn*')], [2, 6, 8]);
    $run('stopword_not_prefix', [Query::notSearch('dictionary_text', 'the*')], [2, 6, 7]);
    $run('stem_not_prefix', [Query::notSearch('dictionary_text', 'runn*')], [1, 3, 7]);
    $run('ordinary_stemming_unchanged', [Query::search('dictionary_text', 'running')], null, unchangedControl: true);
    $run('ordinary_stopword_unchanged', [Query::search('dictionary_text', 'the')], null, unchangedControl: true);
    $run('dictionary_phrase_unchanged', [Query::search('dictionary_text', '"running theme"')], null, unchangedControl: true);
    if ($group === 'all') {
        foreach (["zol:*", "zol* | smok", "zol*' OR 1=1 --", 'zol**', '*zol', 'zo*l', 'zol* smok', '"zol*"', "zol\\*", 'zol&smok*', 'zol:*A', 'zol;DROP TABLE eng2114_prefix*', "zol*\n"] as $i => $malformed) {
            foreach ([false, true] as $negated) {
                $name = 'grammar_' . $i . ($negated ? '_not' : '_search');
                $binds = [];
                $q = $negated ? Query::notSearch('text', $malformed) : Query::search('text', $malformed);
                $sql = $adapter->getSQLConditions([$q], $binds);
                if (str_contains($sql, ' ~* ') || str_contains($sql, $malformed)) {
                    $record['failures'][$name] = 'Malformed grammar reached prefix or raw SQL branch';
                }
                $run($name, [$q], null, unchangedControl: true);
            }
        }
    }
    $binds = [];
    $condition = $adapter->getSQLConditions([Query::search('text', 'zol*')], $binds);
    $explain = $pdo->prepare("EXPLAIN (FORMAT JSON) SELECT id FROM eng2114_prefix AS {$alias} WHERE {$condition}");
    $explain->execute($binds);
    $record['query_plan'] = json_decode($explain->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
    // Record the actual plan; this tiny temporary table cannot qualify index use.
} finally {
    $pdo->rollBack();
    $record['temporary_transaction_rolled_back'] = true;
}
$record['passed'] = $record['failures'] === [];
$oldMask = umask(0077);
$out = fopen($options['output'], 'x');
need($out !== false, 'Cannot create exclusive result');
fwrite($out, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
fclose($out);
umask($oldMask);
fwrite(STDOUT, json_encode(['variant' => $variant,
    'control_group' => $group,
    'test_sha256' => hash_file('sha256', __FILE__),
    'database_locale' => $pdo->query("SELECT datcollate, datctype, datlocprovider, datlocale FROM pg_catalog.pg_database WHERE datname = current_database()")->fetch(PDO::FETCH_ASSOC), 'passed' => $record['passed'], 'failures' => array_keys($record['failures'])]) . "\n");
exit($record['passed'] ? 0 : 1);
