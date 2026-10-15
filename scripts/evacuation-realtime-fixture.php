<?php

// Persistent, in-memory fixture for the real WebSocket smoke test. No production rows are written.
use App\Models\CampAssignment;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\EvacuationPlace;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null, 'cache.default' => 'array', 'session.driver' => 'array']);
DB::purge('sqlite');
Artisan::call('migrate', ['--force' => true]);
$tokens = [];
foreach (['admin', 'campmanager', 'residents'] as $role) {
    $users[$role] = User::factory()->create(['role' => $role]);
    $tokens[$role] = $users[$role]->createToken('memory-test')->plainTextToken;
}
$place = EvacuationPlace::create(['barangay' => 'In-memory test', 'municipality' => 'Test', 'canonical_key' => 'test|memory']);
$center = EvacuationCenter::create(['name' => 'In-memory center', 'place_id' => $place->id, 'capacity' => 5]);
$other = EvacuationCenter::create(['name' => 'Unrelated center', 'place_id' => $place->id]);
$event = EvacuationEvent::create(['name' => 'In-memory event', 'started_at' => now()]);
DB::table('evacuation_state')->where('id', 1)->update(['active_event_id' => $event->id]);
CampAssignment::create(['user_id' => $users['campmanager']->id, 'center_id' => $center->id, 'place_ids' => [$place->id]]);
echo json_encode(['key' => config('broadcasting.connections.reverb.key'), 'center' => $center->id, 'other' => $other->id])."\n";
flush();
while ($line = fgets(STDIN)) {
    try {
        $command = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        $auth = $command['command'] === 'auth';
        $summary = $command['command'] === 'summary';
        $body = $summary ? [] : ($auth ? ['socket_id' => $command['socket_id'], 'channel_name' => 'private-evacuation.admin'] : ['client_request_id' => $command['request_id'], 'full_name' => 'Realtime Test', 'reported_age' => 60, 'gender' => 'other', 'place_id' => $place->id]);
        $app->make('auth')->forgetGuards();
        $role = $summary ? 'admin' : ($auth ? $command['role'] : 'campmanager');
        $request = Request::create($summary ? '/api/admin/evacuation/centers' : ($auth ? '/api/broadcasting/auth' : '/api/camp/registrations'), $summary ? 'GET' : 'POST', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.($tokens[$role] ?? 'invalid')], content: json_encode($body));
        $response = $app->handle($request);
        echo json_encode(['status' => $response->getStatusCode(), 'data' => json_decode($response->getContent(), true)])."\n";
        flush();
    } catch (Throwable $error) {
        echo json_encode(['error' => $error->getMessage()])."\n";
        flush();
    }
}
