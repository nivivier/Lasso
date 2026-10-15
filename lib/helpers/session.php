<?php
// Session, authentification et en-têtes de sécurité — extrait de
// lib/helpers.php sans rien changer à son comportement.
//
// Ce qui tient ensemble ici : ouvrir une session et la fermer, savoir QUI
// demande (current_user(), require_login()), prouver qu'une requête vient bien
// de l'application (csrf_token()/check_csrf()), décourager la force brute
// (login_*), et le mot de passe oublié (reinit_*). Les en-têtes de sécurité et
// le nonce de la CSP s'y rattachent : c'est la même question — ce qu'on laisse
// entrer et ce qu'on laisse exécuter.
//
// ⚠️ Chargé par lib/helpers.php, jamais requis directement : helpers.php reste
// la porte unique, comme lib/db.php l'est pour son schéma et ses migrations.

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) == 443)
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

// Durée d'inactivité tolérée avant déconnexion, et durée de vie maximale d'une
// session quoi qu'il arrive. Réglables dans l'application (Serveur → Session),
// bornées à la lecture COMME à l'écriture : une valeur arrivée par un autre
// chemin — import, édition directe de la table — resterait sans effet.
function session_duree_idle(): int
{
    return max(SESSION_IDLE_MIN, min(SESSION_IDLE_MAX, (int) param('session_idle', (string) SESSION_IDLE)));
}

function session_duree_absolue(): int
{
    return max(SESSION_ABSOLUTE_MIN, min(SESSION_ABSOLUTE_MAX, (int) param('session_absolue', (string) SESSION_ABSOLUTE)));
}

// Dossier des fichiers de session, SOUS data/ et non dans le /tmp du serveur.
//
// C'est la correction d'un travers propre à l'hébergement mutualisé : sans
// save_path à nous, PHP écrit dans un dossier temporaire PARTAGÉ par tous les
// sites de la machine, et le ramasse-miettes de n'importe lequel d'entre eux —
// avec SA durée à lui, souvent les 24 minutes par défaut — efface nos fichiers
// de session. L'application croyait accorder une heure d'inactivité et
// déconnectait au bout de vingt minutes, plusieurs fois par jour.
//
// data/ est déjà refusé par le serveur web (data/.htaccess + RedirectMatch à la
// racine) : un identifiant de session ne s'y télécharge pas.
function session_dossier(): ?string
{
    // ⚠️ Deux niveaux : ce fichier vit dans lib/helpers/, pas dans lib/.
    // Avec un seul, les sessions atterrissaient dans lib/data/sessions/ —
    // hors du dossier protégé, et invisible jusqu'à ce qu'on le remarque.
    $dir = __DIR__ . '/../../data/sessions';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return (is_dir($dir) && is_writable($dir)) ? $dir : null;
}

function start_session(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    // gc_maxlifetime AVANT session_start() : c'est lui qui décide quand PHP
    // considère un fichier de session comme un déchet. Réglé sur notre propre
    // durée d'inactivité, sinon PHP ramasse ce que l'application tient encore
    // pour valide. La probabilité est relevée (1/100 au lieu de 1/1000) : dans
    // un dossier qui n'est qu'à nous, le ramassage ne dépend plus que de NOS
    // requêtes, et elles sont rares.
    $dossier = session_dossier();
    if ($dossier !== null) {
        ini_set('session.gc_maxlifetime', (string) session_duree_idle());
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
        session_save_path($dossier);
    }
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => is_https(), // cookie chiffré uniquement en HTTPS
    ]);
    session_start();
}

// En-têtes de sécurité (appelés au tout début de chaque requête).
// Nonce de la politique de sécurité de contenu : une valeur aléatoire par
// requête, reprise sur chaque balise <script> inline et dans l'en-tête CSP.
// C'est ce qui permet de retirer 'unsafe-inline' de script-src : le navigateur
// n'exécute un script inline que si son nonce correspond, donc un script
// injecté par une faille XSS — qui ne peut pas deviner la valeur — est refusé.
//
// Mémoïsé : send_security_headers() et les vues doivent voir la MÊME valeur.
function csp_nonce(): string
{
    static $nonce = null;
    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(16));
    }
    return $nonce;
}

function send_security_headers(): void
{
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    // strict-origin-when-cross-origin (et non same-origin) : les tuiles de la
    // carte des lieux (tile.openstreetmap.org) exigent un Referer identifiant
    // le site appelant — same-origin le supprimait entièrement en cross-origin,
    // ce qui fait échouer leur politique d'usage. Cette politique n'envoie que
    // l'origine (jamais le chemin/la query) en cross-origin, HTTPS→HTTPS.
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // HSTS : impose HTTPS au navigateur pendant 1 an (uniquement servi en HTTPS).
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    // CSP. Plus aucun domaine Google : Inter est servie depuis assets/fonts/
    // (voir le @font-face en tête de assets/app.css). Restent les tuiles
    // OpenStreetMap (vue carte des lieux, lib/geocodage.php).
    //
    // script-src n'accepte plus 'unsafe-inline' : chaque <script> inline porte le
    // nonce de la requête (csp_nonce()), et le navigateur ignore 'unsafe-inline'
    // dès qu'un nonce est présent. Ce passage supposait de supprimer d'abord les
    // 85 attributs de gestionnaire (onclick/onsubmit/onchange) que les vues
    // portaient : un nonce ne couvre QUE les balises <script>, jamais ces
    // attributs, qui auraient donc tous cessé de fonctionner. Ils vivent
    // désormais en écouteurs délégués (data-confirm, data-print… dans
    // assets/app.js).
    //
    // style-src garde 'unsafe-inline' : les attributs style= restent utilisés
    // (couleur d'accent d'un module, largeurs de barres), et un nonce ne
    // s'applique pas davantage aux attributs de style. Le risque est sans commune
    // mesure avec celui d'un script.
    //
    // Les autres directives :
    //   object-src 'none'      — plus de <object>/<embed>, vecteur classique ;
    //   frame-ancestors 'self' — équivalent moderne de X-Frame-Options, qui reste
    //                            envoyé plus haut pour les navigateurs anciens.
    header(
        "Content-Security-Policy: default-src 'self'; "
        . "style-src 'self' 'unsafe-inline'; "
        . "font-src 'self'; "
        . "script-src 'self' 'nonce-" . csp_nonce() . "'; "
        . "object-src 'none'; "
        . "frame-ancestors 'self'; "
        . "img-src 'self' data: https://tile.openstreetmap.org; base-uri 'self'; form-action 'self'"
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
        redirect('connexion');
    }
    // Expiration : inactivité (SESSION_IDLE) ou durée de vie absolue (SESSION_ABSOLUTE).
    $now = time();
    $idle = isset($_SESSION['last_activity']) && ($now - (int) $_SESSION['last_activity']) > session_duree_idle();
    $old  = isset($_SESSION['login_time']) && ($now - (int) $_SESSION['login_time']) > session_duree_absolue();
    if ($idle || $old) {
        logout_session();
        redirect('connexion', ['expired' => 1]);
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

// --- Mots de passe --------------------------------------------------------
// PASSWORD_BCRYPT explicite, et non PASSWORD_DEFAULT : l'option 'cost' n'a de
// sens que pour bcrypt. Le jour où PHP fera pointer PASSWORD_DEFAULT vers un
// Argon2, 'cost' deviendrait silencieusement inopérant et les nouveaux mots de
// passe seraient hachés avec les paramètres par défaut d'un autre algorithme,
// sans que rien ne le signale. Point d'entrée unique : les quatre endroits qui
// créaient un hash (installation, changement par l'utilisateur, création de
// compte, réinitialisation) répétaient le même triplet d'arguments.
function hacher_mot_de_passe(string $mdp): string
{
    return password_hash($mdp, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
}

// Réhachage opportuniste après une authentification réussie : si BCRYPT_COST a
// été relevé (ou l'algorithme changé) depuis la création du hash, on en profite
// pour le mettre à niveau — c'est le seul moment où le mot de passe en clair est
// disponible. Sans ça, une empreinte créée avec un coût faible le reste à vie.
function rehacher_si_necessaire(int $uid, string $mdpEnClair, string $hashActuel): void
{
    if (password_needs_rehash($hashActuel, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST])) {
        db()->prepare('UPDATE utilisateurs SET mot_de_passe = ? WHERE id = ?')
            ->execute([hacher_mot_de_passe($mdpEnClair), $uid]);
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

// Nombre d'échecs récents (même fenêtre) visant CE compte, toutes IP confondues.
function login_failures_recent_email(string $email): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND cree_le > ?');
    $stmt->execute([$email, time() - LOGIN_WINDOW]);
    return (int) $stmt->fetchColumn();
}

// Vrai si le quota d'échecs est dépassé, côté IP ou côté compte. Le compteur par
// compte comble l'angle mort du comptage par IP seule : un attaquant qui fait
// tourner ses adresses n'était jamais ralenti, quel que soit le nombre d'essais
// sur une même adresse e-mail. $email vide (formulaire soumis sans identifiant)
// n'active que le volet IP — sinon tous les comptes se bloqueraient mutuellement
// via la ligne « email = '' ».
function login_is_locked(string $ip, string $email = ''): bool
{
    if (login_failures_recent($ip) >= LOGIN_MAX_ATTEMPTS) {
        return true;
    }
    return $email !== '' && login_failures_recent_email($email) >= LOGIN_MAX_ATTEMPTS;
}

function login_record_failure(string $ip, string $email): void
{
    db()->prepare('INSERT INTO login_attempts (ip, email, cree_le) VALUES (?, ?, ?)')
        ->execute([$ip, $email, time()]);
    // Purge opportuniste des entrées anciennes.
    db()->prepare('DELETE FROM login_attempts WHERE cree_le < ?')->execute([time() - 3600]);
}

// Purge les échecs après une authentification réussie, pour l'IP et pour le
// compte concerné (voir login_is_locked() : les deux compteurs bloquent, les
// deux doivent donc être remis à zéro). $email vide ne purge que l'IP.
function login_clear_failures(string $ip, string $email = ''): void
{
    db()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
    if ($email !== '') {
        db()->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([$email]);
    }
}

// --- Mot de passe oublié -----------------------------------------------------
//
// Le jeton part en clair dans l'e-mail et n'existe QUE là : la base n'en garde
// que l'empreinte, comme pour un mot de passe. Il vaut une heure (RESET_TTL),
// ne sert qu'une fois, et toute demande annule les jetons précédents du compte.

function reinit_hash(string $jeton): string
{
    return hash('sha256', $jeton);
}

// Crée un jeton pour ce compte et renvoie sa valeur en clair (à ne jamais
// journaliser ni afficher : elle ne doit vivre que dans l'e-mail).
function reinit_creer_jeton(int $utilisateurId, string $ip): string
{
    $jeton = bin2hex(random_bytes(32));
    db()->prepare('DELETE FROM reinit_motdepasse WHERE utilisateur_id = ? AND utilise_le IS NULL')
        ->execute([$utilisateurId]);
    db()->prepare('INSERT INTO reinit_motdepasse (utilisateur_id, jeton_hash, ip, expire_le, cree_le)
                   VALUES (?, ?, ?, ?, ?)')
        ->execute([$utilisateurId, reinit_hash($jeton), $ip, time() + RESET_TTL, time()]);
    // Purge opportuniste : les jetons périmés depuis un jour n'ont plus d'usage.
    db()->prepare('DELETE FROM reinit_motdepasse WHERE expire_le < ?')->execute([time() - 86400]);
    return $jeton;
}

// Demande valide correspondant à ce jeton, ou null. Une seule requête décide :
// jamais expirée, jamais déjà utilisée.
function reinit_demande(string $jeton): ?array
{
    if ($jeton === '') {
        return null;
    }
    $stmt = db()->prepare(
        'SELECT r.*, u.email FROM reinit_motdepasse r
           JOIN utilisateurs u ON u.id = r.utilisateur_id
          WHERE r.jeton_hash = ? AND r.utilise_le IS NULL AND r.expire_le > ?'
    );
    $stmt->execute([reinit_hash($jeton), time()]);
    return $stmt->fetch() ?: null;
}

// Trop de demandes récentes ? On compte par IP et par compte visé : sans le
// second volet, on pourrait noyer une boîte aux lettres depuis plusieurs IP.
// Ce comptage n'a rien à voir avec celui des échecs de connexion — il ne doit
// SURTOUT pas bloquer la connexion du compte visé, sinon demander une
// réinitialisation deviendrait une manière d'enfermer quelqu'un dehors.
function reinit_trop_de_demandes(string $ip, int $utilisateurId): bool
{
    $depuis = time() - 3600;
    $parIp = db()->prepare('SELECT COUNT(*) FROM reinit_motdepasse WHERE ip = ? AND cree_le > ?');
    $parIp->execute([$ip, $depuis]);
    if ((int) $parIp->fetchColumn() >= RESET_MAX_PAR_HEURE) {
        return true;
    }
    $parCompte = db()->prepare('SELECT COUNT(*) FROM reinit_motdepasse WHERE utilisateur_id = ? AND cree_le > ?');
    $parCompte->execute([$utilisateurId, $depuis]);
    return (int) $parCompte->fetchColumn() >= RESET_MAX_PAR_HEURE;
}

// Adresse publique du site. APP_URL fait foi quand elle est définie ; sinon on
// retombe sur l'en-tête Host — pratique en local, mais il vient du client (voir
// le commentaire d'APP_URL dans lib/config.php).
function url_site(): string
{
    if (APP_URL !== '') {
        return rtrim(APP_URL, '/');
    }
    $scheme = is_https() ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

// Envoie le lien de réinitialisation. Retourne [succès, mode], comme les autres
// envois applicatifs.
function envoyer_email_reinit(string $destinataire, string $lien): array
{
    $expediteur = trim((string) param('employeur_email_expediteur'));
    if (!filter_var($expediteur, FILTER_VALIDATE_EMAIL)) {
        error_log('[app] Réinitialisation : aucune adresse d\'expédition configurée (Paramètres → E-mails).');
        return [false, 'config'];
    }
    $minutes = (int) round(RESET_TTL / 60);
    $sujet = 'Réinitialisation de votre mot de passe';
    $html = '<!doctype html><html lang="fr"><head><meta charset="utf-8"></head><body>'
        . '<p>Bonjour,</p>'
        . '<p>Une réinitialisation du mot de passe a été demandée pour ce compte sur '
        . e(param('employeur_nom', 'l\'application')) . '.</p>'
        . '<p><a href="' . e($lien) . '">Choisir un nouveau mot de passe</a></p>'
        . '<p>Ce lien est valable ' . $minutes . ' minutes et ne fonctionne qu\'une fois.</p>'
        . '<p>Si vous n\'êtes pas à l\'origine de cette demande, ignorez ce message : '
        . 'votre mot de passe actuel reste valable.</p>'
        . '<p style="color:#666;font-size:12px">' . e($lien) . '</p>'
        . '</body></html>';
    $entetes = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $expediteur,
        'Reply-To: ' . email_repondre_a($expediteur),
    ]);
    // Le résumé journalisé en dev ne contient pas le lien : le jeton n'a rien à
    // faire dans un fichier de log.
    return envoyer_email($destinataire, $expediteur, '=?UTF-8?B?' . base64_encode($sujet) . '?=',
        $entetes, $html, $sujet);
}
