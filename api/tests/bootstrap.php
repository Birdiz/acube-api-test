<?php

use App\Tests\Api\Fixture\SampleFile;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// The container runs with APP_ENV=dev in its real environment, and Symfony
// reads $_ENV before $_SERVER. Without this, phpunit.dist.xml's forced
// APP_ENV=test would be quietly overruled and the suite would boot the dev
// kernel. Mirror it across all three sources before Dotenv looks at them.
foreach (['APP_ENV' => 'test', 'APP_DEBUG' => '0'] as $key => $default) {
    $value = $_SERVER[$key] ?? $default;

    $_ENV[$key] = $_SERVER[$key] = $value;
    putenv($key.'='.$value);
}

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Start every run from an empty fixture directory. Data providers build their
// sample files while the suite is loading, so this is the only safe moment to
// clear it.
SampleFile::cleanUp();
