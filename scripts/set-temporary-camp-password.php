<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment(['local', 'testing'])) {
    throw new RuntimeException('The temporary demo password is available only in local/testing environments.');
}
$id = DB::transaction(function () {
    $user = User::where('email', 'camp@islasafe.test')->lockForUpdate()->first();
    if ($user && $user->role !== User::ROLE_CAMP_MANAGER) {
        throw new RuntimeException('The demo email belongs to a different role. No account was changed.');
    }
    $user ??= new User(['name' => 'Evacuation Camp Manager', 'email' => 'camp@islasafe.test', 'role' => User::ROLE_CAMP_MANAGER]);
    $user->password = Hash::make('TempCamp123!');
    $user->save();
    $user->tokens()->delete();

    return $user->id;
});
echo "Temporary password configured for camp@islasafe.test.\n";
if (! Hash::check('TempCamp123!', User::findOrFail($id)->password)) {
    throw new RuntimeException('Password verification failed.');
}
echo "Verified password hash and Camp Manager role.\n";
