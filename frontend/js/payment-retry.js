/**
 * Payment Retry Page Logic
 */

document.addEventListener('DOMContentLoaded', function() {
    const searchForm = document.getElementById('search-form');
    const loadingDiv = document.getElementById('loading');
    const resultDiv = document.getElementById('result');
    const searchBtn = document.getElementById('search-btn');
    const emailInput = document.getElementById('email-input');
    const registrationInput = document.getElementById('registration-input');
    const errorDiv = document.getElementById('search-error');
    const errorMessage = document.getElementById('error-message');

    // Check for token in URL (direct retry link)
    const urlParams = new URLSearchParams(window.location.search);
    const token = urlParams.get('token');

    if (token) {
        // Direct retry link - show loading and lookup
        searchByToken(token);
    }

    // Search button click handler
    searchBtn.addEventListener('click', function() {
        const email = emailInput.value.trim();
        const registrationId = registrationInput.value.trim();

        if (!email && !registrationId) {
            showError('Please enter an email address or registration ID');
            return;
        }

        if (email && registrationId) {
            showError('Please enter either email OR registration ID, not both');
            return;
        }

        searchRegistration(email, registrationId);
    });

    // Enter key handlers
    emailInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            searchBtn.click();
        }
    });

    registrationInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            searchBtn.click();
        }
    });

    function searchRegistration(email, registrationId) {
        hideError();
        showLoading();

        let params = new URLSearchParams();
        if (email) params.append('email', email);
        if (registrationId) params.append('registration_id', registrationId);

        fetch('/intake/payment-retry.php?' + params.toString())
            .then(response => response.json())
            .then(data => {
                hideLoading();
                searchForm.classList.add('hidden');
                resultDiv.classList.remove('hidden');

                if (!data.success) {
                    showNotFound();
                    return;
                }

                if (!data.data || !data.data.found) {
                    showNotFound();
                    return;
                }

                showResult(data.data);
            })
            .catch(error => {
                hideLoading();
                showError('Network error. Please try again.');
            });
    }

    function searchByToken(token) {
        showLoading();
        searchForm.classList.add('hidden');

        // Token lookup would need backend endpoint
        // For now, redirect to regular search
        window.location.href = '/payment-retry.html';
    }

    function showResult(registration) {
        document.getElementById('not-found').classList.add('hidden');
        document.getElementById('found').classList.remove('hidden');

        // Populate result
        document.getElementById('result-name').textContent = registration.full_name;
        document.getElementById('result-email').textContent = registration.email;
        document.getElementById('result-base').textContent = registration.base_amount + ' BDT';
        document.getElementById('result-fee').textContent = registration.transaction_fee + ' BDT';
        document.getElementById('result-total').textContent = registration.total_amount + ' BDT';

        // Show payment status
        hideAllStatus();
        if (registration.payment_status === 'paid') {
            document.getElementById('status-paid').classList.remove('hidden');
        } else if (registration.payment_status === 'unpaid') {
            document.getElementById('status-unpaid').classList.remove('hidden');
            if (registration.expires_at) {
                const expiry = new Date(registration.expires_at);
                document.getElementById('expiry-info').textContent =
                    'Retry link expires: ' + expiry.toLocaleDateString();
            }

            // Add retry button handler
            document.getElementById('retry-btn').addEventListener('click', function() {
                if (registration.retry_link) {
                    window.location.href = registration.retry_link;
                } else {
                    showError('Retry link not available. Please contact support.');
                }
            });
        } else if (registration.payment_status === 'failed') {
            document.getElementById('status-failed').classList.remove('hidden');

            // Add retry button handler
            document.getElementById('retry-failed-btn').addEventListener('click', function() {
                if (registration.retry_link) {
                    window.location.href = registration.retry_link;
                } else {
                    showError('Retry link not available. Please contact support.');
                }
            });
        }
    }

    function showNotFound() {
        document.getElementById('not-found').classList.remove('hidden');
        document.getElementById('found').classList.add('hidden');
    }

    function hideAllStatus() {
        document.getElementById('status-paid').classList.add('hidden');
        document.getElementById('status-unpaid').classList.add('hidden');
        document.getElementById('status-failed').classList.add('hidden');
    }

    function showLoading() {
        searchForm.classList.add('hidden');
        loadingDiv.classList.remove('hidden');
        resultDiv.classList.add('hidden');
    }

    function hideLoading() {
        loadingDiv.classList.add('hidden');
    }

    function showError(message) {
        errorMessage.textContent = message;
        errorDiv.classList.remove('hidden');
    }

    function hideError() {
        errorDiv.classList.add('hidden');
    }
});
