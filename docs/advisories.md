# Advisory targeting and mobile inbox

The administrator's Target area dropdown contains all 23 CPG barangays and
`All barangays`. The API validates the target against the same canonical names.
Keep `app/Support/Barangays.php` aligned with the web and mobile barangay lists.
Historical Pitogo and Tawid profile names resolve to Poblacion and San Jose.

Publishing makes an active advisory available as an unread in-app notification:

- `All barangays`: all authenticated resident and camp manager accounts,
  including accounts without a barangay set.
- One barangay: accounts whose profile matches that barangay. Camp managers also
  receive notices for their assigned evacuation center's barangay.
- Archived advisories disappear from the inbox. Active notices also become
  available to eligible accounts created after publication.

`GET /api/advisories` returns only the signed-in user's active audience, together
with `version`, `is_read` and `unread_count`. `POST /api/advisories/{id}/read`
accepts the displayed `version`. Reads persist per account; altered advisories
become unread again. A stale read returns HTTP 409 instead of acknowledging
new content. Unchanged saves do not reset read status. Out-of-audience and
archived IDs return HTTP 404.

The resident bell shows an unread badge and opens Notifications (unread) or
Advisories (all active). Camp managers receive their inbox on the Alerts tab.
The app refreshes every 30 seconds while in the foreground, on resume, and on
manual refresh. Offline content may be stale. Sign-out clears the visible feed.

This is in-app delivery, not SMS or operating-system push delivery. The existing
Firebase/FCM setup is still required for notifications while the app is closed.

Verification: `php artisan test --compact --filter=Advisory`; web browser test
`frontend/tests/advisories.browser.mjs`; mobile renderer test
`mobile/scripts/advisory-inbox-review.mjs` (set `PLAYWRIGHT_MODULE` to Playwright).
