<?php
// Front controller : initialisation + dispatch vers les handlers (lib/routes.php).

declare(strict_types=1);

require_once __DIR__ . '/lib/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/calc.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/modules.php';
require_once __DIR__ . '/lib/routes.php';
require_once __DIR__ . '/lib/routes_compta.php';
require_once __DIR__ . '/lib/routes_facturation.php';
require_once __DIR__ . '/lib/routes_evenements.php';
require_once __DIR__ . '/lib/maj.php';

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

$route = $_GET['p'] ?? null;

// Première installation : forcer la création du compte admin.
if (!has_users() && $route !== 'setup') {
    redirect('setup');
}

// Table de routage : route → handler. Cœur (toujours actif), puis modules
// optionnels ajoutés seulement s'ils sont activés (lib/modules.php).
$handlers = [
    'setup'        => 'route_setup',
    'login'        => 'route_login',
    'logout'       => 'route_logout',
    'compte'       => 'route_compte',
    'comptes'      => 'route_comptes',
    'compte_reset'  => 'route_compte_reset',
    'compte_delete' => 'route_compte_delete',
    'parametres'         => 'route_parametres',
    'parametres_modules' => 'route_parametres_modules',
    'employeur'     => 'route_employeur',
    'emails'        => 'route_emails',
    'export'        => 'route_export',
    'maj'           => 'route_maj',
    'backup'        => 'route_backup',
    'resumes'       => 'route_resumes', // Tableau de bord : fait partie du cœur, toujours actif.
];

if (module_actif('salaires')) {
    $handlers += [
        'resume'       => 'route_resume',
        'employes'     => 'route_employes',
        'employe_voir' => 'route_employe_voir',
        'employe'      => 'route_employe',
        'employe_delete' => 'route_employe_delete',
        'taux_horaires' => 'route_taux_horaires',
        'unites'        => 'route_unites',
        'taux'          => 'route_taux',
        'import_fiches' => 'route_import_fiches',
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
    ];
}

if (module_actif('compta')) {
    $handlers += [
        'compta'           => 'route_compta',
        'compta_plan'      => 'route_compta_plan',
        'compta_import'    => 'route_compta_import',
        'compta_ecritures' => 'route_compta_ecritures',
        'compta_lettrage'  => 'route_compta_ecritures', // alias pour compatibilité
        'compta_regles'    => 'route_compta_regles',
        'compta_bilan'          => 'route_compta_bilan',
        'compta_bilan_print'    => 'route_compta_bilan_print',
        'compta_ecritures_csv'     => 'route_compta_ecritures_csv',
        'compta_ecritures_camt053' => 'route_compta_ecritures_camt053',
        'import_ecritures'         => 'route_import_ecritures',
    ];
}

// Comptes bancaires : partagés entre Comptabilité (relevés, lettrage) et
// Facturation (IBAN créancier de la QR-facture) — accessible dès que l'un des
// deux modules est actif, pas seulement Comptabilité.
if (module_actif('compta') || module_actif('facturation')) {
    $handlers['compta_comptes'] = 'route_compta_comptes';
}

if (module_actif('analytique')) {
    $handlers += [
        'compta_axes'           => 'route_compta_axes',
        'compta_analyse'        => 'route_compta_analyse',
        'compta_analyse_print'      => 'route_compta_analyse_print',
        'compta_analyse_axe'        => 'route_compta_analyse_axe',
        'compta_analyse_axe_print'  => 'route_compta_analyse_axe_print',
        'compta_ventilation_save'         => 'route_compta_ventilation_save',
        'compta_suggestion_ventilation'   => 'route_compta_suggestion_ventilation',
        'compta_suggestion_preview'       => 'route_compta_suggestion_preview',
    ];
    if (module_actif('salaires')) {
        $handlers['fiche_ligne_axe_save'] = 'route_fiche_ligne_axe_save';
    }
}

if (module_actif('facturation')) {
    $handlers += [
        'facturation'           => 'route_facturation',
        'facturation_liste'     => 'route_facturation_liste',
        'facturation_form'      => 'route_facturation_form',
        'facturation_debiteurs' => 'route_facturation_debiteurs',
        'debiteur'              => 'route_debiteur',
        'debiteur_delete'       => 'route_debiteur_delete',
        'facture'               => 'route_facture',
        'facture_emettre'       => 'route_facture_emettre',
        'facture_payee'         => 'route_facture_payee',
        'facture_annuler'       => 'route_facture_annuler',
        'facture_delete'        => 'route_facture_delete',
        'facture_pdf'           => 'route_facture_pdf',
        'facture_email'         => 'route_facture_email',
        'facture_rappel'        => 'route_facture_rappel',
        'import_factures'       => 'route_import_factures',
    ];
}

if (module_actif('evenements')) {
    $handlers += [
        'evenements'         => 'route_evenements',
        'evenements_liste'   => 'route_evenements_liste',
        'evenement'          => 'route_evenement',
        'evenement_delete'   => 'route_evenement_delete',
        'evenement_suisa'    => 'route_evenement_suisa',
        'evenement_axe_defaut' => 'route_evenement_axe_defaut',
        'evenement_employe_lier'   => 'route_evenement_employe_lier',
        'evenement_employe_delier' => 'route_evenement_employe_delier',
        'evenement_ligne_ajouter'     => 'route_evenement_ligne_ajouter',
        'evenement_organisateur_lier'   => 'route_evenement_organisateur_lier',
        'evenement_organisateur_delier' => 'route_evenement_organisateur_delier',
        'evenement_facture_lier'   => 'route_evenement_facture_lier',
        'evenement_facture_delier' => 'route_evenement_facture_delier',
        'facture_evenement_lier'   => 'route_facture_evenement_lier',
        'spectacles'         => 'route_spectacles',
        'spectacle'          => 'route_spectacle',
        'spectacle_delete'   => 'route_spectacle_delete',
        'parametres_evenements' => 'route_parametres_evenements',
        'evenements_json'    => 'route_evenements_json',
        'evenements_ical'    => 'route_evenements_ical',
        'import_evenements'  => 'route_import_evenements',
    ];
}

if ($route === null) {
    $route = route_defaut();
}

if (isset($handlers[$route])) {
    $handlers[$route]();
} else {
    require_login();
    redirect(route_defaut());
}
