<?php
declare(strict_types=1);

namespace JC\Domain;

/**
 * Envoi transactionnel via l'API Brevo (ex-Sendinblue).
 * Clé et expéditeur : réglages brevo_api_key / brevo_sender_email / brevo_sender_name (table app_settings, comme smtp_*).
 */
final class BrevoClient
{
    public function __construct(private string $apiKey, private string $senderEmail, private string $senderName)
    {
    }

    public function configured(): bool
    {
        return $this->apiKey !== '' && $this->senderEmail !== '';
    }

    /** @throws \RuntimeException clé absente, connexion impossible, ou Brevo refuse l'envoi */
    public function send(string $to, string $subject, string $html): bool
    {
        if (!$this->configured()) {
            throw new \RuntimeException('Brevo non configuré (clé API ou adresse expéditeur manquante).');
        }
        $payload = [
            'sender'      => ['email' => $this->senderEmail, 'name' => $this->senderName ?: 'Journée Circuit'],
            'to'          => [['email' => $to]],
            'subject'     => $subject,
            'htmlContent' => $html,
        ];
        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['api-key: ' . $this->apiKey, 'Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('Connexion Brevo impossible : ' . $err);
        }
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status >= 400) {
            throw new \RuntimeException('Brevo a répondu HTTP ' . $status . ' : ' . substr((string)$body, 0, 300));
        }
        return true;
    }
}
