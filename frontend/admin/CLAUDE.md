# Admin Panel - Requirements & Architecture

Remote PHP-based admin panel for managing NAT-TEST registrations, content, and exam dates.

## Deployment
- **URL:** https://nat-test.ku.ac.bd/admin
- **Authentication:** Required (username/password)
- **User Management:** Add/remove admin users
- **Technology:** PHP 8.0+, MySQL, Sessions

## Core Features

### 1. Dashboard
- Total registrations count
- Approval rate percentage
- Revenue tracking (BDT 4,000 × approved registrations)
- Recent activity feed

### 2. Registration Management
**View & Filter:**
- List all registrations with filters:
  - Exam date
  - Exam level (1Q-5Q)
  - Status (pending, approved, rejected)
  - Date range
- Search by name, email, mobile

**Review Process:**
For each registration, verify:
- ✅ All information in correct format
- ✅ Uploaded photo meets specifications (passport-style, recent, clear face)
- ✅ ID document valid and readable (Passport/NID)
- ✅ Payment receipt uploaded and verified

**Actions:**
- **Approve:** Send confirmation email immediately
- **Reject:** Send rejection email with reasons
  - User can correct errors via email
  - Allow resubmission after corrections

**Export:**
- Export filtered/all registrations to CSV/Excel

### 3. Email Management
- View email history (sent to whom, when, type)
- Resend confirmation emails
- Email templates stored in database
- All emails sent via Khulna University SMTP

### 4. Exam Date & Level Management
**CRUD Operations:**
- Add new exam date: exam date, registration deadline
- Edit existing exam dates
- Delete exam dates (with safety confirmation)
- View all dates in table/list

**Level Assignment:**
- Checkbox selection for levels (1Q, 2Q, 3Q, 4Q, 5Q)
- Add/remove levels from each exam date
- Multiple levels can be offered per exam date

**Validation:**
- No duplicate exam dates
- Registration deadline must be before exam date
- At least one level must be selected

**Publishing:**
- Immediate - changes appear on public site instantly
- No separate "publish" step

### 5. Content Management
- Home page editor (already exists in current admin)
- Edit hero, benefits, resources, support sections
- Rich text editor
- Export to frontend/data/content.json

### 6. Admission Tickets
**Process:**
- After registrations approved, admin uploads admission tickets (PDFs)
- Bulk upload: one ZIP file containing all tickets for an exam date
- Filename format: `email_address.pdf` or `registration_id.pdf`
- One "Send Tickets" button to email all participants for that exam date

**Ticket Information:**
- Student name
- Photo (from registration)
- Exam date, time, venue
- Exam level
- Unique ticket/roll number
- Reporting time
- QR code (optional)

### 7. Participants View
- View list of approved registrations (participants)
- Filter by exam date, level
- Export to Excel for records
- No public display - admin only

## Authentication & Security

### User Authentication
- Username/password stored in `admin_users` table
- Passwords hashed with `password_hash()` (bcrypt)
- Session-based authentication
- Session timeout: 30 minutes inactive
- Session regeneration on login

### User Management
- Super admin: Can add/remove users
- Regular admin: Can perform all admin tasks except user management
- Users stored in database, not hardcoded

### Security Measures
- CSRF tokens on all forms
- SQL injection prevention (prepared statements)
- XSS prevention (htmlspecialchars, content-security-policy)
- Session hijacking prevention (regenerate_id, secure cookies)
- Password requirements: 8+ chars, mixed case, numbers
- Login rate limiting: 5 attempts per 15 minutes
- Audit log: all login attempts and critical actions

## Database Schema

### admin_users Table
```sql
CREATE TABLE admin_users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(100) NOT NULL,
    role ENUM('super_admin', 'admin') DEFAULT 'admin',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    login_attempts INT DEFAULT 0,
    locked_until TIMESTAMP NULL
);
```

### audit_log Table
```sql
CREATE TABLE audit_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    action VARCHAR(100) NOT NULL,
    table_name VARCHAR(50),
    record_id INT,
    old_values JSON,
    new_values JSON,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES admin_users(id)
);
```

### email_log Table
```sql
CREATE TABLE email_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    registration_id INT,
    email_type ENUM('confirmation', 'rejection', 'admission_ticket', 'resend') NOT NULL,
    recipient_email VARCHAR(100) NOT NULL,
    subject VARCHAR(255),
    body TEXT,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sent_by INT,
    status ENUM('sent', 'failed') DEFAULT 'sent',
    error_message TEXT,
    FOREIGN KEY (registration_id) REFERENCES registrations(id),
    FOREIGN KEY (sent_by) REFERENCES admin_users(id)
);
```

## File Structure
```
frontend/admin/
├── index.php              # Login page
├── dashboard.php          # Main dashboard
├── logout.php             # Logout handler
├── auth/                  # Authentication module
│   ├── login.php          # Login handler
│   ├── session.php        # Session management
│   └── middleware.php     # Auth middleware (include on protected pages)
├── pages/
│   ├── dashboard.php      # Dashboard with stats
│   ├── registrations.php  # Registration management
│   ├── exam-dates.php     # Exam dates & levels CRUD
│   ├── content.php        # Content management (existing)
│   ├── admission-tickets.php  # Ticket upload & email
│   └── participants.php   # View approved registrations
├── api/
│   ├── registrations/
│   │   ├── list.php       # Get filtered registrations
│   │   ├── approve.php    # Approve registration
│   │   ├── reject.php     # Reject registration
│   │   └── export.php     # Export to CSV
│   ├── exam-dates/
│   │   ├── list.php       # Get all exam dates
│   │   ├── create.php     # Add exam date
│   │   ├── update.php     # Edit exam date
│   │   └── delete.php     # Delete exam date
│   ├── users/
│   │   ├── list.php       # Get all users
│   │   ├── create.php     # Add user
│   │   └── delete.php     # Remove user
│   └── emails/
│       ├── history.php    # Get email log
│       └── resend.php     # Resend email
├── templates/
│   ├── header.php         # Common header
│   ├── footer.php         # Common footer
│   └── emails/            # Email templates
│       ├── confirmation.html.php
│       ├── rejection.html.php
│       └── admission_ticket.html.php
├── config.php             # Database connection, constants
├── functions.php          # Common functions
└── .env                   # Environment variables (gitignored)
```

## Environment Variables (.env)
```
DB_HOST=localhost
DB_NAME=nattest_regs
DB_USER=nattest_regs
DB_PASS=your_password_here

SMTP_HOST=smtp.ku.ac.bd
SMTP_PORT=587
SMTP_USER=nat-test@ku.ac.bd
SMTP_PASS=your_smtp_password
SMTP_FROM=nat-test@ku.ac.bd

SESSION_NAME=nat_test_admin
SESSION_LIFETIME=1800
```

## Security Best Practices

1. **Never expose admin panel without authentication**
2. **All database queries use prepared statements**
3. **All user output escaped with htmlspecialchars()**
4. **CSRF tokens on all POST/DELETE requests**
5. **File uploads validated (type, size, dimensions)**
6. **Passwords never logged or displayed**
7. **Session cookies: HttpOnly, Secure, SameSite**
8. **Login attempts logged and rate-limited**
9. **Critical actions logged to audit_log**
10. **Regular security audits and updates**

## Development Workflow

1. **Local Development:**
   - Use XAMPP/WAMP or PHP built-in server
   - Copy .env.example to .env
   - Create local MySQL database
   - Run seed script to create admin user

2. **Deployment:**
   - Deploy to /frontend/admin/ on server
   - Set proper file permissions (644 for files, 755 for directories)
   - Configure Apache/Nginx to block direct access to /api/, /config.php
   - Update .env with production credentials
   - Test authentication and all features

3. **Backup Strategy:**
   - Daily MySQL backups
   - Keep 30 days of backups
   - Store backups outside web root

## Migration from Streamlit Admin

**Removed:**
- Local-only Streamlit app
- SSH/rsync pull mechanism
- Manual content export

**Added:**
- Remote web-based admin panel
- Direct database access
- Real-time updates
- User authentication
- Email management
- Exam date management UI
- Admission ticket distribution
