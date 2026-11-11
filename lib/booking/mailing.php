<?php
// Module booking — l'envoi aux structures : qui est éligible à un mailing
// (aperçu ET file d'attente lisent la même fonction, docs/SPEC_BOOKING.md §7),
// les expéditeurs, le message individuel du bouton « Contacter » et la liste
// d'exclusion. Chargé par lib/booking.php, jamais requis directement.

declare(strict_types=1);

// Un mois de festival (mois_debut..mois_fin, ex. 6..9) chevauche-t-il la plage
// filtrée (ex. avril=4..septembre=9) ? Gère le passage d'année (ex. nov.=11 à
// fév.=2) en testant les deux découpages possibles.
function periode_chevauche(int $debut, int $fin, int $filtreDebut, int $filtreFin): bool
{
    $moisDans = function (int $mois, int $d, int $f): bool {
        return $d <= $f ? ($mois >= $d && $mois <= $f) : ($mois >= $d || $mois <= $f);
    };
    for ($m = $debut; ; $m = $m % 12 + 1) {
        if ($moisDans($m, $filtreDebut, $filtreFin)) {
            return true;
        }
        if ($m === $fin) {
            break;
        }
    }
    return false;
}

// Filtre les structures éligibles à un mailing (aperçu ET constitution réelle
// de la file d'attente doivent utiliser cette même fonction, cf. §7 — jamais
// deux implémentations divergentes). $criteres (voir mailing_criteres_depuis()) :
// categorie_id (int[], arbre catégorie/sous-catégorie — mêmes ids que le
// filtre de ?p=structures, une catégorie racine choisie inclut tout son
// sous-arbre, résolu ici via structure_categorie_champs() comme dans
// structures_filtres()), pays/grande_region/departement_canton/ville
// (string[]), tag_id (int[]), mois_debut/mois_fin (int|null, période de
// programmation des lieux liés), mois_evenement_debut/fin (int|null, période
// des événements), contact_jamais (bool), contact_avant (date string|'').
// Exclut toujours les statuts ne_pas_contacter/inactif, en dernière étape,
// non contournable.
function mailing_structures_eligibles(array $criteres): array
{
    // Un ciblage ne sort JAMAIS des statuts contactables : « ne pas contacter »
    // et « inactif » n'entrent pas dans une campagne, quoi qu'on coche. Le
    // critère de statut ne fait donc que resserrer à l'intérieur de ceux-là —
    // par exemple aux seuls contacts privilégiés. Une sélection entièrement
    // hors de ces statuts (URL forgée, critères enregistrés devenus caducs) est
    // ignorée plutôt que de vider la liste sans rien dire.
    $statuts = STRUCTURE_STATUTS_CONTACTABLES;
    if (!empty($criteres['statut'])) {
        $choisis = array_values(array_intersect(
            array_map('strval', (array) $criteres['statut']),
            STRUCTURE_STATUTS_CONTACTABLES
        ));
        if ($choisis) {
            $statuts = $choisis;
        }
    }
    $where = ['s.statut IN (' . sql_in($statuts) . ')'];
    $params = $statuts;

    // Catégorie/sous-catégorie : une condition par id coché, unies en OR —
    // même logique que structures_filtres() dans lib/routes_facturation.php.
    if (!empty($criteres['categorie_id'])) {
        $map = structure_categorie_map();
        $catConds = [];
        foreach ($criteres['categorie_id'] as $cid) {
            $champs = structure_categorie_champs((int) $cid, $map);
            if ($champs['categorie'] === '') {
                continue;
            }
            if ($champs['sous_categorie'] === '') {
                $catConds[] = 's.categorie = ?';
                $params[] = $champs['categorie'];
            } else {
                $catConds[] = '(s.categorie = ? AND s.sous_categorie = ?)';
                $params[] = $champs['categorie'];
                $params[] = $champs['sous_categorie'];
            }
        }
        if ($catConds) {
            $where[] = '(' . implode(' OR ', $catConds) . ')';
        }
    }
    if (!empty($criteres['pays'])) {
        $where[] = 's.adresse_pays IN (' . sql_in($criteres['pays']) . ')';
        $params = array_merge($params, $criteres['pays']);
    }
    // grande_region = « Région » (Normandie, Romandie…) ; departement_canton = « Département / canton ».
    if (!empty($criteres['grande_region'])) {
        $where[] = 's.grande_region IN (' . sql_in($criteres['grande_region']) . ')';
        $params = array_merge($params, $criteres['grande_region']);
    }
    if (!empty($criteres['departement_canton'])) {
        $where[] = 's.departement_canton IN (' . sql_in($criteres['departement_canton']) . ')';
        $params = array_merge($params, $criteres['departement_canton']);
    }
    if (!empty($criteres['ville'])) {
        $where[] = 's.adresse_localite IN (' . sql_in($criteres['ville']) . ')';
        $params = array_merge($params, $criteres['ville']);
    }
    // Tags et campagnes : mêmes règles, et « aucun » y est une option à part
    // entière — cochée avec des identifiants, elle les REJOINT (OU), elle ne les
    // annule pas : « sans tag, ou avec celui-là » est une question qu'on pose.
    $lien = function (array $valeurs, string $table, string $colonne) use (&$where, &$params): void {
        $valeurs = array_map('strval', $valeurs);
        $ids = array_values(array_filter($valeurs, fn ($v) => $v !== 'aucun'));
        $conds = [];
        if (in_array('aucun', $valeurs, true)) {
            $conds[] = "s.id NOT IN (SELECT structure_id FROM $table)";
        }
        if ($ids) {
            $conds[] = "s.id IN (SELECT structure_id FROM $table WHERE $colonne IN (" . sql_in($ids) . '))';
            $params = array_merge($params, $ids);
        }
        if ($conds) {
            $where[] = '(' . implode(' OR ', $conds) . ')';
        }
    };
    if (!empty($criteres['tag_id'])) {
        $lien($criteres['tag_id'], 'structure_tag_liens', 'tag_id');
    }
    if (!empty($criteres['campagne_id'])) {
        $lien($criteres['campagne_id'], 'campagne_structures', 'campagne_id');
    }
    if (!empty($criteres['contact_jamais'])) {
        $where[] = "s.dernier_contact_le = ''";
    } elseif (!empty($criteres['contact_avant'])) {
        $where[] = "(s.dernier_contact_le = '' OR s.dernier_contact_le < ?)";
        $params[] = (string) $criteres['contact_avant'];
    }

    $sql = 'SELECT s.* FROM structures s WHERE ' . implode(' AND ', $where) . ' ORDER BY s.nom';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $structures = $stmt->fetchAll();

    // Filtres de période — appliqués en PHP (pas en SQL) car le chevauchement
    // gère le passage d'année (periode_chevauche()), pas exprimable simplement
    // en SQL. Deux plages, directement sur la structure depuis la fusion
    // lieux→structures (migration_59/60) : mois_debut/mois_fin = « Préparé
    // de… à » (période de programmation), mois_evenement_debut/fin =
    // « Événements de… à » (quand ça a lieu).
    $filtrePeriode = function (array $structures, string $colDebut, string $colFin, int $md, int $mf): array {
        return array_values(array_filter($structures, function ($s) use ($colDebut, $colFin, $md, $mf) {
            if ($s[$colDebut] === null || $s[$colFin] === null) {
                return false;
            }
            return periode_chevauche((int) $s[$colDebut], (int) $s[$colFin], $md, $mf);
        }));
    };
    if (!empty($criteres['mois_debut']) && !empty($criteres['mois_fin'])) {
        $structures = $filtrePeriode($structures, 'mois_debut', 'mois_fin', (int) $criteres['mois_debut'], (int) $criteres['mois_fin']);
    }
    if (!empty($criteres['mois_evenement_debut']) && !empty($criteres['mois_evenement_fin'])) {
        $structures = $filtrePeriode($structures, 'mois_evenement_debut', 'mois_evenement_fin', (int) $criteres['mois_evenement_debut'], (int) $criteres['mois_evenement_fin']);
    }

    return $structures;
}

// Destinataires réels d'un mailing pour les structures éligibles : un contact
// actif et non désinscrit par structure (email renseigné), ou à défaut
// l'e-mail de la structure elle-même (si renseigné et la structure n'a pas de
// contact exploitable) — jamais un contact désinscrit même si la structure ne
// l'est pas (opt-out par contact, §4).
function mailing_destinataires(array $criteres): array
{
    $structures = mailing_structures_eligibles($criteres);
    // Liste d'exclusion « ne pas contacter » (mailing_exclusions) : chargée une
    // fois, appliquée en dernier — non contournable, y compris sur l'e-mail de
    // repli de la structure.
    $exclus = [];
    foreach (db()->query('SELECT email FROM mailing_exclusions') as $x) {
        $exclus[mb_strtolower(trim((string) $x['email']), 'UTF-8')] = true;
    }
    $estExclu = fn (string $e): bool => isset($exclus[mb_strtolower(trim($e), 'UTF-8')]);
    $destinataires = [];
    foreach ($structures as $s) {
        $stmt = db()->prepare(
            'SELECT ' . CONTACT_SELECT . CONTACT_FROM . "
             WHERE cs.structure_id = ? AND cs.actif = 1 AND pe.desinscrit = 0 AND pe.email <> ''
             ORDER BY cs.id"
        );
        $stmt->execute([(int) $s['id']]);
        $contacts = $stmt->fetchAll();
        // Plusieurs contacts et au moins un marqué « booking » : mailing réservé à
        // ceux-là (voir la fiche structure) — sinon, comportement inchangé
        // (envoi à tous les contacts actifs de la structure).
        $booking = array_values(array_filter($contacts, fn ($c) => (int) $c['est_booking'] === 1));
        if ($booking) {
            $contacts = $booking;
        }
        if ($contacts) {
            foreach ($contacts as $c) {
                if (!$estExclu((string) $c['email'])) {
                    $destinataires[] = ['structure' => $s, 'contact' => $c, 'email' => $c['email']];
                }
            }
        } elseif ($s['email'] !== '' && !$estExclu((string) $s['email'])) {
            $destinataires[] = ['structure' => $s, 'contact' => null, 'email' => $s['email']];
        }
    }
    return $destinataires;
}

// Expéditeurs possibles d'un mailing (table mailing_expediteurs, écran
// Paramètres → E-mails). Un expéditeur n'est pas qu'une adresse : c'est une
// BOÎTE, avec son propre serveur SMTP — « diffusion@ » et « production@ »
// peuvent vivre chez deux hébergeurs différents. Les champs SMTP laissés vides
// retombent sur le profil booking puis sur le profil général
// (smtp_config_booking(), lib/helpers.php).
// Le premier de la liste (ordre, puis id) sert de défaut.
function mailing_expediteurs(): array
{
    return db()->query('SELECT * FROM mailing_expediteurs ORDER BY ordre, id')->fetchAll();
}

// Un expéditeur par identifiant, ou null. Sert aussi de validation : une valeur
// venue d'un POST qui ne désigne aucune ligne ne donne rien, l'adresse
// d'expédition n'étant pas un champ libre.
function mailing_expediteur(?int $id): ?array
{
    if (!$id) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM mailing_expediteurs WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

// Expéditeur retenu pour un envoi : celui demandé s'il existe, sinon le premier
// déclaré. Peut être null — aucune boîte déclarée : l'envoi repart alors de
// l'adresse d'expédition générale, comme avant les expéditeurs multiples.
function mailing_expediteur_ou_defaut(?int $id): ?array
{
    return mailing_expediteur($id) ?? (mailing_expediteurs()[0] ?? null);
}

// Adresse « De : » d'un expéditeur — « Nom <adresse> » s'il porte un nom, pour
// que le destinataire voie autre chose qu'une boîte technique.
function mailing_expediteur_from(?array $expediteur): string
{
    if (!$expediteur) {
        return (string) param('employeur_email_expediteur');
    }
    $email = trim((string) $expediteur['email']);
    $nom = trim((string) $expediteur['nom']);
    return $nom !== '' ? $nom . ' <' . $email . '>' : $email;
}

// Libellé du défaut, pour l'option « Par défaut — … » des listes déroulantes.
function mailing_expediteur_defaut_libelle(): string
{
    $d = mailing_expediteurs()[0] ?? null;
    return $d ? mailing_expediteur_libelle($d) : (string) param('employeur_email_expediteur');
}

// Libellé d'un expéditeur dans les listes déroulantes.
function mailing_expediteur_libelle(array $expediteur): string
{
    $nom = trim((string) $expediteur['nom']);
    return $nom !== '' ? $nom . ' — ' . $expediteur['email'] : (string) $expediteur['email'];
}


// --- Message individuel écrit depuis une fiche structure (bouton « Contacter »)

// Contacts d'une structure joignables par e-mail. Mêmes conditions QUE POUR UN
// MAILING (mailing_structures_eligibles() ci-dessus) : contact actif, non
// désinscrit, avec une adresse, et cette adresse hors liste d'exclusion. Se
// désinscrire d'un mailing pose « desinscrit », pas une ligne d'exclusion (voir
// route_desinscription()) : ne regarder que la liste d'exclusion aurait laissé
// écrire à quelqu'un qui vient de demander à ne plus rien recevoir.
function structure_contacts_joignables(int $structureId): array
{
    $stmt = db()->prepare(
        'SELECT ' . CONTACT_SELECT . CONTACT_FROM . "
          WHERE cs.structure_id = ? AND cs.actif = 1 AND pe.desinscrit = 0 AND pe.email <> ''
          ORDER BY cs.est_booking DESC, cs.id"
    );
    $stmt->execute([$structureId]);
    return array_values(array_filter($stmt->fetchAll(), fn ($c) => !mailing_email_exclu((string) $c['email'])));
}

// Contacts joignables d'une structure ET de celles qui l'organisent — sa
// « structure mère » au sens de structure_organisateurs. Une salle rattachée à
// un festival, une antenne rattachée à sa faîtière : l'interlocuteur n'est
// souvent pas sur la fiche fille mais sur la mère, et il fallait jusqu'ici
// changer de page pour lui écrire.
//
// Chaque contact repris porte le nom de sa structure d'origine, pour que la
// liste des destinataires ne laisse aucun doute sur qui l'on contacte. Les
// contacts propres à la fiche restent en tête.
//
// Un seul niveau : la mère, pas la grand-mère. Remonter toute la chaîne
// mélangerait des interlocuteurs de plus en plus lointains dans une liste
// destinée à un message individuel.
function structure_contacts_joignables_etendus(int $structureId): array
{
    $propres = structure_contacts_joignables($structureId);
    foreach ($propres as &$c) {
        $c['structure_nom'] = '';
    }
    unset($c);

    $stmt = db()->prepare(
        'SELECT organisateur_id FROM structure_organisateurs WHERE structure_id = ?'
    );
    $stmt->execute([$structureId]);
    $meres = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    $repris = [];
    foreach ($meres as $mere) {
        $nom = db()->prepare('SELECT nom FROM structures WHERE id = ?');
        $nom->execute([$mere]);
        $nomMere = (string) $nom->fetchColumn();
        foreach (structure_contacts_joignables($mere) as $c) {
            $c['structure_nom'] = $nomMere;
            $repris[] = $c;
        }
    }
    return array_merge($propres, $repris);
}

// Ce qui empêche d'écrire à une structure, en clair — '' si rien ne l'empêche.
// Une seule fonction pour la décision ET pour sa formulation : le bouton
// « Contacter » s'affiche désactivé avec cette phrase en infobulle, et la route
// d'envoi refuse sur la même base. Deux tests séparés auraient fini par ne plus
// dire la même chose.
function structure_contact_impossible_raison(array $structure, array $contactsJoignables): string
{
    $statut = (string) ($structure['statut'] ?? '');
    if (!in_array($statut, STRUCTURE_STATUTS_CONTACTABLES, true)) {
        return 'Structure « ' . structure_statut_libelle($statut) . ' » : on ne la contacte pas.';
    }
    if (!$contactsJoignables) {
        return "Aucun contact joignable : il faut une adresse e-mail hors liste d'exclusion.";
    }
    return '';
}

function structure_contactable(array $structure, array $contactsJoignables): bool
{
    return structure_contact_impossible_raison($structure, $contactsJoignables) === '';
}

// Brouillon en cours pour une structure (un seul, voir migration_72).
function structure_message_brouillon(int $structureId): ?array
{
    $stmt = db()->prepare('SELECT * FROM structure_message_brouillons WHERE structure_id = ?');
    $stmt->execute([$structureId]);
    return $stmt->fetch() ?: null;
}

// Résout les variables {{prenom}}/{{nom_structure}} d'un gabarit de mailing.
function mailing_personnaliser(string $texte, array $structure, ?array $contact): string
{
    return str_replace(
        ['{{prenom}}', '{{nom_structure}}'],
        [$contact['prenom'] ?? '', $structure['nom'] ?? ''],
        $texte
    );
}

// Ajoute chaque e-mail à la liste « ne pas contacter » (table mailing_exclusions,
// §8 point 5) — SANS créer de structure fantôme pour un e-mail inconnu. Si des
// contacts existants portent cette adresse, ils sont aussi marqués désinscrits
// (opt-out visible sur leur fiche). La table reste la référence, non
// contournable : filtrée à l'envoi (mailing_destinataires) et respectée par
// tout import ultérieur (structure_import_attacher_contact).
function structures_importer_liste_exclusion(array $emails): int
{
    $n = 0;
    $ins = db()->prepare('INSERT OR IGNORE INTO mailing_exclusions (email) VALUES (?)');
    // La désinscription vaut pour la PERSONNE, partout : qui se désinscrit
    // ne veut plus rien recevoir, pas « plus rien de cette salle-là ».
    $maj = db()->prepare('UPDATE personnes SET desinscrit = 1 WHERE email = ? COLLATE NOCASE');
    foreach ($emails as $email) {
        $email = trim($email);
        if ($email === '') {
            continue;
        }
        $ins->execute([$email]);
        $maj->execute([$email]);
        $n++;
    }
    return $n;
}

// L'adresse figure-t-elle dans la liste d'exclusion « ne pas contacter » ?
function mailing_email_exclu(string $email): bool
{
    $email = trim($email);
    if ($email === '') {
        return false;
    }
    $stmt = db()->prepare('SELECT 1 FROM mailing_exclusions WHERE email = ? COLLATE NOCASE');
    $stmt->execute([$email]);
    return (bool) $stmt->fetchColumn();
}
