<?php
declare(strict_types=1);

namespace JC\Core;

final class Router
{
    /** Route accessible sans connexion. */
    public const PUBLIC = '@public';
    /** Route accessible à tout utilisateur connecté. */
    public const AUTH = '@auth';

    private array $routes = [];

    public function get(string $pattern, array $handler, string $perm = self::AUTH): self
    {
        return $this->add('GET', $pattern, $handler, $perm);
    }

    public function post(string $pattern, array $handler, string $perm = self::AUTH): self
    {
        return $this->add('POST', $pattern, $handler, $perm);
    }

    private function add(string $method, string $pattern, array $handler, string $perm): self
    {
        $regex = preg_replace('/\{[a-z_]+\}/', '([^/]+)', $pattern);
        $this->routes[] = [$method, '#^' . $regex . '$#', $handler, $perm];
        return $this;
    }

    public function dispatch(): void
    {
        $method = Http::method();
        $path = Http::path();
        $pathMatched = false;

        foreach ($this->routes as [$m, $regex, $handler, $perm]) {
            if (!preg_match($regex, $path, $mt)) {
                continue;
            }
            $pathMatched = true;
            if ($m !== $method) {
                continue;
            }
            array_shift($mt);
            // Paramètres numériques -> int (les contrôleurs sont en strict_types).
            $params = array_map(static fn(string $p) => ctype_digit($p) ? (int)$p : urldecode($p), $mt);
            $this->run($handler, $params, $perm, $method);
            return;
        }
        Http::abort($pathMatched ? 405 : 404);
    }

    private function run(array $handler, array $params, string $perm, string $method): void
    {
        try {
            if ($perm !== self::PUBLIC) {
                if (Auth::user() === null) {
                    if (Http::wantsJson()) {
                        Http::json(['ok' => false, 'message' => 'Session expirée, reconnectez-vous.'], 401);
                    }
                    Http::redirect('/login');
                }
                if ($perm !== self::AUTH && !Auth::can($perm)) {
                    Http::abort(403);
                }
            }
            if ($method === 'POST') {
                Csrf::verify();
            }
            [$class, $fn] = $handler;
            (new $class())->$fn(...$params);
        } catch (ReadOnlyException $e) {
            if (Http::wantsJson()) {
                Http::json(['ok' => false, 'readonly' => true, 'message' => $e->getMessage()], 423);
            }
            flash('warn', $e->getMessage());
            Http::redirect((string)($_SERVER['HTTP_REFERER'] ?? '/'));
        } catch (\Throwable $e) {
            Logger::error('app', get_class($e) . ' : ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            if (Http::wantsJson()) {
                Http::json(['ok' => false, 'message' => App::config('debug') ? $e->getMessage() : 'Erreur interne.'], 500);
            }
            http_response_code(500);
            View::render('error', ['code' => 500, 'message' => App::config('debug') ? $e->getMessage() : 'Une erreur est survenue. Elle a été enregistrée.']);
        }
    }
}
