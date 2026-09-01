<?php
/**
 * Script ponctuel : rejoue la déduction de contre-partie sur les écritures
 * DÉJÀ importées, sans les réimporter.
 *
 * Pourquoi il existe : les corrections d'import de la 2.4.3 ne valent que pour
 * les imports à venir. Réimporter le même relevé ne suffit pas — les écritures
 * sont reconnues comme doublons (par leur hash) et laissées telles quelles. Il
 * faudrait supprimer l'import puis le refaire, ce qui fait perdre le lettrage.
 *
 * Ce qui est écrit, et RIEN d'autre :
 *   • ecritures.tiers          quand elle est vide et qu'on sait la déduire
 *   • ecritures.tiers_source   la provenance de cette valeur
 *
 * Montants, dates, soldes, catégories, axes et lettrages ne sont jamais
 * touchés. Les contre-parties déjà renseignées ne sont jamais écrasées : le
 * script ne fait qu'ajouter là où il n'y a rien.
 *
 * Deux déductions, celles de contrepartie_ligne() (lib/compta.php) :
 *   • achat par carte  -> le commerçant, lu dans le libellé (source « texte »)
 *   • frais bancaires  -> l'établissement du compte, s'il est renseigné dans
 *                         Comptabilité → Comptes bancaires (source « compte »)
 *
 * Par défaut : DRY-RUN (n'écrit rien, affiche ce qui serait fait).
 * Avec --appliquer : sauvegarde automatique de la base, puis écriture.
 *
 * Usage :
 *   php scripts/maj_contreparties.php [--appliquer] [--db=chemin/base.sqlite]
 *
 * --db permet de viser une COPIE de la base pour un essai sans risque ; par
 * défaut, la base configurée (APP_DB_PATH) est utilisée.
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
require_once __DIR__ . '/../lib/calc.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/compta.php';

$appliquer = in_array('--appliquer', $argv, true);

$stmt = db()->query(
    "SELECT e.id, e.date_op, e.montant, e.texte, e.tiers, e.nature, c.banque, c.libelle AS compte
       FROM ecritures e
       JOIN comptes_bancaires c ON c.id = e.compte_bancaire_id
      WHERE e.tiers = ''
      ORDER BY e.date_op, e.id"
);

$aFaire = [];
$fraisSansBanque = 0;
foreach ($stmt as $e) {
    // estCamt = true : ces écritures viennent d'un relevé, et on ne veut
    // surtout pas relancer sur elles la reconnaissance du CSV PostFinance,
    // qui inventait des contre-parties (voir contrepartie_ligne()).
    [$tiers, $source] = contrepartie_ligne($e, true, '', (string) $e['banque']);
    if ($tiers !== '') {
        $aFaire[] = ['id' => (int) $e['id'], 'tiers' => $tiers, 'source' => $source] + $e;
    } elseif (str_contains((string) $e['nature'], 'CHRG') && trim((string) $e['banque']) === '') {
        $fraisSansBanque++;
    }
}

printf("%s — %d écriture(s) recevraient une contre-partie\n\n",
    $appliquer ? 'APPLICATION' : 'DRY-RUN (rien n\'est écrit)', count($aFaire));
foreach ($aFaire as $l) {
    printf("  #%-5d %s %10.2f  %-22s (%s)\n      %s\n",
        $l['id'], $l['date_op'], (float) $l['montant'], $l['tiers'], $l['source'],
        mb_substr((string) $l['texte'], 0, 90));
}

if ($fraisSansBanque > 0) {
    printf("\n⚠ %d écriture(s) de frais bancaires restent sans contre-partie faute\n"
        . "  d'établissement renseigné. Renseignez le champ « Banque » du compte\n"
        . "  dans Comptabilité → Comptes bancaires, puis relancez ce script.\n", $fraisSansBanque);
}

if (!$appliquer) {
    echo "\nRelancer avec --appliquer pour écrire (sauvegarde automatique avant).\n";
    exit(0);
}
if (!$aFaire) {
    echo "\nRien à faire.\n";
    exit(0);
}

$bak = sauvegarder_base('contreparties');
if ($bak === null) {
    fwrite(STDERR, "Sauvegarde impossible — abandon (aucune écriture modifiée).\n");
    exit(1);
}
echo "\nSauvegarde : $bak\n";

$upd = db()->prepare('UPDATE ecritures SET tiers = ?, tiers_source = ? WHERE id = ? AND tiers = \'\'');
db()->beginTransaction();
$n = 0;
foreach ($aFaire as $l) {
    $upd->execute([$l['tiers'], $l['source'], $l['id']]);
    $n += $upd->rowCount();
}
db()->commit();
printf("%d écriture(s) mise(s) à jour.\n", $n);
