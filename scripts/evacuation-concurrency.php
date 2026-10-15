<?php

/** Isolated MySQL integration test. Never migrates or seeds the application database. */
use App\Models\CampAssignment;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\EvacuationPlace;
use App\Models\EvacuationRegistration;
use App\Models\Evacuee;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = isset($argv[1]) && $argv[1] === 'worker' ? json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR) : null;
config(['cache.default' => 'array', 'session.driver' => 'array', 'broadcasting.default' => 'null']);
function selectDatabase(string $name): void
{
    if (! preg_match('/^islasafe_evac_test_[a-f0-9]{12}$/', $name)) {
        throw new RuntimeException('Unsafe test database name.');
    }
    config(['database.default' => 'mysql', 'database.connections.mysql.database' => $name, 'database.connections.mysql.url' => null]);
    DB::purge('mysql');
}
if ($input) {
    selectDatabase($input['database']);
    while (microtime(true) < $input['start']) {
        usleep(1000);
    }
    $path = $input['path'] ?? '/api/camp/registrations';
    if (! in_array($path, ['/api/camp/registrations', '/api/admin/evacuation/centers'], true)) {
        throw new RuntimeException('Unexpected integration test endpoint.');
    }
    $request = Request::create($path, 'POST', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$input['token']], content: json_encode($input['body']));
    $response = $app->handle($request);
    $data = json_decode($response->getContent(), true);
    echo json_encode(['status' => $response->getStatusCode(), 'registration' => $data['data']['registration']['id'] ?? null, 'count' => $data['data']['person']['total_evacuations'] ?? null, 'message' => $data['message'] ?? null]);
    exit;
}
$mysql = config('database.connections.mysql');
$pdo = new PDO('mysql:host='.$mysql['host'].';port='.$mysql['port'].';charset=utf8mb4', $mysql['username'], $mysql['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database = 'islasafe_evac_test_'.bin2hex(random_bytes(6));
$pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try {
    selectDatabase($database);
    Artisan::call('migrate', ['--force' => true]);
    $manager = User::factory()->create(['role' => 'campmanager']);
    $token = $manager->createToken('isolated-test')->plainTextToken;
    $place = EvacuationPlace::create(['barangay' => 'Isolated place', 'municipality' => 'Test', 'canonical_key' => 'test|isolated']);
    $center = EvacuationCenter::create(['name' => 'Isolated center', 'place_id' => $place->id, 'capacity' => 20]);
    $event = EvacuationEvent::create(['name' => 'Isolated event', 'started_at' => now()]);
    DB::table('evacuation_state')->where('id', 1)->update(['active_event_id' => $event->id]);
    CampAssignment::create(['user_id' => $manager->id, 'center_id' => $center->id, 'place_ids' => [$place->id]]);
    $body = fn ($overrides = []) => [...['client_request_id' => (string) Str::uuid(), 'full_name' => 'Concurrency Test', 'place_id' => $place->id], ...$overrides];
    $parallel = function (array $bodies, string $path = '/api/camp/registrations', ?string $authToken = null) use ($database, $token) {
        $token = $authToken ?? $token;
        $processes = [];
        $start = microtime(true) + 1;
        foreach ($bodies as $body) {
            $process = proc_open([PHP_BINARY, __FILE__, 'worker'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
            fwrite($pipes[0], json_encode(compact('database', 'token', 'body', 'start', 'path')));
            fclose($pipes[0]);
            $processes[] = [$process, $pipes];
        }
        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            if (proc_close($process) !== 0) {
                throw new RuntimeException('Worker failed: '.$errors);
            }
            $results[] = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        }

        return $results;
    };
    $check = function (bool $condition, string $message) {
        if (! $condition) {
            throw new RuntimeException($message);
        } echo "PASS: $message\n";
    };
    $same = $body();
    $results = $parallel([$same, $same]);
    $statuses = array_column($results, 'status');
    sort($statuses);
    $check($statuses === [200, 201] && Evacuee::count() === 1 && EvacuationRegistration::count() === 1 && count(array_unique(array_column($results, 'registration'))) === 1, 'Concurrent identical draft produces one person and one registration');
    $person = Evacuee::first();
    $results = $parallel([$body(['evacuee_id' => $person->id]), $body(['evacuee_id' => $person->id])]);
    $check(array_column($results, 'status') === [200, 200] && array_column($results, 'count') === [1, 1] && EvacuationRegistration::count() === 1, 'Concurrent known-person requests retain one event count');
    $known = Evacuee::create(['full_name' => 'Known unregistered identity', 'place_id' => $place->id]);
    $results = $parallel([$body(['evacuee_id' => $known->id]), $body(['evacuee_id' => $known->id])]);
    $statuses = array_column($results, 'status');
    sort($statuses);
    $check($statuses === [200, 201] && array_column($results, 'count') === [1, 1] && EvacuationRegistration::count() === 2, 'Concurrent first registration of a known person produces one event record');
    $center->update(['capacity' => 3]);
    $results = $parallel([$body(['full_name' => 'Second person']), $body(['full_name' => 'Third person'])]);
    $statuses = array_column($results, 'status');
    sort($statuses);
    $check($statuses === [201, 409] && Evacuee::count() === 3 && EvacuationRegistration::where('status', 'checked_in')->count() === 3, 'Concurrent capacity race never overfills center or leaves a failed identity');
    $admin = User::factory()->create(['role' => 'admin']);
    $adminToken = $admin->createToken('isolated-center-test')->plainTextToken;
    $newCenter = ['name' => 'Concurrent facility', 'place_id' => $place->id, 'capacity' => 50, 'latitude' => 10.12, 'longitude' => 124.56];
    $results = $parallel([$newCenter, $newCenter], '/api/admin/evacuation/centers', $adminToken);
    $statuses = array_column($results, 'status');
    sort($statuses);
    $check($statuses === [201, 422] && EvacuationCenter::count() === 2, 'Concurrent center creation preserves the database unique facility constraint');
    $newManager = User::factory()->create(['role' => 'campmanager']);
    $results = $parallel([[...$newCenter, 'name' => 'Assignment race A', 'camp_manager_id' => $newManager->id], [...$newCenter, 'name' => 'Assignment race B', 'camp_manager_id' => $newManager->id]], '/api/admin/evacuation/centers', $adminToken);
    $statuses = array_column($results, 'status');
    sort($statuses);
    $check($statuses === [201, 422] && EvacuationCenter::count() === 3 && CampAssignment::where('user_id', $newManager->id)->count() === 1, 'Competing manager assignments commit one center and roll back the other');
} finally {
    DB::disconnect('mysql');
    $pdo->exec("DROP DATABASE `$database`");
    echo "Isolated test database removed.\n";
}
