<?php
// Les dettes du CSS qui ne doivent plus grossir. Lancement : php tests/css_test.php
//
// 1. !important — il ne se surcharge plus : celui qui en pose un oblige le
//    suivant à en poser un second (c'est arrivé : la bascule « deux colonnes »
//    devait se masquer par-dessus un display déjà forcé). Un seul est légitime,
//    [hidden] { display: none !important }, qui garantit que l'attribut HTML
//    l'emporte sur tout display: flex/grid. Pour gagner contre une règle plus
//    spécifique, on répète sa spécificité (voir .seg-picker dans assets/css/).
//
// 2. Couleurs écrites en dur hors des tokens (--…: dans :root et les thèmes).
//    Celles qui restent sont pour l'essentiel voulues FIXES : blanc sur un fond
//    coloré, papier blanc des documents imprimés (qui reste blanc en mode
//    sombre), ombres noires translucides. Le plafond est un cliquet : on peut
//    le baisser, jamais le monter — une couleur nouvelle se déclare en token.
//
// 3 à 6. Variables lues sans être définies (ou l'inverse), z-index en
//    chiffres, espacements hors échelle, points de rupture hors paliers :
//    les échelles vivent dans assets/css/01-base.css, ce test les tient.
//
// 7 à 10. :not() enchaînés, arrondis en dur, :has() et sélecteurs profonds,
//    poids des commentaires : des cliquets, qu'on baisse et ne monte jamais.

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

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/helpers.php';
// La feuille entière, ses fichiers mis bout à bout dans l'ordre de la cascade
// (CSS_FICHIERS, lib/helpers.php).
$css = css_source();
// Le dossier et la liste disent la même chose : un fichier déposé dans
// assets/css/ sans être inscrit dans CSS_FICHIERS ne serait jamais chargé, et
// rien ne le signalerait. Leur ordre alphabétique est aussi celui de la
// cascade (préfixes 01-, 02-…) : on le lit d'un coup d'œil dans le dossier.
$dossier = array_map(fn ($f) => 'assets/css/' . basename($f), glob("$racine/assets/css/*.css") ?: []);
sort($dossier);
check('assets/css/ et CSS_FICHIERS listent les mêmes fichiers, dans le même ordre', $dossier, CSS_FICHIERS);
check('plus de feuille d\'un seul tenant (assets/app' . '.css)', false, is_file("$racine/assets/app" . '.css'));
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

echo "\n3) Variables : toute variable lue est définie, toute variable définie est lue\n";
// Une variable lue sans être définie ne lève aucune erreur : la propriété
// retombe sur sa valeur héritée ou initiale, en silence (var(--text) a ainsi
// laissé un bouton prendre la couleur de son parent). Une variable définie
// sans être lue est du code mort qui se recopie dans chaque thème.
// Lectures et définitions se cherchent aussi dans le PHP et le JavaScript :
// une vue pose --vignette ou --depth en style en ligne, couleurs_css_vars()
// injecte les couleurs de l'employeur.
$autres = '';
foreach (array_merge(glob("$racine/lib/*.php") ?: [], glob("$racine/lib/*/*.php") ?: [],
    glob("$racine/views/*.php") ?: [], glob("$racine/assets/*.js") ?: [], [$racine . '/index.php']) as $f) {
    $autres .= file_get_contents($f) . "\n";
}
preg_match_all('#(--[a-z][a-z0-9-]*)\s*:#', $code, $d);
preg_match_all('#(?<![\w-])(--[a-z][a-z0-9-]*)\s*[:\'"]#', $autres, $dAutres);
preg_match_all('#var\(\s*(--[a-z][a-z0-9-]*)#', $code . "\n" . $autres, $l);
// setProperty('--x', …) dans un script : une définition.
preg_match_all('#setProperty\(\s*[\'"](--[a-z][a-z0-9-]*)#', $autres, $dJs);
$definies = array_unique(array_merge($d[1], $dAutres[1], $dJs[1]));
$lues = array_unique($l[1]);
// La palette des états et des types (docs/UI.md § 13) : trois valeurs par
// état, deux par type, réservées même tant qu'aucun écran ne s'en sert.
$palette = ['--amber-bord', '--danger-bord', '--ok-tint', '--ok-bord', '--personne-tint', '--document-tint', '--evenement-tint'];
$nonDefinies = array_values(array_diff($lues, $definies));
sort($nonDefinies);
check('aucune variable lue sans être définie', [], $nonDefinies);
$mortes = array_values(array_diff($definies, $lues, $palette));
sort($mortes);
check('aucune variable définie sans être lue (hors palette de UI.md § 13)', [], $mortes);

echo "\n4) Calques : tout z-index de 10 ou plus passe par un nom (--z-…, 01-base.css)\n";
// Vingt et une valeurs éparses, de -1 à 1001, ne disaient pas qui passe devant
// qui : il fallait les retrouver toutes pour en poser une nouvelle. Les noms
// sont rangés dans l'ordre à un seul endroit. 0 à 5 restent des chiffres :
// ils ne rangent que les enfants d'un même composant.
$zEnDur = [];
foreach ($lignes as $i => $ligne) {
    if (preg_match_all('#z-index\s*:\s*(-?\d+)#', $ligne, $m)) {
        foreach ($m[1] as $v) if ((int) $v >= 10) $zEnDur[] = 'ligne ' . ($i + 1) . " : $v";
    }
}
check('aucun z-index de 10 ou plus écrit en chiffres', [], $zEnDur);

echo "\n5) Espacements hors de l'échelle --esp-* (gap, margin, padding)\n";
// L'échelle (4/8/12/16/24, 01-base.css) est appliquée là où une déclaration y
// tenait déjà entièrement ; le reste (6, 10, 14px…) la rejoint quand on
// retouche sa règle. Cliquet, comme pour les couleurs : on le baisse, jamais
// on ne le monte. Et une déclaration est en jetons OU en chiffres, pas les deux.
$horsEchelle = 0; $melanges = [];
preg_match_all('#(?<![\w-])(?:gap|row-gap|column-gap|margin|padding)(?:-[a-z-]+)?\s*:\s*([^;{}]*)#', $code, $m, PREG_OFFSET_CAPTURE);
foreach ($m[1] as [$valeur, $pos]) {
    $px = preg_match('#(?<![\w.-])\d+(?:\.\d+)?px#', $valeur);
    if ($px && str_contains($valeur, 'var(--esp-')) $melanges[] = 'ligne ' . (substr_count($code, "\n", 0, $pos) + 1) . ' : ' . trim($valeur);
    elseif ($px) $horsEchelle++;
}
check('aucune déclaration ne mêle jetons --esp-* et pixels', [], $melanges);
$plafondEsp = 486;
check("déclarations d'espacement hors échelle ≤ $plafondEsp (ici $horsEchelle)", true, $horsEchelle <= $plafondEsp);

echo "\n6) Points de rupture : seulement les paliers nommés (01-base.css)\n";
// Douze largeurs différentes pour une seule fenêtre : chaque seuil ajouté
// créait une bande de quelques pixels où la page ne ressemblait à aucune
// autre. Une @media ne lit pas de variable, d'où une liste ici.
$paliers = [480, 700, 800, 801, 900, 1099, 1300, 1400, 1401];
$autresLargeurs = [];
preg_match_all('#@media[^{]*#', $code, $m);
foreach ($m[0] as $requete) {
    preg_match_all('#(?:min|max)-width\s*:\s*(\d+)px#', $requete, $w);
    foreach ($w[1] as $l) if (!in_array((int) $l, $paliers, true)) $autresLargeurs[] = trim($requete);
}
check('aucune @media hors des paliers nommés', [], array_values(array_unique($autresLargeurs)));

echo "\n7) Pas de liste d'exceptions : au plus trois :not() à la suite\n";
// « td:not(.a):not(.b):not(.c)… » énumère, loin de la vue, les cellules que
// la vue affiche : renommer une classe y fait disparaître une colonne sans
// bruit. La vue déclare ce qu'elle garde (.carte-garde, docs/UI.md § 9).
preg_match_all('#(?::not\([^()]*\)){4,}#', $code, $m);
check('aucun sélecteur à plus de trois :not() enchaînés', [], $m[0]);

echo "\n8) Arrondis hors des jetons --radius-*\n";
// Neuf valeurs d'arrondi écrites à la main (2, 3, 5, 6, 7, 9, 11px…) pour
// quatre familles de formes. Cliquet : un arrondi nouveau prend un jeton.
preg_match_all('#border(?:-[a-z]+)*-radius\s*:\s*([^;{}]*)#', $code, $m);
$arrondis = count(array_filter($m[1], fn ($v) => preg_match('#(?<![\w.-])(?:[1-9]\d*(?:\.\d+)?px|\d+%)#', $v)));
$plafondArrondis = 7;
check("arrondis écrits en dur ≤ $plafondArrondis (ici $arrondis)", true, $arrondis <= $plafondArrondis);

echo "\n9) Sélecteurs : :has() et profondeur\n";
// Chaque :has() restant a été relu : il lit un ÉTAT (:checked, [open]), une
// structure que la vue ignore (une mini-carte n'existe que si la ville est
// géocodée), ou porte une règle voulue sans exception. Un :has() neuf qui ne
// fait que retrouver une structure connue de la vue est une classe à poser.
// Même cliquet pour les sélecteurs de cinq composés ou plus : ce sont des duels
// de spécificité, chacun en appelle un suivant.
$nbHas = 0;
$profonds = 0;
preg_match_all('#([^{}]+)\{#', $code, $m);
foreach ($m[1] as $sel) {
    $sel = trim($sel);
    if ($sel === '' || $sel[0] === '@' || preg_match('#^(from|to|\d+%)#', $sel)) {
        continue;
    }
    foreach (explode(',', $sel) as $un) {
        $nbHas += substr_count($un, ':has(');
        $plat = preg_replace('#\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\)#', '()', trim($un));
        if (count(preg_split('#\s*[ >+~]\s*#', $plat, -1, PREG_SPLIT_NO_EMPTY)) >= 5) {
            $profonds++;
        }
    }
}
$plafondHas = 46;
$plafondProfonds = 26;
check(":has() ≤ $plafondHas (ici $nbHas)", true, $nbHas <= $plafondHas);
check("sélecteurs de 5 composés ou plus ≤ $plafondProfonds (ici $profonds)", true, $profonds <= $plafondProfonds);

echo "\n10) Poids des commentaires\n";
// Un commentaire énonce l'invariante ; le récit va dans docs/DECISIONS.md.
// Cliquet en octets : le volume ne remonte pas.
$poidsCommentaires = strlen($css) - strlen(preg_replace('#/\*.*?\*/#s', '', $css));
$plafondCommentaires = 270100;
check("commentaires ≤ $plafondCommentaires octets (ici $poidsCommentaires)", true, $poidsCommentaires <= $plafondCommentaires);

echo "\n$tests tests, $fails échec(s)\n";
exit($fails > 0 ? 1 : 0);
