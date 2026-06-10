# Project architecture

Content-managed academic website with registration system. Three PHP-based services
on Khulna University server, coordinated through JSON contracts and shared database.

## Directory Structure
```
/Users/zahid/projects/NAT_TEST_KU/
├── frontend/         # Static website (deployed to server)
│   ├── intake/       # PHP-based intake service (INSIDE frontend directory)
│   └── admin/        # PHP-based admin panel (deployed to server)
└── admin_local/      # Local development scripts (not deployed)
```

**IMPORTANT:** `/intake/` is INSIDE `/frontend/` at `/frontend/intake/`, NOT at the project root

## Services

### /frontend/intake — PHP service on Khulna University server
- Location: `/frontend/intake/` (INSIDE frontend directory, NOT at root)
- Single responsibility: receive registration POSTs, write to MySQL database
- Endpoints: POST /register ONLY. No GET, no admin UI, no data exposure.
- Runs behind HTTPS on nat-test.ku.ac.bd
- Database: nattest_regs MySQL database (user: nattest_reg)
- Rate-limited, honeypot-protected, input-validated
- Technology: PHP with mysqli

### /frontend/admin — PHP admin panel on Khulna University server
- Location: `/frontend/admin/` (deployed at https://nat-test.ku.ac.bd/admin)
- Authentication required: username/password stored in database
- User management: add/remove admin users
- Multi-page app: Dashboard | Registrations | Exam Dates | Content | Admission Tickets
- Reads/writes to nattest_regs MySQL database (user: nattest_reg)
- Sends emails via Khulna University SMTP
- Technology: PHP with mysqli, sessions for auth

### /frontend — Static site on Khulna University server
- Pure HTML/CSS/JS + pre-generated JSON
- No build step, no framework
- Served as static files (Nginx or Apache)
- Registration form POSTs to /intake/register.php
- Data loaded dynamically from PHP endpoints (schedule, resources)

## Data flow
Public registration → /intake/register.php (MySQL)
                              ↓
                    /admin authentication & review
                              ↓
                    Email confirmations/rejections
                              ↓
                    Admission ticket upload & email
                              ↓
                    Exam dates managed via admin UI

## Hard constraints — do not violate
- /frontend must never write to any database
- /intake must never expose reads over HTTP
- /admin must always require authentication
- No third-party data stores (Supabase, Formspree, Firebase, etc.)
- No JS frameworks in /frontend (no React, Vue, Next, etc.)
- Secrets live in per-service .env files, all gitignored
- *.db, .env files are gitignored

## Conventions
- PHP 8.0+ with type hints
- MySQL with mysqli, parameterized queries only
- Environment variables via PHP getenv(), never hardcoded
- Passwords hashed with password_hash() (bcrypt)
- Session-based authentication with session_regenerate_id()
- Prepared statements for ALL database queries (no SQL injection)
