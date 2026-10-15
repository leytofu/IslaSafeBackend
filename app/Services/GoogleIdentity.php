<?php

namespace App\Services;

use Firebase\JWT\JWK;
use Google\Auth\OAuth2;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class GoogleIdentity
{
    public const CERTS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    public function verify(string $token): array
    {
        $clientId = config('services.google.client_id');
        abort_unless(is_string($clientId) && $clientId !== '', 503, 'Google sign-in is not configured. Use email and your IslaSafe password.');
        try {
            $keys = Cache::remember('google.identity.keys', 3600, fn () => Http::timeout(10)->get(self::CERTS_URL)->throw()->json());
            // A new kid can appear during Google's key rotation. Refresh once.
            $header = json_decode(base64_decode(strtr(explode('.', $token)[0], '-_', '+/')), true);
            if (! in_array($header['kid'] ?? null, array_column($keys['keys'] ?? [], 'kid'), true)) {
                Cache::forget('google.identity.keys');
                $keys = Cache::remember('google.identity.keys', 3600, fn () => Http::timeout(10)->get(self::CERTS_URL)->throw()->json());
            }
        } catch (\Throwable) {
            abort(503, 'Google verification is temporarily unavailable. Please try again.');
        }
        try {
            $oauth = new OAuth2(['audience' => $clientId]);
            $oauth->setIdToken($token);
            $claims = (array) $oauth->verifyIdToken(JWK::parseKeySet($keys, 'RS256'));
            if (! in_array($claims['iss'] ?? null, ['accounts.google.com', 'https://accounts.google.com'], true)
                || ! is_int($claims['exp'] ?? null) || $claims['exp'] <= time()
                || ! is_int($claims['iat'] ?? null) || $claims['iat'] > time() + 30
                || ($claims['email_verified'] ?? false) !== true
                || ! is_string($claims['email'] ?? null) || ! filter_var($claims['email'], FILTER_VALIDATE_EMAIL)
                || strlen($claims['email']) > 255
                || ! is_string($claims['sub'] ?? null) || $claims['sub'] === '' || strlen($claims['sub']) > 255
                || ! is_string($claims['nonce'] ?? null)) {
                throw new \UnexpectedValueException;
            }

            return $claims;
        } catch (\Throwable) {
            throw ValidationException::withMessages(['id_token' => 'Google sign-in could not be verified. Please continue with Google again.']);
        }
    }
}
