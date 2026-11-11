<?php
// Deux dettes du CSS qui ne doivent plus grossir. Lancement : php tests/css_test.php
//
// 1. !important — il ne se surcharge plus : celui qui en pose un oblige le
//    suivant à en poser un second (c'est arrivé : la bascule « deux colonnes »
//    devait se masquer par-dessus un display déjà forcé). Un seul est légitime,
//    [hidden] { display: none !important }, qui garantit que l'attribut HTML
//    l'emporte sur tout display: flex/grid. Pour gagner contre une règle plus
//    spécifique, on répète sa spécificité (voir .seg-picker dans app.css).
//
// 2. Couleurs écrites en dur hors des tokens (--…: dans :root et les thèmes).
//    Celles qui restent sont pour l'essentiel voulues FIXES : blanc sur un fond
//    coloré, papier blanc des documents imprimés (qui reste blanc en mode
//    sombre), ombres noires translucides. Le plafond est un cliquet : on peut
//    le baisser, jamais le monter — une couleur nouvelle se déclare en token.

$racine = dirname(__DIR__);
$tests = 0;
$fails = 0;
function check(string $label, $attendu, $obtenu): void
{
    global $tests, $fails;
    $tests++;
    if ($attendu !== $obtenu) {
        $fails++;
        printf("  FAIL  %-56s attendu %s, obtenu %s\n", $label, var_export($attendu, true), var_export($obtenu, true));
    } else {
        printf("  ok    %s\n", $label);
    }
}

const COULEURS_EN_DUR_MAX = 63;

$css = (string) file_get_contents("$racine/assets/app.css");
// Les commentaires sont effacés en gardant leurs sauts de ligne : ils parlent
// de !important et citent des couleurs, et les numéros de ligne restent justes.
$code = preg_replace_callback('#/\*.*?\*/#s', fn ($m) => str_repeat("\n", substr_count($m[0], "\n")), $css);
$lignes = explode("\n", $code);

echo "1) !important\n";
$importants = [];
foreach ($lignes as $i => $l) {
    if (str_contains($l, '!important')) {
        $importants[] = ($i + 1) . ': ' . trim($l);
    }
}
check('un seul, celui de [hidden]', ['[hidden] { display: none !important; }'],
    array_map(fn ($s) => preg_replace('#^\d+: #', '', $s), $importants));

echo "\n2) Couleurs en dur hors tokens\n";
$enDur = [];
foreach ($lignes as $i => $l) {
    if (preg_match('#^\s*--[\w-]+\s*:#', $l)) {
        continue;
    }
    // rgb(var(--x-rgb) / .3) reste un token : seule la valeur littérale compte.
    preg_match_all('#\#[0-9a-fA-F]{3,8}\b|(?:rgba?|hsla?)\((?!\s*var\()[^)]*\)#', $l, $m);
    foreach ($m[0] as $c) {
        $enDur[] = ($i + 1) . ': ' . $c;
    }
}
$n = count($enDur);
check("au plus " . COULEURS_EN_DUR_MAX . " (obtenu $n)", true, $n <= COULEURS_EN_DUR_MAX);
if ($n < COULEURS_EN_DUR_MAX) {
    echo "  note  le plafond peut descendre à $n (COULEURS_EN_DUR_MAX)\n";
}

echo "\n$tests tests, $fails échec(s)\n";
exit($fails > 0 ? 1 : 0);
