<?php

// ENG-2082: load the actual Appwrite bootstrap in separate processes because its
// constants and System environment cache cannot be reset within one process.
if (($argv[1] ?? '') === 'check') {
    $root = getenv('REVENTO_APPWRITE_SOURCE') ?: '/usr/src/code';
    require getenv('REVENTO_APPWRITE_AUTOLOAD') ?: $root . '/vendor/autoload.php';
    spl_autoload_register(static function (string $class) use ($root): void {
        if (str_starts_with($class, 'Appwrite\\')) {
            $file = $root . '/src/' . str_replace('\\', '/', $class) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    });
    require $root . '/app/init/constants.php';
    $expected = (int) $argv[2];
    if (APP_LIMIT_DATABASE_TRANSACTION !== $expected) {
        throw new RuntimeException('ENG-2082: expected transaction limit ' . $expected . ', got ' . APP_LIMIT_DATABASE_TRANSACTION);
    }
    exit(0);
}

$checks = [];
foreach ([
    'default' => [null, 100],
    'pro-capacity' => ['1000', 1000],
    'custom-capacity' => ['454', 454],
    'minimum' => ['1', 1],
    'zero-default' => ['0', 100],
    'empty-default' => ['', 100],
    'negative-clamped' => ['-1', 1],
    'invalid-clamped' => ['invalid', 1],
] as $name => [$value, $expected]) {
    $environment = getenv();
    unset($environment['_APP_LIMIT_DATABASE_TRANSACTION']);
    if ($value !== null) {
        $environment['_APP_LIMIT_DATABASE_TRANSACTION'] = $value;
    }
    $process = proc_open([PHP_BINARY, __FILE__, 'check', (string) $expected], [
        0 => ['file', '/dev/null', 'r'],
        1 => STDOUT,
        2 => STDERR,
    ], $pipes, env_vars: $environment);
    if (!is_resource($process) || proc_close($process) !== 0) {
        throw new RuntimeException('ENG-2082 scenario failed: ' . $name);
    }
    $checks[] = $name;
}
echo json_encode(['passed' => true, 'checks' => $checks]) . "\n";
