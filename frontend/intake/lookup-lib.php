<?php
/**
 * NAT-TEST Intake Service - Application Lookup Helpers
 *
 * Pure matching logic for the applicant self-service lookup
 * (application-lookup.php). Kept separate so it can be unit-tested
 * without executing the endpoint.
 */

// Prevent direct access
if (!defined('INTAKE_SERVICE')) {
    exit('Direct access not permitted');
}

/**
 * Normalize a Bangladeshi mobile number for comparison: digits only,
 * with the international prefix (880...) collapsed to the local 0... form.
 */
function normalizeBdMobile(string $mobile): string {
    $digits = preg_replace('/\D+/', '', $mobile);
    if (strlen($digits) === 13 && strpos($digits, '880') === 0) {
        $digits = '0' . substr($digits, 3);
    }
    return $digits;
}

/**
 * True when two mobile numbers refer to the same line in any accepted format.
 */
function mobilesMatch(string $a, string $b): bool {
    $na = normalizeBdMobile($a);
    $nb = normalizeBdMobile($b);
    return $na !== '' && $na === $nb;
}

/**
 * Canonicalize a lookup name exactly the way registration stores names
 * (validate.php validateRequired: trim then htmlspecialchars), so names
 * with quotes/apostrophes compare correctly against stored values.
 */
function canonicalLookupName(string $name): string {
    return htmlspecialchars(trim($name), ENT_QUOTES, 'UTF-8');
}

/**
 * An exam is "upcoming" when its test date is today or later.
 */
function isUpcomingTestDate(string $testDate, ?string $today = null): bool {
    $today = $today ?: date('Y-m-d');
    return $testDate >= $today;
}
