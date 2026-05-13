# Intake Service Deployment Guide

Complete guide for deploying the NAT-TEST intake service to shared hosting.

## Pre-Deployment Checklist

### 1. Server Requirements Verification

Create a PHP info file to verify server capabilities:

```php
<?php
phpinfo();
?>
```

Upload as `phpinfo.php` and access via browser. Check for:
- PHP version 7.4+ or 8.0+
- MySQLi extension enabled
- Fileinfo extension enabled
- GD library enabled
- upload_max_filesize >= 6M
- post_max_size >= 8M

**Delete phpinfo.php after verification!**

### 2. Database Preparation

#### Via Hosting Control Panel (cPanel, DirectAdmin, etc.)

1. Log into your hosting control panel
2. Navigate to "MySQL Databases" or "MySQL Manager"
3. Create a new database:
   - Database name: `nat_test_intake` (or your preferred name)
   - Note the database hostname (usually `localhost`)
4. Create a new database user:
   - Username: `intake_user`
   - Password: Generate a strong password (use password generator)
5. Add user to database with privileges:
   - SELECT
   - INSERT
   - UPDATE (optional, for future features)

#### Via phpMyAdmin

If you have phpMyAdmin access:

1. Log into phpMyAdmin
2. Click "New" to create database
3. Name: `nat_test_intake`
4. Click "Create"
5. Go to "User accounts" tab
6. Click "Add user account"
7. Username: `intake_user`
8. Generate strong password
9. Under "Database-specific privileges", select the database
10. Check only: SELECT, INSERT
11. Click "Go"

#### Import Schema

1. Open `init.sql` in a text editor
2. In phpMyAdmin, select the `nat_test_intake` database
3. Click "Import" tab
4. Choose the `init.sql` file
5. Click "Go" at the bottom

**Verify**: Check that the `registrations` table was created successfully with all fields including:
- `approved` (TINYINT, default 0) - Approval status
- `approved_at` (TIMESTAMP, nullable) - When it was approved
- `approved_by` (VARCHAR, nullable) - Admin username who approved

**Note**: If you already created the database before this feature was added, run the migration script:
```bash
mysql -u intake_user -p nat_test_intake < migrations/add_approval_fields.sql
```

### 3. Generate IP Salt

Generate a random salt for IP hashing:

```bash
openssl rand -hex 32
```

Or use an online random hex generator. Save this value - you'll need it for `.env`.

## Deployment Steps

### Step 1: Prepare Files

1. Create the `.env` file from the template:

```bash
cp .env.example .env
```

2. Edit `.env` with your actual values:

```env
DB_HOST=localhost
DB_NAME=nat_test_intake
DB_USER=intake_user
DB_PASS=your_actual_database_password_here
IP_SALT=the_random_hex_you_generated_earlier
ALLOWED_ORIGINS=*
```

3. Update `ALLOWED_ORIGINS` if needed:
   - Development: `*` (allows all origins)
   - Production: `https://your-domain.com,https://www.your-domain.com`

### Step 2: Upload Files

Choose your preferred method:

#### Option A: FTP/SFTP

1. Use FileZilla, Cyberduck, or similar
2. Connect to your hosting server
3. Navigate to the public directory (often `public_html` or `www`)
4. Navigate to the `frontend/` directory (or create it if it doesn't exist)
5. Create an `intake` subdirectory inside `frontend/`
6. Upload all files to `frontend/intake/`:
   - register.php
   - config.php
   - validate.php
   - upload.php
   - security.php
   - .htaccess
   - .env
   - init.sql (optional, for backup)

**Resulting structure:**
```
public_html/
└── frontend/
    ├── intake/
    │   ├── register.php
    │   ├── config.php
    │   └── ...
    ├── js/
    ├── css/
    └── registration.html
```

#### Option B: Hosting File Manager

1. Log into hosting control panel
2. Open File Manager
3. Navigate to public directory, then to `frontend/`
4. Create `intake` folder inside `frontend/`
5. Upload files one by one or as a ZIP

#### Option C: ZIP Upload

1. Create ZIP of intake directory (exclude .git, node_modules, etc.)
2. Upload ZIP via File Manager to the `frontend/` directory
3. Extract in place (creates `frontend/intake/`)
4. Verify `.htaccess` was extracted (sometimes hidden files are excluded)

### Step 3: Set Directory Permissions

#### Via FTP Client

Right-click each directory and set permissions:
- `uploads/` - 755
- `uploads/photos/` - 755
- `uploads/ids/` - 755
- `uploads/receipts/` - 755
- `logs/` - 755

#### Via File Manager

1. Select directory
2. Click "Change Permissions" or "chmod"
3. Set to 755 (or 0755)
4. Click "Apply"

#### Via SSH (if available)

```bash
cd /path/to/intake
chmod -R 755 uploads logs
```

### Step 4: Verify Installation

1. Test database connection:
   - If there's an error, check `logs/php_errors.log`

2. Test endpoint with cURL (from your local machine):

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

Expected response:
```json
{
  "success": true,
  "message": "Registration submitted successfully",
  "data": {
    "id": "uuid-here",
    "email": "test@example.com",
    "exam_level": "N5",
    "test_date": "2024-12-15"
  }
}
```

3. Check that files were uploaded:
   - `uploads/photos/` should contain a file
   - `uploads/ids/` should contain a file

4. Check database:
   - In phpMyAdmin, browse the `registrations` table
   - Should see 1 row with the test data

### Step 5: Update Frontend

Update the intake service URL in the frontend to match your directory structure:

1. Open `frontend/js/registration.js`
2. Find the intakeUrl variable around line 604
3. Update the path based on your setup:

**If intake is a subdirectory under frontend (recommended):**
```javascript
const intakeUrl = 'intake/register.php';
```

**If intake is at the same level as frontend:**
```javascript
const intakeUrl = '../intake/register.php';
```

**If intake is at the root level:**
```javascript
const intakeUrl = '/intake/register.php';
```

**If intake is on a different domain/subdomain:**
```javascript
const intakeUrl = 'https://intake.your-domain.com/register.php';
```

### Step 6: Test End-to-End

1. Open the registration form in a browser
2. Fill out all 4 steps with valid data
3. Upload test files (photo and ID)
4. Submit the form
5. Should see success modal
6. Check database for new record
7. Check upload directories for files

## Troubleshooting

### 500 Internal Server Error

**Check logs first:**
```bash
tail -f logs/php_errors.log
```

**Common causes:**

1. Database connection failed
   - Verify `.env` credentials are correct
   - Check database user has proper permissions
   - Verify database hostname (sometimes not localhost)

2. Missing PHP extensions
   - Contact hosting support to enable MySQLi, Fileinfo

3. File permissions error
   - Ensure `logs/` and `uploads/` are writable (755 or 777)

### File Upload Fails

1. Check PHP upload limits in `phpinfo()`:
   - `upload_max_filesize` should be >= 6M
   - `post_max_size` should be >= 8M

2. Verify upload directories exist and are writable

3. Check file size doesn't exceed 5MB limit

### CORS Errors

1. Update `.htaccess` with correct frontend domain:

```apache
Header set Access-Control-Allow-Origin "https://your-frontend-domain.com"
```

2. If frontend and intake are on same domain, use relative path in JavaScript

### Rate Limiting Issues

If legitimate users are being blocked:

1. Edit `.env`:
```env
RATE_LIMIT_MINUTE=10
RATE_LIMIT_DAY=50
```

2. Clear PHP sessions if needed (contact hosting support or delete session cookies)

### .htaccess Not Working

1. Verify Apache `AllowOverride` is enabled
2. Check for syntax errors in `.htaccess`
3. Ensure file is named `.htaccess` (not `.htaccess.txt`)

## Security Checklist

Before going live, verify:

- [ ] `.env` file is not accessible via browser
- [ ] `uploads/` directory is protected (can't access files directly)
- [ ] `logs/` directory is protected
- [ ] Database user has only INSERT+SELECT (no DROP/DELETE)
- [ ] HTTPS is enabled for the domain
- [ ] CORS is restricted to frontend domain only
- [ ] Rate limiting is enabled
- [ ] Error display is off (errors logged only)
- [ ] Test submission works and data is stored
- [ ] Honeypot field exists and is hidden in frontend form

## Maintenance

### Regular Tasks

**Weekly:**
- Check `logs/activity.log` for suspicious activity
- Monitor database size and growth

**Monthly:**
- Review and archive old registrations (via admin service)
- Check for PHP/security updates

**As needed:**
- Pull data for admin service
- Clean up failed uploads in `uploads/`

### Monitoring

Key metrics to watch:
- Daily submission count
- Rate limiting violations (check logs)
- Average file sizes
- Error rates

### Log Rotation

To prevent logs from growing too large:

```bash
# Via cron job (if available)
0 0 * * * mv /path/to/intake/logs/activity.log /path/to/intake/logs/activity-$(date +\%Y\%m\%d).log
```

Or manually download and clear logs periodically.

## Admin Service Integration

### Pull Database

```bash
# Dump from intake server
mysqldump -u intake_user -p nat_test_intake > intake_dump_$(date +%Y%m%d).sql

# Import to local admin database
sqlite3 admin.db <<EOF
.mode csv
.import intake_dump_$(date +%Y%m%d).sql registrations
EOF
```

### Pull Files

```bash
# Pull all uploads via rsync
rsync -avz --progress user@intake-server:/path/to/intake/uploads/ ./data/uploads/

# Or pull specific category
rsync -avz user@intake-server:/path/to/intake/uploads/photos/ ./data/photos/
```

## Support

For issues or questions:
- Check logs: `logs/php_errors.log` and `logs/activity.log`
- Review this deployment guide
- Contact hosting support for server-level issues

## Going Live

1. Remove any test registrations from the database
2. Clear log files
3. Update `ALLOWED_ORIGINS` in `.env` to production domain
4. Double-check all security settings
5. Perform one final test submission
6. Monitor first few live registrations closely
7. Keep regular backups of database and uploads

Good luck with your deployment!
