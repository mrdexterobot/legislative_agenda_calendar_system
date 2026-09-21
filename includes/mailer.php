<?php
/**
 * mailer.php — dependency-free SMTP client (STARTTLS on 587, AUTH LOGIN).
 *
 * WHY THIS WAS REWRITTEN
 * ---------------------------------------------------------------------------
 * The previous version only checked whether SMTP_USERNAME was still the
 * placeholder. It never checked SMTP_FROM_EMAIL, SMTP_PASSWORD, or whether
 * the host was even reachable — so the single most common Brevo failure
 * ("MAIL FROM:<PASTE_YOUR_VERIFIED_BREVO_SENDER_EMAIL_HERE>" → 501/550
 * rejection) produced a failure that every caller then swallowed, because
 * api/auth/forgot-password.php deliberately returns a generic response and
 * api/meetings/send-notifications.php reports failures per-recipient.
 * Result: "it says the code was sent but nothing arrives."
 *
 * Three changes:
 *   1. smtpConfigIssues() validates ALL required settings before a socket is
 *      ever opened, and returns human-readable problems instead of a bare
 *      false.
 *   2. Every send — success, skip, or hard failure — writes the real SMTP
 *      conversation outcome to includes/debug_last_email.php (gitignored).
 *   3. api/admin/test-email.php (new) surfaces that real error in the browser
 *      instead of relying on anyone opening a debug file.
 */

/** Returns a list of configuration problems. Empty array = configured. */
function smtpConfigIssues(): array {
    $issues = [];

    $required = [
        'SMTP_HOST'       => 'SMTP host',
        'SMTP_PORT'       => 'SMTP port',
        'SMTP_USERNAME'   => 'SMTP username',
        'SMTP_PASSWORD'   => 'SMTP password / API key',
        'SMTP_FROM_EMAIL' => 'Sender email (must be a verified sender in Brevo)',
    ];

    foreach ($required as $const => $label) {
        if (!defined($const)) {
            $issues[] = "$label is missing from includes/config.php ($const).";
            continue;
        }
        $value = (string) constant($const);
        if (trim($value) === '') {
            $issues[] = "$label is blank in includes/config.php ($const).";
        } elseif (stripos($value, 'PASTE_') === 0) {
            $issues[] = "$label is still the placeholder value in includes/config.php ($const).";
        }
    }

    if (defined('SMTP_FROM_EMAIL')
        && stripos((string) SMTP_FROM_EMAIL, 'PASTE_') !== 0
        && !filter_var(SMTP_FROM_EMAIL, FILTER_VALIDATE_EMAIL)) {
        $issues[] = 'SMTP_FROM_EMAIL is not a valid email address.';
    }

    if (!extension_loaded('openssl')) {
        $issues[] = 'The PHP openssl extension is not enabled, so STARTTLS is impossible. '
            . 'In XAMPP: open php.ini, remove the leading ";" from ";extension=openssl", restart Apache. '
            . 'On shared hosting this is normally on already.';
    }

    return $issues;
}

/**
 * Sends one email. Returns ['success' => bool, 'error' => ?string].
 * Never throws — callers can safely ignore the return value, but the reason
 * for any failure is always written to includes/debug_last_email.php.
 */
function sendEmail(string $to, string $subject, string $body): array {
    $timestamp = date('Y-m-d H:i:s');

    $issues = smtpConfigIssues();
    if ($issues) {
        $note = 'Email not sent — configuration problem: ' . implode(' ', $issues);
        error_log("[EMAIL SKIPPED] To: $to | Subject: $subject | $note");
        writeEmailDebugFile($to, $subject, $body, $timestamp, false, $note);
        return ['success' => false, 'error' => $note];
    }

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $note = "Recipient address \"$to\" is not a valid email address.";
        writeEmailDebugFile($to, $subject, $body, $timestamp, false, $note);
        return ['success' => false, 'error' => $note];
    }

    try {
        $result = smtpSend($to, $subject, $body);
    } catch (\Throwable $e) {
        $result = ['success' => false, 'error' => $e->getMessage()];
    }

    writeEmailDebugFile($to, $subject, $body, $timestamp, $result['success'], $result['error'] ?? 'Sent successfully.');

    if (!$result['success']) {
        error_log("[EMAIL FAILED] To: $to | Subject: $subject | " . $result['error']);
    }

    return $result;
}

/** Low-level SMTP conversation. Returns ['success' => bool, 'error' => ?string]. */
function smtpSend(string $to, string $subject, string $body): array {
    $host = SMTP_HOST;
    $port = (int) SMTP_PORT;

    $fp = @fsockopen($host, $port, $errno, $errstr, 10);
    if (!$fp) {
        return ['success' => false, 'error' =>
            "Could not open a connection to $host:$port — $errstr ($errno). "
            . 'Usually this means outbound SMTP is blocked (firewall, antivirus, or the host blocking port '
            . $port . '). Brevo also accepts port 2525 — try changing SMTP_PORT in includes/config.php.'];
    }
    stream_set_timeout($fp, 15);

    try {
        smtpReadResponse($fp, [220]); // server greeting

        smtpCommand($fp, 'EHLO ' . smtpLocalDomain() . "\r\n", [250]);
        smtpCommand($fp, "STARTTLS\r\n", [220]);

        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('TLS handshake failed after STARTTLS was accepted.');
        }

        // RFC 3207: EHLO must be re-issued inside the now-encrypted session.
        smtpCommand($fp, 'EHLO ' . smtpLocalDomain() . "\r\n", [250]);

        smtpCommand($fp, "AUTH LOGIN\r\n", [334]);
        smtpCommand($fp, base64_encode(SMTP_USERNAME) . "\r\n", [334]);
        smtpCommand($fp, base64_encode(SMTP_PASSWORD) . "\r\n", [235]);

        smtpCommand($fp, 'MAIL FROM:<' . SMTP_FROM_EMAIL . ">\r\n", [250]);
        smtpCommand($fp, "RCPT TO:<$to>\r\n", [250, 251]);
        smtpCommand($fp, "DATA\r\n", [354]);

        $message = smtpBuildMessage($to, $subject, $body);
        smtpCommand($fp, $message . "\r\n.\r\n", [250]);

        smtpCommand($fp, "QUIT\r\n", [221]);
    } catch (\Throwable $e) {
        @fclose($fp);
        return ['success' => false, 'error' => $e->getMessage()];
    }

    fclose($fp);
    return ['success' => true];
}

function smtpLocalDomain(): string {
    return preg_replace('/[^a-zA-Z0-9.\-]/', '', $_SERVER['SERVER_NAME'] ?? 'localhost') ?: 'localhost';
}

function smtpCommand($fp, string $command, array $expectedCodes): string {
    fwrite($fp, $command);
    // Never echo the base64 credential lines back into an error message.
    $safeCommand = preg_match('/^[A-Za-z0-9+\/=]+\r\n$/', $command) ? '<credentials>' : trim($command);
    return smtpReadResponse($fp, $expectedCodes, $safeCommand);
}

/** Reads one (possibly multiline) SMTP response and enforces the status code. */
function smtpReadResponse($fp, array $expectedCodes, string $context = 'server greeting'): string {
    $response = '';
    while (($line = fgets($fp, 515)) !== false) {
        $response .= $line;
        // Complete once a line has a SPACE (not a dash) in the 4th position.
        if (strlen($line) < 4 || $line[3] === ' ') {
            break;
        }
    }
    if ($response === '') {
        throw new RuntimeException("No response from the SMTP server after \"$context\" (connection may have timed out).");
    }
    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException("SMTP server rejected \"$context\" with: " . trim($response) . ' ' . smtpHint($code));
    }
    return $response;
}

/** Turns the commonest Brevo/SMTP rejection codes into an actionable sentence. */
function smtpHint(int $code): string {
    switch ($code) {
        case 535:
            return '[Authentication failed — SMTP_USERNAME/SMTP_PASSWORD in includes/config.php do not match a '
                 . 'Brevo SMTP key. In Brevo: SMTP & API → SMTP tab. The password is the long "xsmtpsib-..." key, '
                 . 'not your account password.]';
        case 501:
        case 550:
        case 553:
            return '[Usually the sender address: SMTP_FROM_EMAIL must be an address you have verified in Brevo '
                 . '(Senders, Domains & Dedicated IPs → Senders). It does not have to match SMTP_USERNAME.]';
        case 554:
            return '[The message or sender was refused by the relay — check that your Brevo account is activated '
                 . 'and not still pending review; new accounts are sometimes held before the first send.]';
        default:
            return '';
    }
}

function smtpBuildMessage(string $to, string $subject, string $body): string {
    $fromName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'Legislative Agenda & Calendar Management System';
    $encodedSubject = function_exists('mb_encode_mimeheader')
        ? mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n")
        : $subject;

    $headers = [
        'From: ' . $fromName . ' <' . SMTP_FROM_EMAIL . '>',
        'To: <' . $to . '>',
        'Subject: ' . $encodedSubject,
        'Date: ' . date('r'),
        'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . smtpLocalDomain() . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];

    // Dot-stuffing (RFC 5321 §4.5.2).
    $normalizedBody = str_replace(["\r\n", "\r"], "\n", $body);
    $lines = explode("\n", $normalizedBody);
    $stuffed = array_map(fn($line) => str_starts_with($line, '.') ? '.' . $line : $line, $lines);

    return implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $stuffed);
}

function writeEmailDebugFile(string $to, string $subject, string $body, string $timestamp, bool $success, string $note): void {
    $content = "<?php\n"
        . "/**\n"
        . " * DEBUG FILE — auto-generated by includes/mailer.php, overwritten on\n"
        . " * every email attempt. Never required/executed by the app — safe to\n"
        . " * delete, will be recreated on the next send.\n"
        . " *\n"
        . " * To:      $to\n"
        . " * Subject: $subject\n"
        . " * Sent at: $timestamp\n"
        . " * Result:  " . ($success ? 'SUCCESS' : 'FAILED') . " — $note\n"
        . " *\n"
        . " * --- Body ---\n"
        . " * " . str_replace("\n", "\n * ", $body) . "\n"
        . " */\n";

    @file_put_contents(__DIR__ . '/debug_last_email.php', $content);
}
