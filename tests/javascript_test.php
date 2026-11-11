<?php
// Le JavaScript, que php -l ne voit pas. Lancement : php tests/javascript_test.php
//
// Une faute de frappe dans assets/app.js ou dans le <script> d'une vue ne
// cassait que l'écran concerné, et seulement à l'usage : php -l ne lit pas le
// JavaScript, et rien d'autre ne le lisait. Ce test fait analyser chaque source
// par un vrai moteur — sans l'exécuter : new Function(source) compile et
// s'arrête là.
//
// Moteur : Node s'il est installé (il l'est sur le runner de la CI), sinon
// JavaScriptCore, que tout Mac porte via osascript. Aucun des deux n'est une
// dépendance du projet : ce sont des outils de la machine qui teste. Sans
// moteur, le test le DIT (« ignoré ») ; en CI, il échoue — une vérification
// qui se saute en silence là où elle doit tourner ne vérifie rien.
//
// Les <script> des vues mêlent du PHP : une balise d'écho devient 0 (une
// expression valide partout où elle sert de valeur, y compris entre
// guillemets), un bloc de code PHP disparaît. Les deux branches d'un if PHP se retrouvent donc à la
// suite, ce qui reste du JavaScript valide tant que chacune l'est.

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

// Toutes les sources : fichiers de assets/ (hors vendor/, qui n'est pas à nous)
// et blocs <script> en ligne des vues. Rend [nom lisible => source].
function sources_js(string $racine): array
{
    $sources = [];
    foreach (glob("$racine/assets/*.js") as $f) {
        $sources['assets/' . basename($f)] = (string) file_get_contents($f);
    }
    foreach (glob("$racine/views/*.php") as $f) {
        // Les blocs de code PHP s'effacent AVANT de chercher les balises : un
        // commentaire qui parle d'une balise <script> en serait une sinon. Ils
        // laissent leurs sauts de ligne, pour que les numéros restent justes.
        $src = preg_replace_callback('#<\?php.*?(\?>|\z)#s', fn ($b) => str_repeat("\n", substr_count($b[0], "\n")), (string) file_get_contents($f));
        // Les attributs peuvent contenir du PHP (le nonce), dont la balise
        // fermante porte un « > » : on l'enjambe au lieu de s'y arrêter.
        preg_match_all('#<script\b((?:<\?.*?\?>|[^>])*)>(.*?)</script>#s', $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($m as $bloc) {
            [$attrs, $corps] = [$bloc[1][0], $bloc[2][0]];
            if (preg_match('#\bsrc=|type="(?!text/javascript|module)#', $attrs) || trim($corps) === '') {
                continue;
            }
            $ligne = substr_count($src, "\n", 0, $bloc[2][1]) + 1;
            $js = preg_replace('#<\?=.*?\?>#s', '0', $corps);
            $js = preg_replace('#<\?php.*?\?>#s', '', $js);
            $sources['views/' . basename($f) . ':' . $ligne] = $js;
        }
    }
    return $sources;
}

// Le même vérificateur pour les deux moteurs, aux entrées/sorties près.
function moteur_js(): ?string
{
    if (trim((string) shell_exec('command -v node 2>/dev/null')) !== '') {
        return 'node';
    }
    if (PHP_OS_FAMILY === 'Darwin' && trim((string) shell_exec('command -v osascript 2>/dev/null')) !== '') {
        return 'jsc';
    }
    return null;
}

function verifier_syntaxe(string $moteur, array $sources): array
{
    $dir = sys_get_temp_dir() . '/lasso_js_' . bin2hex(random_bytes(4));
    mkdir($dir);
    $liste = [];
    $i = 0;
    foreach ($sources as $nom => $js) {
        $chemin = "$dir/" . $i++ . '.js';
        file_put_contents($chemin, $js);
        $liste[] = ['nom' => $nom, 'chemin' => $chemin];
    }
    file_put_contents("$dir/liste.json", json_encode($liste));
    $coeur = 'const erreurs = {};
        for (const s of liste) {
            try { new Function(lire(s.chemin)); } catch (e) { erreurs[s.nom] = String(e); }
        }';
    if ($moteur === 'node') {
        $prog = 'const fs = require("fs"); const lire = p => fs.readFileSync(p, "utf8");
            const liste = JSON.parse(lire(process.argv[2]));' . $coeur . '
            process.stdout.write(JSON.stringify(erreurs));';
        file_put_contents("$dir/verif.js", $prog);
        $sortie = shell_exec('node ' . escapeshellarg("$dir/verif.js") . ' ' . escapeshellarg("$dir/liste.json") . ' 2>&1');
    } else {
        $prog = 'ObjC.import("Foundation");
            function run(argv) {
                const lire = p => $.NSString.stringWithContentsOfFileEncodingError(p, $.NSUTF8StringEncoding, null).js;
                const liste = JSON.parse(lire(argv[0]));' . $coeur . '
                return JSON.stringify(erreurs);
            }';
        file_put_contents("$dir/verif.js", $prog);
        $sortie = shell_exec('osascript -l JavaScript ' . escapeshellarg("$dir/verif.js") . ' ' . escapeshellarg("$dir/liste.json") . ' 2>&1');
    }
    array_map('unlink', glob("$dir/*"));
    rmdir($dir);
    $erreurs = json_decode((string) $sortie, true);
    return is_array($erreurs) ? $erreurs : ['(moteur)' => trim((string) $sortie)];
}

$sources = sources_js($racine);

echo "1) Le relevé des sources est complet\n";
check('assets/app.js en fait partie', true, isset($sources['assets/app.js']));
$enLigne = count(array_filter(array_keys($sources), fn ($n) => str_starts_with($n, 'views/')));
// Un garde-fou contre l'expression régulière qui ne trouverait plus rien : le
// test passerait alors sur zéro script.
check('plus de 50 blocs <script> trouvés dans les vues', true, $enLigne > 50);
check('aucun PHP ne subsiste dans les sources', [], array_keys(array_filter($sources, fn ($js) => str_contains($js, '<?'))));

echo "\n2) Chaque source se compile\n";
$moteur = moteur_js();
if ($moteur === null) {
    if (getenv('CI')) {
        check('un moteur JavaScript est disponible en CI', true, false);
    } else {
        echo "  --    ignoré : ni node ni osascript sur cette machine\n";
    }
} else {
    echo "  (moteur : $moteur, " . count($sources) . " sources)\n";
    $erreurs = verifier_syntaxe($moteur, $sources);
    foreach ($erreurs as $nom => $msg) {
        check("$nom compile", 'ok', $msg);
    }
    check('aucune erreur de syntaxe', 0, count($erreurs));
}

echo "\n3) Conventions (docs/UI.md § 11)\n";
// alert() bloque la page et ne ressemble à rien d'autre dans l'application :
// lassoToast() pose le même message flottant que les retours du serveur.
$alertes = array_keys(array_filter($sources, fn ($js) => preg_match('#(?<![\w.])alert\s*\(#', $js)));
check('aucun alert() — lassoToast() à la place', [], $alertes);

echo "\n$tests tests, $fails échec(s)\n";
exit($fails > 0 ? 1 : 0);
