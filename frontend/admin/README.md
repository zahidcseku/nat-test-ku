# NAT-TEST Admin Panel

Remote PHP-based administration panel for managing NAT-TEST registrations, exam dates, and content.

## Quick Start

### 1. Install Dependencies
- PHP 8.0 or higher
- MySQL 5.7 or higher
- Apache or Nginx web server

### 2. Configure Environment
```bash
cd frontend/admin
cp .env.example .env
# Edit .env with your database and SMTP credentials
```

### 3. Create Database Tables
```bash
mysql -u root -p nattest_regs < schema.sql
```

### 4. Create First Admin User
```bash
php scripts/create_admin.php
# Follow prompts to create super admin user
```

### 5. Deploy
Upload entire `/frontend/admin/` directory to server at `nat-test.ku.ac.bd/admin`

### 6. Login
Visit: `https://nat-test.ku.ac.bd/admin/`
Default credentials: (what you created in step 4)

## Features

### 📊 Dashboard
- Overview statistics (registrations, approval rate, revenue)
- Recent activity feed
- Quick actions

### 📝 Registration Management
- View all registrations with advanced filters
- Approve/reject registrations with email notifications
- Verify: information, photo, ID, payment
- Export to CSV/Excel
- Email history tracking

### 📅 Exam Date Management
- Add/edit/delete exam dates
- Assign exam levels (1Q-5Q) via checkboxes
- Validation prevents invalid dates
- Changes appear on public site immediately

### ✉️ Email Management
- View email history
- Resend confirmation emails
- Email templates stored in database
- All emails sent via Khulna University SMTP

### 🎫 Admission Tickets
- Upload admission tickets (PDFs) for approved participants
- Bulk upload via ZIP file
- One-click email to all participants for an exam date

### 👥 Participants
- View approved registrations
- Filter by exam date, level
- Export for records

### 📄 Content Management
- Edit home page content
- Manage hero, benefits, resources, support sections
- Rich text editor
- Export to frontend

## Security

### Authentication
- Username/password stored in database
- Passwords hashed with bcrypt
- Session-based authentication with 30-minute timeout
- Login rate limiting: 5 attempts per 15 minutes

### Authorization
- Super admin: Can manage users
- Regular admin: All tasks except user management
- All pages protected by authentication middleware

### Data Protection
- All database queries use prepared statements
- CSRF tokens on all forms
- XSS prevention on all user output
- File upload validation
- Audit logging for critical actions

## User Management

### Add New Admin User
```php
// Via admin interface: Users → Add User
// Or via command line:
php scripts/create_admin.php
```

### Remove Admin User
```php
// Via admin interface: Users → List → Delete
// Or via command line:
php scripts/remove_admin.php username
```

### Reset Password
```php
// Via admin interface: Users → List → Reset Password
// Or via command line:
php scripts/reset_password.php username
```

## Database Schema

### Main Tables
- **admin_users** - Admin user accounts
- **audit_log** - Security audit trail
- **email_log** - Email sending history
- **registrations** - Registration submissions (from intake)
- **exam_dates** - Exam dates and deadlines
- **exam_levels** - Levels offered for each exam date
- **content_blocks** - Home page content

## API Endpoints

### Registration APIs
- `GET /api/registrations/list.php` - List registrations with filters
- `POST /api/registrations/approve.php` - Approve registration
- `POST /api/registrations/reject.php` - Reject registration
- `GET /api/registrations/export.php` - Export to CSV

### Exam Date APIs
- `GET /api/exam-dates/list.php` - List all exam dates
- `POST /api/exam-dates/create.php` - Add exam date
- `POST /api/exam-dates/update.php` - Update exam date
- `POST /api/exam-dates/delete.php` - Delete exam date

### User Management APIs
- `GET /api/users/list.php` - List all admin users
- `POST /api/users/create.php` - Add new user
- `POST /api/users/delete.php` - Remove user

### Email APIs
- `GET /api/emails/history.php` - Get email log
- `POST /api/emails/resend.php` - Resend email

## File Upload Handling

### Admission Tickets
- Format: ZIP file containing individual PDF tickets
- Naming: `email_address.pdf` or `registration_id.pdf`
- Max size: 50MB
- Validation: PDF format, file size limits

## Email Templates

Located in `/templates/emails/`:
- `confirmation.html.php` - Registration approval
- `rejection.html.php` - Registration rejection with reasons
- `admission_ticket.html.php` - Admission ticket delivery

## Backup Strategy

### Automated Backups
- Daily MySQL dumps via cron
- Retained for 30 days
- Stored outside web root

### Manual Backup
```bash
# Backup database
mysqldump -u root -p nattest_regs > backup_$(date +%Y%m%d).sql

# Backup admin uploads
tar -czf uploads_$(date +%Y%m%d).tar.gz uploads/
```

## Troubleshooting

### Login Issues
```bash
# Check if user exists
php scripts/check_user.php username

# Reset password
php scripts/reset_password.php username

# Check login attempts
php scripts/show_login_attempts.php username
```

### Database Connection Errors
1. Verify .env file exists and is readable
2. Check MySQL service is running
3. Verify database credentials
4. Check database exists: `SHOW DATABASES;`

### Email Not Sending
1. Verify SMTP settings in .env
2. Check SMTP credentials are correct
3. Check email_log table for error messages
4. Verify SMTP port (587 for STARTTLS) is not blocked

### Permission Errors
```bash
# Set proper permissions
chmod 644 *.php
chmod 755 api/ pages/ templates/
chmod 600 .env

# uploads directory must be writable
chmod 755 uploads/
```

## Development

### Local Development Setup
1. Install XAMPP/WAMP or use PHP built-in server
2. Create local MySQL database
3. Copy .env.example to .env and configure
4. Import schema: `mysql -u root -p nattest_regs < schema.sql`
5. Create admin user: `php scripts/create_admin.php`
6. Start PHP server: `php -S localhost:8000`
7. Visit: `http://localhost:8000/`

### Testing
```bash
# Run tests (if implemented)
phpunit tests/

# Check for syntax errors
find . -name "*.php" -exec php -l {} \;
```

## Deployment Checklist

- [ ] Update .env with production credentials
- [ ] Create MySQL database and user
- [ ] Import schema.sql
- [ ] Set proper file permissions
- [ ] Configure web server (Apache/Nginx)
- [ ] Test authentication
- [ ] Test email sending
- [ ] Test file uploads
- [ ] Set up automated backups
- [ ] Configure HTTPS
- [ ] Set up monitoring/logging

## Maintenance

### Regular Tasks
- Review audit_log for suspicious activity
- Check email_log for failed sends
- Verify backups are running
- Review and clean up old uploads
- Update PHP dependencies (if using Composer)

### Security Updates
- Keep PHP version updated
- Update MySQL regularly
- Review and update password policies
- Rotate SMTP passwords periodically
- Review admin user list and remove inactive accounts

## Support

For issues or questions:
- Email: tech-support@nat-test.ku.ac.bd
- Documentation: See CLAUDE.md for detailed architecture

## License

Internal use - Khulna University NAT-TEST Center
