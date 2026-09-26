<?php
declare(strict_types=1);

/* Démarrage des scripts en ligne de commande (tâches planifiées). Refuse tout appel depuis le web. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Ligne de commande uniquement.\n");
}
$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'JC\\')) {
        $file = $root . '/src/' . str_replace('\\', '/', substr($class, 3)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
require $root . '/src/helpers.php';
\JC\Core\App::boot($root);
