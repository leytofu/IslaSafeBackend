<?php

// Configure local development only. Production requires a TLS reverse proxy and real allowed origins.
$backend = dirname(__DIR__);
$frontend = dirname($backend).'/frontend';
$environment = file_get_contents($backend.'/.env');
$read = function (string $key) use ($environment) {
    return preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $environment, $match) ? trim($match[1], "\r\n\"'") : '';
};
if ($read('APP_ENV') === 'production') {
    throw new RuntimeException('This script configures local development only. Configure production Reverb and TLS explicitly.');
}
$key = $read('REVERB_APP_KEY') ?: bin2hex(random_bytes(16));
$secret = $read('REVERB_APP_SECRET') ?: bin2hex(random_bytes(32));
$write = function (string $path, array $values) {
    $content = file_exists($path) ? file_get_contents($path) : '';
    foreach ($values as $name => $value) {
        $line = $name.'='.$value;
        $pattern = '/^'.preg_quote($name, '/').'=.*$/m';
        $content = preg_match($pattern, $content) ? preg_replace_callback($pattern, fn () => $line, $content) : rtrim($content).PHP_EOL.$line.PHP_EOL;
    }
    file_put_contents($path, $content);
};
$write($backend.'/.env', ['BROADCAST_CONNECTION' => 'reverb', 'REVERB_APP_ID' => 'islasafe-local', 'REVERB_APP_KEY' => $key, 'REVERB_APP_SECRET' => $secret, 'REVERB_HOST' => '127.0.0.1', 'REVERB_PORT' => '8080', 'REVERB_SCHEME' => 'http', 'REVERB_SERVER_HOST' => '127.0.0.1', 'REVERB_SERVER_PORT' => '8080', 'REVERB_ALLOWED_ORIGINS' => 'localhost,127.0.0.1']);
$write($frontend.'/.env', ['VITE_REVERB_APP_KEY' => $key, 'VITE_REVERB_HOST' => 'localhost', 'VITE_REVERB_PORT' => '8080', 'VITE_REVERB_SCHEME' => 'http']);
echo "Local Reverb configured. Restart backend, frontend and Reverb processes. No credentials were printed.\n";
