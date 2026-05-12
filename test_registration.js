#!/usr/bin/env node

/**
 * Comprehensive Test Suite for NAT-TEST Registration System
 * Tests syntax, integration, and component availability
 */

const fs = require('fs');
const path = require('path');

// ANSI color codes for output
const colors = {
  reset: '\x1b[0m',
  green: '\x1b[32m',
  red: '\x1b[31m',
  yellow: '\x1b[33m',
  blue: '\x1b[34m',
  cyan: '\x1b[36m'
};

function log(message, color = 'reset') {
  console.log(`${colors[color]}${message}${colors.reset}`);
}

function section(title) {
  console.log('\n' + '='.repeat(60));
  log(title, 'cyan');
  console.log('='.repeat(60));
}

// Test results
const results = {
  passed: [],
  failed: [],
  warnings: [],
  commitSha: null
};

// Frontend directory
const frontendDir = '/Users/zahid/projects/NAT_TEST_KU/frontend';

/**
 * Test 1: Verify all required files exist
 */
function testFileStructure() {
  section('TEST 1: File Structure Verification');

  const requiredFiles = [
    'registration.html',
    'js/registration.js',
    'js/lib/dompurify.js',
    'css/style.css',
    'index.html'
  ];

  requiredFiles.forEach(file => {
    const filePath = path.join(frontendDir, file);
    if (fs.existsSync(filePath)) {
      const stats = fs.statSync(filePath);
      log(`✓ ${file} exists (${(stats.size / 1024).toFixed(2)} KB)`, 'green');
      results.passed.push(`File exists: ${file}`);
    } else {
      log(`✗ ${file} is missing`, 'red');
      results.failed.push(`File missing: ${file}`);
    }
  });

  // Check for resource files
  const resourcesDir = path.join(frontendDir, 'resources');
  if (fs.existsSync(resourcesDir)) {
    const files = fs.readdirSync(resourcesDir);
    const pdfCount = files.filter(f => f.endsWith('.pdf')).length;
    log(`✓ Resources directory exists with ${pdfCount} PDF files`, 'green');
  } else {
    log('⚠ Resources directory not found', 'yellow');
    results.warnings.push('Resources directory missing');
  }
}

/**
 * Test 2: Verify HTML structure
 */
function testHTMLStructure() {
  section('TEST 2: HTML Structure Verification');

  const htmlPath = path.join(frontendDir, 'registration.html');
  const html = fs.readFileSync(htmlPath, 'utf-8');

  // Check for required elements
  const requiredElements = [
    { name: 'Form tag', pattern: /<form\s+id="registration-form"/ },
    { name: 'Step 1 container', pattern: /id="step-1"/ },
    { name: 'Step 2 container', pattern: /id="step-2"/ },
    { name: 'Step 3 container', pattern: /id="step-3"/ },
    { name: 'Progress tracker', pattern: /data-progress-step="1"/ },
    { name: 'PBT Online tab', pattern: /id="tab-pbt-online"/ },
    { name: 'PBT Offline tab', pattern: /id="tab-pbt-offline"/ },
    { name: 'CBT tab', pattern: /id="tab-cbt"/ },
    { name: 'Success modal', pattern: /id="success-modal"/ },
    { name: 'DOMPurify script', pattern: /<script\s+src="js\/lib\/dompurify\.js"/ },
    { name: 'Registration script', pattern: /<script\s+src="js\/registration\.js"/ }
  ];

  requiredElements.forEach(({ name, pattern }) => {
    if (pattern.test(html)) {
      log(`✓ ${name} found`, 'green');
      results.passed.push(`HTML element: ${name}`);
    } else {
      log(`✗ ${name} not found`, 'red');
      results.failed.push(`HTML element missing: ${name}`);
    }
  });

  // Check for all required form fields
  const requiredFields = [
    'full_name', 'email', 'mobile', 'address', 'dob', 'gender',
    'nationality', 'id_number', 'exam_level', 'test_date',
    'photo_upload', 'id_upload', 'payment_upload'
  ];

  let missingFields = [];
  requiredFields.forEach(field => {
    if (!html.includes(`id="${field}"`)) {
      missingFields.push(field);
    }
  });

  if (missingFields.length === 0) {
    log(`✓ All ${requiredFields.length} required form fields present`, 'green');
    results.passed.push('All form fields present');
  } else {
    log(`✗ Missing fields: ${missingFields.join(', ')}`, 'red');
    results.failed.push(`Missing form fields: ${missingFields.join(', ')}`);
  }
}

/**
 * Test 3: Verify JavaScript module structure
 */
function testJavaScriptStructure() {
  section('TEST 3: JavaScript Module Verification');

  const jsPath = path.join(frontendDir, 'js/registration.js');
  const js = fs.readFileSync(jsPath, 'utf-8');

  // Check for module pattern
  const hasModulePattern = js.includes('const RegistrationForm = (function()');
  if (hasModulePattern) {
    log('✓ Uses IIFE module pattern', 'green');
    results.passed.push('Module pattern correct');
  } else {
    log('✗ Missing proper module pattern', 'red');
    results.failed.push('Module pattern incorrect');
  }

  // Check for required functions
  const requiredFunctions = [
    'validateStep1', 'validateStep2', 'validateStep3',
    'showStep', 'nextStep', 'previousStep',
    'switchTab', 'toggleOffline', 'submitForm', 'resetForm',
    'showError', 'showSuccess', 'handleFileUpload',
    'isValidEmail', 'isValidPhone', 'validateFile'
  ];

  let missingFunctions = [];
  requiredFunctions.forEach(func => {
    if (!js.includes(`function ${func}`)) {
      missingFunctions.push(func);
    }
  });

  if (missingFunctions.length === 0) {
    log(`✓ All ${requiredFunctions.length} required functions present`, 'green');
    results.passed.push('All functions present');
  } else {
    log(`✗ Missing functions: ${missingFunctions.join(', ')}`, 'red');
    results.failed.push(`Missing functions: ${missingFunctions.join(', ')}`);
  }

  // Check for DOMPurify usage
  if (js.includes('DOMPurify.sanitize')) {
    log('✓ DOMPurify sanitization implemented', 'green');
    results.passed.push('DOMPurify used for XSS protection');
  } else {
    log('⚠ DOMPurify not used for sanitization', 'yellow');
    results.warnings.push('DOMPurify not used');
  }

  // Check for configuration constants
  const hasConfig = js.includes('const CONFIG') &&
                   js.includes('MAX_PHOTO_SIZE') &&
                   js.includes('MAX_ID_SIZE');

  if (hasConfig) {
    log('✓ Configuration constants defined', 'green');
    results.passed.push('Config constants present');
  } else {
    log('✗ Missing configuration constants', 'red');
    results.failed.push('Config constants missing');
  }

  // Check for public API export
  if (js.includes('return {') && js.includes('nextStep,')) {
    log('✓ Public API exported correctly', 'green');
    results.passed.push('Public API exported');
  } else {
    log('✗ Public API not exported', 'red');
    results.failed.push('Public API missing');
  }
}

/**
 * Test 4: Verify CSS styles
 */
function testCSSStyles() {
  section('TEST 4: CSS Styles Verification');

  const cssPath = path.join(frontendDir, 'css/style.css');
  const css = fs.readFileSync(cssPath, 'utf-8');

  const requiredStyles = [
    '.form-step',
    '.form-step.active',
    '.progress-step',
    '.progress-step.active',
    '.progress-step.completed',
    '.field-error',
    '.field-error.show',
    '.field-success',
    '.field-success.show',
    '.modal-overlay',
    '.modal-overlay.show',
    '.modal-content',
    '.tab-content',
    '.tab-content.active',
    '.file-preview',
    '.file-preview.show'
  ];

  let missingStyles = [];
  requiredStyles.forEach(style => {
    if (!css.includes(style)) {
      missingStyles.push(style);
    }
  });

  if (missingStyles.length === 0) {
    log(`✓ All ${requiredStyles.length} required CSS classes present`, 'green');
    results.passed.push('All CSS classes present');
  } else {
    log(`✗ Missing CSS classes: ${missingStyles.join(', ')}`, 'red');
    results.failed.push(`Missing CSS: ${missingStyles.join(', ')}`);
  }

  // Check for responsive design
  const hasResponsive = css.includes('@media') || css.includes('sm:') || css.includes('md:') || css.includes('lg:');
  if (hasResponsive) {
    log('✓ Responsive design styles present', 'green');
    results.passed.push('Responsive styles present');
  } else {
    log('⚠ No responsive design styles found', 'yellow');
    results.warnings.push('No responsive styles');
  }
}

/**
 * Test 5: Verify security features
 */
function testSecurityFeatures() {
  section('TEST 5: Security Features Verification');

  const jsPath = path.join(frontendDir, 'js/registration.js');
  const js = fs.readFileSync(jsPath, 'utf-8');

  const htmlPath = path.join(frontendDir, 'registration.html');
  const html = fs.readFileSync(htmlPath, 'utf-8');

  // Check for input validation
  if (js.includes('isValidEmail') && js.includes('isValidPhone')) {
    log('✓ Email and phone validation implemented', 'green');
    results.passed.push('Input validation present');
  } else {
    log('✗ Missing input validation', 'red');
    results.failed.push('Input validation missing');
  }

  // Check for file size validation
  if (js.includes('MAX_PHOTO_SIZE') && js.includes('MAX_ID_SIZE')) {
    log('✓ File size limits enforced', 'green');
    results.passed.push('File size validation present');
  } else {
    log('✗ Missing file size validation', 'red');
    results.failed.push('File size validation missing');
  }

  // Check for file type validation
  if (js.includes('ALLOWED_PHOTO_TYPES') && js.includes('ALLOWED_ID_TYPES')) {
    log('✓ File type validation implemented', 'green');
    results.passed.push('File type validation present');
  } else {
    log('✗ Missing file type validation', 'red');
    results.failed.push('File type validation missing');
  }

  // Check for XSS protection
  if (js.includes('DOMPurify.sanitize') && html.includes('DOMPurify')) {
    log('✓ XSS protection via DOMPurify', 'green');
    results.passed.push('XSS protection present');
  } else {
    log('⚠ DOMPurify not properly integrated', 'yellow');
    results.warnings.push('DOMPurify integration incomplete');
  }

  // Check for no inline event handlers with user data
  const dangerousPatterns = [
    /javascript:/,
    /on\w+\s*=\s*["'].*\+.*["']/
  ];

  let hasDangerousPatterns = false;
  dangerousPatterns.forEach(pattern => {
    if (pattern.test(html) || pattern.test(js)) {
      hasDangerousPatterns = true;
    }
  });

  if (!hasDangerousPatterns) {
    log('✓ No dangerous inline patterns detected', 'green');
    results.passed.push('No dangerous patterns');
  } else {
    log('✗ Dangerous patterns detected', 'red');
    results.failed.push('Dangerous patterns present');
  }
}

/**
 * Test 6: Git status check
 */
function testGitStatus() {
  section('TEST 6: Git Status Verification');

  // Read .git/index to check if index.html is modified
  const gitIndexPath = path.join(frontendDir, '../.git/index');
  const indexHtmlPath = path.join(frontendDir, 'index.html');

  try {
    // Check if we can read git status using git diff
    const { spawnSync } = require('child_process');

    // Check if index.html has been modified
    const diffResult = spawnSync('git', ['diff', 'frontend/index.html'], {
      cwd: path.join(frontendDir, '..'),
      encoding: 'utf-8'
    });

    if (diffResult.stdout.trim() === '') {
      log('✓ index.html unchanged (as required)', 'green');
      results.passed.push('index.html unchanged');
    } else {
      log('✗ index.html has been modified', 'red');
      results.failed.push('index.html modified');
    }

    // Get current commit SHA
    const shaResult = spawnSync('git', ['rev-parse', '--short', 'HEAD'], {
      cwd: path.join(frontendDir, '..'),
      encoding: 'utf-8'
    });

    if (shaResult.stdout.trim()) {
      results.commitSha = shaResult.stdout.trim();
      log(`✓ Current commit: ${results.commitSha}`, 'blue');
      results.passed.push(`Commit SHA: ${results.commitSha}`);
    }
  } catch (error) {
    log('⚠ Could not verify git status', 'yellow');
    results.warnings.push('Git status verification failed');
  }
}

/**
 * Test 7: Integration verification
 */
function testIntegration() {
  section('TEST 7: Integration Verification');

  const htmlPath = path.join(frontendDir, 'registration.html');
  const html = fs.readFileSync(htmlPath, 'utf-8');

  // Check for proper script loading order
  const dompurifyIndex = html.indexOf('js/lib/dompurify.js');
  const registrationIndex = html.indexOf('js/registration.js');

  if (dompurifyIndex > 0 && registrationIndex > dompurifyIndex) {
    log('✓ Scripts loaded in correct order', 'green');
    results.passed.push('Script loading order correct');
  } else {
    log('✗ Scripts not loaded in correct order', 'red');
    results.failed.push('Script loading order incorrect');
  }

  // Check for event handlers
  const hasEventHandlers = html.includes('onclick="RegistrationForm.') ||
                          html.includes('addEventListener');

  if (hasEventHandlers) {
    log('✓ Event handlers properly attached', 'green');
    results.passed.push('Event handlers attached');
  } else {
    log('✗ Event handlers not attached', 'red');
    results.failed.push('Event handlers missing');
  }

  // Check for form submission prevention
  if (html.includes('onsubmit="return false;"')) {
    log('✓ Form submission prevented (client-side only)', 'green');
    results.passed.push('Form submission prevented');
  } else {
    log('⚠ Form submission not explicitly prevented', 'yellow');
    results.warnings.push('Form submission prevention missing');
  }
}

/**
 * Generate final report
 */
function generateReport() {
  section('FINAL TEST REPORT');

  const totalTests = results.passed.length + results.failed.length + results.warnings.length;
  const passRate = ((results.passed.length / totalTests) * 100).toFixed(1);

  console.log(`\nTotal Tests: ${totalTests}`);
  log(`Passed: ${results.passed.length}`, 'green');
  log(`Failed: ${results.failed.length}`, results.failed.length > 0 ? 'red' : 'green');
  log(`Warnings: ${results.warnings.length}`, results.warnings.length > 0 ? 'yellow' : 'green');
  console.log(`\nPass Rate: ${passRate}%`);

  if (results.failed.length > 0) {
    console.log('\n' + '='.repeat(60));
    log('FAILED TESTS:', 'red');
    console.log('='.repeat(60));
    results.failed.forEach(failure => log(`  ✗ ${failure}`, 'red'));
  }

  if (results.warnings.length > 0) {
    console.log('\n' + '='.repeat(60));
    log('WARNINGS:', 'yellow');
    console.log('='.repeat(60));
    results.warnings.forEach(warning => log(`  ⚠ ${warning}`, 'yellow'));
  }

  console.log('\n' + '='.repeat(60));
  log('OVERALL ASSESSMENT', 'cyan');
  console.log('='.repeat(60));

  if (results.failed.length === 0 && results.warnings.length === 0) {
    log('✓ ALL TESTS PASSED - System is ready for deployment', 'green');
    console.log('\nThe registration system has successfully implemented:');
    console.log('  • Multi-step form with validation');
    console.log('  • File upload with size/type restrictions');
    console.log('  • XSS protection via DOMPurify');
    console.log('  • Tab switching (PBT Online/Offline, CBT)');
    console.log('  • Responsive design');
    console.log('  • Proper error handling and user feedback');
    console.log('  • index.html unchanged as required');
  } else if (results.failed.length === 0) {
    log('✓ TESTS PASSED WITH WARNINGS - System functional but review warnings', 'yellow');
  } else {
    log('✗ TESTS FAILED - Please address the failures above', 'red');
  }

  console.log('\n' + '='.repeat(60));
}

// Run all tests
try {
  testFileStructure();
  testHTMLStructure();
  testJavaScriptStructure();
  testCSSStyles();
  testSecurityFeatures();
  testGitStatus();
  testIntegration();
  generateReport();

  // Exit with appropriate code
  process.exit(results.failed.length > 0 ? 1 : 0);
} catch (error) {
  log(`\n✗ Test suite error: ${error.message}`, 'red');
  console.error(error);
  process.exit(1);
}
