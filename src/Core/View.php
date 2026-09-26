<?php
declare(strict_types=1);

namespace JC\Core;

final class View
{
    /** Affiche templates/<name>.php, dans la mise en page sauf si $layout vaut false. */
    public static function render(string $name, array $vars = [], bool|string $layout = true): void
    {
        $content = self::capture($name, $vars);
        if ($layout === false) {
            echo $content;
            return;
        }
        $layoutName = is_string($layout) ? $layout : 'layout';
        echo self::capture($layoutName, $vars + ['content' => $content, 'title' => $vars['title'] ?? '']);
    }

    /** Retourne le HTML d'un gabarit (sans mise en page). */
    public static function fetch(string $name, array $vars = []): string
    {
        return self::capture($name, $vars);
    }

    private static function capture(string $name, array $vars): string
    {
        $file = App::path('templates/' . str_replace(['..', '\\'], '', $name) . '.php');
        if (!is_file($file)) {
            throw new \RuntimeException('Gabarit introuvable : ' . $name);
        }
        extract($vars, EXTR_SKIP);
        ob_start();
        try {
            require $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string)ob_get_clean();
    }
}
