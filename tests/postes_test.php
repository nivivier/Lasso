<?php
// Tests des postes salariaux : la grille configurable qui remplace les quinze
// lignes autrefois écrites en dur. Lancement : php tests/postes_test.php
//
// Travaille sur une base TEMPORAIRE, comme tests/migrations_test.php : c'est
// APP_DB_PATH, définie avant lib/config.php, qui détourne toute l'application.
//
// Ce que ces tests protègent :
//  1. la grille installée par migration_80 est bien celle du décompte suisse
//     actuel — mêmes codes, mêmes rubriques de certificat, mêmes regroupements
//     comptables : c'est d'elle que dépendent le certificat de salaire et les
//     récapitulatifs de charges ;
//  2. une fiche fige ses lignes, libellé compris, et renommer le poste ensuite
//     ne réécrit pas la fiche — l'invariante centrale du module ;
//  3. les barèmes d'âge et le salaire coordonné, les deux seuls endroits où le
//     montant dépend d'autre chose que du brut.

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/lasso_postes_' . bin2hex(random_bytes(6)) . '.sqlite';
define('APP_DB_PATH', $tmp);

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/calc.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';

$tests = 0;
$fails = 0;
function check(string $label, $attendu, $obtenu): void
{
    global $tests, $fails;
    $tests++;
    $ok = is_float($attendu) ? abs($attendu - (float) $obtenu) < 0.005 : $attendu === $obtenu;
    if (!$ok) {
        $fails++;
        printf("  FAIL  %-52s attendu %s, obtenu %s\n", $label, var_export($attendu, true), var_export($obtenu, true));
    } else {
        printf("  ok    %s\n", $label);
    }
}
register_shutdown_function(function () use ($tmp) {
    foreach ([$tmp, $tmp . '-wal', $tmp . '-shm'] as $f) {
        if (is_file($f)) @unlink($f);
    }
});

db(); // init_schema() + run_migrations()

echo "1) Grille installée par les migrations\n";
$postes = postes_actifs();
check('quinze postes actifs', 15, count($postes));
$parCode = [];
foreach ($postes as $p) {
    $parCode[(string) $p['code']] = $p;
}
check('AVS est une déduction employé', 'deduction', (string) $parCode['avs']['sens']);
check('LAA a deux taux (seuil d\'heures)', 'laa_seuil', (string) $parCode['laa']['mode']);
check('impôt à la source : taux porté par l\'employé', 'taux_employe', (string) $parCode['impot_source']['mode']);
check('LPP assise sur le salaire coordonné', 'coordonne', (string) $parCode['lpp']['base']);
// Les rubriques du certificat : sans elles, ajouter une ligne fausserait un
// formulaire officiel sans prévenir.
check('AVS alimente la case 9', '9', (string) $parCode['avs']['rubrique_certificat']);
check('LPP alimente la case 10.1', '10.1', (string) $parCode['lpp']['rubrique_certificat']);
check('impôt à la source alimente la case 12', '12', (string) $parCode['impot_source']['rubrique_certificat']);
// Le regroupement OCAS des charges patronales, lu par la comptabilité.
$ocas = array_values(array_filter($postes, fn ($p) => (string) $p['groupe_compta'] === 'ocas'));
check('quatre charges patronales regroupées en OCAS', 4, count($ocas));
// Les lignes qu'un décompte montre même à zéro.
$visibles = array_values(array_filter($postes, fn ($p) => !(int) $p['masquer_si_zero']));
check('AVS/AC/LAA/LPP restent visibles à zéro', ['avs', 'ac', 'laa', 'lpp'],
    array_map(fn ($p) => (string) $p['code'], $visibles));

echo "2) Correspondance poste → colonne de la fiche\n";
check('avs → ded_avs', 'ded_avs', poste_colonne_montant('avs'));
check('emp_avs → emp_avs', 'emp_avs', poste_colonne_montant('emp_avs'));

echo "3) Une fiche fige ses lignes, libellé compris\n";
db()->exec("INSERT INTO employes (id, prenom, nom) VALUES (1, 'Test', 'Postes')");
db()->exec("INSERT INTO fiches (id, employe_id, annee, mois, employe_nom, employe_rue, employe_npa,
    employe_avs, canton, procedure, salaire_horaire, nombre_heures, supplement_taux,
    salaire_travail, supplement_montant, salaire_brut, ded_avs, ded_ac, ded_amat, ded_laa,
    ded_lpp, ded_impot_source, ded_caf, total_deductions, salaire_net)
    VALUES (1, 1, 2026, 3, 'Test Postes', '', '', '', 'Genève', 'Ordinaire', 30, 10, 0,
            300, 0, 300, 0, 0, 0, 0, 0, 0, 0, 0, 300)");
$emp = ['supplement_vacances' => 0, 'procedure' => 'Ordinaire', 'impot_source_taux' => 0, 'date_naissance' => ''];
$taux = taux_pour_annee(2026);
$c = calculer_fiche($emp, 300.0, $taux);
fiche_postes_ecrire(1, $c, $taux);
$lignes = fiche_postes_lire(1);
check('quinze lignes figées', 15, count($lignes));
check('libellé figé', 'AVS / AI / APG', (string) $lignes[0]['libelle']);
check('montant figé = montant calculé', $c['ded_avs'], (float) $lignes[0]['montant']);
// Renommer le poste ne doit rien réécrire : c'est toute la raison de la copie.
db()->exec("UPDATE postes_salariaux SET libelle = 'Renommé' WHERE code = 'avs'");
postes_oublier();
$apres = fiche_postes_lire(1);
check('renommer le poste ne touche pas la fiche', 'AVS / AI / APG', (string) $apres[0]['libelle']);

echo "4) Une ligne ajoutée n'a pas de colonne dans « fiches »\n";
// calculer_fiche() rend une clé par poste ; la table, elle, n'a de colonnes que
// pour les quinze lignes d'origine. Sans filtre, enregistrer une fiche
// échouait dès qu'une ligne était ajoutée (« no column named ded_… »).
db()->exec("INSERT INTO postes_salariaux (code, libelle, sens, mode, base, ordre)
            VALUES ('cantonal', 'Cotisation cantonale', 'deduction', 'taux', 'brut', 65)");
postes_oublier();
$cAjout = calculer_fiche($emp, 1000.0, $taux + ['cantonal' => 0.02]);
check('le calcul rend bien la clé du poste ajouté', true, array_key_exists('ded_cantonal', $cAjout));
$stockable = fiche_montants_stockables($cAjout);
check('mais elle est écartée de ce qu\'on stocke', false, array_key_exists('ded_cantonal', $stockable));
check('le salaire coordonné aussi (calcul intermédiaire)', false, array_key_exists('salaire_coordonne', $stockable));
check('les colonnes historiques passent', true, array_key_exists('ded_avs', $stockable));
// La ligne ajoutée vit dans la copie figée, elle : c'est là qu'on la relit.
fiche_postes_ecrire(1, $cAjout, $taux + ['cantonal' => 0.02]);
$codes = array_map(fn ($l) => (string) $l['code'], fiche_postes_lire(1));
check('la ligne ajoutée est figée sur la fiche', true, in_array('cantonal', $codes, true));
db()->exec("DELETE FROM postes_salariaux WHERE code = 'cantonal'");
postes_oublier();

echo "5) Le code d'une ligne suit la famille de sa colonne\n";
// poste_colonne_montant() décide de la colonne d'après le préfixe « emp_ » :
// une charge nommée « caf » écrirait dans ded_caf (colonne employé), une
// déduction nommée « emp_frais » dans les charges patronales. Le code est donc
// aligné sur la nature à la création.
require_once __DIR__ . '/../lib/modules.php';
require_once __DIR__ . '/../lib/routes.php';
enregistrer_poste(0, ['code' => 'caf', 'libelle' => 'Essai charge', 'sens' => 'charge',
                      'mode' => 'taux', 'base' => 'brut']);
$cree = db()->query("SELECT code FROM postes_salariaux WHERE libelle = 'Essai charge'")->fetchColumn();
check('une charge reçoit le préfixe emp_', 'emp_caf', (string) $cree);
check('sa colonne ne peut pas viser une déduction', 'emp_caf', poste_colonne_montant((string) $cree));
enregistrer_poste(0, ['code' => 'emp_frais', 'libelle' => 'Essai déduction', 'sens' => 'deduction',
                      'mode' => 'taux', 'base' => 'brut']);
$cree2 = db()->query("SELECT code FROM postes_salariaux WHERE libelle = 'Essai déduction'")->fetchColumn();
check('une déduction perd le préfixe emp_', 'frais', (string) $cree2);
check('elle ne peut pas viser une charge patronale', 'ded_frais', poste_colonne_montant((string) $cree2));
db()->exec("DELETE FROM postes_salariaux WHERE libelle LIKE 'Essai %'");
postes_oublier();

echo "6) Salaire coordonné\n";
check('sans réglage : coordonné = brut', 1000.0, salaire_coordonne(1000.0, []));
check('déduction annuelle ramenée au mois', 500.0, salaire_coordonne(1000.0, ['coord_deduction' => 6000]));
check('jamais négatif', 0.0, salaire_coordonne(100.0, ['coord_deduction' => 6000]));
check('plafond annuel ramené au mois', 800.0, salaire_coordonne(1000.0, ['coord_plafond' => 9600]));

echo "7) Barème d'âge\n";
$lppId = (int) $parCode['lpp']['id'];
db()->exec("UPDATE postes_salariaux SET mode = 'bareme_age' WHERE id = $lppId");
postes_oublier();
$ins = db()->prepare('INSERT INTO poste_bareme_age (poste_id, annee, age_min, age_max, valeur) VALUES (?, ?, ?, ?, ?)');
foreach ([[25, 34, 0.07], [35, 44, 0.10], [45, 54, 0.15], [55, 65, 0.18]] as $p) {
    $ins->execute([$lppId, 2026, $p[0], $p[1], $p[2]]);
}
check('30 ans → 7 %', 0.07, bareme_age_taux($lppId, 2026, 30));
check('50 ans → 15 %', 0.15, bareme_age_taux($lppId, 2026, 50));
check('hors de tout palier → 0, jamais un taux deviné', 0.0, bareme_age_taux($lppId, 2026, 20));
// Repli d'année, comme pour les taux.
check('année non configurée → dernière année antérieure', 0.10, bareme_age_taux($lppId, 2028, 40));
// L'âge de référence est un choix de l'employeur.
$ne = ['date_naissance' => '1980-11-15'];
check('âge atteint dans l\'année (défaut)', 46, age_pour_bareme($ne, 2026, 3));
check('sans date de naissance : aucun palier', null, age_pour_bareme(['date_naissance' => ''], 2026, 3));
$tauxAge = bareme_age_effectif($ne, 2026, 3);
check('le taux du barème est rangé sous le code du poste', 0.15, (float) ($tauxAge['lpp'] ?? 0));
check('sans date de naissance : taux à 0', 0.0,
    (float) (bareme_age_effectif(['date_naissance' => ''], 2026, 3)['lpp'] ?? -1));

echo "6) L'ordre d'une liste plate\n";
$liste = [['id' => 1], ['id' => 2], ['id' => 3]];
check('descendre le premier', '2,1,3', postes_ordre_deplace($liste, 1, 1));
check('monter le dernier', '1,3,2', postes_ordre_deplace($liste, 3, -1));
check('déjà en tête : rien ne bouge', '1,2,3', postes_ordre_deplace($liste, 1, -1));
check('déjà en fin : rien ne bouge', '1,2,3', postes_ordre_deplace($liste, 3, 1));

echo "\n";
if ($fails === 0) {
    echo "✅ TOUS LES TESTS PASSENT ($tests assertions)\n";
    exit(0);
}
echo "❌ $fails / $tests assertions en échec\n";
exit(1);
