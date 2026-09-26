<?php
declare(strict_types=1);

/*
 * Point d'entrée unique du portail. Le dossier public/ est la seule partie accessible depuis Internet.
 */

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

use JC\Controllers\AccountController;
use JC\Controllers\AuthController;
use JC\Controllers\BilanController;
use JC\Controllers\BilletwebControlController;
use JC\Controllers\ChangesController;
use JC\Controllers\ControleController;
use JC\Controllers\DashboardController;
use JC\Controllers\DiagnosticController;
use JC\Controllers\DossiersController;
use JC\Controllers\FilesController;
use JC\Controllers\ListingController;
use JC\Controllers\MailCenterController;
use JC\Controllers\PhotoAccessController;
use JC\Controllers\PhotoController;
use JC\Controllers\PublicController;
use JC\Controllers\ReviewsController;
use JC\Controllers\RfidController;
use JC\Controllers\SettingsController;
use JC\Controllers\StarterAccessController;
use JC\Controllers\StarterController;
use JC\Controllers\TeamController;
use JC\Core\App;
use JC\Core\Auth;
use JC\Core\Http;
use JC\Core\Router;

App::boot($root);
Http::securityHeaders();
Auth::boot();

$r = new Router();
$r->get('/login', [AuthController::class, 'loginForm'], Router::PUBLIC);
$r->post('/login', [AuthController::class, 'login'], Router::PUBLIC);
$r->post('/logout', [AuthController::class, 'logout']);

// Pages publiques (participants)
$r->get('/participer', [PublicController::class, 'form'], Router::PUBLIC);
$r->post('/participer', [PublicController::class, 'submit'], Router::PUBLIC);
$r->get('/participer/verifier', [PublicController::class, 'lookup'], Router::PUBLIC);
$r->get('/participer/officiel/{id}', [PublicController::class, 'officialPdf'], Router::PUBLIC);
$r->get('/participer/decharge-lecture/{id}', [PublicController::class, 'officialReader'], Router::PUBLIC);
$r->get('/suivi/{token}', [PublicController::class, 'suivi'], Router::PUBLIC);
$r->get('/suivi/{token}/remplacer/{doc}', [PublicController::class, 'replaceForm'], Router::PUBLIC);
$r->post('/suivi/{token}/remplacer/{doc}', [PublicController::class, 'replaceSubmit'], Router::PUBLIC);
$r->get('/decharge/{token}/{kind}', [PublicController::class, 'download'], Router::PUBLIC);

// Avis Google/Facebook contre code promo (reprise de l'ancien outil, même URL /avis.php)
$r->get('/avis.php', [ReviewsController::class, 'form'], Router::PUBLIC);
$r->post('/avis.php', [ReviewsController::class, 'submit'], Router::PUBLIC);

// Annulation / remplacement (reprise de l'ancien outil, reconstruit sur ce portail-ci)
$r->get('/annulation', [ChangesController::class, 'form'], Router::PUBLIC);
$r->post('/annulation', [ChangesController::class, 'submit'], Router::PUBLIC);

// « Mon espace » participant (optionnel) : réutilisation de documents
$r->get('/mon-espace', [AccountController::class, 'loginForm'], Router::PUBLIC);
$r->post('/mon-espace/connexion', [AccountController::class, 'login'], Router::PUBLIC);
$r->get('/mon-espace/creer', [AccountController::class, 'registerForm'], Router::PUBLIC);
$r->post('/mon-espace/creer', [AccountController::class, 'register'], Router::PUBLIC);
$r->post('/mon-espace/deconnexion', [AccountController::class, 'logout'], Router::PUBLIC);

// Invitation Team (pilote invité par un Team Manager)
$r->get('/team/{token}', [PublicController::class, 'teamForm'], Router::PUBLIC);
$r->post('/team/{token}', [PublicController::class, 'teamSubmit'], Router::PUBLIC);

// Le domaine documents.journeecircuit.fr sert d'abord les participants : la racine est le formulaire, pas l'admin.
$r->get('/', [PublicController::class, 'form'], Router::PUBLIC);
$r->get('/admin', [DashboardController::class, 'index']);

// Dossiers et documents
$r->get('/dossiers', [DossiersController::class, 'index'], 'dossiers.view');
$r->get('/dossiers/{id}', [DossiersController::class, 'show'], 'dossiers.view');
$r->post('/dossiers/{id}/decision', [DossiersController::class, 'decision'], 'dossiers.decide');
$r->post('/dossiers/{id}/fix', [DossiersController::class, 'fix'], 'dossiers.decide');
$r->post('/dossiers/nettoyer-assurance', [DossiersController::class, 'cleanStaleInsurance'], 'dossiers.decide');
$r->get('/files/document/{id}', [FilesController::class, 'document'], 'files.view');
$r->get('/files/signature/{id}', [FilesController::class, 'signature'], 'files.view');
$r->get('/files/waiver/{id}/{kind}', [FilesController::class, 'waiver'], 'files.view');

// Listing
$r->get('/listing', [ListingController::class, 'index'], 'listing.view');
$r->post('/listing/{id}/api', [ListingController::class, 'api'], 'listing.edit');
$r->get('/listing/{id}/rows', [ListingController::class, 'rows'], 'listing.view');
$r->get('/listing/{id}/poll', [ListingController::class, 'poll'], 'listing.view');
$r->get('/listing/{id}/export.csv', [ListingController::class, 'exportCsv'], 'listing.view');
$r->get('/listing/{id}/etiquettes.pdf', [ListingController::class, 'labels'], 'labels');
$r->get('/listing/{id}/mono', [ListingController::class, 'mono'], 'listing.view');
$r->get('/controle', [ControleController::class, 'index'], 'listing.view');

// Starter
$r->get('/starter', [StarterController::class, 'index'], 'starter');
$r->post('/starter/{id}/api', [StarterController::class, 'api'], 'starter');

// Starter — réglages de l'accès public (admin)
$r->get('/starter/acces', [StarterAccessController::class, 'settingsForm'], 'starter');
$r->post('/starter/acces', [StarterAccessController::class, 'settingsSave'], 'starter');

// Starter — accès public par lien + code PIN optionnel, sans identifiants staff, verrouillé sur une seule journée
$r->get('/starter-public', [StarterAccessController::class, 'publicForm'], Router::PUBLIC);
$r->post('/starter-public', [StarterAccessController::class, 'publicUnlock'], Router::PUBLIC);
$r->post('/starter-public/api', [StarterAccessController::class, 'publicApi'], Router::PUBLIC);

// Photographe
$r->get('/photo', [PhotoController::class, 'index'], 'photo.view');

// Photographe — réglages de l'accès public (admin)
$r->get('/photo/acces', [PhotoAccessController::class, 'settingsForm'], 'photo.view');
$r->post('/photo/acces', [PhotoAccessController::class, 'settingsSave'], 'photo.view');

// Photographe — accès public par lien + code PIN optionnel, sans identifiants staff (même principe que Starter)
$r->get('/photo-public', [PhotoAccessController::class, 'publicForm'], Router::PUBLIC);
$r->post('/photo-public', [PhotoAccessController::class, 'publicUnlock'], Router::PUBLIC);

// RFID
$r->get('/rfid', [RfidController::class, 'index'], 'rfid.view');
$r->post('/rfid/{id}/api', [RfidController::class, 'api'], 'rfid.edit');
$r->post('/rfid/{id}/urtime', [RfidController::class, 'urtimeApi'], 'rfid.view');

// Bilan et tarifs
$r->get('/bilan', [BilanController::class, 'index'], 'finance');
$r->post('/bilan/save', [BilanController::class, 'save'], 'finance');
$r->get('/tarifs', [BilanController::class, 'tarifs'], 'tarifs.view');
$r->post('/tarifs', [BilanController::class, 'tarifsSave'], 'tarifs.edit');

// Contrôle Billetweb (diagnostic, lecture seule y compris en mode écriture)
$r->get('/billetweb-control', [BilletwebControlController::class, 'index'], 'billetweb_control');
$r->post('/billetweb-control/{id}/live', [BilletwebControlController::class, 'live'], 'billetweb_control');

// Teams (staff)
$r->get('/teams', [TeamController::class, 'index'], 'teams');
$r->post('/teams', [TeamController::class, 'create'], 'teams');
$r->get('/teams/insurances', [TeamController::class, 'insurances'], 'teams');
$r->post('/teams/insurances/{id}/decision', [TeamController::class, 'insuranceDecision'], 'teams');
$r->get('/teams/insurances/{id}/fichier', [TeamController::class, 'insuranceFile'], 'teams');

// Team Manager (public, lien secret) et invitation pilote (voir plus haut, PublicController::teamForm/teamSubmit)
$r->get('/team-manager/{token}', [TeamController::class, 'manager'], Router::PUBLIC);
$r->post('/team-manager/{token}/pilote', [TeamController::class, 'addDriver'], Router::PUBLIC);
$r->post('/team-manager/{token}/vehicule', [TeamController::class, 'addVehicle'], Router::PUBLIC);
$r->post('/team-manager/{token}/assurance', [TeamController::class, 'uploadInsurance'], Router::PUBLIC);

// Mail Center (routes littérales enregistrées avant /mailcenter/{key} pour éviter qu'il les capture)
$r->get('/mailcenter', [MailCenterController::class, 'index'], 'mailcenter');
$r->post('/mailcenter/transport', [MailCenterController::class, 'transportSave'], 'mailcenter');
$r->post('/mailcenter/briefing/{id}', [MailCenterController::class, 'briefingSave'], 'mailcenter');
$r->get('/mailcenter/{key}', [MailCenterController::class, 'edit'], 'mailcenter');
$r->post('/mailcenter/{key}', [MailCenterController::class, 'save'], 'mailcenter');
$r->post('/mailcenter/{key}/reset', [MailCenterController::class, 'reset'], 'mailcenter');

// Avis Google/Facebook : validation admin (formulaire public plus haut, /avis.php)
$r->get('/avis', [ReviewsController::class, 'index'], 'reviews');
$r->post('/avis/{id}/decision', [ReviewsController::class, 'decision'], 'reviews');
$r->get('/avis/{id}/fichier', [ReviewsController::class, 'file'], 'reviews');

// Annulations / remplacements : validation admin (formulaire public plus haut, /annulation)
$r->get('/changements', [ChangesController::class, 'index'], 'changes');
$r->post('/changements/{id}/decision', [ChangesController::class, 'decision'], 'changes');

// Administration technique
$r->get('/diagnostic', [DiagnosticController::class, 'index'], 'diagnostic');
$r->get('/settings', [SettingsController::class, 'index'], 'settings');
$r->post('/settings/mode', [SettingsController::class, 'mode'], 'settings');

$r->dispatch();
