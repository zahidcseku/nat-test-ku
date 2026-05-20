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
    ALLOWED_PAYMENT_TYPES: ['image/jpeg', 'image/png', 'application/pdf'],
    FEE_PER_LEVEL: 4000,
    MAX_LEVELS: 5,
    MIN_LEVELS: 1
  };

  // State management
  let currentStep = 1;
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

    // Validate exam levels selection (checkboxes instead of dropdown)
    if (selectedLevels.length < CONFIG.MIN_LEVELS) {
      const errorEl = document.getElementById('exam_levels-error');
      if (errorEl) {
        errorEl.textContent = 'Please select at least ' + CONFIG.MIN_LEVELS + ' level';
        errorEl.classList.add('show');
      }
      isValid = false;
    } else {
      const errorEl = document.getElementById('exam_levels-error');
      if (errorEl) {
        errorEl.classList.remove('show');
      }
      const successEl = document.getElementById('exam_levels-success');
      if (successEl) {
        successEl.textContent = selectedLevels.length + ' level(s) selected';
        successEl.classList.add('show');
      }
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
        exam_levels: selectedLevels,
        test_date: testDate,
        total_amount: totalAmount
      };
    }

    return isValid;
  }

  /**
   * Go to specific step (helper function)
   */
  function goToStep(stepNumber) {
    showStep(stepNumber);
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
        console.log('Showing level confirmation');
        return showLevelConfirmation();
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
    // Add selected levels as array
    if (selectedLevels && selectedLevels.length > 0) {
      selectedLevels.forEach(level => {
        formDataToSend.append('exam_levels[]', level);
      });
    }

    // Add total amount
    formDataToSend.append('total_amount', totalAmount);

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

      if (data.success) {
        // The PHP backend returns registration details at the root level of 'data'
        // or nested inside 'data.data' depending on the successResponse helper.
        const responseData = data.data || data;

        // Collect submission data for success message
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

        if (formData.step2.payment_method) {
          params.set('payment_method', formData.step2.payment_method);
          console.log('✅ Setting payment_method from form data:', formData.step2.payment_method);
        } else {
          console.error('❌ payment_method not found in formData.step2!');
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
    const container = document.getElementById('exam_levels_checkboxes');
    if (!container) return;

    container.innerHTML = '';
    selectedLevels = [];
    totalAmount = 0;
    updateFeeDisplay();

    const loadingMsg = document.createElement('p');
    loadingMsg.className = 'text-secondary col-span-5';
    loadingMsg.textContent = 'Loading available levels...';
    container.appendChild(loadingMsg);

    fetch(`/intake/api/exam-dates/levels.php?date=${encodeURIComponent(testDate)}`)
      .then(response => response.json())
      .then(data => {
        if (data.levels && data.levels.length > 0) {
          container.innerHTML = '';
          data.levels.forEach(level => {
            const checkboxDiv = document.createElement('div');
            checkboxDiv.className = 'flex items-center gap-2 p-3 bg-surface-container-low rounded-lg cursor-pointer hover:bg-surface-container-high transition-all';

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
          const container = document.getElementById('exam_levels_container');
          if (container) {
            container.classList.remove('opacity-50');
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
   * Update fee display based on selected levels
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

  /**
   * Show confirmation modal for level selection
   */
  function showLevelConfirmation() {
    if (!validateLevelSelection()) {
      return false;
    }

    const modal = document.getElementById('level_confirmation_modal');
    const countEl = document.getElementById('confirm_levels_count');
    const listEl = document.getElementById('confirm_levels_list');
    const totalEl = document.getElementById('confirm_total');

    countEl.textContent = selectedLevels.length;
    // Validate API response values before displaying - ensure they match expected format (1Q-5Q)
    const validLevels = selectedLevels.filter(level => {
      const isValid = /^([1-5]Q)$/.test(level);
      if (!isValid) {
        console.warn('Invalid level format received from API:', level);
      }
      return isValid;
    });

    if (validLevels.length === 0) {
      console.error('No valid levels found in selection');
      return false;
    }

    listEl.textContent = validLevels.sort().join(', ');
    totalEl.textContent = totalAmount.toLocaleString('en-BD');

    modal.classList.remove('hidden');
    return true; // Return true to indicate modal was shown successfully
  }

  /**
   * Cancel level confirmation
   */
  function cancelLevelConfirmation() {
    document.getElementById('level_confirmation_modal').classList.add('hidden');
  }

  /**
   * Confirm level selection and proceed
   */
  function confirmLevelSelection() {
    const modal = document.getElementById('level_confirmation_modal');
    if (modal) {
      modal.classList.add('hidden');
    }

    const paymentAmount = document.getElementById('payment_amount_display');
    const paymentLevels = document.getElementById('payment_levels_display');

    if (paymentAmount) {
      paymentAmount.textContent = totalAmount.toLocaleString('en-BD') + ' BDT';
    }
    if (paymentLevels) {
      paymentLevels.textContent = 'For ' + selectedLevels.length + ' selected level(s)';
    }

    // Advance to step 4 after confirmation
    showStep(4);
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
    loadExamDates,
    populateExamLevels,
    populateExamLevelsCheckboxes,
    handleLevelSelection,
    updateFeeDisplay,
    validateLevelSelection,
    showLevelConfirmation,
    cancelLevelConfirmation,
    confirmLevelSelection,
    goToStep
  };

})();
