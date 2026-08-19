<?php
// Garde-fou de la politique de sécurité de contenu.
// Lancement : php tests/csp_test.php
//
// Depuis que script-src a perdu 'unsafe-inline' au profit d'un nonce
// (send_security_headers(), lib/helpers.php), deux fautes redeviennent
// possibles et sont toutes deux SILENCIEUSES à l'écriture :
//
//   1. une balise <script> inline sans nonce → le navigateur refuse de
//      l'exécuter, sans rien casser visiblement côté serveur ;
//   2. un attribut de gestionnaire (onclick=, onsubmit=…) → un nonce ne les
//      couvre pas, ils ne s'exécutent plus du tout.
//
// Ces deux contrôles portent sur le source des vues, donc l'erreur est
// signalée à l'écriture plutôt que découverte en production.

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
    printf("  FAIL  %-52s attendu %s, obtenu %s\n", $label, var_export($attendu, true), var_export($obtenu, true));
}

// Fichiers susceptibles de produire du HTML.
$fichiers = [];
foreach ([$racine . '/views', $racine . '/lib'] as $dossier) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dossier, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $fichiers[] = $f->getPathname();
        }
    }
}
sort($fichiers);

echo "1) Toute balise <script> inline porte un nonce\n";
$sansNonce = [];
foreach ($fichiers as $f) {
    $src = (string) file_get_contents($f);
    // Retire les commentaires PHP sur une ligne, qui mentionnent « <script> »
    // en prose sans en produire.
    $src = preg_replace('#^\s*//.*$#m', '', $src);
    if (!preg_match_all('#<script\b([^>]*)>#i', $src, $m, PREG_SET_ORDER)) {
        continue;
    }
    foreach ($m as $balise) {
        $attrs = $balise[1];
        if (stripos($attrs, 'src=') !== false) {
            continue; // script externe : couvert par 'self'
        }
        if (stripos($attrs, 'nonce=') === false) {
            $sansNonce[] = basename($f);
        }
    }
}
check('aucune balise <script> inline sans nonce', [], array_values(array_unique($sansNonce)));

echo "2) Aucun attribut de gestionnaire inline (non couvert par un nonce)\n";
$avecHandler = [];
foreach ($fichiers as $f) {
    $src = (string) file_get_contents($f);
    $src = preg_replace('#^\s*//.*$#m', '', $src);
    // on…= précédé d'une espace, pour ne pas confondre avec « version= » etc.
    if (preg_match('#\son(click|submit|change|input|load|error|keydown|keyup|focus|blur|mouseover|mouseout)\s*=#i', $src)) {
        $avecHandler[] = basename($f);
    }
}
check('aucun onclick/onsubmit/onchange… dans les vues', [], $avecHandler);

echo "3) L'en-tête CSP lui-même\n";
require_once $racine . '/lib/config.php';
require_once $racine . '/lib/helpers.php';
$nonce = csp_nonce();
check('csp_nonce() renvoie une valeur non vide', true, $nonce !== '');
check('csp_nonce() est stable dans la requête', $nonce, csp_nonce());
check('csp_nonce() fait au moins 16 octets encodés', true, strlen($nonce) >= 20);

echo "\n$tests tests, $fails échec(s)\n";
exit($fails > 0 ? 1 : 0);
