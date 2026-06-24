<?php
// ============================================================================
//  Configuration de l'application — à adapter lors du déploiement en production.
//  Ce fichier est chargé avant tout le reste (index.php et les scripts CLI).
//
//  NE MODIFIEZ PAS ce fichier pour la production : créez plutôt un fichier
//  lib/config.local.php (non versionné) qui redéfinit les constantes voulues.
//  Il est chargé en premier ci-dessous ; ses valeurs ont donc la priorité.
// ============================================================================

$__local = __DIR__ . '/config.local.php';
if (is_file($__local)) {
    require $__local;
}

// --- Environnement : 'prod' ou 'dev' --------------------------------------
// En 'prod' : erreurs masquées (mais journalisées), e-mails réellement envoyés,
// redirection HTTPS forcée. En 'dev' : erreurs affichées, e-mails journalisés.
// Mettez explicitement 'prod' sur le serveur ; 'auto' détecte via le nom d'hôte.
if (!defined('APP_ENV')) {
    $app_env = 'auto';
    if ($app_env === 'auto') {
        $app_local = PHP_SAPI === 'cli'
            || in_array($_SERVER['SERVER_NAME'] ?? '', ['127.0.0.1', 'localhost', '::1'], true)
            || in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
        $app_env = $app_local ? 'dev' : 'prod';
    }
    define('APP_ENV', $app_env);
}

// --- Emplacement du fichier SQLite ----------------------------------------
// SÉCURITÉ : en production, placez la base HORS de la racine web (ex. un dossier
// au-dessus de public_html) et indiquez ici son chemin absolu. Exemple :
//   define('APP_DB_PATH', '/home/clients/xxxx/data/database.sqlite');
if (!defined('APP_DB_PATH')) {
    define('APP_DB_PATH', __DIR__ . '/../data/database.sqlite');
}

// --- HTTPS ----------------------------------------------------------------
// Forcer la redirection vers HTTPS (recommandé dès qu'un certificat est actif).
if (!defined('FORCE_HTTPS')) {
    define('FORCE_HTTPS', APP_ENV === 'prod');
}

// --- Sécurité : mots de passe & sessions ----------------------------------
if (!defined('PASSWORD_MIN'))      define('PASSWORD_MIN', 12);       // longueur minimale
if (!defined('BCRYPT_COST'))       define('BCRYPT_COST', 12);        // coût bcrypt
if (!defined('SESSION_IDLE'))      define('SESSION_IDLE', 3600);     // 1h d'inactivité
if (!defined('SESSION_ABSOLUTE'))  define('SESSION_ABSOLUTE', 86400);// 24 h de durée de vie max

// --- Sécurité : anti-force-brute du login ---------------------------------
if (!defined('LOGIN_MAX_ATTEMPTS')) define('LOGIN_MAX_ATTEMPTS', 5); // échecs tolérés…
if (!defined('LOGIN_WINDOW'))       define('LOGIN_WINDOW', 900);     // …par fenêtre de 15 min

// --- Sécurité : secret d'installation -------------------------------------
// Si NON vide, l'écran de création du premier compte (setup) n'est accessible
// qu'avec l'URL  ?p=setup&key=<ce secret>. Empêche un inconnu de créer le compte
// admin pendant la fenêtre entre la mise en ligne et votre première connexion.
// Laissez vide en local ; renseignez une longue valeur aléatoire avant un déploiement public.
if (!defined('SETUP_SECRET')) define('SETUP_SECRET', '');

// --- Envoi d'e-mails (SMTP) -----------------------------------------------
// Beaucoup d'hébergements mutualisés DÉSACTIVENT la fonction PHP mail() : les
// e-mails partent alors par SMTP authentifié. Renseignez le serveur et la boîte
// d'envoi soit dans Paramètres → Employeur, soit ici via lib/config.local.php
// (non versionné). Tant que SMTP_USER est vide, l'application retombe sur mail().
//   define('SMTP_HOST', 'mail.votre-hebergeur.ch');
//   define('SMTP_USER', 'salaires@exemple.ch');
//   define('SMTP_PASS', 'le-mot-de-passe-de-la-boite');
if (!defined('SMTP_HOST'))   define('SMTP_HOST', '');     // serveur SMTP de l'hébergeur
if (!defined('SMTP_PORT'))   define('SMTP_PORT', 465);     // 465 = SSL implicite, 587 = STARTTLS
if (!defined('SMTP_SECURE')) define('SMTP_SECURE', 'ssl'); // 'ssl' (port 465) ou 'tls' (port 587)
if (!defined('SMTP_USER'))   define('SMTP_USER', '');      // identifiant = adresse complète de la boîte
if (!defined('SMTP_PASS'))   define('SMTP_PASS', '');
