<?php
/**
 * findModuleOverlap(): module-overlap detection for the duplicate guard.
 * Run: php frontend/intake/test/test_duplicate_guard.php
 */
define('INTAKE_SERVICE', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lookup-lib.php';

$pass = true;
$check = function ($label, $cond) use (&$pass) {
    echo ($cond ? 'PASS' : 'FAIL') . ": $label\n";
    if (!$cond) $pass = false;
};

$check('identical sets overlap fully', findModuleOverlap('N1,N3', 'N1,N3') === ['N1', 'N3']);
$check('partial overlap returns shared only', findModuleOverlap('N1,N3', 'N3,N4') === ['N3']);
$check('disjoint sets => empty', findModuleOverlap('N1,N2', 'N3,N4') === []);
$check('case/space insensitive', findModuleOverlap('n1 , N3', ' N1,n3') === ['N1', 'N3']);
$check('single vs multi overlap', findModuleOverlap('N2', 'N1,N2,N3') === ['N2']);
$check('single vs multi disjoint', findModuleOverlap('N5', 'N1,N2,N3') === []);
$check('empty new => empty', findModuleOverlap('', 'N1') === []);
$check('empty existing => empty', findModuleOverlap('N1', '') === []);
$check('result sorted + unique', findModuleOverlap('N3,N1,N3', 'N1,N3') === ['N1', 'N3']);

exit($pass ? 0 : 1);
