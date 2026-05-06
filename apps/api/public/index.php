<?php

declare(strict_types=1);

/**
 * 3bayti API entry point.
 *
 * Every HTTP request to the API enters here. We bootstrap the Slim app
 * via the factory in src/Bootstrap.php — that's where the DI container,
 * routes, middleware, and error handling are wired.
 *
 * This file stays tiny on purpose. All the interesting stuff is in the
 * factory so it can be unit-tested without an HTTP server in front of it.
 */

use Bayti\Api\Bootstrap;

require __DIR__ . '/../vendor/autoload.php';

$app = Bootstrap::createApp();
$app->run();
