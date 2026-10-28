/**
 * Payment Retry Page Logic
 *
 * All intake calls are POST multipart (initSecurity requirement) and the
 * server never returns the raw retry token: the token comes either from
 * the emailed retry link (?token=...) in the URL, or embedded in the
 * retry_link returned to email+dob verified requesters.
 */

document.addEventListener('DOMContentLoaded', function() {
    const searchForm = document.getElementById('search-form');
    const loadingDiv = document.getElementById('loading');
    const resultDiv = document.getElementById('result');
    const searchBtn = document.getElementById('search-btn');
    const emailInput = document.getElementById('email-input');
    const dobInput = document.getElementById('dob-input');
    const errorDiv = document.getElementById('search-error');
    const errorMessage = document.getElementById('error-message');

    // Check for token in URL (direct retry link from the applicant's email)
    const urlParams = new URLSearchParams(window.location.search);
    const urlToken = urlParams.get('token');

    if (urlToken) {
        // Direct retry link - show loading and lookup
        searchByToken(urlToken);
    }

    // Search button click handler
    searchBtn.addEventListener('click', function() {
        const email = emailInput.value.trim();
        const dob = dobInput.value.trim();

        if (!email) {
            showError('Please enter your email address');
            return;
        }

        if (!dob) {
            showError('Please enter your date of birth');
            return;
        }

        searchRegistration(email, dob);
    });

    // Enter key handlers
    emailInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            searchBtn.click();
        }
    });

    dobInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            searchBtn.click();
        }
    });

    function searchRegistration(email, dob) {
        hideError();
        showLoading();

        const params = new FormData();
        params.append('email', email);
        params.append('dob', dob);

        fetch('/intake/payment-retry.php', { method: 'POST', body: params })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                searchForm.classList.add('hidden');
                resultDiv.classList.remove('hidden');

                if (!data.success || !data.data || !data.data.found) {
                    showNotFound();
                    return;
                }

                showResult(data.data, null);
            })
            .catch(error => {
                hideLoading();
                showError('Network error. Please try again.');
            });
    }

    function searchByToken(token) {
        showLoading();
        searchForm.classList.add('hidden');

        const params = new FormData();
        params.append('token', token);

        fetch('/intake/payment-retry.php', { method: 'POST', body: params })
            .then(response => response.json())
            .then(data => {
                resultDiv.classList.remove('hidden');

                if (!data.success || !data.data || !data.data.found) {
                    hideLoading();
                    showNotFound();
                    return;
                }

                hideLoading();
                showResult(data.data, token);
            })
            .catch(error => {
                hideLoading();
                searchForm.classList.remove('hidden');
                showError('Network error. Please try again.');
            });
    }

    function startPayment(token, button) {
        if (!token) {
            alert('Retry link not available. Please look up your registration with your email and date of birth, or contact support.');
            return;
        }

        button.disabled = true;
        button.textContent = 'Connecting to payment gateway...';

        const params = new FormData();
        params.append('token', token);

        fetch('/intake/payment-retry-session.php', { method: 'POST', body: params })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.data && data.data.redirect_url) {
                    window.location.href = data.data.redirect_url;
                } else {
                    button.disabled = false;
                    button.textContent = 'Try Again';
                    alert(data.error || 'Could not start the payment session. Please try again.');
                }
            })
            .catch(error => {
                button.disabled = false;
                button.textContent = 'Try Again';
                alert('Network error. Please try again.');
            });
    }

    // The token is never in the response payload: it comes from the URL
    // (emailed retry link) or from the retry_link of a verified lookup.
    function tokenFromResult(registration, urlToken) {
        if (urlToken) return urlToken;
        if (registration.retry_link) {
            try {
                return new URL(registration.retry_link).searchParams.get('token');
            } catch (e) {
                return null;
            }
        }
        return null;
    }

    function showResult(registration, urlToken) {
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
            const retryBtn = document.getElementById('retry-btn');
            wireRetryButton(registration, urlToken, retryBtn);
        } else if (registration.payment_status === 'failed') {
            document.getElementById('status-failed').classList.remove('hidden');
            wireRetryButton(registration, urlToken, document.getElementById('retry-failed-btn'));
        }
    }

    function wireRetryButton(registration, urlToken, button) {
        const token = tokenFromResult(registration, urlToken);

        if (registration.link_expired || !token) {
            button.disabled = true;
            button.textContent = 'Retry link expired — reload and look up your registration to get a new one';
            const expiryInfo = document.getElementById('expiry-info');
            if (expiryInfo) {
                expiryInfo.textContent = 'Enter your email address and date of birth to receive a fresh retry link.';
            }
            return;
        }

        if (registration.expires_at) {
            const expiry = new Date(registration.expires_at);
            const expiryInfo = document.getElementById('expiry-info');
            if (expiryInfo) {
                expiryInfo.textContent = 'Retry link expires: ' + expiry.toLocaleDateString();
            }
        }

        button.addEventListener('click', function() {
            startPayment(token, this);
        });
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
