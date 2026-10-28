<?php
// Removed 2026-10-28 (security audit): debug script exposing session
// internals over HTTP without authentication. Inert placeholder deployed
// over the original so the exposed copy on the server is overwritten.
// Safe to delete from the server.
http_response_code(404);
exit;
