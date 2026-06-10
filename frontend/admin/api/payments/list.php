<?php
/**
 * Payment List API Endpoint
 * Returns payment statistics and filtered payment list
 */

// Require authentication
require_once __DIR__ . '/../../auth/middleware.php';

header('Content-Type: application/json');

// Get filter parameters
$status = $_GET['status'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';

try {
    $conn = getDbConnection();
    if (!$conn) {
        echo json_encode(['success' => false, 'error' => 'Database connection failed']);
        exit;
    }

    // Build WHERE clause
    $where = ['1=1'];
    $params = [];
    $types = '';

    if (!empty($status)) {
        $where[] = 'payment_status = ?';
        $params[] = $status;
        $types .= 's';
    }

    if (!empty($dateFrom)) {
        $where[] = 'created_at >= ?';
        $params[] = $dateFrom;
        $types .= 's';
    }

    if (!empty($dateTo)) {
        $where[] = 'created_at <= ?';
        $params[] = $dateTo;
        $types .= 's';
    }

    if (!empty($search)) {
        $where[] = '(full_name LIKE ? OR email LIKE ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
        $types .= 'ss';
    }

    $whereClause = implode(' AND ', $where);

    // Get statistics
    $statsQuery = "
        SELECT
            COUNT(*) as total_registrations,
            SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) as paid_count,
            SUM(CASE WHEN payment_status = 'unpaid' THEN 1 ELSE 0 END) as unpaid_count,
            SUM(CASE WHEN payment_status = 'paid' THEN total_amount_paid ELSE 0 END) as revenue,
            SUM(CASE WHEN payment_status = 'unpaid' THEN total_amount_paid ELSE 0 END) as pending_revenue
        FROM registrations
        WHERE {$whereClause}
    ";

    $stmt = $conn->prepare($statsQuery);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $stats = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Get payment list
    $listQuery = "
        SELECT
            id, full_name, email, base_amount, transaction_fee, total_amount_paid,
            payment_status, payment_time, created_at
        FROM registrations
        WHERE {$whereClause}
        ORDER BY created_at DESC
        LIMIT 100
    ";

    $stmt = $conn->prepare($listQuery);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $payments = [];
    while ($row = $result->fetch_assoc()) {
        $payments[] = [
            'id' => $row['id'],
            'full_name' => $row['full_name'],
            'email' => $row['email'],
            'base_amount' => (float)$row['base_amount'],
            'transaction_fee' => (float)$row['transaction_fee'],
            'total_amount' => (float)$row['total_amount_paid'],
            'payment_status' => $row['payment_status'],
            'payment_time' => $row['payment_time'],
            'created_at' => $row['created_at']
        ];
    }

    $stmt->close();
    $conn->close();

    echo json_encode([
        'success' => true,
        'data' => [
            'stats' => [
                'total_registrations' => (int)$stats['total_registrations'],
                'paid_count' => (int)$stats['paid_count'],
                'unpaid_count' => (int)$stats['unpaid_count'],
                'revenue' => (float)$stats['revenue'],
                'pending_revenue' => (float)$stats['pending_revenue']
            ],
            'payments' => $payments
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
