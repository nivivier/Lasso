<?php
// Module événements : fonctions pures (statut SUISA dérivé, règles de
// visibilité/export) — voir SPEC_EVENEMENTS.md.

const EVENEMENTS_STATUTS     = ['option', 'confirme', 'annule'];
const EVENEMENTS_VISIBILITES = ['public', 'prive', 'non_repertorie'];
const EVENEMENTS_SUISA_ENVOYE_A = ['suisa', 'organisateur'];
// Les 5 valeurs du statut SUISA dérivé (evenement_statut_suisa()), pour valider
// le filtre de la liste des événements.
const EVENEMENTS_STATUTS_SUISA_FILTRE = ['a_faire', 'envoye', 'manquant', 'decompte_recu', 'ne_sapplique_pas'];

function evenement_statut_libelle(string $statut): string
{
    return match ($statut) {
        'confirme' => 'Confirmé',
        'annule'   => 'Annulé',
        default    => 'Option',
    };
}

function evenement_visibilite_libelle(string $visibilite): string
{
    return match ($visibilite) {
        'public' => 'Public',
        'prive'  => 'Privé',
        default  => 'Non répertorié',
    };
}

// Statut SUISA dérivé (jamais stocké), voir SPEC_EVENEMENTS.md §5. Calculé par
// ordre de priorité : ne s'applique pas > décompte reçu > manquant > envoyé > à faire.
function evenement_statut_suisa(array $ev): string
{
    if (!(int) ($ev['suisa_applicable'] ?? 1)) {
        return 'ne_sapplique_pas';
    }
    if (trim((string) ($ev['suisa_decompte_le'] ?? '')) !== '') {
        return 'decompte_recu';
    }
    $envoyeLe = trim((string) ($ev['suisa_envoye_le'] ?? ''));
    if ($envoyeLe === '') {
        return 'a_faire';
    }
    $limite = (new DateTimeImmutable($envoyeLe))
        ->modify('+' . evenements_delai_decompte_mois() . ' months')
        ->format('Y-m-d');
    return $limite < date('Y-m-d') ? 'manquant' : 'envoye';
}

function evenement_statut_suisa_libelle(string $statut): string
{
    return match ($statut) {
        'decompte_recu'    => 'Décompte reçu',
        'manquant'         => 'Manquant',
        'envoye'           => 'Envoyé',
        'ne_sapplique_pas' => "Ne s'applique pas",
        default            => 'À faire',
    };
}

function evenement_suisa_badge(array $ev): string
{
    $statut  = evenement_statut_suisa($ev);
    $classe  = match ($statut) {
        'decompte_recu' => 'ok-badge',
        'manquant'      => 'warn-badge',
        'envoye'        => 'emise-badge',
        default         => 'muted-badge', // a_faire, ne_sapplique_pas
    };
    return '<span class="badge ' . $classe . '">' . e(evenement_statut_suisa_libelle($statut)) . '</span>';
}

function evenement_badge_statut(array $ev): string
{
    $classe = match ($ev['statut']) {
        'confirme' => 'ok-badge',
        'annule'   => 'muted-badge',
        default    => 'warn-badge', // option
    };
    return '<span class="badge ' . $classe . '">' . e(evenement_statut_libelle((string) $ev['statut'])) . '</span>';
}

function evenement_badge_visibilite(array $ev): string
{
    $classe = match ($ev['visibilite']) {
        'public' => 'ok-badge',
        'prive'  => 'emise-badge',
        default  => 'muted-badge', // non_repertorie
    };
    return '<span class="badge ' . $classe . '">' . e(evenement_visibilite_libelle((string) $ev['visibilite'])) . '</span>';
}

// Prédicat SQL du statut SUISA dérivé, même règles que evenement_statut_suisa()
// (à tenir synchronisées) — pour filtrer côté base plutôt que de recharger tous
// les événements en PHP à chaque affichage de la liste. $prefixe : alias de
// table éventuel (ex. "e." si la requête utilise "FROM evenements e"). Les
// statuts 'envoye'/'manquant' comparent à la date du jour + délai configurable
// (mois) : l'appelant doit lier ce délai (evenements_delai_decompte_mois()) en
// tant que paramètre supplémentaire pour ces deux statuts uniquement.
function evenement_sql_statut_suisa(string $statut, string $prefixe = ''): string
{
    $applicable   = "{$prefixe}suisa_applicable = 1";
    $nonEnvoyee   = "{$prefixe}suisa_envoye_le = ''";
    $envoyeeSansDecompte = "{$prefixe}suisa_envoye_le <> '' AND {$prefixe}suisa_decompte_le = ''";
    $limite       = "date({$prefixe}suisa_envoye_le, '+' || ? || ' months')";
    return match ($statut) {
        'decompte_recu' => "$applicable AND {$prefixe}suisa_decompte_le <> ''",
        'a_faire'       => "$applicable AND $nonEnvoyee",
        'envoye'        => "$applicable AND $envoyeeSansDecompte AND $limite >= date('now')",
        'manquant'      => "$applicable AND $envoyeeSansDecompte AND $limite < date('now')",
        default         => "{$prefixe}suisa_applicable = 0", // ne_sapplique_pas
    };
}

// Délai (en mois) avant qu'une date SUISA envoyée mais jamais décomptée soit
// considérée « manquante ». Paramètre configurable (onglet Événements).
function evenements_delai_decompte_mois(): int
{
    return max(1, (int) param('suisa_delai_decompte_mois', '12'));
}

// Nombre d'événements SUISA « manquants » (badge de menu).
function nb_evenements_suisa_manquants(): int
{
    try {
        $n = 0;
        $stmt = db()->query(
            "SELECT suisa_applicable, suisa_envoye_le, suisa_decompte_le FROM evenements
             WHERE suisa_applicable = 1 AND suisa_envoye_le <> '' AND suisa_decompte_le = ''"
        );
        foreach ($stmt as $ev) {
            if (evenement_statut_suisa($ev) === 'manquant') {
                $n++;
            }
        }
        return $n;
    } catch (\Exception) {
        return 0;
    }
}

// Liste des spectacles pour un <select> — réutilisée par la liste des
// événements, le formulaire événement et l'onglet des paramètres.
function spectacles_pour_selection(): array
{
    return db()->query('SELECT id, nom FROM spectacles ORDER BY nom')->fetchAll();
}

// Vrai si $id correspond à un spectacle existant — validé avant tout
// INSERT/UPDATE d'un événement pour éviter une violation de clé étrangère
// (PRAGMA foreign_keys = ON) sur un id invalide/périmé posté.
function spectacle_existe(int $id): bool
{
    $stmt = db()->prepare('SELECT 1 FROM spectacles WHERE id = ?');
    $stmt->execute([$id]);
    return (bool) $stmt->fetchColumn();
}

// Remplace tous les liens d'un événement vers une table de jointure
// (evenement_employes / evenement_fiches) par $ids. $table/$colonne toujours
// des constantes internes, jamais une valeur utilisateur (même règle que
// supprimer_si_non_reference(), lib/helpers.php).
function evenement_sync_liens(int $evenementId, string $table, string $colonne, array $ids): void
{
    db()->prepare("DELETE FROM $table WHERE evenement_id = ?")->execute([$evenementId]);
    if (!$ids) {
        return;
    }
    $ins = db()->prepare("INSERT INTO $table (evenement_id, $colonne) VALUES (?, ?)");
    foreach ($ids as $id) {
        $ins->execute([$evenementId, $id]);
    }
}

// Validation stricte d'une date « Y-m-d » : DateTime::createFromFormat() seul
// accepterait silencieusement une date invalide comme "2026-02-30" en la
// « roulant » au 2 mars — checkdate() la rejette explicitement.
function date_valide(string $s): bool
{
    return (bool) preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)
        && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

// --------------------------------------------------------- EXPORT PUBLIC
// Un événement est exposable (site web / JSON / iCal) si sa visibilité n'est
// pas « non_repertorie » et que son statut n'est pas « option » (pas encore
// assez sûr pour être publié) — voir SPEC_EVENEMENTS.md §4.
function evenement_exportable(array $ev): bool
{
    return (string) $ev['visibilite'] !== 'non_repertorie' && (string) $ev['statut'] !== 'option';
}

// Données exposables d'un événement, filtrées selon la visibilité. $ev doit
// contenir un champ spectacle_nom (jointure sur spectacles) le cas échéant.
// Jamais de champ SUISA / facture / employés / fiches dans le résultat.
function evenement_export_donnees(array $ev): array
{
    $donnees = ['id' => (int) $ev['id'], 'date' => (string) $ev['date']];
    if ((string) $ev['visibilite'] === 'prive') {
        $donnees['prive'] = true;
        return $donnees;
    }
    $donnees['prive']   = false;
    $donnees['annule']  = (string) $ev['statut'] === 'annule';
    $donnees['ville']   = (string) ($ev['ville'] ?? '');
    if (trim((string) ($ev['salle'] ?? '')) !== '') {
        $donnees['salle'] = (string) $ev['salle'];
    }
    if (trim((string) ($ev['festival'] ?? '')) !== '') {
        $donnees['festival'] = (string) $ev['festival'];
    }
    if (trim((string) ($ev['lien_infos'] ?? '')) !== '') {
        $donnees['lien_infos'] = (string) $ev['lien_infos'];
    }
    if (trim((string) ($ev['spectacle_nom'] ?? '')) !== '') {
        $donnees['spectacle'] = (string) $ev['spectacle_nom'];
    }
    $donnees['remarques'] = (string) ($ev['remarques'] ?? '');
    return $donnees;
}

// Liste filtrée/formatée des événements exposables, triée par date. $spectacleId
// optionnel restreint l'export à un seul spectacle (point d'accès dédié, §8).
function evenements_a_exporter(?int $spectacleId = null): array
{
    $sql = "SELECT e.*, s.nom AS spectacle_nom FROM evenements e
            LEFT JOIN spectacles s ON s.id = e.spectacle_id
            WHERE e.visibilite <> 'non_repertorie' AND e.statut <> 'option'";
    $params = [];
    if ($spectacleId) {
        $sql .= ' AND e.spectacle_id = ?';
        $params[] = $spectacleId;
    }
    $sql .= ' ORDER BY e.date';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return array_map('evenement_export_donnees', $stmt->fetchAll());
}

// Jeton d'accès à l'export public (JSON/iCal). Généré au premier appel si absent.
function evenements_export_token(): string
{
    $token = (string) param('evenements_export_token', '');
    if ($token === '') {
        $token = bin2hex(random_bytes(16));
        db()->prepare('INSERT OR REPLACE INTO parametres (cle, valeur) VALUES (?, ?)')
            ->execute(['evenements_export_token', $token]);
    }
    return $token;
}

function evenements_regenerer_token(): string
{
    $token = bin2hex(random_bytes(16));
    db()->prepare('INSERT OR REPLACE INTO parametres (cle, valeur) VALUES (?, ?)')
        ->execute(['evenements_export_token', $token]);
    return $token;
}

// URL absolue d'un des deux points d'accès d'export, avec jeton (et filtre
// spectacle optionnel) déjà inclus — prête à copier-coller (onglet Paramètres).
function evenements_export_url(string $route, string $token, ?int $spectacleId = null): string
{
    $scheme = is_https() ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $qs     = 'p=' . $route . '&token=' . urlencode($token);
    if ($spectacleId) {
        $qs .= '&spectacle_id=' . $spectacleId;
    }
    return $scheme . '://' . $host . '/?' . $qs;
}

// Échappement des caractères spéciaux iCal (RFC 5545 §3.3.11).
function evenements_ical_echap(string $s): string
{
    return str_replace(["\\", "\n", ',', ';'], ['\\\\', '\\n', '\\,', '\\;'], $s);
}

// Flux iCal (.ics) à partir d'événements déjà filtrés/formatés (evenements_a_exporter()).
function evenements_generer_ical(array $items): string
{
    $lignes = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Lasso//Evenements//FR', 'CALSCALE:GREGORIAN'];
    foreach ($items as $it) {
        $date = str_replace('-', '', (string) $it['date']);
        if ($it['prive']) {
            $summary = 'Événement privé';
        } else {
            $summary = ((bool) $it['annule'] ? '[ANNULÉ] ' : '') . ($it['spectacle'] ?? 'Concert');
        }
        $lignes[] = 'BEGIN:VEVENT';
        $lignes[] = 'UID:evenement-' . $it['id'] . '@lasso';
        $lignes[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
        $lignes[] = 'DTSTART;VALUE=DATE:' . $date;
        $lignes[] = 'SUMMARY:' . evenements_ical_echap($summary);
        if (!$it['prive']) {
            $lieu = trim(($it['salle'] ?? '') !== '' ? ($it['salle'] . ', ' . ($it['ville'] ?? '')) : (string) ($it['ville'] ?? ''));
            if ($lieu !== '') {
                $lignes[] = 'LOCATION:' . evenements_ical_echap($lieu);
            }
            if (!empty($it['festival'])) {
                $lignes[] = 'DESCRIPTION:' . evenements_ical_echap('Festival : ' . $it['festival']);
            }
            if (!empty($it['lien_infos'])) {
                $lignes[] = 'URL:' . evenements_ical_echap($it['lien_infos']);
            }
        }
        $lignes[] = 'END:VEVENT';
    }
    $lignes[] = 'END:VCALENDAR';
    return implode("\r\n", $lignes);
}
