# NAT-TEST Admin Panel - Deployment Guide

## Quick Deployment

### 1. Prerequisites
- PHP 8.0 or higher
- MySQL 5.7 or higher
- Apache or Nginx with mod_rewrite
- Access to nattest_regs database

### 2. Upload Files
```bash
# Upload entire admin directory to server
scp -r frontend/admin/ user@nat-test.ku.ac.bd:/var/www/html/frontend/
```

### 3. Configure Environment
```bash
ssh user@nat-test.ku.ac.bd

# Navigate to admin directory
cd /var/www/html/frontend/admin

# Copy environment template
cp .env.example .env

# Edit with your credentials
nano .env
```

Edit `.env` file:
```bash
DB_HOST=localhost
DB_NAME=nattest_regs
DB_USER=nattest_reg
DB_PASS=your_mysql_password

SMTP_HOST=smtp.ku.ac.bd
SMTP_PORT=587
SMTP_USER=nat-test@ku.ac.bd
SMTP_PASS=your_smtp_password
SMTP_FROM=nat-test@ku.ac.bd
```

### 4. Set Permissions
```bash
# Set proper file permissions
find . -type f -name "*.php" -exec chmod 644 {} \;
find . -type d \( -name api -o -name pages -o -name templates -o -name uploads \) -exec chmod 755 {} \;
chmod 600 .env
chmod 755 uploads/

# Make setup script executable
chmod +x setup.php
```

### 5. Initialize Database
```bash
# Run setup script
php setup.php

# Follow prompts to create first admin user
```

### 6. Configure Web Server

**Apache (already configured with .htaccess):**
```bash
# Ensure mod_rewrite is enabled
sudo a2enmod rewrite
sudo systemctl restart apache2
```

**Nginx:**
Add to your server config:
```nginx
location /admin {
    try_files $uri $uri/ /admin/index.php?$query_string;

    # Block direct access to sensitive files
    location ~* /admin/\.(env|sql|md)$ {
        deny all;
    }

    # Block direct access to config
    location ~* /admin/(config|functions)\.php$ {
        deny all;
    }
}
```

### 7. Test Installation
1. Visit: https://nat-test.ku.ac.bd/admin/
2. Login with the admin credentials you created
3. Verify dashboard loads correctly

## Troubleshooting

### Database Connection Failed
```bash
# Check MySQL is running
sudo systemctl status mysql

# Test connection
mysql -u nattest_reg -p nattest_regs

# Check credentials in .env
cat .env
```

### Permission Errors
```bash
# Check ownership
ls -la uploads/

# Fix permissions
sudo chown -R www-data:www-data uploads/
chmod -R 755 uploads/
```

### 500 Internal Server Error
```bash
# Check error logs
tail -f /var/log/apache2/error.log
# or
tail -f /var/log/nginx/error.log

# Common issues:
# - .env file missing
# - Database connection failed
# - File permissions wrong
```

### Login Issues
```bash
# Check if users exist
mysql -u nattest_reg -p nattest_regs -e "SELECT username, email FROM admin_users;"

# Reset password (run setup.php again or use PHP script)
php -r "require 'config.php'; echo password_hash('newpassword', PASSWORD_DEFAULT);"
```

## Security Checklist

- ✅ .env file is not accessible via web
- ✅ Database user has minimal required permissions
- ✅ File uploads restricted to PDF/ZIP only
- ✅ CSRF protection enabled on all forms
- ✅ Passwords hashed with bcrypt
- ✅ Session cookies are HttpOnly and Secure
- ✅ Login rate limiting enabled
- ✅ Audit logging enabled
- ✅ Admin panel behind HTTPS only

## Maintenance

### Regular Backups
```bash
# Add to crontab (crontab -e)
0 2 * * * mysqldump -u root -p nattest_regs > /backups/nattest_regs_$(date +\%Y\%m\%d).sql
```

### Monitor Logs
```bash
# View audit log
mysql -u nattest_reg -p nattest_regs -e "SELECT * FROM audit_log ORDER BY created_at DESC LIMIT 20;"

# View failed login attempts
mysql -u nattest_reg -p nattest_regs -e "SELECT * FROM login_attempts WHERE success = 0 ORDER BY attempted_at DESC LIMIT 20;"
```

### Update Admin Panel
```bash
# Pull latest changes
cd /var/www/html/frontend/admin
git pull

# Check for schema updates
php setup.php
```

## URL Structure

```
https://nat-test.ku.ac.bd/admin/
├── index.php              # Login page
├── dashboard.php          # Main dashboard
├── pages/
│   ├── registrations.php  # Manage registrations
│   ├── exam-dates.php     # Manage exam dates
│   ├── admission-tickets.php  # Upload tickets
│   ├── participants.php   # View approved registrations
│   └── content.php        # Content management (placeholder)
└── api/
    └── registrations/
        └── export.php     # Export to CSV
```

## Support

For issues or questions:
- Email: tech-support@nat-test.ku.ac.bd
- Documentation: See CLAUDE.md and README.md
