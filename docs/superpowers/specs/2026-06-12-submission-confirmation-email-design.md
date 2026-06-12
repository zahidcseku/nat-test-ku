# Automated Submission-Confirmation Email — Design

**Date:** 2026-06-12
**Status:** Approved by user (proceeding to implementation on user instruction)

## Goal

Email every applicant automatically: a payment confirmation when their payment
is in hand (successful online payment, or a receipt attached at submission),
or an application receipt with payment options when it is not. All submitted
information is included (data only — never the uploaded images).

## Decisions (made with user)

1. **QR delivery: hosted image + link.** The SSLCommerz QR (PDF page) is
   converted once to a PNG served from the site; emails embed it by absolute
   URL and link to the hosted PDF. No attachments.
2. **Online applicants get two emails:** an application receipt immediately
   at submission (with pay link + QR — useful if they abandon the gateway)
   and a payment confirmation when the IPN lands.
3. **Sender:** `no-reply@nat-test.ku.ac.bd`, overridable via `MAIL_FROM` env.
4. **QR appears in ALL unpaid (application receipt) variants**, including
   offline-without-receipt (user adjustment).
5. **Architecture: self-contained intake mailer** (`frontend/intake/mailer.php`).
   No imports from /admin (hard constraint). Same PHP `mail()` transport the
   admin panel uses.

## Email matrix

| Situation | Variant | Sent from | Payment section |
|---|---|---|---|
| Offline submission WITH receipt | Confirmation ("receipt received, pending verification") | register.php | none |
| Offline submission, NO receipt | Application receipt | register.php | Bank details + "email deposit receipt to money_receipt@nat-test.ku.ac.bd quoting your registration ID" + QR (alternative: scan to pay, then email proof) |
| Online submission (pending or gateway down) | Application receipt | register.php | Pay-online link (the registration's retry link) + QR + QR-PDF link |
| Online payment confirmed | Confirmation (paid amount + transaction reference) | payment-ipn.php (paid branch) | none |

Every variant contains all submitted data: registration ID, full name, email,
mobile, address, DOB, nationality, ID document type + number, exam levels,
test date, total amount, payment method, payment status. No uploaded images.
Next steps in every email: admin review → approval email → admission ticket
by email before the exam.

Static-QR caveat (verified): QR payments do not carry the registration's
transaction ID, so they cannot auto-flip the registration to paid via IPN.
QR sections instruct the payer to email their payment proof to
money_receipt@nat-test.ku.ac.bd with their registration ID, like a bank
deposit.

## Components

### frontend/intake/mailer.php (new)

- `buildRegistrationEmail(array $registration, string $variant): array`
  — pure; returns `['subject' => ..., 'body' => ...]` (HTML, simple inline
  styles). `$variant` ∈ `submission_receipt` | `payment_confirmation`.
  `$registration` carries the field values plus `payment_method`,
  `payment_status`, `retry_token`, `has_receipt` (bool), and (for paid)
  `bank_tran_id`. The confirmation variant words its banner from
  `payment_status`: `'paid'` → "Payment received" with the transaction
  reference; otherwise (receipt attached at submission) → "Payment receipt
  received — pending verification".
- `sendRegistrationEmail(array $registration, string $variant): bool`
  — builds, sends via `mail()` with From/MIME headers, logs the attempt to
  `email_log` (`sent_by` NULL = system) when the table exists, always
  `logActivity()`s the outcome. NEVER throws; returns false on any failure.
- Bank details constants: Test Site Director / 0200025673722 /
  Agrani Bank Plc. / Khulna University branch (same as registration.html).
- URLs built from SITE_URL: QR image `/resources/sslcommerz-payment-qr.png`,
  QR PDF `/resources/sslcommerz-payment-qr.pdf`, pay link
  `/payment-retry.html?token=<retry_token>`.

### frontend/intake/config.php

- `define('MAIL_FROM', getenv('MAIL_FROM') ?: 'no-reply@nat-test.ku.ac.bd');`
- `define('RECEIPT_EMAIL', 'money_receipt@nat-test.ku.ac.bd');`

### frontend/intake/register.php

After the post-insert verification, before the success response:
receipt attached → `payment_confirmation` variant; otherwise →
`submission_receipt` variant. Wrapped so a mail failure only logs a warning.

### frontend/intake/payment-ipn.php

In the `$newStatus === 'paid'` branch (replacing the "queued" comment):
fetch the registration's fields, send `payment_confirmation` with the bank
transaction reference. Failure logs a warning; IPN response unchanged.

### Assets (one-time build step, committed)

- `frontend/resources/sslcommerz-payment-qr.png` — rendered from
  `dev_resources/QR for SSLCOMMERZ.pdf` (macOS `sips`/`qlmanage`).
- `frontend/resources/sslcommerz-payment-qr.pdf` — copy of the PDF.

### Migration — frontend/intake/migrations/extend_email_log_types.sql

Extend `email_log.email_type` enum with `submission_receipt` and
`payment_confirmation` (keeping existing values). Guarded for the case
where the table does not exist (documented: run only where admin schema is
installed; the mailer degrades gracefully without it).

## Error handling

- Email must never break a registration or an IPN: all mailer calls are
  fire-and-forget with boolean results; failures log as warnings.
- `mail()` returning false, missing email_log table, missing enum values —
  all degrade to logActivity entries.
- All applicant-provided values are HTML-escaped in the body.

## Testing

1. CLI test `frontend/intake/test/test_registration_email.php` on the pure
   builder: confirmation variant contains the data table + next steps and NO
   payment section; offline receipt variant contains bank details + receipt
   instruction + QR image URL; online receipt variant contains the retry
   link + QR; paid variant contains the transaction reference; applicant
   values are escaped (`<script>` becomes `&lt;script&gt;`); no `data:`
   image URIs.
2. Runtime (local DB): browser submissions for each trigger; verify
   activity.log records the send attempt and the HTTP responses are
   unchanged (no local MTA — graceful-failure path is exercised live).
3. Deployment acceptance: one real submission per variant; verify inbox
   rendering and email_log rows.
