# IslaSafe evacuation operations implementation

The Camp Manager mobile dashboard and Admin Evacuation Centers grid now use Laravel database records. Registration creates an event-based history record, updates occupancy and demographic statistics, and broadcasts an update after commit. No example residents, centers, or events were inserted into the application database.

UI update: Operations setup has been removed from the web Evacuation Centers page at the user's request. The grid, center actions, demographics, and live updates remain. Setup features described below retain their backend endpoints, but their panel is no longer shown on this page.

See [Evacuation center management and GIS synchronization](EVACUATION_CENTER_MANAGEMENT.md) for the current Add Center, manager assignment, editing/deactivation, and Resident/Admin map workflow and its verification results.

## Inspection and approach

Inspected the Expo mobile routes and placeholders, the React admin grid and existing map picker, Laravel APIs and authorization, models, migrations, and authentication. The web application is in `frontend/`; there is no `web/` application directory. There were no evacuation tables, registration endpoints, or working WebSocket infrastructure. The admin grid previously used sample center data.

The implementation reuses Expo Router, React Native Reanimated, existing mobile components and secure storage, React/Vite, Laravel, Sanctum, and MySQL. Laravel Reverb and Laravel Echo/Pusher provide the required live transport. Existing GIS sample consumers were left intact; the Evacuation Centers page uses the live API.

## Completed behavior

- Camp dashboard: assigned station and active event, total registrations, first-time and returning evacuees, current occupancy/capacity, recent registrations, prominent registration action, refresh, and accessible reduced-motion entry animation.
- Smart registration: debounced, authorized identity search; identifying barangay/age/unique ID hints; profile selection and autofill; actual previous event count and latest evacuation; DOB/reported age, gender, canonical residence, and optional purok. People with identical names remain separate identities.
- Registration confirmation: server-generated time, server-authorized center/event/manager, actual history count, and existing-registration details when a duplicate is detected. Registration succeeds only after a confirmed backend response.
- Secure drafts: saved by manager account in the existing vault. Interrupted submissions retain their UUID and unchanged payload for safe retry. Sensitive drafts are not placed in ordinary AsyncStorage. Backend operations require verified authentication and connectivity.
- Roster: search, status filters, pagination, check-in/checkout at the assigned center, loading/error feedback, foreground refresh, and periodic reconciliation while focused.
- Admin grid: real per-center registration/occupancy/capacity/available-space/status/activity values, pagination, and search.
- Admin details: age, all supported gender categories, barangay/purok breakdowns, first-time/returning totals, center event history count, occupancy percentage, searchable/paginated roster, capacity/contact/status controls, and legitimate transfers. Destination lookup searches all centers rather than only the currently visible grid page.
- Admin operations setup: canonical places, real centers through the existing map picker, open/close evacuation event, assignments of existing Camp Manager accounts and authorized places, and linking a resident account to its demographic/residence profile.

Existing resident accounts do not contain structured demographic/residence fields. Admin must link those accounts to a resident profile with a canonical place before they appear in a manager's scoped search. This prevents disclosure of an unscoped resident directory and avoids guessing identity from a name.

## Database changes

Applied `database/migrations/2026_10_14_200000_create_evacuation_operations.php` to the local MySQL database (batch 5).

| Table | Purpose |
| --- | --- |
| `evacuation_places` | Canonical barangay/municipality and unique normalized place key |
| `evacuation_centers` | Place, capacity, operational status, contact, coordinates |
| `evacuation_events` | Event lifecycle and dates |
| `evacuation_state` | Locked singleton selecting the active event |
| `camp_assignments` | One assigned center per manager and authorized place IDs |
| `evacuees` | Unique person profile, optional unique link to an existing resident account |
| `evacuation_registrations` | Unique person/event record, UUID retry key, center/manager/time/status and demographic snapshots |
| `evacuation_movements` | Registration, checkout, reentry, transfer, and event-closure journal |

Unique `(evacuee_id, event_id)` and unique `client_request_id` constraints prevent duplicate records. Transactions and locking reads serialize person/center mutations and capacity checks, with deadlock retries. Foreign keys preserve historical references. Name/place and occupancy indexes support search and statistics.

The existing role migration's rollback now drops its index before dropping the role column; this fixes SQLite migration rollback in isolated tests without changing the deployed role schema.

History counts distinct events. Occupancy and demographic breakdowns count **checked-in registrations in the active event only**. Transfers move the existing registration and journal the movement, so they add neither a second person nor another evacuation event. The source center retains its movement history. Event closure checks out occupants and retains registrations.

`registered`, `first_time`, and `returning` refer to active-event registrations currently associated with that center, including checked-out people; transfers change that association. `current` refers only to checked-in occupants. Historical facility event totals include centers visited through transfers.

## Authorization and endpoints

All paths below have the `/api` prefix and require Sanctum authentication. Camp routes additionally require `campmanager`, an assignment, and scoped record access. Camp search is restricted to authorized places or records associated with the assigned center. Arbitrary submitted center/event/manager IDs, timestamps, statuses, and resident account links are rejected during registration.

| Role | Method and endpoint | Behavior |
| --- | --- | --- |
| Camp | `GET /camp/context` | Assigned center, current statistics, event, places, recent registrations |
| Camp | `GET /camp/people?q=...` | Debounced identity hints; up to 15 matches |
| Camp | `GET /camp/people/{id}` | Selected identity and actual history; latest 50 records and full event count |
| Camp | `GET /camp/registrations` | Paginated assigned-center roster; query/status/place filters |
| Camp | `POST /camp/registrations` | Transactional registration or existing receipt |
| Camp | `PATCH /camp/registrations/{id}` | Assigned-center check-in/checkout |
| Admin | `GET /admin/evacuation/setup` | Real places, events, existing managers, assignments, gender options |
| Admin | `GET, POST /admin/evacuation/centers` | Paginated search or center creation |
| Admin | `GET, PATCH /admin/evacuation/centers/{id}` | Statistics or capacity/status/contact changes |
| Admin | `GET /admin/evacuation/centers/{id}/registrations` | Paginated searchable/filterable roster |
| Admin | `PATCH /admin/evacuation/registrations/{id}` | Check-in, checkout, or transfer |
| Admin | `POST /admin/evacuation/places` | Canonical place upsert |
| Admin | `POST /admin/evacuation/assignments` | Assign existing manager and authorized places |
| Admin | `POST /admin/evacuation/events` | Open an event when none is active |
| Admin | `POST /admin/evacuation/events/{id}/close` | Close event and preserve its history |
| Admin | `GET, POST /admin/evacuation/residents` | Search resident accounts or link a demographic profile |
| Admin | `POST /broadcasting/auth` | Authenticate the private live-update subscription |

SOS listing/status-management endpoints are now Admin-only, as requested. Public emergency submission and existing Resident SOS functionality remain available. Camp Managers cannot create accounts, modify another center, transfer people, or access Admin rescue management through these APIs.

## Live synchronization

`EvacuationCenterUpdated` is emitted through `DB::afterCommit`. Its private `evacuation.admin` channel admits Admin users only. Payload contains `center_id`, `event_id`, and `changed_at`; it contains no personal information. The web hook retrieves authoritative statistics for the affected card. Transfers update source and destination cards.

One Echo instance/subscription is maintained per authenticated page, cleaned up on exit. Search/page changes do not create new subscriptions. Subscription success and reconnect trigger reconciliation. Targeted requests guard against stale responses. Visible admin pages also refresh every 15 seconds and on foreground/network restoration, with manual refresh. Camp context/roster reconcile every 20 seconds while focused/foregrounded. Broadcast failure does not turn a committed registration into a reported failure.

Local Reverb credentials were generated into ignored `.env` files, and local Reverb is configured for localhost origins. Production requires environment-specific Reverb credentials, TLS/proxy configuration, allowed origins, and a supervised server process. The local configurator refuses `APP_ENV=production`.

## Files created or modified for this feature

Paths are relative to their application directories.

**Backend:**

- Created `app/Http/Controllers/Api/EvacuationController.php`, `app/Services/EvacuationOperations.php`, `app/Services/EvacuationDemographics.php`, `app/Events/EvacuationCenterUpdated.php`.
- Created models `CampAssignment`, `EvacuationCenter`, `EvacuationEvent`, `EvacuationMovement`, `EvacuationPlace`, `EvacuationRegistration`, `Evacuee`; added relationships to `app/Models/User.php`.
- Created the evacuation migration, `routes/channels.php`, and `config/broadcasting.php`, `config/reverb.php`, `config/cors.php`.
- Modified `routes/api.php`, `bootstrap/app.php`, the role rollback migration, `.env.example`, `composer.json`, and `composer.lock`.
- Created `tests/Feature/EvacuationTest.php`; updated rescue authorization expectations in `tests/Feature/SosRequestTest.php`.
- Created `scripts/configure-local-reverb.php`, `scripts/evacuation-concurrency.php`, `scripts/evacuation-realtime-fixture.php`, `scripts/evacuation-realtime-smoke.mjs`, and this guide.

**Mobile:**

- Created `src/lib/camp.ts`, `src/lib/demographics.ts`, `src/hooks/useCampContext.ts`, `src/components/CampOverview.tsx`.
- Created `src/screens/CampRegistrationScreen.tsx`, `src/screens/CampRosterScreen.tsx`, `src/app/camp/register.tsx`, and `tests/camp.test.ts`.
- Updated `src/app/camp/overview.tsx`, `center.tsx`, `evacuees.tsx`, `alerts.tsx`, and `_layout.tsx`.

**Frontend:**

- Created `src/types/evacuation.ts`, `src/hooks/useEvacuationCenters.ts`, and components `LiveEvacuationCenterCard.tsx`, `EvacuationCenterDetail.tsx`, `EvacuationCenterLookup.tsx`, `EvacuationSetup.tsx`.
- Updated `src/pages/EvacuationCentersPage.tsx` and its CSS, `src/components/AddEvacuationCenterDialog.tsx`, `src/data/evacuationCenters.ts` (optional canonical place ID), `src/App.tsx`, `.env.example`, and package/lock files.

Added Laravel Reverb 1.12, Laravel Echo, and Pusher JS. Composer selected compatible Laravel 13.36/Guzzle dependencies; CommonMark and source-map-js were updated to resolve reported dependency advisories. No new mobile native dependencies were added.

## Verification and remaining limits

Verified:

- Full Laravel suite: **70 passed, 310 assertions**. Includes 19 evacuation tests for identity, history, role/center scope, expired tokens, unique constraints, capacity/rollback, transfers, DOB boundaries, canonical places, demographic/place statistics, and broadcast outage behavior. Existing authentication, password recovery, profile, and SOS tests pass.
- Real MySQL concurrent workers: four scenarios pass—same draft, already-registered identity, simultaneous first registration of a known identity, and competing requests for the last space. Tests create and remove an isolated database; production rows are not seeded.
- Real Reverb socket: Admin-only subscription, committed event delivery, correct affected center, no personal payload, REST reconciliation of missed updates, and reconnection pass. Fixture records live only in an in-memory SQLite database.
- Mobile typecheck/lint pass; **21 mobile tests** pass, including age boundaries and invalid/leap/future birthday handling. Android export succeeds.
- Frontend production build/lint pass; four existing OTP tests pass. Vite still reports a large-chunk advisory.

Not verified: Android emulator touch/keyboard/layout behavior, measured animation frame rate, and actual browser rendering/poll timing. The Windows Computer Use helper returned `native pipe unavailable`, and the browser inventory exposed no enabled surfaces. Permission to use ADB for native UI verification was requested; no alternative UI automation was used without authorization. These are outstanding verification tasks, not claimed passes.

Operational setup is required before real use: record genuine places and centers, open the active event, assign each manager, and link residents whose structured residence is not yet recorded. There is no production demo-data fallback.

## Exact local run commands

Prerequisites: running MySQL, valid existing backend `.env`/application key, PHP/Composer, Node/npm, Android SDK/emulator. Use separate terminals for the four persistent servers. Run from the existing workspace; do not replace an existing `.env` with the example.

Backend API terminal:

```powershell
Set-Location C:\Users\leych\Wala_Lang\CapstoneSystem\backend
composer install
php artisan migrate
php scripts/configure-local-reverb.php
php artisan config:clear
php artisan serve --host=127.0.0.1 --port=8000
```

Reverb terminal:

```powershell
Set-Location C:\Users\leych\Wala_Lang\CapstoneSystem\backend
php artisan reverb:start --host=127.0.0.1 --port=8080
```

Admin web terminal (restart an already running Vite instance to load the Reverb environment):

```powershell
Set-Location C:\Users\leych\Wala_Lang\CapstoneSystem\frontend
npm ci
npm run dev -- --host 127.0.0.1 --port 5173
```

Android terminal:

```powershell
Set-Location C:\Users\leych\Wala_Lang\CapstoneSystem\mobile
npm ci
$env:EXPO_PUBLIC_API_BASE_URL='http://10.0.2.2:8000/api'
npm run android
```

For an already installed development build, use `npm run start -- --port 8081` and open IslaSafe in the emulator. Address `10.0.2.2` is the Android emulator's host-machine bridge; physical Android devices require a reachable LAN API address.

Verification commands:

```powershell
Set-Location C:\Users\leych\Wala_Lang\CapstoneSystem\backend
php artisan test
php scripts/evacuation-concurrency.php
node scripts/evacuation-realtime-smoke.mjs

Set-Location C:\Users\leych\Wala_Lang\CapstoneSystem\frontend
npm run build
npm run lint
node --test tests/*.test.ts

Set-Location C:\Users\leych\Wala_Lang\CapstoneSystem\mobile
npm run typecheck
npm run lint
npm test
npm run bundle:android
```

The concurrency test needs MySQL database creation/drop privileges for its isolated schema. The socket smoke test requires running Reverb and the existing mobile `ws` dependency installed by `npm ci`. The existing frontend TypeScript tests run with Node 24.

Reference documentation used: [Laravel broadcasting](https://laravel.com/framework/docs/13.x/broadcasting), [Laravel Reverb](https://laravel.com/framework/docs/13.x/reverb), and [Expo SDK 57](https://docs.expo.dev/versions/v57.0.0/). Laravel guidance was consulted through Context7.
