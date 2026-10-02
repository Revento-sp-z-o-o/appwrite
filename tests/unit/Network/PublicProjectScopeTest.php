<?php

namespace Tests\Unit\Network;

use Appwrite\Extend\Exception;
use Appwrite\Network\PublicProjectScope;
use Appwrite\Utopia\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\DI\Container;
use Utopia\Http\Http;
use Utopia\Http\Route;
use Utopia\Http\RouteMatch;

final class PublicProjectScopeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        Config::setParam('errors', require __DIR__.'/../../../app/config/errors.php');
    }

    public function testPrivateRequestsRetainConsoleAndAdminAccess(): void
    {
        PublicProjectScope::project('', 'console');
        PublicProjectScope::project('', 'dev2');
        PublicProjectScope::mode('', 'admin');
        $this->addToAssertionCount(1);
    }

    public function testPublicRequestsRetainMatchingProjectClientAccess(): void
    {
        PublicProjectScope::project('production', 'production');
        PublicProjectScope::project('0', '0');
        PublicProjectScope::mode('production', 'default');
        $this->addToAssertionCount(1);
    }

    public static function forbiddenProjects(): array
    {
        return [
            'other project' => ['production', 'dev2'],
            'explicit console' => ['production', 'console'],
            'implicit console' => ['production', ''],
            'bad proxy console scope' => ['console', 'console'],
            'combined header value' => ['production,dev2', 'production'],
        ];
    }

    #[DataProvider('forbiddenProjects')]
    public function testRejectsProjectOutsidePublicScope(string $scope, string $project): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionCode(401);
        PublicProjectScope::project($scope, $project);
    }

    public static function forbiddenModes(): array
    {
        return [['admin'], [''], [['default']], [null], ['DEFAULT']];
    }

    #[DataProvider('forbiddenModes')]
    public function testRejectsNonClientMode(mixed $mode): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionCode(401);
        PublicProjectScope::mode('production', $mode);
    }
    public static function resourceGuards(): array
    {
        return [
            'http body project override' => ['resources/request.php', 'mode', 'dev2', 'default'],
            'http console fallback' => ['resources/request.php', 'mode', 'console', 'default'],
            'http admin mode' => ['resources/request.php', 'mode', 'production', 'admin'],
            'http inferred admin mode' => ['resources/request.php', 'mode', 'dev2', 'default'],
            'realtime other project' => ['realtime/connection.php', 'project', 'dev2', 'default'],
            'realtime console' => ['realtime/connection.php', 'project', 'console', 'default'],
            'realtime admin mode' => ['realtime/connection.php', 'user', 'production', 'admin'],
            'http legacy alias' => ['resources/request.php', 'mode', 'production', 'default', 'dev2'],
        ];
    }

    #[DataProvider('resourceGuards')]
    public function testResourceRejectsPublicContext(string $file, string $resource, string $project, string $mode, string $pathProject = ''): void
    {
        if (!defined('APP_MODE_DEFAULT')) {
            define('APP_MODE_DEFAULT', 'default');
            define('APP_MODE_ADMIN', 'admin');
        }
        $request = $this->createStub(Request::class);
        $request->method('getHeaderLine')->willReturnCallback(static fn ($name, $default = '') => match ($name) {
            PublicProjectScope::HEADER => 'production',
            'x-appwrite-project' => str_starts_with($file, 'realtime/') ? $project : 'production',
            default => $default,
        });
        $request->method('getParam')->willReturnCallback(static fn ($name, $default = null) => match ($name) {
            'project' => $project,
            'mode' => $mode,
            default => $default,
        });
        $container = new Container();
        $register = require __DIR__.'/../../../app/init/'.$file;
        $register($container);
        $http = $this->createStub(Http::class);
        $http->method('match')->willReturn($pathProject === '' ? null : new RouteMatch(new Route('GET', '/v1/databases'), ['projectId' => $pathProject]));
        foreach ([
            'request' => $request,
            'console' => new Document(['$id' => 'console']),
            'authorization' => new Authorization(),
            'utopia' => $http,
            'projectIdFromPath' => $pathProject,
            'dbForPlatform' => new class () {
                public function getDocument(string $collection, string $id): Document
                {
                    return new Document(['$id' => $id]);
                }
            },
        ] as $name => $value) {
            $container->set($name, static fn () => $value);
        }
        if ($resource === 'user') {
            $container->set('project', static fn () => new Document(['$id' => 'production']));
        }
        $this->expectException(Exception::class);
        $this->expectExceptionCode(str_starts_with($file, 'realtime/') ? 1008 : 401);
        $container->get($resource);
    }
}
