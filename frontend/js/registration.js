/**
 * Registration Form Module
 * Handles the one-page registration form: validation, file uploads, and submission
 */

const RegistrationForm = (function() {
  'use strict';

  // Configuration
  const CONFIG = {
    MAX_PHOTO_SIZE: 2 * 1024 * 1024, // 2MB
    MAX_ID_SIZE: 4 * 1024 * 1024,     // 4MB
    MAX_PAYMENT_SIZE: 4 * 1024 * 1024, // 4MB
    ALLOWED_PHOTO_TYPES: ['image/jpeg', 'image/png'],
    ALLOWED_ID_TYPES: ['image/jpeg', 'image/png', 'application/pdf'],
    ALLOWED_PAYMENT_TYPES: ['image/jpeg', 'image/png', 'application/pdf'],
    // Registration fee per level. The applicant pays this base fee only —
    // SSLCommerz's commission is merchant-side (deducted from settlement)
    FEE_PER_LEVEL: 4000,
    MAX_LEVELS: 5,
    MIN_LEVELS: 1,
  };

  // State management
  let examDatesData = []; // Store exam dates with levels
  let formData = {
    step1: {},
    step2: {},
    step3: {},
    step4: {}
  };
  let selectedLevels = [];
  let totalAmount = 0;

  /**
   * Calculate payment amount
   *
   * The applicant pays the base fee only. SSLCommerz's commission is
   * deducted from the merchant settlement, never added to the charge.
   */
  function calculatePaymentAmount(levelCount) {
    const baseAmount = levelCount * CONFIG.FEE_PER_LEVEL;

    return {
      base: baseAmount,
      fee: 0,
      total: baseAmount
    };
  }

  /**
   * Update payment display
   */
  function updatePaymentDisplay(levelCount) {
    if (levelCount === 0) {
      const paymentAmountEl = document.getElementById('payment_amount_display');
      const paymentLevelsEl = document.getElementById('payment_levels_display');
      const paymentFeeEl = document.getElementById('payment_fee_display');

      if (paymentAmountEl) paymentAmountEl.textContent = '0 BDT';
      if (paymentLevelsEl) paymentLevelsEl.textContent = 'Select exam levels first';
      if (paymentFeeEl) paymentFeeEl.classList.add('hidden');
      return;
    }

    const payment = calculatePaymentAmount(levelCount);

    const paymentAmountEl = document.getElementById('payment_amount_display');
    const paymentLevelsEl = document.getElementById('payment_levels_display');
    const paymentFeeEl = document.getElementById('payment_fee_display');

    if (paymentAmountEl) {
      paymentAmountEl.textContent = payment.total.toLocaleString('en-BD') + ' BDT';
    }
    if (paymentLevelsEl) {
      paymentLevelsEl.textContent = `For ${levelCount} selected level${levelCount > 1 ? 's' : ''}`;
    }
    if (paymentFeeEl) {
      paymentFeeEl.textContent = 'There is a 2.5% (3.5% for AMEX) online transaction processing charge for online payments.';
      paymentFeeEl.classList.remove('hidden');
    }
  }

  /**
   * Show loading overlay
   */
  function showLoading(message) {
    // Remove existing overlay if present
    hideLoading();

    const overlay = document.createElement('div');
    overlay.id = 'loading-overlay';
    overlay.className = 'fixed inset-0 bg-black/50 flex items-center justify-center z-50';

    const contentDiv = document.createElement('div');
    contentDiv.className = 'bg-white rounded-lg p-8 max-w-md mx-4 text-center';

    const spinner = document.createElement('div');
    spinner.className = 'animate-spin rounded-full h-12 w-12 border-b-2 border-primary mx-auto mb-4';

    const messageP = document.createElement('p');
    messageP.className = 'text-lg font-semibold text-primary';
    messageP.textContent = message;

    const waitP = document.createElement('p');
    waitP.className = 'text-sm text-secondary mt-2';
    waitP.textContent = 'Please wait, do not close this page...';

    contentDiv.appendChild(spinner);
    contentDiv.appendChild(messageP);
    contentDiv.appendChild(waitP);
    overlay.appendChild(contentDiv);
    document.body.appendChild(overlay);
  }

  /**
   * Hide loading overlay
   */
  function hideLoading() {
    const overlay = document.getElementById('loading-overlay');
    if (overlay) {
      overlay.remove();
    }
  }

  /**
   * Utility: Validate email format
   */
  function isValidEmail(email) {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return emailRegex.test(email);
  }

  /**
   * Utility: Validate phone number (Bangladeshi format)
   * Accepts: +8801xxxxxxxxx, 8801xxxxxxxxx, 01xxxxxxxxx
   */
  function isValidPhone(phone) {
    const phoneRegex = /^(\+?880|0)?1[3-9]\d{8}$/;
    return phoneRegex.test(phone);
  }

  /**
   * Utility: Validate file size and type
   */
  function validateFile(file, maxSize, allowedTypes) {
    if (!file) return { valid: false, error: 'No file selected' };

    if (file.size > maxSize) {
      const maxSizeMB = (maxSize / (1024 * 1024)).toFixed(0);
      return { valid: false, error: `File size exceeds ${maxSizeMB}MB limit` };
    }

    if (!allowedTypes.includes(file.type)) {
      return { valid: false, error: 'Invalid file type' };
    }

    return { valid: true, error: null };
  }

  /**
   * Utility: Format file size for display
   */
  function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
  }

  /**
   * Utility: Show error message
   */
  function showError(fieldId, message) {
    const errorEl = document.getElementById(`${fieldId}-error`);
    if (errorEl) {
      errorEl.textContent = message;
      errorEl.classList.add('show');
    }

    const fieldEl = document.getElementById(fieldId);
    if (fieldEl) {
      fieldEl.classList.add('invalid');
      fieldEl.classList.remove('valid');
    }
  }

  /**
   * Utility: Show success message
   */
  function showSuccess(fieldId, message = '') {
    const errorEl = document.getElementById(`${fieldId}-error`);
    if (errorEl) {
      errorEl.classList.remove('show');
    }

    const successEl = document.getElementById(`${fieldId}-success`);
    if (successEl && message) {
      successEl.textContent = message;
      successEl.classList.add('show');
    }

    const fieldEl = document.getElementById(fieldId);
    if (fieldEl) {
      fieldEl.classList.remove('invalid');
      fieldEl.classList.add('valid');
    }
  }

  /**
   * Utility: Clear field validation state
   */
  function clearFieldValidation(fieldId) {
    const errorEl = document.getElementById(`${fieldId}-error`);
    if (errorEl) {
      errorEl.classList.remove('show');
    }

    const successEl = document.getElementById(`${fieldId}-success`);
    if (successEl) {
      successEl.classList.remove('show');
    }

    const fieldEl = document.getElementById(fieldId);
    if (fieldEl) {
      fieldEl.classList.remove('invalid', 'valid');
    }
  }

  /**
   * Scroll to and focus the first field with a visible error
   */
  function scrollToFirstError() {
    const firstError = document.querySelector('.field-error.show');
    if (!firstError) return;
    firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
    const wrapper = firstError.closest('div');
    const field = wrapper ? wrapper.querySelector('input, select, textarea') : null;
    if (field) field.focus({ preventScroll: true });
  }

  /**
   * Wire inline validation: fields validate when the user leaves them
   */
  function initInlineValidation() {
    ['full_name', 'email', 'mobile', 'address', 'nationality'].forEach(fieldId => {
      const el = document.getElementById(fieldId);
      if (el) el.addEventListener('blur', () => validateField(fieldId));
    });

    const dob = document.getElementById('dob');
    if (dob) dob.addEventListener('change', () => validateField('dob'));

    const testDate = document.getElementById('test_date');
    if (testDate) testDate.addEventListener('change', () => validateField('test_date'));

    document.querySelectorAll('input[name="payment_method"]').forEach(radio => {
      radio.addEventListener('change', () => {
        validateStep3();
        toggleReceiptSection(radio.value);
      });
    });
  }

  /**
   * The payment receipt upload only applies to bank deposits — hide it
   * (and drop any chosen file) when the user pays online
   */
  function toggleReceiptSection(paymentMethod) {
    const section = document.getElementById('payment_receipt_section');
    if (!section) return;

    if (paymentMethod === 'online') {
      section.classList.add('hidden');
      const input = document.getElementById('payment_upload');
      if (input) input.value = '';
      const preview = document.getElementById('payment_upload-preview');
      if (preview) {
        preview.src = '';
        preview.classList.remove('show');
      }
      clearFieldValidation('payment_upload');
    } else {
      section.classList.remove('hidden');
    }
  }

  /**
   * Validate a single field by id. Shows inline error/success.
   * Used by inline (blur/change) validation and by section validators.
   */
  function validateField(fieldId) {
    const el = document.getElementById(fieldId);
    if (!el) return true;
    const value = (el.value || '').trim();

    switch (fieldId) {
      case 'full_name':
        if (!value) { showError('full_name', 'Please enter your full name as it appears on your ID'); return false; }
        if (value.length < 3) { showError('full_name', 'Full name must be at least 3 characters'); return false; }
        showSuccess('full_name', '✓'); return true;

      case 'email':
        if (!value) { showError('email', 'Please enter your email address'); return false; }
        if (!isValidEmail(value)) { showError('email', 'Please enter a valid email address'); return false; }
        showSuccess('email', '✓'); return true;

      case 'mobile':
        if (!value) { showError('mobile', 'Please enter your mobile number'); return false; }
        if (!isValidPhone(value)) { showError('mobile', 'Please enter a valid Bangladeshi mobile number (e.g., 01712345678)'); return false; }
        showSuccess('mobile', '✓'); return true;

      case 'address':
        if (!value) { showError('address', 'Please enter your full address'); return false; }
        if (value.length < 10) { showError('address', 'Please enter a complete address (at least 10 characters)'); return false; }
        showSuccess('address', '✓'); return true;

      case 'dob': {
        if (!value) { showError('dob', 'Please enter your date of birth'); return false; }
        // Date picker returns YYYY-MM-DD, convert to YYYY/MM/DD for validation
        const dobFormatted = value.replace(/-/g, '/');
        const dobRegex = /^\d{4}\/\d{2}\/\d{2}$/;
        if (!dobRegex.test(dobFormatted)) { showError('dob', 'Please enter date in YYYY/MM/DD format'); return false; }
        const [year, month, day] = dobFormatted.split('/').map(Number);
        const dobDate = new Date(year, month - 1, day);
        const today = new Date();
        if (dobDate.getFullYear() !== year || dobDate.getMonth() !== month - 1 || dobDate.getDate() !== day) {
          showError('dob', 'Please enter a valid date'); return false;
        }
        if (dobDate > today) { showError('dob', 'Date of birth cannot be in the future'); return false; }
        showSuccess('dob', '✓'); return true;
      }

      case 'nationality':
        if (!value) { showError('nationality', 'Please enter your nationality'); return false; }
        showSuccess('nationality', '✓'); return true;

      case 'test_date':
        if (!value) { showError('test_date', 'Please select your intended test date'); return false; }
        showSuccess('test_date', '✓'); return true;

      default:
        return true;
    }
  }

  /**
   * Validate Section 1: Personal Information
   */
  function validateStep1() {
    const fields = ['full_name', 'email', 'mobile', 'address', 'dob', 'nationality'];
    let isValid = true;

    fields.forEach(fieldId => {
      if (!validateField(fieldId)) {
        isValid = false;
      }
    });

    if (isValid) {
      formData.step1 = {
        full_name: document.getElementById('full_name').value.trim(),
        email: document.getElementById('email').value.trim(),
        mobile: document.getElementById('mobile').value.trim(),
        address: document.getElementById('address').value.trim(),
        dob: document.getElementById('dob').value,
        nationality: document.getElementById('nationality').value.trim()
      };
    }

    return isValid;
  }

  /**
   * Validate Step 2: Exam Details
   */
  function validateStep2() {
    let isValid = true;

    // Validate exam levels selection (shared with inline checkbox validation)
    if (!validateLevelSelection()) {
      isValid = false;
    }

    // Intended Test Date
    const testDate = document.getElementById('test_date').value;
    if (!validateField('test_date')) {
      isValid = false;
    }

    // Store valid data
    if (isValid) {
      formData.step2 = {
        exam_levels: selectedLevels,
        test_date: testDate,
        total_amount: totalAmount
      };
    }

    return isValid;
  }

  /**
   * Validate Step 3: Payment Method
   */
  function validateStep3() {
    let isValid = true;

    // Payment Method Selection
    const paymentMethod = document.querySelector('input[name="payment_method"]:checked');
    if (!paymentMethod) {
      showError('payment_method', 'Please select a payment method');
      isValid = false;
    } else {
      showSuccess('payment_method', '✓');
      // Store valid data
      formData.step3 = {
        payment_method: paymentMethod.value
      };
    }

    return isValid;
  }

  /**
   * Handle file upload with validation
   */
  function handleFileUpload(fieldId, file, maxSize, allowedTypes) {
    const validation = validateFile(file, maxSize, allowedTypes);

    if (!validation.valid) {
      showError(fieldId, validation.error);
      return false;
    }

    // Show file info
    showSuccess(fieldId, `✓ ${file.name} (${formatFileSize(file.size)})`);

    // Show preview if it's an image
    if (file.type.startsWith('image/')) {
      const preview = document.getElementById(`${fieldId}-preview`);
      if (preview) {
        const reader = new FileReader();
        reader.onload = function(e) {
          // Set the source and show the preview
          preview.src = e.target.result;

          // Add error handling for image load
          preview.onerror = function() {
            console.error('Failed to load preview image for:', fieldId);
            preview.style.display = 'none';
          };

          // Show the preview when image loads successfully
          preview.onload = function() {
            preview.classList.add('show');
          };

          // Fallback: show preview after a short timeout even if onload doesn't fire
          setTimeout(function() {
            if (preview.src && !preview.classList.contains('show')) {
              preview.classList.add('show');
            }
          }, 100);
        };
        reader.onerror = function() {
          console.error('Failed to read file:', fieldId);
        };
        reader.readAsDataURL(file);
      }
    }

    return true;
  }

  /**
   * Validate Step 3: Document Uploads
   */
  function validateStep4() {
    let isValid = true;

    // Student Photo (required)
    const photoInput = document.getElementById('photo_upload');
    const photoFile = photoInput ? photoInput.files[0] : null;

    if (!photoFile) {
      showError('photo_upload', 'Please upload your photo (max 2MB, JPG or PNG)');
      isValid = false;
    } else {
      const photoValid = handleFileUpload(
        'photo_upload',
        photoFile,
        CONFIG.MAX_PHOTO_SIZE,
        CONFIG.ALLOWED_PHOTO_TYPES
      );
      if (!photoValid) {
        isValid = false;
      }
    }

    // Government ID (required)
    const idInput = document.getElementById('id_upload');
    const idFile = idInput ? idInput.files[0] : null;

    if (!idFile) {
      showError('id_upload', 'Please upload your government ID (max 4MB, JPG, PNG, or PDF)');
      isValid = false;
    } else {
      const idValid = handleFileUpload(
        'id_upload',
        idFile,
        CONFIG.MAX_ID_SIZE,
        CONFIG.ALLOWED_ID_TYPES
      );
      if (!idValid) {
        isValid = false;
      }
    }

    // Payment Receipt (optional)
    const paymentInput = document.getElementById('payment_upload');
    const paymentFile = paymentInput ? paymentInput.files[0] : null;

    if (paymentFile) {
      const paymentValid = handleFileUpload(
        'payment_upload',
        paymentFile,
        CONFIG.MAX_PAYMENT_SIZE,
        CONFIG.ALLOWED_PAYMENT_TYPES
      );
      if (!paymentValid) {
        isValid = false;
      }
    }

    // Store valid data
    if (isValid) {
      formData.step4 = {
        photo_file: photoFile,
        id_file: idFile,
        payment_receipt_file: paymentFile || null
      };
    }

    return isValid;
  }

  /**
   * Switch between registration type tabs
   */
  function switchTab(tabName) {
    // Hide all tab content
    document.querySelectorAll('.tab-content').forEach(tab => {
      tab.classList.remove('active');
    });

    // Remove active state from all tab buttons
    document.querySelectorAll('[data-tab]').forEach(btn => {
      btn.classList.remove('active', 'border-primary', 'text-primary');
      btn.classList.add('border-transparent', 'text-secondary');
    });

    // Show selected tab content
    const targetTab = document.getElementById(`tab-${tabName}`);
    if (targetTab) {
      targetTab.classList.add('active');
    }

    // Activate selected tab button
    const activeBtn = document.querySelector(`[data-tab="${tabName}"]`);
    if (activeBtn) {
      activeBtn.classList.remove('border-transparent', 'text-secondary');
      activeBtn.classList.add('active', 'border-primary', 'text-primary');
    }
  }

  /**
   * Toggle offline process accordion
   */
  function toggleOffline() {
    // First switch to the pbt-offline tab
    switchTab('pbt-offline');

    // Then open the accordion
    const accordion = document.getElementById('offline-accordion');
    if (accordion) {
      accordion.classList.add('open');
    }
  }

  /**
   * Validate all steps
   */
  function validateAllSteps() {
    const step1Valid = validateStep1();
    const step2Valid = validateStep2();
    const step3Valid = validateStep3();
    const step4Valid = validateStep4();

    return step1Valid && step2Valid && step3Valid && step4Valid;
  }

  /**
   * Validate the whole form and submit it to the intake service
   */
  function submitForm(event) {
    console.log('submitForm called');
    if (event) {
      event.preventDefault();
      event.stopPropagation();
    }
    console.log('Current formData:', formData);

    // Validate the whole form
    if (!validateAllSteps()) {
      console.log('Validation failed');
      scrollToFirstError();
      return false;
    }

    console.log('Validation passed');

    // Check payment method
    const paymentMethod = formData.step3.payment_method;

    if (paymentMethod === 'online') {
      // Show loading message for payment gateway redirect
      showLoading('Redirecting to payment gateway...');
    } else {
      // Show loading state for offline payment
      const submitBtn = document.querySelector('.submit-btn');
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Submitting...';
      }
    }

    // Prepare FormData for multipart/form-data upload
    const formDataToSend = new FormData();

    // Add step 1 data (Personal Information)
    formDataToSend.append('full_name', formData.step1.full_name);
    formDataToSend.append('email', formData.step1.email);
    formDataToSend.append('mobile', formData.step1.mobile);
    formDataToSend.append('address', formData.step1.address);
    // Convert date from YYYY-MM-DD to YYYY/MM/DD for backend
    const dobFormatted = formData.step1.dob.replace(/-/g, '/');
    formDataToSend.append('dob', dobFormatted);
    formDataToSend.append('nationality', formData.step1.nationality);

    // Add step 2 data (Exam Details)
    // Add selected levels as array
    if (selectedLevels && selectedLevels.length > 0) {
      selectedLevels.forEach(level => {
        formDataToSend.append('exam_levels[]', level);
      });
    }

    // Add total amount
    formDataToSend.append('total_amount', totalAmount);

    // Convert date from YYYY-MM-DD to YYYY/MM/DD for backend
    const testDateFormatted = formData.step2.test_date.replace(/-/g, '/');
    formDataToSend.append('test_date', testDateFormatted);

    // Add step 3 data (Payment Method)
    formDataToSend.append('payment_method', formData.step3.payment_method);

    // Add step 4 files (Document Uploads)
    formDataToSend.append('photo', formData.step4.photo_file);
    formDataToSend.append('id_document', formData.step4.id_file);
    if (formData.step4.payment_receipt_file) {
      formDataToSend.append('payment_receipt', formData.step4.payment_receipt_file);
    }

    // Add honeypot field (should be empty)
    // Using a space to bypass firewall rules; backend uses trim()
    formDataToSend.append('website', ' ');

    // Send to intake service (intake is a subdirectory under frontend)
    const intakeUrl = 'intake/register.php';

    // Log submission details for debugging
    console.log('Submitting to:', intakeUrl);
    console.log('Form data:', {
      step1: formData.step1,
      step2: formData.step2,
      step3: formData.step3,
      step4: formData.step4,
      hasPhoto: !!formData.step4.photo_file,
      hasId: !!formData.step4.id_file,
      hasReceipt: !!formData.step4.payment_receipt_file
    });

    fetch(intakeUrl, {
      method: 'POST',
      body: formDataToSend
    })
    .then(response => {
      console.log('Response status:', response.status);
      console.log('Response headers:', response.headers);

      // Check if response is ok
      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }

      return response.json();
    })
    .then(data => {
      console.log('✅ Response received:', data);

      // Hide loading state
      hideLoading();

      if (data.success) {
        // The PHP backend returns registration details at the root level of 'data'
        // or nested inside 'data.data' depending on the successResponse helper.
        const responseData = data.data || data;

        // Check if redirect URL is present (online payment)
        if (responseData.redirect_url) {
          // Show success message then redirect
          console.log('✅ Registration saved! Redirecting to payment gateway...');

          // Create temporary success message
          const successDiv = document.createElement('div');
          successDiv.className = 'fixed top-4 right-4 bg-green-500 text-white px-6 py-4 rounded-lg shadow-lg z-50';
          successDiv.textContent = 'Registration saved! Redirecting to payment gateway...';
          document.body.appendChild(successDiv);

          // Redirect after delay
          setTimeout(() => {
            window.location.href = responseData.redirect_url;
          }, 2000);
          return;
        }

        // Online payment chosen but gateway unavailable — registration was
        // saved; send the user to the retry page to complete payment
        if (responseData.payment_retry_url) {
          const warnDiv = document.createElement('div');
          warnDiv.className = 'fixed top-4 right-4 bg-amber-500 text-white px-6 py-4 rounded-lg shadow-lg z-50';
          warnDiv.textContent = 'Registration saved, but the payment gateway is unavailable. Taking you to the payment page...';
          document.body.appendChild(warnDiv);

          setTimeout(() => {
            window.location.href = responseData.payment_retry_url;
          }, 3000);
          return;
        }

        // Offline payment - show success message
        const submissionData = {
          ...formData.step1,
          ...formData.step2,
          ...formData.step3,
          id: responseData.id,
          submitted_at: new Date().toISOString()
        };

        // Redirect to success page with registration data
        const params = new URLSearchParams();

        // Use backend response data for fields it returns
        const fieldsToTransfer = ['id', 'email', 'exam_level', 'test_date'];
        fieldsToTransfer.forEach(field => {
          if (responseData[field]) params.set(field, responseData[field]);
        });

        // Use form data for fields backend doesn't return
        if (formData.step1.full_name) {
          params.set('full_name', formData.step1.full_name);
        }

        // Fallback for email if not in response
        if (!params.has('email') && formData.step1.email) {
          params.set('email', formData.step1.email);
        }

        if (formData.step3.payment_method) {
          params.set('payment_method', formData.step3.payment_method);
          console.log('✅ Setting payment_method from form data:', formData.step3.payment_method);
        } else {
          console.error('❌ payment_method not found in formData.step3!');
        }

        console.log('🔗 Final Redirect URL parameters:', params.toString());

        // Redirect to success page
        window.location.href = 'registration-success.html?' + params.toString();

        // Note: Form reset will happen after redirect completes
        return;
      } else {
        // Show error message with details
        console.error('Submission failed:', data);
        const errorMsg = data.error || 'Unknown error';
        const debugInfo = data.debug ? `\n\nDebug info: ${JSON.stringify(data.debug, null, 2)}` : '';
        alert('Submission failed: ' + errorMsg + debugInfo);
      }
    })
    .catch(error => {
      hideLoading();
      console.error('Submission error:', error);
      console.error('Error stack:', error.stack);
      alert('Submission failed: ' + error.message + '\n\nPlease check the browser console for more details.');

    })
    .finally(() => {
      console.log('Submission completed');
    });

    return false;
  }

  /**
   * Reset the form
   */
  function resetForm() {
    // Reset all form fields
    document.querySelectorAll('input, textarea, select').forEach(field => {
      if (field.type === 'radio' || field.type === 'checkbox') {
        field.checked = false;
      } else {
        field.value = '';
      }
      field.classList.remove('valid', 'invalid');
    });

    // Reset file previews
    document.querySelectorAll('.file-preview').forEach(preview => {
      preview.src = '';
      preview.classList.remove('show');
    });

    // Clear error/success messages
    document.querySelectorAll('.field-error, .field-success').forEach(msg => {
      msg.classList.remove('show');
    });

    formData = {
      step1: {},
      step2: {},
      step3: {},
      step4: {}
    };
    selectedLevels = [];
    totalAmount = 0;
    updateFeeDisplay();
  }

  /**
   * Load exam dates from database
   */
  function loadExamDates() {
    console.log('🔄 Loading exam dates from database...');

    fetch('/intake/get_exam_dates.php')
      .then(response => {
        if (!response.ok) {
          throw new Error('HTTP ' + response.status + ': ' + response.statusText);
        }
        return response.json();
      })
      .then(result => {
        if (result.success && result.data && result.data.exams.length > 0) {
          const exams = result.data.exams;

          // Store exam data for later use in populating levels
          examDatesData = exams;

          // Update "Next Test Date" section
          const nextExamDateEl = document.getElementById('next-exam-date');
          const nextExamStatusEl = document.getElementById('next-exam-status');

          if (nextExamDateEl && nextExamStatusEl) {
            const firstExam = exams[0];
            nextExamDateEl.textContent = firstExam.display;
            nextExamStatusEl.textContent = 'Registration deadline: ' + new Date(firstExam.deadline).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
          }

          // Populate test date dropdown
          const testDateSelect = document.getElementById('test_date');
          if (testDateSelect) {
            // Clear existing options and add default
            while (testDateSelect.firstChild) {
              testDateSelect.removeChild(testDateSelect.firstChild);
            }

            const defaultOption = document.createElement('option');
            defaultOption.value = '';
            defaultOption.textContent = 'Select Test Date';
            testDateSelect.appendChild(defaultOption);

            // Add options from database
            exams.forEach(exam => {
              const option = document.createElement('option');
              option.value = exam.value;
              option.textContent = exam.display;
              // Store exam ID as data attribute for level lookup
              option.setAttribute('data-exam-id', exam.id);
              testDateSelect.appendChild(option);
            });
          }

          console.log('✅ Loaded ' + exams.length + ' exam dates from database');
        } else {
          // No exams available
          const nextExamDateEl = document.getElementById('next-exam-date');
          const nextExamStatusEl = document.getElementById('next-exam-status');

          if (nextExamDateEl && nextExamStatusEl) {
            nextExamDateEl.textContent = 'Coming Soon';
            nextExamStatusEl.textContent = 'Test dates will be displayed here once available';
          }

          console.warn('⚠ No exam dates available from database');
        }
      })
      .catch(error => {
        console.error('❌ Error loading exam dates:', error);

        // Show error state
        const nextExamDateEl = document.getElementById('next-exam-date');
        const nextExamStatusEl = document.getElementById('next-exam-status');

        if (nextExamDateEl && nextExamStatusEl) {
          nextExamDateEl.textContent = 'Unable to load';
          nextExamStatusEl.textContent = 'Please refresh the page or contact support';
        }
      });
  }

  /**
   * Populate exam levels dropdown based on selected test date
   */
  function populateExamLevels(selectedExamDate) {
    const examLevelSelect = document.getElementById('exam_level');
    if (!examLevelSelect) return;

    // Clear existing options and add default
    while (examLevelSelect.firstChild) {
      examLevelSelect.removeChild(examLevelSelect.firstChild);
    }

    const defaultOption = document.createElement('option');
    defaultOption.value = '';
    defaultOption.textContent = 'Select Exam Level';
    examLevelSelect.appendChild(defaultOption);

    // Find the exam data for the selected date
    const selectedExam = examDatesData.find(exam => exam.value === selectedExamDate);

    if (selectedExam && selectedExam.levels && selectedExam.levels.length > 0) {
      // Add available levels from database
      const levelLabels = {
        '1Q': '1Q - Basic Level',
        '2Q': '2Q - Elementary Level',
        '3Q': '3Q - Intermediate Level',
        '4Q': '4Q - Upper Intermediate Level',
        '5Q': '5Q - Advanced Level'
      };

      selectedExam.levels.forEach(level => {
        const option = document.createElement('option');
        option.value = level;
        option.textContent = levelLabels[level] || level;
        examLevelSelect.appendChild(option);
      });

      // Enable the dropdown
      examLevelSelect.disabled = false;

      console.log('✅ Loaded ' + selectedExam.levels.length + ' levels for exam date: ' + selectedExamDate);
    } else {
      // No levels available or no exam selected
      examLevelSelect.disabled = true;
      console.warn('⚠ No levels available for selected exam date');
    }
  }

  /**
   * Populate exam levels checkboxes for multi-level selection
   */
  function populateExamLevelsCheckboxes(testDate) {
    let container = document.getElementById('exam_levels_checkboxes');
    if (!container) return;

    container.innerHTML = '';
    selectedLevels = [];
    totalAmount = 0;
    updateFeeDisplay();

    const loadingMsg = document.createElement('p');
    loadingMsg.className = 'text-secondary col-span-5';
    loadingMsg.textContent = 'Loading available levels...';
    container.appendChild(loadingMsg);

    // Use absolute path from domain root
    fetch(`/intake/api/exam-dates/levels.php?date=${encodeURIComponent(testDate)}`)
      .then(response => {
        if (!response.ok) {
          throw new Error('HTTP ' + response.status + ': ' + response.statusText);
        }
        return response.json();
      })
      .then(data => {
        if (data.levels && data.levels.length > 0) {
          container.innerHTML = '';
          data.levels.forEach(level => {
            const checkboxDiv = document.createElement('div');
            checkboxDiv.className = 'flex items-center gap-2 p-3 bg-white rounded-lg cursor-pointer hover:bg-gray-100 transition-all';

            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.id = 'level_' + level;
            checkbox.value = level;
            checkbox.className = 'w-5 h-5 text-primary accent-primary';
            checkbox.addEventListener('change', function() {
              handleLevelSelection(level, this.checked);
            });

            const label = document.createElement('label');
            label.htmlFor = 'level_' + level;
            label.className = 'flex-1 cursor-pointer font-semibold text-primary select-none';
            label.textContent = level;

            checkboxDiv.appendChild(checkbox);
            checkboxDiv.appendChild(label);
            container.appendChild(checkboxDiv);
          });
          const wrapper = document.getElementById('exam_levels_container');
          if (wrapper) {
            wrapper.classList.remove('opacity-50');
          }
        } else {
          container.innerHTML = '';
          const errorMsg = document.createElement('p');
          errorMsg.className = 'text-error col-span-5';
          errorMsg.textContent = 'No levels available for this date';
          container.appendChild(errorMsg);
        }
      })
      .catch(error => {
        console.error('Error loading levels:', error);
        container.innerHTML = '';
        const errorMsg = document.createElement('p');
        errorMsg.className = 'text-error col-span-5';
        errorMsg.textContent = 'Error loading levels. Please try again.';
        container.appendChild(errorMsg);
      });
  }

  /**
   * Handle checkbox selection for exam levels
   */
  function handleLevelSelection(level, isSelected) {
    if (isSelected) {
      if (!selectedLevels.includes(level)) {
        selectedLevels.push(level);
      }
    } else {
      selectedLevels = selectedLevels.filter(l => l !== level);
    }
    updateFeeDisplay();
    validateLevelSelection();
  }

  /**
   * Update fee displays (levels box and submit-area summary)
   */
  function updateFeeDisplay() {
    const count = selectedLevels.length;
    const total = count * CONFIG.FEE_PER_LEVEL;

    const feeSummary = document.getElementById('fee_summary');
    const feeCount = document.getElementById('fee_count');
    const feeTotal = document.getElementById('fee_total');
    const feeMultiplier = document.getElementById('fee_multiplier');

    if (count > 0) {
      feeSummary.classList.remove('hidden');
      feeCount.textContent = count;
      feeTotal.textContent = total.toLocaleString('en-BD') + ' BDT';
      feeMultiplier.textContent = count;
    } else {
      feeSummary.classList.add('hidden');
    }

    // Live summary above the Submit button
    const submitSummary = document.getElementById('submit_summary');
    const submitSummaryEmpty = document.getElementById('submit_summary_empty');
    if (submitSummary && submitSummaryEmpty) {
      if (count > 0) {
        document.getElementById('submit_summary_count').textContent = count;
        document.getElementById('submit_summary_total').textContent = total.toLocaleString('en-BD');
        submitSummary.classList.remove('hidden');
        submitSummaryEmpty.classList.add('hidden');
      } else {
        submitSummary.classList.add('hidden');
        submitSummaryEmpty.classList.remove('hidden');
      }
    }

    // Keep the Payment Method section's "Total Payment Due" box in sync
    updatePaymentDisplay(count);

    totalAmount = total;
  }

  /**
   * Validate level selection
   */
  function validateLevelSelection() {
    const errorEl = document.getElementById('exam_levels-error');
    const successEl = document.getElementById('exam_levels-success');

    if (!errorEl || !successEl) return false;

    if (selectedLevels.length < CONFIG.MIN_LEVELS) {
      errorEl.textContent = 'Please select at least ' + CONFIG.MIN_LEVELS + ' level(s)';
      errorEl.classList.add('show');
      successEl.classList.remove('show');
      return false;
    } else {
      errorEl.classList.remove('show');
      successEl.textContent = selectedLevels.length + ' level(s) selected';
      successEl.classList.add('show');
      return true;
    }
  }

  // Export public API
  return {
    CONFIG,
    calculatePaymentAmount,
    updatePaymentDisplay,
    showLoading,
    hideLoading,
    isValidEmail,
    isValidPhone,
    validateFile,
    formatFileSize,
    showError,
    showSuccess,
    clearFieldValidation,
    scrollToFirstError,
    validateField,
    initInlineValidation,
    validateStep1,
    validateStep2,
    handleFileUpload,
    validateStep3,
    switchTab,
    toggleOffline,
    submitForm,
    resetForm,
    validateAllSteps,
    loadExamDates,
    populateExamLevels,
    populateExamLevelsCheckboxes,
    handleLevelSelection,
    updateFeeDisplay,
    validateLevelSelection
  };
})();
