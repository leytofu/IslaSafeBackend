<?php

// Browser integration fixture: all requests use Laravel and an isolated in-memory DB.
use App\Models\EvacuationPlace;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null,
    'cache.default' => 'array', 'session.driver' => 'array', 'broadcasting.default' => getenv('CENTER_TEST_BROADCAST') === '1' ? 'reverb' : 'null']);
DB::purge('sqlite');
Artisan::call('migrate', ['--force' => true]);
$admin = User::factory()->create(['role' => 'admin', 'name' => 'Center Test Admin']);
$token = $admin->createToken('isolated-browser-test')->plainTextToken;
$manager = User::factory()->create(['role' => 'campmanager', 'name' => 'Camp Manager Test']);
$place = EvacuationPlace::create(['barangay' => 'Pitogo', 'municipality' => 'President Carlos P. Garcia', 'canonical_key' => 'cpg|pitogo']);
echo json_encode(['token' => $token, 'user' => $admin, 'place_id' => $place->id, 'manager_id' => $manager->id, 'key' => config('broadcasting.connections.reverb.key')])."\n";
flush();
while ($line = fgets(STDIN)) {
    try {
        $command = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        $app->make('auth')->forgetGuards();
        $request = Request::create($command['path'], $command['method'] ?? 'GET', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], content: json_encode($command['body'] ?? []));
        $response = $app->handle($request);
        echo json_encode(['status' => $response->getStatusCode(), 'body' => $response->getContent()])."\n";
        flush();
    } catch (Throwable $error) {
        echo json_encode(['status' => 500, 'body' => json_encode(['message' => $error->getMessage()])])."\n";
        flush();
    }
}
