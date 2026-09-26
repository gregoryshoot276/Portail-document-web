<?php
declare(strict_types=1);

namespace JC\Controllers;

use JC\Core\Audit;
use JC\Core\CustomerAuth;
use JC\Core\Http;
use JC\Core\Mode;
use JC\Core\ReadOnlyException;
use JC\Core\Throttle;
use JC\Core\View;
use JC\Domain\I18n;

/** « Mon espace » participant (optionnel) : réutiliser permis/assurance validés d'un événement à l'autre. */
final class AccountController extends BaseController
{
    private function render(string $tpl, array $vars): void
    {
        View::render($tpl, $vars + ['pageTitle' => 'Mon espace · Journée Circuit', 'lang' => I18n::lang()], 'layout_public');
    }

    public function loginForm(): void
    {
        if (CustomerAuth::user()) {
            Http::redirect('/participer');
        }
        $this->render('account/login', ['error' => '']);
    }

    public function login(): void
    {
        $email = mb_strtolower(trim(Http::str('email')));
        $password = (string)(Http::body()['password'] ?? '');
        $key = 'customer_login:' . Http::ip();
        $wait = Throttle::lockedFor($key);
        if ($wait > 0) {
            $this->render('account/login', ['error' => 'Trop de tentatives. Réessayez dans ' . (int)ceil($wait / 60) . ' minute(s).']);
            return;
        }
        $row = $email !== '' ? $this->db()->row('SELECT * FROM customer_accounts WHERE email=? AND is_active=1', [$email]) : null;
        // password_verify est toujours exécuté, même sans compte trouvé, pour ne pas révéler son existence par le temps de réponse.
        $hash = $row['password_hash'] ?? '$2y$10$JpzLEycy5yQK9N8XivE7ieyQZPDvAiggatPCnntrVykBqmZRViQp2';
        if (!$row || !password_verify($password, (string)$hash)) {
            Throttle::fail($key, 8, 15);
            $this->render('account/login', ['error' => 'Identifiants incorrects.']);
            return;
        }
        Throttle::clear($key);
        CustomerAuth::login($row);
        Audit::log('customer_login', null, ['account_id' => (int)$row['id']]);
        Http::redirect('/participer');
    }

    public function registerForm(): void
    {
        if (CustomerAuth::user()) {
            Http::redirect('/participer');
        }
        $this->render('account/register', ['errors' => [], 'old' => []]);
    }

    public function register(): void
    {
        $email = mb_strtolower(trim(Http::str('email')));
        $password = (string)(Http::body()['password'] ?? '');
        $nom = Http::str('nom');
        $prenom = Http::str('prenom');
        $tel = Http::str('telephone');
        $consent = !empty(Http::body()['consent']);
        $errors = [];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Adresse e-mail invalide.';
        }
        if (mb_strlen($password) < 10) {
            $errors[] = 'Le mot de passe doit contenir au moins 10 caractères.';
        }
        if ($nom === '' || $prenom === '') {
            $errors[] = 'Nom et prénom obligatoires.';
        }
        if (!$errors) {
            try {
                Mode::assertWritable();
                $db = $this->db();
                if ($db->val('SELECT id FROM customer_accounts WHERE email=?', [$email])) {
                    $errors[] = 'Un compte existe déjà avec cette adresse e-mail. Connectez-vous plutôt.';
                } else {
                    $db->run(
                        'INSERT INTO customer_accounts(email,password_hash,nom,prenom,telephone,document_reuse_consent,consent_at,is_active,created_at,updated_at) VALUES(?,?,?,?,?,?,?,1,NOW(),NOW())',
                        [$email, password_hash($password, PASSWORD_DEFAULT), $nom, $prenom, $tel ?: null, $consent ? 1 : 0, $consent ? date('Y-m-d H:i:s') : null]
                    );
                    $id = (int)$db->lastInsertId();
                    Audit::log('customer_register', null, ['account_id' => $id]);
                    CustomerAuth::login(['id' => $id, 'email' => $email, 'nom' => $nom, 'prenom' => $prenom, 'telephone' => $tel]);
                    Http::redirect('/participer');
                }
            } catch (ReadOnlyException $e) {
                $errors[] = I18n::t('readonly_notice');
            }
        }
        $this->render('account/register', ['errors' => $errors, 'old' => ['email' => $email, 'nom' => $nom, 'prenom' => $prenom, 'telephone' => $tel]]);
    }

    public function logout(): void
    {
        CustomerAuth::logout();
        Http::redirect('/participer');
    }
}
