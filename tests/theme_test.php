<?php
// Garde-fou du thème sombre. Lancement : php tests/theme_test.php
//
// Le mode sombre ne tient qu'à une discipline : AUCUNE couleur de surface ne
// doit être écrite en dur dans une règle de composant, puisque seuls les
// tokens portent leurs deux valeurs (light-dark(), 01-base.css). Un « background: #fff » ajouté
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

// Base jetable : couleurs_css_vars() lit les couleurs de l'employeur.
$tmp = sys_get_temp_dir() . '/lasso_theme_' . bin2hex(random_bytes(6)) . '.sqlite';
define('APP_DB_PATH', $tmp);
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
// La feuille entière, ses fichiers bout à bout dans l'ordre de la cascade.
$css = css_source();
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

echo "2) Chaque couleur neutre porte ses deux valeurs\n";
// Une seule déclaration par token : light-dark(clair, sombre), dans 01-base.css.
// Le bloc de tokens redéfinis en sombre — écrit DEUX fois, une sous la media
// query et une sous l'attribut — avait dérivé : dix teintes manquaient au
// choix « sombre » explicite. Il n'existe plus.
foreach (['--bg', '--card', '--ink', '--muted', '--line', '--surface', '--personne', '--evenement'] as $token) {
    check("« $token » s'écrit light-dark(clair, sombre)", 1,
        preg_match('/\n\s*' . preg_quote($token, '/') . '\s*:\s*light-dark\(/', $css));
}
check('plus de palette sombre à part (tokens --d-*)', false, str_contains($css, '--d-bg'));

echo "3) Les couleurs paramétrables ont leurs variantes sombres (PHP)\n";
$helpers = (string) file_get_contents($racine . '/lib/helpers.php');
foreach (['primary_sombre', 'primary_d_sombre', 'primary_tint_sombre', 'brand_sombre'] as $cle) {
    check("couleurs_derivees() produit « $cle »", true, str_contains($helpers, "'$cle'"));
}
// L'injecteur de couleur (celui de l'employeur) est écrit APRÈS la feuille et
// l'emporte en cascade : il doit porter lui aussi les deux valeurs.
$vars = couleurs_css_vars();
check('l\'injecteur écrit --primary en light-dark()', 1, preg_match('/--primary:light-dark\(/', $vars));
check('… et --highlight', 1, preg_match('/--highlight:light-dark\(/', $vars));
check('… sans bloc sombre à part', false, str_contains($vars, 'prefers-color-scheme'));

echo "4) Structure à trois états (clair / sombre / automatique)\n";
// color-scheme décide du côté de light-dark() : « light dark » laisse le
// système choisir (automatique), l'attribut impose un choix explicite.
check('automatique : color-scheme « light dark » sur :root', 1,
    preg_match('/:root\s*\{\s*color-scheme:\s*light dark/', $css));
check('choix « clair » explicite', true, str_contains($css, ':root[data-theme="clair"] { color-scheme: light; }'));
check('choix « sombre » explicite', 1, preg_match('/:root\[data-theme="sombre"\]\s*\{\s*color-scheme:\s*dark/', $css));
// --sombre (0/1) pour ce qui n'est pas une couleur : la media query doit
// exclure un choix « clair », sinon un thème clair choisi resterait atténué
// sur un système sombre.
check('--sombre : la media query exclut un choix « clair » explicite', true,
    str_contains($css, ':root:not([data-theme="clair"]) { --sombre: 1; }'));

foreach (glob($tmp . '*') ?: [] as $f) {
    @unlink($f);
}
echo "\n$tests tests, $fails échec(s)\n";
exit($fails > 0 ? 1 : 0);
