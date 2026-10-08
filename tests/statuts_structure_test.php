<?php
// Garde-fou des statuts de structure. Lancement : php tests/statuts_structure_test.php
//
// STRUCTURE_STATUTS (lib/booking.php) est la liste de référence, et presque
// tout en découle : le sélecteur segmenté, les entonnoirs, la modification
// groupée bouclent dessus. Mais QUATRE endroits la réécrivent, parce qu'ils ne
// peuvent pas la lire :
//
//   1. le masque du tableau            tr[data-statut="…"] .col-statut
//   2. la couleur du segment actif     .statut-toggle .seg-btn[data-statut-valeur="…"]
//   3. la case à cocher des cartes     .liste-cartes tbody tr[data-statut="…"]
//   4. le tracé de l'icône             icone_table(), par son nom
//
// Les trois premiers sont du CSS : aucune boucle ne les parcourt, aucune erreur
// ne les signale. Un statut qui y manque s'affiche simplement avec la couleur
// par défaut, ou sans icône du tout. C'est arrivé à « À vérifier » le jour de
// son ajout : les constantes PHP étaient complètes, la couleur du tableau
// aussi, et il restait bleu dans le sélecteur et incolore sur téléphone.
//
// Le test ne tient donc PAS une liste de ce qui devrait exister — elle
// dériverait comme le reste. Il lit ce que la feuille de style déclare, et le
// compare à la liste de référence : ce qui manque est nommé.

$racine = dirname(__DIR__);
require_once $racine . '/lib/config.php';
require_once $racine . '/lib/helpers.php';
require_once $racine . '/lib/booking.php';

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
    printf("  FAIL  %s\n        attendu %s\n        obtenu  %s\n", $label, var_export($attendu, true), var_export($obtenu, true));
}

$css = (string) file_get_contents($racine . '/assets/app.css');

// Les statuts qu'une famille de règles CSS déclare réellement, dans l'ordre de
// référence — c'est la comparaison de DEUX ensembles, pas la vérification
// d'une liste écrite à la main.
$declares = function (string $motif) use ($css): array {
    preg_match_all($motif, $css, $m);
    $vus = array_values(array_unique($m[1]));
    return array_values(array_intersect(STRUCTURE_STATUTS, $vus));
};
$attendu = STRUCTURE_STATUTS;

echo "1) Les quatre endroits qui réécrivent la liste des statuts\n";

check(
    'masque de la colonne « Statut » (tr[data-statut] .col-statut)',
    $attendu,
    $declares('/tr\[data-statut="([a-z_]+)"\]\s*\.col-statut/')
);

check(
    'couleur du segment actif (.statut-toggle .seg-btn[data-statut-valeur])',
    $attendu,
    $declares('/\.statut-toggle\s+\.seg-btn\[data-statut-valeur="([a-z_]+)"\]/')
);

check(
    'case à cocher des mini-cartes (.liste-cartes tr[data-statut] .col-check)',
    $attendu,
    $declares('/\.liste-cartes\s+tbody\s+tr\[data-statut="([a-z_]+)"\]\s*td\.col-check/')
);

// Une icône absente de la table ne lève rien : icon() rend un <svg> vide, et la
// cellule paraît blanche. Le sélecteur de la fiche montrerait alors cinq
// boutons dont un sans dessin.
$sansIcone = [];
foreach (STRUCTURE_STATUTS as $st) {
    if (trim(icone_chemins(structure_statut_icone($st))) === '') {
        $sansIcone[] = $st;
    }
}
check('chaque statut a son tracé dans icone_table()', [], $sansIcone);

echo "\n2) Ce que le masque CSS promet, les tokens doivent le tenir\n";

// Le masque pointe un token --ico-m-… ; s'il n'est pas défini, la règle est
// valide mais ne dessine rien — un carré vide à la place de l'icône.
preg_match_all('/tr\[data-statut="([a-z_]+)"\]\s*\.col-statut[^{]*\{[^}]*var\((--ico-m-[a-z-]+)\)/', $css, $m);
$tokensManquants = [];
foreach ($m[2] as $i => $token) {
    if (!preg_match('/\s' . preg_quote($token, '/') . '\s*:/', $css)) {
        $tokensManquants[] = $m[1][$i] . ' → ' . $token;
    }
}
check('chaque masque de statut pointe un token défini', [], $tokensManquants);
check('un masque par statut, pas un de plus', count(STRUCTURE_STATUTS), count(array_unique($m[1])));

echo "\n3) Les constantes se répondent entre elles\n";

// Les quatre tables sont indexées par statut : une clé oubliée dans l'une
// d'elles passe par un repli silencieux (?? 'circle-dashed', ?? 'muted'…).
foreach ([
    'STRUCTURE_STATUTS_LIBELLES'      => STRUCTURE_STATUTS_LIBELLES,
    'STRUCTURE_STATUTS_ICONES'        => STRUCTURE_STATUTS_ICONES,
    'STRUCTURE_STATUTS_CLASSES_ICONE' => STRUCTURE_STATUTS_CLASSES_ICONE,
] as $nom => $table) {
    check(
        $nom . ' couvre exactement les statuts',
        STRUCTURE_STATUTS,
        array_values(array_intersect(STRUCTURE_STATUTS, array_keys($table)))
    );
    check($nom . ' n\'a pas de clé en trop', [], array_values(array_diff(array_keys($table), STRUCTURE_STATUTS)));
}

// Les classes de couleur doivent exister, sinon l'icône hérite de la couleur
// du texte et le statut ne se distingue plus.
$classesInconnues = [];
foreach (STRUCTURE_STATUTS_CLASSES_ICONE as $st => $classe) {
    if (!preg_match('/\.' . preg_quote($classe, '/') . '\s*[,{]/', $css)) {
        $classesInconnues[] = $st . ' → .' . $classe;
    }
}
check('chaque classe de couleur d\'icône existe dans la feuille de style', [], $classesInconnues);

// Contactables : un sous-ensemble, jamais un statut inventé.
check(
    'STRUCTURE_STATUTS_CONTACTABLES ne contient que des statuts connus',
    [],
    array_values(array_diff(STRUCTURE_STATUTS_CONTACTABLES, STRUCTURE_STATUTS))
);
check(
    'le statut nommé en dur (STRUCTURE_STATUT_A_VERIFIER) existe encore',
    true,
    in_array(STRUCTURE_STATUT_A_VERIFIER, STRUCTURE_STATUTS, true)
);

echo "\n$tests tests, $fails échec(s)\n";
exit($fails > 0 ? 1 : 0);
