<?php
/**
 * Lookup matching helpers: mobile normalization, name canonicalization,
 * upcoming-exam check.
 * Run: php frontend/intake/test/test_application_lookup.php
 */
define('INTAKE_SERVICE', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lookup-lib.php';

$pass = true;
$check = function ($label, $cond) use (&$pass) {
    echo ($cond ? 'PASS' : 'FAIL') . ": $label\n";
    if (!$cond) $pass = false;
};

// Mobile normalization: +880 / 880 / 0 prefixes all converge
$check('plain 01x kept', normalizeBdMobile('01712345678') === '01712345678');
$check('+880 collapses', normalizeBdMobile('+8801712345678') === '01712345678');
$check('880 collapses', normalizeBdMobile('8801712345678') === '01712345678');
$check('spaces/dashes stripped', normalizeBdMobile('017 1234-5678') === '01712345678');
$check('match across formats', mobilesMatch('+880 1712 345 678', '01712345678'));
$check('different numbers do not match', !mobilesMatch('01712345678', '01712345679'));
$check('empty never matches', !mobilesMatch('', ''));

// Name canonicalization mirrors registration storage (htmlspecialchars)
$check('plain name canonical', canonicalLookupName("  Test Applicant ") === 'Test Applicant');
$check("apostrophe name matches stored encoding", canonicalLookupName("O'Brien") === htmlspecialchars("O'Brien", ENT_QUOTES, 'UTF-8'));

// Upcoming check (explicit today for determinism)
$check('future date upcoming', isUpcomingTestDate('2030-01-01', '2026-06-13'));
$check('today is upcoming', isUpcomingTestDate('2026-06-13', '2026-06-13'));
$check('past date not upcoming', !isUpcomingTestDate('2026-06-12', '2026-06-13'));

exit($pass ? 0 : 1);
