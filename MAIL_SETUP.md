# IslaSafe Gmail recovery mail

Password recovery sends six-digit codes immediately through the configured Laravel mail transport. A queue worker is not required. Codes expire after five minutes; verified reset authorizations expire after ten minutes. Completing a reset revokes existing API sessions.

The current backend SMTP configuration authenticated successfully against Gmail on 2026-10-14. Recipient inbox delivery still requires a live recovery request for a registered account.

## Sender configuration

Keep sender credentials only in the backend `.env`:

```dotenv
APP_NAME=IslaSafe
MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_TIMEOUT=12
MAIL_USERNAME=your-sender@gmail.com
MAIL_PASSWORD=your-google-app-password
MAIL_FROM_ADDRESS=your-sender@gmail.com
MAIL_FROM_NAME="IslaSafe"
```

Use a [Google app password](https://support.google.com/accounts/answer/185833), with two-step verification enabled on the sending account. The Gmail recipient does not need an app password. Google documents [SMTP with STARTTLS on port 587](https://support.google.com/mail/answer/7104828).

After changing credentials, run `php artisan config:clear`. Check connectivity with `php artisan mail:check`; this authenticates without sending email or printing credentials.

## Test recovery

Use Forgot Password in either mobile role's sign-in form. Enter the email stored on the IslaSafe account, check that inbox (including Spam), enter the six-digit code, and choose a new password. The app validates the code format, respects resend cooldowns, and restarts recovery when a challenge or reset authorization expires.

Delivery failures return HTTP 503 instead of reporting that a code was sent. A failed initial send consumes the unusable challenge. A failed resend leaves the previous code valid until its original expiry.

Automated verification: 21 password-recovery tests cover the full reset flow, immediate mail dispatch, SMTP failures, resend preservation, expiry, attempt limits, and revocation. They use in-memory data and an array mail transport; they do not establish delivery into a Gmail inbox.
