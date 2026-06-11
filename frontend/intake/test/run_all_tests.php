<?php
/**
 * Payment Flow Test Suite Runner
 *
 * Master test runner that executes all payment flow tests
 */

// Define test mode
define('TEST_MODE', true);
define('INTAKE_SERVICE', true);

// Load all test dependencies
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/mock_sslcommerz_api.php';
require_once __DIR__ . '/test_data_generator.php';
require_once __DIR__ . '/run_payment_scenarios.php';
require_once __DIR__ . '/test_ipn_handler.php';

class PaymentTestSuite {

    private $results = [];
    private $startTime;
    private $endTime;

    /**
     * Constructor
     */
    public function __construct() {
        $this->startTime = microtime(true);
    }

    /**
     * Run complete test suite
     */
    public function runCompleteSuite() {
        echo "\n";
        echo "========================================\n";
        echo "SSLCommerz Payment Flow Test Suite\n";
        echo "========================================\n\n";

        echo "Start Time: " . date('Y-m-d H:i:s') . "\n";
        echo "Test Environment: " . (defined('SSLCZ_MODE') ? strtoupper(SSLCZ_MODE) : 'UNKNOWN') . "\n";
        echo "PHP Version: " . PHP_VERSION . "\n\n";

        // Section 1: Unit Tests
        $this->runUnitTests();

        // Section 2: Integration Tests
        $this->runIntegrationTests();

        // Section 3: Scenario Tests
        $this->runScenarioTests();

        // Section 4: IPN Tests
        $this->runIPNTests();

        // Calculate total time
        $this->endTime = microtime(true);
        $totalDuration = round(($this->endTime - $this->startTime) * 1000);

        // Print final summary
        $this->printFinalSummary($totalDuration);

        // Export comprehensive results
        $this->exportComprehensiveResults();

        return $this->results;
    }

    /**
     * Run unit tests
     */
    private function runUnitTests() {
        echo "\n--- Section 1: Unit Tests ---\n\n";

        $unitTests = [
            'environment_config' => $this->testEnvironmentConfig(),
            'database_connection' => $this->testDatabaseConnection(),
            'payment_calculation' => $this->testPaymentCalculation(),
            'token_generation' => $this->testTokenGeneration(),
            'api_class_init' => $this->testAPIClassInit()
        ];

        $this->results['unit_tests'] = $unitTests;

        $passed = count(array_filter($unitTests, function($t) { return $t['success']; }));
        $total = count($unitTests);

        echo "Unit Tests: {$passed}/{$total} passed\n";
    }

    /**
     * Run integration tests
     */
    private function runIntegrationTests() {
        echo "\n--- Section 2: Integration Tests ---\n\n";

        $integrationTests = [
            'mock_api_integration' => $this->testMockAPIIntegration(),
            'data_generator_integration' => $this->testDataGeneratorIntegration(),
            'payment_flow_integration' => $this->testPaymentFlowIntegration()
        ];

        $this->results['integration_tests'] = $integrationTests;

        $passed = count(array_filter($integrationTests, function($t) { return $t['success']; }));
        $total = count($integrationTests);

        echo "Integration Tests: {$passed}/{$total} passed\n";
    }

    /**
     * Run scenario tests
     */
    private function runScenarioTests() {
        echo "\n--- Section 3: Scenario Tests ---\n\n";

        $scenarioRunner = new PaymentFlowScenarioRunner();
        $scenarioResults = $scenarioRunner->runAllScenarios();

        $this->results['scenario_tests'] = $scenarioResults;

        $passed = count(array_filter($scenarioResults, function($r) { return $r['success']; }));
        $total = count($scenarioResults);

        echo "Scenario Tests: {$passed}/{$total} passed\n";
    }

    /**
     * Run IPN tests
     */
    private function runIPNTests() {
        echo "\n--- Section 4: IPN Tests ---\n\n";

        $ipnTester = new IPNTester();
        $ipnResults = $ipnTester->runIPNTests();

        $this->results['ipn_tests'] = $ipnResults;

        $passed = count(array_filter($ipnResults, function($r) { return isset($r['success']) && $r['success']; }));
        $total = count($ipnResults);

        echo "IPN Tests: {$passed}/{$total} passed\n";
    }

    /**
     * Test: Environment configuration
     */
    private function testEnvironmentConfig() {
        try {
            $required = ['DB_HOST', 'DB_NAME', 'SSLCZ_STORE_ID', 'SSLCZ_MODE'];
            $missing = [];

            foreach ($required as $const) {
                if (!defined($const)) {
                    $missing[] = $const;
                }
            }

            $success = empty($missing);

            return [
                'success' => $success,
                'message' => $success ? 'Environment configured' : 'Missing: ' . implode(', ', $missing),
                'details' => ['missing' => $missing]
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Test: Database connection
     */
    private function testDatabaseConnection() {
        try {
            $conn = getDbConnection();
            $success = $conn !== null;

            if ($conn) {
                $conn->close();
            }

            return [
                'success' => $success,
                'message' => $success ? 'Database connected' : 'Database connection failed'
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Test: Payment calculation
     */
    private function testPaymentCalculation() {
        try {
            // Test single level
            $calc1 = calculatePaymentAmount(1, false);
            $test1 = ($calc1['base'] === 4000.0 && $calc1['total'] === 4100.0);

            // Test multiple levels
            $calc2 = calculatePaymentAmount(3, false);
            $test2 = ($calc2['base'] === 12000.0 && $calc2['total'] === 12300.0);

            // Test AMEX
            $calc3 = calculatePaymentAmount(2, true);
            $test3 = ($calc3['total'] === 8280.0); // 8000 + 3.5% = 8280

            $success = $test1 && $test2 && $test3;

            return [
                'success' => $success,
                'message' => $success ? 'Payment calculations correct' : 'Payment calculation errors',
                'details' => ['test1' => $test1, 'test2' => $test2, 'test3' => $test3]
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Test: Token generation
     */
    private function testTokenGeneration() {
        try {
            $token1 = generateRetryToken();
            $token2 = generateRetryToken();

            $unique = ($token1 !== $token2);
            $format = preg_match('/^[a-f0-9]{32}$/', $token1);

            $success = $unique && $format;

            return [
                'success' => $success,
                'message' => $success ? 'Token generation working' : 'Token generation failed',
                'details' => ['unique' => $unique, 'format' => $format]
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Test: API class initialization
     */
    private function testAPIClassInit() {
        try {
            if (!class_exists('SSLCommerz')) {
                return ['success' => false, 'error' => 'SSLCommerz class not found'];
            }

            $api = new SSLCommerz();
            $success = ($api !== null);

            return [
                'success' => $success,
                'message' => $success ? 'API class initialized' : 'API class initialization failed'
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Test: Mock API integration
     */
    private function testMockAPIIntegration() {
        try {
            $mockAPI = new MockSSLCommerzAPI();
            $session = $mockAPI->createPaymentSession(['amount' => '4100.00'], 'success');

            $success = ($session['status'] === 'SUCCESS');

            return [
                'success' => $success,
                'message' => $success ? 'Mock API working' : 'Mock API failed',
                'details' => ['session_status' => $session['status']]
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Test: Data generator integration
     */
    private function testDataGeneratorIntegration() {
        try {
            $generator = new PaymentTestDataGenerator();
            $userData = $generator->generateTestUser();

            $requiredFields = ['full_name', 'email', 'mobile', 'address', 'dob'];
            $hasRequired = true;

            foreach ($requiredFields as $field) {
                if (!isset($userData[$field]) || empty($userData[$field])) {
                    $hasRequired = false;
                    break;
                }
            }

            return [
                'success' => $hasRequired,
                'message' => $hasRequired ? 'Data generator working' : 'Data generator missing fields',
                'details' => ['user_data' => $userData]
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Test: Payment flow integration
     */
    private function testPaymentFlowIntegration() {
        try {
            // Create test data
            $generator = new PaymentTestDataGenerator();
            $registration = $generator->createTestRegistration(['level_count' => 2]);

            // Calculate payment
            $payment = $generator->generatePaymentData($registration, ['level_count' => 2]);

            // Create mock session
            $mockAPI = new MockSSLCommerzAPI();
            $session = $mockAPI->createPaymentSession($payment, 'success');

            $success = (
                $registration['base_amount'] === 8000.0 &&
                $payment['total_amount'] === 8200.0 &&
                $session['status'] === 'SUCCESS'
            );

            return [
                'success' => $success,
                'message' => $success ? 'Payment flow integrated' : 'Payment flow integration failed',
                'details' => [
                    'registration' => $registration['base_amount'],
                    'payment' => $payment['total_amount'],
                    'session' => $session['status']
                ]
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Print final summary
     */
    private function printFinalSummary($totalDuration) {
        echo "\n";
        echo "========================================\n";
        echo "Final Test Summary\n";
        echo "========================================\n\n";

        // Calculate totals
        $totalTests = 0;
        $totalPassed = 0;

        foreach ($this->results as $section => $tests) {
            if (is_array($tests)) {
                $sectionPassed = count(array_filter($tests, function($t) {
                    return isset($t['success']) && $t['success'];
                }));
                $sectionTotal = count($tests);

                $totalTests += $sectionTotal;
                $totalPassed += $sectionPassed;

                $sectionRate = $sectionTotal > 0 ? round(($sectionPassed / $sectionTotal) * 100, 1) : 0;
                echo ucfirst(str_replace('_', ' ', $section)) . ": {$sectionPassed}/{$sectionTotal} ({$sectionRate}%)\n";
            }
        }

        $overallRate = $totalTests > 0 ? round(($totalPassed / $totalTests) * 100, 1) : 0;

        echo "\n";
        echo "Total Tests: {$totalTests}\n";
        echo "Total Passed: {$totalPassed}\n";
        echo "Total Failed: " . ($totalTests - $totalPassed) . "\n";
        echo "Overall Success Rate: {$overallRate}%\n";
        echo "Total Duration: {$totalDuration}ms\n";
        echo "End Time: " . date('Y-m-d H:i:s') . "\n\n";

        // Final verdict
        if ($overallRate >= 95) {
            echo "🎉 EXCELLENT! Test suite passed with high success rate.\n";
        } elseif ($overallRate >= 80) {
            echo "✓ GOOD! Most tests passed. Review failures.\n";
        } elseif ($overallRate >= 50) {
            echo "⚠ WARNING! Low success rate. Immediate attention needed.\n";
        } else {
            echo "✗ CRITICAL! Test suite failed. Investigate urgently.\n";
        }

        echo "\n";
    }

    /**
     * Export comprehensive results
     */
    private function exportComprehensiveResults() {
        $exportData = [
            'test_run' => [
                'start_time' => date('Y-m-d H:i:s', $this->startTime),
                'end_time' => date('Y-m-d H:i:s', $this->endTime),
                'duration_ms' => round(($this->endTime - $this->startTime) * 1000),
                'environment' => defined('SSLCZ_MODE') ? strtoupper(SSLCZ_MODE) : 'UNKNOWN',
                'php_version' => PHP_VERSION
            ],
            'results' => $this->results,
            'summary' => $this->calculateSummary()
        ];

        $filename = __DIR__ . '/logs/comprehensive_test_results_' . date('Y-m-d_H-i-s') . '.json';
        file_put_contents($filename, json_encode($exportData, JSON_PRETTY_PRINT));

        echo "Comprehensive results exported to: {$filename}\n";
    }

    /**
     * Calculate summary statistics
     */
    private function calculateSummary() {
        $totalTests = 0;
        $totalPassed = 0;

        foreach ($this->results as $section => $tests) {
            if (is_array($tests)) {
                $sectionPassed = count(array_filter($tests, function($t) {
                    return isset($t['success']) && $t['success'];
                }));
                $sectionTotal = count($tests);

                $totalTests += $sectionTotal;
                $totalPassed += $sectionPassed;
            }
        }

        return [
            'total_tests' => $totalTests,
            'total_passed' => $totalPassed,
            'total_failed' => $totalTests - $totalPassed,
            'success_rate' => $totalTests > 0 ? round(($totalPassed / $totalTests) * 100, 2) : 0
        ];
    }
}

// Run complete test suite if executed directly
if (php_sapi_name() === 'cli' && realpath($argv[0]) === realpath(__FILE__)) {
    $suite = new PaymentTestSuite();
    $results = $suite->runCompleteSuite();

    // Exit with appropriate code based on success rate
    $summary = $suite->calculateSummary();
    $success = $summary['success_rate'] >= 80; // 80% threshold
    exit($success ? 0 : 1);
}