/**
 * Registration Form Module
 * Handles multi-step form, validation, file uploads, and submission
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
    ALLOWED_PAYMENT_TYPES: ['image/jpeg', 'image/png', 'application/pdf']
  };

  // State management
  let currentStep = 1;
  let formData = {
    step1: {},
    step2: {},
    step3: {},
    step4: {}
  };

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
   * Validate Step 1: Personal Information & Payment Method
   */
  function validateStep1() {
    let isValid = true;

    // Full Name
    const fullName = document.getElementById('full_name').value.trim();
    if (!fullName) {
      showError('full_name', 'Please enter your full name as it appears on your ID');
      isValid = false;
    } else if (fullName.length < 3) {
      showError('full_name', 'Full name must be at least 3 characters');
      isValid = false;
    } else {
      showSuccess('full_name', '✓');
    }

    // Email
    const email = document.getElementById('email').value.trim();
    if (!email) {
      showError('email', 'Please enter your email address');
      isValid = false;
    } else if (!isValidEmail(email)) {
      showError('email', 'Please enter a valid email address');
      isValid = false;
    } else {
      showSuccess('email', '✓');
    }

    // Mobile Number
    const mobile = document.getElementById('mobile').value.trim();
    if (!mobile) {
      showError('mobile', 'Please enter your mobile number');
      isValid = false;
    } else if (!isValidPhone(mobile)) {
      showError('mobile', 'Please enter a valid Bangladeshi mobile number (e.g., 01712345678)');
      isValid = false;
    } else {
      showSuccess('mobile', '✓');
    }

    // Address
    const address = document.getElementById('address').value.trim();
    if (!address) {
      showError('address', 'Please enter your full address');
      isValid = false;
    } else if (address.length < 10) {
      showError('address', 'Please enter a complete address (at least 10 characters)');
      isValid = false;
    } else {
      showSuccess('address', '✓');
    }

    // Date of Birth
    const dob = document.getElementById('dob').value;
    if (!dob) {
      showError('dob', 'Please enter your date of birth');
      isValid = false;
    } else {
      // Date picker returns YYYY-MM-DD, convert to YYYY/MM/DD for validation
      const dobFormatted = dob.replace(/-/g, '/');
      const dobRegex = /^\d{4}\/\d{2}\/\d{2}$/;

      if (!dobRegex.test(dobFormatted)) {
        showError('dob', 'Please enter date in YYYY/MM/DD format');
        isValid = false;
      } else {
        // Parse and validate the date
        const [year, month, day] = dobFormatted.split('/').map(Number);
        const dobDate = new Date(year, month - 1, day);
        const today = new Date();

        // Check if date is valid
        if (dobDate.getFullYear() !== year ||
            dobDate.getMonth() !== month - 1 ||
            dobDate.getDate() !== day) {
          showError('dob', 'Please enter a valid date');
          isValid = false;
        } else if (dobDate > today) {
          showError('dob', 'Date of birth cannot be in the future');
          isValid = false;
        } else {
          showSuccess('dob', '✓');
        }
      }
    }

    // Gender
    const gender = document.getElementById('gender').value;
    if (!gender) {
      showError('gender', 'Please select your gender');
      isValid = false;
    } else {
      showSuccess('gender', '✓');
    }

    // Nationality
    const nationality = document.getElementById('nationality').value.trim();
    if (!nationality) {
      showError('nationality', 'Please enter your nationality');
      isValid = false;
    } else {
      showSuccess('nationality', '✓');
    }

    // Store valid data
    if (isValid) {
      formData.step1 = {
        full_name: fullName,
        email: email,
        mobile: mobile,
        address: address,
        dob: dob,
        gender: gender,
        nationality: nationality
      };
    }

    return isValid;
  }

  /**
   * Validate Step 2: Exam Details
   */
  function validateStep2() {
    let isValid = true;

    // Payment Method Selection
    const paymentMethod = document.querySelector('input[name="payment_method"]:checked');
    if (!paymentMethod) {
      showError('payment_method', 'Please select a payment method');
      isValid = false;
    } else {
      showSuccess('payment_method', '✓');
      // Store valid data
      formData.step2 = {
        payment_method: paymentMethod.value
      };
    }

    return isValid;
  }

  /**
   * Validate Step 3: Exam Details
   */
  function validateStep3() {
    let isValid = true;

    // Exam Level
    const examLevel = document.getElementById('exam_level').value;
    if (!examLevel) {
      showError('exam_level', 'Please select your exam level');
      isValid = false;
    } else {
      showSuccess('exam_level', '✓');
    }

    // Intended Test Date (PLACEHOLDER: will load from database)
    const testDate = document.getElementById('test_date').value;
    if (!testDate) {
      showError('test_date', 'Please select your intended test date');
      isValid = false;
    } else {
      showSuccess('test_date', '✓');
    }

    // Store valid data
    if (isValid) {
      formData.step3 = {
        exam_level: examLevel,
        test_date: testDate
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
   * Show a specific form step
   */
  function showStep(stepNumber) {
    // Hide all steps
    document.querySelectorAll('.form-step').forEach(step => {
      step.classList.remove('active');
    });

    // Show target step
    const targetStep = document.getElementById(`step-${stepNumber}`);
    if (targetStep) {
      targetStep.classList.add('active');
    }

    // Update progress tracker
    updateProgressTracker(stepNumber);

    // Update current step
    currentStep = stepNumber;
  }

  /**
   * Update progress tracker visual state
   */
  function updateProgressTracker(activeStep) {
    for (let i = 1; i <= 4; i++) {
      const stepEl = document.querySelector(`[data-progress-step="${i}"]`);
      if (!stepEl) continue;

      stepEl.classList.remove('active', 'completed');

      if (i < activeStep) {
        stepEl.classList.add('completed');
      } else if (i === activeStep) {
        stepEl.classList.add('active');
      }
    }
  }

  /**
   * Go to next step
   */
  function nextStep() {
    console.log('nextStep called, currentStep:', currentStep);

    if (currentStep === 1) {
      console.log('Validating step 1...');
      const step1Valid = validateStep1();
      console.log('Step 1 validation result:', step1Valid);
      if (step1Valid) {
        console.log('Moving to step 2');
        showStep(2);
      } else {
        console.log('Step 1 validation failed');
      }
    } else if (currentStep === 2) {
      console.log('Validating step 2...');
      const step2Valid = validateStep2();
      console.log('Step 2 validation result:', step2Valid);
      if (step2Valid) {
        console.log('Moving to step 3');
        showStep(3);
      } else {
        console.log('Step 2 validation failed');
      }
    } else if (currentStep === 3) {
      console.log('Validating step 3...');
      const step3Valid = validateStep3();
      console.log('Step 3 validation result:', step3Valid);
      if (step3Valid) {
        console.log('Moving to step 4');
        showStep(4);
      } else {
        console.log('Step 3 validation failed');
      }
    }
  }

  /**
   * Go to previous step
   */
  function previousStep() {
    if (currentStep > 1) {
      showStep(currentStep - 1);
    }
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
   * Submit the form (mock submission)
   */
  function submitForm(event) {
    console.log('submitForm called');
    if (event) {
      event.preventDefault();
      event.stopPropagation();
    }
    console.log('Current formData:', formData);

    // Validate all steps
    if (!validateAllSteps()) {
      console.log('Validation failed');
      alert('Please correct the errors before submitting');
      return false;
    }

    console.log('Validation passed');

    // Show loading state
    const submitBtn = document.querySelector('.submit-btn');
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = 'Submitting...';
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
    formDataToSend.append('gender', formData.step1.gender);
    formDataToSend.append('nationality', formData.step1.nationality);

    // Add step 2 data (Payment Method)
    formDataToSend.append('payment_method', formData.step2.payment_method);

    // Add step 3 data (Exam Details)
    formDataToSend.append('exam_level', formData.step3.exam_level);
    // Convert date from YYYY-MM-DD to YYYY/MM/DD for backend
    const testDateFormatted = formData.step3.test_date.replace(/-/g, '/');
    formDataToSend.append('test_date', testDateFormatted);

    // Add step 4 files (Document Uploads)
    formDataToSend.append('photo', formData.step4.photo_file);
    formDataToSend.append('id_document', formData.step4.id_file);
    if (formData.step4.payment_receipt_file) {
      formDataToSend.append('payment_receipt', formData.step4.payment_receipt_file);
    }

    // Add honeypot field (should be empty)
    // Use single space to avoid ModSecurity empty string validation issues
    formDataToSend.append('website', ' ');

    // Send to intake service (intake is a subdirectory under frontend)
    const intakeUrl = 'intake/register.php';

    // Log submission details for debugging
    console.log('Submitting to:', intakeUrl);
    console.log('Form data:', {
      step1: formData.step1,
      step2: formData.step2,
      step3: formData.step3,
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
      console.log('Response data:', data);

      if (data.success) {
        // Verify response contains expected data before showing success
        if (!data.data || !data.data.id) {
          console.error('Invalid response: Missing registration ID', data);
          alert('Registration completed but could not verify submission. Please contact support to confirm your registration.');
          return;
        }

        // Verify the response contains expected fields
        const responseData = data.data;
        if (!responseData.id || !responseData.email || !responseData.exam_level) {
          console.error('Invalid response: Missing required fields', data);
          alert('Registration data incomplete. Please contact support to verify your submission.');
          return;
        }

        // Collect submission data for success message
        const submissionData = {
          ...formData.step1,
          ...formData.step2,
          ...formData.step3,
          id: responseData.id,
          submitted_at: new Date().toISOString()
        };

        console.log('✅ Registration verified with ID:', responseData.id);

        // Show success message
        showSuccessMessage(submissionData);

        // Reset form after delay
        setTimeout(() => {
          resetForm();
        }, 5000);
      } else {
        // Show error message with details
        console.error('Submission failed:', data);
        const errorMsg = data.error || 'Unknown error';
        const debugInfo = data.debug ? `\n\nDebug info: ${JSON.stringify(data.debug, null, 2)}` : '';
        alert('Submission failed: ' + errorMsg + debugInfo);
      }
    })
    .catch(error => {
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
   * Show success message modal
   */
  function showSuccessMessage(data) {
    const modal = document.getElementById('success-modal');
    if (!modal) return;

    // Populate success message - SANITIZE ALL USER INPUT WITH DOMPURIFY
    const modalContent = modal.querySelector('.modal-content');
    if (modalContent) {
      const paymentMethodText = data.payment_method === 'online' ? 'Online' : 'Offline';

      modalContent.innerHTML = `
        <div class="text-center">
          <div class="text-6xl mb-4">✅</div>
          <h2 class="text-2xl font-bold text-primary mb-4">Registration Received Successfully!</h2>
          <p class="text-lg text-secondary mb-6">Thank you, <strong>${DOMPurify.sanitize(data.full_name)}</strong>!</p>

          <div class="bg-surface-container-low rounded-lg p-6 text-left mb-6">
            <h3 class="font-bold text-primary mb-3">Your application has been submitted:</h3>
            <ul class="space-y-2 text-sm">
              <li><strong>Exam Level:</strong> ${DOMPurify.sanitize(data.exam_level)}</li>
              <li><strong>Test Date:</strong> ${DOMPurify.sanitize(data.test_date)}</li>
              <li><strong>Payment Method:</strong> ${DOMPurify.sanitize(paymentMethodText)}</li>
              <li><strong>Email:</strong> ${DOMPurify.sanitize(data.email)}</li>
            </ul>
          </div>

          <div class="bg-primary-container text-primary-dark rounded-lg p-6 text-left mb-6">
            <h3 class="font-bold mb-3">What happens next:</h3>
            <ol class="space-y-2 text-sm list-decimal list-inside">
              <li>You'll receive a confirmation email at <strong>${DOMPurify.sanitize(data.email)}</strong> within 24 hours</li>
              <li>We'll review your application</li>
              <li>You'll receive an approval email or request for corrections</li>
              <li>Your admission ticket will be sent via email</li>
            </ol>
          </div>

          <p class="text-sm text-secondary mb-6">
            Questions? Contact us at: <a href="mailto:info@nat-test.ku.ac.bd" class="text-primary underline">info@nat-test.ku.ac.bd</a>
          </p>

          <div class="flex gap-4 justify-center">
            <button onclick="window.location.href='index.html'" class="bg-surface-container-high text-primary px-6 py-3 rounded-lg font-semibold hover:bg-surface-container-highest transition-all">
              Return to Homepage
            </button>
            <button onclick="RegistrationForm.resetForm(); document.getElementById('success-modal').classList.remove('show');" class="bg-primary text-white px-6 py-3 rounded-lg font-semibold hover:opacity-90 transition-all">
              Register Another Candidate
            </button>
          </div>
        </div>
      `;
    }

    // Show modal
    modal.classList.add('show');
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

    // Reset state
    currentStep = 1;
    formData = {
      step1: {},
      step2: {},
      step3: {},
      step4: {}
    };

    // Show first step
    showStep(1);

    // Hide success modal
    const modal = document.getElementById('success-modal');
    if (modal) {
      modal.classList.remove('show');
    }
  }

  // Export public API
  return {
    CONFIG,
    isValidEmail,
    isValidPhone,
    validateFile,
    formatFileSize,
    showError,
    showSuccess,
    clearFieldValidation,
    validateStep1,
    validateStep2,
    handleFileUpload,
    validateStep3,
    showStep,
    updateProgressTracker,
    nextStep,
    previousStep,
    switchTab,
    toggleOffline,
    submitForm,
    resetForm,
    validateAllSteps,
    showSuccessMessage
  };

})();
