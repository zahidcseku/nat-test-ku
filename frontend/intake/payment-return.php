<?php
/**
 * SSLCommerz Browser Return Endpoint
 *
 * SSLCommerz sends the customer's browser back to success_url/fail_url/
 * cancel_url with a POST request. The result pages are static HTML, which
 * web servers refuse to serve for POST (405). This endpoint accepts the
 * POST and issues a 303 redirect (POST -> GET) to the matching static page.
 *
 * No database access here by design: the authoritative payment status is
 * set server-to-server by payment-ipn.php. This endpoint only routes the
 * user's browser to the right page.
 */

// Define service constant
define('INTAKE_SERVICE', true);

// Load dependencies
require_once __DIR__ . '/config.php';

// Which outcome page to show, set via query string on the configured
// return URLs (e.g. /intake/payment-return.php?outcome=success)
$outcomePages = [
    'success' => '/payment-success.html',
    'fail' => '/payment-failed.html',
    'cancel' => '/payment-cancelled.html'
];

// Certificate requests use their own return pages so the copy matches the flow.
$certificateOutcomePages = [
    'success' => '/certificate-success.html',
    'fail' => '/certificate-failed.html',
    'cancel' => '/certificate-cancelled.html'
];

$outcome = $_GET['outcome'] ?? '';
if (!array_key_exists($outcome, $outcomePages)) {
    // Unknown outcome: never show success by accident
    $outcome = 'fail';
}

// Sanitize gateway POST fields used for logging and the redirect target
$tranId = preg_replace('/[^A-Za-z0-9_-]/', '', $_POST['tran_id'] ?? '');
$tranId = substr($tranId, 0, 50);
$gatewayStatus = preg_replace('/[^A-Za-z_]/', '', $_POST['status'] ?? '');
$gatewayStatus = substr($gatewayStatus, 0, 20);

// Route by tran_id prefix: 'CRT' -> certificate pages, else -> registration pages.
$pages = (strpos($tranId, 'CRT') === 0) ? $certificateOutcomePages : $outcomePages;

logActivity("Payment return ({$outcome}) for transaction: " . ($tranId !== '' ? $tranId : 'unknown')
    . ($gatewayStatus !== '' ? ", gateway status: {$gatewayStatus}" : ''));

// Build redirect target; pass sanitized reference info for display purposes
$target = SITE_URL . $pages[$outcome];
$params = [];
if ($tranId !== '') {
    $params['tran_id'] = $tranId;
}
if ($gatewayStatus !== '') {
    $params['status'] = $gatewayStatus;
}
if (!empty($params)) {
    $target .= '?' . http_build_query($params);
}

// 303 See Other: instructs the browser to follow with GET
header('Location: ' . $target, true, 303);
exit;
