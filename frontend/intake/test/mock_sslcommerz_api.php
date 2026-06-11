<?php
/**
 * Mock SSLCommerz API for Testing
 *
 * Simulates SSLCommerz API responses without making real API calls.
 * This allows testing payment flow without sandbox credentials.
 */

// Prevent direct access
if (!defined('INTAKE_SERVICE') && !defined('TEST_MODE')) {
    exit('Direct access not permitted');
}

class MockSSLCommerzAPI {

    private $responses = [];
    private $callCount = 0;

    /**
     * Constructor - Load mock responses
     */
    public function __construct() {
        $this->responses = $this->loadMockResponses();
    }

    /**
     * Load predefined mock responses
     */
    private function loadMockResponses() {
        return [
            'create_session_success' => [
                'status' => 'SUCCESS',
                'GatewayPageURL' => 'https://sandbox.sslcommerz.com/gwprocess/v4/test_payment.php?Q=test',
                'sessionkey' => 'TEST_SESSION_KEY_' . time(),
                'tran_id' => 'TEST_TRAN_' . $this->generateTransactionId()
            ],
            'create_session_failed' => [
                'status' => 'FAILED',
                'error' => 'Invalid store credentials',
                'error_code' => 'AUTH_001'
            ],
            'transaction_success' => [
                'tran_id' => 'TEST_TRAN_' . $this->generateTransactionId(),
                'bank_tran_id' => 'BANK_TEST_' . time(),
                'transaction_status' => 'SUCCESS',
                'amount' => '4100.00',
                'currency' => 'BDT',
                'card_type' => 'VISA',
                'store_amount' => '3976.50',
                'card_issuer' => 'Test Bank',
                'card_brand' => 'VISA',
                'card_sub_brand' => 'Classic',
                'card_issuer_country' => 'Bangladesh',
                'issuing_bank_branch' => 'Dhaka Branch',
                'ecp_issuer' => 'Test ECP',
                'ipn_response' => 'OK'
            ],
            'transaction_failed' => [
                'tran_id' => 'TEST_TRAN_FAILED_' . time(),
                'transaction_status' => 'FAILED',
                'error' => 'Insufficient funds',
                'error_code' => 'CARD_002',
                'amount' => '4100.00',
                'currency' => 'BDT'
            ],
            'transaction_cancelled' => [
                'tran_id' => 'TEST_TRAN_CANCELLED_' . time(),
                'transaction_status' => 'CANCELLED',
                'error' => 'User cancelled payment',
                'error_code' => 'USER_001'
            ],
            'transaction_pending' => [
                'tran_id' => 'TEST_TRAN_PENDING_' . time(),
                'transaction_status' => 'PENDING',
                'error' => 'Awaiting bank confirmation',
                'error_code' => 'BANK_001'
            ],
            'transaction_validation_success' => [
                'tran_id' => 'TEST_TRAN_' . $this->generateTransactionId(),
                'bank_tran_id' => 'BANK_TEST_' . time(),
                'transaction_status' => 'SUCCESS',
                'currency' => 'BDT',
                'amount' => '4100.00',
                'validation_code' => 'VALID'
            ],
            'ipn_success' => [
                'tran_id' => 'TEST_IPN_' . $this->generateTransactionId(),
                'bank_tran_id' => 'BANK_IPN_' . time(),
                'transaction_status' => 'SUCCESS',
                'amount' => '4100.00',
                'currency' => 'BDT',
                'verify_sign' => '', // Will be calculated dynamically
                'risk_level' => '0',
                'risk_title' => 'Safe'
            ]
        ];
    }

    /**
     * Generate test transaction ID
     */
    private function generateTransactionId() {
        return strtoupper(substr(md5(time() . rand(1000, 9999)), 0, 12));
    }

    /**
     * Calculate IPN signature
     */
    private function calculateIPNSignature($tranId, $amount, $currency, $storePassword) {
        return md5($tranId . $amount . $currency . $storePassword);
    }

    /**
     * Mock create payment session
     */
    public function createPaymentSession($paymentData, $scenario = 'success') {
        $this->callCount++;

        switch ($scenario) {
            case 'success':
                $response = $this->responses['create_session_success'];
                $response['tran_id'] = 'NAT' . time() . substr(md5(rand()), 0, 8);
                break;

            case 'invalid_credentials':
                $response = $this->responses['create_session_failed'];
                break;

            case 'invalid_amount':
                return [
                    'status' => 'FAILED',
                    'error' => 'Invalid amount format',
                    'error_code' => 'AMOUNT_001'
                ];

            case 'server_error':
                return [
                    'status' => 'ERROR',
                    'error' => 'SSLCommerz server temporarily unavailable',
                    'error_code' => 'SERVER_500'
                ];

            default:
                $response = $this->responses['create_session_success'];
        }

        return $response;
    }

    /**
     * Mock transaction status check
     */
    public function checkTransactionStatus($transactionId, $scenario = 'success') {
        $this->callCount++;

        switch ($scenario) {
            case 'success':
                return $this->responses['transaction_success'];

            case 'failed':
                return $this->responses['transaction_failed'];

            case 'cancelled':
                return $this->responses['transaction_cancelled'];

            case 'pending':
                return $this->responses['transaction_pending'];

            case 'not_found':
                return [
                    'error' => 'Transaction not found',
                    'error_code' => 'TRANS_404'
                ];

            default:
                return $this->responses['transaction_success'];
        }
    }

    /**
     * Mock IPN validation
     */
    public function validateIPN($ipnData, $storePassword) {
        $this->callCount++;

        // Calculate expected signature
        if (isset($ipnData['tran_id'], $ipnData['amount'], $ipnData['currency'])) {
            $expectedSign = $this->calculateIPNSignature(
                $ipnData['tran_id'],
                $ipnData['amount'],
                $ipnData['currency'],
                $storePassword
            );

            return [
                'valid' => isset($ipnData['verify_sign']) && hash_equals($expectedSign, $ipnData['verify_sign']),
                'expected_signature' => $expectedSign,
                'provided_signature' => $ipnData['verify_sign'] ?? 'missing'
            ];
        }

        return [
            'valid' => false,
            'error' => 'Missing required IPN fields'
        ];
    }

    /**
     * Generate mock IPN payload
     */
    public function generateMockIPN($scenario = 'success', $registrationData = []) {
        $baseIPN = [
            'tran_id' => 'TEST_IPN_' . $this->generateTransactionId(),
            'bank_tran_id' => 'BANK_TEST_' . time(),
            'amount' => '4100.00',
            'currency' => 'BDT',
            'card_type' => 'VISA',
            'currency_type' => 'BDT',
            'currency_amount' => '4100.00',
            'currency_rate' => '1.0000',
            'base_fare' => '4000.00',
            'card_sub_brand' => 'Classic',
            'product_category' => 'Education',
            'product_profile' => 'General',
            'user_ip' => '127.0.0.1',
            'cust_id' => $registrationData['email'] ?? 'test@example.com',
            'cust_name' => $registrationData['full_name'] ?? 'Test User',
            'cust_email' => $registrationData['email'] ?? 'test@example.com',
            'cust_phone' => $registrationData['mobile'] ?? '+880171234567',
            'ecp_issuer' => 'Test ECP',
            'card_issuer' => 'Test Bank',
            'card_brand' => 'VISA',
            'card_issuer_country' => 'Bangladesh',
            'store_id' => 'test_box_store_id',
            'store_passwd' => '******',
            'date' => date('Y-m-d H:i:s'),
            'datetime' => date('Y-m-d H:i:s'),
            'verify_sign' => '', // Will be calculated
            'risk_level' => '0',
            'risk_title' => 'Safe'
        ];

        switch ($scenario) {
            case 'success':
                $baseIPN['tran_status'] = 'SUCCESS';
                $baseIPN['status_code'] = '1';
                $baseIPN['error_code'] = '0';
                break;

            case 'failed':
                $baseIPN['tran_status'] = 'FAILED';
                $baseIPN['status_code'] = '2';
                $baseIPN['error_code'] = 'CARD_002';
                $baseIPN['error_reason'] = 'Insufficient funds';
                break;

            case 'cancelled':
                $baseIPN['tran_status'] = 'CANCELLED';
                $baseIPN['status_code'] = '3';
                $baseIPN['error_code'] = 'USER_001';
                $baseIPN['error_reason'] = 'User cancelled payment';
                break;

            case 'pending':
                $baseIPN['tran_status'] = 'PENDING';
                $baseIPN['status_code'] = '4';
                $baseIPN['error_reason'] = 'Awaiting bank confirmation';
                break;

            default:
                $baseIPN['tran_status'] = 'SUCCESS';
        }

        // Calculate signature
        $baseIPN['verify_sign'] = $this->calculateIPNSignature(
            $baseIPN['tran_id'],
            $baseIPN['amount'],
            $baseIPN['currency'],
            defined('SSLCZ_STORE_PASSWORD') ? SSLCZ_STORE_PASSWORD : 'test_password'
        );

        return $baseIPN;
    }

    /**
     * Get test scenario definitions
     */
    public function getTestScenarios() {
        return [
            'happy_path' => [
                'description' => 'Successful payment flow',
                'steps' => [
                    'create_payment_session' => 'success',
                    'redirect_to_gateway' => 'success',
                    'user_completes_payment' => 'success',
                    'ipn_received' => 'success',
                    'payment_status_updated' => 'paid'
                ]
            ],
            'payment_failed' => [
                'description' => 'Payment failure scenario',
                'steps' => [
                    'create_payment_session' => 'success',
                    'redirect_to_gateway' => 'success',
                    'payment_declined' => 'failed',
                    'ipn_received' => 'failed',
                    'payment_status_updated' => 'failed'
                ]
            ],
            'user_cancelled' => [
                'description' => 'User cancellation scenario',
                'steps' => [
                    'create_payment_session' => 'success',
                    'redirect_to_gateway' => 'success',
                    'user_cancels' => 'cancelled',
                    'redirect_to_cancel_url' => 'cancelled',
                    'payment_status_updated' => 'unpaid'
                ]
            ],
            'invalid_credentials' => [
                'description' => 'Invalid API credentials',
                'steps' => [
                    'create_payment_session' => 'invalid_credentials',
                    'error_displayed' => 'Authentication failed',
                    'registration_saved' => 'unpaid'
                ]
            ],
            'server_error' => [
                'description' => 'SSLCommerz server error',
                'steps' => [
                    'create_payment_session' => 'server_error',
                    'error_displayed' => 'Payment gateway temporarily unavailable',
                    'registration_saved' => 'unpaid'
                ]
            ],
            'duplicate_ipn' => [
                'description' => 'Duplicate IPN handling',
                'steps' => [
                    'create_payment_session' => 'success',
                    'payment_completed' => 'success',
                    'first_ipn' => 'success',
                    'payment_status_updated' => 'paid',
                    'second_ipn' => 'success',
                    'duplicate_detected' => 'true',
                    'payment_unchanged' => 'paid'
                ]
            ]
        ];
    }

    /**
     * Get call statistics
     */
    public function getCallStats() {
        return [
            'total_calls' => $this->callCount,
            'responses_available' => count($this->responses),
            'scenarios_defined' => count($this->getTestScenarios())
        ];
    }

    /**
     * Reset call counter
     */
    public function resetCallCount() {
        $this->callCount = 0;
    }

    /**
     * Get all available response types
     */
    public function getAvailableResponseTypes() {
        return array_keys($this->responses);
    }
}

// Test usage example
if (defined('TEST_MODE') && TEST_MODE) {
    $mockAPI = new MockSSLCommerzAPI();

    echo "Mock SSLCommerz API Test Suite\n";
    echo "==============================\n\n";

    // Test 1: Create payment session
    echo "Test 1: Create Payment Session (Success)\n";
    $session = $mockAPI->createPaymentSession(['amount' => '4100.00'], 'success');
    print_r($session);
    echo "\n";

    // Test 2: Check transaction status
    echo "Test 2: Check Transaction Status (Success)\n";
    $status = $mockAPI->checkTransactionStatus('TEST_TRAN_12345', 'success');
    print_r($status);
    echo "\n";

    // Test 3: Generate mock IPN
    echo "Test 3: Generate Mock IPN (Success)\n";
    $ipn = $mockAPI->generateMockIPN('success', [
        'email' => 'test@example.com',
        'full_name' => 'Test User',
        'mobile' => '+880171234567'
    ]);
    print_r($ipn);
    echo "\n";

    // Test 4: Get test scenarios
    echo "Test 4: Available Test Scenarios\n";
    $scenarios = $mockAPI->getTestScenarios();
    foreach ($scenarios as $name => $scenario) {
        echo "- {$name}: {$scenario['description']}\n";
    }
    echo "\n";

    // Test 5: Call statistics
    echo "Test 5: Call Statistics\n";
    print_r($mockAPI->getCallStats());
}
