<?php
// Le garde-fou du nommage (docs/NOMMAGE.md). Lancement : php tests/nommage_test.php
//
// L'application a été renommée d'un bloc : routes, vues et fonctions suivent
// enfin une convention. Ce fichier est ce qui l'empêche de se redégrader —
// sans lui, la dette reviendrait en deux ans, exactement comme la première
// fois. Il ne touche ni la base ni la session : il LIT le code.
//
// Chaque règle renvoie à son numéro dans docs/NOMMAGE.md § 3.

declare(strict_types=1);

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
    printf("  FAIL  %-58s attendu %s, obtenu %s\n", $label, var_export($attendu, true), var_export($obtenu, true));
}

// --- ce que le code déclare -------------------------------------------------
$index = file_get_contents($racine . '/index.php');
preg_match_all("/'([a-z0-9_]+)'\s*=>\s*'(route_[a-z0-9_]+)'/", $index, $m1);
preg_match_all("/\\\$handlers\['([a-z0-9_]+)'\]\s*=\s*'(route_[a-z0-9_]+)'/", $index, $m2);
$routes = array_combine(
    array_merge($m1[1], $m2[1]),
    array_merge($m1[2], $m2[2])
);

$sourcesLib = '';
foreach (glob($racine . '/lib/*.php') as $f) {
    $sourcesLib .= file_get_contents($f);
}

$vues = [];
foreach (glob($racine . '/views/*.php') as $f) {
    $nom = basename($f, '.php');
    // Les partiels (_*) portent le nom de ce qu'ils rendent, pas d'une route ;
    // layout.php enveloppe tout le monde et n'en est pas une non plus.
    if ($nom[0] !== '_' && $nom !== 'layout') {
        $vues[] = $nom;
    }
}

echo "1) Chaque route a sa fonction (N9)\n";
$sansFonction = [];
foreach ($routes as $route => $fn) {
    if (!preg_match('/function\s+' . preg_quote($fn, '/') . '\s*\(/', $sourcesLib)) {
        $sansFonction[] = "$route → $fn()";
    }
}
check('toutes les routes ont leur fonction', [], $sansFonction);
check('aucune route déclarée deux fois', count($routes), count(array_merge($m1[1], $m2[1])));

echo "\n2) Chaque vue pleine porte le nom d'une route (N8)\n";
$vuesOrphelines = array_values(array_diff($vues, array_keys($routes)));
check('aucune vue sans route du même nom', [], $vuesOrphelines);

echo "\n3) Toute route citée existe\n";
// Un ?p= qui ne mène nulle part est un lien mort, et rien ne le signale à
// l'exécution : la page s'ouvre sur le tableau de bord sans rien dire.
$citees = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine));
foreach ($it as $f) {
    $p = $f->getPathname();
    if (preg_match('#/(vendor|\.git|data|node_modules|uploads)/#', $p) || !preg_match('/\.(php|js|css)$/', $p)) {
        continue;
    }
    preg_match_all('/\?p=([a-z0-9_]+)/', file_get_contents($p), $mm);
    foreach ($mm[1] as $r) {
        $citees[$r] = str_replace($racine . '/', '', $p);
    }
}
// Les noms d'exemple des commentaires : « ?p=x », « ?p=structure_xxx »…
$exemples = ['x', 'structure_xxx', 'lieu', 'lieux'];
$mortes = [];
foreach ($citees as $r => $ou) {
    if (!isset($routes[$r]) && !in_array($r, $exemples, true)) {
        $mortes[] = "$r ($ou)";
    }
}
check('aucun ?p= vers une route inexistante', [], $mortes);

echo "\n4) Tout en français (N1)\n";
// Les mots qui avaient survécu : _delete, _print, _save, _new, _edit, _view,
// backup, login, logout, setup, preview. « json », « ical », « csv », « pdf »,
// « xml » et « camt053 » restent : ce sont des noms de format (N10).
$ANGLAIS = ['delete', 'print', 'save', 'new', 'edit', 'view', 'backup', 'login', 'logout', 'setup', 'preview', 'list'];
$anglicismes = [];
foreach (array_keys($routes) as $route) {
    foreach (explode('_', $route) as $mot) {
        if (in_array($mot, $ANGLAIS, true)) {
            $anglicismes[] = "$route (« $mot »)";
        }
    }
}
check('aucun mot anglais dans un nom de route', [], $anglicismes);

echo "\n5) Pas de suffixe _liste (N2)\n";
$listes = array_values(array_filter(array_keys($routes), fn ($r) => str_ends_with($r, '_liste')));
check('aucune route ne finit par _liste', [], $listes);

echo "\n6) Aucune route nue nommée « campagne » (N6)\n";
// L'application a trois sortes de campagnes — démarchage, recherche de fonds,
// envoi groupé. Aucune ne garde le mot nu, pas même la première arrivée.
$nues = array_values(array_filter(
    array_keys($routes),
    fn ($r) => $r === 'campagne' || $r === 'campagnes' || str_starts_with($r, 'campagne_')
));
check('aucune route campagne / campagnes / campagne_*', [], $nues);

echo "\n7) Pas de préfixe parametres_ (N7)\n";
$prefixes = array_values(array_filter(array_keys($routes), fn ($r) => str_starts_with($r, 'parametres_')));
check('aucune route ne commence par parametres_', [], $prefixes);

echo "\n8) Plus aucun « spectacle » dans le code\n";
// Le terme est devenu « projet », jusque dans le schéma (migration_94). Seuls
// le journal des versions et les migrations antérieures le gardent : l'un
// raconte ce qui était vrai alors, les autres tournent AVANT le renommage.
$restes = [];
foreach ($it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine)) as $f) {
    $p = $f->getPathname();
    $rel = str_replace($racine . '/', '', $p);
    if (preg_match('#^(vendor|\.git|data|node_modules|uploads)/#', $rel)
        || !preg_match('/\.(php|js|css)$/', $rel)
        // Ce fichier-ci nomme forcément ce qu'il interdit.
        || $rel === 'tests/nommage_test.php'
        || $rel === 'lib/db/migrations.php') {
        continue;
    }
    if (stripos(file_get_contents($p), 'spectacle') !== false) {
        $restes[] = $rel;
    }
}
check('aucun fichier de code ne dit plus « spectacle »', [], $restes);

echo "\n$tests tests, $fails échec(s)\n";
exit($fails > 0 ? 1 : 0);
