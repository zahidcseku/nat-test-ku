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
    step3: {}
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
      const dobDate = new Date(dob);
      const today = new Date();
      if (dobDate > today) {
        showError('dob', 'Date of birth cannot be in the future');
        isValid = false;
      } else {
        showSuccess('dob', '✓');
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

    // National ID / Passport Number
    const idNumber = document.getElementById('id_number').value.trim();
    if (!idNumber) {
      showError('id_number', 'Please enter your National ID or Passport number');
      isValid = false;
    } else if (idNumber.length < 5) {
      showError('id_number', 'ID number must be at least 5 characters');
      isValid = false;
    } else {
      showSuccess('id_number', '✓');
    }

    // Payment Method
    const paymentMethod = document.querySelector('input[name="payment_method"]:checked');
    if (!paymentMethod) {
      showError('payment_method', 'Please select a payment method');
      isValid = false;
    } else {
      showSuccess('payment_method', '✓');
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
        nationality: nationality,
        id_number: idNumber,
        payment_method: paymentMethod ? paymentMethod.value : null
      };
    }

    return isValid;
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
    validateStep1
  };

})();
