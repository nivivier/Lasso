<?php
// ============================================================================
//  Versionnage & mises à jour de l'application.
//  Phase 1-2 : numéro de version (fichier VERSION), canaux (branches git),
//  détection « à jour / mise à jour disponible » et diagnostic exec/git.
//  L'exécution de la mise à jour (phase 3) n'est pas encore implémentée.
// ============================================================================

const MAJ_REPO   = 'nivivier/Lasso';
const MAJ_CANAUX = ['stable' => 'stable', 'test' => 'main']; // canal => branche git

// Version locale : lue dans le fichier VERSION (aucune dépendance à git).
function maj_version_locale(): string
{
    $f = __DIR__ . '/../VERSION';
    return is_file($f) ? (trim((string) file_get_contents($f)) ?: '0.0.0') : '0.0.0';
}

// Canal suivi (préférence stockée en base ; défaut « test »).
function maj_canal(): string
{
    $c = (string) param('maj_canal', 'test');
    return isset(MAJ_CANAUX[$c]) ? $c : 'test';
}

function maj_branche(string $canal): string
{
    return MAJ_CANAUX[$canal] ?? 'main';
}

// exec() utilisable (non désactivé par disable_functions) ?
function maj_exec_dispo(): bool
{
    if (!function_exists('exec')) {
        return false;
    }
    $off = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    return !in_array('exec', $off, true);
}

// git appelable sur le dépôt local ?
function maj_git_dispo(): bool
{
    if (!maj_exec_dispo()) {
        return false;
    }
    $out = [];
    $code = 1;
    @exec('git -C ' . escapeshellarg(__DIR__ . '/..') . ' rev-parse --short HEAD 2>/dev/null', $out, $code);
    return $code === 0 && !empty($out);
}

// --- Capacités pour la mise à jour par archive (Cas B, sans exec/git) ---
function maj_zip_dispo(): bool
{
    return class_exists('ZipArchive');
}
function maj_targz_dispo(): bool
{
    return class_exists('PharData');
}
function maj_download_dispo(): bool
{
    return function_exists('curl_init') || filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN);
}
function maj_app_dir(): string
{
    return dirname(__DIR__);
}
// Le process PHP peut-il écrire dans les dossiers de code (indispensable au swap) ?
function maj_app_writable(): bool
{
    $base = maj_app_dir();
    foreach ([$base, "$base/lib", "$base/views", "$base/assets"] as $d) {
        if (!is_dir($d) || !is_writable($d)) {
            return false;
        }
    }
    return true;
}
// Mise à jour web faisable par archive ?
function maj_archive_possible(): bool
{
    return maj_download_dispo() && (maj_zip_dispo() || maj_targz_dispo()) && maj_app_writable();
}

// SHA court du commit local — lu directement dans .git (sans binaire git).
function maj_sha_local(): ?string
{
    $base = __DIR__ . '/..';
    $head = @file_get_contents("$base/.git/HEAD");
    if ($head === false) {
        return null;
    }
    if (preg_match('/ref:\s*(\S+)/', $head, $m)) {
        $ref = @file_get_contents("$base/.git/" . $m[1]);
        return ($ref !== false && trim($ref) !== '') ? substr(trim($ref), 0, 7) : null;
    }
    return substr(trim($head), 0, 7) ?: null; // HEAD détaché
}

// Jeton de lecture GitHub (dépôt privé) : constante config.local.php ou paramètre.
// Laissé vide si le dépôt est public.
function maj_token(): string
{
    if (defined('MAJ_TOKEN') && MAJ_TOKEN !== '') {
        return (string) MAJ_TOKEN;
    }
    return (string) param('maj_token', '');
}

// Requête HTTP (cURL puis repli allow_url_fopen). $headers = en-têtes supplémentaires.
// Renvoie null si échec réseau ou code != 200.
function maj_http_get(string $url, array $headers = []): ?string
{
    $headers = array_merge(['User-Agent: Lasso-updater'], $headers);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_HTTPHEADER => $headers,
        ]);
        $r    = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return ($r !== false && $code === 200) ? (string) $r : null;
    }
    if (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
        $ctx = stream_context_create(['http' => ['timeout' => 8, 'header' => implode("\r\n", $headers)]]);
        $r = @file_get_contents($url, false, $ctx);
        return $r === false ? null : (string) $r;
    }
    return null;
}

// En-têtes d'authentification GitHub (vides si dépôt public / pas de jeton).
function maj_gh_headers(string $accept = 'application/vnd.github+json'): array
{
    $h = ['Accept: ' . $accept];
    $t = maj_token();
    if ($t !== '') {
        $h[] = 'Authorization: Bearer ' . $t;
    }
    return $h;
}

// Version distante : fichier VERSION de la branche du canal.
// Via l'API « contents » (Accept: raw) → fonctionne en public ET en privé (avec jeton).
function maj_version_distante(string $canal): ?string
{
    $url = 'https://api.github.com/repos/' . MAJ_REPO . '/contents/VERSION?ref=' . maj_branche($canal);
    $v = maj_http_get($url, maj_gh_headers('application/vnd.github.raw'));
    return $v === null ? null : (trim($v) ?: null);
}

// SHA complet du commit local (lecture .git, gère packed-refs).
function maj_sha_local_full(): ?string
{
    $base = maj_app_dir();
    $head = @file_get_contents("$base/.git/HEAD");
    if ($head === false) {
        return null;
    }
    if (preg_match('/ref:\s*(\S+)/', $head, $m)) {
        $ref = @file_get_contents("$base/.git/" . $m[1]);
        if ($ref !== false && trim($ref) !== '') {
            return trim($ref);
        }
        $packed = @file_get_contents("$base/.git/packed-refs");
        if ($packed !== false && preg_match('/^([0-9a-f]{40})\s+' . preg_quote($m[1], '/') . '$/m', $packed, $pm)) {
            return $pm[1];
        }
        return null;
    }
    return trim($head) ?: null;
}

// Position du commit local vis-à-vis du canal (API GitHub compare) :
// 'identical' | 'behind' (retard → MAJ dispo) | 'ahead' (avance → recul) | 'diverged'.
function maj_position(string $canal): ?string
{
    $sha = maj_sha_local_full();
    if ($sha === null) {
        return null;
    }
    $json = maj_http_get(
        'https://api.github.com/repos/' . MAJ_REPO . '/compare/' . maj_branche($canal) . '...' . $sha,
        maj_gh_headers()
    );
    if ($json === null) {
        return null;
    }
    $d = json_decode($json, true);
    return $d['status'] ?? null;
}

// SHA court du dernier commit distant du canal (API GitHub).
function maj_sha_distant(string $canal): ?string
{
    $json = maj_http_get('https://api.github.com/repos/' . MAJ_REPO . '/commits/' . maj_branche($canal), maj_gh_headers());
    if ($json === null) {
        return null;
    }
    $d = json_decode($json, true);
    return isset($d['sha']) ? substr((string) $d['sha'], 0, 7) : null;
}

// --- Exécution de la mise à jour par archive (Cas B) ---

// Interrupteur : la MAJ web est active sauf si explicitement désactivée en config.
function maj_web_active(): bool
{
    return !defined('ALLOW_WEB_UPDATE') || ALLOW_WEB_UPDATE === true;
}

function maj_log_path(): string
{
    return dirname(APP_DB_PATH) . '/maj.log';
}
function maj_log(string $msg): void
{
    @file_put_contents(maj_log_path(), '[' . date('c') . '] ' . $msg . "\n", FILE_APPEND);
}

// Téléchargement binaire vers un fichier. true si OK (HTTP 200, non vide).
function maj_telecharger(string $url, string $dest): bool
{
    if (function_exists('curl_init')) {
        $fp = @fopen($dest, 'wb');
        if (!$fp) {
            return false;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 120, CURLOPT_USERAGENT => 'Lasso-updater',
        ]);
        $ok   = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        fclose($fp);
        return $ok !== false && $code === 200 && @filesize($dest) > 0;
    }
    if (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
        $data = @file_get_contents($url);
        return $data !== false && @file_put_contents($dest, $data) !== false;
    }
    return false;
}

function maj_rmdir_recursif(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $it) {
        if ($it === '.' || $it === '..') {
            continue;
        }
        $p = "$dir/$it";
        is_dir($p) ? maj_rmdir_recursif($p) : @unlink($p);
    }
    @rmdir($dir);
}

// Copie récursive de $src sur $dst (écrase les fichiers, crée les dossiers manquants).
// Les fichiers non versionnés (data/, uploads/, config.local.php) n'étant pas dans
// l'archive, ils ne sont jamais touchés.
function maj_copier_arbre(string $src, string $dst): void
{
    foreach (scandir($src) as $it) {
        if ($it === '.' || $it === '..') {
            continue;
        }
        $s = "$src/$it";
        $d = "$dst/$it";
        if (is_dir($s)) {
            if (!is_dir($d) && !@mkdir($d, 0775, true) && !is_dir($d)) {
                throw new RuntimeException("Dossier non créable : $it");
            }
            maj_copier_arbre($s, $d);
        } elseif (!@copy($s, $d)) {
            throw new RuntimeException("Copie impossible : $it");
        }
    }
}

// Met à jour l'application vers le dernier état du canal. Renvoie un tableau résultat.
function maj_executer(string $canal): array
{
    $ancienne = maj_version_locale();
    $branche  = maj_branche($canal);
    $app      = maj_app_dir();
    $base     = dirname(APP_DB_PATH) . '/maj_tmp_' . bin2hex(random_bytes(4));
    $zip      = $base . '.zip';
    $ext      = $base . '_x';
    try {
        // 1) Sauvegarde de la base.
        if (is_file(APP_DB_PATH)) {
            @copy(APP_DB_PATH, preg_replace('/\.sqlite$/', '', APP_DB_PATH) . '_maj_' . date('Ymd_His') . '.sqlite.bak');
        }
        // 2) Téléchargement de l'archive du canal (dépôt public).
        if (!@mkdir($ext, 0775, true) && !is_dir($ext)) {
            throw new RuntimeException('Dossier temporaire non créable.');
        }
        $url = 'https://github.com/' . MAJ_REPO . '/archive/refs/heads/' . $branche . '.zip';
        if (!maj_telecharger($url, $zip)) {
            throw new RuntimeException("Téléchargement de l'archive échoué.");
        }
        // 3) Extraction.
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('ZipArchive indisponible.');
        }
        $za = new ZipArchive();
        if ($za->open($zip) !== true) {
            throw new RuntimeException('Archive illisible.');
        }
        $za->extractTo($ext);
        $za->close();
        // 4) Dossier racine de l'archive (Lasso-<branche>).
        $tops = array_values(array_filter(scandir($ext), fn($x) => $x !== '.' && $x !== '..' && is_dir("$ext/$x")));
        if (count($tops) !== 1) {
            throw new RuntimeException("Structure d'archive inattendue.");
        }
        $racine = "$ext/" . $tops[0];
        // 5) Validation minimale avant tout remplacement.
        if (!is_file("$racine/VERSION") || !is_file("$racine/index.php")) {
            throw new RuntimeException('Archive incomplète (VERSION/index.php absents).');
        }
        // 6) Remplacement des fichiers de code.
        maj_copier_arbre($racine, $app);
        $nouvelle = trim((string) @file_get_contents("$app/VERSION")) ?: '?';
        // 7) Canal mémorisé + journal.
        db()->prepare('INSERT OR REPLACE INTO parametres (cle, valeur) VALUES (?, ?)')->execute(['maj_canal', $canal]);
        maj_log("OK canal=$canal $ancienne -> $nouvelle (" . (current_user()['email'] ?? '?') . ')');
        return ['ok' => true, 'ancienne' => $ancienne, 'nouvelle' => $nouvelle, 'canal' => $canal];
    } catch (Throwable $e) {
        maj_log("ECHEC canal=$canal : " . $e->getMessage());
        return ['ok' => false, 'message' => $e->getMessage()];
    } finally {
        @unlink($zip);
        maj_rmdir_recursif($ext);
    }
}

// -------------------------------------------------------------- ROUTE
function route_maj(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        if (isset($_POST['maj_go'])) {
            // Lancement de la mise à jour vers le canal suivi.
            if (!maj_web_active()) {
                $_SESSION['maj_resultat'] = ['ok' => false, 'message' => 'Mise à jour web désactivée (ALLOW_WEB_UPDATE).'];
            } elseif (!maj_archive_possible()) {
                $_SESSION['maj_resultat'] = ['ok' => false, 'message' => 'Mise à jour automatique non supportée par ce serveur.'];
            } else {
                $_SESSION['maj_resultat'] = maj_executer(maj_canal());
            }
            redirect('maj');
        }
        // Sinon : changement de canal (préférence).
        $c = (string) ($_POST['canal'] ?? 'test');
        if (isset(MAJ_CANAUX[$c])) {
            db()->prepare('INSERT OR REPLACE INTO parametres (cle, valeur) VALUES (?, ?)')
                ->execute(['maj_canal', $c]);
        }
        redirect('maj');
    }

    $canal     = maj_canal();
    $locale    = maj_version_locale();
    $distante  = maj_version_distante($canal);
    $shaLocal  = maj_sha_local();
    $shaDist   = maj_sha_distant($canal);

    // État du local vis-à-vis du canal — au niveau commit (précis), avec replis.
    $pos = maj_position($canal);
    if ($pos !== null) {
        $etat = ['identical' => 'a_jour', 'behind' => 'retard', 'ahead' => 'avance', 'diverged' => 'diverge'][$pos] ?? 'inconnu';
    } elseif ($shaLocal !== null && $shaDist !== null) {
        $etat = ($shaLocal === $shaDist) ? 'a_jour' : 'retard';
    } elseif ($distante !== null) {
        $etat = version_compare($distante, $locale, '<') ? 'avance' : (version_compare($distante, $locale, '>') ? 'retard' : 'a_jour');
    } else {
        $etat = 'inconnu';
    }
    // Downgrade : installer le canal ferait reculer (local en avance / divergent, ou version distante antérieure).
    $downgrade = in_array($etat, ['avance', 'diverge'], true)
        || ($distante !== null && version_compare($distante, $locale, '<'));

    $resultat = $_SESSION['maj_resultat'] ?? null;
    unset($_SESSION['maj_resultat']);

    render('maj', [
        'canal'     => $canal,
        'locale'    => $locale,
        'distante'  => $distante,
        'shaLocal'  => $shaLocal,
        'shaDist'   => $shaDist,
        'etat'      => $etat,
        'downgrade' => $downgrade,
        'resultat'  => $resultat,
        'webActive' => maj_web_active(),
        'execDispo'       => maj_exec_dispo(),
        'gitDispo'        => maj_git_dispo(),
        'dlDispo'         => maj_download_dispo(),
        'zipDispo'        => maj_zip_dispo(),
        'targzDispo'      => maj_targz_dispo(),
        'appWritable'     => maj_app_writable(),
        'archivePossible' => maj_archive_possible(),
    ], 'Mises à jour');
}
