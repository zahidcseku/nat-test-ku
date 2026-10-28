<?php
// Removed 2026-10-28 (security audit): superseded legacy registration
// endpoint. Its INSERT bound undefined variables and failed on the
// production schema, but it bypassed register.php's cap/duplicate/payment
// logic and wrote applicant data to logs/debug_*.json — one bugfix away
// from an unauthenticated writer into registrations. Inert placeholder
// deployed over the original so the server copy is overwritten.
// The live endpoint is register.php.
http_response_code(404);
exit;
