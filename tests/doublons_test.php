<?php
// Tests des doublons POTENTIELS (lib/dev.php). Lancement :
//   php tests/doublons_test.php
// N'utilise pas la base : on teste la mise à plat des noms, la clé de paire, le
// décodage des clés postées et — surtout — que le pré-filtre de
// doublons_potentiels_score() n'écarte jamais une paire que similar_text()
// aurait retenue.

require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/dev.php';

$tests = 0;
$fails = 0;
function check(string $label, $attendu, $obtenu): void
{
    global $tests, $fails;
    $tests++;
    if ($attendu === $obtenu) {
        printf("  ok    %s\n", $label);
        return;
    }
    $fails++;
    printf("  FAIL  %-56s attendu %s, obtenu %s\n", $label, var_export($attendu, true), var_export($obtenu, true));
}

echo "1) Mise à plat des noms\n";
check('casse ignorée', doublons_potentiels_nom('Le Courrier'), doublons_potentiels_nom('LE COURRIER'));
check('ponctuation et espaces ignorés', doublons_potentiels_nom('Anti-Concert'), doublons_potentiels_nom('anti concert'));
check('guillemets ignorés', doublons_potentiels_nom('Festival « Ça va »'), doublons_potentiels_nom('Festival Ça Va'));
// Les accents sont repliés AVANT le filtrage des caractères : sans cela
// « Théâtre » perdrait ses deux voyelles accentuées au lieu de devenir
// « theatre », et deux orthographes du même mot ne se compareraient plus sur
// le même nombre de lettres.
check('accents repliés, pas supprimés', 'theatre', doublons_potentiels_nom('Théâtre'));
check('nom vide de lettres → chaîne vide', '', doublons_potentiels_nom('—  ///  '));

echo "\n2) Clé de paire, indépendante de l'ordre\n";
check('ordre croissant', '12:34', doublons_potentiels_cle(12, 34));
check('ordre décroissant → même clé', '12:34', doublons_potentiels_cle(34, 12));

echo "\n3) Décodage des clés postées (valeurs venues du client)\n";
check('paire valide', [[12, 34]], doublons_potentiels_lire_cles(['12:34']));
check('les deux sens = une seule paire', [[12, 34]], doublons_potentiels_lire_cles(['12:34', '34:12']));
check('texte libre rejeté', [], doublons_potentiels_lire_cles(['abc']));
check('paire réflexive rejetée', [], doublons_potentiels_lire_cles(['5:5']));
check('id négatif rejeté', [], doublons_potentiels_lire_cles(['-1:2']));
check('chaîne vide rejetée', [], doublons_potentiels_lire_cles(['']));
// Ces valeurs finissent en identifiants de fusion : tout ce qui n'a pas
// exactement la forme « entier:entier » doit être jeté, pas rattrapé par un
// (int) permissif qui lirait « 7 » dans « 7:8; DROP TABLE structures ».
check('suffixe parasite rejeté', [], doublons_potentiels_lire_cles(['7:8; DROP TABLE structures']));
check('décimales rejetées', [], doublons_potentiels_lire_cles(['1.5:2']));

echo "\n4) Score et seuil\n";
check('noms identiques → 100', 100, doublons_potentiels_score('lecourrier', 'lecourrier', 85));
check('sous le seuil → null', null, doublons_potentiels_score('lecourrier', 'radiocite', 85));
check('nom vide → null', null, doublons_potentiels_score('', 'lecourrier', 85));
check('seuil respecté à la borne', 100, doublons_potentiels_score('gooutgenevecom', 'gooutgenevecom', 100));

echo "\n5) Le pré-filtre n'écarte aucune paire que similar_text() retiendrait\n";
// Le pré-filtre est une optimisation : il doit être une borne SUPÉRIEURE, donc
// ne jamais rejeter une paire au-dessus du seuil. On confronte, sur un corpus
// engendré, le résultat filtré au calcul complet.
$mots = ['theatre', 'letheatre', 'theatredupassage', 'passage', 'lecourrier', 'courrier',
         'radiocite', 'radio', 'onefm', 'onefmgeneve', 'gooutgeneve', 'goout', 'lafabrik',
         'fabrikgeneve', 'echosystem70', 'echosystem', 'festivalcavabiensepasser',
         'cavabiensepasser', 'a', 'ab', 'abc', 'abcdefghij', ''];
$divergences = 0;
$compares = 0;
foreach ([80, 85, 90, 95, 100] as $seuil) {
    foreach ($mots as $x) {
        foreach ($mots as $y) {
            $compares++;
            $filtre = doublons_potentiels_score($x, $y, $seuil);
            if ($x === '' || $y === '') {
                $complet = null;
            } else {
                similar_text($x, $y, $pc);
                $complet = $pc < $seuil ? null : (int) round($pc);
            }
            if ($filtre !== $complet) {
                $divergences++;
                printf("        divergence : « %s » / « %s » au seuil %d — filtré %s, complet %s\n",
                    $x, $y, $seuil, var_export($filtre, true), var_export($complet, true));
            }
        }
    }
}
check(sprintf('%d comparaisons, aucune divergence', $compares), 0, $divergences);
// Symétrie : la clé de paire ne dit pas dans quel sens les noms sont comparés.
$asym = 0;
foreach ($mots as $x) {
    foreach ($mots as $y) {
        if (doublons_potentiels_score($x, $y, 80) !== doublons_potentiels_score($y, $x, 80)) { $asym++; }
    }
}
check('score symétrique', 0, $asym);

echo "\n$tests tests, $fails échec(s)\n";
exit($fails > 0 ? 1 : 0);
