<?php
// Fonctions utilitaires : session, auth, CSRF, formatage, rendu.

// Validation stricte d'une date « Y-m-d » : DateTime::createFromFormat() seul
// accepterait silencieusement une date invalide comme "2026-02-30" en la
// « roulant » au 2 mars — checkdate() la rejette explicitement. Partagée entre
// lib/evenements.php (dates d'événement) et lib/compta.php (dates camt.053).
function date_valide(string $s): bool
{
    return (bool) preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)
        && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

// Crée un élément DOM namespacé avec texte optionnel — factorise le patron
// répété par les deux générateurs XML du dépôt (build_certificat_xml() pour
// l'eCS CSI, compta_generer_camt053() pour le relevé bancaire). $prefix est
// préfixé devant $name si fourni (ex. 'sd' → <sd:Nom>), sinon l'élément est
// créé dans le namespace par défaut (sans préfixe).
function dom_el(DOMDocument $doc, string $ns, string $name, ?string $text = null, ?string $prefix = null): DOMElement
{
    $qname = $prefix !== null ? $prefix . ':' . $name : $name;
    $n = $doc->createElementNS($ns, $qname);
    if ($text !== null && $text !== '') {
        $n->appendChild($doc->createTextNode($text));
    }
    return $n;
}

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
    // Propage ?depuis=type:id (lien de retour contextuel, voir lien_retour_contextuel())
    // s'il était présent sur la requête courante et que l'appelant ne l'a pas déjà
    // explicitement fourni — pour qu'il survive à un POST-puis-redirection vers la
    // même page (ex. enregistrer la carte « Informations » d'un événement).
    if (!isset($params['depuis']) && ($_GET['depuis'] ?? '') !== '') {
        $params['depuis'] = $_GET['depuis'];
    }
    $url = '?p=' . urlencode($route);
    foreach ($params as $k => $v) {
        $url .= '&' . urlencode($k) . '=' . urlencode((string) $v);
    }
    header('Location: ' . $url);
    exit;
}

// Supprime la ligne $id de $table, sauf si des lignes de $tableRef y font
// encore référence via $colonneRef (ex. un employé qui a des fiches, un
// débiteur qui a des factures) — la suppression est alors refusée. Noms de
// tables/colonnes toujours des constantes internes, jamais une valeur utilisateur.
// Retourne true si la suppression a eu lieu, false si elle a été refusée.
function supprimer_si_non_reference(string $table, int $id, string $tableRef, string $colonneRef): bool
{
    $stmt = db()->prepare("SELECT COUNT(*) FROM $tableRef WHERE $colonneRef = ?");
    $stmt->execute([$id]);
    if ((int) $stmt->fetchColumn() > 0) {
        return false;
    }
    db()->prepare("DELETE FROM $table WHERE id = ?")->execute([$id]);
    return true;
}

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

// Filtre persistant entre requêtes (listes avec filtres : fiches, écritures,
// factures…) : priorité au paramètre GET (et mémorisé en session pour les
// navigations suivantes, ex. retour depuis une fiche), sinon dernière valeur
// en session, sinon défaut. $cleSession est généralement préfixée par l'écran
// (« fiches_annee », « ecr_annee »…) pour ne pas mélanger les filtres entre
// pages qui partagent un même nom de paramètre GET (ex. « annee »).
function filtre_persistant(string $cleGet, string $cleSession, $defaut)
{
    if (isset($_GET[$cleGet])) {
        $_SESSION[$cleSession] = $_GET[$cleGet];
    }
    return $_SESSION[$cleSession] ?? $defaut;
}

// Montant CHF : "1 234.55"
function chf(float $v): string
{
    return number_format($v, 2, '.', "\u{202F}");
}

// Nombre sans zéros ni point superflus : 3.50 -> "3.5", 4.00 -> "4".
// Utilisé pour les quantités/heures/taux affichés (formulaires, PDF, pct()).
function nombre_court(float $v, int $decimales = 2): string
{
    return rtrim(rtrim(number_format($v, $decimales, '.', ''), '0'), '.');
}

// Pourcentage lisible : 0.053 -> "5.3 %"
function pct(float $v): string
{
    return nombre_court($v * 100, 4) . ' %';
}

// --- Couleurs : dérive la palette de l'appli depuis la couleur principale
// choisie (Paramètres > Employeur) — un seul réglage, tout le reste suit. ---

// Hex (#rrggbb, # optionnel) → [teinte 0-360, saturation 0-100, luminosité 0-100].
// Hex invalide → repli sur la couleur principale par défaut de l'appli.
function hex_vers_hsl(string $hex): array
{
    $hex = ltrim($hex, '#');
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
        $hex = '6d4ade';
    }
    $r = hexdec(substr($hex, 0, 2)) / 255;
    $g = hexdec(substr($hex, 2, 2)) / 255;
    $b = hexdec(substr($hex, 4, 2)) / 255;
    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $l = ($max + $min) / 2;
    if ($max === $min) {
        return [0.0, 0.0, round($l * 100, 1)];
    }
    $d = $max - $min;
    $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
    $h = match ($max) {
        $r      => fmod(($g - $b) / $d, 6),
        $g      => ($b - $r) / $d + 2,
        default => ($r - $g) / $d + 4,
    };
    $h *= 60;
    if ($h < 0) {
        $h += 360;
    }
    return [round($h, 1), round($s * 100, 1), round($l * 100, 1)];
}

// [teinte 0-360, saturation 0-100, luminosité 0-100] → hex (#rrggbb).
function hsl_vers_hex(float $h, float $s, float $l): string
{
    $s = max(0.0, min(100.0, $s)) / 100;
    $l = max(0.0, min(100.0, $l)) / 100;
    $h = fmod($h, 360);
    if ($h < 0) {
        $h += 360;
    }
    $c = (1 - abs(2 * $l - 1)) * $s;
    $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
    $m = $l - $c / 2;
    [$r, $g, $b] = match (true) {
        $h < 60  => [$c, $x, 0],
        $h < 120 => [$x, $c, 0],
        $h < 180 => [0, $c, $x],
        $h < 240 => [0, $x, $c],
        $h < 300 => [$x, 0, $c],
        default  => [$c, 0, $x],
    };
    return sprintf('#%02x%02x%02x', round(($r + $m) * 255), round(($g + $m) * 255), round(($b + $m) * 255));
}

// Teintes dérivées de la couleur principale : boutons (primary/primary-d),
// fond teinté (primary-tint), et teintes sombres pour les titres et la barre
// latérale (brand/brand-2). Voir couleurs_css_vars() pour l'injection en CSS.
function couleurs_derivees(string $hexPrincipale): array
{
    [$h, $s, $l] = hex_vers_hsl($hexPrincipale);
    $primary = hsl_vers_hex($h, $s, $l);
    $rgb     = sscanf($primary, '#%02x%02x%02x');
    
    // Si la couleur principale est trop claire, on l'assombrit légèrement
if ($l > 75) {
    $l = max(75, $l - (($l - 75) * 0.75));
    $primary = hsl_vers_hex($h, $s, $l);

}
    
    return [
        'primary'      => $primary,
        'primary_d'    => hsl_vers_hex($h, $s, max($l - 12, 15)),
        'primary_tint' => hsl_vers_hex($h, min($s + 10, 90), 95),
        'primary_rgb'  => implode(' ', $rgb),
        'brand'        => hsl_vers_hex($h, min($s + 10, 78), 20),
        'brand_2'      => hsl_vers_hex($h, min($s + 8, 78), 35),
    ];
}

// Bloc <style> qui redéfinit les variables CSS de couleur d'après la couleur
// principale choisie — injecté dans <head> (views/layout.php), après app.css.
function couleurs_css_vars(): string
{
    $c = couleurs_derivees((string) param('employeur_couleur_principale', '#6d4ade'));
    return '<style>:root{--primary:' . $c['primary'] . ';--primary-d:' . $c['primary_d']
        . ';--primary-tint:' . $c['primary_tint'] . ';--primary-rgb:' . $c['primary_rgb']
        . ';--brand:' . $c['brand'] . ';--brand-2:' . $c['brand_2'] . ';}</style>';
}

// Options d'unité de temps pour un <select> de ligne de prestation, encodées
// "heures|libellé" (valeur) — partagées entre le formulaire de fiche de salaire
// et l'ajout rapide de prestation depuis un événement.
function options_unites(array $unites): string
{
    $opts = '';
    foreach ($unites as $u) {
        $val = $u['heures'] . '|' . $u['libelle'];
        $opts .= '<option value="' . e($val) . '" data-h="' . e((string) $u['heures']) . '">'
            . e($u['libelle']) . ' (' . nombre_court($u['heures']) . ' h)</option>';
    }
    return $opts;
}

// Options de taux horaire standard + « Autre » pour un <select> de ligne de prestation.
function options_taux_horaires(array $tauxHoraires): string
{
    $opts = '';
    foreach ($tauxHoraires as $th) {
        $opts .= '<option value="' . e((string) $th['montant']) . '" data-rate="' . e((string) $th['montant']) . '">'
            . e($th['libelle'] . ' — ' . chf((float) $th['montant']) . ' CHF/h') . '</option>';
    }
    $opts .= '<option value="autre">Autre…</option>';
    return $opts;
}

// Valide une valeur contre une liste blanche, avec repli sur une valeur par
// défaut si absente/invalide — un seul point de contrôle plutôt que de
// recopier le in_array(...) ? ... : défaut à chaque nouveau champ validé
// (notamment le dispatcher de modification groupée des événements).
function valeur_autorisee(?string $valeur, array $whitelist, string $defaut = ''): string
{
    return in_array($valeur, $whitelist, true) ? $valeur : $defaut;
}

// Options d'axe analytique pour un <select> de ligne de prestation (fiche de
// salaire ou événement) — un « — » en tête pour l'absence d'axe.
function options_axes(array $axes): string
{
    $opts = '<option value="">—</option>';
    foreach ($axes as $ax) {
        $label = ($ax['code'] !== '' && $ax['code'] !== null) ? $ax['code'] : $ax['libelle'];
        $opts .= '<option value="' . (int) $ax['id'] . '">' . e($label) . '</option>';
    }
    return $opts;
}

// Pré-sélectionne une <option> dans un bloc d'options déjà généré (unité, taux
// horaire, axe…) — une ligne éditée en place est ainsi pré-remplie avec sa
// valeur déjà enregistrée.
function preselectionner_option(string $optionsHtml, string $value): string
{
    if ($value === '') {
        return $optionsHtml;
    }
    return preg_replace_callback('/<option value="([^"]*)"/', function ($m) use ($value) {
        return $m[0] . (html_entity_decode($m[1], ENT_QUOTES) === $value ? ' selected' : '');
    }, $optionsHtml);
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

// Traite l'upload d'un PDF (ex. feuille SUISA pré-remplie d'un spectacle).
// Même logique que handle_logo_upload() mais validation par mime réel (finfo)
// plutôt que getimagesize(). Renvoie le chemin web relatif (uploads/…) si un
// fichier valide a été envoyé, null si aucun fichier, ou lève RuntimeException.
function handle_pdf_upload(string $field): ?string
{
    $f = $_FILES[$field] ?? null;
    if ($f === null || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null; // aucun fichier → inchangé
    }
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException("Échec de l'envoi du fichier (code {$f['error']}).");
    }
    if ($f['size'] > 2 * 1024 * 1024) {
        throw new RuntimeException('Fichier trop lourd (2 Mo maximum).');
    }
    $mime = @finfo_file(finfo_open(FILEINFO_MIME_TYPE), $f['tmp_name']);
    if ($mime !== 'application/pdf') {
        throw new RuntimeException('Format non supporté (PDF uniquement).');
    }
    $dir = __DIR__ . '/../uploads';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $name = $field . '_' . bin2hex(random_bytes(6)) . '.pdf';
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

// Libellé court d'un événement (date + spectacle + ville), utilisé partout
// où l'on affiche un lien vers un événement sans reprendre toute la fiche.
function evenement_label_court(array $ev): string
{
    $l = date('d.m.Y', strtotime((string) $ev['date']));
    if (!empty($ev['spectacle_nom'])) $l .= ' — ' . $ev['spectacle_nom'];
    if (!empty($ev['ville']))         $l .= ' (' . $ev['ville'] . ')';
    return $l;
}

// Ajoute (ou complète) le paramètre ?depuis=type:id (ou ?depuis=type seul
// pour une cible statique sans id, ex. 'dashboard'/'compta_ecritures') à une
// URL, pour que la page cible affiche un lien de retour contextuel (voir
// lien_retour_contextuel()).
function url_avec_retour(string $href, string $type, ?int $id = null): string
{
    $sep = str_contains($href, '?') ? '&' : '?';
    return $href . $sep . 'depuis=' . rawurlencode($id !== null ? $type . ':' . $id : $type);
}

// Lien « retour » contextuel : si la page a été atteinte via un lien croisé
// inter-module portant ?depuis=type:id (voir url_avec_retour()), pointe vers
// cet objet précis avec son libellé actuel plutôt que vers la liste générique.
function lien_retour_contextuel(string $defautHref, string $defautLabel): string
{
    $depuis = (string) ($_GET['depuis'] ?? '');
    // Cibles sans id propre (page/liste, pas un objet précis) — le filtrage
    // actif (compte/année/catégorie…) est repris automatiquement au retour
    // via filtre_persistant() (session), pas besoin de l'encoder dans l'URL.
    $statiques = [
        'dashboard'        => ['?p=resumes', 'Tableau de bord'],
        'compta_ecritures' => ['?p=compta_ecritures', 'Écritures'],
    ];
    if (isset($statiques[$depuis])) {
        return lien_retour($statiques[$depuis][0], $statiques[$depuis][1]);
    }
    if (preg_match('/^(facture|evenement|fiche|employe):(\d+)$/', $depuis, $m)) {
        $id = (int) $m[2];
        if ($m[1] === 'employe') {
            $stmt = db()->prepare('SELECT prenom, nom FROM employes WHERE id = ?');
            $stmt->execute([$id]);
            $emp = $stmt->fetch();
            if ($emp) {
                return lien_retour('?p=employe_voir&id=' . $id, $emp['prenom'] . ' ' . $emp['nom']);
            }
        } elseif ($m[1] === 'facture') {
            $stmt = db()->prepare('SELECT numero FROM factures WHERE id = ?');
            $stmt->execute([$id]);
            $numero = $stmt->fetchColumn();
            if ($numero !== false) {
                return lien_retour('?p=facture&id=' . $id, $numero !== '' ? 'Facture ' . $numero : 'Facture (brouillon)');
            }
        } elseif ($m[1] === 'evenement') {
            $stmt = db()->prepare('SELECT e.*, s.nom AS spectacle_nom FROM evenements e
                                    LEFT JOIN spectacles s ON s.id = e.spectacle_id WHERE e.id = ?');
            $stmt->execute([$id]);
            $ev = $stmt->fetch();
            if ($ev) {
                return lien_retour('?p=evenement&id=' . $id, evenement_label_court($ev));
            }
        } elseif ($m[1] === 'fiche') {
            $stmt = db()->prepare('SELECT mois, annee, employe_nom FROM fiches WHERE id = ?');
            $stmt->execute([$id]);
            $f = $stmt->fetch();
            if ($f) {
                return lien_retour('?p=fiche&id=' . $id, 'Fiche ' . mois_nom((int) $f['mois']) . ' ' . $f['annee'] . ' — ' . $f['employe_nom']);
            }
        }
    }
    return lien_retour($defautHref, $defautLabel);
}

// Mémorise l'état « avant » de lignes modifiées en masse (voir bulk_undo_appliquer()),
// pour permettre une annulation en un clic (lien « Annuler » affiché 10 s + raccourci
// Ctrl-Z/Cmd+Z). Portée volontairement limitée aux modifications de colonnes (UPDATE) —
// les suppressions en masse ne sont pas couvertes (état bien plus lourd à restaurer
// fidèlement : lignes filles, contraintes, etc.). Pour un remplacement de lignes filles
// (ex. ventilations), voir bulk_undo_memoriser_ventilations().
function bulk_undo_memoriser(string $table, array $ids, array $colonnes, string $route, array $retour = []): void
{
    if (!$ids) {
        return;
    }
    $in   = implode(',', array_fill(0, count($ids), '?'));
    $cols = implode(',', array_map(fn(string $c) => "\"$c\"", $colonnes));
    $stmt = db()->prepare("SELECT id, $cols FROM \"$table\" WHERE id IN ($in)");
    $stmt->execute($ids);
    $_SESSION['bulk_undo'] = [
        'table' => $table, 'colonnes' => $colonnes, 'rows' => $stmt->fetchAll(),
        'route' => $route, 'retour' => $retour, 'expire' => time() + 300,
    ];
}

// Variante de bulk_undo_memoriser() pour l'affectation d'axe analytique en masse : cette
// action remplace les ventilations existantes (DELETE puis INSERT) au lieu de modifier des
// colonnes, donc rien à restaurer via un simple UPDATE — on mémorise les lignes
// ecriture_id/axe_id/montant à la place (éventuellement aucune, si les écritures n'avaient
// pas encore de ventilation).
function bulk_undo_memoriser_ventilations(array $ecritureIds, string $route, array $retour = []): void
{
    if (!$ecritureIds) {
        return;
    }
    $in   = implode(',', array_fill(0, count($ecritureIds), '?'));
    $stmt = db()->prepare("SELECT ecriture_id, axe_id, montant FROM ecritures_ventilations WHERE ecriture_id IN ($in)");
    $stmt->execute($ecritureIds);
    $_SESSION['bulk_undo'] = [
        'kind' => 'ventilations', 'ecriture_ids' => $ecritureIds, 'rows' => $stmt->fetchAll(),
        'route' => $route, 'retour' => $retour, 'expire' => time() + 300,
    ];
}

// Restaure l'état mémorisé par bulk_undo_memoriser()/bulk_undo_memoriser_ventilations(),
// si présent et pas expiré. Renvoie [route, retour] pour la redirection vers la page
// d'origine, ou null si rien à annuler (déjà utilisé, expiré, ou aucune action en attente).
function bulk_undo_appliquer(): ?array
{
    $u = $_SESSION['bulk_undo'] ?? null;
    unset($_SESSION['bulk_undo']);
    if (!$u || $u['expire'] < time()) {
        return null;
    }
    if (($u['kind'] ?? null) === 'ventilations') {
        $ids = $u['ecriture_ids'];
        if (!$ids) {
            return null;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        db()->prepare("DELETE FROM ecritures_ventilations WHERE ecriture_id IN ($in)")->execute($ids);
        $ins = db()->prepare('INSERT INTO ecritures_ventilations (ecriture_id, axe_id, montant) VALUES (?, ?, ?)');
        foreach ($u['rows'] as $row) {
            $ins->execute([$row['ecriture_id'], $row['axe_id'], $row['montant']]);
        }
        return ['route' => $u['route'], 'retour' => $u['retour']];
    }
    if (!$u['rows']) {
        return null;
    }
    $sets = implode(',', array_map(fn(string $c) => "\"$c\" = ?", $u['colonnes']));
    $stmt = db()->prepare("UPDATE \"{$u['table']}\" SET $sets WHERE id = ?");
    foreach ($u['rows'] as $row) {
        $vals = array_map(fn(string $c) => $row[$c], $u['colonnes']);
        $vals[] = $row['id'];
        $stmt->execute($vals);
    }
    return ['route' => $u['route'], 'retour' => $u['retour']];
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
    return envoyer_email($destinataire, $expediteur, $sujetEnc, $entetes, $html, $sujet);
}

// Transport commun à tout envoi d'e-mail applicatif (fiches, factures…) : en
// dev, journalisé dans data/emails_envoyes.log au lieu d'être envoyé ; en
// prod, SMTP authentifié si configuré, sinon repli sur mail(). $entetesMime =
// en-têtes hors To/Subject (From/Reply-To/Content-Type…) ; $corps = corps déjà
// encodé (HTML simple ou MIME multipart avec pièce jointe) ; $resumeLog =
// ligne de résumé journalisée en dev (pas le corps complet).
// Retourne [bool succès, string mode ('local'|'smtp'|'mail')].
function envoyer_email(string $destinataire, string $expediteur, string $sujetEnc, string $entetesMime, string $corps, string $resumeLog): array
{
    if (APP_ENV === 'dev') {
        $log = dirname(APP_DB_PATH) . '/emails_envoyes.log';
        @file_put_contents($log, '[' . date('c') . "] To: $destinataire | De: $expediteur | $resumeLog\n", FILE_APPEND);
        return [true, 'local'];
    }

    // En prod : SMTP authentifié si configuré (beaucoup d'hébergeurs désactivent mail()),
    // sinon repli sur mail() pour les hébergeurs qui l'autorisent.
    $cfg = smtp_config();
    if ($cfg['user'] !== '') {
        $message = 'To: ' . $destinataire . "\r\n" . 'Subject: ' . $sujetEnc . "\r\n" . $entetesMime . "\r\n\r\n" . $corps;
        return [smtp_transmettre($cfg, $destinataire, $message), 'smtp'];
    }
    if (!function_exists('mail')) {
        error_log('[app] mail() indisponible et SMTP non configuré (écran Employeur ou config.local.php).');
        return [false, 'mail'];
    }
    return [(bool) @mail($destinataire, $sujetEnc, $corps, $entetesMime), 'mail'];
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

// Transmet un message brut déjà complet (en-têtes To/Subject/… + ligne vide + corps)
// par SMTP authentifié, en PHP pur (aucune dépendance). Gère SSL implicite
// (port 465) ou STARTTLS (port 587), AUTH LOGIN. Appelé par envoyer_email(),
// commun à tous les e-mails applicatifs (fiches simples, factures avec pièce jointe).
function smtp_transmettre(array $cfg, string $to, string $message): bool
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
        return '<span class="badge ok-badge">' . e(date('d.m.Y', strtotime($date))) . '</span>';
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
    $stmt = db()->prepare(
        'SELECT fl.*, a.code AS axe_code, a.libelle AS axe_libelle,
                e.date AS evenement_date, s.nom AS evenement_spectacle_nom
         FROM fiche_lignes fl
         LEFT JOIN axes_analytiques a ON a.id = fl.axe_analytique_id
         LEFT JOIN evenements e ON e.id = fl.evenement_id
         LEFT JOIN spectacles s ON s.id = e.spectacle_id
         WHERE fl.fiche_id = ? ORDER BY fl.ordre, fl.id'
    );
    $stmt->execute([$f['id']]);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        $rows = [[
            'libelle'       => 'Heures',
            'heures_unite'  => 1.0,
            'quantite'      => (float) $f['nombre_heures'],
            'taux_horaire'  => (float) $f['salaire_horaire'],
            'axe_analytique_id' => null,
            'axe_code'      => null,
            'axe_libelle'   => null,
            'evenement_id'  => null,
            'evenement_date' => null,
            'evenement_spectacle_nom' => null,
        ]];
    }
    return $rows;
}

// Icônes Lucide (https://lucide.dev, licence ISC). SVG en ligne, sans requête externe.
function icon(string $name): string
{
    $paths = [
        'file-text' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/>',
        'receipt-swiss-franc' => '<path d="M10 11h4"/><path d="M10 17V7h5"/><path d="M4 3a1 1 0 0 1 1-1 1.3 1.3 0 0 1 .7.2l.933.6a1.3 1.3 0 0 0 1.4 0l.934-.6a1.3 1.3 0 0 1 1.4 0l.933.6a1.3 1.3 0 0 0 1.4 0l.933-.6a1.3 1.3 0 0 1 1.4 0l.934.6a1.3 1.3 0 0 0 1.4 0l.933-.6A1.3 1.3 0 0 1 19 2a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1 1.3 1.3 0 0 1-.7-.2l-.933-.6a1.3 1.3 0 0 0-1.4 0l-.934.6a1.3 1.3 0 0 1-1.4 0l-.933-.6a1.3 1.3 0 0 0-1.4 0l-.933.6a1.3 1.3 0 0 1-1.4 0l-.934-.6a1.3 1.3 0 0 0-1.4 0l-.933.6a1.3 1.3 0 0 1-.7.2 1 1 0 0 1-1-1z"/><path d="M8 15h5"/>',
        'building-2' => '<path d="M10 12h4"/><path d="M10 8h4"/><path d="M14 21v-3a2 2 0 0 0-4 0v3"/><path d="M6 10H4a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-2"/><path d="M6 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16"/>',
        'landmark' => '<path d="M10 18v-7"/><path d="M11.119 2.205a2 2 0 0 1 1.762 0l7.84 3.846A.5.5 0 0 1 20.5 7h-17a.5.5 0 0 1-.22-.949z"/><path d="M14 18v-7"/><path d="M18 18v-7"/><path d="M3 22h18"/><path d="M6 18v-7"/>',
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
        'import'    => '<path d="M12 3v12"/><path d="m8 11 4 4 4-4"/><path d="M8 5H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-4"/>',
        'tag'       => '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/>',
        'chevron-up'   => '<path d="m18 15-6-6-6 6"/>',
        'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
        'plus'      => '<path d="M5 12h14"/><path d="M12 5v14"/>',
        'search'    => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'merge'     => '<path d="m8 6 4-4 4 4"/><path d="M12 2v10.3a4 4 0 0 1-1.172 2.872L4 22"/><path d="m20 22-5-5"/>',
        'archive'   => '<rect width="20" height="5" x="2" y="3" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/>',
        'grip'      => '<circle cx="9" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="18" r="1"/>',
        'book-open' => '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
        'wand'      => '<path d="m21.64 3.64-1.28-1.28a1.21 1.21 0 0 0-1.72 0L2.36 18.64a1.21 1.21 0 0 0 0 1.72l1.28 1.28a1.2 1.2 0 0 0 1.72 0L21.64 5.36a1.2 1.2 0 0 0 0-1.72"/><path d="m14 7 3 3"/><path d="M5 6v4"/><path d="M19 14v4"/><path d="M10 2v2"/><path d="M7 8H3"/><path d="M21 16h-4"/><path d="M11 3H9"/>',
        'lock'      => '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'circle-gauge' => '<path d="M15.6 2.7a10 10 0 1 0 5.7 5.7"/><circle cx="12" cy="12" r="2"/><path d="M13.4 10.6 19 5"/>',
        'calendar'  => '<rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'music'     => '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>',
        'file-braces' => '<path d="M6 22a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h8a2.4 2.4 0 0 1 1.704.706l3.588 3.588A2.4 2.4 0 0 1 20 8v12a2 2 0 0 1-2 2z"/><path d="M14 2v5a1 1 0 0 0 1 1h5"/><path d="M10 12a1 1 0 0 0-1 1v1a1 1 0 0 1-1 1 1 1 0 0 1 1 1v1a1 1 0 0 0 1 1"/><path d="M14 18a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1 1 1 0 0 1-1-1v-1a1 1 0 0 0-1-1"/>',
        'calendar-sync' => '<path d="M11 10v4h4"/><path d="m11 14 1.535-1.605a5 5 0 0 1 8 1.5"/><path d="M16 2v4"/><path d="m21 18-1.535 1.605a5 5 0 0 1-8-1.5"/><path d="M21 22v-4h-4"/><path d="M21 8.5V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h4.3"/><path d="M3 10h4"/><path d="M8 2v4"/>',
        'info'      => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
    ];
    $p = $paths[$name] ?? '';
    return '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
        . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

// Icône « i » avec infobulle : survol/focus sur ordinateur, tap sur mobile
// (voir le script dans views/layout.php). Un seul endroit pour changer
// l'icône/le comportement partout où une infobulle est utilisée sur le site.
function info_tip(string $texte): string
{
    return '<span class="info-tip" tabindex="0" role="button" aria-label="Plus d\'informations">'
        . icon('info')
        . '<span class="info-tip-bulle" role="tooltip">' . e($texte) . '</span></span>';
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
