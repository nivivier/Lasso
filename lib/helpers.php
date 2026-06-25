<?php
// Fonctions utilitaires : session, auth, CSRF, formatage, rendu.

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) == 443)
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => is_https(), // cookie chiffré uniquement en HTTPS
        ]);
        session_start();
    }
}

// En-têtes de sécurité (appelés au tout début de chaque requête).
function send_security_headers(): void
{
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    // HSTS : impose HTTPS au navigateur pendant 1 an (uniquement servi en HTTPS).
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    // CSP minimale : on autorise la police Google et les styles/scripts inline déjà utilisés.
    header(
        "Content-Security-Policy: default-src 'self'; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
        . "font-src 'self' https://fonts.gstatic.com; "
        . "script-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data:; base-uri 'self'; form-action 'self'"
    );
}

function has_users(): bool
{
    return (int) db()->query('SELECT COUNT(*) FROM utilisateurs')->fetchColumn() > 0;
}

function current_user(): ?array
{
    // Mémoïsé : appelé plusieurs fois par requête (require_login, layout, handlers).
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    if (empty($_SESSION['uid'])) {
        return $cache = null;
    }
    $stmt = db()->prepare('SELECT * FROM utilisateurs WHERE id = ?');
    $stmt->execute([$_SESSION['uid']]);
    return $cache = ($stmt->fetch() ?: null);
}

function require_login(): void
{
    if (!current_user()) {
        redirect('login');
    }
    // Expiration : inactivité (SESSION_IDLE) ou durée de vie absolue (SESSION_ABSOLUTE).
    $now = time();
    $idle = isset($_SESSION['last_activity']) && ($now - (int) $_SESSION['last_activity']) > SESSION_IDLE;
    $old  = isset($_SESSION['login_time']) && ($now - (int) $_SESSION['login_time']) > SESSION_ABSOLUTE;
    if ($idle || $old) {
        logout_session();
        redirect('login', ['expired' => 1]);
    }
    $_SESSION['last_activity'] = $now;
}

// Vide et détruit la session courante (déconnexion / expiration).
function logout_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function check_csrf(): void
{
    $ok = isset($_POST['csrf'], $_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], $_POST['csrf']);
    if (!$ok) {
        http_response_code(400);
        exit('Jeton de sécurité invalide. Rechargez la page.');
    }
}

// --- Anti-force-brute du login -------------------------------------------
function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

// Nombre d'échecs récents (fenêtre LOGIN_WINDOW) pour cette IP.
function login_failures_recent(string $ip): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND cree_le > ?');
    $stmt->execute([$ip, time() - LOGIN_WINDOW]);
    return (int) $stmt->fetchColumn();
}

// Vrai si l'IP a dépassé le quota d'échecs et reste bloquée.
function login_is_locked(string $ip): bool
{
    return login_failures_recent($ip) >= LOGIN_MAX_ATTEMPTS;
}

function login_record_failure(string $ip, string $email): void
{
    db()->prepare('INSERT INTO login_attempts (ip, email, cree_le) VALUES (?, ?, ?)')
        ->execute([$ip, $email, time()]);
    // Purge opportuniste des entrées anciennes.
    db()->prepare('DELETE FROM login_attempts WHERE cree_le < ?')->execute([time() - 3600]);
}

function login_clear_failures(string $ip): void
{
    db()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
}

function redirect(string $route, array $params = []): void
{
    $url = '?p=' . urlencode($route);
    foreach ($params as $k => $v) {
        $url .= '&' . urlencode($k) . '=' . urlencode((string) $v);
    }
    header('Location: ' . $url);
    exit;
}

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

// Montant CHF : "1 234.55"
function chf(float $v): string
{
    return number_format($v, 2, '.', "\u{202F}");
}

// Pourcentage lisible : 0.053 -> "5.3 %"
function pct(float $v): string
{
    return rtrim(rtrim(number_format($v * 100, 4, '.', ''), '0'), '.') . ' %';
}

const MOIS_FR = [
    1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
    5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
    9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre',
];

function mois_nom(int $m): string
{
    return MOIS_FR[$m] ?? (string) $m;
}

// Chemin web du logo employeur ('clair' fond clair, 'sombre' fond sombre) ou '' si non défini.
function param_logo(string $variant): string
{
    $cle = $variant === 'sombre' ? 'employeur_logo_sombre' : 'employeur_logo_clair';
    return (string) param($cle, '');
}

// Traite l'upload d'un logo. Renvoie le chemin web relatif (uploads/…) si un
// fichier valide a été envoyé, null si aucun fichier, ou lève RuntimeException.
function handle_logo_upload(string $field): ?string
{
    $f = $_FILES[$field] ?? null;
    if ($f === null || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null; // aucun fichier → inchangé
    }
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException("Échec de l'envoi du fichier (code {$f['error']}).");
    }
    if ($f['size'] > 2 * 1024 * 1024) {
        throw new RuntimeException('Image trop lourde (2 Mo maximum).');
    }
    $info = @getimagesize($f['tmp_name']); // valide que c'est une vraie image
    $exts = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
    if ($info === false || !isset($exts[$info[2]])) {
        throw new RuntimeException('Format non supporté (PNG, JPG, GIF ou WebP).');
    }
    $dir = __DIR__ . '/../uploads';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $name = $field . '_' . bin2hex(random_bytes(6)) . '.' . $exts[$info[2]];
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException("Impossible d'enregistrer le fichier.");
    }
    @chmod($dir . '/' . $name, 0644);
    return 'uploads/' . $name;
}

// Sépare « 1213 Lancy » en ['1213', 'Lancy'] (NPA + localité).
function split_npa(string $s): array
{
    $s = trim($s);
    if (preg_match('/^\s*(\d{4})\s+(.+)$/', $s, $m)) {
        return [$m[1], trim($m[2])];
    }
    return ['', $s];
}

// Numéro AVS suisse : 756.XXXX.XXXX.XX (13 chiffres, préfixe 756).
function avs_valide(string $avs): bool
{
    return (bool) preg_match('/^756\.\d{4}\.\d{4}\.\d{2}$/', trim($avs));
}

// Lien « retour » discret, à placer au-dessus du titre de page.
function lien_retour(string $href, string $label): string
{
    return '<a class="back-link" href="' . e($href) . '">' . icon('arrow-left') . ' ' . e($label) . '</a>';
}

// Génère le HTML autonome d'une fiche pour un envoi par e-mail (CSS embarqué).
function fiche_email_html(array $f): string
{
    $scheme   = is_https() ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $logoRel  = param_logo('clair');
    // URL absolue (les clients mail n'affichent pas les chemins relatifs) ; '' si aucun logo.
    $logo_src = $logoRel !== '' ? $scheme . '://' . $host . '/' . ltrim($logoRel, '/') : '';
    $impression = true; // pas de lien sur le nom dans l'e-mail
    $css = @file_get_contents(__DIR__ . '/../assets/app.css') ?: '';

    ob_start();
    require __DIR__ . '/../views/_fiche_body.php';
    $corps = ob_get_clean();

    return '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<style>' . $css . ' body{background:#fff;margin:0;padding:18px}</style></head>'
        . '<body>' . $corps . '</body></html>';
}

// Envoie une fiche par e-mail. En local, journalise au lieu d'envoyer.
// Retourne [bool succès, string mode ('local'|'mail')].
function envoyer_fiche_email(array $f, string $destinataire, string $expediteur): array
{
    $sujet = 'Fiche de salaire — ' . mois_nom((int) $f['mois']) . ' ' . $f['annee'];
    $html  = fiche_email_html($f);
    $entetes = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $expediteur,
        'Reply-To: ' . $expediteur,
    ]);
    $sujetEnc = '=?UTF-8?B?' . base64_encode($sujet) . '?=';

    if (APP_ENV === 'dev') {
        $log = dirname(APP_DB_PATH) . '/emails_envoyes.log';
        @file_put_contents($log, '[' . date('c') . "] To: $destinataire | De: $expediteur | $sujet\n", FILE_APPEND);
        return [true, 'local'];
    }

    // En prod : SMTP authentifié si configuré (beaucoup d'hébergeurs désactivent mail()),
    // sinon repli sur mail() pour les hébergeurs qui l'autorisent.
    $cfg = smtp_config();
    if ($cfg['user'] !== '') {
        $ok = smtp_envoyer($cfg, $destinataire, $sujetEnc, $html, $entetes);
        return [$ok, 'smtp'];
    }
    if (!function_exists('mail')) {
        error_log('[app] mail() indisponible et SMTP non configuré (écran Employeur ou config.local.php).');
        return [false, 'mail'];
    }
    $ok = @mail($destinataire, $sujetEnc, $html, $entetes);
    return [(bool) $ok, 'mail'];
}

// Réglages SMTP effectifs : priorité à la base (écran Employeur), repli sur les
// constantes de lib/config.local.php pour chaque champ laissé vide.
function smtp_config(): array
{
    $val = function (string $cle, string $defaut): string {
        $v = (string) param($cle, '');
        return $v !== '' ? $v : $defaut;
    };
    return [
        'host'   => $val('smtp_host', SMTP_HOST),
        'port'   => (int) $val('smtp_port', (string) SMTP_PORT),
        'secure' => $val('smtp_secure', SMTP_SECURE) === 'tls' ? 'tls' : 'ssl',
        'user'   => $val('smtp_user', SMTP_USER),
        'pass'   => $val('smtp_pass', SMTP_PASS),
    ];
}

// Envoi d'un e-mail par SMTP authentifié, en PHP pur (aucune dépendance).
// Gère SSL implicite (port 465) ou STARTTLS (port 587), AUTH LOGIN.
// Retourne true si le serveur a accepté le message, false sinon (cause journalisée).
function smtp_envoyer(array $cfg, string $to, string $sujetEnc, string $html, string $entetes): bool
{
    $echec = function (string $msg): bool {
        error_log('[app] SMTP : ' . $msg);
        return false;
    };

    $transport = $cfg['secure'] === 'ssl' ? 'ssl://' : 'tcp://';
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $fp = @stream_socket_client(
        $transport . $cfg['host'] . ':' . $cfg['port'],
        $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx
    );
    if (!$fp) {
        return $echec("connexion impossible à {$cfg['host']}:{$cfg['port']} ($errno $errstr)");
    }
    stream_set_timeout($fp, 20);

    // Lit une réponse SMTP (gère les réponses multi-lignes « 250-… »).
    $lire = function () use ($fp): string {
        $data = '';
        while (($ligne = fgets($fp, 515)) !== false) {
            $data .= $ligne;
            if (strlen($ligne) >= 4 && $ligne[3] === ' ') break; // dernière ligne
        }
        return $data;
    };
    // Envoie une commande et vérifie le code de réponse attendu.
    $cmd = function (string $envoi, string $codeAttendu) use ($fp, $lire, $echec) {
        if ($envoi !== '') {
            fwrite($fp, $envoi . "\r\n");
        }
        $rep = $lire();
        if (substr($rep, 0, 3) !== $codeAttendu) {
            return $echec("réponse inattendue (attendu $codeAttendu) : " . trim($rep));
        }
        return true; // succès
    };

    $hello = ($_SERVER['SERVER_NAME'] ?? null) ?: 'localhost';

    if ($cmd('', '220') !== true) return false;                       // bannière
    if ($cmd('EHLO ' . $hello, '250') !== true) return false;

    if ($cfg['secure'] === 'tls') {
        if ($cmd('STARTTLS', '220') !== true) return false;
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            return $echec('échec du passage en TLS (STARTTLS)');
        }
        if ($cmd('EHLO ' . $hello, '250') !== true) return false;     // re-EHLO après TLS
    }

    if ($cmd('AUTH LOGIN', '334') !== true) return false;
    if ($cmd(base64_encode($cfg['user']), '334') !== true) return false;
    if ($cmd(base64_encode($cfg['pass']), '235') !== true) return false; // 235 = authentifié

    // Enveloppe : MAIL FROM = compte authentifié (souvent exigé par l'hébergeur / SPF).
    if ($cmd('MAIL FROM:<' . $cfg['user'] . '>', '250') !== true) return false;
    if ($cmd('RCPT TO:<' . $to . '>', '250') !== true) return false;
    if ($cmd('DATA', '354') !== true) return false;

    // En-têtes To/Subject (le From est déjà dans $entetes) puis corps.
    $message = 'To: ' . $to . "\r\n"
        . 'Subject: ' . $sujetEnc . "\r\n"
        . $entetes . "\r\n\r\n"
        . $html;
    // Point-stuffing : une ligne « . » seule terminerait prématurément les données.
    $message = preg_replace('/^\./m', '..', $message);
    fwrite($fp, $message . "\r\n.\r\n");
    if ($cmd('', '250') !== true) return false;                       // message accepté

    fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return true;
}

// Badge de statut de paiement d'une fiche (payé le … / à payer).
function badge_paiement(array $f): string
{
    $date = trim((string) ($f['date_paiement'] ?? ''));
    if ($date !== '') {
        return '<span class="badge ok-badge">Payé le ' . e(date('d.m.Y', strtotime($date))) . '</span>';
    }
    $annee = (int) ($f['annee'] ?? 0);
    $mois  = (int) ($f['mois'] ?? 0);
    $cy = (int) date('Y');
    $cm = (int) date('n');
    if ($annee > $cy || ($annee === $cy && $mois > $cm)) {
        return '<span class="badge muted-badge">À venir</span>';
    }
    return '<span class="badge warn-badge">À payer</span>';
}

function fiche_a_venir(array $f): bool
{
    if (trim((string) ($f['date_paiement'] ?? '')) !== '') return false;
    $cy = (int) date('Y');
    $cm = (int) date('n');
    $annee = (int) ($f['annee'] ?? 0);
    $mois  = (int) ($f['mois'] ?? 0);
    return $annee > $cy || ($annee === $cy && $mois > $cm);
}

// Coût employeur d'une fiche pour les listes : « — » si aucune charge patronale figée
// (typiquement les anciennes fiches importées avant le calcul des charges).
function cout_emp_affiche(array $f): string
{
    return ((float) ($f['total_charges_emp'] ?? 0)) > 0
        ? chf((float) ($f['cout_total_emp'] ?? 0))
        : '—';
}

// Lignes de prestation d'une fiche. Repli pour les fiches d'avant les unités.
function fiche_lignes_de(array $f): array
{
    $stmt = db()->prepare('SELECT * FROM fiche_lignes WHERE fiche_id = ? ORDER BY ordre, id');
    $stmt->execute([$f['id']]);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        $rows = [[
            'libelle'      => 'Heures',
            'heures_unite' => 1.0,
            'quantite'     => (float) $f['nombre_heures'],
            'taux_horaire' => (float) $f['salaire_horaire'],
        ]];
    }
    return $rows;
}

// Icônes Lucide (https://lucide.dev, licence ISC). SVG en ligne, sans requête externe.
function icon(string $name): string
{
    $paths = [
        'file-text' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/>',
        'bar-chart' => '<path d="M3 3v18h18"/><rect x="7" y="10" width="3" height="8" rx="1"/><rect x="12" y="6" width="3" height="12" rx="1"/><rect x="17" y="13" width="3" height="5" rx="1"/>',
        'layers'    => '<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>',
        'users'     => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'settings'  => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
        'menu'      => '<line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/>',
        'x'         => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'building'  => '<rect width="16" height="20" x="4" y="2" rx="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M12 6h.01"/><path d="M12 10h.01"/><path d="M12 14h.01"/><path d="M16 10h.01"/><path d="M16 14h.01"/><path d="M8 10h.01"/><path d="M8 14h.01"/>',
        'percent'   => '<line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
        'printer'   => '<polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>',
        'eye'       => '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
        'arrow-left' => '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
        'pencil'    => '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
        'trash'     => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/>',
        'download'  => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
        'banknote'  => '<rect width="20" height="12" x="2" y="6" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01"/><path d="M18 12h.01"/>',
        'clock'     => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'chevron'   => '<polyline points="6 9 12 15 18 9"/>',
        'mail'      => '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
        'check'     => '<polyline points="20 6 9 17 4 12"/>',
        'save'      => '<path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/>',
        'user-plus' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/>',
        'file-plus' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" x2="12" y1="18" y2="12"/><line x1="9" x2="15" y1="15" y2="15"/>',
        'upload'    => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/>',
        'tag'       => '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/>',
        'chevron-up'   => '<path d="m18 15-6-6-6 6"/>',
        'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
        'plus'      => '<path d="M5 12h14"/><path d="M12 5v14"/>',
        'search'    => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'merge'     => '<path d="m8 6 4-4 4 4"/><path d="M12 2v10.3a4 4 0 0 1-1.172 2.872L4 22"/><path d="m20 22-5-5"/>',
        'archive'   => '<rect width="20" height="5" x="2" y="3" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/>',
        'grip'      => '<circle cx="9" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="18" r="1"/>',
        'book-open' => '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
    ];
    $p = $paths[$name] ?? '';
    return '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
        . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

// Nombre de fiches non payées (date_paiement vide) du mois courant ou avant.
function nb_fiches_a_payer(): int
{
    try {
        $m = (int) date('m');
        $y = (int) date('Y');
        $s = db()->prepare("SELECT COUNT(*) FROM fiches WHERE date_paiement = '' AND (annee < ? OR (annee = ? AND mois <= ?))");
        $s->execute([$y, $y, $m]);
        return (int) $s->fetchColumn();
    } catch (\Exception) {
        return 0;
    }
}

// Nombre d'écritures comptables non lettrées.
function nb_ecritures_a_lettrer(): int
{
    try {
        return (int) db()->query("SELECT COUNT(*) FROM ecritures WHERE plan_compte_id IS NULL AND origine_lettrage <> 'ignore'")->fetchColumn();
    } catch (\Exception) {
        return 0;
    }
}

function render(string $view, array $data = [], ?string $title = null): void
{
    extract($data);
    $contentView = __DIR__ . '/../views/' . $view . '.php';
    $pageTitle   = $title ?? 'Fiches de salaire';
    require __DIR__ . '/../views/layout.php';
}

// Rendu d'une vue "nue" (sans layout), pour l'impression.
function render_bare(string $view, array $data = []): void
{
    extract($data);
    require __DIR__ . '/../views/' . $view . '.php';
}
