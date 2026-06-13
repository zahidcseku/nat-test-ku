# Applicant Self-Service Application Lookup — Design

**Date:** 2026-06-13
**Status:** Approved by user

## Goal

An applicant can retrieve and view their submitted registration information
from the registration page, authenticated by full name + mobile number +
date of birth. For upcoming exams, the result tells them how to request
changes (email info@nat-test.ku.ac.bd).

## Decisions (made with user)

1. **Lookup credentials:** full name + mobile number + date of birth — all
   three must match one record (user's choice over ID-based lookup).
2. **Result UI:** modal overlay on the registration page.
3. **Unpaid registrations** show a "Complete payment" button to the existing
   retry page.
4. **Change-request note:** shown at the end of each registration's details
   ONLY when its exam is upcoming (test_date >= today, server time):
   "Need to change or update any information for this upcoming exam?
   Email us at info@nat-test.ku.ac.bd" (mailto link).
5. **Placement:** left panel of registration.html, between the Application
   Fee card and the Group Registration card.

## Components

### frontend/intake/application-lookup.php (new endpoint)

- POST only (PII never in URLs/access logs); JSON response via the existing
  helpers. 405 on other methods.
- Inputs: `full_name`, `mobile`, `dob` (date input, YYYY-MM-DD).
- Matching:
  - Candidates: `SELECT ... WHERE dob = ? AND LOWER(full_name) = LOWER(?)`,
    with the input name passed through the same sanitization registration
    uses (trim + htmlspecialchars) so stored encoded names match.
  - Mobile verified in PHP with normalization on BOTH sides: strip
    non-digits; `880XXXXXXXXXX` (13 digits) → `0XXXXXXXXXX`; compare equal.
  - All three match → included in results. Multiple matches allowed
    (a person may register several times); ordered by submitted_at DESC.
- Response per match: id, full_name, email, mobile, address, dob,
  nationality, id_document_type, id_document_number, exam_level, test_date,
  total_amount_paid, payment_method, payment_status, approved,
  submitted_at, `is_upcoming` (bool: test_date >= server today),
  and `retry_link` (payment-retry.html?token=...) ONLY when payment_status
  is unpaid/failed. Never file paths or images.
- No match (or invalid input): single generic message "No application found
  with those details" — never indicates which field failed.
- Rate limiting: `initSecurity()` like register.php (session-based 5/min),
  and every attempt is logged (hashed IP, matched-or-not — never the
  submitted values).

### frontend/registration.html

- New left-panel card after the Application Fee card: heading "Already
  registered?", three inputs (`lookup_name`, `lookup_mobile`, `lookup_dob`
  type=date), a "View my application" button, inline error span.
- Modal markup `id="lookup_modal"` (hidden): container the JS fills with
  one section per registration; close button; click-outside closes.
- Script include: `js/application-lookup.js` (new file; registration.js is
  untouched).

### frontend/js/application-lookup.js (new)

- Submits the three fields via fetch POST (urlencoded) to
  `/intake/application-lookup.php`.
- Renders results into the modal with DOM methods (no innerHTML with user
  data): per registration a definition table of the fields above, a
  color-coded payment/approval status line, the Complete-payment button
  (links retry_link) when present, and the change-request note with
  mailto:info@nat-test.ku.ac.bd when `is_upcoming`.
- Errors (no match, network) show in the card's inline error span.
- Button disabled state while the request is in flight.

## Security posture (explicit)

- Knowledge-based auth (name+mobile+DOB) is weaker than an unguessable-ID
  capability; accepted by user decision for this data. Mitigations:
  POST-only, generic errors, session rate limiting, attempt logging,
  no images/file paths in responses.
- This is the second deliberate exception (after payment-retry.php) to the
  "/intake exposes no reads over HTTP" constraint; both are documented in
  intake/CLAUDE.md as part of implementation.

## Testing

1. CLI test (`frontend/intake/test/test_application_lookup.php`) on the
   matching helpers: exact match; case-insensitive name; apostrophe name
   (stored encoded) matches raw input; mobile variants (+880…, 880…, 0…)
   all match; wrong DOB → no match; two registrations same person → both
   returned, newest first; unpaid → retry_link present; paid → absent;
   past test_date → is_upcoming false, future → true.
2. Live browser check (local DB): card renders, lookup succeeds, modal
   shows table + status + pay button + upcoming note; generic error on
   wrong details; rate limiting after repeated misses.
