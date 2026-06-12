# One-Page Registration Form — Design

**Date:** 2026-06-12
**Status:** Approved by user

## Goal

Convert the four-step wizard registration form into a single scrollable page,
and remove the gender field from the form. An applicant fills everything top
to bottom and submits once. The payment flow (online via SSLCommerz, offline)
is not changed.

## Decisions (made with user)

1. **Gender:** removed from the form and from required validation. The
   database column stays NOT NULL and unmigrated; the backend stores
   `'other'` for all new rows. Existing rows keep their values; the admin
   panel is untouched.
2. **Validation UX:** inline — each field validates when the user leaves it
   (blur/change) — plus full re-validation on submit, scrolling to and
   focusing the first invalid field.
3. **Cost confirmation:** the level-confirmation modal is removed. A live
   payment summary box sits directly above the Submit button and updates as
   level checkboxes change: "N level(s) selected · Total: X BDT". Total is
   base only (levels × 4000 BDT) per the fee-model decision of 2026-06-12 —
   no surcharge.
4. **Approach:** restructure in place (existing registration.html +
   registration.js). No rebuild. The submission code path is preserved
   byte-for-byte apart from dropping the gender field.

## Changes

### frontend/registration.html

- The four `.form-step` containers (Personal Information, Exam Details,
  Payment Method, Documents) become always-visible stacked sections, each
  with a numbered section heading (1–4).
- Remove: the 4-circle progress indicator (`data-progress-step` block),
  all per-step Next / Go Back buttons, the gender field (label, select,
  error/success spans), and the level-confirmation modal markup.
- Add: live payment summary box above the single Submit button (which
  remains at the end of the Documents section).
- The offline-registration instructions section is untouched.

### frontend/js/registration.js

- Remove: `currentStep`, `showStep()`, `nextStep()`, `prevStep()`,
  progress-indicator update code, `showLevelConfirmation()`,
  `cancelLevelConfirmation()`, `confirmLevelSelection()`.
- Keep all per-field validators; wire them to `blur` (text/select inputs)
  and `change` (checkboxes, radios, file inputs) for inline feedback.
- New `validateAll()` runs the four existing section validators at submit;
  on failure, scroll to and focus the first invalid field.
- The `formData.step1–4` objects are still populated (by the section
  validators) before submission so `submitForm()` is unchanged except that
  it no longer appends `gender`.
- The live summary updates from the existing level-checkbox change handler.
- `totalAmount` remains base-only (levels × 4000), as submitted today.
- Exam-date loading (`get_exam_dates.php`) and level-availability fetch
  (`api/exam-dates/levels.php`) are unchanged.

### frontend/intake/validate.php

- Gender is no longer required or read from input: the gender block always
  sets `$sanitized['gender'] = 'other'`. Anything sent in a `gender` field
  is ignored.

### frontend/intake/register.php

- No change. It binds whatever validation returns, which is now always
  `'other'`.

### Not changed

- Database schema, admin panel, intake security (honeypot, rate limiting),
  payment gateway code, payment-test page, offline instructions content.

## Error handling

- Inline errors reuse the existing `showError`/`showSuccess` helpers and
  `field-error` spans.
- Server-side 400 responses display through the existing submission error
  path; no changes.
- The honeypot field remains in the form.

## Testing

1. PHP: `validateRegistrationData()` with no gender field → valid, returns
   `gender === 'other'`; with a gender value sent → still `'other'`.
2. JS parse check on registration.js.
3. Local server run: page renders as one form; summary box updates when
   levels change; submit with valid data reaches `/intake/register.php`
   (locally the gateway call fails with 502 — expected without credentials);
   submit with invalid data scrolls to the first invalid field.
