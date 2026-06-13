# Duplicate Registration Guard — Design

**Date:** 2026-06-13
**Status:** Approved by user

## Goal

Stop a person from accidentally registering twice for the same modules on
the same exam date (back-button re-submit, double-click). The registration
form blocks a submission that overlaps an existing registration and routes
the applicant to complete/handle the original instead.

## Decisions (made with user)

1. **Match key:** email + DOB + test_date, then **module overlap** — block if
   the new submission shares ANY module with an existing registration for
   that email/DOB/date.
2. **Overlap with a PAID registration:** block; tell them to email
   info@nat-test.ku.ac.bd to change modules (paid message wins if overlap
   touches both paid and unpaid rows).
3. **Overlap with only UNPAID/FAILED registrations:** block; return the
   existing registration's payment retry link so they pay the original.
4. **Allowed:** same person/date with non-overlapping modules; same person
   different test date; anyone else.
5. **Email** is the identity key (not mobile) — it is what confirmation/retry
   emails use. Targets accidental re-submits, not deliberate multi-email
   abuse. Server-side only; authoritative.

## Components

### frontend/intake/lookup-lib.php (extend)

`findModuleOverlap(string $newCsv, string $existingCsv): array` — split both
on commas, trim + uppercase each module, return the sorted array of modules
present in both (empty = no overlap). Pure; unit-tested.

### frontend/intake/register.php

After validation (`$data` ready) and BEFORE the SSLCommerz session block:

- SELECT `id, exam_level, payment_status, payment_retry_token` FROM
  registrations WHERE `LOWER(email) = LOWER(?) AND dob = ? AND test_date = ?`.
- For each row, compute overlap of `$data['exam_level']` vs row's
  `exam_level`. Collect: any paid overlap (with its modules), and the most
  recent unpaid/failed overlap (with its retry token + modules).
- If any paid overlap → block with paid message (HTTP 409), no insert, no
  gateway session.
- Else if an unpaid/failed overlap → block with unpaid message + retry URL
  (HTTP 409), no insert.
- Else → proceed exactly as today.
- Block responses use `errorResponse()` shape extended with
  `duplicate => true` and (unpaid case) `payment_retry_url`.

Helper require: register.php already loads config/validate/security/upload/
mailer; add `lookup-lib.php`.

### frontend/js/registration.js

In `submitForm()`'s response handling, before the generic failure branch:
if the JSON has `duplicate === true`, hide loading, restore the submit
button, show the server `error` message in the banner; if
`payment_retry_url` is present, surface it (reuse the gateway-unavailable
pattern: amber banner + redirect to the retry page after a short delay).

## Error handling

- The duplicate SELECT is wrapped so a DB hiccup never blocks a legitimate
  registration silently — on query failure, log and fall through to normal
  insert (fail-open: better a rare duplicate than a blocked real applicant).
- All comparisons case/space-normalized (email lower+trim; modules
  trim+upper).

## Testing

1. CLI test (`test_duplicate_guard.php`) on `findModuleOverlap`: identical
   sets; partial overlap; disjoint; case/whitespace (`1q ` vs `1Q`);
   single vs multi; empty inputs.
2. Live local-DB via the real form:
   - resubmit identical modules, existing unpaid → blocked + retry link, no
     new row;
   - resubmit overlapping subset, existing unpaid → blocked;
   - overlap with a paid row → blocked with admin message;
   - non-overlapping new modules same date → inserts (allowed);
   - same modules different test date → inserts (allowed);
   - confirm no SSLCommerz session is created on a blocked online submission.
