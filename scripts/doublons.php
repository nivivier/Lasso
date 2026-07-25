<?php
/**
 * Script ponctuel : détecte (et, sur demande, fusionne) les doublons EXACTS de
 * structures, de lieux et de contacts.
 *
 * Clés de rapprochement (comparaison exacte, aux espaces et à la casse près) :
 *   • structures : nom + localité
 *   • lieux      : nom + ville + type
 *   • contacts   : même structure + e-mail identique (si renseigné) ; à défaut,
 *                  même structure + prénom + nom + téléphone identiques
 *
 * Dans chaque groupe, la fiche la PLUS ANCIENNE (plus petit id) est conservée ;
 * les autres lui cèdent leurs rattachements avant d'être supprimées :
 *   • structures : via structures_fusionner() (contacts, étiquettes, lieux,
 *     historique, factures, mailings)
 *   • lieux      : liens aux structures, événements (evenements.lieu_id),
 *     historique du lieu ; les champs vides de la fiche gardée sont complétés
 *   • contacts   : file d'attente et envois de mailing repointés, champs vides
 *     complétés, drapeaux (administration / booking / désinscrit) cumulés
 *
 * Par défaut : DRY-RUN (n'écrit rien, liste les groupes trouvés).
 * Avec --appliquer : sauvegarde automatique de la base, puis fusion.
 *
 * Usage :
 *   php scripts/doublons.php [--type=structures|lieux|contacts|tous]
 *                            [--appliquer] [--db=chemin/base.sqlite] [--detail]
 */

// --db=… doit être pris en compte AVANT le chargement de la config.
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--db=')) {
        $chemin = substr($a, 5);
        if (!is_file($chemin)) {
            fwrite(STDERR, "Base introuvable : $chemin\n");
            exit(1);
        }
        define('APP_DB_PATH', $chemin);
        break;
    }
}

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/booking.php';

$appliquer = in_array('--appliquer', $argv, true);
$detail    = in_array('--detail', $argv, true);
$type      = 'tous';
foreach ($argv as $a) {
    if (str_starts_with($a, '--type=')) {
        $type = substr($a, 7);
    }
}
if (!in_array($type, ['structures', 'lieux', 'contacts', 'tous'], true)) {
    fwrite(STDERR, "--type doit valoir structures, lieux, contacts ou tous.\n");
    exit(1);
}

echo "Base : " . APP_DB_PATH . "\n";
echo "Mode : " . ($appliquer ? 'APPLICATION' : 'simulation (dry-run)') . " · type : $type\n\n";

// Groupes de doublons : [ ['cle' => …, 'ids' => [garde, autres…], 'libelle' => …] ]
$groupes = function (string $sql): array {
    $out = [];
    foreach (db()->query($sql)->fetchAll() as $r) {
        $ids = array_map('intval', explode(',', (string) $r['ids']));
        sort($ids); // le plus petit id (le plus ancien) est conservé
        $out[] = ['libelle' => (string) $r['libelle'], 'ids' => $ids];
    }
    return $out;
};

$total = ['structures' => 0, 'lieux' => 0, 'contacts' => 0];

// ------------------------------------------------------------- STRUCTURES
$grStructures = ($type === 'structures' || $type === 'tous') ? $groupes(
    "SELECT nom || CASE WHEN TRIM(adresse_localite) <> '' THEN ' — ' || adresse_localite ELSE '' END AS libelle,
            GROUP_CONCAT(id) AS ids
     FROM structures
     GROUP BY TRIM(LOWER(nom)), TRIM(LOWER(adresse_localite))
     HAVING COUNT(*) > 1
     ORDER BY COUNT(*) DESC, nom"
) : [];

// ------------------------------------------------------------------ LIEUX
$grLieux = ($type === 'lieux' || $type === 'tous') ? $groupes(
    "SELECT nom || CASE WHEN TRIM(ville) <> '' THEN ' — ' || ville ELSE '' END || ' (' || type || ')' AS libelle,
            GROUP_CONCAT(id) AS ids
     FROM lieux
     GROUP BY TRIM(LOWER(nom)), TRIM(LOWER(ville)), TRIM(LOWER(type))
     HAVING COUNT(*) > 1
     ORDER BY COUNT(*) DESC, nom"
) : [];

// --------------------------------------------------------------- CONTACTS
// Deux passes : par e-mail (identifiant fort), puis par identité pour les
// contacts sans e-mail — un contact déjà traité n'est pas repris.
$grContacts = [];
if ($type === 'contacts' || $type === 'tous') {
    $grContacts = $groupes(
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
    foreach ($groupes(
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

// ------------------------------------------------------------------ RAPPORT
$afficher = function (string $titre, array $gr) use ($detail, &$total, $type): void {
    $cle = strtolower(explode(' ', $titre)[0]);
    $surnum = 0;
    foreach ($gr as $g) { $surnum += count($g['ids']) - 1; }
    printf("%-12s : %d groupe(s), %d fiche(s) en trop\n", $titre, count($gr), $surnum);
    if ($detail) {
        foreach ($gr as $g) {
            printf("    • %s — garde #%d, supprime #%s\n", $g['libelle'], $g['ids'][0],
                implode(', #', array_slice($g['ids'], 1)));
        }
    }
};
$afficher('Structures', $grStructures);
$afficher('Lieux', $grLieux);
$afficher('Contacts', $grContacts);

$aFaire = count($grStructures) + count($grLieux) + count($grContacts);
if (!$aFaire) {
    echo "\nAucun doublon exact trouvé.\n";
    exit(0);
}
if (!$detail) {
    echo "\n(Relancer avec --detail pour lister chaque groupe.)\n";
}
if (!$appliquer) {
    echo "\nAucune écriture (simulation). Relancer avec --appliquer pour fusionner.\n";
    exit(0);
}

// ---------------------------------------------------------------- FUSION
$bak = sauvegarder_base('avant_doublons');
echo "\nSauvegarde : " . ($bak ?? '(échec — abandon)') . "\n";
if ($bak === null) { exit(1); }

// Complète les champs vides de la fiche gardée avec ceux d'un doublon.
$completer = function (string $table, int $garde, int $autre, array $colonnes): void {
    $sets = [];
    foreach ($colonnes as $c) {
        $sets[] = "$c = CASE WHEN TRIM(COALESCE($c, '')) = '' THEN (SELECT $c FROM $table WHERE id = :autre) ELSE $c END";
    }
    db()->prepare("UPDATE $table SET " . implode(', ', $sets) . ' WHERE id = :garde')
        ->execute([':autre' => $autre, ':garde' => $garde]);
};

$n = ['structures' => 0, 'lieux' => 0, 'contacts' => 0];

foreach ($grStructures as $g) {
    $garde = array_shift($g['ids']);
    structures_fusionner($garde, $g['ids']); // gère sa propre transaction
    $n['structures'] += count($g['ids']);
}

foreach ($grLieux as $g) {
    $garde = array_shift($g['ids']);
    foreach ($g['ids'] as $autre) {
        db()->beginTransaction();
        $completer('lieux', $garde, $autre, ['region', 'grande_region', 'pays', 'site_web', 'notes', 'dernier_concert_le']);
        db()->prepare('INSERT OR IGNORE INTO structure_lieux (structure_id, lieu_id) SELECT structure_id, ? FROM structure_lieux WHERE lieu_id = ?')
            ->execute([$garde, $autre]);
        db()->prepare('DELETE FROM structure_lieux WHERE lieu_id = ?')->execute([$autre]);
        db()->prepare('UPDATE evenements SET lieu_id = ? WHERE lieu_id = ?')->execute([$garde, $autre]);
        db()->prepare("UPDATE historique SET entite_id = ? WHERE entite_type = 'lieu' AND entite_id = ?")->execute([$garde, $autre]);
        db()->prepare('DELETE FROM lieux WHERE id = ?')->execute([$autre]);
        db()->commit();
        $n['lieux']++;
    }
}

foreach ($grContacts as $g) {
    $garde = array_shift($g['ids']);
    foreach ($g['ids'] as $autre) {
        db()->beginTransaction();
        $completer('structure_contacts', $garde, $autre, ['prenom', 'nom', 'role', 'email', 'telephone', 'formulaire_url', 'langue']);
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
        $n['contacts']++;
    }
}

printf(
    "\nTerminé : %d structure(s), %d lieu(x) et %d contact(s) en doublon fusionnés.\n",
    $n['structures'], $n['lieux'], $n['contacts']
);
