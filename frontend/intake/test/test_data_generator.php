<?php
/**
 * Payment Flow Test Data Generator
 *
 * Generates realistic test data for payment flow testing
 */

// Prevent direct access
if (!defined('INTAKE_SERVICE') && !defined('TEST_MODE')) {
    exit('Direct access not permitted');
}

class PaymentTestDataGenerator {

    private $bangladeshiDivisions = [
        'Dhaka', 'Chittagong', 'Khulna', 'Rajshahi', 'Sylhet',
        'Rangpur', 'Barisal', 'Mymensingh'
    ];

    private $districts = [
        'Dhaka District', 'Chittagong District', 'Khulna District', 'Gazipur',
        'Narayanganj', 'Comilla', 'Sylhet', 'Rajshahi', 'Bogra', 'Rangpur'
    ];

    private $examLevels = ['N5', 'N4', 'N3', 'N2', 'N1', '1Q', '2Q', '3Q', '4Q', '5Q'];

    private $testNames = [
        'Rahman Hassan', 'Islam Fatima', 'Ahmed Karim', 'Hossain Ayesha',
        'Kabir Rahman', 'Begum Islam', 'Sheikh Ahmed', 'Khan Hossain'
    ];

    private $testEmails = [
        'rahman.hassan@example.com',
        'islam.fatima@example.com',
        'ahmed.karim@example.com',
        'hossain.ayesha@example.com'
    ];

    /**
     * Generate random test user data
     */
    public function generateTestUser($options = []) {
        $name = $options['name'] ?? $this->testNames[array_rand($this->testNames)];
        $firstName = explode(' ', $name)[0];
        $lastName = explode(' ', $name)[1];

        return [
            'full_name' => $name,
            'email' => $options['email'] ?? strtolower($firstName . '.' . $lastName . rand(100, 999) . '@example.com'),
            'mobile' => $options['mobile'] ?? $this->generateMobileNumber(),
            'address' => $options['address'] ?? $this->generateAddress(),
            'dob' => $options['dob'] ?? $this->generateDateOfBirth(),
            'gender' => $options['gender'] ?? (rand(0, 1) ? 'male' : 'female'),
            'nationality' => $options['nationality'] ?? 'Bangladeshi',
            'photo_path' => $options['photo_path'] ?? null,
            'id_document_path' => $options['id_document_path'] ?? null,
            'payment_method' => $options['payment_method'] ?? 'online',
            'exam_level' => $options['exam_level'] ?? $this->examLevels[array_rand($this->examLevels)],
            'test_date' => $options['test_date'] ?? $this->generateTestDate()
        ];
    }

    /**
     * Generate Bangladeshi mobile number
     */
    private function generateMobileNumber() {
        $prefixes = ['017', '018', '019', '015', '016', '011'];
        $prefix = $prefixes[array_rand($prefixes)];
        $number = $prefix . rand(10000000, 99999999);
        return '+' . substr($number, 0, 3) . ' ' . substr($number, 3);
    }

    /**
     * Generate Bangladeshi address
     */
    private function generateAddress() {
        $division = $this->bangladeshiDivisions[array_rand($this->bangladeshiDivisions)];
        $district = $this->districts[array_rand($this->districts)];
        $streetNumber = rand(1, 999);
        $area = 'House #' . $streetNumber . ', Road #' . rand(1, 50);

        return "{$area}, {$district}, {$division}";
    }

    /**
     * Generate date of birth (18-30 years old)
     */
    private function generateDateOfBirth() {
        $minAge = 18;
        $maxAge = 30;
        $currentYear = date('Y');
        $birthYear = $currentYear - rand($minAge, $maxAge);
        $birthMonth = rand(1, 12);
        $birthDay = rand(1, 28);

        return sprintf('%04d/%02d/%02d', $birthYear, $birthMonth, $birthDay);
    }

    /**
     * Generate future test date (1-6 months from now)
     */
    private function generateTestDate() {
        $monthsAhead = rand(1, 6);
        $testDate = date('Y/m/d', strtotime('+' . $monthsAhead . ' months'));

        return $testDate;
    }

    /**
     * Generate payment data
     */
    public function generatePaymentData($registrationData, $options = []) {
        $levelCount = $options['level_count'] ?? 1;
        $isAmex = $options['is_amex'] ?? false;

        $baseAmount = $levelCount * 4000; // 4000 BDT per level
        $feeRate = $isAmex ? 0.035 : 0.025; // 3.5% AMEX, 2.5% others
        $transactionFee = $baseAmount * $feeRate;
        $totalAmount = $baseAmount + $transactionFee;

        return [
            'base_amount' => $baseAmount,
            'transaction_fee' => $transactionFee,
            'total_amount' => $totalAmount,
            'currency' => 'BDT',
            'payment_method_detail' => $isAmex ? 'card' : $this->generatePaymentMethod(),
            'is_amex' => $isAmex,
            'level_count' => $levelCount
        ];
    }

    /**
     * Generate payment method
     */
    private function generatePaymentMethod() {
        $methods = ['card', 'bkash', 'nagad', 'rocket', 'bank', 'other'];
        return $methods[array_rand($methods)];
    }

    /**
     * Generate SSLCommerz transaction ID
     */
    public function generateSSLCommerzTransactionID() {
        return 'NAT' . date('YmdHis') . strtoupper(substr(md5(rand()), 0, 6));
    }

    /**
     * Generate retry token
     */
    public function generateRetryToken() {
        return bin2hex(random_bytes(16));
    }

    /**
     * Generate retry expiry date (7 days from now)
     */
    public function generateRetryExpiry() {
        return date('Y-m-d H:i:s', strtotime('+7 days'));
    }

    /**
     * Create complete test registration data
     */
    public function createTestRegistration($options = []) {
        $userData = $this->generateTestUser($options);
        $paymentData = $this->generatePaymentData($userData, $options);

        return array_merge($userData, [
            'id' => $this->generateUUID(),
            'sslcommerz_transaction_id' => $this->generateSSLCommerzTransactionID(),
            'sslcommerz_session_id' => 'SESSION_' . $this->generateRetryToken(),
            'payment_status' => $options['payment_status'] ?? 'unpaid',
            'payment_time' => $options['payment_time'] ?? null,
            'payment_ipn_received' => $options['payment_ipn_received'] ?? false,
            'payment_retry_token' => $options['payment_retry_token'] ?? null,
            'payment_retry_expires' => $options['payment_retry_expires'] ?? null,
            'payment_retry_count' => $options['payment_retry_count'] ?? 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ], $paymentData);
    }

    /**
     * Generate UUID v4
     */
    private function generateUUID() {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Generate test scenarios
     */
    public function generateTestScenarios() {
        return [
            'single_level_success' => $this->createTestRegistration([
                'level_count' => 1,
                'payment_status' => 'paid',
                'payment_time' => date('Y-m-d H:i:s', strtotime('-1 hour')),
                'payment_ipn_received' => true
            ]),

            'multiple_levels_success' => $this->createTestRegistration([
                'level_count' => 3,
                'payment_status' => 'paid',
                'payment_time' => date('Y-m-d H:i:s', strtotime('-2 hours')),
                'payment_ipn_received' => true
            ]),

            'payment_failed' => $this->createTestRegistration([
                'level_count' => 1,
                'payment_status' => 'failed',
                'payment_time' => date('Y-m-d H:i:s', strtotime('-30 minutes'))
            ]),

            'payment_unpaid' => $this->createTestRegistration([
                'level_count' => 1,
                'payment_status' => 'unpaid'
            ]),

            'payment_retry_available' => $this->createTestRegistration([
                'level_count' => 1,
                'payment_status' => 'failed',
                'payment_retry_token' => $this->generateRetryToken(),
                'payment_retry_expires' => $this->generateRetryExpiry(),
                'payment_retry_count' => 0
            ]),

            'amex_payment' => $this->createTestRegistration([
                'level_count' => 2,
                'is_amex' => true,
                'payment_status' => 'paid',
                'payment_time' => date('Y-m-d H:i:s', strtotime('-45 minutes')),
                'payment_ipn_received' => true
            ]),

            'old_retry_expired' => $this->createTestRegistration([
                'level_count' => 1,
                'payment_status' => 'failed',
                'payment_retry_token' => $this->generateRetryToken(),
                'payment_retry_expires' => date('Y-m-d H:i:s', strtotime('-10 days')),
                'payment_retry_count' => 2
            ])
        ];
    }

    /**
     * Generate batch test registrations
     */
    public function generateBatchRegistrations($count = 10) {
        $registrations = [];
        $scenarios = [
            'paid' => 6, // 60% success rate
            'failed' => 2, // 20% failure rate
            'unpaid' => 2 // 20% unpaid
        ];

        for ($i = 0; $i < $count; $i++) {
            if ($i < $scenarios['paid']) {
                $status = 'paid';
            } elseif ($i < $scenarios['paid'] + $scenarios['failed']) {
                $status = 'failed';
            } else {
                $status = 'unpaid';
            }

            $registration = $this->createTestRegistration([
                'payment_status' => $status,
                'level_count' => rand(1, 3),
                'payment_time' => $status === 'paid' ? date('Y-m-d H:i:s', strtotime('-' . rand(1, 48) . ' hours')) : null,
                'payment_ipn_received' => $status === 'paid'
            ]);

            // Vary email and mobile
            $registration['email'] = 'test_' . $i . '@example.com';
            $registration['mobile'] = '+8801' . rand(7, 9) . rand(10000000, 99999999);

            $registrations[] = $registration;
        }

        return $registrations;
    }

    /**
     * Generate IPN test payloads
     */
    public function generateIPNTestPayloads() {
        return [
            'success_ipn' => [
                'tran_id' => $this->generateSSLCommerzTransactionID(),
                'bank_tran_id' => 'BANK_TEST_' . time(),
                'amount' => '4100.00',
                'currency' => 'BDT',
                'tran_status' => 'SUCCESS',
                'status_code' => '1',
                'error_code' => '0',
                'verify_sign' => 'test_signature_' . md5(time())
            ],

            'failed_ipn' => [
                'tran_id' => $this->generateSSLCommerzTransactionID(),
                'amount' => '4100.00',
                'currency' => 'BDT',
                'tran_status' => 'FAILED',
                'status_code' => '2',
                'error_code' => 'CARD_002',
                'error_reason' => 'Insufficient funds'
            ],

            'cancelled_ipn' => [
                'tran_id' => $this->generateSSLCommerzTransactionID(),
                'amount' => '4100.00',
                'currency' => 'BDT',
                'tran_status' => 'CANCELLED',
                'status_code' => '3',
                'error_code' => 'USER_001'
            ],

            'pending_ipn' => [
                'tran_id' => $this->generateSSLCommerzTransactionID(),
                'amount' => '4100.00',
                'currency' => 'BDT',
                'tran_status' => 'PENDING',
                'status_code' => '4'
            ]
        ];
    }

    /**
     * Get statistics summary
     */
    public function getDataStatistics($testData) {
        if (empty($testData)) {
            return [
                'total' => 0,
                'paid' => 0,
                'failed' => 0,
                'unpaid' => 0,
                'success_rate' => 0
            ];
        }

        $paid = count(array_filter($testData, function($item) {
            return isset($item['payment_status']) && $item['payment_status'] === 'paid';
        }));

        $failed = count(array_filter($testData, function($item) {
            return isset($item['payment_status']) && $item['payment_status'] === 'failed';
        }));

        $unpaid = count(array_filter($testData, function($item) {
            return isset($item['payment_status']) && $item['payment_status'] === 'unpaid';
        }));

        $total = count($testData);
        $successRate = $total > 0 ? round(($paid / $total) * 100, 2) : 0;

        return [
            'total' => $total,
            'paid' => $paid,
            'failed' => $failed,
            'unpaid' => $unpaid,
            'success_rate' => $successRate,
            'total_revenue' => array_sum(array_column($testData, 'total_amount'))
        ];
    }
}

// Test usage
if (defined('TEST_MODE') && TEST_MODE) {
    $generator = new PaymentTestDataGenerator();

    echo "Payment Test Data Generator\n";
    echo "============================\n\n";

    // Test 1: Generate single user
    echo "Test 1: Generate Test User\n";
    $user = $generator->generateTestUser();
    print_r($user);
    echo "\n";

    // Test 2: Generate test scenarios
    echo "Test 2: Generate Test Scenarios\n";
    $scenarios = $generator->generateTestScenarios();
    foreach ($scenarios as $name => $scenario) {
        echo "{$name}: {$scenario['payment_status']} - {$scenario['full_name']}\n";
    }
    echo "\n";

    // Test 3: Generate batch registrations
    echo "Test 3: Generate Batch Registrations (5)\n";
    $batch = $generator->generateBatchRegistrations(5);
    $stats = $generator->getDataStatistics($batch);
    print_r($stats);
    echo "\n";

    // Test 4: Generate IPN payloads
    echo "Test 4: Generate IPN Test Payloads\n";
    $ipnPayloads = $generator->generateIPNTestPayloads();
    foreach ($ipnPayloads as $name => $payload) {
        echo "{$name}: {$payload['tran_status']}\n";
    }
}
