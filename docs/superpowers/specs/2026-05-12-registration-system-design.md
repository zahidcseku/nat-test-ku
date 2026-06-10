# Registration System Design

**Date:** 2026-05-12
**Status:** Approved
**Author:** Design Team

## Overview

Multi-step registration system for NAT-TEST Centre supporting PBT (Paper-Based Test) online/offline registration and CBT (Computer-Based Test) coming soon placeholder. The system provides a clean, professional interface for candidates to submit examination applications with document uploads.

## Architecture

### Three Main Sections

1. **PBT Online Registration** (default view) - Multi-step form with 3 steps
2. **PBT Offline Registration** (expandable accordion) - Instructions + PDF link
3. **CBT Registration** (coming soon) - Banner + email capture

### Multi-Step Form Flow

- **Step 1: Personal Information** (10 fields) + Payment Method Selection
- **Step 2: Exam Details** (2 fields: exam level, test date)
- **Step 3: Document Uploads** (3 uploads: photo, ID, optional payment proof)

### Key Design Decisions

- Keep current registration.html visual design (progress tracker, layout)
- Use JavaScript for step navigation and validation
- Frontend-only mock submission (displays success with data summary)
- File uploads ready for future intake API integration
- No changes to index.html (explicitly preserved)

## Page Layout

### Main Structure

```
┌─────────────────────────────────────────────────────────┐
│  Top Navigation (same as current)                      │
└─────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────┐
│  Left Column (Context)              │  Right Column    │
│  ─────────────────────              │  ─────────────   │
│  • Page Title & Description         │  • TABS:         │
│  • Registration Type Tabs           │    - PBT Online  │
│  • PBT/CBT Selection                │    - PBT Offline │
│                                     │    - CBT         │
│  • Payment Information Card         │                  │
│  • Support Pill                     │  • Multi-step    │
│                                     │    Form          │
│                                     │                  │
│                                     │  • Success       │
│                                     │    Message       │
└─────────────────────────────────────────────────────────┘
```

### Tab System (Horizontal)

- **PBT Online Registration** (active by default)
- **PBT Offline Registration** (accordion content)
- **CBT Registration** (coming soon banner)

### Multi-Step Form UI

- Progress indicator (1-2-3 circles with labels)
- Form fields with ghost-input styling (current design)
- Navigation buttons: "Previous" / "Next Step" / "Submit Application"
- Validation error messages below fields
- File upload preview thumbnails

## Form Fields

### Step 1: Personal Information & Payment Method

1. **Full Name** (text, required) - "As it appears on ID"
2. **Email** (email, required) - For all correspondence
3. **Mobile Number** (tel, required) - Bangladeshi format
4. **Address** (textarea, required) - Full address
5. **Date of Birth** (date, required)
6. **Gender** (select: Male/Female/Other, required)
7. **Nationality** (text, required, default: "Bangladeshi")
8. **National ID/Passport Number** (text, required)
9. **Payment Method** (radio: Online/Offline, required)

**Payment Instructions:**
- **Online**: "Use the online payment link [link]"
- **Offline**: Bank details (Agrani Bank, Account: 0200025673722, Account Name: Test Site Director, Khulna University branch)

### Step 2: Exam Details

10. **Exam Level** (select: 1Q/2Q/3Q/4Q/5Q, required)
11. **Intended Test Date** (select dropdown, required, **PLACEHOLDER: Data will come from database**) - Available test dates loaded from database, displays as dropdown selection. Database schema to be setup by user later.

### Step 3: Document Uploads

12. **Student Photo** (file, required, max 2MB, JPG/PNG) - "Taken within last 6 months, plain light background"
13. **Government ID** (file, required, max 4MB, JPG/PNG/PDF) - "Passport or National ID"
14. **Payment Receipt** (file, optional, max 4MB, JPG/PNG/PDF) - "Or send later to money_receipt@nat-test.ku.ac.bd"

## Offline Process Section

**Accordion Content:**
- 6-step process list with icons
- Download button: "📥 Download Offline Form (PDF)"
- Links to: `/resources/offline_application_formV3.pdf`
- Email display: offline_registration@nat-test.ku.ac.bd
- Payment instructions embedded

**6-Step Offline Process:**
1. Fill out the form (PDF download)
2. Make the payment
3. Send form + documents + payment receipt via email
4. Receive confirmation email
5. Application review → approval email
6. Receive admission ticket

## CBT Section

**Coming Soon Banner:**
- "🚀 Coming Soon" heading
- Brief description: "Computer-Based Testing registration will open soon"
- Email capture: "Get notified when CBT launches"
- Submit button: "Notify Me"

## Data Flow

### Form Progress Flow

```
User Starts
    ↓
Step 1: Personal Info + Payment
    ↓ [validate all fields]
Step 2: Exam Details
    ↓ [validate exam level, date]
Step 3: Document Uploads
    ↓ [validate required files, sizes]
Submit Button
    ↓ [final validation]
Show Success Message
```

### Validation Checks (Frontend-Only)

- **Required fields**: All fields except payment receipt
- **Email format**: Standard email regex
- **File sizes**: Photo ≤2MB, ID ≤4MB, Payment ≤4MB
- **File types**: JPG/PNG for photo, JPG/PNG/PDF for ID & payment
- **Date validation**: DOB prevents future dates; Test date loaded from database dropdown (no client-side validation needed)

### Form Data Structure (for future API)

```javascript
{
  full_name: string,
  email: string,
  mobile: string,
  address: string,
  dob: date,
  gender: "male" | "female" | "other",
  nationality: string,
  id_number: string,
  payment_method: "online" | "offline",
  exam_level: "1Q" | "2Q" | "3Q" | "4Q" | "5Q",
  test_date: date,
  photo_file: file,
  id_file: file,
  payment_receipt_file?: file
}
```

### Submission Handling (Mock)

- Validate all steps
- Collect form data
- Display success modal with submitted details
- Log data to console (for development)
- Reset form after 5 seconds
- **Note**: No actual API call until intake service is built

## Success Message

**Detailed Confirmation (Option B):**

```
✅ Registration Received Successfully!

Thank you, [Full Name]!

Your application has been submitted:
• Exam Level: [XQ]
• Intended Test Date: [Date]
• Payment Method: [Online/Offline]
• Email: [email address]

What happens next:
1. You'll receive a confirmation email at [email] within 24 hours
2. We'll review your application
3. You'll receive an approval email or request for corrections
4. Your admission ticket will be sent via email

Questions? Contact us at: info@nat-test.ku.ac.bd
[Return to Homepage] [Register Another Candidate]
```

## Error Handling

### Validation Errors

- **Field-level**: Red error message below specific field
- **Step-level**: Can't proceed to next step until current step valid
- **File errors**: Show file name + error (e.g., "photo.jpg (3.2MB - exceeds 2MB limit)")

### Error Message Examples

- "Please enter your full name as it appears on your ID"
- "Please enter a valid email address"
- "Photo must be JPG or PNG format and under 2MB"
- "Test date must be after today"
- "Please upload a government-issued ID"

### User Feedback Elements

- Loading states on buttons during validation
- Green checkmarks for valid fields
- File upload progress indicators
- Success confetti animation (subtle)
- Disabled navigation buttons until step is valid

## Technical Implementation

### Frontend Stack

- **HTML**: Keep existing structure, add form sections
- **CSS**: Use existing Tailwind classes, add minimal custom CSS for file inputs
- **JavaScript**: Vanilla JS (no frameworks)
  - Step navigation logic
  - Form validation
  - File upload handling
  - Tab switching
  - Accordion toggle
  - Success modal

### File Structure

```
frontend/
├── registration.html (update existing)
├── css/
│   └── style.css (add file input styles)
└── js/
    └── registration.js (NEW - form logic)
```

### Key JavaScript Functions

- `validateStep(stepNumber)` - Validate current step fields
- `showStep(stepNumber)` - Navigate between steps
- `handleFileUpload(field)` - Validate file size/type
- `switchTab(tabName)` - Toggle between PBT/CBT sections
- `toggleOffline()` - Expand/collapse offline accordion
- `submitForm()` - Validate all, show success message
- `resetForm()` - Clear all fields after success

## Testing Approach

### Manual Testing Checklist

**Step 1 - Personal Information:**
- [ ] All required fields show errors when empty
- [ ] Email validation rejects invalid formats
- [ ] Phone field accepts Bangladeshi format
- [ ] DOB prevents future dates
- [ ] Payment method selection shows correct instructions

**Step 2 - Exam Details:**
- [ ] Exam level dropdown shows all 5 options
- [ ] Test date dropdown displays available dates (PLACEHOLDER: will load from database)
- [ ] Cannot proceed without selections

**Step 3 - Document Uploads:**
- [ ] Photo upload rejects files >2MB
- [ ] Photo upload rejects non-image files
- [ ] ID upload rejects files >4MB
- [ ] ID upload accepts JPG/PNG/PDF
- [ ] Payment receipt is optional (can submit without)
- [ ] File preview thumbnails display correctly

**Navigation & Submission:**
- [ ] Previous/Next buttons work correctly
- [ ] Progress tracker updates (1→2→3)
- [ ] Submit button validates all steps
- [ ] Success message displays with all data
- [ ] Form resets after success
- [ ] Can register another candidate

**Tab System:**
- [ ] PBT Online tab shows form
- [ ] PBT Offline tab expands accordion
- [ ] CBT tab shows coming soon + email capture
- [ ] PDF download link works
- [ ] CBT email submission shows success

**Responsive Testing:**
- [ ] Mobile (320px - 768px)
- [ ] Tablet (768px - 1024px)
- [ ] Desktop (1024px+)

**Browser Testing:**
- [ ] Chrome/Edge (Chromium)
- [ ] Firefox
- [ ] Safari (if on Mac)

## Future Integration Points

- **Intake API endpoint** (not built yet): Will replace `submitForm()` mock
- **Database**: Remote database connection string (to be provided by user)
- **Email**: Backend will trigger confirmation emails

## Security Considerations

- No PII in browser console logs (use generic placeholders)
- File uploads validated client-side (server-side validation will come with API)
- No sensitive data in localStorage or session storage
- Honeypot field can be added later for bot protection
- HTTPS required for production deployment

## Constraints & Requirements

### Must Have

- ✅ Keep current registration.html visual design
- ✅ Multi-step form (3 steps)
- ✅ File upload fields ready for future API
- ✅ Payment proof optional
- ✅ Payment method selection in Step 1
- ✅ Frontend-only mock submission for now
- ✅ Offline process in expandable accordion
- ✅ CBT section with email capture
- ✅ Basic validation (required fields, email format, file size/type)
- ✅ Detailed success confirmation message
- ❌ NO CHANGES to index.html

### Content Requirements

From `/dev_resources/registration_details.md`:
- Application Fee: BDT 4000.00 (Subject to change)
- Photo specifications: plain light background, 70-80% face, front-facing
- Payment instructions with bank details
- Offline form PDF link: `/resources/offline_application_formV3.pdf`
- Contact emails for various purposes
