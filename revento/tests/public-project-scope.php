<?php

// Run inside the packaged Swoole runtime, without a database or live server.
$root = getenv('REVENTO_APPWRITE_SOURCE') ?: '/usr/src/code';
require $root . '/vendor/autoload.php';

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Request;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\DI\Container;
use Utopia\Http\Http;
use Utopia\Http\RouteMatch;

Config::setParam('errors', require $root . '/app/config/errors.php');
define('APP_MODE_DEFAULT', 'default');
define('APP_MODE_ADMIN', 'admin');
$http = new class () extends Http {
    public function __construct()
    {
    }
    public function match(\Utopia\Http\Request $request): ?RouteMatch
    {
        return null;
    }
};
$db = new class () {
    public int $reads = 0;
    public function setAuthorization(Authorization $authorization): void
    {
    }
    public function getDocument(string $collection, string $id): Document
    {
        $this->reads++;
        return new Document(['$id' => $id]);
    }
};
$cases = [
    ['OPTIONS', '/v1/account?project=dev2', '', '', 'mode', true],
    ['GET', '/v1/account', '', '', 'project', false],
    ['GET', '/v1/account?project=dev2', '', '', 'project', true],
    ['POST', '/v1/account', 'application/json', '{"project":"dev2"}', 'project', true],
    ['POST', '/v1/account', 'application/json', '{"project":"console"}', 'project', true],
    ['POST', '/v1/account', 'application/x-www-form-urlencoded', 'project=dev2', 'project', true],
    ['POST', '/v1/account', 'multipart/form-data; boundary=test', "--test\r\nContent-Disposition: form-data; name=\"project\"\r\n\r\ndev2\r\n--test--\r\n", 'project', true],
    ['POST', '/v1/account', 'application/json', '{"mode":"admin"}', 'mode', true],
    ['GET', '/v1/account?mode=admin', '', '', 'mode', true],
];
$checks = 0;
foreach ($cases as [$method, $path, $contentType, $body, $resource, $reject]) {
    $raw = "$method $path HTTP/1.1\r\nHost: private.test\r\nX-Appwrite-Project: production\r\nX-Appwrite-Public-Project: production\r\n";
    if ($contentType !== '') {
        $raw .= "Content-Type: $contentType\r\n";
    }
    $raw .= 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body;
    $swoole = \Swoole\Http\Request::create();
    $swoole->parse($raw);
    if (!$swoole->isCompleted()) {
        throw new RuntimeException('Request parser incomplete');
    }
    $request = new Request($swoole);
    $container = new Container();
    (require $root . '/app/init/resources/request.php')($container);
    foreach (['request' => $request, 'console' => new Document(['$id' => 'console']), 'dbForPlatform' => $db, 'authorization' => new Authorization(), 'utopia' => $http, 'projectIdFromPath' => ''] as $name => $value) {
        $container->set($name, static fn () => $value);
    }
    $rejected = false;
    try {
        $container->get('mode');
    } catch (Exception $e) {
        if ($e->getType() !== Exception::GENERAL_ACCESS_FORBIDDEN) {
            throw $e;
        }
        $rejected = true;
    }
    if ($rejected !== $reject) {
        throw new RuntimeException('Unexpected public boundary result at case ' . $checks);
    }
    // Rejection must leave project resolution reusable by error hooks.
    $container->get('project');
    $checks++;
}
// The real realtime resource must not interpret the valid ID "0" as console.
function getConsoleDB(): object
{
    global $db;
    return $db;
}
$realtimeChecks = 0;
foreach (['0', ''] as $header) {
    $swoole = \Swoole\Http\Request::create();
    $swoole->parse("GET /v1/realtime?project=0 HTTP/1.1\r\nHost: private.test\r\nX-Appwrite-Public-Project: 0\r\nX-Appwrite-Project: $header\r\n\r\n");
    $request = new Request($swoole);
    $container = new Container();
    (require $root . '/app/init/realtime/connection.php')($container);
    $container->set('request', static fn () => $request);
    $container->set('console', static fn () => new Document(['$id' => 'console']));
    if ($container->get('project')->getId() !== '0') {
        throw new RuntimeException('Project zero resolved to a different context');
    }
    $realtimeChecks++;
}
echo json_encode(['passed' => true, 'parsed_http_cases' => $checks, 'realtime_zero_cases' => $realtimeChecks]) . "\n";
