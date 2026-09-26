<?php
declare(strict_types=1);

namespace JC\Core;

/**
 * Authentification et droits.
 * Utilise la table existante `admins` : mêmes comptes et mêmes mots de passe que l'ancien portail.
 */
final class Auth
{
    /** Droits par rôle. '*' = tout, '-x' = tout sauf x. */
    public const ROLES = [
        'super_admin'  => ['*'],
        'admin'        => ['*', '-settings'],
        'starter'      => ['starter'],
        'timing'       => ['stats', 'rfid.view', 'rfid.edit'],
        'read_only'    => ['dossiers.view', 'files.view'],
        'photographer' => ['photo.view'],
        'staff_view'   => [],
    ];

    public const ROLE_LABELS = [
        'super_admin'  => 'Super administrateur',
        'admin'        => 'Administrateur',
        'starter'      => 'Starter',
        'timing'       => 'Chronométrage',
        'read_only'    => 'Lecture seule',
        'photographer' => 'Photographe',
        'staff_view'   => 'Staff',
    ];

    public static function boot(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $idle = (int)App::config('session_idle_minutes', 240) * 60;
        session_name((string)App::config('session_name', 'jc3_session'));
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        // Sans ceci, le nettoyage natif de PHP (session.gc_maxlifetime, 24 min par défaut chez la plupart des
        // hébergeurs) peut supprimer le fichier de session bien avant notre propre délai d'inactivité ci-dessous —
        // un participant qui met du temps à remplir la décharge se retrouverait avec un jeton CSRF périmé.
        ini_set('session.gc_maxlifetime', (string)$idle);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        if (isset($_SESSION['last_seen']) && time() - (int)$_SESSION['last_seen'] > $idle) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['last_seen'] = time();
    }

    public static function user(): ?array
    {
        $u = $_SESSION['admin'] ?? null;
        if (!is_array($u)) {
            return null;
        }
        // Revalidation périodique : compte désactivé ou rôle modifié depuis l'ouverture de session.
        if (time() - (int)($_SESSION['admin_checked'] ?? 0) > 300) {
            try {
                $row = App::db()->row('SELECT id,display_name,role,email,is_active FROM admins WHERE id=?', [(int)$u['id']]);
                if (!$row || (int)$row['is_active'] !== 1) {
                    self::logout();
                    return null;
                }
                $_SESSION['admin'] = self::sessionUser($row);
                $_SESSION['admin_checked'] = time();
                $u = $_SESSION['admin'];
            } catch (\Throwable $e) {
                Logger::error('auth', 'revalidation impossible : ' . $e->getMessage());
            }
        }
        return $u;
    }

    private static function sessionUser(array $row): array
    {
        return [
            'id'    => (int)$row['id'],
            'email' => (string)$row['email'],
            'name'  => (string)$row['display_name'],
            'role'  => (string)($row['role'] ?: 'read_only'),
        ];
    }

    /** Retourne un message d'erreur, ou null si la connexion réussit. */
    public static function attempt(string $email, string $password): ?string
    {
        $email = strtolower(trim($email));
        $ip = Http::ip();
        $keys = ['ip:' . $ip, 'acct:' . $email];
        foreach ($keys as $k) {
            $wait = Throttle::lockedFor($k);
            if ($wait > 0) {
                return 'Trop de tentatives. Réessayez dans ' . (int)ceil($wait / 60) . ' minute(s).';
            }
        }
        $row = $email !== '' ? App::db()->row('SELECT * FROM admins WHERE email=? AND is_active=1 LIMIT 1', [$email]) : null;
        // Toujours exécuter un password_verify pour ne pas révéler si le compte existe (temps de réponse).
        $hash = $row['password_hash'] ?? '$2y$10$JpzLEycy5yQK9N8XivE7ieyQZPDvAiggatPCnntrVykBqmZRViQp2';
        $ok = password_verify($password, (string)$hash) && $row !== null;
        if (!$ok) {
            $max = (int)App::config('login_max_attempts', 5);
            $lock = (int)App::config('login_lock_minutes', 15);
            foreach ($keys as $k) {
                Throttle::fail($k, $max, $lock);
            }
            Logger::info('auth', 'échec connexion ' . $email . ' depuis ' . $ip);
            return 'Identifiants incorrects.';
        }
        foreach ($keys as $k) {
            Throttle::clear($k);
        }
        session_regenerate_id(true);
        $_SESSION['admin'] = self::sessionUser($row);
        $_SESSION['admin_checked'] = time();
        Audit::log('v3.login');
        if (!App::db()->isReadOnly()) {
            App::db()->run('UPDATE admins SET last_login_at=NOW() WHERE id=?', [(int)$row['id']]);
        }
        return null;
    }

    public static function logout(): void
    {
        unset($_SESSION['admin'], $_SESSION['admin_checked']);
        session_regenerate_id(true);
    }

    public static function can(string $perm): bool
    {
        $u = $_SESSION['admin'] ?? null;
        if (!is_array($u)) {
            return false;
        }
        return self::roleCan((string)$u['role'], $perm);
    }

    public static function roleCan(string $role, string $perm): bool
    {
        $rules = self::ROLES[$role] ?? [];
        if (in_array('-' . $perm, $rules, true)) {
            return false;
        }
        return in_array('*', $rules, true) || in_array($perm, $rules, true);
    }

    public static function id(): ?int
    {
        $u = $_SESSION['admin'] ?? null;
        return is_array($u) ? (int)$u['id'] : null;
    }

    public static function label(): string
    {
        $u = $_SESSION['admin'] ?? null;
        return is_array($u) ? ((string)($u['name'] ?: $u['email'])) : '';
    }
}
