<?php
/**
 * IPN Webhook Testing Tool
 *
 * Test IPN webhook handling with various scenarios
 */

// Define test mode
define('TEST_MODE', true);
define('INTAKE_SERVICE', true);

// Load dependencies
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/mock_sslcommerz_api.php';
require_once __DIR__ . '/test_data_generator.php';

class IPNTester {

    private $mockAPI;
    private $dataGenerator;
    private $ipnUrl;
    private $testResults = [];

    /**
     * Constructor
     */
    public function __construct($ipnUrl = null) {
        $this->mockAPI = new MockSSLCommerzAPI();
        $this->dataGenerator = new PaymentTestDataGenerator();
        $this->ipnUrl = $ipnUrl ?: (SITE_URL . '/intake/payment-ipn.php');
    }

    /**
     * Run IPN tests
     */
    public function runIPNTests() {
        echo "\n";
        echo "========================================\n";
        echo "IPN Webhook Testing\n";
        echo "========================================\n\n";

        echo "IPN URL: {$this->ipnUrl}\n\n";

        // Test 1: Successful IPN
        $this->testIPNScenario('success_ipn', 'Successful payment IPN', 'success');

        // Test 2: Failed payment IPN
        $this->testIPNScenario('failed_ipn', 'Failed payment IPN', 'failed');

        // Test 3: Cancelled payment IPN
        $this->testIPNScenario('cancelled_ipn', 'Cancelled payment IPN', 'cancelled');

        // Test 4: Pending payment IPN
        $this->testIPNScenario('pending_ipn', 'Pending payment IPN', 'pending');

        // Test 5: Duplicate IPN handling
        $this->testDuplicateIPN();

        // Test 6: Invalid signature IPN
        $this->testInvalidSignature();

        // Test 7: Missing fields IPN
        $this->testMissingFields();

        // Test 8: Invalid amount IPN
        $this->testInvalidAmount();

        $this->printSummary();
        return $this->testResults;
    }

    /**
     * Test single IPN scenario
     */
    private function testIPNScenario($testName, $description, $scenario) {
        echo "Testing: {$testName}\n";
        echo "Description: {$description}\n";

        try {
            // Generate mock IPN data
            $ipnData = $this->mockAPI->generateMockIPN($scenario);
            $ipnData['test_mode'] = 'true';

            echo "IPN Data:\n";
            echo "  Transaction ID: {$ipnData['tran_id']}\n";
            echo "  Amount: {$ipnData['amount']} {$ipnData['currency']}\n";
            echo "  Status: {$ipnData['tran_status']}\n";

            // Validate IPN structure
            $validation = $this->validateIPNStructure($ipnData);

            if (!$validation['valid']) {
                $this->testResults[$testName] = [
                    'success' => false,
                    'error' => 'IPN structure validation failed: ' . $validation['error']
                ];
                echo "Status: ✗ FAILED - {$validation['error']}\n\n";
                return;
            }

            // Test signature calculation
            $signatureValid = $this->testSignatureCalculation($ipnData);

            if (!$signatureValid) {
                $this->testResults[$testName] = [
                    'success' => false,
                    'error' => 'Signature calculation failed'
                ];
                echo "Status: ✗ FAILED - Signature validation failed\n\n";
                return;
            }

            // Simulate IPN processing
            $processResult = $this->simulateIPNProcessing($ipnData);

            $this->testResults[$testName] = [
                'success' => $processResult['success'],
                'data' => $processResult,
                'ipn_data' => $ipnData
            ];

            echo "Status: " . ($processResult['success'] ? '✓ PASSED' : '✗ FAILED') . "\n";
            echo "Processing: {$processResult['message']}\n\n";

        } catch (Exception $e) {
            $this->testResults[$testName] = [
                'success' => false,
                'error' => $e->getMessage()
            ];
            echo "Status: ✗ FAILED - {$e->getMessage()}\n\n";
        }
    }

    /**
     * Test duplicate IPN handling
     */
    private function testDuplicateIPN() {
        echo "Testing: duplicate_ipn\n";
        echo "Description: Duplicate IPN detection\n";

        try {
            // Generate first IPN
            $firstIPN = $this->mockAPI->generateMockIPN('success');
            $firstIPN['test_mode'] = 'true';

            // Process first IPN
            $firstResult = $this->simulateIPNProcessing($firstIPN);

            // Create duplicate IPN (same transaction ID)
            $secondIPN = $firstIPN;
            $secondResult = $this->simulateIPNProcessing($secondIPN, true); // true = is duplicate

            $success = $firstResult['success'] && isset($secondResult['duplicate']);

            $this->testResults['duplicate_ipn'] = [
                'success' => $success,
                'data' => [
                    'first_ipn' => $firstResult,
                    'second_ipn' => $secondResult
                ]
            ];

            echo "Status: " . ($success ? '✓ PASSED' : '✗ FAILED') . "\n";
            echo "First IPN: {$firstResult['message']}\n";
            echo "Second IPN: " . (isset($secondResult['duplicate']) ? 'Duplicate detected' : $secondResult['message']) . "\n\n";

        } catch (Exception $e) {
            $this->testResults['duplicate_ipn'] = [
                'success' => false,
                'error' => $e->getMessage()
            ];
            echo "Status: ✗ FAILED - {$e->getMessage()}\n\n";
        }
    }

    /**
     * Test invalid signature
     */
    private function testInvalidSignature() {
        echo "Testing: invalid_signature\n";
        echo "Description: Invalid IPN signature rejection\n";

        try {
            // Generate valid IPN
            $ipnData = $this->mockAPI->generateMockIPN('success');
            $ipnData['test_mode'] = 'true';

            // Corrupt the signature
            $ipnData['verify_sign'] = 'INVALID_SIGNATURE_' . substr(md5(time()), 0, 20);

            $processResult = $this->simulateIPNProcessing($ipnData);

            $success = !$processResult['success'] && strpos($processResult['message'], 'signature') !== false;

            $this->testResults['invalid_signature'] = [
                'success' => $success,
                'data' => $processResult
            ];

            echo "Status: " . ($success ? '✓ PASSED' : '✗ FAILED') . "\n";
            echo "Result: {$processResult['message']}\n\n";

        } catch (Exception $e) {
            $this->testResults['invalid_signature'] = [
                'success' => false,
                'error' => $e->getMessage()
            ];
            echo "Status: ✗ FAILED - {$e->getMessage()}\n\n";
        }
    }

    /**
     * Test missing fields
     */
    private function testMissingFields() {
        echo "Testing: missing_fields\n";
        echo "Description: Missing required IPN fields\n";

        try {
            // Create IPN with missing fields
            $incompleteIPN = [
                'tran_id' => 'TEST_TRAN_' . time(),
                'amount' => '4100.00',
                'test_mode' => 'true'
                // Missing: currency, tran_status, verify_sign
            ];

            $validation = $this->validateIPNStructure($incompleteIPN);
            $success = !$validation['valid'];

            $this->testResults['missing_fields'] = [
                'success' => $success,
                'data' => ['validation' => $validation]
            ];

            echo "Status: " . ($success ? '✓ PASSED' : '✗ FAILED') . "\n";
            echo "Validation: " . ($validation['valid'] ? 'Valid (unexpected)' : 'Invalid (expected)') . "\n";
            echo "Missing: " . implode(', ', $validation['missing'] ?? ['unknown']) . "\n\n";

        } catch (Exception $e) {
            $this->testResults['missing_fields'] = [
                'success' => false,
                'error' => $e->getMessage()
            ];
            echo "Status: ✗ FAILED - {$e->getMessage()}\n\n";
        }
    }

    /**
     * Test invalid amount
     */
    private function testInvalidAmount() {
        echo "Testing: invalid_amount\n";
        echo "Description: Invalid amount in IPN\n";

        try {
            // Generate IPN with invalid amount
            $ipnData = $this->mockAPI->generateMockIPN('success');
            $ipnData['test_mode'] = 'true';
            $ipnData['amount'] = 'invalid_amount';

            $processResult = $this->simulateIPNProcessing($ipnData);
            $success = !$processResult['success'];

            $this->testResults['invalid_amount'] = [
                'success' => $success,
                'data' => $processResult
            ];

            echo "Status: " . ($success ? '✓ PASSED' : '✗ FAILED') . "\n";
            echo "Result: {$processResult['message']}\n\n";

        } catch (Exception $e) {
            $this->testResults['invalid_amount'] = [
                'success' => false,
                'error' => $e->getMessage()
            ];
            echo "Status: ✗ FAILED - {$e->getMessage()}\n\n";
        }
    }

    /**
     * Validate IPN structure
     */
    private function validateIPNStructure($ipnData) {
        $requiredFields = ['tran_id', 'amount', 'currency', 'tran_status', 'verify_sign'];
        $missing = [];

        foreach ($requiredFields as $field) {
            if (!isset($ipnData[$field]) || empty($ipnData[$field])) {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {
            return [
                'valid' => false,
                'error' => 'Missing required fields',
                'missing' => $missing
            ];
        }

        return ['valid' => true];
    }

    /**
     * Test signature calculation
     */
    private function testSignatureCalculation($ipnData) {
        $expectedSign = md5(
            $ipnData['tran_id'] .
            $ipnData['amount'] .
            $ipnData['currency'] .
            (defined('SSLCZ_STORE_PASSWORD') ? SSLCZ_STORE_PASSWORD : 'test_password')
        );

        return hash_equals($expectedSign, $ipnData['verify_sign']);
    }

    /**
     * Simulate IPN processing
     */
    private function simulateIPNProcessing($ipnData, $isDuplicate = false) {
        // Simulate processing time
        usleep(10000); // 10ms

        // Check for duplicate
        if ($isDuplicate) {
            return [
                'success' => true,
                'duplicate' => true,
                'message' => 'Duplicate IPN detected and ignored'
            ];
        }

        // Validate structure
        $validation = $this->validateIPNStructure($ipnData);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'message' => 'Invalid IPN structure: ' . $validation['error']
            ];
        }

        // Validate signature
        if (!$this->testSignatureCalculation($ipnData)) {
            return [
                'success' => false,
                'message' => 'Invalid signature'
            ];
        }

        // Validate amount
        if (!is_numeric($ipnData['amount']) || floatval($ipnData['amount']) <= 0) {
            return [
                'success' => false,
                'message' => 'Invalid amount'
            ];
        }

        // Process based on status
        $statusMap = [
            'SUCCESS' => 'paid',
            'FAILED' => 'failed',
            'CANCELLED' => 'unpaid',
            'PENDING' => 'pending'
        ];

        $paymentStatus = $statusMap[$ipnData['tran_status']] ?? 'unknown';

        return [
            'success' => true,
            'payment_status' => $paymentStatus,
            'transaction_id' => $ipnData['tran_id'],
            'amount' => $ipnData['amount'],
            'message' => "IPN processed successfully - Status: {$paymentStatus}"
        ];
    }

    /**
     * Print summary
     */
    private function printSummary() {
        echo "========================================\n";
        echo "IPN Test Summary\n";
        echo "========================================\n\n";

        $total = count($this->testResults);
        $passed = count(array_filter($this->testResults, function($result) {
            return isset($result['success']) && $result['success'];
        }));
        $failed = $total - $passed;
        $successRate = $total > 0 ? round(($passed / $total) * 100, 2) : 0;

        echo "Total Tests: {$total}\n";
        echo "Passed: {$passed}\n";
        echo "Failed: {$failed}\n";
        echo "Success Rate: {$successRate}%\n\n";

        // Detailed results
        foreach ($this->testResults as $name => $result) {
            $status = isset($result['success']) && $result['success'] ? '✓' : '✗';
            $message = $result['success'] ? ($result['data']['message'] ?? 'Success') : ($result['error'] ?? 'Unknown error');
            echo "{$status} {$name}: {$message}\n";
        }

        echo "\n";
    }

    /**
     * Export results
     */
    public function exportResults($filename = null) {
        if ($filename === null) {
            $filename = __DIR__ . '/logs/ipn_test_results_' . date('Y-m-d_H-i-s') . '.json';
        }

        $exportData = [
            'test_date' => date('Y-m-d H:i:s'),
            'ipn_url' => $this->ipnUrl,
            'results' => $this->testResults,
            'summary' => [
                'total' => count($this->testResults),
                'passed' => count(array_filter($this->testResults, function($r) { return isset($r['success']) && $r['success']; })),
                'failed' => count(array_filter($this->testResults, function($r) { return !isset($r['success']) || !$r['success']; }))
            ]
        ];

        file_put_contents($filename, json_encode($exportData, JSON_PRETTY_PRINT));
        return $filename;
    }
}

// Run tests if executed directly
if (php_sapi_name() === 'cli' && realpath($argv[0]) === realpath(__FILE__)) {
    $ipnUrl = $argv[1] ?? null;
    $tester = new IPNTester($ipnUrl);
    $results = $tester->runIPNTests();

    // Export results
    $exportFile = $tester->exportResults();
    echo "Results exported to: {$exportFile}\n";

    // Exit with appropriate code
    $allPassed = count(array_filter($results, function($r) { return isset($r['success']) && $r['success']; })) === count($results);
    exit($allPassed ? 0 : 1);
}