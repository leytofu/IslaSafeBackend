<?php

use App\Services\GoogleIdentity;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || ! preg_match('/^islasafe_auth_test_[a-f0-9]{12}$/', config('database.connections.mysql.database'))) {
    throw new RuntimeException('This worker requires an isolated authentication test database.');
}
config(['services.google.client_id' => 'test-web.apps.googleusercontent.com', 'app.debug' => false]);
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
Http::preventStrayRequests();
Http::fake([
    GoogleIdentity::CERTS_URL => Http::response(['keys' => [$input['jwk']]]),
]);
// Both workers reach the request at the same time after bootstrapping.
while (microtime(true) < $input['start_at']) {
    usleep(1000);
}
$request = Request::create('/api/auth/google', 'POST', [], [], [], [
    'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
], json_encode($input['proof']));
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);
$body = json_decode($response->getContent(), true);
echo json_encode(['status' => $response->getStatusCode(), 'user_id' => $body['user']['id'] ?? null, 'message' => $body['message'] ?? null]);
$kernel->terminate($request, $response);
