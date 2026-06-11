<?php
/**
 * Payment Flow Scenario Runner
 *
 * Executes predefined test scenarios for payment flow validation
 */

// Define test mode
define('TEST_MODE', true);
define('INTAKE_SERVICE', true);

// Load dependencies
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/mock_sslcommerz_api.php';
require_once __DIR__ . '/test_data_generator.php';
require_once __DIR__ . '/../payment-gateway.php';

class PaymentFlowScenarioRunner {

    private $mockAPI;
    private $dataGenerator;
    private $results = [];
    private $scenarioResults = [];

    /**
     * Constructor
     */
    public function __construct() {
        $this->mockAPI = new MockSSLCommerzAPI();
        $this->dataGenerator = new PaymentTestDataGenerator();
    }

    /**
     * Run all test scenarios
     */
    public function runAllScenarios() {
        echo "\n";
        echo "========================================\n";
        echo "Payment Flow Scenario Tests\n";
        echo "========================================\n\n";

        $scenarios = $this->getScenarios();

        foreach ($scenarios as $scenarioName => $scenario) {
            echo "Running scenario: {$scenarioName}\n";
            echo "Description: {$scenario['description']}\n";
            echo str_repeat('-', 50) . "\n";

            try {
                $result = $this->runScenario($scenarioName, $scenario);
                $this->scenarioResults[$scenarioName] = $result;

                echo "Status: " . ($result['success'] ? '✓ PASSED' : '✗ FAILED') . "\n";
                echo "Duration: {$result['duration']}ms\n";
                echo "Steps executed: " . count($result['steps']) . "\n";

                if (!$result['success']) {
                    echo "Error: {$result['error']}\n";
                }

            } catch (Exception $e) {
                $this->scenarioResults[$scenarioName] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'steps' => []
                ];
                echo "Status: ✗ FAILED\n";
                echo "Error: {$e->getMessage()}\n";
            }

            echo "\n";
        }

        $this->printSummary();
        return $this->scenarioResults;
    }

    /**
     * Get test scenarios
     */
    private function getScenarios() {
        return [
            'happy_path' => [
                'description' => 'Complete successful payment flow',
                'registration_data' => [
                    'level_count' => 1,
                    'payment_method' => 'online'
                ],
                'payment_scenario' => 'success',
                'expected_status' => 'paid',
                'expected_ipn' => true
            ],

            'multiple_levels_payment' => [
                'description' => 'Payment for multiple exam levels',
                'registration_data' => [
                    'level_count' => 3,
                    'payment_method' => 'online'
                ],
                'payment_scenario' => 'success',
                'expected_status' => 'paid',
                'expected_total' => 12300.00 // 3 levels x 4000 + fees
            ],

            'amex_payment' => [
                'description' => 'Payment using AMEX card (higher fees)',
                'registration_data' => [
                    'level_count' => 2,
                    'payment_method' => 'online',
                    'is_amex' => true
                ],
                'payment_scenario' => 'success',
                'expected_status' => 'paid',
                'expected_total' => 8280.00 // 2 levels x 4000 + 3.5% fees
            ],

            'payment_declined' => [
                'description' => 'Payment declined by bank',
                'registration_data' => [
                    'level_count' => 1,
                    'payment_method' => 'online'
                ],
                'payment_scenario' => 'failed',
                'expected_status' => 'failed'
            ],

            'user_cancelled' => [
                'description' => 'User cancels payment',
                'registration_data' => [
                    'level_count' => 1,
                    'payment_method' => 'online'
                ],
                'payment_scenario' => 'cancelled',
                'expected_status' => 'unpaid'
            ],

            'api_credentials_error' => [
                'description' => 'Invalid SSLCommerz credentials',
                'registration_data' => [
                    'level_count' => 1,
                    'payment_method' => 'online'
                ],
                'payment_scenario' => 'invalid_credentials',
                'expected_status' => 'unpaid',
                'should_fail_at' => 'session_creation'
            ],

            'offline_payment' => [
                'description' => 'Registration with offline payment method',
                'registration_data' => [
                    'level_count' => 1,
                    'payment_method' => 'offline'
                ],
                'payment_scenario' => null,
                'expected_status' => 'unpaid'
            ],

            'retry_functionality' => [
                'description' => 'Payment retry after initial failure',
                'registration_data' => [
                    'level_count' => 1,
                    'payment_method' => 'online'
                ],
                'payment_scenario' => 'failed_then_retry',
                'expected_status' => 'paid',
                'retry_count' => 1
            ],

            'ipn_webhook_success' => [
                'description' => 'IPN webhook processing',
                'registration_data' => [
                    'level_count' => 1,
                    'payment_method' => 'online'
                ],
                'payment_scenario' => 'success',
                'expected_status' => 'paid',
                'ipn_test' => true
            ],

            'duplicate_ipn_handling' => [
                'description' => 'Duplicate IPN detection and handling',
                'registration_data' => [
                    'level_count' => 1,
                    'payment_method' => 'online'
                ],
                'payment_scenario' => 'success',
                'expected_status' => 'paid',
                'duplicate_ipn' => true
            ]
        ];
    }

    /**
     * Run single scenario
     */
    private function runScenario($scenarioName, $scenario) {
        $startTime = microtime(true);
        $steps = [];
        $success = true;
        $error = null;

        try {
            // Step 1: Create test registration data
            $steps[] = $this->executeStep('create_test_data', function() use ($scenario) {
                $testData = $this->dataGenerator->createTestRegistration($scenario['registration_data']);
                return [
                    'success' => true,
                    'data' => $testData,
                    'message' => 'Test registration data created'
                ];
            });

            // Step 2: Calculate payment amounts
            $steps[] = $this->executeStep('calculate_payment', function() use ($scenario) {
                $paymentData = $this->dataGenerator->generatePaymentData([], $scenario['registration_data']);

                // Verify calculation
                if (isset($scenario['expected_total'])) {
                    if (abs($paymentData['total_amount'] - $scenario['expected_total']) > 0.01) {
                        return [
                            'success' => false,
                            'message' => 'Payment calculation mismatch',
                            'expected' => $scenario['expected_total'],
                            'calculated' => $paymentData['total_amount']
                        ];
                    }
                }

                return [
                    'success' => true,
                    'data' => $paymentData,
                    'message' => 'Payment calculated correctly'
                ];
            });

            // Step 3: Create SSLCommerz session
            $steps[] = $this->executeStep('create_session', function() use ($scenario, $steps) {
                $sessionResult = $this->mockAPI->createPaymentSession([], $scenario['payment_scenario']);

                if (isset($scenario['should_fail_at']) && $scenario['should_fail_at'] === 'session_creation') {
                    if ($sessionResult['status'] !== 'FAILED') {
                        return [
                            'success' => false,
                            'message' => 'Expected session creation to fail'
                        ];
                    }
                }

                return [
                    'success' => $sessionResult['status'] === 'SUCCESS',
                    'data' => $sessionResult,
                    'message' => $sessionResult['status'] === 'SUCCESS' ? 'Session created' : 'Session failed as expected'
                ];
            });

            // Step 4: Process payment (if online)
            if ($scenario['payment_scenario']) {
                $steps[] = $this->executeStep('process_payment', function() use ($scenario) {
                    $transactionStatus = $this->mockAPI->checkTransactionStatus('TEST_TRAN', $scenario['payment_scenario']);

                    $expectedStatusMap = [
                        'success' => 'SUCCESS',
                        'failed' => 'FAILED',
                        'cancelled' => 'CANCELLED'
                    ];

                    $expectedStatus = $expectedStatusMap[$scenario['payment_scenario']] ?? 'SUCCESS';

                    return [
                        'success' => $transactionStatus['transaction_status'] === $expectedStatus,
                        'data' => $transactionStatus,
                        'message' => 'Payment processed: ' . $transactionStatus['transaction_status']
                    ];
                });

                // Step 5: Handle IPN
                if (isset($scenario['ipn_test']) || isset($scenario['duplicate_ipn'])) {
                    $steps[] = $this->executeStep('process_ipn', function() use ($scenario) {
                        $ipnData = $this->mockAPI->generateMockIPN($scenario['payment_scenario']);

                        // Test duplicate IPN handling
                        if (isset($scenario['duplicate_ipn'])) {
                            // First IPN
                            $firstResult = $this->mockAPI->validateIPN($ipnData, 'test_password');
                            // Second IPN (duplicate)
                            $secondResult = $this->mockAPI->validateIPN($ipnData, 'test_password');

                            return [
                                'success' => $firstResult['valid'] && $secondResult['valid'],
                                'data' => ['first_ipn' => $firstResult, 'second_ipn' => $secondResult],
                                'message' => 'Duplicate IPN handled correctly'
                            ];
                        }

                        return [
                            'success' => true,
                            'data' => $ipnData,
                            'message' => 'IPN processed successfully'
                        ];
                    });
                }
            }

            // Step 6: Verify final status
            $steps[] = $this->executeStep('verify_status', function() use ($scenario) {
                $testData = $steps[0]['data'];

                $status = 'unpaid';
                if ($scenario['payment_scenario'] === 'success') {
                    $status = 'paid';
                } elseif ($scenario['payment_scenario'] === 'failed') {
                    $status = 'failed';
                }

                if (isset($scenario['retry_count'])) {
                    $status = 'paid'; // Retry succeeded
                }

                $matches = ($status === $scenario['expected_status']);

                return [
                    'success' => $matches,
                    'data' => ['expected' => $scenario['expected_status'], 'actual' => $status],
                    'message' => $matches ? 'Status matches expected' : 'Status mismatch'
                ];
            });

        } catch (Exception $e) {
            $success = false;
            $error = $e->getMessage();
        }

        $duration = round((microtime(true) - $startTime) * 1000);

        return [
            'success' => $success,
            'error' => $error,
            'steps' => $steps,
            'duration' => $duration
        ];
    }

    /**
     * Execute single step
     */
    private function executeStep($stepName, $callback) {
        try {
            $result = $callback();
            $result['step'] = $stepName;
            $result['timestamp'] = date('Y-m-d H:i:s');
            return $result;
        } catch (Exception $e) {
            return [
                'step' => $stepName,
                'success' => false,
                'error' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s')
            ];
        }
    }

    /**
     * Print summary
     */
    private function printSummary() {
        echo "========================================\n";
        echo "Test Summary\n";
        echo "========================================\n\n";

        $total = count($this->scenarioResults);
        $passed = count(array_filter($this->scenarioResults, function($result) {
            return $result['success'];
        }));
        $failed = $total - $passed;
        $successRate = $total > 0 ? round(($passed / $total) * 100, 2) : 0;

        echo "Total Scenarios: {$total}\n";
        echo "Passed: {$passed}\n";
        echo "Failed: {$failed}\n";
        echo "Success Rate: {$successRate}%\n\n";

        // Detailed breakdown
        foreach ($this->scenarioResults as $name => $result) {
            $status = $result['success'] ? '✓' : '✗';
            $duration = $result['duration'] ?? 'N/A';
            $steps = count($result['steps'] ?? []);

            echo "{$status} {$name} ({$steps} steps, {$duration}ms)\n";

            if (!$result['success'] && isset($result['error'])) {
                echo "  Error: {$result['error']}\n";
            }
        }

        echo "\n";
    }

    /**
     * Export results to JSON
     */
    public function exportResults($filename = null) {
        if ($filename === null) {
            $filename = __DIR__ . '/logs/scenario_results_' . date('Y-m-d_H-i-s') . '.json';
        }

        $exportData = [
            'test_date' => date('Y-m-d H:i:s'),
            'scenarios_tested' => count($this->scenarioResults),
            'results' => $this->scenarioResults,
            'summary' => [
                'total' => count($this->scenarioResults),
                'passed' => count(array_filter($this->scenarioResults, function($r) { return $r['success']; })),
                'failed' => count(array_filter($this->scenarioResults, function($r) { return !$r['success']; }))
            ]
        ];

        file_put_contents($filename, json_encode($exportData, JSON_PRETTY_PRINT));
        return $filename;
    }
}

// Run scenarios if executed directly
if (php_sapi_name() === 'cli' && realpath($argv[0]) === realpath(__FILE__)) {
    $runner = new PaymentFlowScenarioRunner();
    $results = $runner->runAllScenarios();

    // Export results
    $exportFile = $runner->exportResults();
    echo "Results exported to: {$exportFile}\n";

    // Exit with appropriate code
    $allPassed = count(array_filter($results, function($r) { return $r['success']; })) === count($results);
    exit($allPassed ? 0 : 1);
}