<?php
// Test de fumée : chaque page s'affiche. Lancement : php tests/pages_test.php
//
// Jusqu'ici, quatre routes sur cent soixante-dix étaient appelées par un test :
// une erreur fatale dans une vue — une variable renommée, un partiel déplacé —
// ne se voyait qu'en ouvrant la page. Ce test ouvre TOUTES les routes GET, en
// vrai : un serveur PHP intégré, une base de démonstration neuve
// (scripts/base_demo.php), une session ouverte avec le compte administrateur.
//
// Puis il envoie chaque formulaire POST de ces pages (§ 5) : les chemins
// d'écriture, où se cachent les requêtes que l'affichage n'emprunte jamais.
//
// Il ne vérifie pas ce que la page dit — seulement qu'elle se rend : pas de
// code 5xx, pas d'avertissement, d'erreur ni d'exception PHP dans la réponse
// (en dev, display_errors les écrit dans la page), pas de renvoi vers la
// connexion. Les pages qui montrent UN enregistrement reçoivent le premier
// identifiant de leur table, sans quoi elles ne feraient que rediriger.
//
// Rien n'est écrit hors du dossier temporaire, à une exception près : la
// session du serveur intégré vit dans data/sessions, comme toutes les autres,
// et la déconnexion finale la détruit.

declare(strict_types=1);

$racine = dirname(__DIR__);
$dir = sys_get_temp_dir() . '/lasso_pages_' . bin2hex(random_bytes(6));
mkdir($dir);
$base = "$dir/demo.sqlite";

$tests = 0;
$fails = 0;
function check(string $label, $attendu, $obtenu): void
{
    global $tests, $fails;
    $tests++;
    if ($attendu === $obtenu) {
        return;
    }
    $fails++;
    printf("  FAIL  %s\n        attendu %s\n        obtenu  %s\n", $label, var_export($attendu, true), var_export($obtenu, true));
}

function nettoyer(string $dir): void
{
    foreach (glob("$dir/*") ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
}

// --- 1. La base de démonstration, dans le dossier temporaire
exec('php ' . escapeshellarg("$racine/scripts/base_demo.php") . ' ' . escapeshellarg($base) . ' --sans-scan 2>&1', $sortie, $code);
if ($code !== 0 || !is_file($base)) {
    echo "  FAIL  la base de démonstration n'a pas pu être créée :\n" . implode("\n", $sortie) . "\n";
    nettoyer($dir);
    exit(1);
}

// --- 2. Le serveur intégré, branché sur cette base. APP_DB_PATH est posé
// avant config.php par auto_prepend_file : config.local.php ne le redéfinit
// pas s'il l'est déjà.
file_put_contents("$dir/prepend.php", '<?php define("APP_DB_PATH", ' . var_export($base, true) . ');');
$port = 0;
$sock = stream_socket_server('tcp://127.0.0.1:0');
if ($sock) {
    $port = (int) substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
    fclose($sock);
}
$cmd = sprintf('exec php -d auto_prepend_file=%s -d display_errors=1 -S 127.0.0.1:%d -t %s',
    escapeshellarg("$dir/prepend.php"), $port, escapeshellarg($racine));
$serveur = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', "$dir/serveur.log", 'a'], 2 => ['file', "$dir/serveur.log", 'a']], $pipes);
$url = "http://127.0.0.1:$port/index.php";
for ($i = 0; $i < 50 && @file_get_contents("http://127.0.0.1:$port/robots.txt") === false && !@fsockopen('127.0.0.1', $port); $i++) {
    usleep(100000);
}

// Requêtes HTTP par les flux natifs de PHP, sans curl : le runner de la CI
// n'installe rien. Le cookie de session est tenu à la main ; les redirections
// ne sont pas suivies, c'est leur cible qu'on vérifie.
$cookies = '';
function requete(string $url, string &$cookies, ?array $post = null): array
{
    $entetes = $cookies !== '' ? "Cookie: $cookies\r\n" : '';
    $opts = ['method' => 'GET', 'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 30];
    if ($post !== null) {
        $opts['method'] = 'POST';
        $opts['content'] = http_build_query($post);
        $entetes .= "Content-Type: application/x-www-form-urlencoded\r\n";
    }
    $opts['header'] = $entetes;
    $corps = (string) @file_get_contents($url, false, stream_context_create(['http' => $opts]));
    $reponse = $http_response_header ?? [];
    $code = preg_match('#^HTTP/\S+\s+(\d+)#', (string) ($reponse[0] ?? ''), $m) ? (int) $m[1] : 0;
    $lieu = '';
    foreach ($reponse as $h) {
        if (preg_match('/^Set-Cookie:\s*([^=;]+=[^;]*)/i', $h, $c)) {
            $cookies = $c[1];
        } elseif (preg_match('/^Location:\s*(\S+)/i', $h, $l)) {
            $lieu = $l[1];
        }
    }
    return [$code, $corps, $lieu];
}

// --- 3. Connexion avec le compte administrateur de la démonstration
[, $page] = requete("$url?p=connexion", $cookies);
preg_match('/name="csrf" value="([^"]+)"/', $page, $m);
[$code, , $lieu] = requete("$url?p=connexion", $cookies, [
    'csrf' => $m[1] ?? '', 'email' => 'admin@grand-vent.test', 'mot_de_passe' => 'demo1234',
]);
echo "1) Connexion\n";
check('la connexion redirige hors de la page de connexion', true, $code === 302 && !str_contains($lieu, 'connexion'));

// --- 4. Les routes, lues dans la table de routage
$index = (string) file_get_contents("$racine/index.php");
preg_match_all("/'([a-z0-9_]+)'\s*=>\s*'route_[a-z0-9_]+'/", $index, $mm);
$routes = array_values(array_unique($mm[1]));
// Hors champ : ce qui ferme la session, ou demande un jeton ou un réseau. (La
// sauvegarde, qui écrivait sur un GET, est passée en POST : en GET elle ne fait
// plus que renvoyer à l'export.)
$exclues = ['deconnexion', 'installation', 'connexion', 'motdepasse_oublie', 'motdepasse_reinitialiser',
    'mailing_traiter', 'desinscription', 'maj'];
$routes = array_values(array_diff($routes, $exclues));

// Les pages d'un enregistrement : le premier identifiant de la table qui porte
// le nom de leur objet (fiche_imprimer → fiches).
$pdo = new PDO('sqlite:' . $base);
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
$premierId = function (string $route) use ($pdo, $tables): ?int {
    foreach ([$route, explode('_', $route)[0]] as $objet) {
        $t = $objet . 's';
        if (in_array($t, $tables, true)) {
            $id = $pdo->query("SELECT MIN(id) FROM \"$t\"")->fetchColumn();
            return $id !== null && $id !== false ? (int) $id : null;
        }
    }
    return null;
};

// Les pages dont les paramètres ne suivent pas la règle « &id= du premier
// enregistrement » : on les leur donne explicitement.
$val = fn (string $sql) => (string) $pdo->query($sql)->fetchColumn();
$anneeFiches = $val('SELECT MAX(annee) FROM fiches');
$employeFiches = $val("SELECT MIN(employe_id) FROM fiches WHERE annee = $anneeFiches");
$parametres = [
    'certificat'           => "employe_id=$employeFiches&annee=$anneeFiches",
    'certificat_imprimer'  => "employe_id=$employeFiches&annee=$anneeFiches",
    'quittance_imprimer'   => 'id=' . $val("SELECT MIN(id) FROM pieces WHERE type = 'quittance'"),
    'compta_analyse_axe'   => 'axe=' . $val('SELECT MIN(id) FROM axes_analytiques'),
    'compta_analyse_axe_imprimer' => 'axe=' . $val('SELECT MIN(id) FROM axes_analytiques'),
    'booking_campagne'     => 'id=' . $val('SELECT MIN(id) FROM campagnes'),
];

echo "\n2) Chaque page GET se rend (" . count($routes) . " routes)\n";
$motifsErreur = '/(Fatal error|Parse error|Warning:|Notice:|Deprecated:|Uncaught|Stack trace)/';
$vues = 0;
$variantesVues = 0;
$pagesRendues = [];
foreach ($routes as $r) {
    $id = $premierId($r);
    $suite = $parametres[$r] ?? ($id ? "id=$id" : '');
    $cible = "$url?p=$r" . ($suite !== '' ? "&$suite" : '');
    [$code, $corps, $lieu] = requete($cible, $cookies);
    $etiquette = substr($cible, strlen($url));
    check("$etiquette : pas d'erreur serveur", true, $code < 500);
    check("$etiquette : pas d'erreur PHP dans la page", '', preg_match($motifsErreur, $corps, $e) ? $e[1] . ' — ' . trim(substr($corps, max(0, strpos($corps, $e[1]) - 20), 240)) : '');
    check("$etiquette : la session tient", false, str_contains($lieu, 'p=connexion'));
    if ($code !== 200) {
        // LASSO_PAGES_DETAIL=1 : la liste de ce qui ne s'est pas rendu, pour
        // vérifier qu'aucune vraie page n'y passe (une route POST redirige).
        if (getenv('LASSO_PAGES_DETAIL')) {
            echo "  --    $etiquette → $code $lieu\n";
        }
        continue;
    }
    $vues++;
    $pagesRendues[$cible] = $corps;
    // Les variantes que la page propose elle-même : une recherche, et chaque
    // lien de tri de ses en-têtes. C'est là que se cachent les requêtes
    // construites à la volée — une colonne renommée ne casse que le tri qui
    // la nomme.
    $variantes = ["$cible&q=a"];
    if (preg_match_all('/href="(\?p=' . preg_quote($r, '/') . '&amp;[^"]*\btri=[^"]*)"/', $corps, $tl)) {
        foreach (array_unique($tl[1]) as $lien) {
            $variantes[] = $url . html_entity_decode($lien);
        }
    }
    foreach ($variantes as $v) {
        [$codeV, $corpsV] = requete($v, $cookies);
        $etiq = substr($v, strlen($url));
        check("$etiq : pas d'erreur serveur", true, $codeV < 500);
        check("$etiq : pas d'erreur PHP", '', preg_match($motifsErreur, $corpsV, $e) ? $e[1] : '');
        $variantesVues++;
    }
}
echo "  ($variantesVues variantes de recherche et de tri)\n";
echo "  ($vues pages rendues, les autres redirigent ou téléchargent)\n";
// Un garde-fou contre le test qui ne verrait plus rien : la session perdue, la
// table de routage mal lue, et tout redirigerait en silence.
check('plus de 80 pages rendues', true, $vues > 80);

// --- 5. Les chemins d'écriture : chaque formulaire POST, envoyé tel quel
//
// Les pages GET ne disent rien de ce qui se passe à l'enregistrement : c'est
// là que se trouvent les INSERT/UPDATE construits à la main, les colonnes
// renommées qu'aucun écran ne lit plus. On reprend donc chaque formulaire
// POST des pages rendues, rempli comme le navigateur l'enverrait — ses valeurs
// actuelles, les champs requis vides remplis d'une valeur plausible — et on
// vérifie que la réponse n'est ni une erreur serveur ni une erreur PHP, puis
// que la page où elle renvoie s'affiche aussi.
//
// La base est jetable : les suppressions passent EN DERNIER, pour que les
// autres formulaires trouvent encore leurs enregistrements. Hors champ, en
// plus des routes du § 4 : ce qui ouvre le réseau (géocodage, mise à jour)
// et la restauration, qui remplacerait la base sous le test.
echo "\n3) Chaque formulaire POST s'enregistre sans erreur\n";
$exclusPost = array_merge($exclues, ['sauvegarde_restaurer', 'sauvegarde_copie_restaurer', 'structures_geocoder', 'evenements_geocoder', 'geocoder_ville_unique']);

// Les champs d'un formulaire, et une liste d'envois : un par bouton nommé
// (« section », « action » : c'est souvent lui qui choisit la branche), ou un
// seul s'il n'y en a pas.
function formulaire_envois(DOMXPath $xp, DOMElement $form): array
{
    $champs = [];
    $id = $form->getAttribute('id');
    $noeuds = iterator_to_array($xp->query('.//input|.//select|.//textarea|.//button', $form));
    if ($id !== '') {
        foreach ($xp->query('//*[@form="' . $id . '"]') as $n) {
            $noeuds[] = $n;
        }
    }
    $boutons = [];
    foreach ($noeuds as $n) {
        /** @var DOMElement $n */
        $nom = $n->getAttribute('name');
        $type = strtolower($n->getAttribute('type') ?: ($n->tagName === 'button' ? 'submit' : 'text'));
        if ($n->tagName === 'button' || in_array($type, ['submit', 'image'], true)) {
            if ($nom !== '') {
                $boutons[] = [$nom, $n->getAttribute('value')];
            }
            continue;
        }
        if ($nom === '' || $n->hasAttribute('disabled') || in_array($type, ['reset', 'button', 'file'], true)) {
            continue;
        }
        $requis = $n->hasAttribute('required');
        if ($n->tagName === 'select') {
            $choix = [];
            foreach ($xp->query('.//option', $n) as $o) {
                if ($o->hasAttribute('selected')) {
                    $choix[] = $o->getAttribute('value');
                }
            }
            if (!$choix && ($premiere = $xp->query('.//option', $n)->item(0))) {
                $choix[] = $premiere->getAttribute('value');
            }
            foreach ($n->hasAttribute('multiple') ? $choix : array_slice($choix, 0, 1) as $v) {
                $champs[] = [$nom, $v];
            }
            continue;
        }
        if (in_array($type, ['checkbox', 'radio'], true)) {
            if ($n->hasAttribute('checked')) {
                $champs[] = [$nom, $n->getAttribute('value') ?: 'on'];
            }
            continue;
        }
        $v = $n->tagName === 'textarea' ? $n->textContent : $n->getAttribute('value');
        if ($v === '' && $requis) {
            $v = match ($type) {
                'email'  => 'essai@example.test',
                'number' => $n->getAttribute('min') !== '' ? $n->getAttribute('min') : '1',
                'date'   => date('Y-m-d'),
                'month'  => date('Y-m'),
                'password' => 'essai-mot-de-passe-1234',
                'url'    => 'https://example.test',
                default  => 'Essai',
            };
        }
        $champs[] = [$nom, $v];
    }
    return $boutons ? array_map(fn ($b) => array_merge($champs, [$b]), $boutons) : [$champs];
}

// Corps x-www-form-urlencoded d'une liste de paires (les noms « x[] »
// se répètent : http_build_query() les écraserait).
function corps_formulaire(array $paires): string
{
    return implode('&', array_map(fn ($p) => rawurlencode($p[0]) . '=' . rawurlencode((string) $p[1]), $paires));
}

$envois = [];
foreach ($pagesRendues as $page => $html) {
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8">' . $html);
    $xp = new DOMXPath($dom);
    foreach ($xp->query('//form') as $form) {
        /** @var DOMElement $form */
        if (strtolower($form->getAttribute('method')) !== 'post') {
            continue;
        }
        $action = html_entity_decode($form->getAttribute('action'));
        $action = $action === '' ? $page : (str_starts_with($action, '?') ? $url . $action : (str_starts_with($action, 'index.php') ? "http://127.0.0.1:$port/" . $action : null));
        if ($action === null || !preg_match('/[?&]p=([a-z0-9_]+)/', $action, $pm) || in_array($pm[1], $exclusPost, true)) {
            continue;
        }
        foreach (formulaire_envois($xp, $form) as $paires) {
            $noms = array_map(fn ($p) => $p[0], $paires);
            sort($noms);
            $bouton = end($paires);
            // Une signature par geste : le même formulaire répété sur chaque
            // ligne d'une liste n'est envoyé qu'une fois.
            $signature = $pm[1] . '|' . implode(',', array_unique($noms)) . '|' . (in_array($bouton[0], ['section', 'action', 'mode'], true) ? $bouton[1] : '');
            if (isset($envois[$signature])) {
                continue;
            }
            $detruit = (bool) preg_match('/supprimer|retirer|delier|fusionner|vider|purger/', $pm[1])
                || $form->hasAttribute('data-confirm');
            $envois[$signature] = [$action, $paires, $detruit, $page];
        }
    }
}
uasort($envois, fn ($a, $b) => $a[2] <=> $b[2]);
$postes = 0;
foreach ($envois as [$action, $paires, $detruit, $page]) {
    $etiq = 'POST ' . substr($action, strlen($url)) . ' (depuis ' . substr($page, strlen($url)) . ')';
    $entetes = "Cookie: $cookies\r\nContent-Type: application/x-www-form-urlencoded\r\n";
    $corps = (string) @file_get_contents($action, false, stream_context_create(['http' => [
        'method' => 'POST', 'header' => $entetes, 'content' => corps_formulaire($paires),
        'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 30,
    ]]));
    $rep = $http_response_header ?? [];
    $code = preg_match('#^HTTP/\S+\s+(\d+)#', (string) ($rep[0] ?? ''), $m) ? (int) $m[1] : 0;
    $lieu = '';
    foreach ($rep as $h) {
        if (preg_match('/^Location:\s*(\S+)/i', $h, $l)) {
            $lieu = $l[1];
        }
    }
    $postes++;
    check("$etiq : pas d'erreur serveur", true, $code > 0 && $code < 500);
    check("$etiq : pas d'erreur PHP", '', preg_match($motifsErreur, $corps, $e) ? $e[1] . ' — ' . trim(substr($corps, max(0, strpos($corps, $e[1]) - 20), 240)) : '');
    check("$etiq : la session tient", false, str_contains($lieu, 'p=connexion'));
    // Là où l'enregistrement renvoie : c'est la page qui relit ce qu'on vient
    // d'écrire.
    if ($lieu !== '' && !str_contains($lieu, 'p=connexion')) {
        $suite = str_starts_with($lieu, 'http') ? $lieu : $url . (str_starts_with($lieu, '?') ? $lieu : '?' . ltrim(strstr($lieu, '?') ?: '', '?'));
        [$codeS, $corpsS] = requete($suite, $cookies);
        check("$etiq → la page de retour s'affiche", true, $codeS < 500 && !preg_match($motifsErreur, $corpsS));
    }
}
echo "  ($postes formulaires envoyés, dont " . count(array_filter($envois, fn ($e) => $e[2])) . " suppressions en dernier)\n";
check('plus de 100 formulaires envoyés', true, $postes > 100);

// La file des envois groupés, vidée comme le ferait la tâche planifiée : le
// formulaire « Envoyer » ci-dessus l'a remplie. Ce chemin-là ne lève rien
// quand il se trompe — après le renommage contact_id → personne_id, il
// envoyait en silence à l'adresse de la STRUCTURE au lieu du contact. On
// vérifie donc à qui chaque message est parti.
echo "\n4) La file des envois groupés part aux bonnes adresses\n";
$enFile = (int) $pdo->query("SELECT COUNT(*) FROM mailing_file_attente WHERE statut = 'attente'")->fetchColumn();
check('l\'envoi groupé a rempli la file', true, $enFile > 0);
$pdo->exec("INSERT OR REPLACE INTO parametres (cle, valeur) VALUES ('mailing_traiter_token', 'jeton-de-test'), ('mailing_delai_secondes', '0')");
[$codeF, $corpsF] = requete("$url?p=mailing_traiter&token=jeton-de-test", $cookies);
check('la file se vide sans erreur', true, $codeF === 200 && !preg_match($motifsErreur, $corpsF));
// Seulement les personnes encore rattachées à leur structure : les
// suppressions du § 3 ont pu défaire un lien depuis, et la structure reprend
// alors légitimement la main.
$envois = $pdo->query("SELECT m.destinataire_email, pe.email FROM mailing_envois m
                        JOIN personnes pe ON pe.id = m.personne_id
                        JOIN personne_structures cs ON cs.personne_id = m.personne_id AND cs.structure_id = m.structure_id
                        WHERE m.campagne_id = (SELECT MAX(id) FROM mailing_campagnes)")->fetchAll(PDO::FETCH_NUM);
check('les messages nominatifs sont partis', true, count($envois) > 0);
check('… chacun à l\'adresse de sa personne', [], array_values(array_filter($envois, fn ($e) => $e[0] !== $e[1])));

// --- 7. L'échappement des sorties, vérifié sur le RENDU
//
// 3 900 balises d'affichage PHP (« <?= » … ) dans les vues : les relire un à un ne dit rien de demain.
// On vérifie le résultat plutôt que le code : un texte hostile est ajouté à
// chaque colonne de texte libre de la base, puis chaque page est rendue de
// nouveau. Si la balise <x-xss> ressort telle quelle — dans le corps comme
// dans un attribut, que « "' > » auraient refermé —, une sortie a échappé à
// e(). Échappée, elle ressort en &lt;x-xss&gt; et le test l'ignore.
//
// Les colonnes que l'application interprète (dates, statuts, codes, adresses,
// couleurs, montants…) sont laissées : les abîmer ferait échouer la page pour
// une autre raison que l'échappement.
echo "\n5) Aucune donnée n'est rendue sans échappement\n";
$charge = '"\'><x-xss>';
$exclusCol = '/(^id$|_id$|date|_le$|^le$|statut|type|^code|email|couleur|hash|iban|token|url|^mode|sens|format|chemin|fichier|mime|langue|pays|canton|region|json|criteres|^cle$|numero|origine|^ordre|avatar|photo|icone|logo|telephone|npa|reference|qr|bic|annee|mois|^unite|^taux|monnaie|devise|jours|heures|^niveau|^etat|^issue|^route|^geste|^cible|^source|^format|^categorie$|^variante|^secure|smtp|^host|^port|motdepasse|mot_de_passe|^portee|^periode|^frequence|^axe$|^champ|^operateur|^match|^regle|^valeur$)/';
$colonnes = 0;
foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
    foreach ($pdo->query("PRAGMA table_info(\"$t\")")->fetchAll() as $c) {
        if (stripos((string) $c['type'], 'TEXT') === false || preg_match($exclusCol, $c['name'])) {
            continue;
        }
        try {
            $pdo->prepare("UPDATE \"$t\" SET \"{$c['name']}\" = \"{$c['name']}\" || ? WHERE trim(\"{$c['name']}\") <> ''")->execute([$charge]);
            $colonnes++;
        } catch (PDOException $e) {
            // Un déclencheur (exercice clos) ou une contrainte : colonne laissée.
        }
    }
}
// Le nom de l'employeur, rendu sur toutes les pages, vit dans `parametres`.
$pdo->prepare("UPDATE parametres SET valeur = valeur || ? WHERE cle = 'employeur_nom'")->execute([$charge]);
echo "  ($colonnes colonnes de texte libre chargées)\n";
check('assez de colonnes chargées pour que le test dise quelque chose', true, $colonnes > 80);
$fuites = [];
foreach (array_keys($pagesRendues) as $cible) {
    [$codeX, $corpsX] = requete($cible, $cookies);
    // Une réponse JSON n'est pas du HTML (servie en nosniff) : c'est au script
    // qui la reçoit de l'insérer en texte, ce que ce test ne voit pas.
    if (json_decode($corpsX) !== null) {
        continue;
    }
    if ($codeX === 200 && preg_match_all('/.{0,80}<x-xss>/s', $corpsX, $fx)) {
        $fuites[substr($cible, strlen($url))] = trim(preg_replace('/\s+/', ' ', $fx[0][0]));
    }
}
check('aucune balise injectée ne ressort telle quelle', [], $fuites);

// --- 6. Fin : déconnexion (détruit la session), arrêt du serveur
requete("$url?p=deconnexion", $cookies);
$log = (string) @file_get_contents("$dir/serveur.log");
check('aucune erreur PHP au journal du serveur', '', preg_match($motifsErreur, $log, $e) ? trim(substr($log, (int) strpos($log, $e[1]), 300)) : '');
proc_terminate($serveur);
proc_close($serveur);
nettoyer($dir);

echo "\n$tests tests, $fails échec(s)\n";
exit($fails > 0 ? 1 : 0);
