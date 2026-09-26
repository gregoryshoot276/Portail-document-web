<?php
declare(strict_types=1);

namespace JC\Controllers;

use JC\Core\App;
use JC\Core\Auth;
use JC\Core\Db;
use JC\Core\Http;
use JC\Core\View;
use JC\Domain\Events;
use JC\Domain\ListingWriter;

abstract class BaseController
{
    protected function db(): Db
    {
        return App::db();
    }

    protected function writer(): ListingWriter
    {
        return new ListingWriter($this->db(), (int)Auth::id(), Auth::label());
    }

    protected function view(string $template, array $vars = []): void
    {
        View::render($template, $vars);
    }

    protected function event(int $id): array
    {
        $e = Events::find($this->db(), $id);
        if (!$e) {
            Http::abort(404, 'Journée introuvable.');
        }
        return $e;
    }

    /** Journée demandée (?event=) ou journée par défaut. */
    protected function eventFromQuery(): array
    {
        $id = Http::int('event');
        if ($id <= 0) {
            $id = Events::defaultId($this->db());
        }
        if ($id <= 0) {
            Http::abort(404, 'Aucune journée active.');
        }
        return $this->event($id);
    }
}
