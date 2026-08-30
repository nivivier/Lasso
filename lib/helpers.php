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

// URL de lien externe affichable comme <a href> cliquable sans risque (schéma
// http(s) explicite, donc jamais "javascript:" ou autre schéma actif, +
// FILTER_VALIDATE_URL) — partagé entre l'import événements
// (lib/evenements.php) et son aperçu (views/_import_evenements_section.php).
function lien_http_valide(string $lien): bool
{
    return $lien !== '' && preg_match('#^https?://#i', $lien) === 1 && filter_var($lien, FILTER_VALIDATE_URL) !== false;
}

// Détecte le séparateur (virgule ou point-virgule, fréquent dans les exports
// Excel francophones) à partir de la ligne d'en-tête d'un CSV — partagé entre
// l'import structures (lib/booking.php) et l'import événements
// (lib/evenements.php).
function csv_detecter_delimiteur(string $ligneEntete): string
{
    return substr_count($ligneEntete, ';') > substr_count($ligneEntete, ',') ? ';' : ',';
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
        // Filtre multi-valeurs (voir filtre_coche()) : une paire clé[]=valeur
        // par élément, plutôt que (string) $v qui produirait "Array" + un
        // avertissement PHP.
        if (is_array($v)) {
            foreach ($v as $vv) {
                $url .= '&' . urlencode($k) . '[]=' . urlencode((string) $vv);
            }
            continue;
        }
        $url .= '&' . urlencode($k) . '=' . urlencode((string) $v);
    }
    header('Location: ' . $url);
    exit;
}

// Supprime la ligne $id de $table, sauf si des lignes de $tableRef y font
// encore référence via $colonneRef (ex. un employé qui a des fiches, un
// débiteur qui a des factures) — la suppression est alors refusée.
// Retourne true si la suppression a eu lieu, false si elle a été refusée.
//
// Les quatre noms sont interpolés dans le SQL (impossible de les paramétrer :
// PDO ne prépare que des valeurs, pas des identifiants). Tous les appels
// actuels passent des littéraux du code, jamais une valeur issue de la requête
// — mais c'était une invariante tenue par convention, vérifiable nulle part.
// Le contrôle ci-dessous la rend explicite : un identifiant qui n'est pas un
// nom simple (lettres, chiffres, souligné) lève au lieu d'atteindre le SQL.
// Volontairement un contrôle de forme et non une liste des tables autorisées :
// une liste exhaustive devrait être tenue à jour à chaque nouvelle table, et
// finirait par être élargie sans réflexion — la forme, elle, suffit à écarter
// toute injection, puisqu'aucun caractère de syntaxe SQL ne passe.
function supprimer_si_non_reference(string $table, int $id, string $tableRef, string $colonneRef): bool
{
    foreach (['table' => $table, 'tableRef' => $tableRef, 'colonneRef' => $colonneRef] as $nom => $val) {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $val)) {
            throw new InvalidArgumentException("supprimer_si_non_reference() : \$$nom invalide.");
        }
    }
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

// Placeholders "?,?,?..." (un par élément de $valeurs) pour une clause IN (...)
// préparée — un seul point de vérité pour ce pattern, avant répété (copié à
// la main : implode(',', array_fill(0, count($x), '?'))) plus de 40 fois
// dans lib/. Prend le tableau lui-même (pas juste sa taille) pour que
// l'appelant n'ait pas à écrire count($x) séparément.
function sql_in(array $valeurs): string
{
    return implode(',', array_fill(0, count($valeurs), '?'));
}

// Reporte un tableau de paramètres GET (filtres actuels, souvent construit
// avec filtre_coche()) dans un formulaire, sous forme de <input type="hidden">
// — une paire nom[]=valeur par élément pour un filtre multi-valeurs (tableau),
// une seule nom=valeur sinon ; jamais (string) $v directement, qui produirait
// "Array" + un avertissement PHP. Repris à l'identique par tous les
// formulaires qui doivent survivre à une soumission sans perdre les filtres
// déjà actifs (dropdown de colonne, bannière de géocodage, pagination) : un
// seul point de vérité pour ce pattern. redirect() plus haut a une logique
// très proche mais produit une query string (urlencode), pas du HTML échappé
// — volontairement resté séparé plutôt que forcé dans cette fonction.
function hidden_inputs_html(array $params): string
{
    $h = '';
    foreach ($params as $nom => $valeur) {
        foreach ((array) $valeur as $vv) {
            $h .= '<input type="hidden" name="' . e(is_array($valeur) ? $nom . '[]' : (string) $nom) . '" value="' . e((string) $vv) . '">';
        }
    }
    return $h;
}

// Fabrique une closure $autresFiltres('champ') => tous les $tousFiltres SAUF
// celui nommé, sans valeurs vides (array_filter) — pour reporter dans un
// panneau de filtre de colonne (filtre_colonne_html()) les AUTRES filtres
// actuellement actifs de la page, sans celui-là (qui a déjà son propre état
// posté par ce panneau-là). Usage : $autresFiltres = autres_filtres_fn([
// 'annee' => $annee, 'categorie' => $categorieFilter, ...]); puis
// $autresFiltres('annee') à chaque filtre_colonne_html() — évite d'écrire à
// la main, pour chaque filtre, un littéral listant tous les autres.
function autres_filtres_fn(array $tousFiltres): Closure
{
    return fn (string $cle): array => array_filter(array_diff_key($tousFiltres, [$cle => true]));
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

// --- Pagination générique (listes potentiellement longues : fiches, écritures,
// factures, débiteurs, employés, événements) -------------------------------

const PAGINATION_TAILLES = [25, 50, 100, 200];
const PAGINATION_TAILLE_DEFAUT = 100;

// Seuil (nombre total d'éléments, filtres structurés déjà appliqués mais
// recherche texte exclue) en dessous duquel une liste passe en mode "client" :
// toutes les lignes sont chargées d'un coup et la pagination + la recherche se
// font entièrement en JS (lassoListeClient(), assets/app.js + _pagination_client.php),
// sans aller-retour serveur — pour du texte pur, un aller-retour par frappe est
// inutile en dessous de ce volume. Au-delà, voir lassoRechercheServeur() +
// _pagination.php (aller-retour serveur, pagination + LIMIT SQL).
// Valeur PAR DÉFAUT du seuil ; la valeur effective se règle dans
// Paramètres → Serveur (voir pagination_seuil_client()). Elle vit en base parce
// que le bon réglage dépend des données et du matériel, pas du code : sur les
// 2 965 structures, le mode client tient en 207 Ko compressés, 268 ms de
// chargement et 21 ms par frappe — mais 89 600 nœuds DOM, ce qui peut être trop
// pour un appareil modeste. Se règle sans redéploiement.
const PAGINATION_SEUIL_CLIENT = 4000;

// Borne haute du réglage : au-delà, le navigateur garderait en mémoire un DOM
// que même une machine de bureau peinerait à tenir. Ce n'est pas une limite
// mesurée au cordeau, c'est un garde-fou contre une saisie absurde.
const PAGINATION_SEUIL_MAX = 20000;

// Seuil effectif : réglage enregistré, sinon le défaut ci-dessus. Borné des deux
// côtés — 0 force toutes les listes côté serveur, ce qui est un choix légitime.
function pagination_seuil_client(): int
{
    $v = param('pagination_seuil_client', '');
    $v = $v === '' ? PAGINATION_SEUIL_CLIENT : (int) $v;
    return max(0, min(PAGINATION_SEUIL_MAX, $v));
}

// true si le total (filtres structurés, hors recherche texte) tient sous le
// seuil client — décide, pour chaque route de liste, si elle doit charger
// toutes les lignes (mode client) ou paginer/rechercher côté serveur.
function pagination_mode_client(int $totalSansRecherche): bool
{
    return $totalSansRecherche <= pagination_seuil_client();
}

// Nombre de lignes par page : GET prioritaire (et mémorisé en session, comme
// filtre_persistant()) sinon 100 par défaut. Bornée à PAGINATION_TAILLES pour
// qu'une valeur arbitraire dans l'URL ne force pas une requête énorme.
function pagination_taille(string $cleSession): int
{
    $t = (int) filtre_persistant('taille', $cleSession, PAGINATION_TAILLE_DEFAUT);
    return in_array($t, PAGINATION_TAILLES, true) ? $t : PAGINATION_TAILLE_DEFAUT;
}

// Page courante (1-based). Jamais mémorisée en session (contrairement aux
// filtres) : sinon, changer d'année/statut pourrait rouvrir sur une page qui
// n'existe plus pour le nouveau résultat filtré.
function pagination_page(): int
{
    $p = (int) ($_GET['page'] ?? 1);
    return $p > 0 ? $p : 1;
}

// Clause SQL " LIMIT ? OFFSET ?" + les deux valeurs à ajouter à $params, dans
// l'ordre — évite de recalculer l'offset à chaque route.
function pagination_sql(int $page, int $taille): array
{
    return [' LIMIT ? OFFSET ?', [$taille, ($page - 1) * $taille]];
}

// Numéros de page affichés autour de la page courante (± 1) + toujours la
// première et la dernière, « … » (chaîne, pas de sens numérique) pour les
// trous — évite d'afficher 50+ numéros pour une grosse pagination. Mêmes
// règles reprises côté JS pour le mode client (assets/app.js, pagesAffichees()
// dans lassoListeClient()) : les deux doivent rester synchronisées à la main,
// pas de logique partagée possible entre PHP (rendu serveur) et JS (rendu
// dans le navigateur, listes sous PAGINATION_SEUIL_CLIENT).
function pagination_pages_affichees(int $page, int $nbPages): array
{
    if ($nbPages <= 7) {
        return range(1, $nbPages);
    }
    $brut = array_unique([1, 2, $page - 1, $page, $page + 1, $nbPages - 1, $nbPages]);
    $brut = array_values(array_filter($brut, fn ($p) => $p >= 1 && $p <= $nbPages));
    sort($brut);

    $out = [];
    $prec = null;
    foreach ($brut as $p) {
        if ($prec !== null && $p - $prec > 1) {
            $out[] = '…';
        }
        $out[] = $p;
        $prec = $p;
    }
    return $out;
}

// Minuscule + repli des accents sur la lettre de base (é→e, ç→c…), en gardant
// espaces et ponctuation — pour un rapprochement lisible d'intitulés à l'import
// (ex. « Média » ↔ « Media »). Différent de normaliser_nom_structure()
// (lib/booking.php) qui, lui, SUPPRIME accents ET ponctuation (pour comparer
// des noms propres).
//
// Vit ici et non dans lib/booking.php (son emplacement d'origine) parce que
// lib/db.php l'expose à SQLite sous le nom SANS_ACCENTS() pour la recherche
// unifiée : db.php est chargé bien avant booking.php, qui n'accompagne que le
// module booking.
function texte_sans_accents(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    return strtr($s, [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae', 'ÿ' => 'y',
    ]);
}

// Échappe % / _ / \ pour un motif LIKE sûr (à utiliser avec ESCAPE '\\') —
// sinon un utilisateur tapant "%" ou "_" dans une recherche déclencherait un
// joker SQL au lieu d'un caractère littéral. $terme : déjà sans les % de
// bordure, ajoutés par l'appelant ('%' . like_echappe($terme) . '%').
function like_echappe(string $terme): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $terme);
}

// Recherche texte serveur (paginée) : lit ?q=, jamais mémorisée en session
// (comme pagination_page() — une recherche laissée dans la session surprendrait
// à la prochaine visite). $colonnes : expressions SQL (déjà qualifiées si besoin,
// ex. 'e.nom') unies en OR ... LIKE. Retourne [$sqlFragment, $params] à ajouter
// au WHERE ; $sqlFragment est '' si aucune recherche (rien à ajouter).
function recherche_sql(array $colonnes): array
{
    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q === '') {
        return ['', []];
    }
    $motif = '%' . like_echappe($q) . '%';
    $sql = ' AND (' . implode(' OR ', array_map(fn($c) => "$c LIKE ? ESCAPE '\\'", $colonnes)) . ')';
    return [$sql, array_fill(0, count($colonnes), $motif)];
}

// Lecture d'un filtre multi-valeurs (cases à cocher) porté par une colonne :
// GET prioritaire (marqueur <champ>_set toujours présent quand le panneau est
// soumis, même si aucune case n'est cochée — sinon impossible de distinguer
// « toutes les cases décochées » de « rien dans l'URL, retomber sur la
// session ») sinon dernière valeur en session. $valeurs : whitelist connue
// (ex. statut) → intersection ; sinon $texteLibre=false attend des ids
// numériques (annee/employe_id/compte, intval + valeurs nulles écartées, 0
// n'étant jamais un id valide) ; $texteLibre=true conserve les valeurs telles
// quelles (catégorie/axe : mélange d'ids numériques et de sentinelles
// textuelles comme "a_lettrer"/"sans_axe", intval() les corromprait). $defaut :
// valeur de tout premier affichage (session jamais touchée, ex. structures —
// "actif"/"contact_privilegie" pour masquer par défaut le bruit inactif/ne
// pas contacter) ; une fois la session touchée une seule fois (même pour
// tout décocher), $defaut ne revient plus jamais — comme filtre_persistant().
function filtre_coche(string $cle, string $cleSession, ?array $valeurs = null, bool $texteLibre = false, array $defaut = []): array
{
    $normaliser = function (array $brut) use ($valeurs, $texteLibre): array {
        if ($valeurs !== null) {
            return array_values(array_intersect($brut, $valeurs));
        }
        if ($texteLibre) {
            return array_values(array_unique(array_filter(array_map('strval', $brut), fn($v) => $v !== '')));
        }
        return array_values(array_filter(array_map('intval', $brut)));
    };
    if (isset($_GET[$cle . '_set'])) {
        $v = $normaliser((array) ($_GET[$cle] ?? []));
        $_SESSION[$cleSession] = $v;
        return $v;
    }
    if (!isset($_SESSION[$cleSession])) {
        return $defaut;
    }
    return $normaliser((array) $_SESSION[$cleSession]);
}

// Filtre de colonne à cases à cocher (EXPÉRIMENTAL, ?p=fiches — Paiement/
// Date/Employé) : bouton entonnoir + panneau (voir .col-filter* dans
// assets/app.css), plutôt qu'un <select> dans la barre d'outils, pour que
// le filtre reste visuellement rattaché à la colonne qu'il filtre. $page :
// route courante (p=…), sinon ce <form> sans action= soumettrait sans
// paramètre 'p' et retomberait sur la route par défaut de l'appli au lieu
// de rester sur la page filtrée. $champ : nom du paramètre GET, envoyé en
// tableau (ex. 'statut' → statut[]). $options : [valeur => libellé].
// $actives : sous-ensemble actuellement coché de $options. $autresParams :
// les AUTRES filtres de la page (jamais celui-ci) à reporter en hidden
// inputs pour ne pas les perdre en soumettant ce panneau — valeurs
// scalaires ou tableaux, sérialisées à l'identique.
// Tranches d'ancienneté d'une date, partagées par les filtres « Dernière
// modification » et « Dernier contact » (?p=structures).
//
// Volontairement DISJOINTES (« cette semaine » exclut les dernières 24 h, etc.)
// et non emboîtées : le widget de filtre est une liste de cases à cocher unies
// en OU, or avec des tranches emboîtées cocher « Cette année » et « Ce mois »
// donnerait exactement le même résultat que « Cette année » seule — les cases
// ne voudraient plus rien dire. Disjointes, chaque combinaison a un sens.
//
// « Jamais » n'est pas un ornement : sur les structures, 73 % des dates de
// modification et 78 % des dates de contact sont vides. Sans cette tranche,
// cocher toutes les autres masquerait la majorité des fiches sans expliquer
// pourquoi.
const PERIODES_ANCIENNETE = [
    'j1'     => 'Moins de 24 h',
    'j7'     => 'Cette semaine',
    'j30'    => 'Ce mois',
    'j365'   => 'Cette année',
    'a3'     => '1 à 3 ans',
    'plus3'  => 'Plus de 3 ans',
    'jamais' => 'Jamais',
];

// Fragment SQL (et paramètres) filtrant $colonne sur les tranches cochées.
// Renvoie ['', []] si rien n'est coché — aucune restriction, comme les autres
// filtres de colonne. Les dates sont stockées en « AAAA-MM-JJ » : la
// comparaison lexicographique suffit, d'où date('now', …) côté SQLite.
function periode_anciennete_where(string $colonne, array $tranches): array
{
    // [jours au plus (borne ancienne, incluse), jours au moins (borne récente, exclue)]
    $bornes = [
        'j1'    => [1, null],
        'j7'    => [7, 1],
        'j30'   => [30, 7],
        'j365'  => [365, 30],
        'a3'    => [1095, 365],
        'plus3' => [null, 1095],
    ];
    $conds = [];
    $params = [];
    foreach ($tranches as $t) {
        if ($t === 'jamais') {
            $conds[] = "($colonne IS NULL OR $colonne = '')";
            continue;
        }
        if (!isset($bornes[$t])) {
            continue;
        }
        [$plusVieux, $plusRecent] = $bornes[$t];
        $morceaux = ["$colonne <> ''", "$colonne IS NOT NULL"];
        if ($plusVieux !== null) {
            $morceaux[] = "$colonne >= date('now', ?)";
            $params[] = '-' . $plusVieux . ' days';
        }
        if ($plusRecent !== null) {
            $morceaux[] = "$colonne < date('now', ?)";
            $params[] = '-' . $plusRecent . ' days';
        }
        $conds[] = '(' . implode(' AND ', $morceaux) . ')';
    }
    return $conds ? [' AND (' . implode(' OR ', $conds) . ')', $params] : ['', []];
}

// $libelle : hors d'un tableau (panneau « Filtres » mobile de ?p=structures,
// ciblage de ?p=mailing_campagne), il n'y a pas d'en-tête de colonne pour
// nommer le filtre — le libellé entre alors DANS le <summary>, avec le nombre
// de valeurs actives. Tout le bouton devient cliquable, au lieu d'un entonnoir
// de 18px posé à côté d'un texte inerte, et l'état actif se voit sans ouvrir le
// menu. Laissé vide (le défaut), le bouton reste l'entonnoir seul des <thead>,
// où le nom de la colonne et les pastilles de valeurs actives font déjà ce
// travail.
// Bouton d'ouverture d'un filtre de colonne. Sans libellé : l'entonnoir seul
// des <thead>. Avec : un vrai bouton nommé, qui porte son état actif et, le cas
// échéant, le nombre de valeurs cochées.
function filtre_bouton_html(string $libelle, bool $actif, ?int $nb = null): string
{
    if ($libelle === '') {
        // L'entonnoir seul des <thead> porte lui-même son état : coloré et plus
        // épais dès qu'une valeur est cochée. C'est ce qui permet de ne plus
        // lister les valeurs actives sous l'en-tête — elles y doublaient une
        // information déjà donnée par la barre d'outils, et empilées elles
        // faisaient passer la ligne d'en-tête à 92px de haut.
        // $nb est nul pour les filtres à contenu libre (filtre_colonne_form_html())
        // : ils n'ont pas de valeurs cochées à compter, seulement un état.
        $titre = !$actif ? 'Filtrer'
            : ($nb ? 'Filtre actif — ' . $nb . ' valeur(s) sélectionnée(s)' : 'Filtre actif');
        return '<summary class="col-filter-btn' . ($actif ? ' on' : '') . '" title="' . e($titre) . '">'
            . icon('funnel') . '</summary>';
    }
    $h = '<summary class="col-filter-btn col-filter-btn-nomme' . ($actif ? ' on' : '') . '">'
       . '<span class="col-filter-lib">' . e($libelle) . '</span>';
    if ($actif && $nb !== null && $nb > 0) {
        $h .= '<span class="col-filter-nb">' . $nb . '</span>';
    }
    return $h . icon('chevron-down') . '</summary>';
}

// $actionsParOption (facultatif) : HTML posé À CÔTÉ de chaque case à cocher,
// indexé par valeur d'option — le crayon de renommage du filtre « Étiquettes »
// de ?p=structures. Hors du <label> : un bouton à l'intérieur cocherait la case
// en même temps qu'il s'active. Sans ce paramètre, le balisage reste le <label>
// nu de tous les autres filtres.
function filtre_colonne_html(string $page, string $champ, array $options, array $actives, array $autresParams, string $libelle = '', array $actionsParOption = []): string
{
    // Un filtre porteur d'actions a besoin de plus de place : un champ de
    // renommage plus trois icônes ne tiennent pas dans les 200px du panneau
    // ordinaire (voir .col-filter-menu, assets/app.css).
    $h = '<details class="col-filter' . ($actionsParOption ? ' col-filter-large' : '') . '">'
       . filtre_bouton_html($libelle, count($actives) > 0, count($actives))
       . '<form method="get" class="col-filter-menu">'
       . '<input type="hidden" name="p" value="' . e($page) . '">'
       . hidden_inputs_html($autresParams);
    $h .= '<input type="hidden" name="' . e($champ) . '_set" value="1">'
        . '<label class="col-filter-tout"><input type="checkbox" data-check-tout' . (count($actives) === count($options) ? ' checked' : '') . '> Tout</label>'
        . '<div class="col-filter-sep"></div><div class="col-filter-options">';
    $activesTxt = array_map('strval', $actives);
    foreach ($options as $val => $lib) {
        $checked = in_array((string) $val, $activesTxt, true) ? ' checked' : '';
        // Libellés arborescents (les catégories de ?p=structures) : ils arrivent
        // préfixés d'espaces insécables, deux par niveau. Traduits en marge
        // interne sur le <label>, ils décalent la case à cocher EN MÊME TEMPS
        // que son texte ; laissés dans le texte, ils laissaient la colonne de
        // cases parfaitement droite sous une liste visiblement en escalier.
        $niveau = 0;
        if (preg_match('/^\x{00A0}+/u', (string) $lib, $m)) {
            $niveau = intdiv(mb_strlen($m[0]), 2);
            $lib = mb_substr((string) $lib, mb_strlen($m[0]));
        }
        $case = '<label' . ($niveau > 0 ? ' style="--niv:' . $niveau . '"' : '') . '>'
              . '<input type="checkbox" name="' . e($champ) . '[]" value="' . e((string) $val) . '"' . $checked . '> ' . e($lib) . '</label>';
        $h .= isset($actionsParOption[$val])
            ? '<div class="col-filter-opt">' . $case . $actionsParOption[$val] . '</div>'
            : $case;
    }
    return $h . '</div><button type="submit" class="col-filter-apply">Appliquer</button></form></details>';
}

// Pastilles des valeurs actives d'un filtre_colonne_html() — une par valeur,
// chacune avec sa propre croix pour la retirer individuellement (les autres
// valeurs actives et les autres filtres de la page restent inchangés).
function filtre_colonne_actifs_html(string $page, string $champ, array $options, array $actives, array $autresParams): string
{
    if (!$actives) {
        return '';
    }
    $h = '<span class="col-th-actif-list">';
    foreach ($actives as $val) {
        $lib = $options[$val] ?? (string) $val;
        $qs = ['p' => $page] + $autresParams;
        $qs[$champ . '_set'] = 1;
        $qs[$champ] = array_values(array_diff($actives, [$val]));
        $h .= '<span class="col-th-actif">' . e($lib) . '<a href="?' . e(http_build_query($qs)) . '" title="Retirer « ' . e($lib) . ' »">' . icon('x') . '</a></span>';
    }
    return $h . '</span>';
}

// Pastille de filtre actif pour un critère qui n'est PAS une case à cocher, et
// qui ne peut donc pas passer par filtre_colonne_actifs_html() (rien à cocher,
// donc ni $options ni $actives) : une plage de mois, un encadrement de jauge,
// une date de dernier contact. Une seule pastille par groupe, jamais une par
// champ élémentaire — « De »/« à » forment une plage, on ne retire pas l'un
// sans l'autre ; $autresParams doit donc déjà avoir vidé tous les champs du
// groupe d'un coup. Même balisage que filtre_colonne_actifs_html() : les deux
// se mélangent dans la même bande (.filtres-ciblage-actifs, voir
// ?p=mailing_campagne et le panneau « Filtres » mobile de ?p=structures).
function filtre_pille_groupe_html(string $page, string $label, array $autresParams): string
{
    $qs = ['p' => $page] + $autresParams;
    return '<span class="col-th-actif-list"><span class="col-th-actif">' . e($label)
        . '<a href="?' . e(http_build_query($qs)) . '" title="Retirer « ' . e($label) . ' »">' . icon('x') . '</a></span></span>';
}

// Variante de filtre_colonne_html() pour un panneau au contenu libre (pas des
// cases à cocher) — même enveloppe .col-filter/.col-filter-menu (bouton
// entonnoir + <details>, voir assets/app.css), mais $contenu est du HTML déjà
// construit par l'appelant (ex. deux <select> « De »/« à » pour une plage de
// mois, un <input type="date"> + une case à cocher pour ?p=mailing_campagne).
// Pas de marqueur « _set » ni de case « Tout » : ces notions n'ont de sens
// que pour un filtre à cases à cocher (filtre_coche()).
function filtre_colonne_form_html(string $page, array $autresParams, string $contenu, string $libelle = '', bool $actif = false): string
{
    return '<details class="col-filter">'
        . filtre_bouton_html($libelle, $actif)
        . '<form method="get" class="col-filter-menu">'
        . '<input type="hidden" name="p" value="' . e($page) . '">'
        . hidden_inputs_html($autresParams)
        . $contenu
        . '<button type="submit" class="col-filter-apply">Appliquer</button></form></details>';
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

// ------------------------------------------------------------------- PAYS
// Liste de pays configurable (Paramètres → Pays, voir migration_43), partagée
// par tous les champs pays de l'app (structures, lieux, employeur, événements,
// facturation) — un seul point de configuration au lieu d'une saisie libre par
// module. Chaque entrée porte un nom affiché et un code ISO 3166-1 alpha-2
// (pour le drapeau et, côté événements, la valeur stockée).
// Liste des PAYS (racines de la taxonomie pays_liste, voir migration_49) —
// hors régions. Utilisée partout où l'on choisit/affiche un pays (structures,
// lieux, employeur, événements, facturation, drapeaux).
function pays_liste(): array
{
    return db()->query('SELECT * FROM pays_liste WHERE parent_id IS NULL ORDER BY ordre, nom')->fetchAll();
}

// Arbre complet pays + régions (toutes les lignes de pays_liste), pour la page
// de gestion Paramètres → Pays (glisser-déposer à 2 niveaux).
function pays_liste_arbre(): array
{
    return db()->query('SELECT * FROM pays_liste ORDER BY ordre, nom')->fetchAll();
}

// Régions (grandes régions) groupées par NOM de pays parent, ordonnées.
// ['France' => ['Bretagne', 'Normandie', …], 'Suisse' => […], …]. Sert à
// construire les listes déroulantes dépendantes (formulaires) et les optgroups
// des filtres.
function pays_regions_map(): array
{
    $rows = db()->query(
        'SELECT p.nom AS pays, r.nom AS region
         FROM pays_liste r JOIN pays_liste p ON p.id = r.parent_id
         WHERE r.parent_id IS NOT NULL
         ORDER BY p.ordre, p.nom, r.ordre, r.nom'
    )->fetchAll();
    $map = [];
    foreach ($rows as $x) {
        $map[(string) $x['pays']][] = (string) $x['region'];
    }
    return $map;
}

// Garantit qu'une région existe dans la taxonomie sous son pays, la créant au
// besoin (utilisé par l'import : la liste est stricte dans les formulaires, mais
// l'import peut l'enrichir). Sans effet si le pays est inconnu de la liste ou si
// l'un des noms est vide. Cache statique pour éviter les requêtes répétées.
function pays_region_assurer(string $paysNom, string $regionNom): void
{
    $paysNom = trim($paysNom);
    $regionNom = trim($regionNom);
    if ($paysNom === '' || $regionNom === '') { return; }
    static $vues = [];
    $k = $paysNom . "\0" . $regionNom;
    if (isset($vues[$k])) { return; }
    $vues[$k] = true;
    $stmt = db()->prepare('SELECT id FROM pays_liste WHERE nom = ? AND parent_id IS NULL');
    $stmt->execute([$paysNom]);
    $paysId = $stmt->fetchColumn();
    if ($paysId === false) { return; } // pays hors taxonomie : on n'invente pas de pays
    $ex = db()->prepare('SELECT 1 FROM pays_liste WHERE parent_id = ? AND nom = ?');
    $ex->execute([$paysId, $regionNom]);
    if ($ex->fetchColumn()) { return; }
    $ord = db()->prepare('SELECT COALESCE(MAX(ordre),0)+1 FROM pays_liste WHERE parent_id = ?');
    $ord->execute([$paysId]);
    db()->prepare('INSERT OR IGNORE INTO pays_liste (parent_id, nom, code_iso2, ordre) VALUES (?, ?, NULL, ?)')
        ->execute([(int) $paysId, $regionNom, (int) $ord->fetchColumn()]);
}

// Options <option> de régions pour un pays donné (valeur = nom de région).
// Filet de sécurité : une valeur actuelle absente de la taxonomie est ajoutée
// en tête (« non reconnu ») pour ne jamais l'écraser silencieusement.
function region_options_nom(string $paysNom, string $selected): string
{
    $regions = pays_regions_map()[$paysNom] ?? [];
    $h = '';
    if ($selected !== '' && !in_array($selected, $regions, true)) {
        $h .= '<option value="' . e($selected) . '" selected>' . e($selected) . ' (non reconnu)</option>';
    }
    foreach ($regions as $r) {
        $h .= '<option value="' . e($r) . '"' . ($selected === $r ? ' selected' : '') . '>' . e($r) . '</option>';
    }
    return $h;
}

// Cantons suisses non ambigus (français d'un seul côté de la barrière
// linguistique) → grande région déduite avec certitude.
const CANTONS_SUISSES_REGIONS = [
    'GE' => 'Romandie',
    'VD' => 'Romandie', 'NE' => 'Romandie', 'JU' => 'Romandie',
    'TI' => 'Tessin',
    'ZH' => 'Alémanique', 'BS' => 'Alémanique', 'BL' => 'Alémanique',
    'AG' => 'Alémanique', 'SG' => 'Alémanique', 'TG' => 'Alémanique', 'GR' => 'Alémanique',
    'LU' => 'Alémanique', 'ZG' => 'Alémanique', 'SO' => 'Alémanique', 'SH' => 'Alémanique',
    'AR' => 'Alémanique', 'AI' => 'Alémanique', 'GL' => 'Alémanique', 'UR' => 'Alémanique',
    'SZ' => 'Alémanique', 'OW' => 'Alémanique', 'NW' => 'Alémanique',
];
// Cantons bilingues (Fribourg, Valais, Berne) : la région linguistique dépend
// de la commune, pas du canton entier — valeur ci-dessous = simple défaut
// (majorité linguistique), jamais imposée (voir grande_region_deduite() $strict).
const CANTONS_SUISSES_BILINGUES = ['FR' => 'Romandie', 'VS' => 'Romandie', 'BE' => 'Alémanique'];

// Grande région déduite du département (France, via le référentiel officiel
// departements_regions, lib/db.php) ou du canton (Suisse, CANTONS_SUISSES_REGIONS
// ci-dessus). $pays accepte indifféremment un NOM (structures/lieux :
// adresse_pays/pays) ou un CODE ISO2 (événements : pays). $strict (défaut
// true) : ne renvoie JAMAIS de valeur pour un canton bilingue — utilisé
// partout où la déduction est IMPOSÉE (sauvegarde serveur, import, script Dev
// de rattrapage), pour ne jamais écraser une saisie manuelle ambiguë.
// $strict=false ne sert qu'à la suggestion JS du formulaire (défaut
// pré-rempli mais toujours modifiable pour ces 3 cantons). Null si le pays
// n'a pas de règle de déduction, ou si le département/canton n'est pas
// reconnu (jamais de devinette : le champ reste alors saisi à la main).
// $departementsFranceCache : passer departements_regions_map() déjà chargée
// pour éviter une requête SQL par ligne dans une boucle sur plusieurs fiches
// (voir grande_regions_detecter(), lib/dev.php) ; null = requête normale
// (cas courant : un seul appel, ex. sauvegarde d'un formulaire).
function grande_region_deduite(string $pays, string $departementCanton, bool $strict = true, ?array $departementsFranceCache = null): ?string
{
    $pays = trim($pays);
    $departementCanton = trim($departementCanton);
    if ($departementCanton === '') {
        return null;
    }
    if ($pays === 'France' || $pays === 'FR') {
        if ($departementsFranceCache !== null) {
            return $departementsFranceCache[$departementCanton] ?? null;
        }
        $stmt = db()->prepare('SELECT region FROM departements_regions WHERE code = ?');
        $stmt->execute([$departementCanton]);
        $r = $stmt->fetchColumn();
        return $r !== false ? (string) $r : null;
    }
    if ($pays === 'Suisse' || $pays === 'CH') {
        $code = mb_strtoupper($departementCanton, 'UTF-8');
        if (isset(CANTONS_SUISSES_REGIONS[$code])) {
            return CANTONS_SUISSES_REGIONS[$code];
        }
        if (!$strict && isset(CANTONS_SUISSES_BILINGUES[$code])) {
            return CANTONS_SUISSES_BILINGUES[$code];
        }
        return null;
    }
    return null;
}

// Référentiel départements français → région (code => région), pour l'auto-
// remplissage JS de grande_region dans les formulaires (voir _region_select_js.php) —
// même source que grande_region_deduite().
function departements_regions_map(): array
{
    return db()->query('SELECT code, region FROM departements_regions')->fetchAll(PDO::FETCH_KEY_PAIR);
}

// Nom de département français à partir de son code (ex. "74" → "Haute-Savoie")
// — même référentiel departements_regions que ci-dessus. Utilisé pour
// enrichir la requête de géocodage (lib/geocodage.php) : un code seul n'est
// pas reconnu par Nominatim, un nom de département lève l'ambiguïté des
// villes homonymes (ex. plusieurs « Bonneville »). '' si code inconnu/vide.
function departement_nom_depuis_code(string $code): string
{
    $code = trim($code);
    if ($code === '') {
        return '';
    }
    $stmt = db()->prepare('SELECT departement FROM departements_regions WHERE code = ?');
    $stmt->execute([$code]);
    $r = $stmt->fetchColumn();
    return $r !== false ? (string) $r : '';
}

// Nom de pays à partir d'un code ISO2 — inverse de pays_drapeau_nom()/
// pays_options_code(). Nécessaire pour résoudre evenements.pays (stocké en
// code) vers le nom attendu par region_options_nom()/pays_regions_map()
// (clés indexées par nom, comme structures/lieux).
function pays_nom_depuis_code(string $code): string
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (pays_liste() as $p) {
            $map[(string) $p['code_iso2']] = (string) $p['nom'];
        }
    }
    return $map[strtoupper(trim($code))] ?? '';
}

// Émoji drapeau à partir d'un code pays ISO 3166-1 alpha-2 (ex. « CH » → 🇨🇭).
// Vide si le code n'a pas ce format (ex. valeur vide ou non reconnue).
function pays_drapeau(string $code): string
{
    $code = strtoupper(trim($code));
    if (!preg_match('/^[A-Z]{2}$/', $code)) {
        return '';
    }
    $drapeau = '';
    foreach (str_split($code) as $lettre) {
        $drapeau .= mb_chr(127397 + ord($lettre), 'UTF-8');
    }
    return $drapeau;
}

// Drapeau à partir d'un NOM de pays stocké tel quel (structures.adresse_pays,
// lieux.pays, employeur_pays — texte libre historique) — vide si le nom ne
// correspond à aucun pays de la liste configurée (ex. donnée erronée d'un
// import), à l'appelant de retomber sur le texte brut dans ce cas.
function pays_drapeau_nom(string $nom): string
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (pays_liste() as $p) {
            $map[$p['nom']] = $p['code_iso2'];
        }
    }
    return isset($map[$nom]) ? pays_drapeau($map[$nom]) : '';
}

// Options <option> pour un <select> de pays dont la valeur stockée est le NOM
// (structures/lieux/employeur/facturation) — si la valeur actuelle ne
// correspond à aucun pays configuré (donnée libre historique), elle est
// ajoutée en tête pour ne jamais l'écraser silencieusement à l'enregistrement.
function pays_options_nom(string $selected): string
{
    $liste = pays_liste();
    $h = '';
    if ($selected !== '' && !in_array($selected, array_column($liste, 'nom'), true)) {
        $h .= '<option value="' . e($selected) . '" selected>' . e($selected) . ' (non reconnu)</option>';
    }
    foreach ($liste as $p) {
        $h .= '<option value="' . e($p['nom']) . '"' . ($selected === $p['nom'] ? ' selected' : '') . '>' . e($p['nom']) . '</option>';
    }
    return $h;
}

// Options <option> pour un <select> de pays dont la valeur stockée est le CODE
// ISO2 (evenements.pays) — libellé "Nom", valeur "CODE". Même filet de
// sécurité que pays_options_nom() pour un code non reconnu.
function pays_options_code(string $selected): string
{
    $liste = pays_liste();
    $h = '';
    if ($selected !== '' && !in_array($selected, array_column($liste, 'code_iso2'), true)) {
        $h .= '<option value="' . e($selected) . '" selected>' . e($selected) . ' (non reconnu)</option>';
    }
    foreach ($liste as $p) {
        $h .= '<option value="' . e($p['code_iso2']) . '"' . ($selected === $p['code_iso2'] ? ' selected' : '') . '>' . e($p['nom']) . '</option>';
    }
    return $h;
}

// Badge <span> générique (statuts, indicateurs) — factorise le motif répété
// dans evenement_suisa_badge()/evenement_badge_statut() (lib/evenements.php)
// et facturation_badge() (lib/facturation.php). $classe :
// suffixe de couleur ('ok'|'warn'|'muted'|'emise', voir .badge dans
// assets/app.css) ou '' pour le badge neutre par défaut (mauve).
function badge(string $texte, string $classe = ''): string
{
    $cls = $classe !== '' ? ' ' . $classe . '-badge' : '';
    return '<span class="badge' . $cls . '">' . e($texte) . '</span>';
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

        // Variantes pour le mode sombre. Ces quatre valeurs ne peuvent pas être
        // écrites dans assets/app.css : elles dérivent d'une couleur choisie en
        // base (Paramètres → Employeur), et le bloc <style> qui les injecte est
        // émis APRÈS la feuille de style — il l'emporterait donc sur toute
        // media query qui s'y trouverait.
        //
        // Les teintes (L=95, quasi blanches) servent de FOND aux éléments
        // actifs : sur sombre elles doivent devenir des fonds sourds, pas des
        // aplats lumineux. --brand, à l'inverse, sert de couleur de TEXTE et de
        // fond de barre : assombri en clair (L=20), il doit s'éclaircir.
        'primary_tint_sombre' => hsl_vers_hex($h, min($s, 45), 22),
        'brand_sombre'        => hsl_vers_hex($h, min($s + 10, 78), 82),
        'brand_2_sombre'      => hsl_vers_hex($h, min($s + 8, 78), 68),
        // Une couleur d'accent très sombre disparaît sur fond sombre : on lui
        // impose un plancher de luminosité, sans toucher à sa teinte.
        //
        // Plancher à 50 et non plus haut : l'accent sert de FOND à des boutons
        // dont le texte est blanc. Trop éclairci, il satisfait le contraste avec
        // la page mais ruine celui du texte — mesuré à 3,7:1 avec un plancher à
        // 62, sous le seuil de 4,5. Un ton moyen tient les deux contraintes,
        // le fond de page étant lui très sombre.
        'primary_sombre'      => hsl_vers_hex($h, $s, max($l, 50)),
        // La variante « -d » sert au SURVOL. En clair elle assombrit ; sur fond
        // sombre il faut l'inverse, sinon le survol s'enfonce dans le fond — et
        // le texte des badges, qui l'utilise, devenait illisible.
        // Sur seize règles qui l'utilisent, quatorze s'en servent comme COULEUR
        // DE TEXTE sur une teinte (badges, onglets actifs, liens survolés) et
        // deux seulement comme fond de survol. On l'oriente donc vers l'usage
        // dominant : clair, pour être lisible sur les teintes sombres. Les deux
        // fonds de survol sont traités à part dans le bloc sombre d'app.css.
        'primary_d_sombre'    => hsl_vers_hex($h, min($s + 15, 95), 78),
    ];
}

// Bloc <style> qui redéfinit les variables CSS de couleur d'après la couleur
// principale et la couleur de mise en évidence choisies, plus l'image de fond
// personnalisée (page Apparence) — injecté dans <head> (views/layout.php),
// après app.css. Cette dernière remplace la couleur principale à certains
// endroits (boutons principaux, sommes de brut, liens, tags) ; voir
// --highlight* dans app.css pour la liste des règles concernées.
// L'image de fond personnalisée (si configurée) est posée ici en dur
// (body.has-sidebar::before{background-image:...}) plutôt que via une
// variable CSS consommée depuis app.css : une URL relative dans une custom
// property se résout par rapport à la feuille de style où la var() est
// *utilisée*, pas où la propriété est déclarée — utilisée dans app.css
// (assets/app.css), "uploads/…" se serait résolu en "assets/uploads/…"
// (inexistant) au lieu de "uploads/…" à la racine du site. Ici, dans le
// <style> inline de la page elle-même, l'URL relative se résout normalement
// par rapport à la page — comme param_fond()/param_logo() partout ailleurs
// (<img src="uploads/…">). L'effet clair/flouté (filter) est posé sur ce
// même ::before, jamais sur <body> lui-même — voir le commentaire dans
// app.css (body.has-sidebar::before). Sans image personnalisée (cas par
// défaut), rien n'est émis ici : le fond calculé (.wave-decor, voir
// views/_wave_decor.php) prend le relais, posé par views/layout.php.
// Émet les deux blocs qui appliquent une palette sombre : l'un sous la media
// query (mode « automatique »), l'autre sur l'attribut data-theme (choix
// explicite). $valeurs : tokens réels => valeur sombre.
//
// Deux blocs et non un seul parce qu'aucun sélecteur CSS ne combine une media
// query et un attribut ; la liste est donc construite ici, une fois, pour que
// les deux versions ne puissent pas diverger. Le :not([data-theme="clair"])
// est indispensable : sans lui, un thème clair choisi explicitement perdrait
// face à un système réglé en sombre.
// Thème choisi dans Paramètres → Apparence : 'auto' (suit le système), 'clair'
// ou 'sombre'. Réglage commun à l'installation, comme les couleurs et le fond
// — voir docs/DECISIONS.md § Thème.
function param_theme(): string
{
    $t = (string) param('employeur_theme', 'auto');
    return in_array($t, ['auto', 'clair', 'sombre'], true) ? $t : 'auto';
}

function css_palette_sombre(array $valeurs): string
{
    $decl = '';
    foreach ($valeurs as $token => $valeur) {
        $decl .= $token . ':' . $valeur . ';';
    }
    return '@media (prefers-color-scheme: dark){:root:not([data-theme="clair"]){' . $decl . '}}'
        . ':root[data-theme="sombre"]{' . $decl . '}';
}

function couleurs_css_vars(): string
{
    $c = couleurs_derivees((string) param('employeur_couleur_principale', '#6d4ade'));
    $h = couleurs_derivees((string) param('employeur_couleur_evidence', '#2563eb'));
    $fondPerso = (string) param('employeur_fond', '');
    $styleFond = '';
    if ($fondPerso !== '') {
        // Effets combinables (voir param_fond_clair()/param_fond_floute()) :
        // les fonctions filter s'enchaînent dans une seule déclaration,
        // chacune s'appliquant sur le résultat de la précédente.
        $filtres = [];
        if (param_fond_clair()) { $filtres[] = 'contrast(.1) brightness(2)'; }
        if (param_fond_floute()) { $filtres[] = 'blur(10px)'; }
        $filtreFond = $filtres ? 'filter:' . implode(' ', $filtres) . ';' : '';
        $styleFond = 'body.has-sidebar::before{background-image:url(' . json_encode($fondPerso) . ');' . $filtreFond . '}';
    }
    // --primary-base : même valeur que --primary, mais que
    // module_couleur_css_vars() ne réécrit JAMAIS. Sert là où il faut la
    // couleur principale de l'employeur telle quelle, indépendamment du module
    // consulté — l'icône du tableau de bord (views/layout.php), qui n'appartient
    // à aucun module et doit rester un repère de teinte constante.
    // Bloc clair, puis surcharge sombre. L'ordre compte : la media query vient
    // après, elle l'emporte donc à spécificité égale quand elle s'applique.
    $clair = ':root{--primary:' . $c['primary'] . ';--primary-d:' . $c['primary_d']
        . ';--primary-tint:' . $c['primary_tint'] . ';--primary-rgb:' . $c['primary_rgb']
        . ';--primary-base:' . $c['primary']
        . ';--brand:' . $c['brand'] . ';--brand-2:' . $c['brand_2']
        . ';--highlight:' . $h['primary'] . ';--highlight-d:' . $h['primary_d']
        . ';--highlight-tint:' . $h['primary_tint'] . ';--highlight-rgb:' . $h['primary_rgb'] . ';}';

    $sombre = css_palette_sombre([
        '--primary'        => $c['primary_sombre'],
        '--primary-base'   => $c['primary_sombre'],
        '--primary-d'      => $c['primary_d_sombre'],
        '--primary-tint'   => $c['primary_tint_sombre'],
        '--brand'          => $c['brand_sombre'],
        '--brand-2'        => $c['brand_2_sombre'],
        '--highlight'      => $h['primary_sombre'],
        '--highlight-d'    => $h['primary_d_sombre'],
        '--highlight-tint' => $h['primary_tint_sombre'],
    ]);

    return '<style>' . $clair . $sombre . $styleFond . '</style>';
}

// Bloc <style> qui surcharge --primary*/--brand* (voir couleurs_css_vars()
// ci-dessus) avec la couleur fixe du module actif (MODULE_COULEURS,
// lib/modules.php) — injecté juste après couleurs_css_vars() (views/layout.php),
// donc gagne en cascade. $module : clé de nav_groupes()/nav_groupe_actif(),
// ou null hors d'un module (tableau de bord, Paramètres — pas de surcharge,
// la couleur principale de l'employeur s'applique normalement).
// --highlight* (« couleur de mise en évidence », réglage Employeur séparé)
// n'est volontairement PAS touché : il reste la même partout, quel que soit
// le module — seuls --primary* (icône active du rail, focus, accents
// secondaires) et --brand*/--brand-2 (titre de page en dégradé, voir h1 dans
// app.css) suivent la couleur du module. Le rail lui-même (fond de .sidebar)
// ne dépend plus de --brand — voir le commentaire sur .sidebar dans app.css —
// donc cette surcharge ne le fait pas changer de couleur.
function module_couleur_css_vars(?string $module): string
{
    if ($module === null || !isset(MODULE_COULEURS[$module])) {
        return '';
    }
    $c = couleurs_derivees(MODULE_COULEURS[$module]);
    // Même dédoublement clair/sombre que couleurs_css_vars() : sans lui, la
    // couleur du module réintroduirait une teinte quasi blanche en fond des
    // éléments actifs sur un thème sombre.
    return '<style>:root{--primary:' . $c['primary'] . ';--primary-d:' . $c['primary_d']
        . ';--primary-tint:' . $c['primary_tint'] . ';--primary-rgb:' . $c['primary_rgb']
        . ';--brand:' . $c['brand'] . ';--brand-2:' . $c['brand_2'] . ';}'
        . css_palette_sombre([
            '--primary'      => $c['primary_sombre'],
            '--primary-d'    => $c['primary_d_sombre'],
            '--primary-tint' => $c['primary_tint_sombre'],
            '--brand'        => $c['brand_sombre'],
            '--brand-2'      => $c['brand_2_sombre'],
        ])
        . '</style>';
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

// <option> d'un <select> mois, avec un repli vide (« — », valeur '') distinct
// de « Tous » (0) utilisé dans les filtres — ici une valeur absente doit
// pouvoir être enregistrée telle quelle (colonne NULL), pas juste ignorée en
// filtrage. Voir la carte « Période » de ?p=structure (structure_form.php).
function mois_options(int $selectionne): string
{
    $h = '<option value=""' . ($selectionne === 0 ? ' selected' : '') . '>—</option>';
    for ($m = 1; $m <= 12; $m++) {
        $h .= '<option value="' . $m . '"' . ($selectionne === $m ? ' selected' : '') . '>' . e(mois_nom($m)) . '</option>';
    }
    return $h;
}

// Abrégé 3 lettres majuscules (JAN, FÉV… JUN/JUL distincts de juin/juillet,
// contrairement à une simple troncature) — puce de date façon calendrier.
const MOIS_ABREGES_FR = [
    1 => 'JAN', 2 => 'FÉV', 3 => 'MAR', 4 => 'AVR', 5 => 'MAI', 6 => 'JUN',
    7 => 'JUL', 8 => 'AOÛ', 9 => 'SEP', 10 => 'OCT', 11 => 'NOV', 12 => 'DÉC',
];

function mois_abrege(int $m): string
{
    return MOIS_ABREGES_FR[$m] ?? '';
}

// Chemin web du logo employeur ('clair' fond clair, 'sombre' fond sombre) ou '' si non défini.
function param_logo(string $variant): string
{
    $cle = $variant === 'sombre' ? 'employeur_logo_sombre' : 'employeur_logo_clair';
    return (string) param($cle, '');
}

// Représentation data: URI (base64) du logo employeur — utilisée pour le logo
// du rail (views/layout.php, .side-logo) afin d'éviter une requête HTTP
// séparée à chaque navigation. Chaque clic dans l'appli recharge la page
// entière (pas de SPA) ; en dev, php -S (voir CLAUDE.md) n'envoie aucun
// en-tête de cache pour les fichiers statiques (vérifié : ni Last-Modified,
// ni Cache-Control), donc l'image était re-téléchargée à chaque clic — visible
// comme un flash de son texte alt le temps du chargement. Inliner l'image
// dans le HTML supprime cette requête, donc ce flash, quelle que soit la
// configuration de cache du serveur (dev ou prod). Repli sur le chemin web
// classique si le fichier est illisible (pas d'image cassée).
// Cache disque d'une chaîne coûteuse à produire, invalidé par la source.
//
// Motivation : les deux fonctions de data-URI ci-dessous s'exécutaient à CHAQUE
// affichage de page (elles ne servent que dans views/layout.php, présent
// partout). asset_data_uri_mini() décode un PNG de 612×553, le rééchantillonne
// via GD, le réencode et le passe en base64 — pour un badge affiché à 12px.
// Le résultat ne change que si le fichier source change : le recalculer à
// chaque requête est du travail pur perdu, particulièrement sur mutualisé.
//
// La signature inclut mtime ET taille de chaque source : un fichier remplacé
// dans la même seconde (mtime identique) mais de taille différente invalide
// quand même l'entrée. Les entrées devenues obsolètes (même clé, autre
// signature) sont supprimées à l'écriture — sans quoi chaque changement de logo
// laisserait un fichier orphelin derrière lui.
//
// Le cache vit à côté de la base (dirname(APP_DB_PATH)) et non dans le dépôt :
// il suit ainsi APP_DB_PATH hors racine web en production, et bénéficie des
// protections déjà posées sur ce dossier. Toute défaillance d'écriture est
// silencieuse et sans conséquence : on renvoie la valeur calculée.
function cache_disque(string $cle, array $fichiersSources, callable $produire): string
{
    $sig = [];
    foreach ($fichiersSources as $f) {
        $sig[] = @filemtime($f) . ':' . @filesize($f);
    }
    $dir     = dirname(APP_DB_PATH) . '/cache';
    $empreinte = substr(hash('sha256', implode('|', $sig)), 0, 16);
    $fichier = $dir . '/' . $cle . '-' . $empreinte . '.txt';

    $cache = @file_get_contents($fichier);
    if ($cache !== false) {
        return $cache;
    }

    $valeur = (string) $produire();

    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
        proteger_dossier_donnees($dir);
    }
    foreach ((array) @glob($dir . '/' . $cle . '-*.txt') as $obsolete) {
        @unlink($obsolete);
    }
    // Écriture atomique : un fichier temporaire puis rename(), pour qu'une
    // requête concurrente ne puisse jamais lire un contenu tronqué.
    $tmp = $fichier . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $valeur) !== false) {
        @rename($tmp, $fichier);
    }
    return $valeur;
}

// Source à utiliser pour AFFICHER un logo employeur.
//
// En production : l'URL du fichier. L'hébergeur le sert avec
// « cache-control: max-age=31536000, immutable » (voir .htaccess), et son nom
// porte une empreinte (handle_logo_upload()) — un remplacement change donc
// l'URL. Le navigateur le télécharge une fois, puis plus jamais.
//
// En développement : le data-URI. Le serveur intégré de PHP n'envoie aucun
// en-tête de cache, et le logo clignotait à chaque navigation.
//
// Pourquoi ce détour plutôt que d'inliner partout : mesuré sur une page de 100
// structures, les logos inlinés pesaient 21 Ko une fois la page compressée, soit
// 52 % de son poids — et sur CHAQUE page. Le base64 gonfle de 33 % et ne se
// compresse pas, alors que le reste du balisage, très répétitif, se réduit à
// presque rien. Le thème sombre avait doublé la note en rendant deux variantes.
function param_logo_src(string $variant): string
{
    return APP_ENV === 'dev' ? param_logo_data_uri($variant) : param_logo($variant);
}

function param_logo_data_uri(string $variant): string
{
    $chemin = param_logo($variant);
    if ($chemin === '') {
        return '';
    }
    $fs = realpath(__DIR__ . '/../' . $chemin) ?: (__DIR__ . '/../' . $chemin);
    if (!is_file($fs) || !is_readable($fs)) {
        return $chemin;
    }
    return cache_disque('logo-' . $variant, [$fs], function () use ($fs, $chemin) {
        $dims = @getimagesize($fs);
        $data = @file_get_contents($fs);
        if ($data === false) {
            return $chemin;
        }
        return 'data:' . ((string) ($dims['mime'] ?? 'image/png')) . ';base64,' . base64_encode($data);
    });
}

// Variante de param_logo_data_uri() pour un petit badge d'image FIXE du dépôt
// (pas un logo employeur configurable) — ex. le logo Lasso du pied du rail
// (views/layout.php, .side-powered-logo), affiché à 12px de haut alors que le
// fichier source (assets/lasso.png) fait 612×553px (~190 Ko) : l'inliner tel
// quel ajouterait ~250 Ko en base64 à CHAQUE page (pas de SPA). Redimensionné
// via GD avant l'encodage — quelques Ko au lieu de ~250 Ko, sans avoir à
// committer un second fichier image à maintenir en plus de l'original.
// $hauteurPx : hauteur cible en pixels réels (prévoir 2-3× la hauteur CSS
// affichée pour rester net sur les écrans haute densité).
function asset_data_uri_mini(string $cheminRelatif, int $hauteurPx): string
{
    $fs = __DIR__ . '/../' . $cheminRelatif;
    if (!is_file($fs) || !is_readable($fs) || !extension_loaded('gd')) {
        return $cheminRelatif;
    }
    // La hauteur cible fait partie de la clé : deux appels sur la même source
    // à des tailles différentes ne doivent pas se marcher dessus.
    return cache_disque(
        'mini-' . preg_replace('/[^a-z0-9]+/i', '_', $cheminRelatif) . '-' . $hauteurPx,
        [$fs],
        fn () => asset_data_uri_mini_calculer($fs, $cheminRelatif, $hauteurPx)
    );
}

function asset_data_uri_mini_calculer(string $fs, string $cheminRelatif, int $hauteurPx): string
{
    $src = @imagecreatefrompng($fs);
    if (!$src) {
        return $cheminRelatif;
    }
    $w = imagesx($src);
    $h = imagesy($src);
    if ($h <= 0) {
        return $cheminRelatif;
    }
    $nH = $hauteurPx;
    $nW = max(1, (int) round($w * $nH / $h));
    $dst = imagecreatetruecolor($nW, $nH);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nW, $nH, $w, $h);
    // Pas d'imagedestroy() : sans effet depuis PHP 8.0 (GC gère les ressources
    // GD), déprécié en 8.5 — un appel émettrait un warning imprimé directement
    // dans le flux de sortie, corrompant l'attribut src= au milieu du rendu.
    ob_start();
    imagepng($dst);
    $data = ob_get_clean();
    if ($data === false || $data === '') {
        return $cheminRelatif;
    }
    return 'data:image/png;base64,' . base64_encode($data);
}

// Chemin web de l'image de fond de l'application (page Apparence) — celle
// uploadée (uploads/…) si personnalisée, sinon le fond par défaut du dépôt.
function param_fond(): string
{
    $p = (string) param('employeur_fond', '');
    return $p !== '' ? $p : 'assets/fond.jpg';
}

// Effets appliqués à l'image de fond (page Apparence, cases à cocher
// combinables — les deux peuvent être actives en même temps) : 'clair'
// (adoucie/éclaircie, meilleure lisibilité) et 'floute'.
function param_fond_clair(): bool
{
    return (string) param('employeur_fond_clair', '') === '1';
}
function param_fond_floute(): bool
{
    return (string) param('employeur_fond_floute', '') === '1';
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

// Convertit en UTF-8 un contenu de fichier importé dont l'encodage n'est pas
// déjà de l'UTF-8 valide — cas fréquent d'un carnet d'adresses/tableur
// exporté « CSV » sous Windows sans choix explicite d'encodage (Windows-1252
// par défaut) : sans cette conversion, tout caractère accentué (header ou
// donnée) devient une séquence UTF-8 invalide, silencieusement cassée par
// la suite de la chaîne (mb_strtolower(), json_encode(), écriture SQLite…) —
// symptôme observé : les colonnes/valeurs accentuées ne sont pas reconnues.
function normaliser_encodage_utf8(string $s): string
{
    if (str_starts_with($s, "\xFF\xFE")) {
        return (string) mb_convert_encoding(substr($s, 2), 'UTF-8', 'UTF-16LE');
    }
    if (str_starts_with($s, "\xFE\xFF")) {
        return (string) mb_convert_encoding(substr($s, 2), 'UTF-8', 'UTF-16BE');
    }
    if (mb_check_encoding($s, 'UTF-8')) {
        return $s;
    }
    return (string) mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
}

// Lit le fichier téléversé sous le champ "fichier" (import fiches/écritures/
// événements/factures/structures), avec repli sur un contenu déjà mémorisé en
// session (permet de cliquer « Importer » après « Simuler » sans re-téléverser)
// — factorise le motif dupliqué dans les routes d'import. $sessionNomKey
// optionnel : nom du fichier original à relire depuis la session avec le
// contenu (sinon 'nom' reste vide au repli session). Le contenu est toujours
// normalisé en UTF-8 (voir normaliser_encodage_utf8()) avant d'être retourné
// ou mémorisé, donc le repli session n'a pas besoin de reconvertir.
// Retourne ['contenu' => ?string, 'nom' => string, 'err' => ?string].
function lire_fichier_importe(
    int $maxOctets,
    string $msgTropGros,
    string $sessionKey,
    string $msgAucunFichier,
    ?string $sessionNomKey = null
): array {
    $up = $_FILES['fichier'] ?? null;
    if ($up && ($up['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        if (($up['size'] ?? 0) > $maxOctets) {
            return ['contenu' => null, 'nom' => '', 'err' => $msgTropGros];
        }
        $contenu = normaliser_encodage_utf8((string) file_get_contents($up['tmp_name']));
        return ['contenu' => $contenu, 'nom' => (string) $up['name'], 'err' => null];
    }
    if (!empty($_POST['depuis_session']) && !empty($_SESSION[$sessionKey])) {
        $nom = $sessionNomKey !== null ? (string) ($_SESSION[$sessionNomKey] ?? 'import') : '';
        return ['contenu' => (string) $_SESSION[$sessionKey], 'nom' => $nom, 'err' => null];
    }
    return ['contenu' => null, 'nom' => '', 'err' => $msgAucunFichier];
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

// Titre de la page d'édition d'un événement (« Date : Spectacle, Ville »,
// parties absentes proprement omises) — même champs que evenement_label_court()
// mais mise en forme propre à cette page (« : » et virgule, pas « — »/parenthèses
// déjà utilisés ailleurs pour les liens de retour contextuels et les tableaux
// de l'onglet Incohérences — format volontairement distinct, à ne pas fusionner).
function evenement_titre_page(array $ev): string
{
    $titre = (string) ($ev['date'] ?? '') !== '' ? date('d.m.Y', strtotime((string) $ev['date'])) : '';
    $reste = array_filter([$ev['spectacle_nom'] ?? null, $ev['ville'] ?? null], fn ($v) => trim((string) $v) !== '');
    if ($reste) {
        $titre .= ($titre !== '' ? ' : ' : '') . implode(', ', $reste);
    }
    return $titre !== '' ? $titre : 'Événement';
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

// Suffixe "&q=...&page=..." pour le lien d'une ligne de liste (lieux/
// structures/événements/factures) vers sa fiche détail — recherche texte et
// page de pagination ne sont jamais mémorisées en session (voir
// lien_retour_contextuel()), donc perdues au retour sans ce report explicite
// dans l'URL de la fiche elle-même.
function suffixe_retour_liste(string $recherche, int $pgPage): string
{
    $extra = [];
    if ($recherche !== '') { $extra['q'] = $recherche; }
    if ($pgPage > 1) { $extra['page'] = $pgPage; }
    return $extra ? '&' . http_build_query($extra) : '';
}

// Lien « retour » contextuel : si la page a été atteinte via un lien croisé
// inter-module portant ?depuis=type:id (voir url_avec_retour()), pointe vers
// cet objet précis avec son libellé actuel plutôt que vers la liste générique.
function lien_retour_contextuel(string $defautHref, string $defautLabel): string
{
    $depuis = (string) ($_GET['depuis'] ?? '');
    // Recherche texte (q) et page de pagination (page) : ni l'une ni l'autre
    // n'est mémorisée en session (filtre_persistant() ne les couvre pas, voir
    // evenements_lire_filtres()/structures_filtres()/pagination_page()), donc
    // perdues au retour si on ne les reporte pas explicitement — contrairement
    // aux filtres structurés (type/ville/statut…) déjà repris via la session.
    // Portée aux seules cibles « liste » ci-dessous (statiques + $defautHref) :
    // une fiche précise (structure:id…) n'a pas ces champs à remplir.
    $q = trim((string) ($_GET['q'] ?? ''));
    $page = (int) ($_GET['page'] ?? 0);
    $extra = [];
    if ($q !== '') { $extra['q'] = $q; }
    if ($page > 1) { $extra['page'] = $page; }
    $avecExtras = fn (string $href): string => $extra ? $href . (str_contains($href, '?') ? '&' : '?') . http_build_query($extra) : $href;
    // Recherche unifiée : traité à part des cibles statiques ci-dessous parce
    // que son libellé rappelle le terme cherché (« Recherche « hector » »),
    // pour que le lien retour dise où il ramène. Le terme lui-même revient par
    // $avecExtras, qui reporte déjà ?q= — sans quoi on retomberait sur une page
    // de recherche vide, ce qui obligerait à ressaisir la requête.
    if ($depuis === 'recherche') {
        return lien_retour(
            $avecExtras('?p=recherche'),
            $q !== '' ? 'Recherche « ' . $q . ' »' : 'Recherche'
        );
    }
    // Cibles sans id propre (page/liste, pas un objet précis) — le filtrage
    // actif (compte/année/catégorie…) est repris automatiquement au retour
    // via filtre_persistant() (session), pas besoin de l'encoder dans l'URL.
    $statiques = [
        'dashboard'        => ['?p=resumes', 'Tableau de bord'],
        'compta_ecritures' => ['?p=compta_ecritures', 'Écritures'],
        'structures'       => ['?p=structures', 'Structures'],
        // Structures est partagée par 3 groupes de nav (voir nav_groupe_actif())
        // — structures_liste.php pose depuis=<groupe> (pas depuis=structures)
        // sur ses liens vers une structure, pour que nav_groupe_actif() y
        // mette en surbrillance le bon groupe. Sans ces 3 entrées, un tel
        // depuis ne correspondait à rien ici et retombait sur le
        // '?p=structures' générique ci-dessus, perdant le groupe de
        // provenance sur le lien retour.
        'booking'          => ['?p=structures&depuis=booking', 'Structures'],
        'facturation'      => ['?p=structures&depuis=facturation', 'Structures'],
        'evenements'       => ['?p=structures&depuis=evenements', 'Structures'],
    ];
    if (isset($statiques[$depuis])) {
        return lien_retour($avecExtras($statiques[$depuis][0]), $statiques[$depuis][1]);
    }
    if (preg_match('/^(facture|evenement|fiche|employe|structure):(\d+)$/', $depuis, $m)) {
        $id = (int) $m[2];
        if ($m[1] === 'structure') {
            $stmt = db()->prepare('SELECT nom FROM structures WHERE id = ?');
            $stmt->execute([$id]);
            $nom = $stmt->fetchColumn();
            if ($nom !== false) {
                return lien_retour('?p=structure&id=' . $id, (string) $nom);
            }
        } elseif ($m[1] === 'employe') {
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
    return lien_retour($avecExtras($defautHref), $defautLabel);
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
    $in   = sql_in($ids);
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
    $in   = sql_in($ecritureIds);
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
        $in = sql_in($ids);
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

// --- Préférences d'affichage propres à un compte (migration_74) -------------
// Le dernier choix fait dans un widget, par exemple. Survit à la déconnexion,
// contrairement aux filtres de liste qui vivent en session (filtre_persistant()).
// Sans utilisateur connecté (tâche planifiée, route à jeton), lire rend le
// défaut et écrire ne fait rien : ces routes n'ont personne à qui associer un
// choix.
function preference(string $cle, string $defaut = ''): string
{
    $u = current_user();
    if (!$u) {
        return $defaut;
    }
    $stmt = db()->prepare('SELECT valeur FROM utilisateur_preferences WHERE utilisateur_id = ? AND cle = ?');
    $stmt->execute([(int) $u['id'], $cle]);
    $v = $stmt->fetchColumn();
    return $v === false ? $defaut : (string) $v;
}

function preference_definir(string $cle, string $valeur): void
{
    $u = current_user();
    if (!$u) {
        return;
    }
    db()->prepare("INSERT OR REPLACE INTO utilisateur_preferences (utilisateur_id, cle, valeur, maj_le)
                   VALUES (?, ?, ?, datetime('now'))")
        ->execute([(int) $u['id'], $cle, $valeur]);
}

// Adresse de réponse d'un envoi applicatif : celle configurée dans
// Paramètres → E-mails (« Adresse de réponse »), à défaut l'expéditeur. Le
// paramètre existait, s'annonçait comme un reply-to à l'écran… et n'était posé
// nulle part : les réponses revenaient toujours à la boîte d'expédition.
function email_repondre_a(string $expediteur): string
{
    $contact = trim((string) param('employeur_email_contact'));
    return filter_var($contact, FILTER_VALIDATE_EMAIL) ? $contact : $expediteur;
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
        'Reply-To: ' . email_repondre_a($expediteur),
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

// Profil SMTP d'un envoi de mailing. Trois niveaux, du plus précis au plus
// général, chaque champ vide passant la main au suivant :
//   1. la boîte de l'expéditeur choisi (table mailing_expediteurs) — deux
//      expéditeurs peuvent vivre chez deux hébergeurs différents ;
//   2. le profil booking hérité (paramètres smtp_booking_*), pour les
//      installations qui n'avaient qu'une boîte de mailing ;
//   3. le profil général (smtp_config()), celui des salaires et des factures.
// Voir SPEC_BOOKING.md §7.
function smtp_config_booking(?array $expediteur = null): array
{
    $general = smtp_config();
    $val = function (string $cleExpediteur, string $cleParam, string $defaut) use ($expediteur): string {
        $v = trim((string) ($expediteur[$cleExpediteur] ?? ''));
        if ($v !== '') {
            return $v;
        }
        $v = (string) param($cleParam, '');
        return $v !== '' ? $v : $defaut;
    };
    return [
        'host'   => $val('smtp_host', 'smtp_booking_host', $general['host']),
        'port'   => (int) $val('smtp_port', 'smtp_booking_port', (string) $general['port']),
        'secure' => $val('smtp_secure', 'smtp_booking_secure', $general['secure']) === 'tls' ? 'tls' : 'ssl',
        'user'   => $val('smtp_user', 'smtp_booking_user', $general['user']),
        'pass'   => $val('smtp_pass', 'smtp_booking_pass', $general['pass']),
    ];
}

// Envoi d'un mailing booking : contrairement à envoyer_email(), **jamais** de
// repli sur mail() — le débit d'envoi (délai/plafond configurés) ne peut être
// maîtrisé que via SMTP, cf. SPEC_BOOKING.md §7. En dev, journalisé comme les
// autres e-mails plutôt qu'expédié. Retourne [bool succès, string mode].
// $expediteur : ligne de mailing_expediteurs (la boîte choisie) ou null pour
// l'expéditeur général — c'est elle qui décide À LA FOIS de l'adresse affichée
// et du serveur par lequel l'e-mail part.
//
// $copieExpediteur : ajoute l'expéditeur en copie cachée, pour qu'il garde une
// trace du message dans sa boîte. Réservé aux messages écrits un par un
// (bouton « Contacter » d'une fiche structure) — SURTOUT PAS aux campagnes,
// qui en enverraient une copie par destinataire. Un envoi SMTP ne se range pas
// tout seul dans le dossier « Envoyés » : ce dossier est une notion IMAP, que
// seul un logiciel de messagerie alimente. La copie arrive donc dans la boîte
// de réception, pas dans les envoyés.
function envoyer_mailing_email(string $destinataire, ?array $expediteur, string $sujet, string $corps, bool $copieExpediteur = false): array
{
    $from = mailing_expediteur_from($expediteur);
    $adresseExpediteur = trim((string) ($expediteur['email'] ?? param('employeur_email_expediteur')));
    $copie = $copieExpediteur && $adresseExpediteur !== '' && strcasecmp($adresseExpediteur, $destinataire) !== 0;
    if (APP_ENV === 'dev') {
        $log = dirname(APP_DB_PATH) . '/emails_envoyes.log';
        @file_put_contents($log, '[' . date('c') . "] Mailing To: $destinataire | De: $from"
            . ($copie ? " | Cci: $adresseExpediteur" : '') . " | $sujet\n", FILE_APPEND);
        return [true, 'local'];
    }
    $cfg = smtp_config_booking($expediteur);
    if ($cfg['user'] === '') {
        error_log('[app] Mailing : SMTP non configuré (écran E-mails, profil booking ou général).');
        return [false, 'smtp'];
    }
    $sujetEnc = '=?UTF-8?B?' . base64_encode($sujet) . '?=';
    $entetes = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . $from,
        // Les réponses reviennent à la boîte qui a écrit, pas à l'adresse de
        // réponse générale : un mailing part au nom d'une équipe précise.
        'Reply-To: ' . $from,
    ]);
    $message = 'To: ' . $destinataire . "\r\n" . 'Subject: ' . $sujetEnc . "\r\n" . $entetes . "\r\n\r\n" . $corps;
    // La copie passe par l'enveloppe seulement : aucun en-tête « Bcc: » dans le
    // message, sinon le destinataire la lirait.
    $adresses = $copie ? [$destinataire, $adresseExpediteur] : $destinataire;
    return [smtp_transmettre($cfg, $adresses, $message), 'smtp'];
}

// Transmet un message brut déjà complet (en-têtes To/Subject/… + ligne vide + corps)
// par SMTP authentifié, en PHP pur (aucune dépendance). Gère SSL implicite
// (port 465) ou STARTTLS (port 587), AUTH LOGIN. Appelé par envoyer_email(),
// commun à tous les e-mails applicatifs (fiches simples, factures avec pièce jointe).
// $to : une adresse, ou plusieurs (destinataires d'ENVELOPPE — un RCPT TO
// chacun). Une adresse ajoutée ici et absente des en-têtes est exactement ce
// qu'est une copie cachée : le serveur la sert, le message ne la nomme pas.
function smtp_transmettre(array $cfg, string|array $to, string $message): bool
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
    foreach ((array) $to as $adresse) {
        if ($cmd('RCPT TO:<' . $adresse . '>', '250') !== true) return false;
    }
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
            // Pas de ligne fiche_lignes réelle (fiche sans lignes personnalisées) :
            // id=0, une valeur qu'aucune vraie ligne ne prend jamais (voir
            // route_fiche_ligne_axe_save(), qui exige un id existant en base) —
            // affiche/désactive silencieusement l'édition d'axe pour cette ligne
            // implicite plutôt que déclencher un "Undefined array key" (_fiche_body.php).
            'id'            => 0,
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
// Table des dessins d'icônes (jeu Lucide), partagée par icon() et par le
// sprite. Séparée d'icon() pour que les deux lisent la même source : une icône
// ajoutée ici est aussitôt disponible aux deux rendus.
function icone_table(): array
{
    return [
        'file-text' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/>',
        'receipt-swiss-franc' => '<path d="M10 11h4"/><path d="M10 17V7h5"/><path d="M4 3a1 1 0 0 1 1-1 1.3 1.3 0 0 1 .7.2l.933.6a1.3 1.3 0 0 0 1.4 0l.934-.6a1.3 1.3 0 0 1 1.4 0l.933.6a1.3 1.3 0 0 0 1.4 0l.933-.6a1.3 1.3 0 0 1 1.4 0l.934.6a1.3 1.3 0 0 0 1.4 0l.933-.6A1.3 1.3 0 0 1 19 2a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1 1.3 1.3 0 0 1-.7-.2l-.933-.6a1.3 1.3 0 0 0-1.4 0l-.934.6a1.3 1.3 0 0 1-1.4 0l-.933-.6a1.3 1.3 0 0 0-1.4 0l-.933.6a1.3 1.3 0 0 1-1.4 0l-.934-.6a1.3 1.3 0 0 0-1.4 0l-.933.6a1.3 1.3 0 0 1-.7.2 1 1 0 0 1-1-1z"/><path d="M8 15h5"/>',
        'building-2' => '<path d="M10 12h4"/><path d="M10 8h4"/><path d="M14 21v-3a2 2 0 0 0-4 0v3"/><path d="M6 10H4a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-2"/><path d="M6 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16"/>',
        'house'      => '<path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/><path d="M3 10a2 2 0 0 1 .709-1.528l7-6a2 2 0 0 1 2.582 0l7 6A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'house-plus' => '<path d="M12.35 21H5a2 2 0 0 1-2-2v-9a2 2 0 0 1 .71-1.53l7-6a2 2 0 0 1 2.58 0l7 6A2 2 0 0 1 21 10v2.35"/><path d="M14.8 12.4A1 1 0 0 0 14 12h-4a1 1 0 0 0-1 1v8"/><path d="M15 18h6"/><path d="M18 15v6"/>',
        'landmark' => '<path d="M10 18v-7"/><path d="M11.119 2.205a2 2 0 0 1 1.762 0l7.84 3.846A.5.5 0 0 1 20.5 7h-17a.5.5 0 0 1-.22-.949z"/><path d="M14 18v-7"/><path d="M18 18v-7"/><path d="M3 22h18"/><path d="M6 18v-7"/>',
        'bar-chart' => '<path d="M3 3v18h18"/><rect x="7" y="10" width="3" height="8" rx="1"/><rect x="12" y="6" width="3" height="12" rx="1"/><rect x="17" y="13" width="3" height="5" rx="1"/>',
        'layers'    => '<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>',
        'users'     => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        // Choix de thème (Paramètres → Apparence).
        'sun'       => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/>',
        'moon'      => '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9"/>',
        'monitor'   => '<rect width="20" height="14" x="2" y="3" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/>',
        'settings'  => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
        'menu'      => '<line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/>',
        'x'         => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'list-x'    => '<path d="M11 12H3"/><path d="M16 6H3"/><path d="M16 18H3"/><path d="m19 10-4 4"/><path d="m15 10 4 4"/>',
        'building'  => '<rect width="16" height="20" x="4" y="2" rx="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M12 6h.01"/><path d="M12 10h.01"/><path d="M12 14h.01"/><path d="M16 10h.01"/><path d="M16 14h.01"/><path d="M8 10h.01"/><path d="M8 14h.01"/>',
        'blocks'    => '<path d="M10 22V7a1 1 0 0 0-1-1H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-5a1 1 0 0 0-1-1H2"/><rect x="14" y="2" width="8" height="8" rx="1"/>',
        'user'      => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'percent'   => '<line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
        'printer'   => '<polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>',
        'eye'       => '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
        'eye-off'   => '<path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49"/><path d="M14.084 14.158a3 3 0 0 1-4.242-4.242"/><path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143"/><path d="m2 2 20 20"/>',
        'arrow-left' => '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
        'pencil'    => '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
        'trash'     => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/>',
        'download'  => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
        'banknote'  => '<rect width="20" height="12" x="2" y="6" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01"/><path d="M18 12h.01"/>',
        'clock'     => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'chevron'   => '<polyline points="6 9 12 15 18 9"/>',
        'mail'      => '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
        'mail-x'    => '<path d="M22 13V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v12c0 1.1.9 2 2 2h9"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/><path d="m17 17 4 4"/><path d="m21 17-4 4"/>',
        'check'     => '<polyline points="20 6 9 17 4 12"/>',
        'save'      => '<path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/>',
        'user-plus' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/>',
        'file-plus' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" x2="12" y1="18" y2="12"/><line x1="9" x2="15" y1="15" y2="15"/>',
        'upload'    => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/>',
        'import'    => '<path d="M12 3v12"/><path d="m8 11 4 4 4-4"/><path d="M8 5H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-4"/>',
        'tag'       => '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/>',
        'chevron-up'    => '<path d="m18 15-6-6-6 6"/>',
        'chevron-down'  => '<path d="m6 9 6 6 6-6"/>',
        'chevron-left'  => '<path d="m15 18-6-6 6-6"/>',
        'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
        'plus'      => '<path d="M5 12h14"/><path d="M12 5v14"/>',
        'search'    => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'merge'     => '<path d="m8 6 4-4 4 4"/><path d="M12 2v10.3a4 4 0 0 1-1.172 2.872L4 22"/><path d="m20 22-5-5"/>',
        'map'       => '<path d="M14.106 5.553a2 2 0 0 0 1.788 0l3.659-1.83A1 1 0 0 1 21 4.619v12.764a1 1 0 0 1-.553.894l-4.553 2.277a2 2 0 0 1-1.788 0l-4.212-2.106a2 2 0 0 0-1.788 0l-3.659 1.83A1 1 0 0 1 3 19.381V6.618a1 1 0 0 1 .553-.894l4.553-2.277a2 2 0 0 1 1.788 0z"/><path d="M15 5.764v15"/><path d="M9 3.236v15"/>',
        'rows-3'    => '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M21 9H3"/><path d="M21 15H3"/>',
        'archive'   => '<rect width="20" height="5" x="2" y="3" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/>',
        'grip'      => '<circle cx="9" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="18" r="1"/>',
        'book-open' => '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
        'wand'      => '<path d="m21.64 3.64-1.28-1.28a1.21 1.21 0 0 0-1.72 0L2.36 18.64a1.21 1.21 0 0 0 0 1.72l1.28 1.28a1.2 1.2 0 0 0 1.72 0L21.64 5.36a1.2 1.2 0 0 0 0-1.72"/><path d="m14 7 3 3"/><path d="M5 6v4"/><path d="M19 14v4"/><path d="M10 2v2"/><path d="M7 8H3"/><path d="M21 16h-4"/><path d="M11 3H9"/>',
        'lock'      => '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'circle-gauge' => '<path d="M15.6 2.7a10 10 0 1 0 5.7 5.7"/><circle cx="12" cy="12" r="2"/><path d="M13.4 10.6 19 5"/>',
        'calendar'  => '<rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'calendar-plus' => '<path d="M16 18h6"/><path d="M16 2v3"/><path d="M19 15v6"/><path d="M21 11.5V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h8.3"/><path d="M3 9h18"/><path d="M8 2v3"/>',
        'music'     => '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>',
        'file-braces' => '<path d="M6 22a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h8a2.4 2.4 0 0 1 1.704.706l3.588 3.588A2.4 2.4 0 0 1 20 8v12a2 2 0 0 1-2 2z"/><path d="M14 2v5a1 1 0 0 0 1 1h5"/><path d="M10 12a1 1 0 0 0-1 1v1a1 1 0 0 1-1 1 1 1 0 0 1 1 1v1a1 1 0 0 0 1 1"/><path d="M14 18a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1 1 1 0 0 1-1-1v-1a1 1 0 0 0-1-1"/>',
        'calendar-sync' => '<path d="M11 10v4h4"/><path d="m11 14 1.535-1.605a5 5 0 0 1 8 1.5"/><path d="M16 2v4"/><path d="m21 18-1.535 1.605a5 5 0 0 1-8-1.5"/><path d="M21 22v-4h-4"/><path d="M21 8.5V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h4.3"/><path d="M3 10h4"/><path d="M8 2v4"/>',
        'info'      => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
        'earth'      => '<path d="M21.54 15H17a2 2 0 0 0-2 2v4.54"/><path d="M7 3.34V5a3 3 0 0 0 3 3a2 2 0 0 1 2 2c0 1.1.9 2 2 2a2 2 0 0 0 2-2c0-1.1.9-2 2-2h3.17"/><path d="M11 21.95V18a2 2 0 0 0-2-2a2 2 0 0 1-2-2v-1a2 2 0 0 0-2-2H2.05"/><circle cx="12" cy="12" r="10"/>',
        'circle-check' => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
        'circle-x'     => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
        // Lucide « circle-dot » — en-tête de la colonne Statut de ?p=structures :
        // un point dans un cercle, neutre, qui ne préjuge d'aucun des quatre
        // états (cœur, coche, croix, cercle pointillé) qu'il coiffe.
        'circle-dot'   => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="1"/>',
        'circle-ellipsis' => '<circle cx="12" cy="12" r="10"/><path d="M17 12h.01"/><path d="M12 12h.01"/><path d="M7 12h.01"/>',
        'circle-dashed' => '<path d="M10.1 2.182a10 10 0 0 1 3.8 0"/><path d="M13.9 21.818a10 10 0 0 1-3.8 0"/><path d="M17.609 3.721a10 10 0 0 1 2.69 2.7"/><path d="M2.182 13.9a10 10 0 0 1 0-3.8"/><path d="M20.279 17.609a10 10 0 0 1-2.7 2.69"/><path d="M21.818 10.1a10 10 0 0 1 0 3.8"/><path d="M3.721 6.391a10 10 0 0 1 2.7-2.69"/><path d="M6.391 20.279a10 10 0 0 1-2.69-2.7"/>',
        'link'       => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        'unlink'     => '<path d="m18.84 12.25 1.72-1.71h-.02a5.004 5.004 0 0 0-.12-7.07 5.006 5.006 0 0 0-6.95 0l-1.72 1.71"/><path d="m5.17 11.75-1.71 1.71a5.004 5.004 0 0 0 .12 7.07 5.006 5.006 0 0 0 6.95 0l1.71-1.71"/><line x1="8" x2="8" y1="2" y2="5"/><line x1="2" x2="5" y1="8" y2="8"/><line x1="16" x2="16" y1="19" y2="22"/><line x1="19" x2="22" y1="16" y2="16"/>',
        'earth-lock' => '<path d="M7 3.34V5a3 3 0 0 0 3 3"/><path d="M11 21.95V18a2 2 0 0 0-2-2 2 2 0 0 1-2-2v-1a2 2 0 0 0-2-2H2.05"/><path d="M21.54 15H17a2 2 0 0 0-2 2v4.54"/><path d="M12 2a10 10 0 1 0 9.54 13"/><path d="M20 6V4a2 2 0 1 0-4 0v2"/><rect width="8" height="5" x="14" y="6" rx="1"/>',
        'globe-off'  => '<path d="M10.114 4.462A14.5 14.5 0 0 1 12 2a10 10 0 0 1 9.313 13.643"/><path d="M15.557 15.556A14.5 14.5 0 0 1 12 22 10 10 0 0 1 4.929 4.929"/><path d="M15.892 10.234A14.5 14.5 0 0 0 12 2a10 10 0 0 0-3.643.687"/><path d="M17.656 12H22"/><path d="M19.071 19.071A10 10 0 0 1 12 22 14.5 14.5 0 0 1 8.44 8.45"/><path d="M2 12h10"/><path d="m2 2 20 20"/>',
        'handshake'  => '<path d="m11 17 2 2a1 1 0 1 0 3-3"/><path d="m14 14 2.5 2.5a1 1 0 1 0 3-3l-3.88-3.88a3 3 0 0 0-4.24 0l-.88.88a1 1 0 1 1-3-3l2.81-2.81a5.79 5.79 0 0 1 7.06-.87l.47.28a2 2 0 0 0 1.42.25L21 4"/><path d="m21 3 1 11h-2"/><path d="M3 3 2 14l6.5 6.5a1 1 0 1 0 3-3"/><path d="M3 4h8"/>',
        'map-pin'    => '<path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>',
        'star'       => '<path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/>',
        'heart'      => '<path d="M2 9.5a5.5 5.5 0 0 1 9.591-3.676.56.56 0 0 0 .818 0A5.49 5.49 0 0 1 22 9.5c0 2.29-1.5 4-3 5.5l-5.492 5.313a2 2 0 0 1-3 .019L5 15c-1.5-1.5-3-3.2-3-5.5"/>',
        'tag'        => '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/>',
        'message-square' => '<path d="M22 17a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h15a2 2 0 0 1 2 2z"/>',
        'send'       => '<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>',
        'funnel'     => '<path d="M10 20a1 1 0 0 0 .553.895l2 1A1 1 0 0 0 14 21v-7a2 2 0 0 1 .517-1.341L21.74 4.67A1 1 0 0 0 21 3H3a1 1 0 0 0-.742 1.67l7.225 7.989A2 2 0 0 1 10 14z"/>',
        'funnel-x'   => '<path d="M12.531 3H3a1 1 0 0 0-.742 1.67l7.225 7.989A2 2 0 0 1 10 14v6a1 1 0 0 0 .553.895l2 1A1 1 0 0 0 14 21v-7a2 2 0 0 1 .517-1.341l.427-.473"/><path d="m16.5 3.5 5 5"/><path d="m21.5 3.5-5 5"/>',
        'funnel-plus' => '<path d="M13.354 3H3a1 1 0 0 0-.742 1.67l7.225 7.989A2 2 0 0 1 10 14v6a1 1 0 0 0 .553.895l2 1A1 1 0 0 0 14 21v-7a2 2 0 0 1 .517-1.341l1.218-1.348"/><path d="M16 6h6"/><path d="M19 3v6"/>',
    ];
}

// Chemins SVG bruts d'une icône (sans enveloppe <svg>), pour le sprite.
function icone_chemins(string $nom): string
{
    return icone_table()[$nom] ?? '';
}

function icon(string $name): string
{
    $paths = icone_table();
    $p = $paths[$name] ?? '';
    if ($p === '') {
        return '';
    }
    // Mode sprite : l'icône n'est plus recopiée, elle référence un <symbol>
    // défini une seule fois par page (voir icones_sprite()). Sur une liste de
    // 100 structures, 442 icônes étaient écrites en entier pour 30 dessins
    // distincts — 129 Ko, contre 29 Ko en référence.
    if (icones_mode_sprite()) {
        icones_enregistrer($name);
        return '<svg class="ico" aria-hidden="true"><use href="#ico-' . $name . '"></use></svg>';
    }
    // Hors mode sprite (corps d'e-mail) : l'icône doit être autonome, un client
    // de messagerie n'aura jamais le sprite de la page.
    return '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
        . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

// Vrai pendant le rendu d'une page HTML complète : icon() y émet des
// références au sprite. Désactivé par défaut — tout ce qui sort du site
// (e-mails) doit rester autonome.
function icones_mode_sprite(?bool $actif = null): bool
{
    static $mode = false;
    if ($actif !== null) {
        $mode = $actif;
    }
    return $mode;
}

// Mémorise les icônes réellement demandées pendant le rendu, pour n'émettre
// qu'elles. Sans ce tri, le sprite pèserait les 79 icônes de la bibliothèque
// (24,8 Ko) sur une page qui n'en utilise que cinq.
function icones_enregistrer(?string $nom = null): array
{
    static $vues = [];
    if ($nom === null) {
        return array_keys($vues);
    }
    $vues[$nom] = true;
    return [];
}

// Sprite des seules icônes rendues. Les attributs de tracé (fill, stroke…) ne
// sont PAS posés ici mais sur .ico en CSS : posés sur le <symbol>, ils
// l'emporteraient sur toute surcharge CSS ciblant l'icône (ex. la loupe des
// champs de recherche, volontairement plus épaisse).
function icones_sprite(): string
{
    $noms = icones_enregistrer();
    if (!$noms) {
        return '';
    }
    $h = '<svg class="ico-sprite" aria-hidden="true" style="display:none">';
    foreach ($noms as $nom) {
        $h .= '<symbol id="ico-' . $nom . '" viewBox="0 0 24 24">' . icone_chemins($nom) . '</symbol>';
    }
    return $h . '</svg>';
}

// Insère le sprite juste après <body>, donc AVANT toute référence <use>.
// Nécessite d'avoir tamponné la page : les icônes utilisées ne sont connues
// qu'une fois le contenu rendu.
function injecter_sprite(string $html): string
{
    $sprite = icones_sprite();
    if ($sprite === '') {
        return $html;
    }
    $html2 = preg_replace('/(<body\b[^>]*>)/i', '$1' . $sprite, $html, 1, $n);
    return $n ? $html2 : $sprite . $html;
}

// Champ de recherche standard de l'application : la saisie plus une loupe
// CLIQUABLE. Un seul rendu pour les dix champs du site, afin que le geste soit
// le même partout.
//
// Deux natures de champ coexistent, d'où $submit :
//   - true  : le champ est dans un <form> (listes filtrées côté serveur, page
//             de recherche) — la loupe est un vrai bouton submit ;
//   - false : le champ filtre en direct en JS (employés, spectacles, écritures
//             d'un axe) — il n'y a rien à soumettre, la loupe se contente de
//             rendre le focus au champ (voir assets/app.js).
//
// Conteneur <span> et non <label> : un <label> ne doit pas contenir d'élément
// interactif autre que son propre champ, et son comportement d'activation
// entrerait en conflit avec le clic sur le bouton. L'accessibilité est portée
// par l'aria-label du champ, déjà présent partout.
function champ_recherche(array $opts = []): string
{
    $classe      = trim('search-label ' . ($opts['classe'] ?? ''));
    $submit      = (bool) ($opts['submit'] ?? false);
    $placeholder = $opts['placeholder'] ?? 'Rechercher...';
    $aria        = $opts['aria'] ?? 'Rechercher';

    $attrs = 'type="search" autocomplete="off"';
    foreach (['id', 'name'] as $a) {
        if (($opts[$a] ?? '') !== '') {
            $attrs .= ' ' . $a . '="' . e((string) $opts[$a]) . '"';
        }
    }
    if (isset($opts['valeur'])) {
        $attrs .= ' value="' . e((string) $opts['valeur']) . '"';
    }
    if (!empty($opts['autofocus'])) {
        $attrs .= ' autofocus';
    }

    return '<span class="' . e($classe) . '">'
        . '<input ' . $attrs . ' placeholder="' . e($placeholder) . '" aria-label="' . e($aria) . '">'
        . '<button type="' . ($submit ? 'submit' : 'button') . '" class="search-go" aria-label="' . e($aria) . '">'
        . icon('search')
        . '</button>'
        . '</span>';
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

// Sélecteur segmenté à icônes (ex. type de structure, visibilité d'un
// événement) — remplace un <select> par des boutons-icônes connectés dont un
// seul est actif, exactement le format des droits par module de ?p=comptes
// (.perm-toggle), mais horizontal. Radios cachés : la valeur est soumise
// naturellement dans le formulaire, sans JS. $options : [valeur => ['icone' =>
// nom pour icon(), 'label' => texte accessible/tooltip]], dans l'ordre voulu.
function icon_picker(string $name, array $options, string $selected, string $ariaLabel = ''): string
{
    $h = '<div class="seg-picker" role="radiogroup"' . ($ariaLabel !== '' ? ' aria-label="' . e($ariaLabel) . '"' : '') . '>';
    foreach ($options as $valeur => $opt) {
        $checked = $selected === $valeur ? ' checked' : '';
        $h .= '<label class="seg-btn" title="' . e($opt['label']) . '">'
            . '<input type="radio" name="' . e($name) . '" value="' . e($valeur) . '" aria-label="' . e($opt['label']) . '"' . $checked . '>'
            . icon($opt['icone'])
            . '</label>';
    }
    return $h . '</div>';
}

// Temps écoulé depuis $date, en toutes lettres — « 3 mois », « 4 ans »,
// « ce mois-ci ». '' si la date est vide ou illisible.
//
// Sans « il y a » devant : l'en-tête de colonne dit déjà de quoi il s'agit, et
// deux mots de moins par cellule sur 2959 lignes, ça compte. La date exacte
// reste accessible au survol, posée en title par l'appelant.
//
// Pourquoi une durée plutôt qu'une date : la question qu'on se pose devant ces
// colonnes n'est pas « quand » mais « depuis combien de temps » — « 21.11.2022 »
// demandait un calcul mental à chaque ligne.
function duree_depuis(string $date): string
{
    if ($date === '') {
        return '';
    }
    $ts = strtotime($date);
    if ($ts === false) {
        return '';
    }
    // 30,44 = durée moyenne d'un mois sur une année, pour que « 12 mois » et
    // « 1 an » tombent au même endroit plutôt qu'à trois jours près.
    $mois = max(0.0, (time() - $ts) / (30.44 * 86400));
    if ($mois < 1) {
        return 'ce mois-ci';
    }
    if ($mois < 12) {
        return round($mois) . ' mois';
    }
    $ans = (int) round($mois / 12);
    return $ans . ' an' . ($ans > 1 ? 's' : '');
}

// Retire l'indentation du gabarit ENTRE les cellules d'un tableau. Sur
// ?p=structures elle pesait 751 Ko de HTML (254 octets par ligne, 2959 lignes)
// pour rien : le navigateur ne rend aucune de ces espaces.
//
// Volontairement limité aux frontières où le HTML ne rend jamais rien :
//   - entre deux cellules, et autour de <tr> (les blancs y sont ignorés) ;
//   - en tête et en queue de cellule (les blancs de bord d'un bloc sont rognés).
// À l'INTÉRIEUR d'une cellule, en revanche, l'espace n'est pas supprimée mais
// RÉDUITE À UNE : là, elle sépare bel et bien deux étiquettes ou une icône et
// un nom, et la supprimer les collerait — mais le navigateur fond de toute
// façon toute suite d'espaces en une seule, donc n'en garder qu'une ne change
// rien à l'affichage (218 Ko de plus, l'indentation du gabarit entre deux
// éléments d'une même cellule). Cette dernière règle suppose que le contenu
// n'est pas en white-space: pre — vrai pour les tableaux de listes, à vérifier
// avant d'employer la fonction ailleurs.
function compacter_cellules(string $html): string
{
    // Trois passes, pas une par cas : le tampon fait plusieurs mégaoctets et
    // chaque motif le relit en entier. « t[dhr]\b » ne vise que td/th/tr — la
    // limite de mot écarte thead, tbody, title, track.
    $html = (string) preg_replace(
        [
            '~(</?t[dhr]\b[^>]*>)\s+~', // après l'ouverture ou la fermeture d'une cellule ou d'une ligne
            '~\s+(</t[dhr]>)~',          // juste avant leur fermeture
        ],
        '$1',
        $html
    );
    // Dans la cellule : une seule espace là où le gabarit en laissait vingt.
    return (string) preg_replace('~>\s{2,}<~', '> <', $html);
}

// Statut d'une structure — sélecteur segmenté horizontal (même style que
// icon_picker(), mais cliqué en AJAX au lieu de soumis avec le reste du
// formulaire : voir route_structure_statut() + lassoInitStatutToggle(),
// assets/app.js). 4 états,
// mêmes valeurs que structures.statut (STRUCTURE_STATUTS, lib/booking.php,
// migration_63 — remplace actif+desinscrit) : « Contact privilégié »
// (prioritaire), « Actif », « Ne pas contacter » (désinscrite du mailing),
// « Inactif ».
function structure_statut_toggle_html(int $id, string $statut): string
{
    $h = '<div class="seg-picker statut-toggle" role="radiogroup" aria-label="Statut" data-statut-id="' . $id . '">';
    foreach (STRUCTURE_STATUTS as $val) {
        $on = $statut === $val;
        $label = structure_statut_libelle($val);
        $h .= '<button type="button" class="seg-btn' . ($on ? ' on' : '') . '" data-statut-valeur="' . e($val) . '"'
            . ' role="radio" aria-checked="' . ($on ? 'true' : 'false') . '" title="' . e($label) . '" aria-label="' . e($label) . '">'
            . icon(structure_statut_icone($val)) . '</button>';
    }
    return $h . '</div>';
}

// Attribut style="…" d'un badge d'étiquette à la couleur choisie par
// l'utilisateur (?p=parametres_tags, structure_tags.couleur) — fond teinté à
// faible opacité (même esprit que --highlight-tint/-d) + texte à la couleur
// pleine. '' (attribut vide) si pas de couleur : le badge garde son style par
// défaut (.badge, assets/app.css).
function badge_style_html(string $couleur): string
{
    if (!preg_match('/^#[0-9a-f]{6}$/i', $couleur)) {
        return '';
    }
    return ' style="background:' . e($couleur) . '1a;color:' . e($couleur) . ';"';
}

// Affichage combiné « Ville 🇫🇷 (canton/département) » — factorisé entre les
// listes structures et événements (ville en gras, drapeau du pays, canton/
// département entre parenthèses en muted — jamais la grande région, voir
// migration_56). $drapeau : émoji déjà résolu par l'appelant (pays_drapeau()
// si le pays est stocké en code ISO2, pays_drapeau_nom() s'il est stocké en
// nom) ; $paysBrut : texte affiché en repli si aucun drapeau n'a pu être
// résolu (ex. valeur non reconnue). Chaîne vide si rien à afficher —
// à l'appelant de décider son propre repli (« — », combiné à d'autres champs).
function ville_departement_canton_html(string $ville, string $drapeau, string $paysBrut, string $departementCanton): string
{
    $h = '';
    if ($ville !== '') {
        $h .= '<strong>' . e($ville) . '</strong>';
    }
    if ($drapeau !== '') {
        $h .= ' <span class="tiny">' . $drapeau . '</span>';
    } elseif ($paysBrut !== '') {
        $h .= ' <span class="tiny muted">' . e($paysBrut) . '</span>';
    }
    if ($departementCanton !== '') {
        $h .= ' <span class="tiny muted">' . e($departementCanton) . '</span>';
    }
    return $h;
}

// Bandeau + formulaire de géocodage par lots de la vue carte (lieux ET
// structures — factorisé pour ne pas dupliquer entre views/_lieux_carte.php
// et views/_structures_carte.php) : compte des villes non localisées, lien
// « Voir la liste » vers le filtre non_localises=1 de la vue liste, formulaire
// de géocodage (avec les filtres actifs repostés en champs cachés) et message
// de fin quand tout est localisé. $nomCompte : accord pour « N ... dont la
// ville... » (ex. « lieu(x) », « structure(s) ») ; $nomPluriel/$accordAffiches :
// accord pour « toutes les villes des ... » (ex. « lieux »/« affichés »,
// « structures »/« affichées »). $hiddenParams : [nom => valeur] des filtres
// actifs à reposter tels quels avec le formulaire. $geocodeDemande : true si
// un lot vient d'être traité (?geocode=N dans l'URL), pour enchaîner
// automatiquement sur le lot suivant tant qu'il en reste (voir le script en
// bas de fonction) ou afficher le message de fin.
function carte_banner_geocodage_html(
    int $carteVillesManquantes,
    string $nomCompte,
    string $nomPluriel,
    string $accordAffiches,
    string $lienListe,
    string $formAction,
    array $hiddenParams,
    bool $geocodeDemande
): string {
    if ($carteVillesManquantes > 0) {
        $h = '<div class="carte-banner">';
        $h .= '<p>' . $carteVillesManquantes . ' ' . e($nomCompte) . " dont la ville n'est pas encore localisée sur la carte.";
        if ($lienListe !== '') {
            $h .= ' <a href="' . e($lienListe) . '">Voir la liste</a>';
        }
        $h .= '</p>';
        $h .= '<form method="post" action="' . e($formAction) . '" id="geocoder-form">';
        $h .= '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
        $h .= hidden_inputs_html($hiddenParams);
        $h .= '<button type="submit" id="geocoder-btn">' . icon('map-pin') . ' Géocoder (par lots, ≈1 seconde par ville)</button>';
        $h .= '</form>';
        $h .= '<p class="muted small" id="geocoder-auto-msg" hidden>Géocodage en cours (service Nominatim/OpenStreetMap, 1 ville par seconde)… vous pouvez quitter la page à tout moment, il reprendra où il s\'est arrêté au prochain clic.</p>';
        $h .= '</div>';
        if ($geocodeDemande) {
            $h .= '<script nonce="' . e(csp_nonce()) . '">(function () {'
                . 'var msg = document.getElementById("geocoder-auto-msg"); if (msg) { msg.hidden = false; }'
                . 'setTimeout(function () { var f = document.getElementById("geocoder-form"); if (f) { f.requestSubmit(); } }, 400);'
                . '})();</script>';
        }
        return $h;
    }
    if ($geocodeDemande) {
        return '<p class="carte-banner ok flash">Géocodage terminé — toutes les villes des ' . e($nomPluriel) . ' ' . e($accordAffiches) . ' sont désormais localisées.</p>';
    }
    return '';
}

// Bandeau « Filtre : non localisés » de la vue liste (lieux et structures) —
// affiché quand on arrive depuis le lien « Voir la liste » du bandeau carte
// ci-dessus, avec un lien pour quitter le filtre sans perdre les autres
// filtres actifs.
function filtre_non_localises_flash_html(bool $actif, string $nomPluriel, string $lienQuitter): string
{
    if (!$actif) {
        return '';
    }
    return '<p class="warn flash">Filtre : ' . e($nomPluriel) . " dont la ville n'a pas pu être localisée sur la carte. "
        . '<a href="' . e($lienQuitter) . '">Quitter ce filtre</a></p>';
}

// Affichage catégorie/sous-catégorie sur deux lignes (catégorie en petit et
// muted au-dessus, sous-catégorie en dessous) — même style que le rappel de
// catégorie de compta_ecritures.php (.row-field-txt/.row-field-prefix).
function categorie_sous_categorie_html(string $categorie, string $sousCategorie): string
{
    $prefixe = $sousCategorie !== '' ? '<span class="row-field-prefix">' . e($categorie) . '</span>' : '';
    $feuille = $sousCategorie !== '' ? $sousCategorie : $categorie;
    return '<span class="row-field-txt">' . $prefixe . '<span>' . e($feuille) . '</span></span>';
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

// La page est tamponnée pour que le sprite d'icônes puisse être inséré après
// coup, juste sous <body> : on ne sait quelles icônes ont servi qu'une fois le
// contenu rendu, et le <symbol> doit précéder les <use> qui le référencent.
function render(string $view, array $data = [], ?string $title = null): void
{
    extract($data);
    $contentView = __DIR__ . '/../views/' . $view . '.php';
    $pageTitle   = $title ?? 'Fiches de salaire';
    icones_mode_sprite(true);
    ob_start();
    require __DIR__ . '/../views/layout.php';
    echo injecter_sprite((string) ob_get_clean());
}

// Rendu d'une vue "nue" (sans layout), pour l'impression. Même tampon : ces
// vues produisent aussi un document complet.
function render_bare(string $view, array $data = []): void
{
    extract($data);
    icones_mode_sprite(true);
    ob_start();
    require __DIR__ . '/../views/' . $view . '.php';
    echo injecter_sprite((string) ob_get_clean());
}
