<?php
// Garde-fou de l'échelle typographique. Lancement : php tests/tailles_texte_test.php
//
// Sept tokens portent les tailles de texte de toute l'application
// (--fs-tiny … --fs-huge, assets/app.css), et AUCUNE règle n'écrit plus un
// nombre de pixels. La convention n'était écrite NULLE PART : le code la
// disait 206 fois contre 32, et c'est dans ces 32 que la dette s'était logée.
//
// Douze recopiaient un token à l'identique — 11px là où --fs-small vaut 11px,
// 16px là où --fs-large vaut 16px —, c'est-à-dire une valeur qui cessait de
// suivre le token le jour où celui-ci changerait, sans que rien ne le
// signale. Onze réglaient les mini-cartes de téléphone à l'œil (10px, 12px),
// hors de l'échelle : ces écrans s'étaient mis à vivre leur propre vie,
// invisible au développement qui se fait au large. Les neuf dernières étaient
// des corps d'affichage — un chiffre, une date, le nom qu'on cherche des yeux
// — tombés entre deux crans ; elles ont rejoint le plus proche, après qu'un
// septième cran (--fs-bigger, 24px) a comblé le trou de 20 à 32.
//
// Le test ne tient PAS une liste de ce qui devrait exister : il lit la
// feuille de style, et pose trois questions dont la réponse ne peut pas
// dériver.

$racine = dirname(__DIR__);

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

// Les tokens tels que la feuille les déclare, pas tels qu'on les croit.
preg_match_all('/--fs-([a-z]+)\s*:\s*([0-9.]+)px/', $css, $m);
$tokens = [];
foreach ($m[1] as $i => $nom) {
    $tokens['--fs-' . $nom] = $m[2][$i] . 'px';
}

echo "1) L'échelle existe et elle est complète\n";

check('les sept tokens de taille sont déclarés', [
    '--fs-tiny'   => '9px',
    '--fs-small'  => '11px',
    '--fs-basic'  => '13px',
    '--fs-large'  => '16px',
    '--fs-big'    => '20px',
    '--fs-bigger' => '24px',
    '--fs-huge'   => '32px',
], $tokens);

// Un var(--fs-…) mal orthographié est une règle VALIDE qui ne dessine rien :
// la propriété est invalide à l'exécution, l'élément hérite du corps de son
// parent, et l'écart se voit à peine.
preg_match_all('/var\(\s*(--fs-[a-z-]+)\s*\)/', $css, $mv);
$inconnus = array_values(array_unique(array_diff($mv[1], array_keys($tokens))));
check('chaque var(--fs-…) pointe un token déclaré', [], $inconnus);

echo "\n2) Aucune règle n'écrit une taille en pixels\n";

// La règle est entière : pas « aucun doublon d'un token », mais AUCUNE valeur
// du tout. Deux pièges distincts, et le second est le sournois.
//
// Une valeur qui recopie un token ne se voit pas : l'écran est juste, il a
// seulement cessé de suivre l'échelle, et il la quittera le jour où le token
// bougera.
//
// Une valeur qui tombe ENTRE deux crans ne se voit pas davantage — deux
// pixels d'écart ne se remarquent sur aucune capture —, mais c'est par elle
// que l'échelle se dissout : avant la réforme, 23 valeurs distinctes de 9 à
// 32, dont aucune n'était vraiment distinguable de sa voisine. Si un écran en
// réclame vraiment une nouvelle, elle devient un CRAN, déclaré en tête de
// feuille avec les autres — c'est ainsi que --fs-bigger est né.
preg_match_all('/font-size:\s*([0-9.]+px)/', $css, $md);
$enDur = [];
foreach (array_count_values($md[1]) as $valeur => $nb) {
    $token = array_search($valeur, $tokens, true);
    $enDur[] = $valeur . ' (× ' . $nb . ')' . ($token !== false ? ' → ' . $token : ' — hors échelle');
}
check('aucune taille de texte n\'est écrite en pixels', [], $enDur);

echo "\n3) Et pas davantage dans une autre unité\n";

// Un rem, un em ou un pourcentage dit la même chose qu'un px par un autre
// chemin : la règle vaut pour eux, sinon l'échelle se contourne en changeant
// d'unité. Une seule exception en place, et elle est d'une autre nature —
// .92em suit le corps de son PARENT, quel qu'il soit, au lieu de viser un
// cran absolu.
preg_match_all('/font-size:\s*([0-9.]+(?:r?em|%))/', $css, $mr);
check('aucune taille relative inattendue', ['.92em'], array_values(array_unique($mr[1])));

echo "\n$tests tests, $fails échec(s)\n";
exit($fails > 0 ? 1 : 0);
