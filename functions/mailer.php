<?php
/*
 * mailer.php — Sends a 6-digit verification code to the user during registration.
 * Supports three modes in order of priority:
 *   1. MAIL_DEV_MODE = true  → writes the code to functions/dev_codes.log
 *   2. PHPMailer (if vendor/autoload.php exists) → SMTP authentication
 *   3. Native PHP mail() as a fallback
 */

require_once __DIR__ . '/email_config.php';

function send_verification_code(string $to_email, string $to_name, string $code): bool
{
    $minutes = CODE_LIFETIME_MINUTES;
    $subject = 'Confirm your registration — Parkster';

    $html = build_verification_email_html($to_name, $code, $minutes);
    $text = build_verification_email_text($to_name, $code, $minutes);

    if (defined('MAIL_DEV_MODE') && MAIL_DEV_MODE === true) {
        return write_dev_code_log($to_email, $code);
    }

    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
        if (class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
            return send_with_phpmailer($to_email, $to_name, $subject, $html, $text);
        }
    }

    return send_with_native_mail($to_email, $subject, $html);
}

function build_verification_email_html(string $name, string $code, int $minutes): string
{
    $name_safe = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $code_safe = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;background:#f4f6f8;">
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f6f8;padding:30px 0;">
    <tr><td align="center">
      <table width="480" cellpadding="0" cellspacing="0" border="0"
             style="max-width:480px;width:100%;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 4px 16px rgba(0,0,0,.08);">
        <tr>
          <td style="background:linear-gradient(135deg,#1cc7d0,#159ca3);padding:28px;text-align:center;color:#fff;">
            <div style="font-size:26px;font-weight:700;letter-spacing:3px;">PARKSTER</div>
            <div style="font-size:12px;opacity:.9;letter-spacing:1.5px;margin-top:4px;">SMART PARKING SYSTEM</div>
          </td>
        </tr>
        <tr>
          <td style="padding:36px 32px;color:#333;">
            <h2 style="margin:0 0 14px;font-size:20px;color:#1a1a1a;">Welcome, {$name_safe}!</h2>
            <p style="margin:0 0 18px;line-height:1.55;color:#555;font-size:14px;">
              Thank you for creating a Parkster account. To complete your registration,
              enter the verification code below on the verification page:
            </p>
            <div style="margin:26px 0;text-align:center;">
              <div style="display:inline-block;font-family:'Courier New',monospace;font-size:38px;letter-spacing:12px;
                          font-weight:700;color:#1cc7d0;background:#f3f8fa;padding:20px 32px;border-radius:12px;
                          border:2px dashed #1cc7d0;">{$code_safe}</div>
            </div>
            <p style="margin:0 0 8px;color:#666;font-size:13px;line-height:1.55;">
              ⏱️ This code expires in <strong>{$minutes} minutes</strong>.
            </p>
            <p style="margin:24px 0 0;padding-top:18px;border-top:1px solid #eee;color:#888;font-size:12px;line-height:1.5;">
              If you did not request this registration, you can safely ignore this email — no account will be created.
              Never share this code with anyone.
            </p>
          </td>
        </tr>
        <tr>
          <td style="background:#fafbfc;padding:14px;text-align:center;color:#9aa0a6;font-size:11px;">
            © Parkster — This is an automated email, please do not reply.
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}

function build_verification_email_text(string $name, string $code, int $minutes): string
{
    return "Welcome $name,\n\n"
         . "Your Parkster verification code is: $code\n"
         . "This code expires in $minutes minutes.\n\n"
         . "If you did not request this, please ignore this email.\n\n"
         . "— Parkster Security";
}

function send_with_phpmailer(string $to, string $to_name, string $subject, string $html, string $text): bool
{
    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = (SMTP_SECURE === 'ssl')
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ];

        $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
        $mail->addAddress($to, $to_name);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = $text;

        return $mail->send();
    } catch (\Throwable $e) {
        error_log('PHPMailer error: ' . $e->getMessage());
        return false;
    }
}

function send_with_native_mail(string $to, string $subject, string $html): bool
{
    $headers   = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/html; charset=UTF-8';
    $headers[] = 'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM_EMAIL . '>';
    $headers[] = 'Reply-To: ' . MAIL_FROM_EMAIL;
    $headers[] = 'X-Mailer: PHP/' . phpversion();

    return @mail($to, $subject, $html, implode("\r\n", $headers));
}

function write_dev_code_log(string $email, string $code): bool
{
    $log_path = __DIR__ . '/dev_codes.log';
    $line = sprintf(
        "[%s] DEV MODE — To: %s | Code: %s | (Set MAIL_DEV_MODE=false in email_config.php for real sending)\n",
        date('Y-m-d H:i:s'), $email, $code
    );
    return (bool) @file_put_contents($log_path, $line, FILE_APPEND);
}