<?php
declare(strict_types=1);

namespace JC\Domain;

use JC\Core\App;
use JC\Core\Db;
use JC\Core\Logger;
use JC\Core\Mode;

/**
 * Envoi d'e-mails avec les réglages de l'ancien portail (app_settings : mail_enabled, mail_from, mail_transport, smtp_*).
 * Aucun envoi en lecture seule : un e-mail est un effet réel qu'on ne peut pas annuler.
 */
final class Mailer
{
    private static function clean(string $s): string
    {
        return trim(str_replace(["\r", "\n", "\0"], ' ', $s));
    }

    public static function enabled(Db $db): bool
    {
        return Settings::get($db, 'mail_enabled', '0') === '1' || (string)App::config('mail_capture', '') !== '';
    }

    public static function send(Db $db, string $to, string $subject, string $html): bool
    {
        Mode::assertWritable();
        $to = self::clean($to);
        $subject = self::clean($subject);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            Logger::error('mail', 'adresse invalide : ' . $to);
            return false;
        }
        // Tests automatisés : les messages sont écrits dans un fichier au lieu d'être envoyés.
        $capture = (string)App::config('mail_capture', '');
        if ($capture !== '') {
            file_put_contents($capture, json_encode(['to' => $to, 'subject' => $subject, 'html' => $html], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
            return true;
        }
        if (Settings::get($db, 'mail_enabled', '0') !== '1') {
            Logger::info('mail', 'MAIL DÉSACTIVÉ (mail_enabled) : ' . $to . ' — ' . $subject);
            return false;
        }
        $from = self::clean(Settings::get($db, 'mail_from', 'documents@journeecircuit.fr'));
        $fromName = self::clean(Settings::get($db, 'mail_from_name', 'Journée Circuit'));
        $transport = Settings::get($db, 'mail_transport', 'php');

        if ($transport === 'smtp') {
            require_once App::path('vendor/autoload.php');
            if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
                Logger::error('mail', 'SMTP demandé mais PHPMailer est absent.');
                return false;
            }
            try {
                $m = new \PHPMailer\PHPMailer\PHPMailer(true);
                $m->isSMTP();
                $m->Host = Settings::get($db, 'smtp_host');
                $m->Port = (int)Settings::get($db, 'smtp_port', '587');
                $m->SMTPAuth = true;
                $m->Username = Settings::get($db, 'smtp_user');
                $m->Password = Settings::get($db, 'smtp_password');
                $sec = Settings::get($db, 'smtp_security', 'tls');
                if ($sec === 'tls') {
                    $m->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                } elseif ($sec === 'ssl') {
                    $m->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                } else {
                    $m->SMTPSecure = '';
                }
                $m->CharSet = 'UTF-8';
                $m->setFrom($from, $fromName);
                $m->addAddress($to);
                $m->isHTML(true);
                $m->Subject = $subject;
                $m->Body = $html;
                $m->AltBody = trim(preg_replace('/\s+/', ' ', strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $html))) ?? '');
                return $m->send();
            } catch (\Throwable $e) {
                Logger::error('mail', 'SMTP : ' . $e->getMessage());
                return false;
            }
        }

        if ($transport === 'brevo') {
            $apiKey = Settings::get($db, 'brevo_api_key');
            $sender = Settings::get($db, 'brevo_sender_email', $from);
            $senderName = Settings::get($db, 'brevo_sender_name', $fromName);
            try {
                return (new BrevoClient($apiKey, $sender, $senderName))->send($to, $subject, $html);
            } catch (\Throwable $e) {
                Logger::error('mail', 'Brevo : ' . $e->getMessage());
                return false;
            }
        }

        $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: " . mb_encode_mimeheader($fromName, 'UTF-8', 'B') . ' <' . $from . ">\r\n";
        return @mail($to, mb_encode_mimeheader($subject, 'UTF-8', 'B'), $html, $headers);
    }
}
