<?php
/**
 * Certificate request flow — pure-logic smoke tests.
 *
 * Validates the input checks and routing logic embedded in:
 *   - certificate-verify.php (UUID / name / DOB format gate)
 *   - certificate-request.php (UUID / phone / length gate)
 *   - payment-ipn.php (tran_id prefix dispatch)
 *
 * These do NOT hit the database — they pin the validation regexes so a
 * future refactor can't silently loosen them. For an end-to-end test
 * including the SSLCommerz round-trip, see test_ipn_handler.php.
 *
 * Run: php frontend/intake/test/test_certificate_verify.php
 */

$pass = true;
$check = function ($label, $cond) use (&$pass) {
    echo ($cond ? 'PASS' : 'FAIL') . ": $label\n";
    if (!$cond) $pass = false;
};

// Mirror the regexes used in the endpoints (kept in sync by hand).
$uuidRegex     = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
$phoneRegex    = '/^(\+?880|0)?1[3-9]\d{8}$/';
$dobRegex      = '/^(\d{4})-(\d{2})-(\d{2})$/';

// --- UUID format (exam_date_id, registration_id) --------------------
$uuid = '12345678-1234-4321-abcd-1234567890ab';
$check('valid UUID passes', preg_match($uuidRegex, $uuid) === 1);
$check('short UUID rejected', preg_match($uuidRegex, 'not-a-uuid') === 0);
$check('empty rejected',      preg_match($uuidRegex, '') === 0);

// --- Phone (BD) -----------------------------------------------------
$check('plain 01x accepted',       preg_match($phoneRegex, '01712345678')   === 1);
$check('+880 accepted',            preg_match($phoneRegex, '+8801712345678') === 1);
$check('880 (no plus) accepted',   preg_match($phoneRegex, '8801712345678')  === 1);
$check('land line rejected',       preg_match($phoneRegex, '02123456')       === 0);
$check('too short rejected',       preg_match($phoneRegex, '017123')         === 0);
$check('letters rejected',         preg_match($phoneRegex, 'abcdefghijk')    === 0);
$check('leading 02 rejected (not mobile)', preg_match($phoneRegex, '02712345678') === 0);

// --- DOB (YYYY-MM-DD) ----------------------------------------------
$check('DOB YYYY-MM-DD accepted',  preg_match($dobRegex, '1995-08-15') === 1);
$check('DOB slash format rejected', preg_match($dobRegex, '1995/08/15') === 0);
$check('DOB 2-digit year rejected', preg_match($dobRegex, '95-08-15')   === 0);
// checkdate on the matched groups
$ok = preg_match($dobRegex, '1995-13-40', $m);
$check('regex alone does not catch invalid month/day', $ok === 1);
$check('checkdate catches invalid 1995-13-40', $ok === 1 && !checkdate((int)$m[2], (int)$m[3], (int)$m[1]));

// --- tran_id prefix routing (CRT vs NAT) ----------------------------
// Mirrors the dispatch in payment-ipn.php and the page routing in payment-return.php.
$isCertificateTran = static function ($tranId) {
    return strpos($tranId, 'CRT') === 0;
};
$check('CRT-prefixed routes to certificate handler',
    $isCertificateTran('CRT20260728123000abc12345') === true);
$check('NAT-prefixed stays on registration path',
    $isCertificateTran('NAT20260728123000abc12345') === false);
$check('empty tran_id stays on registration path',
    $isCertificateTran('') === false);
$check('mid-string CRT not confused for prefix',
    $isCertificateTran('NAT-CRT-12345') === false);

// --- Required address length gate (certificate-request.php) ---------
$lengthOk = static function ($name, $house, $area, $district, $postal) {
    return strlen($name) >= 2 && strlen($name) <= 200
        && strlen($house) >= 3 && strlen($house) <= 300
        && strlen($area) >= 2 && strlen($area) <= 200
        && strlen($district) >= 2 && strlen($district) <= 100
        && strlen($postal) <= 20;
};
$check('normal address accepted',
    $lengthOk('Jane Doe', '123 Main St', 'Daulatpur', 'Khulna', '9204') === true);
$check('postal code optional',
    $lengthOk('Jane Doe', '123 Main St', 'Daulatpur', 'Khulna', '') === true);
$check('single-char name rejected',
    $lengthOk('J', '123 Main St', 'Daulatpur', 'Khulna', '9204') === false);
$check('empty street rejected',
    $lengthOk('Jane Doe', '', 'Daulatpur', 'Khulna', '9204') === false);

exit($pass ? 0 : 1);
