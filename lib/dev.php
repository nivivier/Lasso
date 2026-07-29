<?php
// Scripts de maintenance ponctuels, lançables en CLI (scripts/…) ET depuis
// l'onglet Paramètres → Dev (lib/routes_dev.php). Toute la logique vit ici
// pour que les deux points d'entrée restent en phase ; les scripts CLI et la
// route web ne font qu'appeler ces fonctions et présenter le résultat.

declare(strict_types=1);

// ===========================================================================
// Doublons exacts (structures, lieux, contacts) — voir scripts/doublons.php
// ===========================================================================

// Détecte les groupes de doublons exacts. $type : structures|lieux|contacts|tous.
// Retourne ['structures' => [...], 'lieux' => [...], 'contacts' => [...]], où
// chaque groupe est ['libelle' => …, 'ids' => [garde, autres…]] (plus petit id
// en tête = fiche la plus ancienne, conservée).
function doublons_detecter(string $type = 'tous'): array
{
    $grouper = function (string $sql): array {
        $out = [];
        foreach (db()->query($sql)->fetchAll() as $r) {
            $ids = array_map('intval', explode(',', (string) $r['ids']));
            sort($ids);
            $out[] = ['libelle' => (string) $r['libelle'], 'ids' => $ids];
        }
        return $out;
    };

    $grStructures = ($type === 'structures' || $type === 'tous') ? $grouper(
        "SELECT nom || CASE WHEN TRIM(adresse_localite) <> '' THEN ' — ' || adresse_localite ELSE '' END AS libelle,
                GROUP_CONCAT(id) AS ids
         FROM structures
         GROUP BY TRIM(LOWER(nom)), TRIM(LOWER(adresse_localite))
         HAVING COUNT(*) > 1
         ORDER BY COUNT(*) DESC, nom"
    ) : [];

    $grLieux = ($type === 'lieux' || $type === 'tous') ? $grouper(
        "SELECT nom || CASE WHEN TRIM(ville) <> '' THEN ' — ' || ville ELSE '' END || ' (' || type || ')' AS libelle,
                GROUP_CONCAT(id) AS ids
         FROM lieux
         GROUP BY TRIM(LOWER(nom)), TRIM(LOWER(ville)), TRIM(LOWER(type))
         HAVING COUNT(*) > 1
         ORDER BY COUNT(*) DESC, nom"
    ) : [];

    $grContacts = [];
    if ($type === 'contacts' || $type === 'tous') {
        $grContacts = $grouper(
            "SELECT c.email || ' (structure ' || c.structure_id || ')' AS libelle, GROUP_CONCAT(c.id) AS ids
             FROM structure_contacts c
             WHERE TRIM(c.email) <> ''
             GROUP BY c.structure_id, TRIM(LOWER(c.email))
             HAVING COUNT(*) > 1"
        );
        $vus = [];
        foreach ($grContacts as $g) {
            foreach ($g['ids'] as $i) { $vus[$i] = true; }
        }
        foreach ($grouper(
            "SELECT TRIM(prenom || ' ' || nom) || ' (structure ' || structure_id || ')' AS libelle, GROUP_CONCAT(id) AS ids
             FROM structure_contacts
             WHERE TRIM(email) = '' AND TRIM(prenom || nom) <> ''
             GROUP BY structure_id, TRIM(LOWER(prenom)), TRIM(LOWER(nom)), TRIM(telephone)
             HAVING COUNT(*) > 1"
        ) as $g) {
            if (!array_intersect_key(array_flip($g['ids']), $vus)) {
                $grContacts[] = $g;
            }
        }
    }

    return ['structures' => $grStructures, 'lieux' => $grLieux, 'contacts' => $grContacts];
}

// Complète les champs vides de la fiche gardée avec ceux d'un doublon.
function doublons_completer_champs_vides(string $table, int $garde, int $autre, array $colonnes): void
{
    $sets = [];
    foreach ($colonnes as $c) {
        $sets[] = "$c = CASE WHEN TRIM(COALESCE($c, '')) = '' THEN (SELECT $c FROM $table WHERE id = :autre) ELSE $c END";
    }
    db()->prepare("UPDATE $table SET " . implode(', ', $sets) . ' WHERE id = :garde')
        ->execute([':autre' => $autre, ':garde' => $garde]);
}

// Fusionne des groupes de structures en doublon. Renvoie le nombre de fiches supprimées.
function doublons_fusionner_structures(array $groupes): int
{
    $n = 0;
    foreach ($groupes as $g) {
        $ids = $g['ids'];
        $garde = array_shift($ids);
        if (!$ids) { continue; }
        structures_fusionner($garde, $ids); // gère sa propre transaction
        $n += count($ids);
    }
    return $n;
}

// Fusionne des groupes de lieux en doublon. Renvoie le nombre de fiches supprimées.
function doublons_fusionner_lieux(array $groupes): int
{
    $n = 0;
    foreach ($groupes as $g) {
        $ids = $g['ids'];
        $garde = array_shift($ids);
        foreach ($ids as $autre) {
            db()->beginTransaction();
            doublons_completer_champs_vides('lieux', $garde, $autre, ['departement_canton', 'grande_region', 'pays', 'site_web', 'notes', 'dernier_concert_le']);
            db()->prepare('INSERT OR IGNORE INTO structure_lieux (structure_id, lieu_id) SELECT structure_id, ? FROM structure_lieux WHERE lieu_id = ?')
                ->execute([$garde, $autre]);
            db()->prepare('DELETE FROM structure_lieux WHERE lieu_id = ?')->execute([$autre]);
            db()->prepare('UPDATE evenements SET lieu_id = ? WHERE lieu_id = ?')->execute([$garde, $autre]);
            db()->prepare("UPDATE historique SET entite_id = ? WHERE entite_type = 'lieu' AND entite_id = ?")->execute([$garde, $autre]);
            db()->prepare('DELETE FROM lieux WHERE id = ?')->execute([$autre]);
            db()->commit();
            $n++;
        }
    }
    return $n;
}

// Fusionne des groupes de contacts en doublon. Renvoie le nombre de fiches supprimées.
function doublons_fusionner_contacts(array $groupes): int
{
    $n = 0;
    foreach ($groupes as $g) {
        $ids = $g['ids'];
        $garde = array_shift($ids);
        foreach ($ids as $autre) {
            db()->beginTransaction();
            doublons_completer_champs_vides('structure_contacts', $garde, $autre, ['prenom', 'nom', 'role', 'email', 'telephone', 'formulaire_url', 'langue']);
            // Drapeaux cumulés : le contact conservé hérite des rôles du doublon.
            db()->prepare(
                'UPDATE structure_contacts SET
                    est_administration = MAX(est_administration, (SELECT est_administration FROM structure_contacts WHERE id = :autre)),
                    est_booking        = MAX(est_booking,        (SELECT est_booking        FROM structure_contacts WHERE id = :autre)),
                    desinscrit         = MAX(desinscrit,         (SELECT desinscrit         FROM structure_contacts WHERE id = :autre))
                 WHERE id = :garde'
            )->execute([':autre' => $autre, ':garde' => $garde]);
            db()->prepare('UPDATE mailing_file_attente SET contact_id = ? WHERE contact_id = ?')->execute([$garde, $autre]);
            db()->prepare('UPDATE mailing_envois SET contact_id = ? WHERE contact_id = ?')->execute([$garde, $autre]);
            db()->prepare('DELETE FROM structure_contacts WHERE id = ?')->execute([$autre]);
            db()->commit();
            $n++;
        }
    }
    return $n;
}

// ===========================================================================
// Mise à jour des dates depuis un CSV — voir scripts/maj_dates_import.php
// ===========================================================================

// Intitulés de colonnes reconnus par défaut (comparaison sans accents).
const MAJ_DATES_COLONNES = [
    'nom'     => ['nom', 'nom de la structure', 'structure'],
    'ville'   => ['ville', 'localite', 'localité', 'commune'],
    'email'   => ['email', 'e-mail', 'mail', 'courriel'],
    'contact' => ['dernier contact', 'date dernier contact', 'contact'],
    'concert' => ['dernier concert', 'dernier concert ou diffusion', 'derniere diffusion', 'dernière diffusion'],
    'maj'     => ['mise a jour', 'mise à jour', 'maj', 'date de mise a jour'],
];

// Repère l'index de chaque colonne reconnue dans l'entête du CSV. $intitules
// (facultatif, CLI seulement) : champ => intitulé exact à viser, au lieu de la
// détection automatique par alias.
// Retourne ['nom' => int|null, 'ville' => int|null, …].
function maj_dates_reperer_colonnes(array $entete, array $intitules = []): array
{
    $index = [];
    foreach (MAJ_DATES_COLONNES as $champ => $alias) {
        $index[$champ] = null;
        $vise = isset($intitules[$champ]) && $intitules[$champ] !== '' ? texte_sans_accents($intitules[$champ]) : null;
        foreach ($entete as $i => $titre) {
            $t = texte_sans_accents(trim((string) $titre));
            if ($vise !== null ? $t === $vise : in_array($t, array_map('texte_sans_accents', $alias), true)) {
                $index[$champ] = $i;
                break;
            }
        }
    }
    return $index;
}

// Précharge les index nécessaires au rapprochement + aux dates actuelles.
// Un SELECT par ligne laisserait un curseur ouvert, ce qui ferait échouer le
// VACUUM de sauvegarde — tout est chargé d'un coup.
function maj_dates_construire_index(): array
{
    $indexNom = [];   // nom normalisé → [ ['id','ville'], … ]
    $indexEmail = []; // e-mail → id de structure
    foreach (db()->query('SELECT id, nom, adresse_localite FROM structures') as $s) {
        $cle = normaliser_nom_structure((string) $s['nom']);
        if ($cle !== '') {
            $indexNom[$cle][] = ['id' => (int) $s['id'], 'ville' => normaliser_nom_structure((string) ($s['adresse_localite'] ?? ''))];
        }
    }
    // Un e-mail n'est pas forcément unique (adresse générique reprise par
    // plusieurs structures) : on garde toutes les structures candidates, avec
    // leur ville, pour permettre la même discrimination que le rapprochement
    // par nom ci-dessous.
    foreach (db()->query(
        "SELECT c.email, c.structure_id, s.adresse_localite
         FROM structure_contacts c JOIN structures s ON s.id = c.structure_id
         WHERE c.email <> ''"
    ) as $c) {
        $cle = mb_strtolower(trim((string) $c['email']), 'UTF-8');
        if ($cle === '') { continue; }
        $sid = (int) $c['structure_id'];
        $dejaCandidate = false;
        foreach ($indexEmail[$cle] ?? [] as $cand) {
            if ($cand['id'] === $sid) { $dejaCandidate = true; break; }
        }
        if (!$dejaCandidate) {
            $indexEmail[$cle][] = ['id' => $sid, 'ville' => normaliser_nom_structure((string) ($c['adresse_localite'] ?? ''))];
        }
    }
    $datesParStructure = [];
    foreach (db()->query('SELECT id, nom, mise_a_jour_le, dernier_contact_le FROM structures')->fetchAll() as $r) {
        $datesParStructure[(int) $r['id']] = $r;
    }
    $lieuxParStructure = [];
    foreach (db()->query('SELECT sl.structure_id, l.id, l.nom, l.dernier_concert_le FROM structure_lieux sl JOIN lieux l ON l.id = sl.lieu_id') as $r) {
        $lieuxParStructure[(int) $r['structure_id']][] = $r;
    }
    return [$indexNom, $indexEmail, $datesParStructure, $lieuxParStructure];
}

// Analyse les lignes du CSV face à la base : détermine ce qui serait écrit,
// sans rien modifier. Retourne :
//   ['stats' => [...], 'aEcrire' => [ ['type','id','date','libelle'], … ],
//    'nonTrouvees' => [libellés], 'ambigues' => [libellés]]
function maj_dates_analyser(array $entete, array $lignes, array $index): array
{
    [$indexNom, $indexEmail, $datesParStructure, $lieuxParStructure] = maj_dates_construire_index();

    $val = fn(array $ligne, ?int $i): string => $i === null ? '' : trim((string) ($ligne[$i] ?? ''));

    $stats = ['lignes' => 0, 'sans_correspondance' => 0, 'ambigues' => 0,
              'maj' => 0, 'contact' => 0, 'concert' => 0];
    $aEcrire = [];
    $nonTrouvees = [];
    $ambigues = [];

    foreach ($lignes as $ligne) {
        $nom = $val($ligne, $index['nom']);
        if ($nom === '') { continue; }
        $stats['lignes']++;
        $ville = $val($ligne, $index['ville']);
        $email = mb_strtolower($val($ligne, $index['email']), 'UTF-8');

        // Rapprochement : e-mail, sinon nom — dans les deux cas discriminé par
        // la ville si plusieurs structures partagent le même e-mail ou le même
        // nom (jamais d'homonyme deviné à l'aveugle).
        $vNorm = normaliser_nom_structure($ville);
        $sid = null;
        if ($email !== '' && isset($indexEmail[$email])) {
            $cands = $indexEmail[$email];
            if ($vNorm !== '') {
                foreach ($cands as $c) { if ($c['ville'] === $vNorm) { $sid = $c['id']; break; } }
            } elseif (count($cands) === 1) {
                $sid = $cands[0]['id'];
            }
        }
        if ($sid === null) {
            $cands = $indexNom[normaliser_nom_structure($nom)] ?? [];
            if ($vNorm !== '') {
                foreach ($cands as $c) { if ($c['ville'] === $vNorm) { $sid = $c['id']; break; } }
            } elseif (count($cands) === 1) {
                $sid = $cands[0]['id'];
            } elseif (count($cands) > 1) {
                $stats['ambigues']++;
                $ambigues[] = $nom;
                continue;
            }
        }
        if ($sid === null) {
            $stats['sans_correspondance']++;
            $nonTrouvees[] = $nom . ($ville !== '' ? " ($ville)" : '');
            continue;
        }

        $actuel = $datesParStructure[$sid] ?? [];

        $dMaj = structure_date_csv_vers_iso($val($ligne, $index['maj']));
        if ($dMaj !== null && $dMaj !== (string) ($actuel['mise_a_jour_le'] ?? '')) {
            $aEcrire[] = ['structure_maj', $sid, $dMaj, "$nom : mise à jour → $dMaj"];
            $stats['maj']++;
        }
        $dContact = structure_date_csv_vers_iso($val($ligne, $index['contact']));
        if ($dContact !== null && $dContact !== (string) ($actuel['dernier_contact_le'] ?? '')) {
            $aEcrire[] = ['structure_contact', $sid, $dContact, "$nom : dernier contact → $dContact"];
            $stats['contact']++;
        }
        $dConcert = structure_date_csv_vers_iso($val($ligne, $index['concert']));
        if ($dConcert !== null) {
            foreach ($lieuxParStructure[$sid] ?? [] as $l) {
                if ($dConcert !== (string) ($l['dernier_concert_le'] ?? '')) {
                    $aEcrire[] = ['lieu_concert', (int) $l['id'], $dConcert, "  ↳ lieu « {$l['nom']} » : dernier concert → $dConcert"];
                    $stats['concert']++;
                }
            }
        }
    }

    return ['stats' => $stats, 'aEcrire' => $aEcrire, 'nonTrouvees' => $nonTrouvees, 'ambigues' => $ambigues];
}

// Applique les écritures déterminées par maj_dates_analyser(). L'appelant est
// responsable de la sauvegarde préalable (sauvegarder_base()).
function maj_dates_appliquer(array $aEcrire): void
{
    db()->beginTransaction();
    foreach ($aEcrire as [$type, $id, $date, $libelle]) {
        if ($type === 'structure_maj') {
            db()->prepare('UPDATE structures SET mise_a_jour_le = ? WHERE id = ?')->execute([$date, $id]);
        } elseif ($type === 'structure_contact') {
            db()->prepare('UPDATE structures SET dernier_contact_le = ? WHERE id = ?')->execute([$date, $id]);
            journaliser_contact_import($id, $date, 'Import CSV — dernier contact connu.');
        } elseif ($type === 'lieu_concert') {
            db()->prepare('UPDATE lieux SET dernier_concert_le = ? WHERE id = ?')->execute([$date, $id]);
            journaliser('lieu', $id, 'dernier_concert', 'Dernier concert / diffusion (import) : ' . $date, $date);
        }
    }
    db()->commit();
}

// ===========================================================================
// Grandes régions déduites du département/canton (structures, lieux,
// événements) — voir grande_region_deduite() (lib/helpers.php). Rattrapage
// des fiches existantes dont la grande région diverge de ce que déduirait
// aujourd'hui le département/canton déjà renseigné (variantes de la
// taxonomie, saisie manuelle antérieure…). Jamais les cantons bilingues
// (Fribourg/Valais/Berne) : la déduction y est volontairement non fiable
// (grande_region_deduite($pays, $departementCanton) en mode strict), donc
// jamais proposée en écart ici.
// ===========================================================================

const GRANDE_REGION_TABLES = [
    'structures' => ['pays_col' => 'adresse_pays', 'nom_sql' => 'nom', 'pays_code' => false],
    'lieux'      => ['pays_col' => 'pays', 'nom_sql' => 'nom', 'pays_code' => false],
    'evenements' => ['pays_col' => 'pays', 'nom_sql' => "ville || CASE WHEN TRIM(salle) <> '' THEN ' — ' || salle ELSE '' END", 'pays_code' => true],
];

// Détecte les écarts entre grande_region actuelle et déduite. Retourne
// [ ['table'=>, 'id'=>, 'nom'=>, 'pays'=>, 'departement_canton'=>, 'actuelle'=>, 'deduite'=>], … ].
function grande_regions_detecter(): array
{
    // Chargée une fois pour toute la détection (au lieu d'une requête
    // départements_regions par ligne France dans grande_region_deduite()).
    $departementsFranceCache = departements_regions_map();
    $out = [];
    foreach (GRANDE_REGION_TABLES as $table => $def) {
        $sql = "SELECT id, ({$def['nom_sql']}) AS nom, {$def['pays_col']} AS pays, departement_canton, grande_region
                FROM $table WHERE TRIM(departement_canton) <> ''";
        foreach (db()->query($sql)->fetchAll() as $r) {
            $deduite = grande_region_deduite((string) $r['pays'], (string) $r['departement_canton'], true, $departementsFranceCache);
            if ($deduite !== null && $deduite !== (string) $r['grande_region']) {
                $out[] = [
                    'table' => $table, 'id' => (int) $r['id'], 'nom' => (string) $r['nom'],
                    'pays' => (string) $r['pays'], 'departement_canton' => (string) $r['departement_canton'],
                    'actuelle' => (string) $r['grande_region'], 'deduite' => $deduite,
                ];
            }
        }
    }
    return $out;
}

// Applique les grandes régions déduites (voir grande_regions_detecter()).
// Renvoie le nombre de fiches modifiées.
function grande_regions_appliquer(array $lignes): int
{
    $n = 0;
    db()->beginTransaction();
    try {
        foreach ($lignes as $l) {
            if (!isset(GRANDE_REGION_TABLES[$l['table']])) {
                continue;
            }
            db()->prepare("UPDATE {$l['table']} SET grande_region = ? WHERE id = ?")->execute([$l['deduite'], $l['id']]);
            $paysNom = GRANDE_REGION_TABLES[$l['table']]['pays_code'] ? pays_nom_depuis_code($l['pays']) : $l['pays'];
            if ($paysNom !== '') {
                pays_region_assurer($paysNom, $l['deduite']);
            }
            $n++;
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
    return $n;
}
