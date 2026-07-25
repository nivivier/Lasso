<?php
/**
 * Script ponctuel : met à jour UNIQUEMENT les dates issues d'un export CSV,
 * sans toucher au reste des fiches (adresses, catégories, contacts…).
 *
 * Champs mis à jour :
 *   • structures.mise_a_jour_le      (colonne « mise à jour »)
 *   • structures.dernier_contact_le  (colonne « dernier contact ») + entrée
 *     d'historique de type « mailing » datée du jour du contact
 *   • lieux.dernier_concert_le       (colonne « dernier concert ») + entrée
 *     d'historique de type « dernier_concert »
 *
 * Rapprochement identique à l'import : e-mail exact (prioritaire), sinon nom
 * normalisé DISCRIMINÉ PAR LA VILLE (deux homonymes de villes différentes ne
 * sont jamais confondus ; un nom ambigu sans ville est signalé, pas deviné).
 *
 * Par défaut : DRY-RUN (n'écrit rien, affiche ce qui serait fait).
 * Avec --appliquer : sauvegarde automatique de la base, puis écriture.
 *
 * Usage :
 *   php scripts/maj_dates_import.php chemin/vers/fichier.csv [--appliquer]
 *        [--db=chemin/base.sqlite]
 *        [--col-nom=Nom] [--col-ville=Ville] [--col-email=Email]
 *        [--col-contact="Dernier contact"] [--col-concert="Dernier concert"]
 *        [--col-maj="Mise à jour"]
 *
 * Sans options --col-*, les colonnes sont détectées par leur intitulé
 * (insensible à la casse et aux accents).
 * --db permet de viser une COPIE de la base (essai sans risque) ; par défaut,
 * la base configurée (APP_DB_PATH) est utilisée.
 */

// --db=… doit être pris en compte AVANT le chargement de la config (qui fixe
// APP_DB_PATH si la constante n'est pas déjà définie).
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

// ---------------------------------------------------------------- arguments
$args = array_slice($argv, 1);
$fichier = null;
$appliquer = false;
$cols = [];
foreach ($args as $a) {
    if ($a === '--appliquer') {
        $appliquer = true;
    } elseif (str_starts_with($a, '--db=')) {
        continue; // déjà traité avant le chargement de la config
    } elseif (str_starts_with($a, '--col-')) {
        [$k, $v] = array_pad(explode('=', substr($a, 6), 2), 2, '');
        $cols[$k] = $v;
    } elseif ($fichier === null) {
        $fichier = $a;
    }
}
if ($fichier === null || !is_file($fichier)) {
    fwrite(STDERR, "Usage : php scripts/maj_dates_import.php <fichier.csv> [--appliquer] [--col-…=Intitulé]\n");
    exit(1);
}

// ------------------------------------------------- lecture + repérage colonnes
[$entete, $lignes] = structures_lire_csv((string) file_get_contents($fichier));
if (!$entete) {
    fwrite(STDERR, "CSV vide ou illisible.\n");
    exit(1);
}
// Intitulés reconnus par défaut pour chaque champ (comparaison sans accents).
$parDefaut = [
    'nom'     => ['nom', 'nom de la structure', 'structure'],
    'ville'   => ['ville', 'localite', 'localité', 'commune'],
    'email'   => ['email', 'e-mail', 'mail', 'courriel'],
    'contact' => ['dernier contact', 'date dernier contact', 'contact'],
    'concert' => ['dernier concert', 'dernier concert ou diffusion', 'derniere diffusion', 'dernière diffusion'],
    'maj'     => ['mise a jour', 'mise à jour', 'maj', 'date de mise a jour'],
];
$index = [];
foreach ($parDefaut as $champ => $alias) {
    $index[$champ] = null;
    $vise = isset($cols[$champ]) && $cols[$champ] !== '' ? texte_sans_accents($cols[$champ]) : null;
    foreach ($entete as $i => $titre) {
        $t = texte_sans_accents(trim((string) $titre));
        if ($vise !== null ? $t === $vise : in_array($t, array_map('texte_sans_accents', $alias), true)) {
            $index[$champ] = $i;
            break;
        }
    }
}
if ($index['nom'] === null) {
    fwrite(STDERR, "Colonne « nom » introuvable. Colonnes du fichier : " . implode(' | ', $entete) . "\n");
    exit(1);
}
echo "Base   : " . APP_DB_PATH . "\n";
echo "Fichier: $fichier\n";
echo "Colonnes repérées :\n";
foreach ($index as $champ => $i) {
    printf("  %-8s : %s\n", $champ, $i === null ? '(absente)' : $entete[$i]);
}
if ($index['contact'] === null && $index['concert'] === null && $index['maj'] === null) {
    fwrite(STDERR, "Aucune colonne de date à traiter — rien à faire.\n");
    exit(1);
}

// ------------------------------------------------------------ index existants
$indexNom = [];   // nom normalisé → [ ['id','ville'], … ]
$indexEmail = []; // e-mail → id de structure
foreach (db()->query('SELECT id, nom, adresse_localite FROM structures') as $s) {
    $cle = normaliser_nom_structure((string) $s['nom']);
    if ($cle !== '') {
        $indexNom[$cle][] = ['id' => (int) $s['id'], 'ville' => normaliser_nom_structure((string) ($s['adresse_localite'] ?? ''))];
    }
}
foreach (db()->query("SELECT email, structure_id FROM structure_contacts WHERE email <> ''") as $c) {
    $cle = mb_strtolower(trim((string) $c['email']), 'UTF-8');
    if ($cle !== '' && !isset($indexEmail[$cle])) {
        $indexEmail[$cle] = (int) $c['structure_id'];
    }
}
// Dates actuelles de chaque structure, préchargées : un SELECT par ligne
// laisserait un curseur ouvert, ce qui ferait échouer le VACUUM de sauvegarde.
$datesParStructure = [];
foreach (db()->query('SELECT id, nom, mise_a_jour_le, dernier_contact_le FROM structures')->fetchAll() as $r) {
    $datesParStructure[(int) $r['id']] = $r;
}
// Lieux liés à chaque structure (pour le « dernier concert »).
$lieuxParStructure = [];
foreach (db()->query('SELECT sl.structure_id, l.id, l.nom, l.dernier_concert_le FROM structure_lieux sl JOIN lieux l ON l.id = sl.lieu_id') as $r) {
    $lieuxParStructure[(int) $r['structure_id']][] = $r;
}

$val = fn(array $ligne, ?int $i): string => $i === null ? '' : trim((string) ($ligne[$i] ?? ''));

// ------------------------------------------------------------------ parcours
$stats = ['lignes' => 0, 'sans_correspondance' => 0, 'ambigues' => 0,
          'maj' => 0, 'contact' => 0, 'concert' => 0, 'inchangees' => 0];
$aEcrire = []; // [ ['type','id','col','valeur','libelle'] ]

foreach ($lignes as $ligne) {
    $nom = $val($ligne, $index['nom']);
    if ($nom === '') { continue; }
    $stats['lignes']++;
    $ville = $val($ligne, $index['ville']);
    $email = mb_strtolower($val($ligne, $index['email']), 'UTF-8');

    // Rapprochement : e-mail, sinon nom + ville (jamais d'homonyme deviné).
    $sid = null;
    if ($email !== '' && isset($indexEmail[$email])) {
        $sid = $indexEmail[$email];
    } else {
        $cands = $indexNom[normaliser_nom_structure($nom)] ?? [];
        $vNorm = normaliser_nom_structure($ville);
        if ($vNorm !== '') {
            foreach ($cands as $c) { if ($c['ville'] === $vNorm) { $sid = $c['id']; break; } }
        } elseif (count($cands) === 1) {
            $sid = $cands[0]['id'];
        } elseif (count($cands) > 1) {
            $stats['ambigues']++;
            echo "  ~ AMBIGU (plusieurs homonymes, ville absente) : $nom\n";
            continue;
        }
    }
    if ($sid === null) {
        $stats['sans_correspondance']++;
        echo "  ~ non trouvée : $nom" . ($ville !== '' ? " ($ville)" : '') . "\n";
        continue;
    }

    $actuel = $datesParStructure[$sid] ?? [];

    // 1. Date de mise à jour.
    $dMaj = structure_date_csv_vers_iso($val($ligne, $index['maj']));
    if ($dMaj !== null && $dMaj !== (string) ($actuel['mise_a_jour_le'] ?? '')) {
        $aEcrire[] = ['structure_maj', $sid, $dMaj, "$nom : mise à jour → $dMaj"];
        $stats['maj']++;
    }
    // 2. Dernier contact (+ entrée d'historique datée).
    $dContact = structure_date_csv_vers_iso($val($ligne, $index['contact']));
    if ($dContact !== null && $dContact !== (string) ($actuel['dernier_contact_le'] ?? '')) {
        $aEcrire[] = ['structure_contact', $sid, $dContact, "$nom : dernier contact → $dContact"];
        $stats['contact']++;
    }
    // 3. Dernier concert → sur le(s) lieu(x) lié(s) à la structure.
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

// ------------------------------------------------------------------ résultat
echo "\n--- " . ($appliquer ? 'APPLICATION' : 'SIMULATION (dry-run)') . " ---\n";
foreach ($aEcrire as $op) { echo "  • {$op[3]}\n"; }
printf(
    "\n%d ligne(s) lue(s) · %d sans correspondance · %d ambiguë(s)\n%d mise(s) à jour · %d dernier(s) contact · %d dernier(s) concert\n",
    $stats['lignes'], $stats['sans_correspondance'], $stats['ambigues'],
    $stats['maj'], $stats['contact'], $stats['concert']
);

if (!$appliquer) {
    echo "\nAucune écriture (simulation). Relancer avec --appliquer pour enregistrer.\n";
    exit(0);
}
if (!$aEcrire) {
    echo "\nRien à écrire.\n";
    exit(0);
}

$bak = sauvegarder_base('avant_maj_dates');
echo "\nSauvegarde : " . ($bak ?? '(échec — abandon)') . "\n";
if ($bak === null) { exit(1); }

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
echo "Terminé : " . count($aEcrire) . " écriture(s).\n";
