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
//
// L'environnement ne doit JAMAIS pouvoir être influencé par la requête. La
// détection précédente reposait sur $_SERVER['SERVER_NAME'] : avec
// « UseCanonicalName Off » (défaut de beaucoup d'hébergements mutualisés),
// cette valeur n'est pas une donnée de configuration mais l'en-tête « Host »
// envoyé par le client. Une requête portant « Host: localhost » suffisait donc
// à faire basculer l'application en 'dev' — soit display_errors actif (chemins
// absolus, traces, fragments SQL renvoyés au visiteur), la redirection HTTPS
// désactivée, et les e-mails détournés vers un fichier journal.
//
// Ordre de résolution, du plus explicite au plus sûr :
//   1. define('APP_ENV', …) dans lib/config.local.php (chargé juste au-dessus) ;
//   2. variable d'environnement du serveur (« SetEnv APP_ENV prod » en Apache,
//      fastcgi_param sinon) — posée par la configuration, pas par le client.
//      Apache préfixe la variable en REDIRECT_ après une réécriture interne,
//      les deux formes sont donc acceptées ;
//   3. exécution en ligne de commande ou via le serveur intégré de PHP
//      (« php -S », SAPI cli-server, jamais utilisé en production) → 'dev' ;
//   4. tout le reste → 'prod'. Le défaut penche désormais du côté sûr : une
//      configuration oubliée masque les erreurs au lieu de les exposer.
if (!defined('APP_ENV')) {
    $app_env_serveur = $_SERVER['APP_ENV'] ?? $_SERVER['REDIRECT_APP_ENV'] ?? null;
    if (in_array($app_env_serveur, ['dev', 'prod'], true)) {
        define('APP_ENV', $app_env_serveur);
    } elseif (in_array(PHP_SAPI, ['cli', 'cli-server'], true)) {
        define('APP_ENV', 'dev');
    } else {
        define('APP_ENV', 'prod');
    }
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
if (!defined('PASSWORD_MIN'))      define('PASSWORD_MIN', 8);        // longueur minimale
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
