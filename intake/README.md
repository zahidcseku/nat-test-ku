# NAT-TEST Intake Service

PHP backend service for receiving and storing registration applications. Part of the three-service architecture (intake → admin → frontend).

## Features

- Secure registration form submission handling
- MySQL database storage
- File upload management (photos, IDs, payment receipts)
- Rate limiting (5 requests/minute, 20/day per IP)
- Honeypot protection against bots
- IP hashing for privacy
- SQL injection prevention with prepared statements
- XSS prevention
- Secure file upload with magic byte validation

## Requirements

- PHP 7.4+ or 8.0+
- MySQL 5.7+ or MariaDB 10.2+
- MySQLi extension
- Fileinfo extension
- GD library (for image validation)
- Apache web server with .htaccess support

## Installation

### 1. Database Setup

Create a MySQL database using your hosting control panel:

```sql
-- Run the init.sql script to create the registrations table
-- You can do this via phpMyAdmin or your hosting's MySQL Manager
```

Create a database user with INSERT-only privileges:

```sql
CREATE USER 'intake_user'@'localhost' IDENTIFIED BY 'your_secure_password';
GRANT INSERT ON nat_test_intake.* TO 'intake_user'@'localhost';
GRANT SELECT ON nat_test_intake.* TO 'intake_user'@'localhost';
FLUSH PRIVILEGES;
```

### 2. File Upload

Upload all files to your hosting server:

- Via FTP/SFTP: Upload the entire `/intake/` directory
- Via hosting file manager: Zip the directory and upload

### 3. Configuration

Create the `.env` file:

```bash
cp .env.example .env
```

Edit `.env` with your actual database credentials:

```env
DB_HOST=localhost
DB_NAME=nat_test_intake
DB_USER=intake_user
DB_PASS=your_secure_database_password
IP_SALT=generate_random_salt_with_openssl_rand_hex_32
```

### 4. Set Directory Permissions

Ensure the following directories are writable:

```bash
chmod 755 uploads/
chmod 755 uploads/photos/
chmod 755 uploads/ids/
chmod 755 uploads/receipts/
chmod 755 logs/
```

### 5. Test the Endpoint

Send a test POST request to verify installation:

```bash
curl -X POST https://your-domain.com/intake/register.php \
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
    "id": "uuid-v4-here",
    "email": "test@example.com",
    "exam_level": "N5",
    "test_date": "2024-12-15"
  }
}
```

## Directory Structure

```
intake/
├── register.php          # Main POST endpoint
├── config.php            # Configuration and database connection
├── validate.php          # Input validation functions
├── upload.php            # File upload handling
├── security.php          # Security functions (rate limiting, etc.)
├── init.sql              # Database schema
├── .env.example          # Environment template
├── .htaccess             # Apache security rules
├── README.md             # This file
├── migrations/           # Database migration scripts
│   └── add_approval_fields.sql
├── uploads/              # File upload directory (protected)
│   ├── photos/
│   ├── ids/
│   └── receipts/
└── logs/                 # Application logs
    ├── php_errors.log
    └── activity.log
```

## Security Features

### Rate Limiting
- 5 requests per minute per IP
- 20 requests per day per IP
- Automatic reset using PHP sessions

### Honeypot Protection
- Hidden 'website' field detects bots
- Bots filling this field are blocked silently

### IP Hashing
- Client IP addresses are hashed with SHA256
- Uses salt from .env for privacy protection

### File Upload Security
- Magic byte validation (not just extension)
- 5MB file size limit
- UUID-based filenames
- Direct access blocked via .htaccess

### SQL Injection Prevention
- MySQLi prepared statements only
- No direct string concatenation in queries

### XSS Prevention
- htmlspecialchars() on all text inputs
- Content-Security-Policy headers

## Admin Integration

The admin service can pull data from the intake service:

### Pull Database

```bash
# Dump the intake database
mysqldump -u intake_user -p nat_test_intake > intake_dump.sql

# Import into admin database
mysql -u admin_user -p nat_test_admin < intake_dump.sql
```

### Pull Uploaded Files

```bash
# Pull all uploaded files via rsync
rsync -avz user@intake-server:/path/to/intake/uploads ./data/
```

### Approval Workflow

All new registrations start with `approved = 0` (pending). The admin service should:

1. **Pull pending registrations**: Query where `approved = 0`
2. **Review applications**: Display photos, IDs, and payment receipts
3. **Approve or reject**: Update the `approved` field:
   ```sql
   -- Approve application
   UPDATE registrations
   SET approved = 1,
       approved_at = CURRENT_TIMESTAMP,
       approved_by = 'admin_username'
   WHERE id = 'application_uuid';

   -- Reject application (optional: add rejected_at field)
   UPDATE registrations
   SET approved = 0
   WHERE id = 'application_uuid';
   ```

4. **Export approved registrations**: Pull only `approved = 1` records for frontend display

### Database Migration

If you deployed before the approval feature was added, run the migration:

```bash
# Via phpMyAdmin: Import the migrations/add_approval_fields.sql file
# Or via command line:
mysql -u intake_user -p nat_test_intake < migrations/add_approval_fields.sql
```

## Troubleshooting

### 500 Internal Server Error

Check the error log:
```bash
tail -f logs/php_errors.log
```

Common causes:
- Database connection failed (check .env credentials)
- Missing PHP extensions (check MySQLi, Fileinfo)
- Directory permissions (logs/ and uploads/ must be writable)

### File Upload Fails

- Check PHP upload_max_filesize and post_max_size
- Verify uploads/ subdirectories exist and are writable
- Check file type and size limits

### Rate Limiting Too Strict

Edit the values in `.env`:
```env
RATE_LIMIT_MINUTE=10
RATE_LIMIT_DAY=50
```

## License

Copyright © 2024 NAT-TEST Centre. All rights reserved.
