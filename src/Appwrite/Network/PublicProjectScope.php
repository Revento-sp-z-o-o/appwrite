<?php

namespace Appwrite\Network;

use Appwrite\Extend\Exception;

/**
 * A restriction added by a public reverse proxy, never an authorization grant.
 * The proxy must overwrite this header and keep direct upstream access private.
 */
final class PublicProjectScope
{
    public const string HEADER = 'x-appwrite-public-project';

    public static function project(string $scope, string $projectId): void
    {
        if ($scope === '') {
            return;
        }

        if ($scope === 'console' || $projectId === '' || $scope !== $projectId) {
            throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN);
        }
    }

    public static function mode(string $scope, mixed $mode): void
    {
        if ($scope !== '' && $mode !== 'default') {
            throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN);
        }
    }
}
