<?php
declare(strict_types=1);

/*
 * Copiez ce fichier en config/config.php puis renseignez vos valeurs.
 * config/config.php ne doit JAMAIS être placé dans le dossier public.
 */
return [
    'app_name'  => 'Journée Circuit – Portail v3',
    'base_url'  => 'https://documents.journeecircuit.fr',
    // Adresse publique utilisée dans les liens des e-mails (suivi du dossier, formulaire). Par défaut : base_url.
    'public_base_url' => 'https://documents.journeecircuit.fr',
    'timezone'  => 'Europe/Paris',

    // Même base de données que l'ancien portail.
    'db' => [
        'host'    => 'db5021211663.hosting-data.io',
        'port'    => 3306,
        'name'    => 'dbs16020428',
        'user'    => 'A_REMPLIR',
        'pass'    => 'A_REMPLIR',
        'charset' => 'utf8mb4',
    ],

    // Dossier de l'ancien portail qui contient Permis/, Assurance/, Signatures/,
    // WaiversFinal/, Templates/ ... (hors dossier public). Ex. /home/www/storage
    // Attention : sur Linux, les majuscules comptent (« storage » et « Storage » sont deux dossiers différents).
    'storage_root' => '/home/www/storage',

    // true = AUCUNE écriture en base, garantie par le code ET par MySQL (transaction en lecture seule).
    // Ce réglage peut ensuite être changé par un super administrateur depuis Réglages > Mode.
    'read_only' => true,

    'session_name'         => 'jc3_session',
    'session_idle_minutes' => 240,
    'login_max_attempts'   => 5,
    'login_lock_minutes'   => 15,

    // Ne jamais mettre true en production.
    'debug' => false,
];
