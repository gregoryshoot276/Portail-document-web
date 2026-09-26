<?php
declare(strict_types=1);

namespace JC\Domain;

use JC\Core\App;

/** Langues du formulaire public : fr, en, nl, de (textes repris de l'ancien portail). */
final class I18n
{
    public const LANGS = ['fr' => 'Français', 'en' => 'English', 'nl' => 'Nederlands', 'de' => 'Deutsch'];
    private static ?array $dict = null;
    private static ?array $clauses = null;

    public static function lang(): string
    {
        $q = $_GET['lang'] ?? null;
        if (is_string($q) && isset(self::LANGS[$q])) {
            $_SESSION['lang'] = $q;
        }
        $l = $_SESSION['lang'] ?? 'fr';
        return isset(self::LANGS[$l]) ? $l : 'fr';
    }

    /** Textes ajoutés par le portail v3 (absents de l'ancien dictionnaire). */
    private const EXTRA = [
        'supp' => ['fr' => 'Pilote supplémentaire', 'en' => 'Additional driver', 'nl' => 'Extra bestuurder', 'de' => 'Zusätzlicher Fahrer'],
        'supp_desc' => ['fr' => 'Permis et décharge.', 'en' => 'Driving licence and waiver.', 'nl' => 'Rijbewijs en vrijwaring.', 'de' => 'Führerschein und Haftungsausschluss.'],
        'linked_driver' => ['fr' => 'Pilote principal auquel vous êtes rattaché', 'en' => 'Main driver you are attached to', 'nl' => 'Hoofdbestuurder waaraan u gekoppeld bent', 'de' => 'Hauptfahrer, dem Sie zugeordnet sind'],
        'confirm_mine' => ['fr' => 'Oui, c’est bien moi', 'en' => 'Yes, that is me', 'nl' => 'Ja, dat ben ik', 'de' => 'Ja, das bin ich'],
        'confirm_not' => ['fr' => 'Non, continuer quand même', 'en' => 'No, continue anyway', 'nl' => 'Nee, toch doorgaan', 'de' => 'Nein, trotzdem fortfahren'],
        'official_check' => ['fr' => 'J’ai lu la décharge officielle du circuit jusqu’à la fin', 'en' => 'I have read the circuit’s official waiver to the end', 'nl' => 'Ik heb de officiële vrijwaring van het circuit volledig gelezen', 'de' => 'Ich habe den offiziellen Haftungsausschluss der Rennstrecke bis zum Ende gelesen'],
        'open_official' => ['fr' => 'Ouvrir la décharge officielle du circuit', 'en' => 'Open the circuit’s official waiver', 'nl' => 'Officiële vrijwaring van het circuit openen', 'de' => 'Offiziellen Haftungsausschluss öffnen'],
        'commitments' => ['fr' => 'Engagements', 'en' => 'Commitments', 'nl' => 'Verklaringen', 'de' => 'Erklärungen'],
        'fix_errors' => ['fr' => 'Merci de corriger :', 'en' => 'Please correct:', 'nl' => 'Gelieve te corrigeren:', 'de' => 'Bitte korrigieren:'],
        'readonly_notice' => ['fr' => 'Le portail est en lecture seule : les dossiers ne peuvent pas être enregistrés pour le moment.', 'en' => 'The portal is read-only: files cannot be saved at the moment.', 'nl' => 'Het portaal is alleen-lezen: dossiers kunnen momenteel niet worden opgeslagen.', 'de' => 'Das Portal ist schreibgeschützt: Akten können derzeit nicht gespeichert werden.'],
        'signature_required' => ['fr' => 'Merci de signer dans le cadre.', 'en' => 'Please sign in the box.', 'nl' => 'Gelieve in het vak te tekenen.', 'de' => 'Bitte im Feld unterschreiben.'],
        'guest_note' => ['fr' => 'Vos documents sont conservés jusqu’à 30 jours après la journée puis supprimés automatiquement.', 'en' => 'Your documents are kept for up to 30 days after the event, then deleted automatically.', 'nl' => 'Uw documenten worden tot 30 dagen na de dag bewaard en daarna automatisch verwijderd.', 'de' => 'Ihre Dokumente werden bis zu 30 Tage nach dem Termin aufbewahrt und dann automatisch gelöscht.'],
        'previous' => ['fr' => 'Précédent', 'en' => 'Previous', 'nl' => 'Vorige', 'de' => 'Zurück'],
        'next' => ['fr' => 'Suivant', 'en' => 'Next', 'nl' => 'Volgende', 'de' => 'Weiter'],
        'step_of' => ['fr' => 'Étape {n} sur {total}', 'en' => 'Step {n} of {total}', 'nl' => 'Stap {n} van {total}', 'de' => 'Schritt {n} von {total}'],
        'close' => ['fr' => 'Fermer', 'en' => 'Close', 'nl' => 'Sluiten', 'de' => 'Schließen'],
    ];

    public static function t(string $key, array $vars = []): string
    {
        self::$dict ??= require App::path('src/Data/i18n.php');
        $txt = self::EXTRA[$key][self::lang()] ?? self::$dict[self::lang()][$key] ?? self::EXTRA[$key]['fr'] ?? self::$dict['fr'][$key] ?? $key;
        foreach ($vars as $k => $v) {
            $txt = str_replace('{' . $k . '}', (string)$v, $txt);
        }
        return $txt;
    }

    /** Clauses de décharge (type = pilot | passenger) dans la langue demandée, avec repli sur le français. */
    public static function clauses(string $type, ?string $lang = null): array
    {
        self::$clauses ??= require App::path('src/Data/clauses.php');
        $type = $type === 'passenger' ? 'passenger' : 'pilot';
        $lang ??= self::lang();
        $fr = self::$clauses['fr'][$type] ?? [];
        $tr = self::$clauses[$lang][$type] ?? [];
        $out = [];
        foreach ($fr as $k => $frText) {
            $out[$k] = $tr[$k] ?? $frText;
        }
        return $out;
    }

    public static function frClauses(string $type): array
    {
        self::$clauses ??= require App::path('src/Data/clauses.php');
        return self::$clauses['fr'][$type === 'passenger' ? 'passenger' : 'pilot'] ?? [];
    }
}
