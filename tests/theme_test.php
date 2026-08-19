<?php
// Garde-fou du thème sombre. Lancement : php tests/theme_test.php
//
// Le mode sombre ne tient qu'à une discipline : AUCUNE couleur de surface ne
// doit être écrite en dur dans une règle de composant, puisque seul le bloc de
// tokens est redéfini sous prefers-color-scheme. Un « background: #fff » ajouté
// par distraction produit un rectangle blanc au milieu d'une page sombre — et
// personne ne le voit tant qu'il ne bascule pas son système.
//
// Sont tolérés :
//   - les définitions de tokens (lignes « --x: … ») ;
//   - « color: #fff », qui est du texte blanc SUR un fond coloré ;
//   - les surfaces d'impression et l'aperçu du logo clair, blancs par nature ;
//   - les data-URI (SVG encodés).

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
$lignes = explode("\n", $css);

// Surfaces légitimement blanches quel que soit le thème :
//   .sheet / body.print-page  — on imprime sur du papier ;
//   .logo-preview.clair       — montre justement le logo clair sur blanc ;
//   .regle-toggle-pill::after — le curseur d'un interrupteur, blanc sur les
//                               deux thèmes comme sur n'importe quelle bascule.
$exceptions = ['.sheet', 'body.print-page', '.logo-preview.clair', '.regle-toggle-pill'];

echo "1) Aucun fond de composant écrit en dur\n";
$fautifs = [];
foreach ($lignes as $n => $l) {
    if (str_contains($l, 'data:image') || preg_match('/^\s*--/', $l)) {
        continue;
    }
    foreach ($exceptions as $e) {
        if (str_contains($l, $e)) {
            continue 2;
        }
    }
    // Seules les surfaces CLAIRES cassent le thème sombre. Un voile foncé
    // (scrim, calque de modale, rgba(0,0,0,…)) reste valable sur les deux, et
    // « rgba(255,255,255,.12) » posé sur la barre de marque aussi. On ne
    // signale donc que : un hex clair, le mot-clé white, ou un blanc
    // translucide à plus de 25 % d'opacité.
    if (!preg_match('/background(-color)?\s*:\s*([^;]+)/', $l, $m)) {
        continue;
    }
    $valeur = trim($m[2]);
    $clair = false;
    if (preg_match('/^#([0-9a-fA-F]{6})/', $valeur, $h)) {
        $clair = (hexdec(substr($h[1], 0, 2)) + hexdec(substr($h[1], 2, 2)) + hexdec(substr($h[1], 4, 2))) / 3 > 200;
    } elseif (preg_match('/^#([0-9a-fA-F]{3})\b/', $valeur, $h)) {
        $clair = strtolower($h[1]) === 'fff';
    } elseif (stripos($valeur, 'white') === 0) {
        $clair = true;
    } elseif (preg_match('/rgba\(\s*255\s*,\s*255\s*,\s*255\s*,\s*\.?([0-9]*)/', $valeur, $o)) {
        $clair = ((float) ('0.' . ($o[1] ?: '0'))) > 0.25;
    }
    if ($clair) {
        $fautifs[] = 'ligne ' . ($n + 1) . ' : ' . trim(substr($l, 0, 70));
    }
}
check('aucun background en dur dans une règle', [], $fautifs);

echo "2) Le bloc sombre existe et couvre les tokens neutres\n";
$aSombre = str_contains($css, '@media (prefers-color-scheme: dark)');
check('un bloc prefers-color-scheme est présent', true, $aSombre);

// Extrait le premier bloc sombre (celui des tokens).
$debut = strpos($css, '@media (prefers-color-scheme: dark)');
$blocSombre = $debut !== false ? substr($css, $debut, 3000) : '';
foreach (['--bg', '--card', '--ink', '--muted', '--line', '--surface'] as $token) {
    check("« $token » redéfini en sombre", true, (bool) preg_match('/\\' . $token . '\s*:/', $blocSombre));
}

echo "3) Les couleurs paramétrables ont leurs variantes sombres (PHP)\n";
$helpers = (string) file_get_contents($racine . '/lib/helpers.php');
foreach (['primary_sombre', 'primary_d_sombre', 'primary_tint_sombre', 'brand_sombre'] as $cle) {
    check("couleurs_derivees() produit « $cle »", true, str_contains($helpers, "'$cle'"));
}
// Les deux injecteurs doivent émettre un bloc sombre, sinon la couleur claire
// de l'employeur (ou du module) écrase la palette sombre : ils sont écrits
// APRÈS la feuille de style.
check(
    'couleurs_css_vars() émet un bloc sombre',
    2,
    substr_count($helpers, '@media (prefers-color-scheme: dark){:root{')
);

echo "\n$tests tests, $fails échec(s)\n";
exit($fails > 0 ? 1 : 0);
