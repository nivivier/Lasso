<?php
// Le JavaScript, EXÉCUTÉ dans un vrai navigateur. Lancement :
// php tests/navigateur_test.php
//
// javascript_test.php compile chaque script sans l'exécuter, pages_test.php
// ouvre chaque page sans navigateur : ni l'un ni l'autre ne voit une erreur
// qui ne survient qu'à l'exécution — une fonction renommée, un élément que le
// script cherche et qui n'existe plus, un script inline que la politique de
// sécurité (CSP) bloque. Ce test les voit.
//
// Comment : un Chrome sans fenêtre, piloté par son protocole de débogage
// (Chrome DevTools Protocol, une WebSocket), depuis PHP pur — aucune
// bibliothèque. Sur une base de démonstration neuve, il part du tableau de
// bord et suit les liens de l'application, UNE page par route. Sur chacune, il
// ouvre et referme les menus et entonnoirs, passe les crayons en édition,
// tape dans les champs de recherche — les gestes qui ne soumettent rien — et
// relève toute exception JavaScript, toute erreur de console et tout blocage
// de la CSP.
//
// Chrome n'est pas une dépendance du projet : c'est un outil de la machine
// qui teste, comme Node pour javascript_test.php. Le runner de la CI en porte
// un. Sans Chrome, le test le DIT et passe — sauf en CI, où il échoue : une
// vérification qui se saute en silence là où elle doit tourner ne vérifie
// rien. CHROME_BIN désigne un autre exécutable.

declare(strict_types=1);

$racine = dirname(__DIR__);
$enCi = getenv('CI') !== false && getenv('CI') !== '';

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

// --- 0. Un Chrome ?
$chrome = getenv('CHROME_BIN') ?: '';
if ($chrome === '') {
    foreach (['/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
              '/Applications/Chromium.app/Contents/MacOS/Chromium'] as $c) {
        if (is_file($c)) {
            $chrome = $c;
            break;
        }
    }
}
if ($chrome === '') {
    foreach (['google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser'] as $c) {
        $p = trim((string) shell_exec('command -v ' . escapeshellarg($c) . ' 2>/dev/null'));
        if ($p !== '') {
            $chrome = $p;
            break;
        }
    }
}
if ($chrome === '') {
    echo "  ignoré  aucun Chrome sur cette machine (CHROME_BIN pour en désigner un)\n";
    echo $enCi ? "  FAIL  en CI, ce test doit tourner\n" : '';
    exit($enCi ? 1 : 0);
}

// --- Client WebSocket minimal, juste ce que demande le protocole de Chrome :
// trames texte masquées à l'envoi, trames non masquées (éventuellement
// fragmentées, longueur 16 ou 64 bits) à la réception.
final class Cdp
{
    private $s;
    private int $id = 0;
    /** @var list<array> */
    public array $evenements = [];

    public function __construct(string $ws)
    {
        $u = parse_url($ws);
        $this->s = @stream_socket_client("tcp://{$u['host']}:{$u['port']}", $no, $msg, 10);
        if (!$this->s) {
            throw new RuntimeException("WebSocket injoignable : $msg");
        }
        $cle = base64_encode(random_bytes(16));
        fwrite($this->s, "GET {$u['path']} HTTP/1.1\r\nHost: {$u['host']}:{$u['port']}\r\n"
            . "Upgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $cle\r\nSec-WebSocket-Version: 13\r\n\r\n");
        stream_set_timeout($this->s, 10);
        $rep = '';
        while (!str_contains($rep, "\r\n\r\n")) {
            $c = fread($this->s, 1);
            if ($c === '' || $c === false) {
                throw new RuntimeException('poignée de main WebSocket interrompue');
            }
            $rep .= $c;
        }
        if (!preg_match('#^HTTP/1\.1 101#', $rep)) {
            throw new RuntimeException('WebSocket refusée : ' . strtok($rep, "\r"));
        }
    }

    private function envoyer(string $texte): void
    {
        $n = strlen($texte);
        $h = chr(0x81) . ($n < 126 ? chr(0x80 | $n) : ($n < 65536 ? chr(0x80 | 126) . pack('n', $n) : chr(0x80 | 127) . pack('J', $n)));
        $masque = random_bytes(4);
        fwrite($this->s, $h . $masque . ($texte ^ str_repeat($masque, intdiv($n, 4) + 1)));
    }

    private function octets(int $n): string
    {
        $d = '';
        while (strlen($d) < $n) {
            $c = fread($this->s, $n - strlen($d));
            if ($c === '' || $c === false) {
                if (feof($this->s) || stream_get_meta_data($this->s)['timed_out']) {
                    throw new RuntimeException('WebSocket fermée ou muette');
                }
                continue;
            }
            $d .= $c;
        }
        return $d;
    }

    // Le prochain message, ou null si rien n'arrive dans le délai. Le délai ne
    // porte que sur le PREMIER octet : une trame commencée se lit jusqu'au bout.
    private function message(float $delai): ?array
    {
        $texte = '';
        while (true) {
            $s = (int) floor(max(0.0, $delai));
            stream_set_timeout($this->s, $s, (int) ((max(0.0, $delai) - $s) * 1e6));
            $b0 = fread($this->s, 1);
            if ($b0 === '' || $b0 === false) {
                if (stream_get_meta_data($this->s)['timed_out']) {
                    return null;
                }
                throw new RuntimeException('WebSocket fermée');
            }
            stream_set_timeout($this->s, 30);
            $b1 = ord($this->octets(1));
            $op = ord($b0) & 0x0f;
            $n = $b1 & 0x7f;
            if ($n === 126) {
                $n = unpack('n', $this->octets(2))[1];
            } elseif ($n === 127) {
                $n = unpack('J', $this->octets(8))[1];
            }
            $charge = $n > 0 ? $this->octets($n) : '';
            if ($op === 8) {
                throw new RuntimeException('WebSocket fermée par Chrome');
            }
            if ($op === 9) {
                continue; // ping : Chrome n'en envoie pas, on l'ignore
            }
            $texte .= $charge;
            if (ord($b0) & 0x80) {
                return json_decode($texte, true) ?: [];
            }
            $delai = 30.0;
        }
    }

    // Un événement reçu : rangé, et une boîte de dialogue (confirm, alert) est
    // refermée aussitôt — elle bloquerait la page.
    private function ranger(array $m): void
    {
        if (isset($m['id'])) {
            return; // réponse à un appel qu'on n'attend plus (fermeture d'un dialogue)
        }
        if (($m['method'] ?? '') === 'Page.javascriptDialogOpening') {
            $this->envoyer((string) json_encode(['id' => ++$this->id, 'method' => 'Page.handleJavaScriptDialog', 'params' => ['accept' => false]]));
        }
        $this->evenements[] = $m;
    }

    public function appeler(string $methode, array $params = [], float $delai = 30): array
    {
        $id = ++$this->id;
        $this->envoyer((string) json_encode(['id' => $id, 'method' => $methode, 'params' => (object) $params]));
        $fin = microtime(true) + $delai;
        while (true) {
            $m = $this->message($fin - microtime(true));
            if ($m === null) {
                throw new RuntimeException("$methode : pas de réponse");
            }
            if (($m['id'] ?? null) === $id) {
                return $m;
            }
            $this->ranger($m);
        }
    }

    // Attend un événement nommé ; rend vrai s'il est arrivé dans le délai.
    public function attendre(string $methode, float $delai): bool
    {
        foreach ($this->evenements as $e) {
            if (($e['method'] ?? '') === $methode) {
                return true;
            }
        }
        $fin = microtime(true) + $delai;
        while (($reste = $fin - microtime(true)) > 0) {
            $m = $this->message($reste);
            if ($m === null) {
                return false;
            }
            $this->ranger($m);
            if (($m['method'] ?? '') === $methode) {
                return true;
            }
        }
        return false;
    }

    // Laisse arriver ce qui est en route pendant un court instant.
    public function ecouter(float $delai): void
    {
        $fin = microtime(true) + $delai;
        while (($reste = $fin - microtime(true)) > 0) {
            $m = $this->message($reste);
            if ($m === null) {
                return;
            }
            $this->ranger($m);
        }
    }

    public function evaluer(string $js, float $delai = 20): mixed
    {
        $r = $this->appeler('Runtime.evaluate', ['expression' => $js, 'awaitPromise' => true, 'returnByValue' => true], $delai);
        return $r['result']['result']['value'] ?? null;
    }
}

$dir = sys_get_temp_dir() . '/lasso_navigateur_' . bin2hex(random_bytes(6));
mkdir($dir);
$base = "$dir/demo.sqlite";
$serveur = $navigateur = null;
$nettoyer = function () use (&$serveur, &$navigateur, $dir): void {
    foreach ([$navigateur, $serveur] as $p) {
        if (is_resource($p)) {
            proc_terminate($p);
            proc_close($p);
        }
    }
    exec('rm -rf ' . escapeshellarg($dir));
};

// --- 1. Base de démonstration, serveur intégré, session (comme pages_test.php)
exec('php ' . escapeshellarg("$racine/scripts/base_demo.php") . ' ' . escapeshellarg($base) . ' --sans-scan 2>&1', $sortie, $code);
if ($code !== 0) {
    echo "  FAIL  base de démonstration :\n" . implode("\n", $sortie) . "\n";
    $nettoyer();
    exit(1);
}
$portLibre = function (): int {
    $s = stream_socket_server('tcp://127.0.0.1:0');
    $p = (int) substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
    fclose($s);
    return $p;
};
file_put_contents("$dir/prepend.php", '<?php define("APP_DB_PATH", ' . var_export($base, true) . ');');
$port = $portLibre();
$serveur = proc_open(sprintf('exec php -d auto_prepend_file=%s -S 127.0.0.1:%d -t %s',
    escapeshellarg("$dir/prepend.php"), $port, escapeshellarg($racine)),
    [0 => ['pipe', 'r'], 1 => ['file', "$dir/serveur.log", 'a'], 2 => ['file', "$dir/serveur.log", 'a']], $tuyaux);
$origine = "http://127.0.0.1:$port";
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
    usleep(100000);
}
$ctx = fn (string $methode, string $entetes = '', string $corps = '') => stream_context_create(['http' => [
    'method' => $methode, 'header' => $entetes, 'content' => $corps, 'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 30]]);
$page = (string) @file_get_contents("$origine/index.php?p=connexion", false, $ctx('GET'));
$cookie = '';
foreach ($http_response_header ?? [] as $h) {
    if (preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)/i', $h, $c)) {
        $cookie = [$c[1], $c[2]];
    }
}
preg_match('/name="csrf" value="([^"]+)"/', $page, $m);
@file_get_contents("$origine/index.php?p=connexion", false, $ctx('POST',
    'Cookie: ' . ($cookie ? "$cookie[0]=$cookie[1]" : '') . "\r\nContent-Type: application/x-www-form-urlencoded\r\n",
    http_build_query(['csrf' => $m[1] ?? '', 'email' => 'admin@grand-vent.test', 'mot_de_passe' => 'demo1234'])));
foreach ($http_response_header ?? [] as $h) {
    if (preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)/i', $h, $c)) {
        $cookie = [$c[1], $c[2]]; // l'identifiant change à la connexion
    }
}

// --- 2. Chrome sans fenêtre
$portChrome = $portLibre();
$args = ['--headless=new', "--remote-debugging-port=$portChrome", '--user-data-dir=' . "$dir/chrome",
    '--no-first-run', '--no-default-browser-check', '--disable-gpu', '--disable-extensions',
    '--window-size=1280,900', 'about:blank'];
if ($enCi) {
    $args[] = '--no-sandbox'; // le bac à sable de Chrome ne démarre pas dans le conteneur du runner
}
$navigateur = proc_open('exec ' . escapeshellarg($chrome) . ' ' . implode(' ', array_map('escapeshellarg', $args)),
    [0 => ['pipe', 'r'], 1 => ['file', "$dir/chrome.log", 'a'], 2 => ['file', "$dir/chrome.log", 'a']], $tuyauxChrome);
$cibles = null;
for ($i = 0; $i < 100 && !$cibles; $i++) {
    usleep(100000);
    $cibles = json_decode((string) @file_get_contents("http://127.0.0.1:$portChrome/json/list"), true);
}
$ws = '';
foreach ((array) $cibles as $c) {
    if (($c['type'] ?? '') === 'page') {
        $ws = (string) $c['webSocketDebuggerUrl'];
        break;
    }
}
echo "1) Le navigateur\n";
check('Chrome démarre et se laisse piloter', true, $ws !== '');
check('la session de démonstration est ouverte', true, is_array($cookie));
if ($ws === '' || !is_array($cookie)) {
    $nettoyer();
    exit(1);
}

$cdp = new Cdp($ws);
foreach (['Page.enable', 'Runtime.enable', 'Log.enable'] as $m) {
    $cdp->appeler($m);
}
// Un lien d'export ou de téléchargement ne doit rien télécharger.
$cdp->appeler('Browser.setDownloadBehavior', ['behavior' => 'deny']);
$cdp->appeler('Network.enable');
$cdp->appeler('Network.setCookie', ['name' => $cookie[0], 'value' => $cookie[1], 'domain' => '127.0.0.1', 'path' => '/']);

// Les gestes qui ne soumettent rien. Chaque bloc est protégé : un geste qui
// échoue ne doit pas masquer les suivants — c'est l'erreur de la PAGE qu'on
// relève, par le protocole, pas celle de ce script.
$gestes = <<<'JS'
(async () => {
    const pause = ms => new Promise(r => setTimeout(r, ms));
    const visibles = sel => [...document.querySelectorAll(sel)].filter(e => e.offsetParent !== null);
    let n = 0;
    for (const s of visibles('details > summary').slice(0, 20)) {
        try { s.click(); await pause(40); s.click(); n++; } catch (e) {}
    }
    for (const b of visibles('button.edit-toggle-btn, button.epf-edit-btn, button.camp-ligne-crayon, button.row-edit-btn, button[data-edit]').slice(0, 8)) {
        try { b.click(); await pause(60); n++; } catch (e) {}
    }
    for (const i of visibles('input[type="search"], input.cat-search-input, input[name="q"]').slice(0, 4)) {
        try {
            i.focus(); i.value = 'a'; i.dispatchEvent(new Event('input', { bubbles: true }));
            await pause(120); i.value = ''; i.dispatchEvent(new Event('input', { bubbles: true })); n++;
        } catch (e) {}
    }
    await pause(150);
    return n;
})()
JS;

$liens = <<<'JS'
[...document.querySelectorAll('a[href]')].map(a => a.href)
JS;

// Hors champ : ce qui ferme la session, part sur le réseau, ou télécharge.
$exclues = ['deconnexion', 'maj', 'installation', 'connexion', 'motdepasse_oublie', 'motdepasse_reinitialiser',
    'desinscription', 'mailing_traiter', 'sauvegarde'];
$telechargement = '/(_exporter|_pdf|_csv|_xml|_fichier|_imprimer|_json)(_|$)/';

// --- 3. Le parcours
$file = ["$origine/index.php?p=tableau_bord"];
$vues = [];
$erreurs = [];
$pages = 0;
while ($file && $pages < 150) {
    $url = array_shift($file);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    $route = (string) ($q['p'] ?? 'tableau_bord');
    if (isset($vues[$route]) || in_array($route, $exclues, true) || preg_match($telechargement, $route)) {
        continue;
    }
    $vues[$route] = true;
    $pages++;
    $cdp->evenements = [];
    try {
        $nav = $cdp->appeler('Page.navigate', ['url' => $url]);
        if (isset($nav['result']['errorText'])) {
            continue; // un téléchargement refusé, pas une page
        }
        if (!$cdp->attendre('Page.loadEventFired', 15)) {
            $erreurs[] = "$route : la page ne finit pas de se charger";
            continue;
        }
        $cdp->evaluer($gestes);
        $cdp->ecouter(0.2);
        foreach ((array) $cdp->evaluer($liens) as $l) {
            if (is_string($l) && str_starts_with($l, $origine) && str_contains($l, '?p=')) {
                $file[] = strtok($l, '#');
            }
        }
    } catch (RuntimeException $e) {
        // Un geste a pu quitter la page (lien, envoi) : le contexte d'exécution
        // a disparu. Ce n'est pas une erreur de la page.
        if (!str_contains($e->getMessage(), 'pas de réponse')) {
            throw $e;
        }
    }
    foreach ($cdp->evenements as $ev) {
        $p = $ev['params'] ?? [];
        $texte = match ($ev['method'] ?? '') {
            'Runtime.exceptionThrown' => strtok((string) ($p['exceptionDetails']['exception']['description']
                ?? $p['exceptionDetails']['text'] ?? ''), "\n"),
            'Runtime.consoleAPICalled' => ($p['type'] ?? '') === 'error'
                ? 'console.error : ' . (string) ($p['args'][0]['value'] ?? $p['args'][0]['description'] ?? '') : null,
            'Log.entryAdded' => ($p['entry']['level'] ?? '') === 'error'
                ? (string) ($p['entry']['text'] ?? '') . (isset($p['entry']['url']) ? ' (' . $p['entry']['url'] . ')' : '') : null,
            default => null,
        };
        if ($texte !== null && $texte !== '') {
            $erreurs[] = "$route : $texte";
        }
    }
}

echo "\n2) Le parcours\n";
check('au moins 60 routes ouvertes (obtenu ' . $pages . ')', true, $pages >= 60);
check('aucune erreur JavaScript, de console ni de CSP', [], array_values(array_unique($erreurs)));

// Fin de session côté serveur, puis on éteint tout.
@file_get_contents("$origine/index.php?p=deconnexion", false, $ctx('GET', "Cookie: $cookie[0]=$cookie[1]\r\n"));
$nettoyer();

echo "\n$tests tests, $fails échec(s)\n";
exit($fails > 0 ? 1 : 0);
