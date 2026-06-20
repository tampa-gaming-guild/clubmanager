<?php
namespace App;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use Exception;

/**
 * Mail Helper Class
 * Wraps PHPMailer with dynamic configuration from environment variables.
 */
class MailHelper {
    /**
     * Send an email using PHPMailer configured from environment variables.
     * 
     * @param string $to Recipient email address
     * @param string $subject Email subject
     * @param string $body Email HTML content
     * @param string $plainTextBody Optional plain text alternative
     * @param int|null $recipientId Optional member/contact ID of the recipient
     * @param int|null $senderId Optional member/contact ID of the sender (null/0 for system)
     * @return bool True on success
     * @throws Exception
     */
    public static function send(string $to, string $subject, string $body, string $plainTextBody = '', ?int $recipientId = null, ?int $senderId = null): bool {
        $mail = new PHPMailer(true);

        try {
            // Server settings
            $mail->isSMTP();
            
            // Resolve settings from $_ENV or default to local Mailpit configuration
            $host = $_ENV['SMTP_HOST'] ?? '127.0.0.1';
            $port = (int)($_ENV['SMTP_PORT'] ?? 1025);
            $user = $_ENV['SMTP_USER'] ?? '';
            $pass = $_ENV['SMTP_PASS'] ?? '';
            $secure = $_ENV['SMTP_SECURE'] ?? ''; // 'tls', 'ssl', or empty/none

            $mail->Host       = $host;
            $mail->Port       = $port;
            
            // SMTP Authentication
            if (!empty($user)) {
                $mail->SMTPAuth   = true;
                $mail->Username   = $user;
                $mail->Password   = $pass;
            } else {
                $mail->SMTPAuth   = false;
            }

            // Encryption Settings
            $secureLower = strtolower($secure);
            if ($secureLower === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($secureLower === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false; // Prevent auto-TLS connection issues with local mail catchers
            }

            // Sender Details
            $fromAddress = $_ENV['MAIL_FROM_ADDRESS'] ?? 'no-reply@tgg.test';
            $fromName    = $_ENV['MAIL_FROM_NAME'] ?? 'TGG Membership';
            $mail->setFrom($fromAddress, $fromName);
            $mail->addAddress($to);

            // Content Format
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            
            if (!empty($plainTextBody)) {
                $mail->AltBody = $plainTextBody;
            } else {
                $mail->AltBody = strip_tags($body);
            }

            $sent = $mail->send();
            if ($sent) {
                try {
                    $appDb = Database::getAppConnection();
                    $stmt = $appDb->prepare("
                        INSERT INTO tgg_email_log (recipient_id, sender_id, recipient, subject, body)
                        VALUES (:recipient_id, :sender_id, :recipient, :subject, :body)
                    ");
                    $stmt->execute([
                        'recipient_id' => $recipientId,
                        'sender_id' => $senderId,
                        'recipient' => $to,
                        'subject' => $subject,
                        'body' => $body
                    ]);
                } catch (Exception $logEx) {
                    error_log("Failed to log email to tgg_email_log: " . $logEx->getMessage());
                }
            }
            return $sent;
        } catch (PHPMailerException $e) {
            throw new Exception("Mailer Error: " . $mail->ErrorInfo);
        } catch (Exception $e) {
            throw new Exception("Mail Config Error: " . $e->getMessage());
        }
    }

    /**
     * Send an email using a database-stored template.
     * 
     * @param string $to Recipient email address
     * @param string $templateKey Database key for the template
     * @param array $placeholders Key-value pairs of placeholders and their replacement values
     * @param int|null $recipientId Optional member/contact ID of the recipient
     * @param int|null $senderId Optional member/contact ID of the sender (null/0 for system)
     * @return bool True on success
     * @throws Exception
     */
    public static function sendTemplate(string $to, string $templateKey, array $placeholders = [], ?int $recipientId = null, ?int $senderId = null): bool {
        $appDb = Database::getAppConnection();
        $stmt = $appDb->prepare("SELECT subject, body FROM tgg_email_templates WHERE template_key = :key LIMIT 1");
        $stmt->execute(['key' => $templateKey]);
        $template = $stmt->fetch();

        if (!$template) {
            throw new Exception("Email template '{$templateKey}' not found in database.");
        }

        $subject = $template['subject'];
        $body = $template['body'];

        // Replace placeholders in subject and body. Values are escaped since the
        // body is sent as HTML and some placeholders (e.g. display_name) come
        // from user-editable profile data.
        foreach ($placeholders as $key => $val) {
            $placeholder = '{' . $key . '}';
            $subject = str_replace($placeholder, htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8'), $subject);
            $body = str_replace($placeholder, htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8'), $body);
        }

        return self::send($to, $subject, $body, '', $recipientId, $senderId);
    }
}
