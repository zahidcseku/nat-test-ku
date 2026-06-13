/**
 * Applicant self-service application lookup.
 * Card inputs -> POST /intake/application-lookup.php -> modal with results.
 * All rendering uses DOM methods; applicant data never goes through innerHTML.
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var btn = document.getElementById('lookup_btn');
    var modal = document.getElementById('lookup_modal');
    var results = document.getElementById('lookup_results');
    var errorEl = document.getElementById('lookup-error');
    if (!btn || !modal || !results) return;

    document.getElementById('lookup_modal_close').addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });

    btn.addEventListener('click', submitLookup);

    function closeModal() { modal.classList.add('hidden'); }

    function showError(msg) {
      errorEl.textContent = msg;
      errorEl.classList.add('show');
    }

    function submitLookup() {
      errorEl.classList.remove('show');
      var name = document.getElementById('lookup_name').value.trim();
      var mobile = document.getElementById('lookup_mobile').value.trim();
      var dob = document.getElementById('lookup_dob').value;

      if (!name || !mobile || !dob) {
        showError('Please enter your full name, mobile number and date of birth.');
        return;
      }

      btn.disabled = true;
      btn.textContent = 'Searching...';

      var params = new URLSearchParams();
      params.set('full_name', name);
      params.set('mobile', mobile);
      params.set('dob', dob);

      fetch('intake/application-lookup.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
      })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
        .then(function (res) {
          btn.disabled = false;
          btn.textContent = 'View my application';
          if (res.ok && res.data.success && res.data.data && res.data.data.applications) {
            renderResults(res.data.data.applications);
            modal.classList.remove('hidden');
          } else {
            showError(res.data.error || 'No application found with those details');
          }
        })
        .catch(function () {
          btn.disabled = false;
          btn.textContent = 'View my application';
          showError('Could not reach the server — please try again.');
        });
    }

    function el(tag, className, text) {
      var node = document.createElement(tag);
      if (className) node.className = className;
      if (text !== undefined) node.textContent = text;
      return node;
    }

    function renderResults(applications) {
      results.textContent = '';

      applications.forEach(function (app, idx) {
        var section = el('div', 'mb-6 pb-6' + (idx < applications.length - 1 ? ' border-b border-surface-container-highest' : ''));

        // Status badges
        var statusWrap = el('div', 'flex flex-wrap gap-2 mb-4');
        var pay = el('span', 'px-3 py-1 rounded-full text-xs font-bold ' +
          (app.payment_status === 'paid' ? 'bg-green-100 text-green-800'
            : app.payment_status === 'failed' ? 'bg-red-100 text-red-800'
            : 'bg-amber-100 text-amber-800'),
          'Payment: ' + String(app.payment_status).toUpperCase());
        var appr = el('span', 'px-3 py-1 rounded-full text-xs font-bold ' +
          (app.approved ? 'bg-green-100 text-green-800' : 'bg-gray-200 text-gray-700'),
          app.approved ? 'APPROVED' : 'PENDING REVIEW');
        statusWrap.appendChild(pay);
        statusWrap.appendChild(appr);
        section.appendChild(statusWrap);

        // Data table
        var rows = [
          ['Registration ID', app.id],
          ['Full Name', app.full_name],
          ['Email', app.email],
          ['Mobile', app.mobile],
          ['Address', app.address],
          ['Date of Birth', app.dob],
          ['Nationality', app.nationality],
          ['ID Document', app.id_document],
          ['Exam Level(s)', app.exam_level],
          ['Test Date', app.test_date],
          ['Registration Fee', Number(app.total_amount).toLocaleString('en-BD') + ' BDT'],
          ['Payment Method', app.payment_method === 'online' ? 'Online Payment' : 'Bank Deposit'],
          ['Submitted', app.submitted_at]
        ];
        var table = el('table', 'w-full text-sm border-collapse mb-4');
        rows.forEach(function (r) {
          var tr = el('tr');
          var th = el('td', 'border border-surface-container-highest bg-surface-container-low font-semibold p-2 w-2/5', r[0]);
          var td = el('td', 'border border-surface-container-highest p-2', r[1] == null ? '' : String(r[1]));
          tr.appendChild(th);
          tr.appendChild(td);
          table.appendChild(tr);
        });
        section.appendChild(table);

        // Pay action for unpaid/failed registrations
        if (app.retry_link) {
          var payLink = el('a', 'inline-block bg-primary text-white px-5 py-2.5 rounded-lg text-sm font-semibold hover:opacity-90 mb-3', 'Complete payment');
          payLink.href = app.retry_link;
          section.appendChild(payLink);
        }

        // Change-request note: upcoming exams only
        if (app.is_upcoming) {
          var note = el('p', 'text-xs text-secondary bg-surface-container-low rounded-lg p-3');
          note.appendChild(document.createTextNode('Need to change or update any information for this upcoming exam? Email us at '));
          var mail = el('a', 'text-primary underline', 'info@nat-test.ku.ac.bd');
          mail.href = 'mailto:info@nat-test.ku.ac.bd';
          note.appendChild(mail);
          note.appendChild(document.createTextNode('.'));
          section.appendChild(note);
        }

        results.appendChild(section);
      });
    }
  });
})();
