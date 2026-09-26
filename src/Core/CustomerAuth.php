<?php
declare(strict_types=1);

namespace JC\Core;

/**
 * Session « mon espace » participant (table customer_accounts, partagée avec l'ancien portail).
 * Indépendante de Auth (comptes staff) : un participant n'a jamais de rôle ni d'accès au back-office.
 */
final class CustomerAuth
{
    public static function user(): ?array
    {
        $u = $_SESSION['customer'] ?? null;
        return is_array($u) ? $u : null;
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u ? (int)$u['id'] : null;
    }

    /** @param array $row ligne customer_accounts (le hash est retiré de la session) */
    public static function login(array $row): void
    {
        unset($row['password_hash']);
        session_regenerate_id(true);
        $_SESSION['customer'] = $row;
    }

    public static function logout(): void
    {
        unset($_SESSION['customer']);
        session_regenerate_id(true);
    }
}
