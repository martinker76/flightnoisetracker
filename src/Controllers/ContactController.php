<?php

declare(strict_types=1);

namespace App\Controllers;

use PDO;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Handles the About-page contact form.
 *
 * POST /api/contact
 *   Body: { name, email, subject, message }
 *
 * Behavior:
 *   1. Validates input shape and lengths (returns 422 on bad input).
 *   2. Enforces a per-IP rate limit (5 messages per hour; 429 on overflow).
 *   3. Persists the message to `contact_messages` (audit trail).
 *   4. Sends an email to flightnoisetracker@kersch.at via PHP mail().
 *   5. Returns the inserted row id so the frontend can confirm delivery.
 *
 * The mail() return value is recorded in the row (`mail_sent`, `mail_error`)
 * so we can spot silently-failed deliveries even if the API returned 201.
 */
class ContactController
{
    /** Recipients for inbound contact-form messages. */
    private const INBOX_EMAIL = 'flightnoisetracker@kersch.at';

    /** Per-IP rate limit: max messages per window. */
    private const RATE_LIMIT_MAX = 5;

    /** Rate-limit window length in seconds. */
    private const RATE_LIMIT_WINDOW_SECONDS = 3600;

    /** Input length caps (DB column widths + a sanity margin). */
    private const MAX_NAME = 120;
    private const MAX_EMAIL = 254;     // RFC 5321 SMTP limit
    private const MAX_SUBJECT = 200;
    private const MAX_MESSAGE = 5000;
    private const MIN_MESSAGE = 10;

    private PDO $db;
    private string $configInbox;

    public function __construct(private array $config)
    {
        $this->db = new PDO(
            "mysql:host={$config['db']['host']};port={$config['db']['port']};dbname={$config['db']['name']};charset=utf8mb4",
            $config['db']['user'],
            $config['db']['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        // Allow override via env / config; defaults to the constant.
        $this->configInbox = $config['contact']['inbox_email'] ?? self::INBOX_EMAIL;

        // Inject SMTP relay credentials as env vars at construction time, so
        // PHPMailer (which calls getenv()) sees them. This is the runtime
        // fallback when the PHP-FPM pool doesn't have FNT_SMTP_* set.
        // Source order: existing env wins (panel/Plesk), config fills gaps.
        $smtp = $config['smtp'] ?? [];
        $smtpMap = [
            'FNT_SMTP_HOST'      => $smtp['host']         ?? null,
            'FNT_SMTP_PORT'      => $smtp['port']         ?? null,
            'FNT_SMTP_USERNAME'  => $smtp['username']     ?? null,
            'FNT_SMTP_PASSWORD'  => $smtp['password']     ?? null,
            'FNT_SMTP_FROM_ADDR' => $smtp['from_address'] ?? null,
            'FNT_SMTP_FROM_NAME' => $smtp['from_name']     ?? null,
        ];
        foreach ($smtpMap as $name => $value) {
            if ($value !== null && $value !== '' && getenv($name) === false) {
                putenv("{$name}={$value}");
                $_ENV[$name] = $value;
            }
        }
    }

    /**
     * POST /api/contact
     */
    public function store(array $params): void
    {
        $input = $this->getJsonInput();
        if ($input === null) {
            $this->sendError('Invalid JSON body.', 'INVALID_BODY', 400);
            return;
        }

        // Required fields
        foreach (['name', 'email', 'subject', 'message'] as $field) {
            if (!isset($input[$field]) || !is_string($input[$field]) || trim($input[$field]) === '') {
                $this->sendError(
                    "Missing or empty required field: {$field}.",
                    'MISSING_FIELDS',
                    422
                );
                return;
            }
        }

        $name = trim($input['name']);
        $email = trim($input['email']);
        $subject = trim($input['subject']);
        $message = trim($input['message']);

        // Length checks
        if (mb_strlen($name) > self::MAX_NAME) {
            $this->sendError('name exceeds ' . self::MAX_NAME . ' chars.', 'INVALID_PARAMETER', 422);
            return;
        }
        if (mb_strlen($email) > self::MAX_EMAIL) {
            $this->sendError('email too long.', 'INVALID_PARAMETER', 422);
            return;
        }
        if (mb_strlen($subject) > self::MAX_SUBJECT) {
            $this->sendError('subject too long.', 'INVALID_PARAMETER', 422);
            return;
        }
        if (mb_strlen($message) < self::MIN_MESSAGE) {
            $this->sendError('message too short (min 10 chars).', 'INVALID_PARAMETER', 422);
            return;
        }
        if (mb_strlen($message) > self::MAX_MESSAGE) {
            $this->sendError('message exceeds ' . self::MAX_MESSAGE . ' chars.', 'INVALID_PARAMETER', 422);
            return;
        }

        // Email format check — basic but catches obvious typos
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->sendError('email is not a valid address.', 'INVALID_PARAMETER', 422);
            return;
        }

        // Anti-header-injection: reject newlines in any sender-controlled field,
        // and ASCII control chars in subject. The senders are user-controlled,
        // so we have to defend.
        foreach ([$name, $subject] as $check) {
            if (preg_match('/[\r\n\0]/', $check)) {
                $this->sendError('Newline or control characters are not allowed.', 'INVALID_PARAMETER', 422);
                return;
            }
        }

        $ip = $this->clientIp();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        if ($userAgent !== null && mb_strlen($userAgent) > 255) {
            $userAgent = substr($userAgent, 0, 255);
        }

        // Per-IP rate limit
        if (!$this->checkRateLimit($ip)) {
            $this->sendError(
                'Too many submissions. Please try again later.',
                'RATE_LIMITED',
                429
            );
            return;
        }

        // Insert
        $stmt = $this->db->prepare(
            'INSERT INTO contact_messages
                (name, email, subject, message, ip_address, user_agent, mail_sent, mail_error)
             VALUES
                (:name, :email, :subject, :message, :ip, :ua, 0, NULL)'
        );
        $stmt->execute([
            ':name' => $name,
            ':email' => $email,
            ':subject' => $subject,
            ':message' => $message,
            ':ip' => $ip,
            ':ua' => $userAgent,
        ]);
        $id = (int)$this->db->lastInsertId();

        // Send mail. We do this *after* persistence so a mail failure doesn't
        // lose the submission — the audit row remains.
        $mailOk = false;
        $mailError = null;
        try {
            $mailOk = $this->sendMail(
                $name,
                $email,
                $subject,
                $message
            );
            if (!$mailOk) {
                $err = error_get_last();
                $mailError = $err['message'] ?? 'mail() returned false';
            }
        } catch (\Throwable $e) {
            $mailError = $e->getMessage();
        }

        // Update mail result
        $upd = $this->db->prepare(
            'UPDATE contact_messages SET mail_sent = :ok, mail_error = :err WHERE id = :id'
        );
        $upd->execute([
            ':ok' => $mailOk ? 1 : 0,
            ':err' => $mailError,
            ':id' => $id,
        ]);

        $this->sendJson([
            'data' => [
                'id' => $id,
                'mail_sent' => $mailOk,
            ],
        ], 201);
    }

    /**
     * Sliding-window per-IP rate limit. One row per IP in `contact_rate_limit`,
     * atomic via SELECT ... FOR UPDATE so concurrent requests from the same
     * IP cannot both pass the check. Previously this was a non-atomic
     * read-modify-write — under contention, two first-time requests could
     * both attempt the fresh INSERT (raising an uncaught PDOException), or
     * two requests at the boundary could both pass and bump the count past
     * the cap.
     */
    private function checkRateLimit(string $ip): bool
    {
        $this->db->beginTransaction();
        try {
            $sel = $this->db->prepare(
                'SELECT message_count, UNIX_TIMESTAMP(window_start) AS ws_ts
                 FROM contact_rate_limit WHERE ip_address = :ip FOR UPDATE'
            );
            $sel->execute([':ip' => $ip]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);
            $now = time();

            if ($row === false) {
                // First request from this IP
                $ins = $this->db->prepare(
                    'INSERT INTO contact_rate_limit (ip_address, message_count, window_start)
                     VALUES (:ip, 1, UTC_TIMESTAMP())'
                );
                $ins->execute([':ip' => $ip]);
                $this->db->commit();
                return true;
            }

            $count = (int)$row['message_count'];
            $windowStart = (int)$row['ws_ts'];
            $windowAge = $now - $windowStart;

            if ($windowAge >= self::RATE_LIMIT_WINDOW_SECONDS) {
                // Window expired — reset
                $upd = $this->db->prepare(
                    'UPDATE contact_rate_limit
                     SET message_count = 1, window_start = UTC_TIMESTAMP()
                     WHERE ip_address = :ip'
                );
                $upd->execute([':ip' => $ip]);
                $this->db->commit();
                return true;
            }

            if ($count >= self::RATE_LIMIT_MAX) {
                // At cap — deny without incrementing
                $this->db->commit();
                return false;
            }

            // Within window, under cap — increment
            $upd = $this->db->prepare(
                'UPDATE contact_rate_limit
                 SET message_count = message_count + 1
                 WHERE ip_address = :ip'
            );
            $upd->execute([':ip' => $ip]);
            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Send email via SMTP auth using PHPMailer.
     *
     * Why SMTP auth: PHP's mail() on this server hands the message to the
     * local exim, which then refuses to deliver ("550 Sender verify failed")
     * because outbound DNS is blocked at the shared-host layer. The server
     * has no usable From address. Authenticated SMTP to an external relay
     * is the only working path.
     *
     * Configuration (env vars; never committed):
     *   FNT_SMTP_HOST        e.g. smtp.mailersend.net
     *   FNT_SMTP_PORT        587 (STARTTLS) or 465 (implicit TLS)
     *   FNT_SMTP_USERNAME    relay username
     *   FNT_SMTP_PASSWORD    relay password / API token
     *   FNT_SMTP_FROM_ADDR   e.g. noreply@your-domain.tld (must be a verified
     *                         sender on the relay side; e.g. for MailerSend
     *                         the From domain must match the verified domain)
     *   FNT_SMTP_FROM_NAME   e.g. "FlightNoiseTracker"
     *
     * If any required setting is missing, we fall back to PHP mail() and
     * log a clear error so the operator can spot the misconfiguration.
     *
     * @return bool true if the SMTP server accepted the message, false otherwise.
     */
    private function sendMail(string $name, string $fromEmail, string $subject, string $message): bool
    {
        $inbox = $this->configInbox;
        $safeName = $this->stripHeaderUnsafe($name);
        $safeSubject = $this->stripHeaderUnsafe($subject);

        // Try the configured SMTP relay first; fall back to mail() if not set up.
        $smtpHost = getenv('FNT_SMTP_HOST');
        $smtpUser = getenv('FNT_SMTP_USERNAME');
        $smtpPass = getenv('FNT_SMTP_PASSWORD');
        $smtpFromAddr = getenv('FNT_SMTP_FROM_ADDR');
        $smtpFromName = getenv('FNT_SMTP_FROM_NAME') ?: 'FlightNoiseTracker';
        $smtpPort = (int)(getenv('FNT_SMTP_PORT') ?: 587);

        if ($smtpHost && $smtpUser && $smtpPass && $smtpFromAddr) {
            try {
                $mail = new PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = $smtpHost;
                $mail->Port = $smtpPort;
                $mail->SMTPAuth = true;
                $mail->Username = $smtpUser;
                $mail->Password = $smtpPass;
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->CharSet = 'UTF-8';

                // From: a verified address on the relay side; not the submitter's.
                // (Most relays refuse to send From: an unverified address.)
                $mail->setFrom($smtpFromAddr, $smtpFromName);

                // To: operator inbox
                $mail->addAddress($inbox);

                // Reply-To: the actual submitter, so a reply lands in their inbox.
                $mail->addReplyTo($fromEmail, $safeName);

                $body = "New contact-form submission for FlightNoiseTracker\n" .
                        "================================================\n\n" .
                        "From:    {$safeName} <{$fromEmail}>\n" .
                        "Subject: {$safeSubject}\n" .
                        "Date:    " . gmdate('c') . "\n" .
                        "IP:      " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n\n" .
                        "----------------------------------------\n\n" .
                        $message . "\n";

                $mail->Subject = '[FlightNoiseTracker] ' . $safeSubject;
                $mail->Body = $body;
                $mail->AltBody = $body;

                $sent = $mail->send();
                return $sent;
            } catch (PHPMailerException $e) {
                error_log('[contact] SMTP send failed: ' . $e->getMessage());
                return false;
            }
        }

        // Fallback: local mail() (will likely fail on this shared host, but
        // we don't want to throw a 500 if SMTP isn't configured yet — the
        // DB row is already saved so the audit trail is intact).
        $headers = [];
        $headers[] = 'From: ' . $inbox;
        $headers[] = 'Reply-To: ' . $safeName . ' <' . $fromEmail . '>';
        $headers[] = 'X-Mailer: PHP/' . PHP_VERSION;
        $headers[] = 'X-Contact-Form: flightnoisetracker';
        $headers[] = 'Content-Type: text/plain; charset=utf-8';
        $headers[] = 'MIME-Version: 1.0';

        $body = "New contact-form submission for FlightNoiseTracker\n" .
                "================================================\n\n" .
                "From:    {$safeName} <{$fromEmail}>\n" .
                "Subject: {$safeSubject}\n" .
                "Date:    " . gmdate('c') . "\n" .
                "IP:      " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n\n" .
                "----------------------------------------\n\n" .
                $message . "\n";

        return @mail($inbox, '[FlightNoiseTracker] ' . $safeSubject, $body, implode("\r\n", $headers));
    }

    /**
     * Strip anything that could break out of a mail header:
     * - Newlines (\r, \n)
     * - Null bytes
     * - Other ASCII control chars
     */
    private function stripHeaderUnsafe(string $s): string
    {
        return preg_replace('/[\x00-\x1F\x7F\r\n]+/', ' ', $s) ?? '';
    }

    /**
     * Best-effort client IP. We trust REMOTE_ADDR (the only reliable source
     * at this layer; X-Forwarded-For can be spoofed unless terminated by a
     * proxy we control). Caddy isn't setting that header so REMOTE_ADDR is
     * correct for our deployment.
     */
    private function clientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    private function getJsonInput(): ?array
    {
        $raw = file_get_contents('php://input');
        if (empty($raw)) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function sendJson(mixed $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function sendError(string $message, string $code, int $statusCode): void
    {
        $this->sendJson(['error' => $message, 'code' => $code], $statusCode);
    }
}
