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

// Requête HTTP simple (cURL puis repli allow_url_fopen). Renvoie null si échec.
function maj_http_get(string $url): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
            CURLOPT_USERAGENT => 'Lasso-updater', CURLOPT_FOLLOWLOCATION => true,
        ]);
        $r  = curl_exec($ch);
        $ok = $r !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
        curl_close($ch);
        return $ok ? (string) $r : null;
    }
    if (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
        $ctx = stream_context_create(['http' => ['timeout' => 8, 'header' => "User-Agent: Lasso-updater\r\n"]]);
        $r = @file_get_contents($url, false, $ctx);
        return $r === false ? null : (string) $r;
    }
    return null;
}

// Version distante : fichier VERSION de la branche du canal (raw GitHub).
function maj_version_distante(string $canal): ?string
{
    $url = 'https://raw.githubusercontent.com/' . MAJ_REPO . '/' . maj_branche($canal) . '/VERSION';
    $v = maj_http_get($url);
    return $v === null ? null : (trim($v) ?: null);
}

// SHA court du dernier commit distant du canal (API GitHub).
function maj_sha_distant(string $canal): ?string
{
    $json = maj_http_get('https://api.github.com/repos/' . MAJ_REPO . '/commits/' . maj_branche($canal));
    if ($json === null) {
        return null;
    }
    $d = json_decode($json, true);
    return isset($d['sha']) ? substr((string) $d['sha'], 0, 7) : null;
}

// -------------------------------------------------------------- ROUTE
function route_maj(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
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

    // « À jour » : comparaison par SHA si disponible (précis), sinon par version.
    if ($shaLocal !== null && $shaDist !== null) {
        $aJour = ($shaLocal === $shaDist);
    } elseif ($distante !== null) {
        $aJour = version_compare($distante, $locale, '<=');
    } else {
        $aJour = null; // indéterminé (réseau)
    }

    render('maj', [
        'canal'     => $canal,
        'locale'    => $locale,
        'distante'  => $distante,
        'shaLocal'  => $shaLocal,
        'shaDist'   => $shaDist,
        'aJour'     => $aJour,
        'execDispo' => maj_exec_dispo(),
        'gitDispo'  => maj_git_dispo(),
    ], 'Mises à jour');
}
