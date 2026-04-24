# Admin service

Local-only Streamlit app. The control center for the project. Runs on
developer's laptop, reads/writes local SQLite, talks to the intake server
via SSH and to the public host via rsync.

## Pages
1. Content — CRUD for projects, publications, news items
2. Registrations — pull new submissions, review, approve/reject, email, export
3. Publish — regenerate frontend/data/*.json, optimize images, rsync to host

## Local DB schema
Mirrors intake inbox, plus workflow columns:
- status: pending | approved | rejected
- reviewed_at, reviewed_by, admin_notes
- email_sent_at
Plus content tables: projects, publications, news, participants

## Sync
- Pull: rsync -avz --ignore-existing ku:/var/intake/inbox.db.latest ./data/
  Then merge new rows (by id) into local admin.db
- Never push writes back to intake server — local admin.db is source of truth
  after the pull

## Email
- Uses Khulna University SMTP
- Credentials in admin/.env: SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, SMTP_FROM
- Templates in admin/templates/emails/
- Always send via STARTTLS, never plaintext

## Publish
- Export approved participants + all content → frontend/data/*.json
- Resize/optimize images → frontend/media/
- rsync -avz --delete frontend/ ku:/var/www/site/
- Dry-run flag on the publish button — show diff before pushing

## Do not
- Do not expose Streamlit on 0.0.0.0 — bind to 127.0.0.1 only
- Do not commit admin.db, .env, or any pulled inbox snapshots
- Do not write back to the intake server's database