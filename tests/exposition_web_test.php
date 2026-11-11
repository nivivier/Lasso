<?php
// Ce que le web ne doit jamais servir. Lancement : php tests/exposition_web_test.php
//
// Les dossiers qui ne contiennent que du code chargé par PHP, ou des données,
// portent chacun un .htaccess « Require all denied ». Et chaque script de
// scripts/ refuse de tourner hors ligne de commande : sur un hébergement sans
// .htaccess (nginx, AllowOverride None), ou sous PHP-CGI avec
// register_argc_argv, « …/scripts/doublons.php?--appliquer » remplirait $argv
// depuis l'URL et écrirait sur la vraie base.

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

echo "1) Dossiers fermés au web\n";
foreach (['data', 'uploads', 'lib', 'views', 'tests', 'scripts', 'vendor'] as $d) {
    $h = "$racine/$d/.htaccess";
    $contenu = is_file($h) ? (string) file_get_contents($h) : '';
    $attendu = $d === 'uploads' ? 'php|phtml' : 'Require all denied';
    check("$d/.htaccess bloque", true, str_contains($contenu, $attendu));
}

echo "\n2) Scripts réservés à la ligne de commande\n";
foreach (glob("$racine/scripts/*.php") as $f) {
    $src = (string) file_get_contents($f);
    $garde = strpos($src, "PHP_SAPI !== 'cli'");
    // La garde doit précéder tout usage de $argv et tout chargement de la
    // configuration : c'est avant eux qu'elle a un sens.
    $avant = array_filter([strpos($src, '$argv'), strpos($src, 'require_once')], fn ($p) => $p !== false);
    check(basename($f) . ' : garde CLI en tête', true, $garde !== false && (!$avant || $garde < min($avant)));
}

echo "\n$tests tests, $fails échec(s)\n";
exit($fails > 0 ? 1 : 0);
