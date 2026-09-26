<?php
declare(strict_types=1);

namespace JC\Domain;

use JC\Core\Db;

/** Retrouve une participation déclarée à partir d'une identité saisie à la main (nom/prénom/e-mail), sans passer
 * par un dossier ou un token : dossier portail d'abord (par e-mail), sinon simple billet Billetweb (nom+prénom+
 * e-mail). Utilisé par les formulaires publics qui vérifient une identité avant d'accepter une demande
 * (avis Google/Facebook, annulation/remplacement). */
final class ParticipantLookup
{
    public static function identify(Db $db, int $eventId, string $nom, string $prenom, string $email): array
    {
        $p = $db->row(
            'SELECT id FROM participants WHERE event_id=? AND archived_at IS NULL AND LOWER(email)=LOWER(?) ORDER BY id DESC LIMIT 1',
            [$eventId, $email]
        );
        if ($p) {
            return ['participant_id' => (int)$p['id'], 'found' => true];
        }
        $found = (bool)$db->val(
            'SELECT 1 FROM billetweb_attendees WHERE event_id=? AND LOWER(email)=LOWER(?) AND LOWER(TRIM(name))=LOWER(TRIM(?)) AND LOWER(TRIM(firstname))=LOWER(TRIM(?)) LIMIT 1',
            [$eventId, $email, $nom, $prenom]
        );
        return ['participant_id' => null, 'found' => $found];
    }
}
