<?php
// Lanceur unique de la suite de tests. Usage :
//
//   php tests/run.php            # analyse syntaxique + tous les tests
//   php tests/run.php --tests    # tests seulement (sans php -l)
//
// Sort avec un code != 0 dès qu'un fichier échoue — c'est tout l'intérêt :
// avant ce lanceur, chaque fichier se lançait à la main et rien ne signalait
// qu'un test avait cessé de tourner. tests/facturation_test.php est resté
// cassé (fatale sur une fonction indéfinie, donc interrompu au 4e groupe
// d'assertions) sans que personne ne le voie, parce qu'aucune commande
// n'agrégeait les résultats ni ne regardait les codes de sortie.
//
// Chaque fichier tourne dans son PROPRE processus PHP : ils définissent tous
// une fonction check() globale, les inclure ensemble déclencherait un
// « Cannot redeclare ». Un sous-processus a en prime l'avantage de convertir
// une erreur fatale en code de sortie 255, donc en échec visible.

declare(strict_types=1);

$racine = dirname(__DIR__);
$lint   = !in_array('--tests', $argv, true);

$echecs   = [];
$reussis  = 0;

// --- 1. Analyse syntaxique -------------------------------------------------
// Sur tout le code du projet, vendor/ exclu (des milliers de fichiers tiers,
// dont certains ciblent des versions de PHP différentes).
if ($lint) {
    echo "Analyse syntaxique\n";
    $fichiers = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS),
            function (SplFileInfo $f): bool {
                $nom = $f->getFilename();
                if ($f->isDir()) {
                    return !in_array($nom, ['vendor', '.git', 'data', 'uploads', 'node_modules'], true);
                }
                return $f->getExtension() === 'php';
            }
        )
    );
    foreach ($it as $f) {
        $fichiers[] = $f->getPathname();
    }
    sort($fichiers);

    $erreursLint = 0;
    foreach ($fichiers as $f) {
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($f) . ' 2>&1', $sortie, $code);
        if ($code !== 0) {
            $erreursLint++;
            echo "  ERREUR  " . substr($f, strlen($racine) + 1) . "\n";
            echo "          " . implode("\n          ", $sortie) . "\n";
        }
        $sortie = [];
    }
    printf("  %d fichiers analysés, %d erreur(s)\n\n", count($fichiers), $erreursLint);
    if ($erreursLint > 0) {
        $echecs[] = 'analyse syntaxique';
    }
}

// --- 2. Fichiers de test ---------------------------------------------------
echo "Tests\n";
$tests = glob(__DIR__ . '/*_test.php') ?: [];
sort($tests);

foreach ($tests as $test) {
    $nom = basename($test);
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($test) . ' 2>&1', $sortie, $code);
    // Dernière ligne non vide : chaque fichier y résume son propre décompte
    // (les formats diffèrent d'un fichier à l'autre, on les affiche tels quels).
    $lignes = array_values(array_filter($sortie, fn ($l) => trim($l) !== ''));
    $resume = $lignes ? end($lignes) : '(aucune sortie)';

    if ($code === 0) {
        $reussis++;
        printf("  ok    %-28s %s\n", $nom, trim($resume));
    } else {
        $echecs[] = $nom;
        printf("  ÉCHEC %-28s code de sortie %d\n", $nom, $code);
        // Sortie complète : sur un échec, le détail est ce qu'on veut lire.
        foreach ($sortie as $l) {
            echo "        $l\n";
        }
    }
    $sortie = [];
}

// --- 3. Verdict ------------------------------------------------------------
echo "\n";
if ($echecs) {
    printf("ÉCHEC — %d/%d fichier(s) en erreur : %s\n", count($echecs), count($tests), implode(', ', $echecs));
    exit(1);
}
printf("Tout passe — %d fichier(s) de test.\n", $reussis);
exit(0);
