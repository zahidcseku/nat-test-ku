<?php
// Removed 2026-10-28 (security audit): browser debug script with hardcoded
// database credentials and unauthenticated table dumps. Inert placeholder
// deployed over the original so the exposed copy on the server is
// overwritten. Safe to delete from the server.
http_response_code(404);
exit;
