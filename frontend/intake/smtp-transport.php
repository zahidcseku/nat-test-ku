<?php
/**
 * Shared SMTP transport for the NAT-TEST project.
 *
 * Single source of truth for sending authenticated SMTP email. Used by:
 *   - frontend/intake/mailer.php  (applicant confirmations, payment emails)
 *   - frontend/admin/functions.php (admin sendEmail: approvals, tickets, resends)
 *
 * Zero project dependencies: reads only from environment variables, calls
 * no project-specific logging or DB code. Callers own logging and persistence.
 *
 * Not directly accessible as an endpoint — define no INTAKE_SERVICE guard
 * because admin includes this file too. The function returns a result
 * array; nothing is echoed.
 */

if (!function_exists('smtpSendMail')) {

    /**
     * Send one MIME/HTML message via authenticated SMTP.
     *
     * Env vars read:
     *   SMTP_HOST, SMTP_PORT (default 587), SMTP_USER, SMTP_PASS,
     *   SMTP_SECURE ('starttls' default | 'ssl' | 'none'),
     *   SMTP_ALLOW_SELF_SIGNED ('true' to relax TLS verification).
     *
     * @param string $to         Recipient email.
     * @param string $subject    Subject line (ASCII or UTF-8).
     * @param string $body       HTML body.
     * @param string $fromEmail Envelope sender (and From: header).
     * @param string $fromName  Optional display name for the From header.
     *
     * @return array{success:bool, error:?string, smtp_host:?string, smtp_port:?int}
     *     success true   -> server accepted the message (queued, not
     *                      necessarily delivered — check the recipient inbox)
     *     success false  -> error has the reason; callers decide how to
     *                      log / fall back / surface to the user.
     */
    function smtpSendMail(string $to, string $subject, string $body, string $fromEmail, string $fromName = ''): array {
        $host   = getenv('SMTP_HOST');
        $port   = (int) (getenv('SMTP_PORT') ?: 587);
        $user   = getenv('SMTP_USER');
        $pass   = getenv('SMTP_PASS');
        $secure = strtolower(getenv('SMTP_SECURE') ?: 'starttls');

        if (!$host || !$user || !$pass) {
            return [
                'success' => false,
                'error'   => 'SMTP_HOST / SMTP_USER / SMTP_PASS not configured',
                'smtp_host' => $host ?: null,
                'smtp_port' => $port,
            ];
        }

        $allowSelfSigned = strtolower(getenv('SMTP_ALLOW_SELF_SIGNED') ?: '') === 'true';
        $context = $allowSelfSigned ? stream_context_create(['ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ]]) : null;

        $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $errno  = 0;
        $errstr = '';
        $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
        if (!$fp) {
            return [
                'success'   => false,
                'error'     => sprintf('connect to %s:%d failed: %s', $host, $port, $errstr),
                'smtp_host' => $host,
                'smtp_port' => $port,
            ];
        }
        stream_set_timeout($fp, 15);

        $read = static function () use ($fp) {
            $data = '';
            while (($line = fgets($fp, 515)) !== false) {
                $data .= $line;
                if (strlen($line) < 4 || $line[3] !== '-') break;
            }
            return $data;
        };
        $cmd = static function (?string $c, int $expect) use ($fp, $read) {
            if ($c !== null) fwrite($fp, $c . "\r\n");
            $resp = $read();
            if (strpos($resp, (string) $expect) !== 0) {
                throw new RuntimeException('SMTP unexpected response: ' . trim(substr($resp, 0, 200)));
            }
            return $resp;
        };

        try {
            $cmd(null, 220);
            $cmd('EHLO nat-test.ku.ac.bd', 250);

            if ($secure === 'starttls') {
                $cmd('STARTTLS', 220);
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('TLS negotiation failed');
                }
                $cmd('EHLO nat-test.ku.ac.bd', 250);
            }

            $cmd('AUTH LOGIN', 334);
            $cmd(base64_encode($user), 334);
            $cmd(base64_encode($pass), 235);
            $cmd('MAIL FROM:<' . $fromEmail . '>', 250);
            $cmd('RCPT TO:<' . $to . '>', 250);
            $cmd('DATA', 354);

            $fromHeader = $fromName !== ''
                ? sprintf('From: %s <%s>', $fromName, $fromEmail)
                : 'From: ' . $fromEmail;

            $headers = $fromHeader . "\r\n"
                . 'To: ' . $to . "\r\n"
                . 'Subject: ' . $subject . "\r\n"
                . 'Date: ' . date('r') . "\r\n"
                . "MIME-Version: 1.0\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n";

            // RFC 5321 dot-stuffing
            $stuffed = preg_replace('/^\./m', '..', $body);
            $cmd($headers . "\r\n" . $stuffed . "\r\n.", 250);
            $cmd('QUIT', 221);
            fclose($fp);

            return [
                'success'   => true,
                'error'     => null,
                'smtp_host' => $host,
                'smtp_port' => $port,
            ];
        } catch (Throwable $e) {
            if (is_resource($fp)) {
                @fclose($fp);
            }
            return [
                'success'   => false,
                'error'     => $e->getMessage(),
                'smtp_host' => $host,
                'smtp_port' => $port,
            ];
        }
    }

    /**
     * Send an HTML message with one or more file attachments via
     * authenticated SMTP. Used for admission-ticket PDFs.
     *
     * Same envelope behaviour as smtpSendMail(): reads the same SMTP_*
     * env vars, returns the same result-array shape. Builds a
     * multipart/mixed body with a text/html first part and one
     * base64-encoded attachment part per file.
     *
     * @param array<int, array{path:string, name:string, mime:string}> $attachments
     */
    function smtpSendMailWithAttachment(
        string $to,
        string $subject,
        string $htmlBody,
        string $fromEmail,
        string $fromName = '',
        array $attachments = []
    ): array {
        // No attachments? Delegate to the simpler HTML-only path.
        if (empty($attachments)) {
            return smtpSendMail($to, $subject, $htmlBody, $fromEmail, $fromName);
        }

        // Validate every attachment file is readable up front so we can
        // fail BEFORE opening the SMTP socket (cleaner error).
        foreach ($attachments as $i => $att) {
            if (!isset($att['path'], $att['name'], $att['mime'])) {
                return [
                    'success'   => false,
                    'error'     => "attachment[$i] missing path/name/mime",
                    'smtp_host' => null,
                    'smtp_port' => null,
                ];
            }
            if (!is_readable($att['path'])) {
                return [
                    'success'   => false,
                    'error'     => 'attachment not readable: ' . $att['path'],
                    'smtp_host' => null,
                    'smtp_port' => null,
                ];
            }
        }

        $boundary = '----=_NextPart_' . bin2hex(random_bytes(12));

        // Build the multipart/mixed body once — we resend the same bytes
        // over the socket regardless of SMTP vs SSL.
        $mimeBody = "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n"
            . "\r\n"
            . $htmlBody . "\r\n";

        foreach ($attachments as $att) {
            $bytes = file_get_contents($att['path']);
            if ($bytes === false) {
                return [
                    'success'   => false,
                    'error'     => 'failed to read attachment: ' . $att['path'],
                    'smtp_host' => null,
                    'smtp_port' => null,
                ];
            }
            $b64 = chunk_split(base64_encode($bytes));
            $mimeBody .= "\r\n--{$boundary}\r\n"
                . 'Content-Type: ' . $att['mime'] . "; name=\"" . $att['name'] . "\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . 'Content-Disposition: attachment; filename="' . $att['name'] . "\"\r\n"
                . "\r\n"
                . $b64;
        }
        $mimeBody .= "\r\n--{$boundary}--\r\n";

        // --- Copy of the SMTP socket dance from smtpSendMail(), with
        //     MIME-Version + Content-Type headers swapped for the
        //     multipart/mixed version.
        $host   = getenv('SMTP_HOST');
        $port   = (int) (getenv('SMTP_PORT') ?: 587);
        $user   = getenv('SMTP_USER');
        $pass   = getenv('SMTP_PASS');
        $secure = strtolower(getenv('SMTP_SECURE') ?: 'starttls');

        if (!$host || !$user || !$pass) {
            return [
                'success'   => false,
                'error'     => 'SMTP_HOST / SMTP_USER / SMTP_PASS not configured',
                'smtp_host' => $host ?: null,
                'smtp_port' => $port,
            ];
        }

        $allowSelfSigned = strtolower(getenv('SMTP_ALLOW_SELF_SIGNED') ?: '') === 'true';
        $context = $allowSelfSigned ? stream_context_create(['ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ]]) : null;

        $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $errno  = 0;
        $errstr = '';
        $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
        if (!$fp) {
            return [
                'success'   => false,
                'error'     => sprintf('connect to %s:%d failed: %s', $host, $port, $errstr),
                'smtp_host' => $host,
                'smtp_port' => $port,
            ];
        }
        stream_set_timeout($fp, 15);

        $read = static function () use ($fp) {
            $data = '';
            while (($line = fgets($fp, 515)) !== false) {
                $data .= $line;
                if (strlen($line) < 4 || $line[3] !== '-') break;
            }
            return $data;
        };
        $cmd = static function (?string $c, int $expect) use ($fp, $read) {
            if ($c !== null) fwrite($fp, $c . "\r\n");
            $resp = $read();
            if (strpos($resp, (string) $expect) !== 0) {
                throw new RuntimeException('SMTP unexpected response: ' . trim(substr($resp, 0, 200)));
            }
            return $resp;
        };

        try {
            $cmd(null, 220);
            $cmd('EHLO nat-test.ku.ac.bd', 250);

            if ($secure === 'starttls') {
                $cmd('STARTTLS', 220);
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('TLS negotiation failed');
                }
                $cmd('EHLO nat-test.ku.ac.bd', 250);
            }

            $cmd('AUTH LOGIN', 334);
            $cmd(base64_encode($user), 334);
            $cmd(base64_encode($pass), 235);
            $cmd('MAIL FROM:<' . $fromEmail . '>', 250);
            $cmd('RCPT TO:<' . $to . '>', 250);
            $cmd('DATA', 354);

            $fromHeader = $fromName !== ''
                ? sprintf('From: %s <%s>', $fromName, $fromEmail)
                : 'From: ' . $fromEmail;

            $headers = $fromHeader . "\r\n"
                . 'To: ' . $to . "\r\n"
                . 'Subject: ' . $subject . "\r\n"
                . 'Date: ' . date('r') . "\r\n"
                . "MIME-Version: 1.0\r\n"
                . 'Content-Type: multipart/mixed; boundary="' . $boundary . "\"\r\n";

            // RFC 5321 dot-stuffing
            $stuffed = preg_replace('/^\./m', '..', $mimeBody);
            $cmd($headers . "\r\n" . $stuffed . "\r\n.", 250);
            $cmd('QUIT', 221);
            fclose($fp);

            return [
                'success'   => true,
                'error'     => null,
                'smtp_host' => $host,
                'smtp_port' => $port,
            ];
        } catch (Throwable $e) {
            if (is_resource($fp)) {
                @fclose($fp);
            }
            return [
                'success'   => false,
                'error'     => $e->getMessage(),
                'smtp_host' => $host,
                'smtp_port' => $port,
            ];
        }
    }
}
