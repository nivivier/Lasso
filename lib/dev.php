<?php
// Scripts de maintenance ponctuels, lançables en CLI (scripts/…) ET depuis
// l'onglet Paramètres → Dev (lib/routes_dev.php). Toute la logique vit ici
// pour que les deux points d'entrée restent en phase ; les scripts CLI et la
// route web ne font qu'appeler ces fonctions et présenter le résultat.

declare(strict_types=1);

// ===========================================================================
// Doublons exacts (structures, contacts) — voir scripts/doublons.php. Les
// lieux ne sont plus un type à part depuis la fusion lieux→structures
// (migration_59/60/61) : un doublon de lieu EST un doublon de structure,
// déjà couvert par le type 'structures'.
// ===========================================================================

// Détecte les groupes de doublons exacts. $type : structures|contacts|tous.
// Retourne ['structures' => [...], 'contacts' => [...]], où chaque groupe est
// ['libelle' => …, 'ids' => [garde, autres…]] (plus petit id en tête = fiche
// la plus ancienne, conservée).
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

    return ['structures' => $grStructures, 'contacts' => $grContacts];
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
// Doublons POTENTIELS (structures) — noms voisins dans le même canton.
//
// Les doublons EXACTS ci-dessus se détectent par un GROUP BY : même nom, même
// localité. Restent hors de leur portée les fiches saisies deux fois avec une
// variante — « Théâtre du Passage » / « Le Théâtre du Passage », une accroche
// en plus, une faute de frappe — que rien ne rapproche en SQL.
//
// Deux critères, tous deux exigés :
//   1. le département / canton est identique et NON VIDE. C'est le garde-fou
//      qui rend le balayage réaliste : sans lui, 2965 structures font 4,4
//      millions de paires, et deux salles homonymes à 500 km l'une de l'autre
//      remonteraient comme doublon. Les fiches sans canton ne sont donc pas
//      comparées — l'écran le dit.
//   2. les noms se ressemblent à au moins $seuil % (similar_text(), sur des
//      noms mis à plat : minuscules, sans accents ni ponctuation, pour que
//      « Anti-Concert » et « anti concert » soient la même chaîne).
//
// Le résultat est une liste de PAIRES, jamais des groupes transitifs : si A
// ressemble à B et B à C, A et C peuvent n'avoir rien à voir, et fondre les
// trois en un seul groupe proposerait une fusion que personne n'a jugée.
// C'est aussi ce qui rend l'exclusion stable — voir doublons_potentiels_cle().
// ===========================================================================

// Seuils proposés à l'écran. En dessous de 80 %, le bruit dépasse largement les
// vraies trouvailles (mesuré sur la base réelle : 338 paires à 80 %, 119 à
// 85 %, 34 à 90 %).
const DOUBLONS_POTENTIELS_SEUILS = [80, 85, 90, 95];
const DOUBLONS_POTENTIELS_SEUIL_DEFAUT = 85;

// Nom mis à plat pour la comparaison. Les accents sont repliés AVANT de retirer
// la ponctuation : normaliser_nom_structure() (lib/booking.php) ne garde que
// [a-z0-9] et réduirait « Théâtre » à « thtre », en amputant les deux chaînes
// d'autant de lettres qu'elles portent d'accents.
function doublons_potentiels_nom(string $nom): string
{
    return (string) preg_replace('/[^a-z0-9]/', '', texte_sans_accents($nom));
}

// Clé d'une paire, indépendante de l'ordre : « petit:grand ». C'est l'unité
// mémorisée par les exclusions comme par les cases à cocher de l'écran.
function doublons_potentiels_cle(int $a, int $b): string
{
    return min($a, $b) . ':' . max($a, $b);
}

// Ressemblance de deux noms mis à plat, en pourcentage — ou null si elle
// n'atteint pas $seuil. Sortie séparée de la boucle pour être testable :
// c'est ici que vit le pré-filtre, et un pré-filtre trop gourmand écarterait
// des paires en silence, sans que rien ne le signale.
//
// Le pré-filtre : au mieux, tous les caractères de la plus courte des deux
// chaînes sont communs, ce qui plafonne similar_text() à 2·min/(la+lb).
// Quand ce plafond est déjà sous le seuil, l'appel est inutile — et c'est de
// loin le plus cher de la boucle (il écarte les deux tiers des paires : 40 837
// comparaisons réelles sur 89 069 paires de la base actuelle).
function doublons_potentiels_score(string $a, string $b, int $seuil): ?int
{
    $la = strlen($a);
    $lb = strlen($b);
    if ($la === 0 || $lb === 0) {
        return null;
    }
    if (2 * min($la, $lb) / ($la + $lb) * 100 < $seuil) {
        return null;
    }
    similar_text($a, $b, $pourcent);
    return $pourcent < $seuil ? null : (int) round($pourcent);
}

// Paires exclues, indexées par clé (pour un isset() en boucle).
function doublons_potentiels_exclusions(): array
{
    $out = [];
    foreach (db()->query('SELECT structure_a, structure_b FROM structure_doublons_ignores')->fetchAll() as $r) {
        $out[doublons_potentiels_cle((int) $r['structure_a'], (int) $r['structure_b'])] = true;
    }
    return $out;
}

// Paires candidates, les plus ressemblantes d'abord. Les paires écartées sont
// retirées ici même ; doublons_potentiels_ignores() les liste à part.
function doublons_potentiels_detecter(int $seuil): array
{
    $seuil = max(50, min(100, $seuil));
    $exclues = doublons_potentiels_exclusions();

    // Un seul balayage de la table : les fiches sont regroupées par canton, et
    // la comparaison ne sort jamais de son groupe.
    $parCanton = [];
    foreach (db()->query('SELECT id, nom, adresse_localite, departement_canton, statut FROM structures')->fetchAll() as $r) {
        $canton = trim((string) $r['departement_canton']);
        $plat = doublons_potentiels_nom((string) $r['nom']);
        if ($canton === '' || $plat === '') {
            continue;
        }
        $parCanton[$canton][] = [
            'id' => (int) $r['id'], 'nom' => (string) $r['nom'], 'plat' => $plat,
            'localite' => (string) $r['adresse_localite'], 'canton' => $canton, 'statut' => (string) $r['statut'],
        ];
    }

    $paires = [];
    foreach ($parCanton as $fiches) {
        $n = count($fiches);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $fiches[$i];
                $b = $fiches[$j];
                $score = doublons_potentiels_score($a['plat'], $b['plat'], $seuil);
                if ($score === null) {
                    continue;
                }
                // Doublons EXACTS au sens de la section précédente (même nom et
                // même localité, à la casse et aux espaces près) : ils y sont
                // déjà proposés à la fusion, les répéter ici embrouillerait.
                // Comparaison faite sur les mêmes clés que doublons_detecter(),
                // et non sur le nom mis à plat, sinon deux fiches que LUI seul
                // rend identiques (« Anti-Concert » / « anti concert »)
                // disparaîtraient des deux listes.
                if (mb_strtolower(trim($a['nom'])) === mb_strtolower(trim($b['nom']))
                    && mb_strtolower(trim($a['localite'])) === mb_strtolower(trim($b['localite']))) {
                    continue;
                }
                $cle = doublons_potentiels_cle($a['id'], $b['id']);
                if (isset($exclues[$cle])) {
                    continue;
                }
                $paires[] = ['cle' => $cle, 'score' => $score, 'a' => $a, 'b' => $b];
            }
        }
    }

    usort($paires, fn ($x, $y) => [$y['score'], $x['a']['nom']] <=> [$x['score'], $y['a']['nom']]);
    return $paires;
}

// Paires écartées, telles qu'elles sont enregistrées — lues à la table, jamais
// redétectées : une exclusion doit rester reprenable même si les deux fiches ne
// se ressemblent plus (l'une renommée depuis), sans quoi elle deviendrait
// invisible et définitive. Le score est recalculé pour l'affichage seul, il ne
// conditionne pas la présence dans la liste.
function doublons_potentiels_ignores(): array
{
    $lignes = db()->query(
        "SELECT i.structure_a, i.structure_b, i.cree_le,
                a.nom AS a_nom, a.adresse_localite AS a_localite, a.departement_canton AS a_canton,
                b.nom AS b_nom, b.adresse_localite AS b_localite, b.departement_canton AS b_canton
           FROM structure_doublons_ignores i
           JOIN structures a ON a.id = i.structure_a
           JOIN structures b ON b.id = i.structure_b
          ORDER BY i.cree_le DESC, i.structure_a"
    )->fetchAll();
    $out = [];
    foreach ($lignes as $r) {
        similar_text(
            doublons_potentiels_nom((string) $r['a_nom']),
            doublons_potentiels_nom((string) $r['b_nom']),
            $score
        );
        $out[] = [
            'cle' => doublons_potentiels_cle((int) $r['structure_a'], (int) $r['structure_b']),
            'score' => (int) round($score),
            'cree_le' => (string) $r['cree_le'],
            'a' => ['id' => (int) $r['structure_a'], 'nom' => (string) $r['a_nom'], 'localite' => (string) $r['a_localite'], 'canton' => (string) $r['a_canton']],
            'b' => ['id' => (int) $r['structure_b'], 'nom' => (string) $r['b_nom'], 'localite' => (string) $r['b_localite'], 'canton' => (string) $r['b_canton']],
        ];
    }
    return $out;
}

// Décode les clés « a:b » venues du formulaire. Tout ce qui n'a pas la forme
// attendue est jeté : ces valeurs arrivent du client, et elles finissent en
// identifiants de fusion.
function doublons_potentiels_lire_cles(array $cles): array
{
    $out = [];
    foreach ($cles as $cle) {
        if (!preg_match('/^([0-9]+):([0-9]+)$/', (string) $cle, $m)) {
            continue;
        }
        $a = (int) $m[1];
        $b = (int) $m[2];
        if ($a === $b || $a < 1 || $b < 1) {
            continue;
        }
        $out[doublons_potentiels_cle($a, $b)] = [min($a, $b), max($a, $b)];
    }
    return array_values($out);
}

// Écarte des paires de la détection. Renvoie le nombre de paires écartées.
function doublons_potentiels_ignorer(array $paires): int
{
    // INSERT OR IGNORE : réécarter une paire déjà écartée n'est pas une erreur,
    // et ne doit pas rafraîchir sa date.
    $st = db()->prepare('INSERT OR IGNORE INTO structure_doublons_ignores (structure_a, structure_b) VALUES (?, ?)');
    $n = 0;
    foreach ($paires as [$a, $b]) {
        $st->execute([$a, $b]);
        $n += $st->rowCount();
    }
    return $n;
}

// Remet des paires écartées dans la détection.
function doublons_potentiels_reprendre(array $paires): int
{
    $st = db()->prepare('DELETE FROM structure_doublons_ignores WHERE structure_a = ? AND structure_b = ?');
    $n = 0;
    foreach ($paires as [$a, $b]) {
        $st->execute([$a, $b]);
        $n += $st->rowCount();
    }
    return $n;
}

// Fusionne des paires : la fiche au plus petit id (la plus ancienne) est
// conservée, l'autre lui cède ses rattachements. Même mécanique que les
// doublons exacts. Renvoie le nombre de fiches supprimées.
function doublons_potentiels_fusionner(array $paires): int
{
    return doublons_fusionner_structures(array_map(fn ($p) => ['ids' => $p], $paires));
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
    foreach (db()->query('SELECT id, nom, mise_a_jour_le, dernier_contact_le, dernier_concert_le FROM structures')->fetchAll() as $r) {
        $datesParStructure[(int) $r['id']] = $r;
    }
    return [$indexNom, $indexEmail, $datesParStructure];
}

// Analyse les lignes du CSV face à la base : détermine ce qui serait écrit,
// sans rien modifier. Retourne :
//   ['stats' => [...], 'aEcrire' => [ ['type','id','date','libelle'], … ],
//    'nonTrouvees' => [libellés], 'ambigues' => [libellés]]
function maj_dates_analyser(array $entete, array $lignes, array $index): array
{
    [$indexNom, $indexEmail, $datesParStructure] = maj_dates_construire_index();

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
        if ($dConcert !== null && $dConcert !== (string) ($actuel['dernier_concert_le'] ?? '')) {
            $aEcrire[] = ['structure_concert', $sid, $dConcert, "$nom : dernier concert → $dConcert"];
            $stats['concert']++;
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
        } elseif ($type === 'structure_concert') {
            db()->prepare('UPDATE structures SET dernier_concert_le = ? WHERE id = ?')->execute([$date, $id]);
            journaliser('structure', $id, 'dernier_concert', 'Dernier concert / diffusion (import) : ' . $date, $date);
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

// ===========================================================================
// Rapprochement événement ↔ structure (evenement_structures, voir
// migration_58/66) — un événement stocke un nom de salle en texte libre
// (salle) mais n'est jamais automatiquement rattaché à une fiche structure de
// la base (ni la CSV, ni le formulaire ne renseignent le lien : c'est un
// champ de recherche saisi à la main, voir _region_select_js.php côté
// formulaire événement). Rattrapage : rapproche par ville (+département/
// canton+pays — même précaution que le géocodage, voir migration_57, pour ne
// pas confondre deux villes homonymes) puis par nom normalisé
// (normaliser_nom_structure(), même convention que maj_dates_construire_index()).
// Jamais deviné en cas d'ambiguïté (plusieurs candidats) : ces fiches restent
// affichées à part, à traiter à la main sur la fiche événement.
// ===========================================================================

// Longueur minimale (nom normalisé) pour qu'une correspondance partielle (l'un
// des noms contient l'autre) soit retenue — évite qu'un nom de lieu trop court
// (« Le », « Bar ») ne « matche » n'importe quoi par simple inclusion.
const EVENEMENTS_LIEUX_LONGUEUR_MIN = 4;

// Détecte, pour chaque événement sans structure liée (salle et ville renseignées),
// les lieux candidats de la même ville (+département/canton+pays). Retourne
// [ ['evenement_id'=>, 'date'=>, 'salle'=>, 'ville'=>, 'departement_canton'=>,
//    'pays'=>, 'candidats'=>[ ['id'=>,'nom'=>,'type'=>], … ] ], … ] — un
// tableau `candidats` vide = aucune correspondance (voir
// evenements_lieux_grouper_aucune()), un seul élément = correspondance
// univoque (voir evenements_lieux_lier()), plusieurs = ambigu (jamais deviné).
function evenements_lieux_detecter(): array
{
    $lignes = db()->query(
        "SELECT id, date, salle, ville, departement_canton, pays
         FROM evenements e
         WHERE NOT EXISTS (SELECT 1 FROM evenement_structures es WHERE es.evenement_id = e.id)
           AND TRIM(salle) <> '' AND TRIM(ville) <> ''
         ORDER BY date DESC"
    )->fetchAll();

    // Index de toutes les structures par ville (+département/canton+pays)
    // normalisés, chargé une fois pour tous les événements (pas une requête
    // par ligne). Non filtré sur la sous-catégorie « booking » : une salle
    // peut être catégorisée autrement (ex. Organisateur) et rester un
    // candidat valable — même principe que route_lieux_options().
    $lieuxParVille = [];
    foreach (db()->query(
        "SELECT id, nom, sous_categorie AS type, adresse_localite AS ville, departement_canton, adresse_pays AS pays
         FROM structures"
    )->fetchAll() as $l) {
        $cle = normaliser_nom_structure((string) $l['ville']) . '|'
            . mb_strtolower(trim((string) $l['departement_canton']), 'UTF-8') . '|'
            . normaliser_nom_structure((string) $l['pays']);
        $lieuxParVille[$cle][] = $l;
    }

    $out = [];
    foreach ($lignes as $e) {
        $paysNom = pays_nom_depuis_code((string) $e['pays']);
        $cle = normaliser_nom_structure((string) $e['ville']) . '|'
            . mb_strtolower(trim((string) $e['departement_canton']), 'UTF-8') . '|'
            . normaliser_nom_structure($paysNom);
        $salleNorm = normaliser_nom_structure((string) $e['salle']);
        $candidats = [];
        foreach ($lieuxParVille[$cle] ?? [] as $l) {
            $nomNorm = normaliser_nom_structure((string) $l['nom']);
            if ($nomNorm === '') {
                continue;
            }
            $exact = $nomNorm === $salleNorm;
            $partiel = !$exact
                && min(mb_strlen($nomNorm), mb_strlen($salleNorm)) >= EVENEMENTS_LIEUX_LONGUEUR_MIN
                && (str_contains($nomNorm, $salleNorm) || str_contains($salleNorm, $nomNorm));
            if ($exact || $partiel) {
                $candidats[] = ['id' => (int) $l['id'], 'nom' => (string) $l['nom'], 'type' => (string) $l['type']];
            }
        }
        $out[] = [
            'evenement_id' => (int) $e['id'], 'date' => (string) $e['date'], 'salle' => (string) $e['salle'],
            'ville' => (string) $e['ville'], 'departement_canton' => (string) $e['departement_canton'], 'pays' => $paysNom,
            'candidats' => $candidats,
        ];
    }
    return $out;
}

// Répartit le résultat de evenements_lieux_detecter() en trois listes pour
// l'écran de rattrapage : correspondances univoques (voir
// evenements_lieux_lier()), écarts ambigus (jamais devinés, affichés pour
// être traités à la main) et absence de correspondance (voir
// evenements_lieux_grouper_aucune()/evenements_lieux_creer()).
function evenements_lieux_repartir(array $detection): array
{
    $univoques = [];
    $ambigues = [];
    $aucune = [];
    foreach ($detection as $d) {
        if (count($d['candidats']) === 1) {
            $univoques[] = $d;
        } elseif (count($d['candidats']) > 1) {
            $ambigues[] = $d;
        } else {
            $aucune[] = $d;
        }
    }
    return ['univoques' => $univoques, 'ambigues' => $ambigues, 'aucune' => $aucune];
}

// Regroupe les événements « aucune correspondance » (voir
// evenements_lieux_repartir()) par salle+ville+département/canton+pays
// normalisés — une seule structure+lieu créée par groupe, pas une par
// événement, quand plusieurs événements partagent la même salle non reconnue.
// Retourne [ ['nom'=>, 'ville'=>, 'departement_canton'=>, 'pays'=>,
// 'evenements'=>[ ['id'=>, 'date'=>], … ]], … ] — id+date de chaque événement
// du groupe (pas seulement l'id) pour permettre un lien direct vers sa fiche
// depuis l'écran de rattrapage (voir views/dev.php).
function evenements_lieux_grouper_aucune(array $aucune): array
{
    $parGroupe = [];
    foreach ($aucune as $d) {
        if ($d['candidats'] || trim($d['salle']) === '') {
            continue;
        }
        $cle = normaliser_nom_structure($d['salle']) . '|' . normaliser_nom_structure($d['ville']) . '|'
            . mb_strtolower(trim($d['departement_canton']), 'UTF-8') . '|' . normaliser_nom_structure($d['pays']);
        if (!isset($parGroupe[$cle])) {
            $parGroupe[$cle] = [
                'nom' => trim($d['salle']), 'ville' => $d['ville'], 'departement_canton' => $d['departement_canton'],
                'pays' => $d['pays'], 'evenements' => [],
            ];
        }
        $parGroupe[$cle]['evenements'][] = ['id' => $d['evenement_id'], 'date' => $d['date']];
    }
    return array_values($parGroupe);
}

// Lie chaque événement de $univoques (voir evenements_lieux_repartir()) à son
// lieu candidat unique. L'appelant est responsable de la sauvegarde préalable
// (sauvegarder_base()). Renvoie le nombre d'événements liés.
function evenements_lieux_lier(array $univoques): int
{
    $n = 0;
    db()->beginTransaction();
    try {
        foreach ($univoques as $d) {
            if (count($d['candidats']) !== 1) {
                continue; // relecture défensive : la répartition doit déjà garantir l'unicité
            }
            // Table de jointure (voir migration_58/66) — pas seulement le
            // miroir organisateur_structure_id, sinon la structure resterait
            // invisible dans la carte « Organisation » (?p=evenement, qui lit
            // evenement_structures).
            db()->prepare('INSERT OR IGNORE INTO evenement_structures (evenement_id, structure_id) VALUES (?, ?)')
                ->execute([$d['evenement_id'], $d['candidats'][0]['id']]);
            evenement_resynchroniser_miroirs($d['evenement_id']);
            $n++;
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
    return $n;
}

// Crée une structure « booking » (même nom que la salle, sous-catégorie
// « Salle » — voir migration_59) par groupe de evenements_lieux_grouper_aucune(),
// puis rattache tous les événements du groupe à cette structure. Depuis la
// fusion lieux→structures, un lieu EST une structure : plus de paire
// structure+lieu ni de structure_lieux à créer (contrairement à l'ancien
// comportement). L'appelant est responsable de la sauvegarde préalable
// (sauvegarder_base()). Renvoie le nombre de structures créées (pas le nombre
// d'événements liés).
function evenements_lieux_creer(array $groupes): int
{
    $n = 0;
    db()->beginTransaction();
    try {
        foreach ($groupes as $g) {
            db()->prepare(
                'INSERT INTO structures (nom, categorie, sous_categorie, adresse_localite, departement_canton, adresse_pays) VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$g['nom'], structure_categorie_par_defaut(), 'Salle', $g['ville'], $g['departement_canton'], $g['pays']]);
            $structureId = (int) db()->lastInsertId();

            // Table de jointure (voir migration_58/66), pas seulement le
            // miroir — même raison que evenements_lieux_lier() ci-dessus.
            $stmtMaj = db()->prepare('INSERT OR IGNORE INTO evenement_structures (evenement_id, structure_id) VALUES (?, ?)');
            foreach ($g['evenements'] as $ev) {
                $stmtMaj->execute([$ev['id'], $structureId]);
                evenement_resynchroniser_miroirs($ev['id']);
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
