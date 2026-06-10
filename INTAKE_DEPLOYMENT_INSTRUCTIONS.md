# Intake Service Deployment Instructions

## ✅ Merge Complete

The two intake directories have been successfully merged:
- **Removed:** `/frontend/intake/` (duplicate folder)
- **Main location:** `/intake/` (root of project)

## 📂 Current Project Structure

```
NAT_TEST_KU/
├── intake/                    ← MAIN intake service (deploy this)
│   ├── register.php          ← Main registration endpoint
│   ├── register_debug.php    ← Debug version with detailed logging
│   ├── config.php            ← Database configuration
│   ├── validate.php          ← Input validation
│   ├── upload.php            ← File upload handling
│   ├── security.php          ← Security functions
│   ├── test.php              ← Health check endpoint
│   ├── simple_test.php       ← Simple PHP test
│   ├── .htaccess             ← Apache security rules
│   ├── .env.example          ← Environment template
│   ├── init.sql              ← Database schema
│   ├── uploads/              ← File upload directory
│   │   ├── photos/
│   │   ├── ids/
│   │   └── receipts/
│   ├── logs/                 ← Log files
│   └── migrations/           ← Database migrations
├── frontend/                 ← Frontend files (deploy to public_html/)
│   ├── js/
│   │   └── registration.js   ← Points to 'intake/register.php'
│   ├── css/
│   ├── images/
│   ├── registration.html     ← Registration form
│   ├── test_intake.html      ← Test page (for debugging)
│   └── index.html
└── intake/                   ← Duplicate removed
```

## 🚀 Deployment Steps

### 1. Upload intake Service to Server

**From your local project:** `/Users/zahid/projects/NAT_TEST_KU/intake/`
**To your server:** `public_html/intake/`

**Files to upload:**
```bash
register.php
register_debug.php
config.php
validate.php
upload.php
security.php
test.php
simple_test.php
.htaccess
.env.example
init.sql
uploads/ (create this directory)
logs/ (create this directory)
migrations/ (optional, for future updates)
```

**Do NOT upload:**
- Documentation files (*.md)
- .gitignore

### 2. Create Subdirectories on Server

After uploading, create these directories:
```bash
public_html/intake/uploads/photos/
public_html/intake/uploads/ids/
public_html/intake/uploads/receipts/
public_html/intake/logs/
```

### 3. Set Directory Permissions

```bash
chmod 755 public_html/intake/uploads/
chmod 755 public_html/intake/uploads/photos/
chmod 755 public_html/intake/uploads/ids/
chmod 755 public_html/intake/uploads/receipts/
chmod 755 public_html/intake/logs/
chmod 644 public_html/intake/.env  # After creating it
```

### 4. Create .env File

On the server:
```bash
cd public_html/intake
cp .env.example .env
nano .env  # or use file manager editor
```

Edit `.env` with your actual database credentials:
```env
DB_HOST=localhost
DB_NAME=nat_test_intake
DB_USER=intake_user
DB_PASS=your_secure_password_here
IP_SALT=generate_with_openssl_rand_hex_32
ALLOWED_ORIGINS=*
```

### 5. Create Database

1. Log into your hosting control panel
2. Go to MySQL Manager / phpMyAdmin
3. Create database: `nat_test_intake`
4. Import `init.sql` to create the `registrations` table
5. Create database user: `intake_user` with strong password
6. Grant privileges: SELECT, INSERT (no UPDATE, DELETE, DROP for security)

### 6. Test the Service

Open in your browser:
```
https://your-domain.com/intake/simple_test.php
```

Expected response:
```json
{
  "success": true,
  "php_version": "7.4.x" or "8.0.x",
  "message": "PHP is working!"
}
```

### 7. Test Registration

Open the test page:
```
https://your-domain.com/test_intake.html
```

Run all tests in order to verify:
- PHP is executing
- Database connection works
- Registration endpoint works

### 8. Test Real Form

Open:
```
https://your-domain.com/registration.html
```

Fill out and submit the form. Check:
- Success message appears
- Records in database (phpMyAdmin)
- Files in `public_html/intake/uploads/`

## 🔧 Troubleshooting

### If PHP Not Executing

Open: `https://your-domain.com/intake/phpinfo.php` (create this first)

If you see PHP code instead of output, PHP is not enabled.

### If Database Connection Fails

Check `.env` credentials and database exists.

### If Files Not Uploading

Check permissions on `uploads/` directory (755).

### Debug Mode

Replace `register.php` with `register_debug.php` temporarily to see detailed error messages.

## 📞 Support Files

**Debug tools on server:**
- `intake/test.php` - Health check
- `intake/simple_test.php` - Basic PHP test
- `intake/register_debug.php` - Debug version with detailed logging

**Test page:**
- `test_intake.html` - Browser-based testing interface

## ✅ Verification Checklist

- [ ] All PHP files uploaded to `public_html/intake/`
- [ ] Directories created: `uploads/photos/`, `uploads/ids/`, `uploads/receipts/`, `logs/`
- [ ] Directory permissions set to 755
- [ ] `.env` file created with correct database credentials
- [ ] MySQL database created and `init.sql` imported
- [ ] Database user has SELECT and INSERT privileges
- [ ] `simple_test.php` returns JSON response
- [ ] Test page shows all green checks
- [ ] Real registration form submits successfully
- [ ] Files appear in `uploads/` directory
- [ ] Records appear in database

## 🎯 Final Note

The path in `frontend/js/registration.js` is correctly set to:
```javascript
const intakeUrl = 'intake/register.php';
```

This works because on your server:
- Form is at: `public_html/registration.html`
- JavaScript loads from: `public_html/js/registration.js`
- Intake endpoint is at: `public_html/intake/register.php`
- Relative path: `intake/register.php` ✅
