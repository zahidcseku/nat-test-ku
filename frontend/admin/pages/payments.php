<?php
/**
 * Payment Management Dashboard
 * Admin interface for viewing and managing payment data
 */

// Require authentication
require_once __DIR__ . '/../auth/middleware.php';

// Page title
$pageTitle = 'Payment Management';
include __DIR__ . '/../templates/header.php';
?>

<div class="bg-white p-8 rounded-lg shadow">
    <h1 class="text-3xl font-bold text-primary mb-8">Payment Management</h1>

    <!-- Statistics Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
        <div class="bg-surface-container-low p-6 rounded-lg">
            <div class="text-sm text-secondary mb-1">Total Revenue</div>
            <div class="text-2xl font-bold text-primary" id="total-revenue">Loading...</div>
        </div>
        <div class="bg-success-container p-6 rounded-lg">
            <div class="text-sm text-success-dark mb-1">Paid Count</div>
            <div class="text-2xl font-bold text-success-dark" id="paid-count">Loading...</div>
        </div>
        <div class="bg-warning-container p-6 rounded-lg">
            <div class="text-sm text-warning-dark mb-1">Unpaid Count</div>
            <div class="text-2xl font-bold text-warning-dark" id="unpaid-count">Loading...</div>
        </div>
        <div class="bg-error-container p-6 rounded-lg">
            <div class="text-sm text-error-dark mb-1">Pending Revenue</div>
            <div class="text-2xl font-bold text-error-dark" id="pending-revenue">Loading...</div>
        </div>
    </div>

    <!-- Filters -->
    <div class="flex flex-wrap gap-4 mb-6">
        <select id="status-filter" class="px-4 py-2 border-2 border-surface-container-highest rounded-lg">
            <option value="">All Statuses</option>
            <option value="paid">Paid</option>
            <option value="unpaid">Unpaid</option>
            <option value="failed">Failed</option>
        </select>

        <input type="date" id="date-from" class="px-4 py-2 border-2 border-surface-container-highest rounded-lg">
        <input type="date" id="date-to" class="px-4 py-2 border-2 border-surface-container-highest rounded-lg">

        <input type="text" id="search" placeholder="Search..." class="px-4 py-2 border-2 border-surface-container-highest rounded-lg">

        <button id="filter-btn" class="bg-primary text-white px-6 py-2 rounded-lg font-semibold hover:bg-primary/90">
            Apply Filters
        </button>
    </div>

    <!-- Payment Table -->
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead>
                <tr class="border-b-2 border-surface-container-highest">
                    <th class="text-left p-4 font-bold text-primary">Name</th>
                    <th class="text-left p-4 font-bold text-primary">Email</th>
                    <th class="text-left p-4 font-bold text-primary">Amount</th>
                    <th class="text-left p-4 font-bold text-primary">Status</th>
                    <th class="text-left p-4 font-bold text-primary">Payment Time</th>
                    <th class="text-left p-4 font-bold text-primary">Actions</th>
                </tr>
            </thead>
            <tbody id="payment-table">
                <tr>
                    <td colspan="6" class="text-center p-8 text-secondary">Loading payments...</td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Bulk Actions -->
    <div class="mt-6 flex gap-4">
        <button id="bulk-retry-btn" class="bg-warning text-warning-dark px-6 py-2 rounded-lg font-semibold hover:bg-warning/90">
            Send Retry Emails
        </button>
        <button id="export-btn" class="bg-primary text-white px-6 py-2 rounded-lg font-semibold hover:bg-primary/90">
            Export CSV
        </button>
    </div>
</div>

<script src="../api/payments/list.js"></script>
<script>
// Load payments on page load
document.addEventListener('DOMContentLoaded', function() {
    loadPayments();
});

function loadPayments(filters = {}) {
    const params = new URLSearchParams(filters);
    
    fetch('../api/payments/list.php?' + params.toString())
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateStats(data.data.stats);
                updateTable(data.data.payments);
            }
        })
        .catch(error => {
            console.error('Error loading payments:', error);
        });
}

function updateStats(stats) {
    document.getElementById('total-revenue').textContent = '৳' + stats.revenue.toLocaleString();
    document.getElementById('paid-count').textContent = stats.paid_count;
    document.getElementById('unpaid-count').textContent = stats.unpaid_count;
    document.getElementById('pending-revenue').textContent = '৳' + stats.pending_revenue.toLocaleString();
}

function updateTable(payments) {
    const tbody = document.getElementById('payment-table');
    
    if (payments.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center p-8 text-secondary">No payments found</td></tr>';
        return;
    }

    tbody.innerHTML = payments.map(payment => `
        <tr class="border-b border-surface-container-low">
            <td class="p-4">${payment.full_name}</td>
            <td class="p-4">${payment.email}</td>
            <td class="p-4 font-semibold">৳${payment.total_amount.toLocaleString()}</td>
            <td class="p-4">
                <span class="px-3 py-1 rounded-full text-sm font-semibold ${getStatusClass(payment.payment_status)}">
                    ${payment.payment_status}
                </span>
            </td>
            <td class="p-4">${payment.payment_time || '-'}</td>
            <td class="p-4">
                <button onclick="sendRetryEmail('${payment.id}')" class="text-primary hover:underline">
                    Send Retry Email
                </button>
            </td>
        </tr>
    `).join('');
}

function getStatusClass(status) {
    switch(status) {
        case 'paid': return 'bg-success-container text-success-dark';
        case 'unpaid': return 'bg-warning-container text-warning-dark';
        case 'failed': return 'bg-error-container text-error-dark';
        default: return 'bg-surface-container text-secondary';
    }
}

function sendRetryEmail(registrationId) {
    if (!confirm('Send retry email to this user?')) return;

    fetch('../api/payments/retry-email.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ registration_id: registrationId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Retry email sent successfully');
        } else {
            alert('Failed to send retry email');
        }
    })
    .catch(error => {
        alert('Error sending retry email');
    });
}

// Filter button handler
document.getElementById('filter-btn').addEventListener('click', function() {
    const filters = {
        status: document.getElementById('status-filter').value,
        date_from: document.getElementById('date-from').value,
        date_to: document.getElementById('date-to').value,
        search: document.getElementById('search').value
    };

    loadPayments(filters);
});
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
