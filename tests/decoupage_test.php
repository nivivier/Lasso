<?php
// Les fichiers découpés passent par leur porte. Lancement : php tests/decoupage_test.php
//
// lib/helpers.php et lib/booking.php ont été découpés en sous-fichiers
// (lib/helpers/, lib/booking/) qu'ils chargent eux-mêmes. La règle : un
// sous-fichier ne se requiert JAMAIS directement — « require lib/booking.php »
// doit continuer de tout apporter, et un sous-fichier chargé seul manquerait
// des constantes et des fonctions de ses voisins. Et chaque sous-fichier doit
// être chargé par sa porte : un fichier ajouté au dossier mais oublié dans la
// liste ne serait jamais lu.

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

$portes = ['helpers' => 'lib/helpers.php', 'booking' => 'lib/booking.php'];

$fichiers = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $rel = substr($f->getPathname(), strlen($racine) + 1);
    if ($f->getExtension() === 'php' && !preg_match('#^(vendor|data|\.git)/#', $rel)) {
        $fichiers[$rel] = (string) file_get_contents($f->getPathname());
    }
}

foreach ($portes as $dossier => $porte) {
    echo "lib/$dossier/ — porte unique : $porte\n";
    foreach (glob("$racine/lib/$dossier/*.php") as $sous) {
        $nom = basename($sous);
        $motif = '#require(_once)?\s*\(?\s*__DIR__\s*\.\s*\'[./]*' . preg_quote("$dossier/$nom", '#') . '\'#';
        $qui = array_keys(array_filter($fichiers, fn ($src) => preg_match($motif, $src)));
        check("$dossier/$nom chargé par sa porte, et par elle seule", [$porte], $qui);
    }
}

echo "\n$tests tests, $fails échec(s)\n";
exit($fails > 0 ? 1 : 0);
