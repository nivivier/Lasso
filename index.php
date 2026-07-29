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
require_once __DIR__ . '/lib/routes_booking.php';
require_once __DIR__ . '/lib/maj.php';
require_once __DIR__ . '/lib/geocodage.php';
require_once __DIR__ . '/lib/dev.php';
require_once __DIR__ . '/lib/routes_dev.php';

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

// Table de routage : route → handler, + route → module(s) dont dépend le
// droit d'accès (lecture pour un GET, écriture pour un POST — convention
// stricte du projet : toute mutation passe par un POST protégé par
// check_csrf(), cf. CLAUDE.md). Une route absente de $routeModules n'a pas
// de contrôle de droits au-delà de require_login() (routes du cœur toujours
// universelles : tableau de bord, mon compte).
$handlers = [
    'setup'  => 'route_setup',
    'login'  => 'route_login',
    'logout' => 'route_logout',
    'compte' => 'route_compte',  // « Mon compte » : accessible à tout compte, indépendamment des permissions.
    'resumes' => 'route_resumes', // Tableau de bord : fait partie du cœur, toujours accessible.
];
$routeModules = [];

ajouter_routes_module($handlers, $routeModules, 'salaires', [
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
]);

ajouter_routes_module($handlers, $routeModules, 'compta', [
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
]);

// Comptes bancaires : partagés entre Comptabilité (relevés, lettrage) et
// Facturation (IBAN créancier de la QR-facture) — accessible dès que l'un des
// deux modules est actif, pas seulement Comptabilité ; le droit d'accès suit
// la même logique « OU » (lecture/écriture sur l'un des deux suffit).
if (module_actif('compta') || module_actif('facturation')) {
    $handlers['compta_comptes'] = 'route_compta_comptes';
    $routeModules['compta_comptes'] = ['compta', 'facturation'];
}

ajouter_routes_module($handlers, $routeModules, 'analytique', [
    'compta_axes'           => 'route_compta_axes',
    'compta_analyse'        => 'route_compta_analyse',
    'compta_analyse_print'      => 'route_compta_analyse_print',
    'compta_analyse_axe'        => 'route_compta_analyse_axe',
    'compta_analyse_axe_print'  => 'route_compta_analyse_axe_print',
    'compta_ventilation_save'         => 'route_compta_ventilation_save',
    'compta_suggestion_ventilation'   => 'route_compta_suggestion_ventilation',
    'compta_suggestion_preview'       => 'route_compta_suggestion_preview',
]);
if (module_actif('analytique') && module_actif('salaires')) {
    $handlers['fiche_ligne_axe_save'] = 'route_fiche_ligne_axe_save';
    $routeModules['fiche_ligne_axe_save'] = ['analytique'];
}

ajouter_routes_module($handlers, $routeModules, 'facturation', [
    'facturation'           => 'route_facturation',
    'facturation_liste'     => 'route_facturation_liste',
    'facturation_form'      => 'route_facturation_form',
    'facture'               => 'route_facture',
    'facture_emettre'       => 'route_facture_emettre',
    'facture_payee'         => 'route_facture_payee',
    'facture_annuler'       => 'route_facture_annuler',
    'facture_delete'        => 'route_facture_delete',
    'facture_pdf'           => 'route_facture_pdf',
    'facture_email'         => 'route_facture_email',
    'facture_rappel'        => 'route_facture_rappel',
    'import_factures'       => 'route_import_factures',
]);

// Structures (ex-débiteurs) : liste/fiche/suppression partagées entre
// Facturation et Booking — accessible dès que l'un des deux modules est actif
// (même logique « OU » que les comptes bancaires ci-dessus), voir
// SPEC_BOOKING.md §3. Les écrans propres au CRM (notes, tags, lieux, mailing,
// import) restent réservés au module booking, ci-dessous.
if (module_actif('facturation') || module_actif('booking')) {
    $handlers['structures']      = 'route_structures';
    $handlers['structures_geocoder'] = 'route_structures_geocoder';
    $handlers['structure']       = 'route_structure';
    $handlers['structure_renommer'] = 'route_structure_renommer';
    $handlers['structure_statut'] = 'route_structure_statut';
    $handlers['structure_flag']  = 'route_structure_flag';
    $handlers['structure_delete'] = 'route_structure_delete';
    $handlers['structure_fusion'] = 'route_structure_fusion';
    $handlers['structure_transformer'] = 'route_structure_transformer';
    foreach (['structures', 'structures_geocoder', 'structure', 'structure_renommer', 'structure_statut', 'structure_flag', 'structure_delete', 'structure_fusion', 'structure_transformer'] as $r) {
        $routeModules[$r] = ['facturation', 'booking'];
    }
}

ajouter_routes_module($handlers, $routeModules, 'booking', [
    'structure_contact_ajouter' => 'route_structure_contact_ajouter',
    'structure_contact_delete'  => 'route_structure_contact_delete',
    'structure_note_ajouter' => 'route_structure_note_ajouter',
    'structure_tag_ajouter'  => 'route_structure_tag_ajouter',
    'structure_tag_retirer'  => 'route_structure_tag_retirer',
    'structure_lieu_lier'    => 'route_structure_lieu_lier',
    'structure_lieu_delier'  => 'route_structure_lieu_delier',
    'lieux'                  => 'route_lieux',
    'lieux_geocoder'         => 'route_lieux_geocoder',
    'lieu'                   => 'route_lieu',
    'lieu_renommer'          => 'route_lieu_renommer',
    'lieu_statut'            => 'route_lieu_statut',
    'lieu_flag'              => 'route_lieu_flag',
    'lieu_organisateur'      => 'route_lieu_organisateur',
    'structures_options'     => 'route_structures_options',
    'lieux_options'          => 'route_lieux_options',
    'lieu_delete'            => 'route_lieu_delete',
    'mailing'                => 'route_mailing',
    'mailing_campagne'       => 'route_mailing_campagne',
    'mailing_modeles'        => 'route_mailing_modeles',
    'mailing_exclusions'     => 'route_mailing_exclusions',
    'mailing_envoyer'        => 'route_mailing_envoyer',
    'import_structures'      => 'route_import_structures',
    'parametres_structures'  => 'route_parametres_structures',
    'parametres_lieux_categories' => 'route_parametres_lieux_categories',
    'parametres_tags'        => 'route_parametres_tags',
]);
// Traitement de la file d'attente mailing + désinscription : protégés par un
// jeton dédié (mailing_verifier_token()), pas par une session utilisateur —
// déclenchés par le planificateur de tâches de l'hébergeur ou par un lien
// dans l'e-mail, jamais soumis à peut_lire()/peut_ecrire() (même logique que
// l'export public du module événements).
if (module_actif('booking')) {
    $handlers['mailing_traiter']  = 'route_mailing_traiter';
    $handlers['desinscription']   = 'route_desinscription';
}

ajouter_routes_module($handlers, $routeModules, 'evenements', [
    'evenements'         => 'route_evenements',
    'evenements_liste'   => 'route_evenements_liste',
    'evenements_geocoder' => 'route_evenements_geocoder',
    'evenements_export_suisa' => 'route_evenements_export_suisa',
    'evenement'          => 'route_evenement',
    'evenement_delete'   => 'route_evenement_delete',
    'evenement_suisa'    => 'route_evenement_suisa',
    'evenement_axe_defaut' => 'route_evenement_axe_defaut',
    'evenement_production_externe' => 'route_evenement_production_externe',
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
    'import_evenements'  => 'route_import_evenements',
]);
// Export public (site web / agenda externe) : protégé par un jeton dédié
// (evenements_verifier_token()), pas par une session utilisateur — reste
// accessible même à un visiteur non connecté, donc jamais soumis à
// peut_lire()/peut_ecrire() comme le reste du module.
if (module_actif('evenements')) {
    $handlers['evenements_json'] = 'route_evenements_json';
    $handlers['evenements_ical'] = 'route_evenements_ical';
}

// Géocodage d'une seule ville (mini-carte de localisation sur ?p=lieu,
// ?p=structure, ?p=evenement) : commun aux deux modules, accessible dès que
// l'un des deux est actif — écriture sur l'un ou l'autre suffit (le
// géocodage n'écrit que dans le cache partagé lieux_geocodage, jamais dans
// la fiche lieu/structure/événement elle-même).
if (module_actif('booking') || module_actif('evenements')) {
    $handlers['geocoder_ville_unique'] = 'route_geocoder_ville_unique';
    $routeModules['geocoder_ville_unique'] = ['booking', 'evenements'];
}

// Cœur : lecture pour consulter les pages de contenu (informations
// employeur, e-mails, exports), écriture réservée à l'administration au
// sens strict (comptes, permissions, modules actifs, mises à jour,
// sauvegarde complète de la base) — voir SPEC_PERMISSIONS.md §7.
if (peut_lire('coeur')) {
    $handlers += [
        'parametres'      => 'route_parametres',
        'employeur'       => 'route_employeur',
        'emails'          => 'route_emails',
        'export'          => 'route_export',
        'parametres_pays' => 'route_parametres_pays',
    ];
    foreach (['parametres', 'employeur', 'emails', 'export', 'parametres_pays'] as $r) {
        $routeModules[$r] = ['coeur'];
    }
}
if (peut_ecrire('coeur')) {
    // Pas d'entrée dans $routeModules : ces routes n'ont pas de mode lecture
    // seule, elles sont entièrement réservées à l'écriture cœur (déjà
    // conditionnées par leur présence même dans $handlers, ci-dessus).
    $handlers += [
        'comptes'             => 'route_comptes',
        'compte_reset'        => 'route_compte_reset',
        'compte_delete'       => 'route_compte_delete',
        'compte_permissions'  => 'route_compte_permissions',
        'parametres_modules'  => 'route_parametres_modules',
        'maj'                 => 'route_maj',
        'diagnostic'          => 'route_diagnostic',
        'apparence'           => 'route_apparence',
        'backup'              => 'route_backup',
        'dev'                 => 'route_dev',
    ];
}

if ($route === null) {
    $route = route_defaut();
}

if (isset($handlers[$route])) {
    if (isset($routeModules[$route]) && !route_autorisee($routeModules[$route])) {
        require_login();
        redirect(route_defaut(), ['refuse' => 1]);
    }
    $handlers[$route]();
} else {
    require_login();
    redirect(route_defaut());
}
