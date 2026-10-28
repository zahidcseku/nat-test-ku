<?php
// Removed 2026-10-28 (security audit): this diagnostic revealed the server
// path and the first characters of every .env value, including DB_PASS and
// SMTP_PASS, over HTTP without authentication. Inert placeholder deployed
// over the original so the exposed copy on the server is overwritten.
// Safe to delete from the server.
http_response_code(404);
exit;
