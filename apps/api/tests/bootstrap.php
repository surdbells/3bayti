<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap.
 *
 * Loads Composer's autoloader. PHPUnit then discovers test classes
 * under the Bayti\Api\Tests\ namespace per composer.json's autoload-dev
 * mapping.
 *
 * Tests run with APP_ENV=test (set in phpunit.xml) so the Bootstrap
 * factory enables verbose error details and avoids any production
 * compilation paths.
 */

require __DIR__ . '/../vendor/autoload.php';
