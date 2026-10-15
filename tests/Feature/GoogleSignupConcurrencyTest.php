<?php

namespace Tests\Feature;

use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class GoogleSignupConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_simultaneous_google_signups_resolve_to_one_resident(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! preg_match('/^islasafe_auth_test_[a-f0-9]{12}$/', DB::connection()->getDatabaseName())) {
            $this->markTestSkipped('Run php tests/run-google-mysql.php for isolated MySQL concurrency verification.');
        }
        $options = ['config' => __DIR__.'/../Fixtures/openssl.cnf', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $key = openssl_pkey_new($options);
        openssl_pkey_export($key, $privateKey, null, $options);
        $details = openssl_pkey_get_details($key);
        $encode = fn ($value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $jwk = ['kty' => 'RSA', 'kid' => 'concurrent-key', 'alg' => 'RS256',
            'n' => $encode($details['rsa']['n']), 'e' => $encode($details['rsa']['e'])];
        $workers = [];
        $startAt = microtime(true) + 3;
        try {
            for ($i = 0; $i < 2; $i++) {
                $id = (string) Str::uuid();
                $nonce = hash('sha256', random_bytes(32));
                DB::table('google_auth_challenges')->insert([
                    'id' => $id, 'nonce' => $nonce, 'purpose' => 'login', 'expires_at' => now()->addMinutes(5),
                ]);
                $token = JWT::encode([
                    'iss' => 'https://accounts.google.com', 'aud' => 'test-web.apps.googleusercontent.com',
                    'iat' => time(), 'exp' => time() + 3600, 'nonce' => $nonce,
                    'sub' => 'concurrent-google-resident', 'email' => 'concurrent@example.test', 'email_verified' => true,
                ], $privateKey, 'RS256', 'concurrent-key');
                $process = proc_open([PHP_BINARY, base_path('tests/Fixtures/google-signup-worker.php')],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), getenv());
                $this->assertIsResource($process);
                fwrite($pipes[0], json_encode(['jwk' => $jwk, 'start_at' => $startAt, 'proof' => ['challenge_id' => $id, 'id_token' => $token]]));
                fclose($pipes[0]);
                $workers[] = [$process, $pipes];
            }
            $ids = [];
            $statuses = [];
            foreach ($workers as [$process, $pipes]) {
                $output = stream_get_contents($pipes[1]);
                $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $errors);
                $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
                $this->assertContains($result['status'], [200, 201], $result['message'] ?? '');
                $ids[] = $result['user_id'];
                $statuses[] = $result['status'];
            }
            $workers = [];
            sort($statuses);
            $this->assertSame([200, 201], $statuses);
            $this->assertSame($ids[0], $ids[1]);
            $this->assertSame(1, User::where('google_subject', 'concurrent-google-resident')->count());
        } finally {
            foreach ($workers as [$process]) {
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
        }
    }
}
