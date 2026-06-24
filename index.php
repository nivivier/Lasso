<?php
// Front controller : initialisation + dispatch vers les handlers (lib/routes.php).

declare(strict_types=1);

require_once __DIR__ . '/lib/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/calc.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/routes.php';
require_once __DIR__ . '/lib/routes_compta.php';

// Redirection HTTPS forcée (avant tout traitement / sortie).
if (FORCE_HTTPS && !is_https() && PHP_SAPI !== 'cli') {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $uri  = $_SERVER['REQUEST_URI'] ?? '/';
    if ($host !== '') {
        header('Location: https://' . $host . $uri, true, 301);
        exit;
    }
}

// Erreurs : visibles en dev, masquées (mais journalisées) en production.
$debug = (APP_ENV === 'dev');
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
if (!$debug) {
    set_exception_handler(function (Throwable $e) {
        error_log('[app] ' . $e);
        http_response_code(500);
        echo '<!doctype html><meta charset="utf-8"><p style="font-family:sans-serif;padding:2rem">'
            . 'Une erreur est survenue. Réessayez ou contactez l\'administrateur.</p>';
    });
}

send_security_headers();
start_session();
db(); // initialise le schéma au premier appel

$route = $_GET['p'] ?? 'resumes';

// Première installation : forcer la création du compte admin.
if (!has_users() && $route !== 'setup') {
    redirect('setup');
}

// Table de routage : route → handler
$handlers = [
    'setup'        => 'route_setup',
    'login'        => 'route_login',
    'logout'       => 'route_logout',
    'compte'       => 'route_compte',
    'employes'     => 'route_employes',
    'employe_voir' => 'route_employe_voir',
    'employe'      => 'route_employe',
    'employe_delete' => 'route_employe_delete',
    'parametres'    => 'route_parametres',
    'employeur'     => 'route_employeur',
    'emails'        => 'route_emails',
    'taux_horaires' => 'route_taux_horaires',
    'unites'        => 'route_unites',
    'export'        => 'route_export',
    'comptes'       => 'route_comptes',
    'compte_reset'  => 'route_compte_reset',
    'compte_delete' => 'route_compte_delete',
    'taux'          => 'route_taux',
    'fiches'       => 'route_fiches',
    'fiche_new'    => 'route_fiche_new',
    'fiche'        => 'route_fiche',
    'fiche_print'  => 'route_fiche_print',
    'fiche_delete' => 'route_fiche_delete',
    'fiche_edit'   => 'route_fiche_edit',
    'fiche_date'   => 'route_fiche_date',
    'fiche_cout'   => 'route_fiche_cout',
    'fiche_email'  => 'route_fiche_email',
    'certificat'       => 'route_certificat',
    'certificat_print' => 'route_certificat_print',
    'certificat_xml'   => 'route_certificat_xml',
    'resumes'      => 'route_resumes',
    'backup'       => 'route_backup',
    // Comptabilité
    'compta'           => 'route_compta',
    'compta_plan'      => 'route_compta_plan',
    'compta_comptes'   => 'route_compta_comptes',
    'compta_import'    => 'route_compta_import',
    'compta_ecritures' => 'route_compta_ecritures',
    'compta_lettrage'  => 'route_compta_ecritures', // alias pour compatibilité
    'compta_regles'    => 'route_compta_regles',
    'compta_bilan'          => 'route_compta_bilan',
    'compta_bilan_print'    => 'route_compta_bilan_print',
    'compta_ecritures_csv'  => 'route_compta_ecritures_csv',
];

if (isset($handlers[$route])) {
    $handlers[$route]();
} else {
    require_login();
    redirect('resumes');
}
