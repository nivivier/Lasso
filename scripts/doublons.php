<?php
/**
 * Script ponctuel : détecte (et, sur demande, fusionne) les doublons EXACTS de
 * structures et de contacts.
 *
 * Clés de rapprochement (comparaison exacte, aux espaces et à la casse près) :
 *   • structures : nom + localité
 *   • contacts   : même structure + e-mail identique (si renseigné) ; à défaut,
 *                  même structure + prénom + nom + téléphone identiques
 *
 * Dans chaque groupe, la fiche la PLUS ANCIENNE (plus petit id) est conservée ;
 * les autres lui cèdent leurs rattachements avant d'être supprimées :
 *   • structures : via structures_fusionner() (contacts, étiquettes,
 *     organisateurs/lieux liés, historique, factures, mailings)
 *   • contacts   : file d'attente et envois de mailing repointés, champs vides
 *     complétés, drapeaux (administration / booking / désinscrit) cumulés
 *
 * Par défaut : DRY-RUN (n'écrit rien, liste les groupes trouvés).
 * Avec --appliquer : sauvegarde automatique de la base, puis fusion.
 *
 * Usage :
 *   php scripts/doublons.php [--type=structures|contacts|tous]
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
require_once __DIR__ . '/../lib/dev.php';

$appliquer = in_array('--appliquer', $argv, true);
$detail    = in_array('--detail', $argv, true);
$type      = 'tous';
foreach ($argv as $a) {
    if (str_starts_with($a, '--type=')) {
        $type = substr($a, 7);
    }
}
if (!in_array($type, ['structures', 'contacts', 'tous'], true)) {
    fwrite(STDERR, "--type doit valoir structures, contacts ou tous.\n");
    exit(1);
}

echo "Base : " . APP_DB_PATH . "\n";
echo "Mode : " . ($appliquer ? 'APPLICATION' : 'simulation (dry-run)') . " · type : $type\n\n";

$gr = doublons_detecter($type);
$grStructures = $gr['structures'];
$grContacts   = $gr['contacts'];

// ------------------------------------------------------------------ RAPPORT
$afficher = function (string $titre, array $gr) use ($detail): void {
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
$afficher('Contacts', $grContacts);

$aFaire = count($grStructures) + count($grContacts);
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

$n = [
    'structures' => doublons_fusionner_structures($grStructures),
    'contacts'   => doublons_fusionner_contacts($grContacts),
];

printf(
    "\nTerminé : %d structure(s) et %d contact(s) en doublon fusionnés.\n",
    $n['structures'], $n['contacts']
);
