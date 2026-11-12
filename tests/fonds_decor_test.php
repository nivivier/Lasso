<?php
// Garde-fou des fonds de l'application. Lancement : php tests/fonds_decor_test.php
//
// Un fond calculé tient en TROIS morceaux qui ne se connaissent pas : une
// entrée dans FONDS_DECOR (lib/helpers.php), une branche qui le dessine
// (views/_fond_decor.php) et une règle « body.fond-<clé> » qui pose son fond
// de page (assets/css/). Ses couleurs en tiennent deux : une entrée dans
// FONDS_COULEURS et une classe « .fondc-<clé> » qui pose --fond-a/--fond-b.
//
// Il manque un morceau, et l'écran s'affiche nu : pas d'erreur, pas de
// message, un fond blanc. C'est exactement ce que ce fichier refuse — comme
// recherche_test.php refuse une source non déclarée.
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/helpers.php';

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
    printf("  FAIL  %-52s attendu %s, obtenu %s\n", $label, var_export($attendu, true), var_export($obtenu, true));
}

$css     = css_source();
$partiel = (string) file_get_contents(__DIR__ . '/../views/_fond_decor.php');
$ecran   = (string) file_get_contents(__DIR__ . '/../views/apparence.php');

echo "1) Chaque décor est complet\n";
// « image » n'est pas un décor calculé : elle n'a ni branche ni fond de page,
// c'est couleurs_css_vars() qui la pose sur body.has-sidebar::before.
$decors = array_diff(array_keys(FONDS_DECOR), ['image']);
foreach ($decors as $cle) {
    // Le dernier décor du partiel est rendu par le « else » final (« vagues ») :
    // il n'a pas de comparaison à son nom, et c'est volontaire — une valeur
    // inconnue doit retomber sur un décor plutôt que sur rien.
    $branche = str_contains($partiel, "\$fondDecor === '" . $cle . "'")
        || str_contains($partiel, "« $cle »");
    check("« $cle » a sa branche dans _fond_decor.php", true, $branche);
    check("« $cle » a son fond de page en CSS", true, str_contains($css, 'body.fond-' . $cle));
}

echo "\n2) Chaque combo de couleurs pose ses deux teintes\n";
foreach (array_keys(FONDS_COULEURS) as $cle) {
    $regle = (bool) preg_match('/\.fondc-' . preg_quote($cle, '/') . '\s*\{/', $css);
    check("« $cle » a sa classe .fondc-$cle", true, $regle);
}
// Et aucune classe .fondc-* orpheline : une classe sans entrée dans la liste
// n'est proposée nulle part, donc jamais choisie — du CSS mort qui se donne
// l'air vivant.
preg_match_all('/\.fondc-([a-z-]+)\s*\{/', $css, $m);
$orphelines = array_values(array_diff(array_unique($m[1]), array_keys(FONDS_COULEURS)));
check('aucune classe .fondc-* sans entrée dans FONDS_COULEURS', [], $orphelines);

echo "\n3) L'écran Apparence les propose tous\n";
foreach (array_keys(FONDS_DECOR) as $cle) {
    check("« $cle » dans le sélecteur de fond", true,
        (bool) preg_match("/'" . preg_quote($cle, '/') . "'\s*=>/", $ecran));
}
// Les combos ne sont pas énumérés dans la vue (elle boucle sur la constante) :
// on vérifie que la boucle est bien là, pas une liste écrite à la main.
check('les combos viennent de la constante, pas d\'une copie',
    true, str_contains($ecran, 'foreach (FONDS_COULEURS as'));

echo "\n4) Une valeur inconnue retombe sur le défaut\n";
check('fond_decor_valide() refuse l\'inconnu', 'maillage', fond_decor_valide('nimporte'));
check('fond_decor_valide() garde le connu', 'anneaux', fond_decor_valide('anneaux'));
check('fond_couleurs_valide() refuse l\'inconnu', 'duo', fond_couleurs_valide('nimporte'));
check('fond_couleurs_valide() garde le connu', 'encre', fond_couleurs_valide('encre'));

echo "\n5) Un décor ne nomme pas ses couleurs\n";
// La règle de docs/UI.md § 13 : un décor ne connaît que --fond-a/--fond-b.
// Écrire var(--primary) dans une de ses règles le soustrait au choix de
// l'utilisateur, en silence. On lit le bloc « Décors de fond » jusqu'au bloc
// suivant, en sautant les combos eux-mêmes (.fondc-*), qui ont le droit — et
// le devoir — de nommer les teintes de l'employeur.
$debut = strpos($css, '/* =================== Décors de fond ===================');
$fin   = strpos($css, '/* =================== Headings', (int) $debut);
$bloc  = substr($css, (int) $debut, (int) $fin - (int) $debut);
// Les commentaires n'exécutent rien — et ils parlent justement de cette
// règle-ci : on les retire d'abord, bloc entier, avant de chercher.
$sansCommentaires = (string) preg_replace('#/\*.*?\*/#s', '', $bloc);
$fautifs = [];
foreach (explode("\n", $sansCommentaires) as $ligne) {
    if (str_starts_with(trim($ligne), '.fondc-') || str_contains($ligne, '--fond-a:') || str_contains($ligne, '--fond-b:')) {
        continue;
    }
    if (preg_match('/var\(--(primary|highlight)[-a-z]*\)/', $ligne)) {
        $fautifs[] = trim($ligne);
    }
}
check('aucune règle de décor n\'écrit var(--primary/--highlight)', [], $fautifs);

echo "\n$tests tests, $fails échec(s)\n";
exit($fails > 0 ? 1 : 0);
