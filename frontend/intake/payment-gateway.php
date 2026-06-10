<?php
/**
 * SSLCommerz Payment Gateway Integration
 *
 * Handles all SSLCommerz API communication including:
 * - Payment session creation
 * - IPN verification
 * - Transaction status checking
 */

// Prevent direct access
if (!defined('INTAKE_SERVICE')) {
    exit('Direct access not permitted');
}

class SSLCommerz {

    private $storeId;
    private $storePassword;
    private $isSandbox;
    private $apiDomain;

    /**
     * Constructor - Load configuration
     */
    public function __construct() {
        $this->storeId = SSLCZ_STORE_ID;
        $this->storePassword = SSLCZ_STORE_PASSWORD;
        $this->isSandbox = (SSLCZ_MODE === 'sandbox');
        $this->apiDomain = SSLCZ_API_DOMAIN;
    }

    /**
     * Create payment session
     *
     * @param array $paymentData Payment details
     * @return array Session creation response
     */
    public function createPayment($paymentData) {
        $endpoint = $this->apiDomain . '/gwprocess/v4/api.php';

        // Required fields
        $params = [
            'store_id' => $this->storeId,
            'store_passwd' => $this->storePassword,
            'total_amount' => $paymentData['total_amount'],
            'currency' => $paymentData['currency'] ?? 'BDT',
            'tran_id' => $paymentData['tran_id'],
            'success_url' => SSLCZ_SUCCESS_URL,
            'fail_url' => SSLCZ_FAIL_URL,
            'cancel_url' => SSLCZ_CANCEL_URL,
            'ipn_url' => SSLCZ_IPN_URL,
            'multi_card_name' => $paymentData['multi_card_name'] ?? '',
            'cus_name' => $paymentData['cus_name'],
            'cus_email' => $paymentData['cus_email'],
            'cus_add1' => $paymentData['cus_add1'] ?? 'N/A',
            'cus_city' => $paymentData['cus_city'] ?? 'N/A',
            'cus_country' => $paymentData['cus_country'] ?? 'Bangladesh',
            'cus_phone' => $paymentData['cus_phone'],
            'shipping_method' => 'NO',
            'product_name' => 'NAT-TEST Registration',
            'product_category' => 'Education',
            'product_profile' => 'General'
        ];

        // Make API call
        $response = $this->makeApiCall($endpoint, $params);

        return $this->parseCreateResponse($response);
    }

    /**
     * Verify IPN signature
     *
     * @param array $ipnData IPN POST data
     * @return bool Valid or not
     */
    public function verifyIPN($ipnData) {
        // Verify IPN whitelist
        if (!in_array($_SERVER['REMOTE_ADDR'], SSLCZ_IPN_WHITELIST)) {
            logActivity("IPN from non-whitelisted IP: " . $_SERVER['REMOTE_ADDR'], 'security');
            return false;
        }

        // Verify signature
        if (isset($ipnData['verify_sign'])) {
            $expectedSignature = md5(
                $ipnData['tran_id'] .
                $ipnData['amount'] .
                $ipnData['currency'] .
                $this->storePassword
            );

            if (!hash_equals($expectedSignature, $ipnData['verify_sign'])) {
                logActivity("IPN signature verification failed for tran_id: " . $ipnData['tran_id'], 'security');
                return false;
            }
        }

        return true;
    }

    /**
     * Check transaction status
     *
     * @param string $transactionId SSLCommerz transaction ID
     * @return array Transaction status
     */
    public function checkTransactionStatus($transactionId) {
        $endpoint = $this->apiDomain . '/validator/api/merchantTransIDValidationAPI.php';

        $params = [
            'store_id' => $this->storeId,
            'store_passwd' => $this->storePassword,
            'tran_id' => $transactionId,
            'type' => 'transaction'
        ];

        $response = $this->makeApiCall($endpoint, $params);

        return $this->parseStatusResponse($response);
    }

    /**
     * Make API call to SSLCommerz
     *
     * @param string $endpoint API endpoint URL
     * @param array $params Request parameters
     * @return string API response
     */
    private function makeApiCall($endpoint, $params) {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($error) {
            logActivity("SSLCommerz API Error: " . $error, 'error');
            throw new Exception("SSLCommerz API connection failed");
        }

        if ($httpCode !== 200) {
            logActivity("SSLCommerz API returned HTTP {$httpCode}", 'error');
            throw new Exception("SSLCommerz API returned error code");
        }

        return $response;
    }

    /**
     * Parse create payment response
     *
     * @param string $response Raw API response
     * @return array Parsed response
     */
    private function parseCreateResponse($response) {
        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // Try parse URL-encoded response
            parse_str($response, $data);
        }

        return [
            'status' => $data['status'] ?? 'FAILED',
            'GatewayPageURL' => $data['GatewayPageURL'] ?? null,
            'sessionkey' => $data['sessionkey'] ?? null,
            'error' => $data['error'] ?? 'Unknown error'
        ];
    }

    /**
     * Parse transaction status response
     *
     * @param string $response Raw API response
     * @return array Parsed status
     */
    private function parseStatusResponse($response) {
        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            parse_str($response, $data);
        }

        return [
            'tran_id' => $data['tran_id'] ?? '',
            'bank_tran_id' => $data['bank_tran_id'] ?? '',
            'transaction_status' => $data['transaction_status'] ?? 'UNKNOWN',
            'amount' => $data['amount'] ?? '0',
            'currency' => $data['currency'] ?? 'BDT',
            'error' => $data['error'] ?? ''
        ];
    }
}
