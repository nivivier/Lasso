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
 * Rapprochement : e-mail (prioritaire), sinon nom normalisé — dans les DEUX
 * cas DISCRIMINÉ PAR LA VILLE si plusieurs structures partagent le même
 * e-mail ou le même nom (un e-mail générique n'est pas forcément unique ;
 * deux homonymes de villes différentes ne sont jamais confondus ; un
 * e-mail/nom ambigu sans ville pour trancher est signalé, pas deviné).
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
require_once __DIR__ . '/../lib/dev.php';

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
$index = maj_dates_reperer_colonnes($entete, $cols);
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

// ------------------------------------------------------------------ analyse
$resultat = maj_dates_analyser($entete, $lignes, $index);
$stats   = $resultat['stats'];
$aEcrire = $resultat['aEcrire'];
foreach ($resultat['ambigues'] as $nom) { echo "  ~ AMBIGU (plusieurs homonymes, ville absente) : $nom\n"; }
foreach ($resultat['nonTrouvees'] as $lib) { echo "  ~ non trouvée : $lib\n"; }

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

maj_dates_appliquer($aEcrire);
echo "Terminé : " . count($aEcrire) . " écriture(s).\n";
