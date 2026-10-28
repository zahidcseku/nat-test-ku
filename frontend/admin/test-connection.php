<?php
// Removed 2026-10-28 (security audit): this diagnostic ran with
// display_errors on and dumped table schemas and a sample registrant's
// PII over HTTP without authentication. Inert placeholder deployed over
// the original so the exposed copy on the server is overwritten.
// Safe to delete from the server.
http_response_code(404);
exit;
