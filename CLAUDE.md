# Project architecture

Content-managed academic website with registration system. Three independent
services, coordinated only through JSON contracts and SSH-based sync.

## Directory Structure
```
/Users/zahid/projects/NAT_TEST_KU/
├── admin/            # Streamlit admin interface (local only)
└── frontend/         # Static website with intake service (deployed to server)
    └── intake/       # PHP-based intake service (INSIDE frontend directory)
```

**IMPORTANT:** `/intake/` is INSIDE `/frontend/` at `/frontend/intake/`, NOT at the project root

## Services

### /frontend/intake — PHP service on Khulna University server
- Location: `/frontend/intake/` (INSIDE frontend directory, NOT at root)
- Single responsibility: receive registration POSTs, write to SQLite inbox
- Endpoints: POST /register ONLY. No GET, no admin UI, no data exposure.
- Runs behind HTTPS on a ku.ac.bd subdomain
- Database: /var/intake/inbox.db (or equivalent path per IT constraints)
- Rate-limited, honeypot-protected, input-validated
- Technology: PHP (not FastAPI as previously documented)

### /admin — Streamlit on developer laptop
- LOCAL ONLY. Never deployed. Never network-exposed.
- Source of truth for all content decisions and approvals
- Pulls intake inbox via SSH/rsync; never accepts inbound connections
- SMTP: Khulna University SMTP server, credentials in admin/.env
- Multi-page app: Content | Registrations | Publish

### /frontend — Static site on Khulna University server
- Pure HTML/CSS/JS + pre-generated JSON
- No build step, no framework, no auth
- Served as static files (Nginx or Apache, depends on IT setup)
- Registration form POSTs to /intake; participant list read from /data/participants.json

## Data flow (strictly one-directional)
Public user → /intake inbox (write-only endpoint)
                ↓ rsync pull
           local admin DB (review, approve, annotate)
                ↓ export
           frontend/data/*.json
                ↓ rsync push
           public site

## Hard constraints — do not violate
- /frontend must never write to any database
- /intake must never expose reads over HTTP
- /admin must never be deployed or reachable from the network
- No third-party data stores (Supabase, Formspree, Firebase, etc.)
- No JS frameworks in /frontend (no React, Vue, Next, etc.)
- No authentication in /frontend or /intake
- Secrets live in per-service .env files, all gitignored
- *.db, .env, frontend/data/, frontend/media/ are gitignored

## Conventions
- Python 3.11+ with type hints
- Ruff for linting, Black for formatting
- SQLite with sqlite3.Row row_factory, parameterized queries only
- Environment variables via python-dotenv, never hardcoded