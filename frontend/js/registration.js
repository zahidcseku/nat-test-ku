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

  // Export public API
  return {
    CONFIG,
    isValidEmail,
    isValidPhone,
    validateFile,
    formatFileSize,
    showError,
    showSuccess,
    clearFieldValidation
  };

})();
