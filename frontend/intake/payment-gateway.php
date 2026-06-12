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
     * Implements the official SSLCommerz hash verification: verify_sign is the
     * md5 of the ksorted key=value pairs named in verify_key, joined with '&',
     * plus store_passwd=md5(store_password).
     *
     * The source IP is logged for audit but not used as an auth gate —
     * SSLCommerz server IPs change and proxies rewrite REMOTE_ADDR.
     * Authenticity comes from this hash plus validateTransaction().
     *
     * @param array $ipnData IPN POST data
     * @return bool Valid or not
     */
    public function verifyIPN($ipnData) {
        $remoteIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (!in_array($remoteIp, SSLCZ_IPN_WHITELIST)) {
            logActivity("IPN from IP outside known list (advisory): {$remoteIp}", 'info');
        }

        if (empty($ipnData['verify_sign']) || empty($ipnData['verify_key'])) {
            logActivity("IPN missing verify_sign/verify_key", 'security');
            return false;
        }

        $signedFields = [];
        foreach (explode(',', $ipnData['verify_key']) as $key) {
            $key = trim($key);
            if ($key !== '' && array_key_exists($key, $ipnData)) {
                $signedFields[$key] = $ipnData[$key];
            }
        }
        $signedFields['store_passwd'] = md5($this->storePassword);
        ksort($signedFields);

        $pairs = [];
        foreach ($signedFields as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }
        $expectedSignature = md5(implode('&', $pairs));

        if (!hash_equals($expectedSignature, $ipnData['verify_sign'])) {
            logActivity("IPN signature verification failed for tran_id: " . ($ipnData['tran_id'] ?? 'unknown'), 'security');
            return false;
        }

        return true;
    }

    /**
     * Validate a transaction server-side via the SSLCommerz validation API
     *
     * This is the authoritative check recommended by SSLCommerz: after an IPN
     * arrives, confirm the transaction directly with their server using the
     * val_id from the IPN payload.
     *
     * @param string $valId Validation ID from IPN POST data
     * @return array ['status' => VALID|VALIDATED|..., 'tran_id', 'amount', ...]
     */
    public function validateTransaction($valId) {
        $endpoint = $this->apiDomain . '/validator/api/validationserverAPI.php';

        $params = [
            'val_id' => $valId,
            'store_id' => $this->storeId,
            'store_passwd' => $this->storePassword,
            'format' => 'json'
        ];

        $response = $this->makeApiCall($endpoint, $params, 'GET');
        $data = json_decode($response, true);
        if (!is_array($data)) {
            $data = [];
        }

        return [
            'status' => $data['status'] ?? 'INVALID',
            'tran_id' => $data['tran_id'] ?? '',
            'val_id' => $data['val_id'] ?? '',
            'amount' => $data['amount'] ?? '0',
            'currency' => $data['currency'] ?? '',
            'bank_tran_id' => $data['bank_tran_id'] ?? '',
            'card_type' => $data['card_type'] ?? '',
            'error' => $data['error'] ?? ''
        ];
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
            'format' => 'json'
        ];

        $response = $this->makeApiCall($endpoint, $params, 'GET');

        return $this->parseStatusResponse($response);
    }

    /**
     * Make API call to SSLCommerz
     *
     * @param string $endpoint API endpoint URL
     * @param array $params Request parameters
     * @param string $method HTTP method ('POST' or 'GET')
     * @return string API response
     */
    private function makeApiCall($endpoint, $params, $method = 'POST') {
        $ch = curl_init();

        if ($method === 'GET') {
            curl_setopt($ch, CURLOPT_URL, $endpoint . '?' . http_build_query($params));
        } else {
            curl_setopt($ch, CURLOPT_URL, $endpoint);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        }
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

        // merchantTransIDValidationAPI wraps transactions in an 'element' array
        if (isset($data['element'][0]) && is_array($data['element'][0])) {
            $data = $data['element'][0];
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
