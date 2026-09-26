<?php
declare(strict_types=1);

use JC\Core\Auth;
use JC\Core\Csrf;
use JC\Core\Mode;

/** Échappement HTML. */
function e(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

function can(string $perm): bool
{
    return Auth::can($perm);
}

function read_only(): bool
{
    return Mode::readOnly();
}

/** Montant : 1 234,50 € (sans décimales si entier). */
function money(float|int|string|null $v, bool $zeroAsDash = false): string
{
    $f = (float)$v;
    if ($zeroAsDash && abs($f) < 0.005) {
        return '';
    }
    $decimals = abs($f - round($f)) < 0.005 ? 0 : 2;
    return number_format($f, $decimals, ',', "\u{202F}") . "\u{00A0}€";
}

/** Nombre pour un champ de saisie : 249, 249.5 (jamais de zéros inutiles). */
function plain_num(float|int|string|null $v, bool $emptyIfZero = false): string
{
    $f = (float)$v;
    if ($emptyIfZero && abs($f) < 0.005) {
        return '';
    }
    return rtrim(rtrim(number_format($f, 2, '.', ''), '0'), '.');
}

function fdate(?string $d, string $fmt = 'd/m/Y'): string
{
    if (!$d || str_starts_with($d, '0000')) {
        return '';
    }
    $t = strtotime($d);
    return $t ? date($fmt, $t) : '';
}

/** Jour de la semaine en français : « samedi ». */
function strftime_fr(?string $date): string
{
    $t = $date ? strtotime($date) : false;
    return $t ? ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'][(int)date('w', $t)] : '';
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_take(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/** URL d'un fichier statique avec empreinte de version pour éviter le cache obsolète. */
function asset(string $path): string
{
    $f = dirname(__DIR__) . '/public/assets/' . ltrim($path, '/');
    return '/assets/' . ltrim($path, '/') . (is_file($f) ? '?v=' . substr((string)filemtime($f), -6) : '');
}

/** Conserve les paramètres GET courants en changeant certains. */
function qs(array $change = []): string
{
    $q = array_merge($_GET, $change);
    $q = array_filter($q, fn($v) => $v !== null && $v !== '');
    return $q ? '?' . http_build_query($q) : '';
}
