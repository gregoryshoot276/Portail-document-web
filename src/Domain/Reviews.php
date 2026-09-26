<?php
declare(strict_types=1);

namespace JC\Domain;

use JC\Core\Db;
use JC\Core\Files;

/** Avis Google/Facebook contre code promo (reprise de l'outil « avis.php » de l'ancien portail, même principe :
 * capture d'avis + validation humaine + code promo, mais adossé aux participants/événements de ce portail-ci. */
final class Reviews
{
    public const PLATFORMS = ['google', 'facebook'];

    public static function ensureTable(Db $db): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $db->run("CREATE TABLE IF NOT EXISTS review_requests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            event_id BIGINT UNSIGNED NULL,
            participant_id BIGINT UNSIGNED NULL,
            nom VARCHAR(190) NOT NULL,
            prenom VARCHAR(190) NOT NULL,
            email VARCHAR(190) NOT NULL,
            platform ENUM('google','facebook') NOT NULL,
            public_token VARCHAR(64) NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'pending',
            screenshot_name VARCHAR(255) NOT NULL,
            screenshot_stored VARCHAR(255) NOT NULL,
            screenshot_mime VARCHAR(100) NOT NULL,
            reward_amount DECIMAL(8,2) NULL,
            promo_code VARCHAR(32) NULL,
            rejection_reason TEXT NULL,
            mail_status VARCHAR(32) NULL,
            mail_error VARCHAR(500) NULL,
            processed_by INT UNSIGNED NULL,
            processed_at DATETIME NULL,
            billetweb_created TINYINT(1) NOT NULL DEFAULT 0,
            billetweb_created_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_review_code (promo_code),
            UNIQUE KEY uq_review_token (public_token),
            KEY idx_review_status (status),
            KEY idx_review_person (email, platform)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $done = true;
    }

    public static function generateCode(Db $db): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        for ($try = 0; $try < 30; $try++) {
            $c = 'JC';
            for ($i = 0; $i < 6; $i++) {
                $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            if (!$db->val('SELECT 1 FROM review_requests WHERE promo_code=?', [$c])) {
                return $c;
            }
        }
        throw new \RuntimeException('Impossible de générer un code unique.');
    }

    /** @return array{0:string,1:string,2:string} [nom stocké, mime, nom d'origine] */
    public static function storeUpload(array $f): array
    {
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Merci de joindre une capture de votre avis.');
        }
        if ((int)($f['size'] ?? 0) > 8 * 1024 * 1024) {
            throw new \RuntimeException('Capture trop volumineuse (8 Mo maximum).');
        }
        $mime = (string)(new \finfo(FILEINFO_MIME_TYPE))->file((string)$f['tmp_name']);
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'][$mime] ?? null;
        if (!$ext) {
            throw new \RuntimeException('Format accepté : JPG, PNG, WEBP ou PDF.');
        }
        $stored = bin2hex(random_bytes(24)) . '.' . $ext;
        if (!move_uploaded_file((string)$f['tmp_name'], Files::dir('review') . '/' . $stored)) {
            throw new \RuntimeException('Enregistrement de la capture impossible.');
        }
        return [$stored, $mime, basename((string)$f['name'])];
    }
}
