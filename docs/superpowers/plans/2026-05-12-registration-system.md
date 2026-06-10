# Registration System Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (\`- [ ]\`) syntax for tracking.

**Goal:** Build a multi-step registration system for NAT-TEST with PBT online/offline registration, CBT coming soon placeholder, file uploads, and frontend-only mock submission.

**Architecture:** Static HTML page with vanilla JavaScript for form logic, validation, and UI interactions. Tab-based interface (PBT/CBT), multi-step form with 3 steps, expandable accordion for offline process. No backend integration yet (mock submission only).

**Tech Stack:** HTML5, CSS3 (Tailwind), Vanilla JavaScript (ES6+), no frameworks

**Security Note:** This implementation uses DOMPurify library to sanitize all user input before displaying in HTML. This prevents XSS attacks while allowing rich formatted content.

---

## File Structure

\`\`\`
frontend/
├── registration.html          (MODIFY - add tab system, multi-step form)
├── css/
│   └── style.css              (MODIFY - add file input styles)
└── js/
    ├── lib/
    │   └── dompurify.js       (ADD - HTML sanitization library)
    └── registration.js        (CREATE - form logic, validation, UI)
\`\`\`

---

## Task 0: Add DOMPurify Library

**Files:**
- Create: \`frontend/js/lib/dompurify.js\`

**Purpose:** Add DOMPurify library for safe HTML sanitization to prevent XSS attacks.

- [ ] **Step 1: Download DOMPurify**

Run: \`cd frontend/js && mkdir -p lib && cd lib && curl -o dompurify.js https://cdnjs.cloudflare.com/ajax/libs/dompurify/3.0.6/purify.min.js\`

Expected: File downloaded successfully

- [ ] **Step 2: Verify file exists**

Run: \`ls -lh frontend/js/lib/dompurify.js\`

Expected: File exists with non-zero size

- [ ] **Step 3: Commit DOMPurify**

\`\`\`bash
git add frontend/js/lib/dompurify.js
git commit -m "security: add DOMPurify library for HTML sanitization"
\`\`\`

---

[Rest of plan continues with all tasks using DOMPurify.sanitize() for any user input displayed in HTML]


## Task 1: Add CSS Styles

**Files:**
- Modify: `frontend/css/style.css`

- [ ] **Step 1: Add custom styles**

Append file input, validation, tab, and modal styles to `frontend/css/style.css` (see design document for complete CSS)

- [ ] **Step 2: Commit CSS**

```bash
git add frontend/css/style.css
git commit -m "style: add styles for file inputs, validation, tabs, and multi-step form"
```

---

## Task 2: Create JavaScript Core Module

**Files:**
- Create: `frontend/js/registration.js`

- [ ] **Step 1: Create module structure**

Create utility functions for validation, file handling, error display (see design document for complete code)

- [ ] **Step 2: Test utilities in browser**

Test email/phone validation, file size formatting in browser console

- [ ] **Step 3: Commit utilities**

```bash
git add frontend/js/registration.js
git commit -m "feat: add core utility functions for validation"
```

---

## Task 3-8: Add Validation & Navigation

**Sequential implementation of:**
- Step 1 validation (personal info)
- Step 2 validation (exam details)  
- Step 3 validation (file uploads)
- Multi-step navigation
- Tab switching
- Form submission handler

Each task follows same pattern: implement → test → commit

---

## Task 9: Update HTML Structure

**Files:**
- Modify: `frontend/registration.html`

- [ ] **Step 1: Backup current file**

```bash
cp frontend/registration.html frontend/registration.html.backup
```

- [ ] **Step 2: Replace main content**

Replace `<main>` tag content with tab system, multi-step form, and all sections (see design document for complete HTML structure)

- [ ] **Step 3: Add DOMPurify script reference**

Before registration.js, add:
```html
<script src="js/lib/dompurify.js"></script>
```

- [ ] **Step 4: Add registration.js reference**

```html
<script src="js/registration.js"></script>
```

- [ ] **Step 5: Add initialization script**

Add event listeners for payment methods, file uploads, tab switching (see design document)

- [ ] **Step 6: Test in browser**

Verify all tabs work, form navigation works, validation shows errors

- [ ] **Step 7: Commit HTML**

```bash
git add frontend/registration.html
git commit -m "feat: add complete tab system and multi-step form"
```

---

## Task 10: Final Testing

- [ ] **Step 1: Complete full registration flow**

Fill all fields, upload files, submit → verify success modal

- [ ] **Step 2: Test all validation rules**

Empty fields, invalid email, wrong file sizes → verify error messages

- [ ] **Step 3: Test all tabs**

PBT Online, PBT Offline, CBT → verify content displays

- [ ] **Step 4: Test responsive design**

Desktop, tablet, mobile → verify layout adapts

- [ ] **Step 5: Test multiple browsers**

Chrome, Firefox, Safari → verify compatibility

- [ ] **Step 6: Verify no console errors**

Check DevTools → verify clean console

- [ ] **Step 7: Verify index.html unchanged**

```bash
git diff index.html
```

Expected: No changes to index.html

- [ ] **Step 8: Final commit**

```bash
git add .
git commit -m "test: complete registration system with all features"
```

---

## Implementation Notes

**Security:** All user input displayed in HTML must be sanitized using `DOMPurify.sanitize(userInput)` before assignment to innerHTML.

**Database Placeholder:** Test date dropdown has placeholder values. When database is ready:
- Create API endpoint: `GET /api/test-dates`
- Fetch dates dynamically on page load
- Replace static options with API results

**API Integration:** When intake API is ready:
- Replace `submitForm()` mock with: `fetch('/api/register', { method: 'POST', body: formData })`
- Handle API errors properly
- Add loading states during submission

**Email:** Backend will trigger confirmation/approval emails when API is integrated.

---

## Verification Checklist

- [ ] All three tabs work (PBT Online, PBT Offline, CBT)
- [ ] Multi-step navigation validates each step
- [ ] All validation rules work correctly
- [ ] File uploads validate size and type
- [ ] Payment instructions show for both methods
- [ ] Success modal displays submitted data
- [ ] Form resets after submission
- [ ] Offline accordion works
- [ ] CBT email capture works
- [ ] Responsive on all screen sizes
- [ ] No console errors
- [ ] index.html unchanged
- [ ] DOMPurify sanitizes all user input

---

## Complete

The registration system is ready for frontend testing. Backend integration will be added when database and intake API are available.
