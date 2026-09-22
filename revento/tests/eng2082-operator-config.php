<?php

$root = getenv('REVENTO_APPWRITE_SOURCE') ?: '/usr/src/code';
require $root . '/vendor/autoload.php';
\Utopia\Config\Config::setParam('runtimes', []);
$groups = require $root . '/app/config/variables.php';
$matches = [];
foreach ($groups as $group) {
    foreach ($group['variables'] as $variable) {
        if ($variable['name'] === '_APP_LIMIT_DATABASE_TRANSACTION') {
            $matches[] = $variable;
        }
    }
}
if (count($matches) !== 1 || $matches[0]['default'] !== '100' || $matches[0]['required'] !== false) {
    throw new RuntimeException('Transaction setting must have one optional installer entry with default100');
}
echo json_encode(['passed' => true, 'installer_default' => $matches[0]['default']]) . "\n";
