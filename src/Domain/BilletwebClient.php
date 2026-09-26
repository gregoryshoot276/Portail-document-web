<?php
declare(strict_types=1);

namespace JC\Domain;

use JC\Core\App;
use JC\Core\Db;

/**
 * Client de l'API Billetweb (authentification Basic).
 * Identifiant et clé : réglages billetweb_user / billetweb_key de l'ancien portail (jamais transmis à l'assistant, jamais affichés).
 */
final class BilletwebClient
{
    public function __construct(private Db $db)
    {
    }

    public function configured(): bool
    {
        return Settings::get($this->db, 'billetweb_user') !== '' && Settings::get($this->db, 'billetweb_key') !== '';
    }

    /** @throws \RuntimeException */
    public function request(string $path, array $query = []): array
    {
        $user = Settings::get($this->db, 'billetweb_user');
        $key = Settings::get($this->db, 'billetweb_key');
        if ($user === '' || $key === '') {
            throw new \RuntimeException('Identifiants Billetweb non configurés (réglages de l\'ancien portail).');
        }
        $base = rtrim((string)App::config('billetweb_base', 'https://www.billetweb.fr'), '/');
        $url = $base . $path . ($query ? '?' . http_build_query($query) : '');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Basic ' . base64_encode($user . ':' . $key), 'Accept: application/json'],
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
            throw new \RuntimeException('Connexion Billetweb impossible : ' . $err);
        }
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status >= 400) {
            throw new \RuntimeException('Billetweb a répondu HTTP ' . $status . '.');
        }
        $data = json_decode((string)$body, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Réponse Billetweb invalide.');
        }
        return $data;
    }
}
