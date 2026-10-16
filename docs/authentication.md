# Web and mobile authentication

Admin web uses Laravel Sanctum stateful authentication. The browser first requests
`GET /sanctum/csrf-cookie`, then posts credentials and the optional `remember`
boolean to `POST /api/auth/web/login`. The response contains the user only.
Authentication is in Laravel's HttpOnly session cookie, never browser storage.
All web API requests include credentials. Mutations send the decoded `XSRF-TOKEN`
cookie in `X-XSRF-TOKEN`; this CSRF cookie is intentionally readable by JavaScript.
`POST /api/auth/web/logout` logs out, invalidates the session and rotates CSRF.
Private broadcast authorization follows the same cookie and CSRF flow.

Residents and camp managers use `POST /api/auth/login` for Sanctum personal
access tokens. Resident registration and Google login also issue tokens. Native
requests use `Authorization: Bearer <token>`, without cookie/CSRF initialization.
Mobile remembers login by default using Expo SecureStore, validates the saved
token through `/api/auth/me` on launch and retains it on temporary network errors.
Users can opt out of Remember me for an in-memory login. Logout clears the local
token and revokes the current server token; password reset revokes all tokens,
database sessions and remember-me credentials. Tokens have no automatic expiry
by default; revoked tokens require a new login. Passwords are never stored.

## Local development

Use the same hostname for web and API (`localhost` for both, or `127.0.0.1` for
both). Different ports are supported. Default stateful hosts cover Vite ports
5173 and 5174. Keep mobile preview origins out of `SANCTUM_STATEFUL_DOMAINS`.
`SESSION_SECURE_COOKIE=false` is only for local HTTP.

## HTTPS deployment

Serve the SPA and API on the same site (for example `admin.example.com` and
`api.example.com`). Configure:

```dotenv
APP_ENV=production
APP_URL=https://api.example.com
FRONTEND_URL=https://admin.example.com
SESSION_SECURE_COOKIE=true
SESSION_DOMAIN=.example.com
SESSION_SAME_SITE=lax
SESSION_EXPIRE_ON_CLOSE=true
SANCTUM_STATEFUL_DOMAINS=admin.example.com
CORS_ALLOWED_ORIGINS=https://admin.example.com
```

Session cookies always use HttpOnly. Secure defaults to true outside local/test
environments. Sanctum uses SameSite=Lax for stateful API requests; unrelated
top-level domains are not supported by this cookie flow. Configure trusted
proxies for your HTTPS infrastructure, run `php artisan config:cache` after
environment changes, and use HTTPS for the mobile API URL as well.

References: [Laravel Sanctum](https://laravel.com/docs/13.x/sanctum),
[Expo SecureStore](https://docs.expo.dev/versions/latest/sdk/securestore/).
