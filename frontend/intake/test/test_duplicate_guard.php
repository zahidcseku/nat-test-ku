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

$check('identical sets overlap fully', findModuleOverlap('1Q/N1,3Q/N3', '1Q/N1,3Q/N3') === ['1Q/N1', '3Q/N3']);
$check('partial overlap returns shared only', findModuleOverlap('1Q/N1,3Q/N3', '3Q/N3,4Q/N4') === ['3Q/N3']);
$check('disjoint sets => empty', findModuleOverlap('1Q/N1,2Q/N2', '3Q/N3,4Q/N4') === []);
$check('case/space insensitive', findModuleOverlap('1q/n1 , 3Q/N3', ' 1Q/N1,3q/n3') === ['1Q/N1', '3Q/N3']);
$check('single vs multi overlap', findModuleOverlap('2Q/N2', '1Q/N1,2Q/N2,3Q/N3') === ['2Q/N2']);
$check('single vs multi disjoint', findModuleOverlap('5Q/N5', '1Q/N1,2Q/N2,3Q/N3') === []);
$check('empty new => empty', findModuleOverlap('', '1Q/N1') === []);
$check('empty existing => empty', findModuleOverlap('1Q/N1', '') === []);
$check('result sorted + unique', findModuleOverlap('3Q/N3,1Q/N1,3Q/N3', '1Q/N1,3Q/N3') === ['1Q/N1', '3Q/N3']);

exit($pass ? 0 : 1);
