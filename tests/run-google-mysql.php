<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Runs only against a new disposable database, using existing local credentials.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if ($app->configurationIsCached()) {
    throw new RuntimeException('Run php artisan config:clear before isolated database tests.');
}
$connection = config('database.connections.mysql');
$originalDatabase = $connection['database'];
$database = 'islasafe_auth_test_'.bin2hex(random_bytes(6));
if (! preg_match('/^islasafe_auth_test_[a-f0-9]{12}$/', $database) || $database === $originalDatabase) {
    throw new RuntimeException('Invalid disposable database name.');
}
$connection['database'] = null;
config(['database.connections.auth_verify_server' => $connection]);
$server = DB::connection('auth_verify_server');
$server->statement('CREATE DATABASE '.$database.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$exitCode = 1;
try {
    $environment = array_merge(getenv(), [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $database,
        'DB_URL' => '', 'DB_HOST' => (string) $connection['host'],
        'DB_PORT' => (string) $connection['port'], 'DB_USERNAME' => (string) $connection['username'],
        'DB_PASSWORD' => (string) $connection['password'],
        'DB_SOCKET' => (string) ($connection['unix_socket'] ?? ''),
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array',
    ]);
    $process = proc_open([PHP_BINARY, 'vendor/phpunit/phpunit/phpunit',
        '--filter', 'GoogleAuthTest|GoogleSignupConcurrencyTest', '--testdox'],
        [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, base_path(), $environment);
    if (! is_resource($process)) {
        throw new RuntimeException('Could not start isolated MySQL tests.');
    }
    fclose($pipes[0]);
    $exitCode = proc_close($process);
} finally {
    // This exact name was generated and created above; no existing DB is dropped.
    $server->statement('DROP DATABASE '.$database);
}
exit($exitCode);
