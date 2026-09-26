# Portail Journée Circuit v3 — installation chez IONOS

Ce portail **s'ajoute** à l'ancien : il utilise la même base de données et les mêmes fichiers, sur un sous-domaine séparé
(`documents.journeecircuit.fr`). L'ancien portail continue de fonctionner sans changement.

**Il démarre en mode « lecture seule »** : il affiche vos vraies données mais n'écrit rien en base et n'envoie aucun e-mail
(garanti par le code *et* par MySQL). Vous pouvez donc le tester pendant une journée sans aucun risque.

## Ce que fait le portail

| Fonction | Où | Lecture seule | Écriture |
|---|---|---|---|
| **Formulaire public participant** (pilote, pilote supplémentaire, passager) : identité, vérification Billetweb en direct, permis, assurance, décharge, signature, 4 langues | `/participer` | affiche un message, ne perd pas la saisie | enregistre le dossier, range les fichiers, envoie l'e-mail de confirmation |
| **Suivi du dossier** par lien secret, remplacement d'un document refusé | `/suivi/…` | consultation | remplacement possible |
| **Décharges PDF** : preuve Journée Circuit (avec empreinte SHA-256) et décharge officielle du circuit (Magny-Cours, Bugatti) remplie sur le PDF du circuit | téléchargement participant et admin | générées à la volée, rien n'est écrit | générées une fois et rangées |
| **Dossiers et décisions** : valider, refuser avec motif, « valider + notifier » ; e-mail automatique au participant | `/dossiers` | consultation | décisions + e-mails |
| **Listing** : édition en ligne, paiements, sur place, décharge forcée, export Excel, étiquettes | `/listing` | consultation | édition |
| **Fusion de deux lignes**, **relances e-mail** (une ligne ou tous les incomplets), **synchronisation Billetweb** (bouton + automatique) | `/listing` | désactivés | actifs |
| **Starter**, **RFID / URTime**, **Contrôle**, **Bilan**, **Tarifs** | menu | consultation | édition |
| **Purge RGPD** des dossiers expirés (30 jours après la journée) | tâche planifiée | inactive | active |

## Ce dont vous avez besoin
- l'espace web IONOS avec **PHP 8.1 ou plus** (extensions : pdo_mysql, mbstring, fileinfo, curl, openssl, zlib) ;
- l'accès à la base de données (mêmes identifiants que l'ancien `Config/database.php`) ;
- un accès FTP/SFTP à l'espace web.

## Installation

### 1. Créer le sous-domaine
Dans IONOS : *Domaines & SSL → Sous-domaines → Créer* : `documents.journeecircuit.fr`, dossier de destination **`/documents/public`**
(le dossier `public` du portail, pas sa racine). Activez le certificat SSL (HTTPS).

### 2. Envoyer les fichiers
Envoyez **tout le contenu** de l'archive dans un dossier `documents` à la racine de votre espace web :
```
documents/
  config/  cron/  src/  templates/  vendor/  storage/   ← jamais accessibles depuis Internet
  public/                                               ← SEUL dossier accessible (destination du sous-domaine)
```

### 3. Configurer
1. Dans `documents/config/`, copiez `config.example.php` en **`config.php`**.
2. Renseignez :
   - `db` : hôte, nom, utilisateur et mot de passe de la base (mêmes que l'ancien portail) ;
   - `storage_root` : le dossier de l'ancien portail qui contient `Permis`, `Assurance`, `Signatures`, `Waivers`, `WaiversFinal`,
     `Templates` (dans votre archive : `storage`, par ex. `/home/www/storage`). **Attention aux majuscules sur Linux.** Le portail lit et écrit les
     documents à cet endroit : les deux portails partagent les mêmes fichiers ;
   - `base_url` : `https://documents.journeecircuit.fr` ; **`public_base_url`** : l'adresse du formulaire que les participants recevront
     dans les e-mails (mettez la même valeur tant que le nouveau formulaire n'est pas le seul en service) ;
   - laissez `'read_only' => true`.
3. Les dossiers `documents/storage/state` et `documents/storage/logs` doivent être **inscriptibles** (droits 755 ou 775).

### 4. Vérifier
Connectez-vous avec **votre compte de l'ancien portail** puis ouvrez **Diagnostic**. Il contrôle PHP, la base, les 26 tables, la protection
« lecture seule » de MySQL, les dossiers de documents, **les modèles de décharge et les PDF officiels**, les réglages e-mail et Billetweb.

### 5. Tâches planifiées (cron IONOS) — uniquement quand vous passez en écriture
*IONOS → Hébergement → Cron-Jobs* :
- toutes les 10 minutes : `php /chemin/complet/documents/cron/sync.php` (synchronisation Billetweb → listing) ;
- chaque nuit : `php /chemin/complet/documents/cron/purge.php` (suppression RGPD des dossiers expirés).
Les deux scripts ne font rien en lecture seule et refusent tout appel depuis le web.

### 6. Comparer, puis passer en écriture
Suivez `GRILLE_COMPARAISON.md`. Passage en écriture : *Réglages → Activer l'écriture* (super administrateur, avec confirmation).

**Avant de passer en écriture, lisez ceci :**
- Les **e-mails partent réellement** (confirmation, refus, relance…) si le réglage `mail_enabled` de l'ancien portail est actif : ils sont partagés.
- **Un seul formulaire public à la fois** doit être donné aux participants (l'ancien ou le nouveau). Les deux écrivent dans la même base : rien ne casse, mais
  les deux synchronisations Billetweb en parallèle sont inutiles ; arrêtez le cron de l'ancien portail quand celui de v3 prend le relais.
- Essayez d'abord sur une **copie de la base** (phpMyAdmin : exporter puis importer dans une seconde base et pointer `config.php` dessus).

### Facultatif : lecture obligatoire du PDF officiel (pdf.js)
Sans pdf.js, le participant ouvre la décharge officielle du circuit puis coche « J'ai lu la décharge jusqu'à la fin » (déclaration sur l'honneur).
Avec pdf.js (`public/assets/vendor/pdfjs/pdf.min.mjs` et `pdf.worker.min.mjs`), le document s'affiche dans la page et les engagements ne se débloquent
qu'une fois le bas atteint, comme dans l'ancien portail. Le Diagnostic indique lequel est actif.

## Sécurité — à respecter
- `config.php` ne doit jamais se trouver dans `public/` ; aucune sauvegarde (`.bak`, `.zip`, `.sql`) dans `public/`.
- Changez les mots de passe de la base, de Billetweb, d'OpenAI et du SMTP si l'archive de l'ancien portail a été partagée.
- Connexions limitées (5 échecs = 15 minutes de blocage), vérification Billetweb limitée en fréquence, liens de suivi longs et secrets,
  consultations de documents enregistrées, en-têtes de sécurité (CSP, HSTS, anti-cadre).
- Les e-mails n'insèrent jamais de HTML saisi par un participant.

## Tests
Le code a été vérifié par 45 tests sur MariaDB, 39 tests de logique métier et 83 parcours de bout en bout (lecture seule et écriture), tous sur des données fictives. Ces tests ne font pas partie de l'archive.
