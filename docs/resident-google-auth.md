# Resident Google authentication

Configure GOOGLE_WEB_CLIENT_ID in your backend .env to the Web OAuth client ID
used by the mobile app. No Google client secret is needed. Run composer install,
php artisan migrate, and php artisan config:clear when deploying these changes.

Full mobile/client setup, device acceptance checklist, and security behavior
are documented in the sibling mobile project at docs/resident-auth.md.

## API

| Method | Route | Authentication | Payload |
| --- | --- | --- | --- |
| POST | /api/auth/google/challenge | Public, throttled | None |
| POST | /api/auth/google | Public, throttled | challenge_id, id_token |
| POST | /api/auth/google/reauth-challenge | Resident Sanctum session | purpose: link or password |
| POST | /api/auth/google/link | Resident Sanctum session | challenge_id, id_token, current_password |
| POST | /api/auth/password/set | Resident Sanctum session | challenge_id, id_token, password, password_confirmation |
| PATCH | /api/auth/resident-profile | Resident Sanctum session | name, barangay, address, optional contact_number |

Challenge responses contain challenge_id, nonce (SHA-256 hex string to pass
unchanged to native Google configure), and expires_in (300 seconds).
Reauthentication challenges are bound to the current user, current Sanctum
token, and purpose. Submit the ID token immediately; never persist it.

Auth responses retain the existing token/user structure. Additional user fields
are has_local_password, google_linked, barangay, address, profile_complete, and
resident_verified. The backend role remains residents. Email verification from
Google is separate from residency verification. The public profile endpoint
cannot assign a role, provider subject, password, or verified residency.

Returning identities resolve by Google subject. A matching email alone returns
409 with instructions to authenticate and link; it never silently links.
The database has unique indexes on both email and Google subject. Signup and
link operations use transactions, locking, and unique-conflict handling.
First-password setup refuses accounts that already have a local password;
existing passwords use the existing email OTP recovery flow.

## Automated verification

~~~sh
php artisan test
php tests/run-google-mysql.php
~~~

The default suite uses SQLite in memory. The MySQL runner uses the current
local MySQL credentials to create a randomly named, isolated test database.
It runs Google identity/account tests and a real two-process concurrent-signup
test, then drops only the database it created. It requires CREATE/DROP DATABASE
permission and refuses cached application configuration. No existing application
database is reset. Without this runner, the MySQL concurrency test is skipped.

Tests generate temporary RSA keys in memory and fake Google's JWKS HTTP
response while exercising the actual signature-verification library. No real
Google identity tokens or credentials are committed.
