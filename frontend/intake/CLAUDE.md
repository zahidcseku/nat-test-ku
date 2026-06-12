# Intake service

PHP service that accepts registration submissions and handles SSLCommerz
online payments. The only internet-exposed code that writes to the database.

Technology: PHP 8.0+, mysqli, MySQL database `nattest_regs` (user `nattest_reg`).
No framework, no composer dependencies.

## Endpoints
Write:
- `register.php` — POST only. Validates, rate-limits, handles uploads,
  inserts into `registrations`, creates SSLCommerz session for online payment
- `payment-ipn.php` — SSLCommerz IPN callback (verify_sign + validation API)
- `payment-return.php` — converts SSLCommerz's POST return into a GET
  redirect to the static success/fail pages (static .html can't accept POST)
- `payment-retry.php`, `payment-retry-session.php` — retry links for
  registrations whose gateway session failed (token expires in 7 days)

Read (public, JSON, no PII — exam schedule data only):
- `get_exam_dates.php`, `get_next_exam.php`, `get_schedule.php`

No registration data is ever readable over HTTP. No admin UI here.

## Shared library files
Every endpoint must `define('INTAKE_SERVICE', true)` BEFORE including these —
each one exits on direct access otherwise:
- `config.php` — env loading, `getDbConnection()` (singleton, always use this,
  never construct mysqli manually), `jsonResponse`/`errorResponse`/`successResponse`
  helpers, `logActivity()`, `calculatePaymentAmount()` (4000 BDT per exam level,
  no fee added to applicant)
- `validate.php` — field validators + `validateRegistrationData()`.
  `asString()` coerces non-string POST input (e.g. `full_name[]=x`) to `''`
  so it fails validation cleanly instead of trim() throwing a TypeError
- `security.php` — `initSecurity()`, rate limiting (5/min, 20/day per IP),
  honeypot field `website` (filled → fake success response, flagged in DB)
- `upload.php` — photo / ID document / payment receipt uploads to `uploads/`

## Validation rules (current form)
- Gender is NOT collected; every row stores `'other'` (column is NOT NULL)
- ID document: type `passport|national_id`; number 4-30 letters/digits after
  stripping spaces and hyphens, stored uppercase (lenient by design —
  foreign passport formats vary)
- `exam_levels` is an array of 1-5 values from 1Q-5Q; `total_amount` must
  equal 4000 × level count
- Dates arrive as YYYY/MM/DD, stored as MySQL YYYY-MM-DD

## Gotchas
- Catch `Throwable`, not `Exception`, in endpoints — TypeError/Error
  otherwise escapes and dies as a bare 500 with no JSON body
- `$_POST` values can be strings OR arrays — never call trim() on raw input
- `.env` may instead be named `config.env`, `db.env`, or `environment.env`
  (ModSecurity blocks some names); `loadEnv()` in config.php tries all four
- `display_errors` is off; check `logs/php_errors.log` and `logs/activity.log`
- SSLCommerz mode via `SSLCZ_MODE` env (`sandbox`/`live`); gateway failure at
  registration time falls back to saving as unpaid + issuing a retry link

## Tests
Standalone CLI scripts in `test/`, no framework, exit 0/1:
```bash
php frontend/intake/test/test_id_document_fields.php
php frontend/intake/test/test_array_input_rejected.php
php frontend/intake/test/test_gender_optional.php
```
They include `../config.php` + `../validate.php` directly and print
PASS/FAIL lines. Follow this style for new tests.

## Schema
- `init.sql` — base schema; incremental changes in `migrations/*.sql`
- `schema/` — exam_dates table DDL + deployment scripts

## Do not
- Do not add HTTP reads of registration data
- Do not import anything from /admin or /frontend
- Do not write SQL without prepared statements
- Do not log PII (log `ip_hash`, never raw IPs)
