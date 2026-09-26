<?php
declare(strict_types=1);

namespace JC\Controllers;

use JC\Core\Auth;
use JC\Core\Http;
use JC\Core\View;

final class AuthController extends BaseController
{
    public function loginForm(): void
    {
        if (Auth::user() !== null) {
            Http::redirect('/admin');
        }
        View::render('login', ['error' => null, 'email' => ''], 'layout_bare');
    }

    public function login(): void
    {
        $email = Http::str('email');
        $error = Auth::attempt($email, (string)Http::input('password', ''));
        if ($error === null) {
            Http::redirect('/admin');
        }
        http_response_code(401);
        View::render('login', ['error' => $error, 'email' => $email], 'layout_bare');
    }

    public function logout(): void
    {
        Auth::logout();
        Http::redirect('/login');
    }
}
