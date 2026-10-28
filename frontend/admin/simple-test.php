<?php
// Removed 2026-10-28 (security audit): this diagnostic printed DB host,
// database name, user and the registration count over HTTP without
// authentication. Inert placeholder deployed over the original so the
// exposed copy on the server is overwritten. Safe to delete from the server.
http_response_code(404);
exit;
