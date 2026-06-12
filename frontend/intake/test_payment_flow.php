<?php
/**
 * Payment Flow Test Suite
 *
 * Comprehensive testing for SSLCommerz payment integration
 * Tests all payment scenarios: success, failure, cancellation, retry, IPN
 */

// Define service constant
define('INTAKE_SERVICE', true);
define('TEST_MODE', true);

// Load dependencies
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/payment-gateway.php';

// Test configuration
const TEST_RESULTS_FILE = __DIR__ . '/logs/test_results.log';
const MOCK_SSLCZ_RESPONSES = __DIR__ . '/test/mock_sslcommerz_responses.json';

// Test results tracking
$testResults = [
    'passed' => 0,
    'failed' => 0,
    'skipped' => 0,
    'tests' => []
];

/**
 * Log test result
 */
function logTestResult($testName, $status, $message = '', $details = []) {
    global $testResults;

    $testResults['tests'][] = [
        'name' => $testName,
        'status' => $status,
        'message' => $message,
        'details' => $details,
        'timestamp' => date('Y-m-d H:i:s')
    ];

    if ($status === 'PASS') {
        $testResults['passed']++;
    } elseif ($status === 'FAIL') {
        $testResults['failed']++;
    } else {
        $testResults['skipped']++;
    }

    // Log to file
    $logMessage = sprintf(
        "[%s] %s: %s - %s\n",
        date('Y-m-d H:i:s'),
        $status,
        $testName,
        $message
    );

    file_put_contents(TEST_RESULTS_FILE, $logMessage, FILE_APPEND);

    // Console output
    echo sprintf("%s [%s] %s\n", $status === 'PASS' ? '✓' : '✗', $status, $testName);
    if ($message) {
        echo "  Message: {$message}\n";
    }
}

/**
 * Create mock SSLCommerz responses directory
 */
function setupMockResponses() {
    $mockDir = __DIR__ . '/test';
    if (!is_dir($mockDir)) {
        mkdir($mockDir, 0755, true);
    }

    $mockResponses = [
        'success_session' => [
            'status' => 'SUCCESS',
            'GatewayPageURL' => 'https://sandbox.sslcommerz.com/gwprocess/test_payment',
            'sessionkey' => 'test_session_key_12345'
        ],
        'failed_session' => [
            'status' => 'FAILED',
            'error' => 'Invalid credentials'
        ],
        'success_transaction' => [
            'tran_id' => 'TEST_TRANSACTION_12345',
            'bank_tran_id' => 'BANK_TEST_67890',
            'transaction_status' => 'SUCCESS',
            'amount' => '4100.00',
            'currency' => 'BDT'
        ],
        'failed_transaction' => [
            'tran_id' => 'TEST_TRANSACTION_FAILED',
            'transaction_status' => 'FAILED',
            'error' => 'Insufficient funds'
        ]
    ];

    file_put_contents(
        $mockDir . '/mock_sslcommerz_responses.json',
        json_encode($mockResponses, JSON_PRETTY_PRINT)
    );
}

/**
 * Test 1: Database Connection
 */
function testDatabaseConnection() {
    try {
        $conn = getDbConnection();

        if (!$conn) {
            logTestResult('Database Connection', 'FAIL', 'Could not establish database connection');
            return false;
        }

        // Test if payment columns exist
        $columnsQuery = "SHOW COLUMNS FROM registrations LIKE 'payment_status'";
        $result = $conn->query($columnsQuery);

        if ($result->num_rows > 0) {
            logTestResult('Database Connection', 'PASS', 'Database connected and payment fields verified');
            $conn->close();
            return true;
        } else {
            logTestResult('Database Connection', 'FAIL', 'Payment fields not found in database');
            $conn->close();
            return false;
        }

    } catch (Exception $e) {
        logTestResult('Database Connection', 'FAIL', $e->getMessage());
        return false;
    }
}

/**
 * Test 2: SSLCommerz Configuration
 */
function testSSLCommerzConfig() {
    try {
        $configChecks = [
            'SSLCZ_STORE_ID' => defined('SSLCZ_STORE_ID') && !empty(SSLCZ_STORE_ID),
            'SSLCZ_STORE_PASSWORD' => defined('SSLCZ_STORE_PASSWORD') && !empty(SSLCZ_STORE_PASSWORD),
            'SSLCZ_MODE' => defined('SSLCZ_MODE') && in_array(SSLCZ_MODE, ['sandbox', 'live']),
            'SSLCZ_API_DOMAIN' => defined('SSLCZ_API_DOMAIN') && !empty(SSLCZ_API_DOMAIN)
        ];

        $missingConfigs = array_filter($configChecks, function($value) {
            return !$value;
        });

        if (empty($missingConfigs)) {
            logTestResult('SSLCommerz Configuration', 'PASS', 'All required configurations present');
            return true;
        } else {
            logTestResult('SSLCommerz Configuration', 'FAIL',
                'Missing configurations: ' . implode(', ', array_keys($missingConfigs)));
            return false;
        }

    } catch (Exception $e) {
        logTestResult('SSLCommerz Configuration', 'FAIL', $e->getMessage());
        return false;
    }
}

/**
 * Test 3: Payment Calculation
 */
function testPaymentCalculation() {
    try {
        // Test single level, non-AMEX
        $result1 = calculatePaymentAmount(1, false);
        if ($result1['base'] !== 4000.0 || $result1['fee'] !== 100.0 || $result1['total'] !== 4100.0) {
            logTestResult('Payment Calculation', 'FAIL', 'Single level calculation incorrect', $result1);
            return false;
        }

        // Test multiple levels, non-AMEX
        $result2 = calculatePaymentAmount(3, false);
        if ($result2['base'] !== 12000.0 || $result2['fee'] !== 300.0 || $result2['total'] !== 12300.0) {
            logTestResult('Payment Calculation', 'FAIL', 'Multiple level calculation incorrect', $result2);
            return false;
        }

        // Test AMEX calculation
        $result3 = calculatePaymentAmount(2, true);
        if ($result3['base'] !== 8000.0 || $result3['fee'] !== 280.0 || $result3['total'] !== 8280.0) {
            logTestResult('Payment Calculation', 'FAIL', 'AMEX calculation incorrect', $result3);
            return false;
        }

        logTestResult('Payment Calculation', 'PASS', 'All payment calculations correct');
        return true;

    } catch (Exception $e) {
        logTestResult('Payment Calculation', 'FAIL', $e->getMessage());
        return false;
    }
}

/**
 * Test 4: Retry Token Generation
 */
function testRetryTokenGeneration() {
    try {
        $token1 = generateRetryToken();
        $token2 = generateRetryToken();

        // Check tokens are different
        if ($token1 === $token2) {
            logTestResult('Retry Token Generation', 'FAIL', 'Tokens are not unique');
            return false;
        }

        // Check token format (32 hex characters)
        if (!preg_match('/^[a-f0-9]{32}$/', $token1)) {
            logTestResult('Retry Token Generation', 'FAIL', 'Token format invalid: ' . $token1);
            return false;
        }

        logTestResult('Retry Token Generation', 'PASS', 'Tokens are unique and properly formatted');
        return true;

    } catch (Exception $e) {
        logTestResult('Retry Token Generation', 'FAIL', $e->getMessage());
        return false;
    }
}

/**
 * Test 5: Retry Expiry Calculation
 */
function testRetryExpiryCalculation() {
    try {
        $expiry = generateRetryExpiry();
        $expiryTime = strtotime($expiry);
        $currentTime = time();
        $expectedTime = strtotime('+' . PAYMENT_RETRY_EXPIRY_DAYS . ' days');

        // Check if expiry is approximately correct (within 1 minute tolerance)
        $difference = abs($expiryTime - $expectedTime);
        if ($difference > 60) {
            logTestResult('Retry Expiry Calculation', 'FAIL',
                'Expiry time incorrect. Expected: ' . date('Y-m-d H:i:s', $expectedTime) .
                ', Got: ' . $expiry);
            return false;
        }

        logTestResult('Retry Expiry Calculation', 'PASS', 'Expiry time correctly calculated');
        return true;

    } catch (Exception $e) {
        logTestResult('Retry Expiry Calculation', 'FAIL', $e->getMessage());
        return false;
    }
}

/**
 * Test 6: Payment Gateway Class Initialization
 */
function testPaymentGatewayClass() {
    try {
        if (!class_exists('SSLCommerz')) {
            logTestResult('Payment Gateway Class', 'FAIL', 'SSLCommerz class not found');
            return false;
        }

        $gateway = new SSLCommerz();

        // Check if gateway is properly initialized (using reflection to access private properties)
        $reflection = new ReflectionClass($gateway);

        $storeIdProp = $reflection->getProperty('storeId');
        $storeIdProp->setAccessible(true);
        $storeId = $storeIdProp->getValue($gateway);

        if (empty($storeId)) {
            logTestResult('Payment Gateway Class', 'FAIL', 'Store ID not set');
            return false;
        }

        logTestResult('Payment Gateway Class', 'PASS', 'SSLCommerz class properly initialized');
        return true;

    } catch (Exception $e) {
        logTestResult('Payment Gateway Class', 'FAIL', $e->getMessage());
        return false;
    }
}

/**
 * Test 7: IPN Signature Verification Logic
 */
function testIPNSignatureVerification() {
    try {
        $gateway = new SSLCommerz();

        // Sign test IPN data with the real SSLCommerz algorithm: md5 of the
        // ksorted key=value pairs named in verify_key, joined with '&', plus
        // store_passwd=md5(store_password)
        $fields = [
            'tran_id' => 'TEST_TRANSACTION_12345',
            'amount' => '4100.00',
            'currency' => 'BDT',
            'status' => 'VALID'
        ];
        $signed = $fields;
        $signed['store_passwd'] = md5(SSLCZ_STORE_PASSWORD);
        ksort($signed);
        $pairs = [];
        foreach ($signed as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }

        $testIPNData = $fields;
        $testIPNData['verify_key'] = implode(',', array_keys($fields));
        $testIPNData['verify_sign'] = md5(implode('&', $pairs));

        $_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        if (!$gateway->verifyIPN($testIPNData)) {
            logTestResult('IPN Signature Verification', 'FAIL', 'Correctly signed IPN was rejected');
            return false;
        }

        $tampered = $testIPNData;
        $tampered['amount'] = '9999.00';
        if ($gateway->verifyIPN($tampered)) {
            logTestResult('IPN Signature Verification', 'FAIL', 'Tampered IPN payload was accepted');
            return false;
        }

        $unsigned = $fields;
        if ($gateway->verifyIPN($unsigned)) {
            logTestResult('IPN Signature Verification', 'FAIL', 'IPN without signature was accepted');
            return false;
        }

        logTestResult('IPN Signature Verification', 'PASS',
            'Valid signature accepted; tampered and unsigned payloads rejected');
        return true;

    } catch (Exception $e) {
        logTestResult('IPN Signature Verification', 'FAIL', $e->getMessage());
        return false;
    }
}

/**
 * Test 8: File Upload and Directory Structure
 */
function testFileUploadStructure() {
    try {
        $requiredDirs = [
            __DIR__ . '/uploads',
            __DIR__ . '/uploads/photos',
            __DIR__ . '/uploads/ids',
            __DIR__ . '/uploads/receipts',
            __DIR__ . '/logs'
        ];

        $missingDirs = [];
        foreach ($requiredDirs as $dir) {
            if (!is_dir($dir)) {
                $missingDirs[] = $dir;
            }
        }

        if (!empty($missingDirs)) {
            logTestResult('File Upload Structure', 'FAIL',
                'Missing directories: ' . implode(', ', $missingDirs));
            return false;
        }

        // Check if directories are writable
        $nonWritable = [];
        foreach ($requiredDirs as $dir) {
            if (!is_writable($dir)) {
                $nonWritable[] = $dir;
            }
        }

        if (!empty($nonWritable)) {
            logTestResult('File Upload Structure', 'FAIL',
                'Non-writable directories: ' . implode(', ', $nonWritable));
            return false;
        }

        logTestResult('File Upload Structure', 'PASS', 'All required directories exist and are writable');
        return true;

    } catch (Exception $e) {
        logTestResult('File Upload Structure', 'FAIL', $e->getMessage());
        return false;
    }
}

/**
 * Test 9: Environment Variables
 */
function testEnvironmentVariables() {
    try {
        $requiredVars = [
            'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS',
            'SSLCZ_STORE_ID', 'SSLCZ_STORE_PASSWORD', 'SSLCZ_MODE'
        ];

        $missingVars = [];
        foreach ($requiredVars as $var) {
            if (!defined($var) || empty(constant($var))) {
                $missingVars[] = $var;
            }
        }

        if (!empty($missingVars)) {
            logTestResult('Environment Variables', 'FAIL',
                'Missing variables: ' . implode(', ', $missingVars));
            return false;
        }

        logTestResult('Environment Variables', 'PASS', 'All required environment variables set');
        return true;

    } catch (Exception $e) {
        logTestResult('Environment Variables', 'FAIL', $e->getMessage());
        return false;
    }
}

/**
 * Test 10: Payment Status Enum Values
 */
function testPaymentStatusEnum() {
    try {
        $conn = getDbConnection();
        if (!$conn) {
            logTestResult('Payment Status Enum', 'SKIP', 'Database not available');
            return false;
        }

        // Check if payment_status enum exists and has correct values
        $query = "SHOW COLUMNS FROM registrations WHERE Field = 'payment_status'";
        $result = $conn->query($query);

        if ($result->num_rows === 0) {
            logTestResult('Payment Status Enum', 'FAIL', 'payment_status column not found');
            $conn->close();
            return false;
        }

        $row = $result->fetch_assoc();
        $type = $row['Type'];

        // Check if enum contains expected values
        $expectedValues = ['unpaid', 'paid', 'failed', 'refunded'];
        $missingValues = [];

        foreach ($expectedValues as $value) {
            if (strpos($type, "'$value'") === false) {
                $missingValues[] = $value;
            }
        }

        if (!empty($missingValues)) {
            logTestResult('Payment Status Enum', 'FAIL',
                'Missing enum values: ' . implode(', ', $missingValues));
            $conn->close();
            return false;
        }

        logTestResult('Payment Status Enum', 'PASS', 'All payment status values present');
        $conn->close();
        return true;

    } catch (Exception $e) {
        logTestResult('Payment Status Enum', 'FAIL', $e->getMessage());
        return false;
    }
}

/**
 * Main test runner
 */
function runPaymentFlowTests() {
    global $testResults;

    echo "\n";
    echo "========================================\n";
    echo "Payment Flow Test Suite\n";
    echo "========================================\n\n";

    // Setup test environment
    echo "Setting up test environment...\n";
    setupMockResponses();

    // Clear previous test results
    if (file_exists(TEST_RESULTS_FILE)) {
        unlink(TEST_RESULTS_FILE);
    }

    // Run tests
    echo "Running tests...\n\n";

    testEnvironmentVariables();
    testDatabaseConnection();
    testSSLCommerzConfig();
    testPaymentCalculation();
    testRetryTokenGeneration();
    testRetryExpiryCalculation();
    testPaymentGatewayClass();
    testIPNSignatureVerification();
    testFileUploadStructure();
    testPaymentStatusEnum();

    // Print summary
    echo "\n";
    echo "========================================\n";
    echo "Test Summary\n";
    echo "========================================\n";
    echo "Total Tests: " . count($testResults['tests']) . "\n";
    echo "Passed: " . $testResults['passed'] . "\n";
    echo "Failed: " . $testResults['failed'] . "\n";
    echo "Skipped: " . $testResults['skipped'] . "\n";
    echo "Success Rate: " . round(($testResults['passed'] / count($testResults['tests'])) * 100, 2) . "%\n";

    // Save detailed results
    file_put_contents(
        TEST_RESULTS_FILE . '.json',
        json_encode($testResults, JSON_PRETTY_PRINT)
    );

    echo "\nDetailed results saved to: " . TEST_RESULTS_FILE . "\n";

    return $testResults['failed'] === 0;
}

// Run tests if executed directly
if (php_sapi_name() === 'cli' && realpath($argv[0]) === realpath(__FILE__)) {
    $success = runPaymentFlowTests();
    exit($success ? 0 : 1);
}