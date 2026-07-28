/**
 * Certificate Request form logic.
 *
 * Two-step flow mirroring RegistrationForm (see js/registration.js):
 *   1. Verify identity (past exam date + xlsx_id + name + DOB) ->
 *      pre-fill recipient_name / recipient_phone from the DB.
 *   2. Confirm shipping address -> POST to /intake/certificate-request.php ->
 *      redirect to SSLCommerz redirect_url.
 */
const CertificateRequestForm = (function () {
  'use strict';

  const CONFIG = {
    FEE: 200,
    ENDPOINTS: {
      PAST_EXAMS: 'intake/get_past_exam_dates.php',
      VERIFY:     'intake/certificate-verify.php',
      SUBMIT:     'intake/certificate-request.php',
    },
  };

  const PHONE_REGEX = /^(\+?880|0)?1[3-9]\d{8}$/;

  let verified = null; // Holds the response from a successful verify step.

  // ---------------- DOM helpers ----------------
  function $(id) { return document.getElementById(id); }

  function showLoading(text) {
    const ov = $('loading-overlay');
    if (text) $('loading-text').textContent = text;
    ov.classList.add('show');
  }
  function hideLoading() { $('loading-overlay').classList.remove('show'); }

  function showBanner(el, message, type) {
    el.textContent = message;
    el.className = 'submit-banner show ' + (type === 'error' ? 'error' : 'success');
  }
  function clearBanner(el) {
    el.textContent = '';
    el.className = 'submit-banner';
  }

  function goToStep(num) {
    [1, 2].forEach(function (n) {
      $('step-' + n).classList.toggle('active', n === num);
    });
    // Step badges
    const badges = {
      1: $('step-1-badge'), 2: $('step-2-badge'), 3: $('step-3-badge'),
    };
    Object.keys(badges).forEach(function (nStr) {
      const n = Number(nStr);
      const b = badges[n];
      const dot = b.querySelector('span:first-child');
      if (n < num) {
        b.className = 'flex items-center gap-2 text-primary';
        dot.className = 'w-7 h-7 rounded-full bg-primary text-white flex items-center justify-center text-xs';
        dot.textContent = '✓';
      } else if (n === num) {
        b.className = 'flex items-center gap-2 text-primary font-semibold';
        dot.className = 'w-7 h-7 rounded-full bg-primary text-white flex items-center justify-center text-xs';
        dot.textContent = String(n);
      } else {
        b.className = 'flex items-center gap-2 text-secondary';
        dot.className = 'w-7 h-7 rounded-full bg-surface-container-high flex items-center justify-center text-xs';
        dot.textContent = String(n);
      }
    });
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  // ---------------- Step 1: load exam dates ----------------
  async function loadPastExamDates() {
    try {
      const res = await fetch(CONFIG.ENDPOINTS.PAST_EXAMS, { method: 'GET' });
      const json = await res.json();
      const sel = $('exam_date_id');
      sel.innerHTML = '';
      const exams = (json && json.data && json.data.exams) || [];
      if (exams.length === 0) {
        sel.innerHTML = '<option value="">No past exams with score reports yet</option>';
        return;
      }
      sel.innerHTML = '<option value="">— Select an exam date —</option>';
      exams.forEach(function (ex) {
        const opt = document.createElement('option');
        opt.value = ex.id;
        opt.textContent = ex.display;
        opt.dataset.value = ex.value;
        sel.appendChild(opt);
      });
    } catch (err) {
      const sel = $('exam_date_id');
      sel.innerHTML = '<option value="">Failed to load exam dates</option>';
    }
  }

  // ---------------- Step 1: verify identity ----------------
  async function verifyIdentity(ev) {
    ev.preventDefault();
    clearBanner($('verify-banner'));

    const form = ev.target;
    const fd = new FormData(form);
    const fields = ['exam_date_id', 'xlsx_id', 'full_name', 'dob'];
    for (const f of fields) {
      const v = (fd.get(f) || '').toString().trim();
      if (v === '') {
        showBanner($('verify-banner'), 'Please fill in all fields.', 'error');
        return;
      }
    }

    const btn = $('verify-btn');
    btn.disabled = true;
    btn.textContent = 'Verifying...';
    showLoading('Verifying your identity');

    try {
      const res = await fetch(CONFIG.ENDPOINTS.VERIFY, { method: 'POST', body: fd });
      const json = await res.json();
      hideLoading();
      btn.disabled = false;
      btn.textContent = 'Verify Identity';

      if (json && json.success && json.data) {
        verified = json.data;
        // Pre-fill step 2.
        $('registration_id').value = verified.registration_id;
        $('exam_date_id_held').value = $('exam_date_id').value;
        $('xlsx_id_held').value = (fd.get('xlsx_id') || '').toString().trim();
        $('recipient_name').value = verified.full_name || '';
        $('recipient_phone').value = verified.mobile || '';
        $('verified-name').textContent = verified.full_name || '';
        goToStep(2);
      } else {
        const msg = (json && json.error) || 'No matching examinee found for this exam.';
        showBanner($('verify-banner'), msg, 'error');
      }
    } catch (err) {
      hideLoading();
      btn.disabled = false;
      btn.textContent = 'Verify Identity';
      showBanner($('verify-banner'), 'Network error. Please try again.', 'error');
    }
  }

  // ---------------- Step 2: submit + pay ----------------
  function validatePhone() {
    const v = $('recipient_phone').value.trim();
    const ok = PHONE_REGEX.test(v);
    $('phone-error').classList.toggle('show', !ok);
    return ok;
  }

  async function submitRequest(ev) {
    ev.preventDefault();
    clearBanner($('submit-banner'));

    if (!validatePhone()) {
      showBanner($('submit-banner'), 'Please fix the phone number and try again.', 'error');
      return;
    }

    const required = [
      'registration_id', 'exam_date_id_held', 'xlsx_id_held',
      'recipient_name', 'recipient_phone', 'house_street', 'area_thana', 'district',
    ];
    for (const id of required) {
      const v = ($(id).value || '').toString().trim();
      if (v === '') {
        showBanner($('submit-banner'), 'Please fill in all required address fields.', 'error');
        return;
      }
    }

    // Build FormData mapping the "held" inputs to the names the endpoint expects.
    const fd = new FormData();
    fd.append('registration_id', $('registration_id').value);
    fd.append('exam_date_id',    $('exam_date_id_held').value);
    fd.append('xlsx_id',         $('xlsx_id_held').value);
    fd.append('recipient_name',  $('recipient_name').value.trim());
    fd.append('recipient_phone', $('recipient_phone').value.trim());
    fd.append('house_street',    $('house_street').value.trim());
    fd.append('area_thana',      $('area_thana').value.trim());
    fd.append('district',        $('district').value.trim());
    fd.append('postal_code',     $('postal_code').value.trim());
    // Honeypot
    fd.append('website', '');

    const btn = $('pay-btn');
    btn.disabled = true;
    btn.textContent = 'Redirecting to payment...';
    showLoading('Contacting payment gateway');

    try {
      const res = await fetch(CONFIG.ENDPOINTS.SUBMIT, { method: 'POST', body: fd });
      const json = await res.json();
      hideLoading();
      btn.disabled = false;
      btn.textContent = 'Pay 200 BDT & Request';

      if (json && json.success && json.data && json.data.redirect_url) {
        window.location.href = json.data.redirect_url;
      } else {
        const msg = (json && json.error) || 'Failed to initiate payment. Please try again.';
        showBanner($('submit-banner'), msg, 'error');
      }
    } catch (err) {
      hideLoading();
      btn.disabled = false;
      btn.textContent = 'Pay 200 BDT & Request';
      showBanner($('submit-banner'), 'Network error. Please try again.', 'error');
    }
  }

  // ---------------- Wire-up ----------------
  function init() {
    loadPastExamDates();

    $('verify-form').addEventListener('submit', verifyIdentity);
    $('address-form').addEventListener('submit', submitRequest);
    $('back-to-step-1').addEventListener('click', function () { goToStep(1); });
    $('recipient_phone').addEventListener('blur', validatePhone);
  }

  return { init: init };
})();

document.addEventListener('DOMContentLoaded', CertificateRequestForm.init);
