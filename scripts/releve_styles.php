<?php
// Relevé des STYLES CALCULÉS de toutes les pages, pour vérifier qu'une
// réorganisation de la feuille de style ne change rien à l'écran.
//
// Ce n'est pas un test : il compare deux états du code, pas un état à une
// attente. On relève avant, on modifie, on relève après, on compare.
//
//   php scripts/releve_styles.php releve <sortie.json> [--racine=DOSSIER]
//   php scripts/releve_styles.php compare <avant.json> <apres.json> [nb]
//   php scripts/releve_styles.php props <sortie.json> <config> <route,route…> [--racine=…]
//   php scripts/releve_styles.php ecarts <avant-props.json> <apres-props.json>
//
// releve  : 69 pages environ × 8 configurations (largeurs ordinateur, tablette,
//           téléphone ; thème automatique clair/sombre, ou imposé par
//           data-theme). Une empreinte par élément (styles calculés, ::before,
//           ::after, ::placeholder), plus l'état de focus des 25 premiers
//           champs de chaque page. Quelques minutes.
// compare : les éléments dont l'empreinte a changé, par page et configuration.
// props   : les mêmes pages, mais toutes les propriétés en clair — pour
//           comprendre un écart que « compare » a signalé.
// ecarts  : entre deux relevés « props », QUELLES propriétés ont changé, de
//           quelle valeur à quelle valeur, sur combien d'éléments.
//
// --racine : le dossier du code à relever (par défaut ce dépôt). Pour l'état
// d'avant : git worktree add /tmp/lasso-avant HEAD, puis --racine=/tmp/lasso-avant.
//
// La base est une base de démonstration NEUVE à chaque relevé (le tirage est
// déterministe), dans un dossier temporaire : deux relevés voient les mêmes
// données. Reste une source de bruit connue : une page dont le TEXTE change
// d'un relevé à l'autre (diagnostic, sauvegardes, horodatage d'impression : la
// largeur calculée suit le texte). Un écart qui ne survit pas à un second
// relevé n'en est pas un.
// Exclus de l'empreinte : variables, transitions, animations, color-scheme
// (héritée par tout élément : elle masquerait le reste).
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ini_set('memory_limit', '4G');

$args = array_values(array_filter(array_slice($argv, 1), fn ($a) => !str_starts_with($a, '--')));
$opts = [];
foreach (array_slice($argv, 1) as $a) if (preg_match('/^--([a-z]+)=(.*)$/', $a, $m)) $opts[$m[1]] = $m[2];
$mode = $args[0] ?? '';
$usage = "Usage : php scripts/releve_styles.php releve|compare|props|ecarts … (voir l'en-tête du fichier)\n";

if ($mode === 'compare') {
    [$a, $b] = [json_decode((string) file_get_contents($args[1] ?? ''), true), json_decode((string) file_get_contents($args[2] ?? ''), true)];
    if (!is_array($a) || !is_array($b)) { fwrite(STDERR, $usage); exit(2); }
    $diff = 0; $parPage = [];
    foreach ($a as $cle => $els) {
        if (!isset($b[$cle])) { echo "MANQUE $cle\n"; $diff++; continue; }
        foreach ($els as $chemin => $h) if (($b[$cle][$chemin] ?? null) !== $h) { $parPage[$cle][] = $chemin; $diff++; }
        foreach ($b[$cle] as $chemin => $h) if (!isset($els[$chemin])) { $parPage[$cle][] = '+' . $chemin; $diff++; }
    }
    uasort($parPage, fn ($x, $y) => count($y) <=> count($x));
    foreach ($parPage as $cle => $l) {
        echo count($l) . "  $cle\n";
        foreach (array_slice($l, 0, (int) ($args[3] ?? 3)) as $c) echo "      $c\n";
    }
    echo "\n" . count($a) . " captures, $diff différence(s)\n";
    exit($diff ? 1 : 0);
}

if ($mode === 'ecarts') {
    [$a, $b] = [json_decode((string) file_get_contents($args[1] ?? ''), true), json_decode((string) file_get_contents($args[2] ?? ''), true)];
    if (!is_array($a) || !is_array($b)) { fwrite(STDERR, $usage); exit(2); }
    $cpt = [];
    $pseudo = ['', '::before', '::after', '::placeholder'];
    foreach ($a as $cle => $els) foreach ($els as $ch => $v) {
        $w = $b[$cle][$ch] ?? null;
        if ($w === null || $w === $v) continue;
        $pa = explode('§', $v); $pb = explode('§', $w);
        foreach ($pa as $i => $x) {
            $xa = explode('|', $x); $xb = explode('|', $pb[$i] ?? '');
            foreach ($xa as $j => $kv) {
                $kw = $xb[$j] ?? '';
                if ($kw !== $kv) $cpt[trim($pseudo[$i] . ' ' . $kv) . '  →  ' . substr($kw, strpos($kw, '=') + 1)][] = "$cle $ch";
            }
        }
    }
    uasort($cpt, fn ($x, $y) => count($y) <=> count($x));
    foreach ($cpt as $k => $l) echo count($l) . "  $k\n        {$l[0]}\n";
    echo count($cpt) ? '' : "aucun écart\n";
    exit($cpt ? 1 : 0);
}

if (!in_array($mode, ['releve', 'props'], true) || !isset($args[1])) { fwrite(STDERR, $usage); exit(2); }

$racine = realpath($opts['racine'] ?? dirname(__DIR__)) ?: '';
if (!is_file("$racine/index.php")) { fwrite(STDERR, "Pas un dépôt Lasso : $racine\n"); exit(2); }
$depot = dirname(__DIR__);
$sortie = $args[1];

// Le client CDP du test du navigateur, repris tel quel.
$src = (string) file_get_contents("$depot/tests/navigateur_test.php");
$debut = strpos($src, 'final class Cdp');
eval(substr($src, $debut, strpos($src, "\n}\n", $debut) + 3 - $debut));

$chrome = getenv('CHROME_BIN') ?: '';
foreach (['/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/Applications/Chromium.app/Contents/MacOS/Chromium'] as $c) if ($chrome === '' && is_file($c)) $chrome = $c;
foreach (['google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser'] as $c) {
    if ($chrome === '') $chrome = trim((string) shell_exec('command -v ' . escapeshellarg($c) . ' 2>/dev/null'));
}
if ($chrome === '') { fwrite(STDERR, "Chrome introuvable (CHROME_BIN pour l'indiquer).\n"); exit(2); }

$tmp = sys_get_temp_dir() . '/lasso_releve_' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
$base = "$tmp/demo.sqlite";
exec('php ' . escapeshellarg("$racine/scripts/base_demo.php") . ' ' . escapeshellarg($base) . ' --sans-scan 2>&1', $o, $code);
if ($code) { fwrite(STDERR, implode("\n", $o) . "\n"); exit(1); }

$portLibre = function (): int { $s = stream_socket_server('tcp://127.0.0.1:0'); $p = (int) substr(strrchr(stream_socket_get_name($s, false), ':'), 1); fclose($s); return $p; };
file_put_contents("$tmp/prepend.php", '<?php define("APP_DB_PATH", ' . var_export($base, true) . ');');
$port = $portLibre();
$serveur = proc_open(sprintf('exec php -d auto_prepend_file=%s -S 127.0.0.1:%d -t %s', escapeshellarg("$tmp/prepend.php"), $port, escapeshellarg($racine)),
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $t1);
$origine = "http://127.0.0.1:$port";
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
$ctx = fn (string $m, string $h = '', string $c = '') => stream_context_create(['http' => ['method' => $m, 'header' => $h, 'content' => $c, 'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 30]]);
$page = (string) @file_get_contents("$origine/index.php?p=connexion", false, $ctx('GET'));
$cookie = ['', ''];
foreach ($http_response_header ?? [] as $h) if (preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)/i', $h, $c)) $cookie = [$c[1], $c[2]];
preg_match('/name="csrf" value="([^"]+)"/', $page, $m);
@file_get_contents("$origine/index.php?p=connexion", false, $ctx('POST', "Cookie: $cookie[0]=$cookie[1]\r\nContent-Type: application/x-www-form-urlencoded\r\n",
    http_build_query(['csrf' => $m[1] ?? '', 'email' => 'admin@grand-vent.test', 'mot_de_passe' => 'demo1234'])));
foreach ($http_response_header ?? [] as $h) if (preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)/i', $h, $c)) $cookie = [$c[1], $c[2]];

$portChrome = $portLibre();
$nav = proc_open('exec ' . escapeshellarg($chrome) . ' ' . implode(' ', array_map('escapeshellarg', ['--headless=new', "--remote-debugging-port=$portChrome", "--user-data-dir=$tmp/chrome",
    '--no-first-run', '--disable-gpu', '--disable-extensions', '--hide-scrollbars', '--window-size=1280,900', 'about:blank'])),
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $t2);
$ws = '';
for ($i = 0; $i < 100 && $ws === ''; $i++) { usleep(100000); foreach ((array) json_decode((string) @file_get_contents("http://127.0.0.1:$portChrome/json/list"), true) as $c) if (($c['type'] ?? '') === 'page') $ws = $c['webSocketDebuggerUrl']; }
$fin = function () use (&$nav, &$serveur, $tmp) { proc_terminate($nav); proc_terminate($serveur); usleep(300000); exec('rm -rf ' . escapeshellarg($tmp)); };
if ($ws === '') { $fin(); fwrite(STDERR, "Chrome ne répond pas.\n"); exit(1); }
$cdp = new Cdp($ws);
foreach (['Page.enable', 'Runtime.enable', 'Network.enable', 'Animation.enable'] as $x) $cdp->appeler($x);
$cdp->appeler('Browser.setDownloadBehavior', ['behavior' => 'deny']);
$cdp->appeler('Network.setCookie', ['name' => $cookie[0], 'value' => $cookie[1], 'domain' => '127.0.0.1', 'path' => '/']);
// On compare des états, pas des images en mouvement.
$cdp->appeler('Animation.setPlaybackRate', ['playbackRate' => 100000]);
$aller = function (string $url) use ($cdp): bool {
    $cdp->evenements = [];
    $r = $cdp->appeler('Page.navigate', ['url' => $url]);
    return !isset($r['result']['errorText']) && $cdp->attendre('Page.loadEventFired', 15);
};

// Les pages : parcourues depuis le tableau de bord, puis FIGÉES à côté du
// relevé, pour que l'avant et l'après visitent exactement les mêmes.
$fichierRoutes = dirname($sortie) . '/releve_routes.json';
if (!is_file($fichierRoutes)) {
    $exclues = ['deconnexion', 'maj', 'installation', 'connexion', 'motdepasse_oublie', 'motdepasse_reinitialiser', 'desinscription', 'mailing_traiter', 'sauvegarde'];
    $file = ["$origine/index.php?p=tableau_bord"]; $vues = [];
    while ($file && count($vues) < 120) {
        $url = array_shift($file);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $r = (string) ($q['p'] ?? 'tableau_bord');
        if (isset($vues[$r]) || in_array($r, $exclues, true) || preg_match('/(_exporter|_pdf|_csv|_xml|_fichier|_json)(_|$)/', $r)) continue;
        if (!$aller($url)) continue;
        $vues[$r] = substr($url, strlen($origine));
        foreach ((array) $cdp->evaluer('[...document.querySelectorAll("a[href]")].map(a=>a.href)') as $l)
            if (is_string($l) && str_starts_with($l, $origine) && str_contains($l, '?p=')) $file[] = strtok($l, '#');
    }
    file_put_contents($fichierRoutes, json_encode($vues, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
$routes = json_decode((string) file_get_contents($fichierRoutes), true);

$js = <<<'JS'
(async () => {
  const props = [...getComputedStyle(document.documentElement)].filter(p => !p.startsWith('--') && p !== 'transition' && !p.startsWith('transition-') && !p.startsWith('animation') && p !== 'color-scheme');
  const h = %EMPREINTE%;
  const chemin = el => { const p = []; for (; el && el.nodeType === 1 && el !== document.documentElement; el = el.parentElement) { let i = 1, s = el; while ((s = s.previousElementSibling)) i++; p.unshift(el.tagName.toLowerCase() + (typeof el.id === 'string' && el.id ? '#' + el.id : '') + ':' + i); } return p.join('>'); };
  const style = (el, pseudo) => { const c = getComputedStyle(el, pseudo); if (pseudo && (c.content === 'none' || c.content === 'normal')) return ''; return props.map(p => %PROP%).join('|'); };
  const out = {};
  const tous = [document.documentElement, ...document.querySelectorAll('body *:not(script):not(style):not(svg *):not(template *)')];
  for (const el of tous) out[chemin(el)] = h(style(el) + '§' + style(el, '::before') + '§' + style(el, '::after') + '§' + style(el, '::placeholder'));
  const champs = [...document.querySelectorAll('input:not([type=hidden]), select, textarea, button, a.btn, summary')].filter(e => e.offsetParent !== null).slice(0, 25);
  for (const el of champs) { el.focus({preventScroll: true}); out['FOCUS ' + chemin(el)] = h(style(el)); el.blur(); }
  return out;
})()
JS;
$js = $mode === 'props'
    ? str_replace(['%EMPREINTE%', '%PROP%'], ['s => s', "p + '=' + c.getPropertyValue(p)"], $js)
    : str_replace(['%EMPREINTE%', '%PROP%'], ["s => { let x = 2166136261; for (let i = 0; i < s.length; i++) { x ^= s.charCodeAt(i); x = Math.imul(x, 16777619); } return (x >>> 0).toString(36); }", 'c.getPropertyValue(p)'], $js);

$configs = [
    'pc-clair'       => [1280, 900, 'light', null],
    'pc-sombre'      => [1280, 900, 'dark', null],
    'pc-attr-sombre' => [1280, 900, 'light', 'sombre'],
    'pc-attr-clair'  => [1280, 900, 'dark', 'clair'],
    'tel-clair'      => [375, 812, 'light', null],
    'tel-sombre'     => [375, 812, 'dark', null],
    'tablette'       => [768, 1024, 'light', null],
    'large'          => [1440, 900, 'light', null],
];
if ($mode === 'props') {
    if (!isset($configs[$args[2] ?? ''], $args[3])) { $fin(); fwrite(STDERR, $usage); exit(2); }
    $configs = [$args[2] => $configs[$args[2]]];
    $routes = array_intersect_key($routes, array_flip(explode(',', $args[3])));
}
$res = [];
foreach ($configs as $nom => [$l, $hh, $schema, $attr]) {
    $cdp->appeler('Emulation.setDeviceMetricsOverride', ['width' => $l, 'height' => $hh, 'deviceScaleFactor' => 1, 'mobile' => $l < 768]);
    $cdp->appeler('Emulation.setEmulatedMedia', ['features' => [['name' => 'prefers-color-scheme', 'value' => $schema], ['name' => 'prefers-reduced-motion', 'value' => 'reduce']]]);
    foreach ($routes as $r => $url) {
        if (!$aller($origine . $url)) continue;
        $cdp->evaluer('document.fonts.ready.then(() => new Promise(r => setTimeout(r, 150)))');
        // L'attribut se pose DANS l'appel qui relève : posé avant l'attente,
        // il atterrissait parfois sur la page précédente, pas encore remplacée,
        // et des pages entières se relevaient dans le mauvais thème.
        $theme = $attr ? "document.documentElement.setAttribute('data-theme', '$attr'); " : '';
        $res["$nom $r"] = $cdp->evaluer($theme . $js, 60) ?? [];
        fwrite(STDERR, '.');
    }
}
file_put_contents($sortie, json_encode($res));
fwrite(STDERR, "\n" . count($res) . " captures → $sortie\n");
$fin();
