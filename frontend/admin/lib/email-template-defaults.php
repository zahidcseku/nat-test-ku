<?php
/**
 * Default subjects, bodies, and variable specs for every email template.
 *
 * Single source of truth used by:
 *   - lib/email-templates.php (fallback when DB row missing; auto-seed)
 *   - pages/email-templates.php (editor reference, reset-to-default action)
 *   - api/email-templates/reset.php (restore row to defaults)
 *
 * Placeholder convention: {variable_name} with single braces.
 * Render is a literal str_replace — no template engine.
 *
 * NOTE: Intake-side templates (submission_receipt_*, payment_confirmation_*)
 * are also defined here so the admin UI can manage them, but the intake
 * service itself does NOT include this file. It reads the rows from the
 * shared email_templates table via /intake/email-templates.php and falls
 * back to its own buildRegistrationEmail() if a row is missing.
 */

/**
 * @return array<string, array{
 *   name: string,
 *   description: string,
 *   subject: string,
 *   body: string,
 *   variables: array<int, array{key:string,label:string,example:string}>
 * }>
 */
function emailTemplateDefaults(): array {
    return [

        // ---------------------------------------------------------------
        // 1. Application approved (admin)
        // ---------------------------------------------------------------
        'confirmation' => [
            'name'        => 'Application Approved',
            'description' => 'Sent when an admin approves a registration.',
            'subject'     => 'NAT-TEST Registration Approved',
            'body'        => '<h2 style="color: #1a202c; font-size: 24px; font-weight: 700; margin-bottom: 16px;">Registration Approved! 🎉</h2>'
                . '<p style="color: #4a5568; font-size: 16px; line-height: 1.6;">Dear {full_name},</p>'
                . '<p style="color: #4a5568; font-size: 16px; line-height: 1.6; margin: 16px 0;">'
                . 'Your registration for the Japanese NAT-TEST has been <strong>approved</strong>.</p>'
                . '<div style="background: #f7fafc; border-left: 4px solid #48bb78; padding: 16px; margin: 24px 0;">'
                . '<p style="margin: 0;"><strong>Exam Details:</strong></p>'
                . '<p style="margin: 8px 0;">Level: {exam_level}</p>'
                . '<p style="margin: 8px 0;">Date: {test_date}</p>'
                . '</div>'
                . '<p style="color: #4a5568; font-size: 16px; line-height: 1.6;">'
                . 'Your admission ticket will be emailed to you a few days before the exam.</p>',
            'variables' => [
                ['key' => 'full_name',  'label' => 'Applicant full name',    'example' => 'Jane Doe'],
                ['key' => 'exam_level', 'label' => 'Exam level(s)',          'example' => '1Q'],
                ['key' => 'test_date',  'label' => 'Exam date (formatted)',  'example' => 'September 15, 2026'],
            ],
        ],

        // ---------------------------------------------------------------
        // 2. Application rejected (admin)
        // ---------------------------------------------------------------
        'rejection' => [
            'name'        => 'Application Rejected',
            'description' => 'Sent when an admin rejects a registration with reasons.',
            'subject'     => 'Action Required: NAT-TEST Registration',
            'body'        => '<h2 style="color: #1a202c; font-size: 24px; font-weight: 700; margin-bottom: 16px;">Action Required: Registration Issues</h2>'
                . '<p style="color: #4a5568; font-size: 16px; line-height: 1.6;">Dear {full_name},</p>'
                . '<p style="color: #4a5568; font-size: 16px; line-height: 1.6; margin: 16px 0;">'
                . 'Your registration requires corrections. Please review the following issues:</p>'
                . '<div style="background: #fed7d7; border-left: 4px solid #f56565; padding: 16px; margin: 24px 0;">'
                . '<strong>Issues Found:</strong>'
                . '<div style="margin-top: 8px;">{rejection_reasons}</div>'
                . '</div>'
                . '<p style="color: #4a5568; font-size: 16px; line-height: 1.6;">'
                . 'Please reply to this email with the corrections and we\'ll update your application.</p>',
            'variables' => [
                ['key' => 'full_name',         'label' => 'Applicant full name', 'example' => 'Jane Doe'],
                ['key' => 'rejection_reasons', 'label' => 'Admin-entered reasons (HTML allowed)', 'example' => 'Photo is too blurry.<br>ID document number missing.'],
            ],
        ],

        // ---------------------------------------------------------------
        // 3. Admission ticket (admin)
        // ---------------------------------------------------------------
        'admission_ticket' => [
            'name'        => 'Admission Ticket',
            'description' => 'Sent with each admission ticket PDF attached. {guide_line} changes depending on whether an exam guide is attached.',
            'subject'     => 'Your NAT-TEST Admission Ticket',
            'body'        => '<!DOCTYPE html><html><body style="font-family:Arial,Helvetica,sans-serif;color:#1a202c;margin:0;padding:16px;">'
                . '<h2 style="color:#002147;">Japanese Language NAT-TEST — Khulna Test Center</h2>'
                . '<p style="font-size:14px;">Dear {full_name},</p>'
                . '<p style="font-size:14px;">{guide_line}</p>'
                . '<div style="background:#f4f6f8;border-left:4px solid #667eea;padding:12px 16px;margin:16px 0;font-size:14px;">'
                . '<strong>Examinee ID:</strong> {xlsx_id}<br>'
                . '<strong>Reg. Number:</strong> {reg_no}'
                . '</div>'
                . '<p style="font-size:13px;color:#555;">Please arrive at the test center at least 30 minutes before the exam start time. '
                . 'If you have any questions, reply to this email or contact '
                . '<a href="mailto:info@nat-test.ku.ac.bd">info@nat-test.ku.ac.bd</a>.</p>'
                . '<p style="font-size:13px;color:#555;">This is an automated message — please do not reply directly.</p>'
                . '</body></html>',
            'variables' => [
                ['key' => 'full_name',  'label' => 'Applicant full name',          'example' => 'Jane Doe'],
                ['key' => 'xlsx_id',    'label' => 'Examinee ID from xlsx',        'example' => '47610001'],
                ['key' => 'reg_no',     'label' => 'Registration sheet number',    'example' => 'NAT-2026-0001'],
                ['key' => 'guide_line', 'label' => 'Auto: sentence about the guide (filled by system)', 'example' => 'Your admission ticket is attached to this email. Please print it and bring it with you on the exam day.'],
            ],
        ],

        // ---------------------------------------------------------------
        // 4. Score report (admin)
        // ---------------------------------------------------------------
        'score_report' => [
            'name'        => 'Score Report',
            'description' => 'Sent with each score report PDF attached.',
            'subject'     => 'Your NAT-TEST Score Report',
            'body'        => '<!DOCTYPE html><html><body style="font-family:Arial,Helvetica,sans-serif;color:#1a202c;margin:0;padding:16px;">'
                . '<h2 style="color:#002147;">Japanese Language NAT-TEST — Khulna Test Center</h2>'
                . '<p style="font-size:14px;">Dear {full_name},</p>'
                . '<p style="font-size:14px;">Your NAT-TEST score report is attached to this email. '
                . 'Please review your results carefully.</p>'
                . '<div style="background:#f4f6f8;border-left:4px solid #667eea;padding:12px 16px;margin:16px 0;font-size:14px;">'
                . '<strong>Examinee ID:</strong> {xlsx_id}<br>'
                . '<strong>Reg. Number:</strong> {reg_no}'
                . '</div>'
                . '<p style="font-size:13px;color:#555;">If you have any questions about your results, reply to this email or contact '
                . '<a href="mailto:info@nat-test.ku.ac.bd">info@nat-test.ku.ac.bd</a>.</p>'
                . '<p style="font-size:13px;color:#555;">This is an automated message — please do not reply directly.</p>'
                . '</body></html>',
            'variables' => [
                ['key' => 'full_name', 'label' => 'Applicant full name',       'example' => 'Jane Doe'],
                ['key' => 'xlsx_id',   'label' => 'Examinee ID from xlsx',     'example' => '47610001'],
                ['key' => 'reg_no',    'label' => 'Registration sheet number', 'example' => 'NAT-2026-0001'],
            ],
        ],

        // ---------------------------------------------------------------
        // 5. Payment retry link (admin)
        // ---------------------------------------------------------------
        'payment_retry' => [
            'name'        => 'Payment Retry Link',
            'description' => 'Sent manually by an admin to re-send the payment link for an unpaid registration.',
            'subject'     => 'Complete Your NAT-TEST Registration Payment',
            'body'        => '<!DOCTYPE html><html><body style="font-family:Arial,Helvetica,sans-serif;color:#1a202c;margin:0;padding:16px;">'
                . '<h2 style="color:#002147;">Japanese Language NAT-TEST — Khulna Test Center</h2>'
                . '<p style="font-size:14px;">Dear {full_name},</p>'
                . '<p style="font-size:14px;">Your registration for NAT-TEST is pending payment completion.</p>'
                . '<div style="background:#f4f6f8;border-left:4px solid #667eea;padding:12px 16px;margin:16px 0;font-size:14px;">'
                . '<strong>Registration ID:</strong> {registration_id}<br>'
                . '<strong>Name:</strong> {full_name}<br>'
                . '<strong>Email:</strong> {email}<br>'
                . '<strong>Exam Level:</strong> {exam_level}<br>'
                . '<strong>Test Date:</strong> {test_date}<br>'
                . '<strong>Total Amount:</strong> {total_amount} BDT (includes {transaction_fee} BDT online fee)'
                . '</div>'
                . '<p style="font-size:14px;"><strong>PAYMENT STATUS: Unpaid</strong></p>'
                . '<p style="font-size:14px;">To complete your registration, please make the payment using the link below:</p>'
                . '<p style="font-size:14px;"><a href="{retry_link}" style="display:inline-block;background:#002147;color:#ffffff;padding:10px 22px;border-radius:6px;text-decoration:none;">Complete your payment</a></p>'
                . '<p style="font-size:13px;color:#555;">Or open this link: <a href="{retry_link}">{retry_link}</a></p>'
                . '<p style="font-size:13px;color:#555;">This secure payment link will expire in 7 days.</p>'
                . '<p style="font-size:13px;color:#555;">Need Help? Email: <a href="mailto:info@nat-test.ku.ac.bd">info@nat-test.ku.ac.bd</a></p>'
                . '</body></html>',
            'variables' => [
                ['key' => 'full_name',        'label' => 'Applicant full name',  'example' => 'Jane Doe'],
                ['key' => 'registration_id',  'label' => 'Registration UUID',    'example' => 'abc-123-def'],
                ['key' => 'email',            'label' => 'Applicant email',      'example' => 'jane@example.com'],
                ['key' => 'exam_level',       'label' => 'Exam level(s)',        'example' => '1Q'],
                ['key' => 'test_date',        'label' => 'Exam date',            'example' => '2026-09-15'],
                ['key' => 'total_amount',     'label' => 'Total amount (number)', 'example' => '4000'],
                ['key' => 'transaction_fee',  'label' => 'Online fee (number)',   'example' => '0'],
                ['key' => 'retry_link',       'label' => 'Payment retry URL',    'example' => 'https://nat-test.ku.ac.bd/payment-retry.html?token=abc'],
            ],
        ],

        // ---------------------------------------------------------------
        // 6. Submission receipt — online payment (intake)
        // ---------------------------------------------------------------
        'submission_receipt_online' => [
            'name'        => 'Submission Received (Online Pay)',
            'description' => 'Auto-sent by the intake service after a registration is saved with online payment selected but not yet completed.',
            'subject'     => 'NAT-TEST Application Received - Payment Required',
            'body'        => '<!DOCTYPE html><html><body style="font-family:Arial,Helvetica,sans-serif;color:#1a202c;margin:0;padding:16px;">'
                . '<h2 style="color:#002147;">Japanese Language NAT-TEST — Khulna Test Center</h2>'
                . '<p style="font-size:14px;">Dear {full_name},</p>'
                . '<p style="font-size:15px;background:#fef7e0;border-left:4px solid #f9ab00;padding:10px 14px;">'
                . '<strong>Your application is saved but not yet paid.</strong> '
                . 'Please complete the payment of <strong>{total_amount} BDT</strong> using one of the options below.</p>'
                . '{payment_options_online}'
                . '<h3 style="color:#002147;">Your submitted information</h3>'
                . '{info_table}'
                . '{next_steps}'
                . '<p style="font-size:13px;color:#555;">Questions? Contact '
                . '<a href="mailto:info@nat-test.ku.ac.bd">info@nat-test.ku.ac.bd</a>. '
                . 'This is an automated message — please do not reply to this address.</p>'
                . '</body></html>',
            'variables' => [
                ['key' => 'full_name',              'label' => 'Applicant full name',                  'example' => 'Jane Doe'],
                ['key' => 'total_amount',           'label' => 'Total amount (formatted with BDT)',    'example' => '4,000.00 BDT'],
                ['key' => 'payment_options_online', 'label' => 'Auto: pay button + QR section',         'example' => '<em>(payment button + QR code rendered here)</em>'],
                ['key' => 'info_table',             'label' => 'Auto: submitted-info HTML table',       'example' => '<em>(submitted info table rendered here)</em>'],
                ['key' => 'next_steps',             'label' => 'Auto: next-steps block',                'example' => '<em>(next steps list rendered here)</em>'],
            ],
        ],

        // ---------------------------------------------------------------
        // 7. Submission receipt — offline / bank deposit (intake)
        // ---------------------------------------------------------------
        'submission_receipt_offline' => [
            'name'        => 'Submission Received (Bank Deposit)',
            'description' => 'Auto-sent by the intake service after a registration is saved with offline (bank deposit) payment selected.',
            'subject'     => 'NAT-TEST Application Received - Payment Required',
            'body'        => '<!DOCTYPE html><html><body style="font-family:Arial,Helvetica,sans-serif;color:#1a202c;margin:0;padding:16px;">'
                . '<h2 style="color:#002147;">Japanese Language NAT-TEST — Khulna Test Center</h2>'
                . '<p style="font-size:14px;">Dear {full_name},</p>'
                . '<p style="font-size:15px;background:#fef7e0;border-left:4px solid #f9ab00;padding:10px 14px;">'
                . '<strong>Your application is saved but not yet paid.</strong> '
                . 'Please complete the payment of <strong>{total_amount} BDT</strong> using one of the options below.</p>'
                . '{payment_options_offline}'
                . '<h3 style="color:#002147;">Your submitted information</h3>'
                . '{info_table}'
                . '{next_steps}'
                . '<p style="font-size:13px;color:#555;">Questions? Contact '
                . '<a href="mailto:info@nat-test.ku.ac.bd">info@nat-test.ku.ac.bd</a>. '
                . 'This is an automated message — please do not reply to this address.</p>'
                . '</body></html>',
            'variables' => [
                ['key' => 'full_name',               'label' => 'Applicant full name',                  'example' => 'Jane Doe'],
                ['key' => 'total_amount',            'label' => 'Total amount (formatted with BDT)',    'example' => '4,000.00 BDT'],
                ['key' => 'payment_options_offline', 'label' => 'Auto: bank account details + QR',       'example' => '<em>(bank details + QR code rendered here)</em>'],
                ['key' => 'info_table',              'label' => 'Auto: submitted-info HTML table',       'example' => '<em>(submitted info table rendered here)</em>'],
                ['key' => 'next_steps',              'label' => 'Auto: next-steps block',                'example' => '<em>(next steps list rendered here)</em>'],
            ],
        ],

        // ---------------------------------------------------------------
        // 8. Payment confirmation — paid (intake)
        // ---------------------------------------------------------------
        'payment_confirmation_paid' => [
            'name'        => 'Payment Received',
            'description' => 'Auto-sent after an online payment is verified as paid.',
            'subject'     => 'NAT-TEST Registration - Payment Received',
            'body'        => '<!DOCTYPE html><html><body style="font-family:Arial,Helvetica,sans-serif;color:#1a202c;margin:0;padding:16px;">'
                . '<h2 style="color:#002147;">Japanese Language NAT-TEST — Khulna Test Center</h2>'
                . '<p style="font-size:14px;">Dear {full_name},</p>'
                . '{banner_paid}'
                . '<h3 style="color:#002147;">Your submitted information</h3>'
                . '{info_table}'
                . '{next_steps}'
                . '<p style="font-size:13px;color:#555;">Questions? Contact '
                . '<a href="mailto:info@nat-test.ku.ac.bd">info@nat-test.ku.ac.bd</a>. '
                . 'This is an automated message — please do not reply to this address.</p>'
                . '</body></html>',
            'variables' => [
                ['key' => 'full_name',  'label' => 'Applicant full name',                  'example' => 'Jane Doe'],
                ['key' => 'banner_paid', 'label' => 'Auto: payment-received banner HTML',   'example' => '<em>(payment received banner rendered here)</em>'],
                ['key' => 'info_table', 'label' => 'Auto: submitted-info HTML table',       'example' => '<em>(submitted info table rendered here)</em>'],
                ['key' => 'next_steps', 'label' => 'Auto: next-steps block',                'example' => '<em>(next steps list rendered here)</em>'],
            ],
        ],

        // ---------------------------------------------------------------
        // 9. Payment confirmation — pending verification (intake)
        // ---------------------------------------------------------------
        'payment_confirmation_pending' => [
            'name'        => 'Payment Pending Verification',
            'description' => 'Auto-sent after a registration with an uploaded receipt is received (manual verification still required).',
            'subject'     => 'NAT-TEST Registration Received - Receipt Pending Verification',
            'body'        => '<!DOCTYPE html><html><body style="font-family:Arial,Helvetica,sans-serif;color:#1a202c;margin:0;padding:16px;">'
                . '<h2 style="color:#002147;">Japanese Language NAT-TEST — Khulna Test Center</h2>'
                . '<p style="font-size:14px;">Dear {full_name},</p>'
                . '{banner_pending}'
                . '<h3 style="color:#002147;">Your submitted information</h3>'
                . '{info_table}'
                . '{next_steps}'
                . '<p style="font-size:13px;color:#555;">Questions? Contact '
                . '<a href="mailto:info@nat-test.ku.ac.bd">info@nat-test.ku.ac.bd</a>. '
                . 'This is an automated message — please do not reply to this address.</p>'
                . '</body></html>',
            'variables' => [
                ['key' => 'full_name',     'label' => 'Applicant full name',                    'example' => 'Jane Doe'],
                ['key' => 'banner_pending', 'label' => 'Auto: pending-verification banner HTML', 'example' => '<em>(pending verification banner rendered here)</em>'],
                ['key' => 'info_table',    'label' => 'Auto: submitted-info HTML table',         'example' => '<em>(submitted info table rendered here)</em>'],
                ['key' => 'next_steps',    'label' => 'Auto: next-steps block',                  'example' => '<em>(next steps list rendered here)</em>'],
            ],
        ],

        // ---------------------------------------------------------------
        // 10. Certificate request received (intake, sent after 200 BDT payment verified)
        // ---------------------------------------------------------------
        'certificate_requested' => [
            'name'        => 'Certificate Request Received',
            'description' => 'Auto-sent after a certificate request payment (200 BDT) is verified as paid.',
            'subject'     => 'NAT-TEST Certificate Request Received',
            'body'        => '<!DOCTYPE html><html><body style="font-family:Arial,Helvetica,sans-serif;color:#1a202c;margin:0;padding:16px;">'
                . '<h2 style="color:#002147;">Japanese Language NAT-TEST — Khulna Test Center</h2>'
                . '<p style="font-size:14px;">Dear {full_name},</p>'
                . '<p style="font-size:15px;background:#e6f4ea;border-left:4px solid #1e8e3e;padding:10px 14px;">'
                . '<strong>Certificate request received.</strong> '
                . 'Your payment of <strong>200 BDT</strong> for postal delivery has been confirmed'
                . ' (bank reference: {bank_tran_id}).</p>'
                . '<div style="background:#f4f6f8;border-left:4px solid #667eea;padding:12px 16px;margin:16px 0;font-size:14px;">'
                . '<strong>Reg. Number:</strong> {reg_no}<br>'
                . '<strong>Exam Date:</strong> {exam_date}<br>'
                . '<strong>Ship To:</strong> {recipient_name}, {house_street}, {area_thana}, {district} {postal_code}'
                . '</div>'
                . '<p style="font-size:14px;">We will process your certificate and post it to the address above. '
                . 'You will receive another email once the certificate has been posted.</p>'
                . '<p style="font-size:13px;color:#555;">Questions? Contact '
                . '<a href="mailto:info@nat-test.ku.ac.bd">info@nat-test.ku.ac.bd</a>. '
                . 'This is an automated message — please do not reply to this address.</p>'
                . '</body></html>',
            'variables' => [
                ['key' => 'full_name',      'label' => 'Examinee full name',       'example' => 'Jane Doe'],
                ['key' => 'reg_no',         'label' => 'Registration sheet number', 'example' => 'NAT-2026-0001'],
                ['key' => 'exam_date',      'label' => 'Exam date (formatted)',     'example' => 'February 16, 2026'],
                ['key' => 'recipient_name', 'label' => 'Shipping recipient name',   'example' => 'Jane Doe'],
                ['key' => 'house_street',   'label' => 'House/street address',      'example' => '123 Main St'],
                ['key' => 'area_thana',     'label' => 'Area/thana',                'example' => 'Daulatpur'],
                ['key' => 'district',       'label' => 'District',                  'example' => 'Khulna'],
                ['key' => 'postal_code',    'label' => 'Postal code',               'example' => '9204'],
                ['key' => 'bank_tran_id',   'label' => 'Bank transaction ID',       'example' => 'BANK123456'],
            ],
        ],

        // ---------------------------------------------------------------
        // 11. Certificate posted (admin action)
        // ---------------------------------------------------------------
        'certificate_posted' => [
            'name'        => 'Certificate Posted',
            'description' => 'Sent when an admin marks a certificate as posted. {tracking_block} is filled with a tracking-number line when one was provided.',
            'subject'     => 'Your NAT-TEST Certificate Has Been Posted',
            'body'        => '<!DOCTYPE html><html><body style="font-family:Arial,Helvetica,sans-serif;color:#1a202c;margin:0;padding:16px;">'
                . '<h2 style="color:#002147;">Japanese Language NAT-TEST — Khulna Test Center</h2>'
                . '<p style="font-size:14px;">Dear {full_name},</p>'
                . '<p style="font-size:15px;background:#e6f4ea;border-left:4px solid #1e8e3e;padding:10px 14px;">'
                . '<strong>Your certificate has been posted.</strong></p>'
                . '<div style="background:#f4f6f8;border-left:4px solid #667eea;padding:12px 16px;margin:16px 0;font-size:14px;">'
                . '<strong>Reg. Number:</strong> {reg_no}<br>'
                . '<strong>Exam Date:</strong> {exam_date}<br>'
                . '{tracking_block}'
                . '<strong>Ship To:</strong> {recipient_name}, {house_street}, {area_thana}, {district} {postal_code}'
                . '</div>'
                . '<p style="font-size:14px;">Please allow 3-7 business days for delivery within Bangladesh. '
                . 'If you do not receive your certificate, contact '
                . '<a href="mailto:info@nat-test.ku.ac.bd">info@nat-test.ku.ac.bd</a>.</p>'
                . '<p style="font-size:13px;color:#555;">This is an automated message — please do not reply to this address.</p>'
                . '</body></html>',
            'variables' => [
                ['key' => 'full_name',      'label' => 'Examinee full name',                              'example' => 'Jane Doe'],
                ['key' => 'reg_no',         'label' => 'Registration sheet number',                        'example' => 'NAT-2026-0001'],
                ['key' => 'exam_date',      'label' => 'Exam date (formatted)',                            'example' => 'February 16, 2026'],
                ['key' => 'recipient_name', 'label' => 'Shipping recipient name',                          'example' => 'Jane Doe'],
                ['key' => 'house_street',   'label' => 'House/street address',                             'example' => '123 Main St'],
                ['key' => 'area_thana',     'label' => 'Area/thana',                                       'example' => 'Daulatpur'],
                ['key' => 'district',       'label' => 'District',                                         'example' => 'Khulna'],
                ['key' => 'postal_code',    'label' => 'Postal code',                                      'example' => '9204'],
                ['key' => 'tracking_block', 'label' => 'Auto: tracking-number HTML line (empty if none)',  'example' => ''],
            ],
        ],
    ];
}
