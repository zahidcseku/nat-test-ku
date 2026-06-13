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

$check('identical sets overlap fully', findModuleOverlap('1Q,3Q', '1Q,3Q') === ['1Q', '3Q']);
$check('partial overlap returns shared only', findModuleOverlap('1Q,3Q', '3Q,4Q') === ['3Q']);
$check('disjoint sets => empty', findModuleOverlap('1Q,2Q', '3Q,4Q') === []);
$check('case/space insensitive', findModuleOverlap('1q , 3Q', ' 1Q,3q') === ['1Q', '3Q']);
$check('single vs multi overlap', findModuleOverlap('2Q', '1Q,2Q,3Q') === ['2Q']);
$check('single vs multi disjoint', findModuleOverlap('5Q', '1Q,2Q,3Q') === []);
$check('empty new => empty', findModuleOverlap('', '1Q') === []);
$check('empty existing => empty', findModuleOverlap('1Q', '') === []);
$check('result sorted + unique', findModuleOverlap('3Q,1Q,3Q', '1Q,3Q') === ['1Q', '3Q']);

exit($pass ? 0 : 1);
