# Intake service

FastAPI server that accepts registration submissions. Minimal surface area
by design — this is the only internet-exposed Python code in the project.

## Scope
- ONE endpoint: POST /register
- Validates, rate-limits, writes to SQLite, returns 200 or error
- That's it. No listing, no deleting, no admin.

## Schema
Registration fields: [TO DEFINE — fill in based on the actual form]
Plus: id (uuid), submitted_at (utc timestamp), ip_hash (sha256 of IP + salt),
user_agent, honeypot_tripped (bool)

## Security posture
- Pydantic models validate every field; reject unknown fields
- slowapi rate limit: 5 req/min per IP, 20 req/day per IP
- Honeypot field 'website' — if filled, silently accept but mark tripped
- No PII in logs — log only request counts, status codes, ip_hash (not IP)
- CORS: allow only the production frontend origin + localhost for dev
- No stack traces in responses

## Operational
- systemd unit or equivalent per IT constraints
- Nightly SQLite backup via cron: sqlite3 inbox.db .dump | gzip
- Logs to stdout → journald / log file per IT setup

## Do not
- Do not add endpoints beyond POST /register
- Do not add database reads via HTTP
- Do not import or depend on anything in /admin or /frontend
- Do not use async ORM complexity — raw sqlite3 or a tiny wrapper is enough