# Directory Structure Reference

This document shows the actual deployed directory structure and paths.

## Server Directory Structure

```
public_html/
└── frontend/
    ├── intake/                    # PHP intake service
    │   ├── register.php          # POST /register endpoint
    │   ├── config.php
    │   ├── validate.php
    │   ├── upload.php
    │   ├── security.php
    │   ├── .htaccess
    │   ├── .env                  # Database credentials
    │   ├── init.sql              # Database schema
    │   ├── uploads/              # Protected upload directory
    │   │   ├── photos/
    │   │   ├── ids/
    │   │   └── receipts/
    │   └── logs/
    ├── js/
    │   └── registration.js       # Main form script
    ├── css/
    ├── images/
    ├── registration.html         # Registration form
    └── index.html
```

## Key Paths

### Frontend JavaScript
**File:** `frontend/js/registration.js` (line ~604)

**Intake URL:** `intake/register.php` (relative to frontend directory)

```javascript
const intakeUrl = 'intake/register.php';
```

**Why this path:**
- Form is at: `frontend/registration.html`
- Script loads from: `frontend/js/registration.js`
- Intake endpoint is at: `frontend/intake/register.php`
- Relative path from frontend: `intake/register.php`

### Database Connection
**File:** `frontend/intake/config.php`

**Database:** MySQL (create via hosting MySQL Manager)
- Host: `localhost`
- Database: `nat_test_intake`
- User: `intake_user`
- Table: `registrations`

### Upload Directory
**Path:** `frontend/intake/uploads/`
- Photos: `frontend/intake/uploads/photos/`
- IDs: `frontend/intake/uploads/ids/`
- Receipts: `frontend/intake/uploads/receipts/`

**Protected by:** `.htaccess` in `frontend/intake/`

### Log Files
**Path:** `frontend/intake/logs/`
- PHP errors: `frontend/intake/logs/php_errors.log`
- Activity: `frontend/intake/logs/activity.log`

## URL Mapping

### Registration Form
```
https://your-domain.com/frontend/registration.html
```

### Intake Service Endpoint
```
https://your-domain.com/frontend/intake/register.php
```

### Frontend Submit Flow
1. User fills form at: `frontend/registration.html`
2. JavaScript sends POST to: `intake/register.php` (relative path)
3. Browser resolves to: `https://your-domain.com/frontend/intake/register.php`
4. Intake service processes and stores in MySQL
5. Files uploaded to: `frontend/intake/uploads/`

## Testing

### Test Registration via Browser
```
1. Open: https://your-domain.com/frontend/registration.html
2. Fill out all 4 steps
3. Submit
4. Check database for new record
5. Check uploads/ for files
```

### Test via cURL
```bash
curl -X POST https://your-domain.com/frontend/intake/register.php \
  -F "full_name=Test User" \
  -F "email=test@example.com" \
  -F "mobile=+880171234567" \
  -F "address=Test Address" \
  -F "dob=1990/01/01" \
  -F "gender=male" \
  -F "nationality=Bangladeshi" \
  -F "payment_method=offline" \
  -F "exam_level=N5" \
  -F "test_date=2024/12/15" \
  -F "website=" \
  -F "photo=@/path/to/test-photo.jpg" \
  -F "id_document=@/path/to/test-id.pdf"
```

## Common Issues

### 404 Not Found
- **Check:** `frontend/js/registration.js` has correct path: `intake/register.php`
- **Verify:** Files exist in `frontend/intake/` directory

### 500 Internal Server Error
- **Check:** `frontend/intake/logs/php_errors.log`
- **Verify:** `.env` file exists and has correct database credentials
- **Verify:** `uploads/` and `logs/` directories are writable (755)

### CORS Error
- **Check:** `.htaccess` in `frontend/intake/`
- **Verify:** CORS headers allow your domain

### File Upload Fails
- **Check:** Directory permissions on `frontend/intake/uploads/`
- **Verify:** Subdirectories exist: `photos/`, `ids/`, `receipts/`
- **Check:** PHP `upload_max_filesize` >= 6M

## Deployment Checklist

- [ ] Created `frontend/intake/` directory on server
- [ ] Uploaded all PHP files to `frontend/intake/`
- [ ] Created `.env` file with database credentials
- [ ] Created and chmodded `uploads/` subdirectories (755)
- [ ] Created and chmodded `logs/` directory (755)
- [ ] Updated `frontend/js/registration.js` with correct path
- [ ] Created MySQL database and imported `init.sql`
- [ ] Tested endpoint via cURL or browser form
- [ ] Verified files appear in `uploads/` after submission
- [ ] Verified records appear in MySQL database
