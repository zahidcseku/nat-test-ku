<?php
// Removed 2026-10-28 (security audit): payment-chain test endpoint.
// Key-gated (PAYMENT_TEST_KEY), but it created real registrations rows
// and real SSLCommerz sessions in production when enabled. If a sandbox
// payment test is needed again, restore it from git history and run it
// locally or behind the test key on a staging host — it should not ship.
// Inert placeholder deployed over the original so the server copy is
// overwritten.
http_response_code(404);
exit;
