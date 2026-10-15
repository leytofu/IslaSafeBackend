# Evacuation center management and GIS synchronization

The existing web, mobile, Laravel, migration, authorization, and GIS files were inspected before changes. The project already contained the center-management implementation; it was reused, audited, hardened, and verified. The Operations setup panel remains absent from the Evacuation Centers page.

## Available workflow

1. Admin opens **Evacuation Centers → Add Center**.
2. The responsive modal collects facility name, an existing or newly entered barangay, maximum capacity, optional contact, and exact coordinates selected by clicking/dragging the MapLibre pin. Coordinates can also be entered manually.
3. **Assign camp manager** reveals a dropdown of actual Camp Manager accounts. Managers assigned to another center are unavailable. Assignment may be completed later when no manager is available.
4. **Save Evacuation Center** persists the center and selected assignment in one Laravel transaction. Success is shown only after the backend confirms the save.
5. The center appears on the admin center-page map, dashboard overview, and full GIS map. Shelter icons show name, barangay, capacity, occupancy, availability, and status when clicked.
6. The Resident Android map reads the same facility coordinates and aggregate occupancy. Residents can tap a shelter icon for details; full centers use amber markers. The mobile map refreshes every 15 seconds while focused, on foreground/reconnection, and through manual refresh.
7. **Edit / deactivate center** opens the same modal with saved values. Admin can edit name, barangay, capacity, coordinates, contact, status, and add an available manager. Closed/unavailable facilities remain visible to Admin and are excluded from the resident feed. Existing occupants must be checked out/transferred before deactivation; capacity cannot be lowered beneath occupancy.

Existing assignments are retained unless explicitly changed through the assignment API. When a facility's barangay changes, its managers gain the new barangay while retaining previously authorized coverage. This fixes the discovered failure where managers could see their edited station but could not register residents from its new barangay.

## Validation and security

- Create/edit/deactivate/assign APIs require Sanctum authentication and the Admin role. Resident and Camp Manager accounts cannot mutate center records.
- Required name, barangay ID, integer capacity (1–100,000), and valid latitude/longitude are validated server-side and in the modal.
- Names normalize whitespace and case for duplicate identity. A database-unique SHA-256 identity key combines normalized facility name with canonical place ID. A deactivated record still reserves that identity; it can be reactivated rather than duplicated. The same facility name is allowed in different barangays.
- Center/assignment writes are transactional, with five attempts for database concurrency failures. A conflicting manager assignment rolls back the new center and emits no committed update.
- The modal uses an immediate save lock to stop rapid repeated submissions and checks refreshed manager availability. Validation/network failures retain form input. The picker pin is centered exactly on the clicked coordinate, independently of the label's size.
- Resident/public map responses contain facility metadata and aggregate counts only; resident records, manager details, and registration histories are excluded. Occupancy counts checked-in people in the active event, excluding historical events.

## Synchronization and APIs

Laravel emits `center.updated` after commit on the existing private Admin channel and public `evacuation.centers` channel. Notifications contain only center ID, event ID, and change timestamp. Consumers retrieve the authoritative map/grid data instead of trusting event counters. The web has live updates, 15-second reconciliation, foreground/reconnect handling, and immediate refresh after a local save. The Resident app uses focused 15-second polling rather than a new native WebSocket dependency. Periodic polling lets slow in-flight responses finish; manual and foreground refreshes can request fresh data immediately.

| Method | Endpoint | Access |
| --- | --- | --- |
| GET | `/api/admin/evacuation/setup` | Admin: canonical places, managers, assignments |
| POST | `/api/admin/evacuation/places` | Admin: enter/reuse a canonical place |
| GET, POST | `/api/admin/evacuation/centers` | Admin: paginated records / transactional creation |
| GET, PATCH | `/api/admin/evacuation/centers/{id}` | Admin: details / edits and deactivation |
| GET | `/api/admin/evacuation/map` | Admin: all located centers, including inactive centers |
| GET | `/api/evacuation-centers` | Public/Resident: operational located centers and aggregate occupancy |

The existing `2026_10_14_210000_add_center_identity_key.php` migration is applied to local MySQL (batch 6). It preserves historical duplicate rows and reserves each legacy identity on its oldest record. No additional schema change was needed in this audit, and tests did not insert demo records into the application database.

## Main implementation files

**Web:** `frontend/src/pages/EvacuationCentersPage.tsx`, `components/AddEvacuationCenterDialog.tsx` and CSS, `components/LiveMap.tsx` and CSS, `hooks/useCenterMap.ts`, `hooks/useEvacuationCenters.ts`, `types/evacuation.ts`, dashboard/GIS map wiring.

**Mobile:** `mobile/src/app/resident/map.tsx`, `components/IslandMap.tsx`, `hooks/useEvacuationCenters.ts`, `lib/evacuationCenters.ts`.

**Backend:** `backend/app/Http/Controllers/Api/EvacuationController.php`, `app/Models/EvacuationCenter.php`, `app/Events/EvacuationCenterUpdated.php`, API routes, existing center identity migration.

Targeted audit changes: center controller, add/edit modal and pin CSS, resident polling hook and detail-modal conditional rendering, `tests/Feature/EvacuationCenterManagementTest.php`, `frontend/tests/evacuation-centers.browser.mjs`, `scripts/center-management-fixture.php`, `scripts/evacuation-concurrency.php`, and new `scripts/center-sync-smoke.mjs` and `mobile/scripts/center-sync-browser.mjs`.

## Verification

- Laravel full suite: **82 passed, 425 assertions**, including 12 center-management cases. The new barangay/manager-scope regression failed before the fix and passes afterward.
- Browser integration: passed real Laravel create + assignment, rapid-submit prevention, exact visual pin alignment and selected coordinates, popup, name/location/barangay edits, manager coverage, deactivation, resident-feed removal, normalized duplicate rejection, required fields, and a responsive 390-pixel modal with no horizontal overflow or browser errors. Records live only in an in-memory fixture.
- Real Reverb: public create/edit notifications, matching Admin/Resident feeds, safe payload, and deactivation after a socket disconnection pass.
- Isolated MySQL: all six concurrency scenarios pass, including duplicate facility creation and competing assignments. The temporary database is removed afterward.
- Resident browser data-flow regression: a simulated 16-second response finishes despite the 15-second polling interval, sends one request, and displays confirmed capacity/occupancy details without runtime or console errors. This fixture verifies the actual mobile hook and UI metadata rather than native map drawing; it serves the real installed icon font locally to isolate API latency from Metro font delivery.
- Web production build/lint and mobile typecheck/lint pass. Android bundle export passes. Vite retains its existing large-chunk advisory.

Native Android marker gestures and rendering were not exercised in this audit. The resident integration was verified through the shared API, browser data-flow simulation, TypeScript/lint, and Android bundle export. Browser screenshots are in `.verification/add-center-desktop.png`, `add-center-mobile.png`, and `center-marker.png` at workspace root.

## Run and verification commands

From the existing workspace, use separate terminals for the running services. Existing environment files and application credentials should be retained.

```powershell
# Backend
Set-Location C:\Users\leych\Wala_Lang\CapstoneSystem\backend
php artisan migrate
php artisan serve --host=127.0.0.1 --port=8000
```

```powershell
# Live updates (local Reverb environment is already configured)
Set-Location C:\Users\leych\Wala_Lang\CapstoneSystem\backend
php artisan reverb:start --host=127.0.0.1 --port=8080
```

```powershell
# Admin web
Set-Location C:\Users\leych\Wala_Lang\CapstoneSystem\frontend
npm run dev -- --host 127.0.0.1 --port 5173
```

```powershell
# Resident Android development build
Set-Location C:\Users\leych\Wala_Lang\CapstoneSystem\mobile
$env:EXPO_PUBLIC_API_BASE_URL='http://10.0.2.2:8000/api'
npm run android
```

```powershell
# Backend and database checks
Set-Location C:\Users\leych\Wala_Lang\CapstoneSystem\backend
php artisan test
php scripts/evacuation-concurrency.php
node scripts/center-sync-smoke.mjs

# Web checks, using the already installed browser-test runtime
Set-Location C:\Users\leych\Wala_Lang\CapstoneSystem\frontend
npm run build
npm run lint
$env:PLAYWRIGHT_MODULE='C:/Users/leych/AppData/Local/npm-cache/_npx/e41f203b7505f1fb/node_modules/playwright/index.mjs'
node tests/evacuation-centers.browser.mjs

# Mobile checks
Set-Location C:\Users\leych\Wala_Lang\CapstoneSystem\mobile
npm run typecheck
npm run lint
npm run bundle:android
```

The socket test requires running Reverb. The browser test requires the web development server and installed Edge; it routes API requests through an isolated in-memory Laravel fixture. MySQL concurrency testing requires temporary database creation/drop privileges. No new framework or native dependency was introduced.

To run the resident slow-network browser fixture, start a separate Expo web terminal, then run the script from another terminal. This fixture intercepts all APIs; it does not authenticate a real resident or change application records.

```powershell
Set-Location C:\Users\leych\Wala_Lang\CapstoneSystem\mobile
$env:EXPO_PUBLIC_API_BASE_URL='http://127.0.0.1:8000/api'
npx expo start --web --port 8082 --offline
```

```powershell
Set-Location C:\Users\leych\Wala_Lang\CapstoneSystem\mobile
$env:PLAYWRIGHT_MODULE='C:/Users/leych/AppData/Local/npm-cache/_npx/e41f203b7505f1fb/node_modules/playwright/index.mjs'
node scripts/center-sync-browser.mjs
```
