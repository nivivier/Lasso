<?php
// Front controller : initialisation + dispatch vers les handlers (lib/routes.php).

declare(strict_types=1);

require_once __DIR__ . '/lib/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/calc.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/modules.php';
require_once __DIR__ . '/lib/recherche.php';
require_once __DIR__ . '/lib/routes.php';
require_once __DIR__ . '/lib/routes_compta.php';
require_once __DIR__ . '/lib/routes_facturation.php';
require_once __DIR__ . '/lib/routes_evenements.php';
require_once __DIR__ . '/lib/routes_booking.php';
require_once __DIR__ . '/lib/routes_fonds.php';
require_once __DIR__ . '/lib/maj.php';
require_once __DIR__ . '/lib/sauvegarde.php';
require_once __DIR__ . '/lib/feuille_route.php';
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
// La base AVANT la session : start_session() lit la durée d'inactivité réglée
// dans l'application (Serveur → Session) pour en informer le ramasse-miettes de
// PHP. Premier appel à db() : il initialise aussi le schéma.
db();
start_session();

$route = $_GET['p'] ?? null;

// Première installation : forcer la création du compte admin.
if (!has_users() && $route !== 'installation') {
    redirect('installation');
}

// Table de routage : route → handler, + route → module(s) dont dépend le
// droit d'accès (lecture pour un GET, écriture pour un POST — convention
// stricte du projet : toute mutation passe par un POST protégé par
// check_csrf(), cf. CLAUDE.md). Une route absente de $routeModules n'a pas
// de contrôle de droits au-delà de require_login() (routes du cœur toujours
// universelles : tableau de bord, mon compte).
$handlers = [
    'installation'  => 'route_installation',
    'connexion'  => 'route_connexion',
    'deconnexion' => 'route_deconnexion',
    // Mot de passe oublié : publiques par nature, comme la connexion.
    'motdepasse_oublie' => 'route_motdepasse_oublie',
    'motdepasse_reinitialiser' => 'route_motdepasse_reinitialiser',
    'mon_compte' => 'route_mon_compte',  // « Mon compte » : accessible à tout compte, indépendamment des permissions.
    'tableau_bord' => 'route_tableau_bord', // Tableau de bord : fait partie du cœur, toujours accessible.
    // Choix d'étiquette du widget « Suivi du booking » : une préférence
    // d'AFFICHAGE, pas une écriture sur le booking — un compte qui n'a que la
    // lecture doit pouvoir changer ce qu'il regarde. D'où l'absence de
    // $routeModules (la route vérifie elle-même l'accès au booking).
    // Recherche unifiée : traverse plusieurs modules, donc rattachable à aucun.
    // Volontairement absente de $routeModules — le filtrage par module_actif()
    // et peut_lire() se fait source par source dans recherche_globale()
    // (lib/recherche.php), seul endroit qui puisse le faire correctement.
    'recherche' => 'route_recherche',
    // Les villes de l'entonnoir « Lieu » (JSON). Rattachée à aucun module pour
    // la même raison que ?p=structures elle-même : trois modules s'en servent,
    // et la route ne rend qu'une liste de noms de lieux, sans donnée de fiche.
    'structures_lieux' => 'route_structures_lieux',
];
$routeModules = [];

ajouter_routes_module($handlers, $routeModules, 'salaires', [
    'cotisations'       => 'route_cotisations',
    'employes'     => 'route_employes',
    'employe' => 'route_employe',
    'employe_form'      => 'route_employe_form',
    'employe_supprimer' => 'route_employe_supprimer',
    'employe_photo' => 'route_employe_photo',
    'taux_horaires' => 'route_taux_horaires',
    'postes'        => 'route_postes',
    'fiches_importer' => 'route_fiches_importer',
    'fiches'       => 'route_fiches',
    'fiche_form'    => 'route_fiche_form',
    'fiche'        => 'route_fiche',
    'fiche_imprimer'  => 'route_fiche_imprimer',
    'fiche_supprimer' => 'route_fiche_supprimer',
    'fiche_modifier'   => 'route_fiche_modifier',
    'fiches_recalculer' => 'route_fiches_recalculer',
    'fiche_paiement'   => 'route_fiche_paiement',
    'fiche_cout_employeur'   => 'route_fiche_cout_employeur',
    'fiche_envoyer'  => 'route_fiche_envoyer',
    'certificat'       => 'route_certificat',
    'certificat_imprimer' => 'route_certificat_imprimer',
    'certificat_exporter_xml'   => 'route_certificat_exporter_xml',
]);

ajouter_routes_module($handlers, $routeModules, 'compta', [
    'compta_plan'      => 'route_compta_plan',
    'compta_ecritures_importer'    => 'route_compta_ecritures_importer',
    'compta_ecritures' => 'route_compta_ecritures',
    'compta_regles'    => 'route_compta_regles',
    'compta_bilan'          => 'route_compta_bilan',
    'compta_bilan_imprimer'    => 'route_compta_bilan_imprimer',
    'compta_ecritures_exporter_csv'     => 'route_compta_ecritures_exporter_csv',
    'compta_ecritures_exporter_camt053' => 'route_compta_ecritures_exporter_camt053',
    'compta_ecritures_importer_valider'         => 'route_compta_ecritures_importer_valider',
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
    'compta_analyse_imprimer'      => 'route_compta_analyse_imprimer',
    'compta_analyse_axe'        => 'route_compta_analyse_axe',
    'compta_analyse_axe_imprimer'  => 'route_compta_analyse_axe_imprimer',
    'compta_ventilation_enregistrer'         => 'route_compta_ventilation_enregistrer',
    'compta_ventilation_suggestion'   => 'route_compta_ventilation_suggestion',
    'compta_ventilation_suggestion_apercu'       => 'route_compta_ventilation_suggestion_apercu',
]);
if (module_actif('analytique') && module_actif('salaires')) {
    $handlers['fiche_ligne_axe_save'] = 'route_fiche_ligne_axe_save';
    $routeModules['fiche_ligne_axe_save'] = ['analytique'];
}

ajouter_routes_module($handlers, $routeModules, 'facturation', [
    'factures'     => 'route_factures',
    'facture_form'      => 'route_facture_form',
    'facture'               => 'route_facture',
    'facture_emettre'       => 'route_facture_emettre',
    'facture_paiement'         => 'route_facture_paiement',
    'facture_annuler'       => 'route_facture_annuler',
    'facture_supprimer'        => 'route_facture_supprimer',
    'facture_exporter_pdf'           => 'route_facture_exporter_pdf',
    'facture_envoyer'         => 'route_facture_envoyer',
    'facture_rappel_imprimer'        => 'route_facture_rappel_imprimer',
    'facture_ligne_axe_enregistrer'     => 'route_facture_ligne_axe_enregistrer',
    'factures_importer'       => 'route_factures_importer',
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
    $handlers['structure_delete'] = 'route_structure_delete';
    $handlers['structure_fusion'] = 'route_structure_fusion';
    foreach (['structures', 'structures_geocoder', 'structure', 'structure_renommer', 'structure_statut', 'structure_delete', 'structure_fusion'] as $r) {
        $routeModules[$r] = ['facturation', 'booking'];
    }
}

ajouter_routes_module($handlers, $routeModules, 'booking', [
    'structure_contact_ajouter' => 'route_structure_contact_ajouter',
    'structure_contact_supprimer'  => 'route_structure_contact_supprimer',
    'structure_note_ajouter' => 'route_structure_note_ajouter',
    'structure_note_modifier' => 'route_structure_note_modifier',
    'structure_tag_ajouter'  => 'route_structure_tag_ajouter',
    'structure_tag_retirer'  => 'route_structure_tag_retirer',
    'structure_tag_gerer'    => 'route_structure_tag_gerer',
    'structure_lieu_lier'    => 'route_structure_lieu_lier',
    'structure_lieu_retirer'  => 'route_structure_lieu_retirer',
    'structure_localisation_enregistrer' => 'route_structure_localisation_enregistrer',
    'structure_message_envoyer'      => 'route_structure_message_envoyer',
    'structures_json'     => 'route_structures_json',
    'lieux_json'          => 'route_lieux_json',
    'mailing_modeles'        => 'route_mailing_modeles',
    // Campagnes de contact : une sélection de structures à démarcher, contact
    // par contact. Rattachées au booking et non au sous-module « Envois
    // groupés » — elles fonctionnent sans le mailing de masse.
    'booking_campagnes'              => 'route_booking_campagnes',
    'booking_campagne'               => 'route_booking_campagne',
    'booking_campagne_form'          => 'route_booking_campagne_form',
    'booking_campagne_enregistrer'   => 'route_booking_campagne_enregistrer',
    'booking_campagne_supprimer'        => 'route_booking_campagne_supprimer',
    'booking_campagne_reponse_enregistrer'       => 'route_booking_campagne_reponse_enregistrer',
    'booking_campagne_structure'     => 'route_booking_campagne_structure',
    'mailing_exclusions'     => 'route_mailing_exclusions',
    'structures_importer'      => 'route_structures_importer',
    'categories_structures'  => 'route_categories_structures',
    'tags'        => 'route_tags',
]);
// Envois groupés (sous-module du booking) : le ciblage, la file d'attente et
// son suivi. Le reste du mailing — modèles de message, liste d'exclusion —
// reste au booking, dont le message individuel d'une fiche structure se sert.
ajouter_routes_module($handlers, $routeModules, 'mailing', [
    'mailing'                => 'route_mailing',
    'mailing_campagne'       => 'route_mailing_campagne',
    'mailing_envoyer'        => 'route_mailing_envoyer',
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

ajouter_routes_module($handlers, $routeModules, 'fonds', [
    'fonds_campagnes'                      => 'route_fonds_campagnes',
    'fonds_campagne_form'        => 'route_fonds_campagne_form',
    'fonds_campagne_enregistrer' => 'route_fonds_campagne_enregistrer',
    'fonds_campagne'             => 'route_fonds_campagne',
    'fonds_demande'              => 'route_fonds_demande',
    'fonds_demande_enregistrer'  => 'route_fonds_demande_enregistrer',
    'fonds_bailleur_pieces_enregistrer'               => 'route_fonds_bailleur_pieces_enregistrer',
    'fonds_versement_enregistrer'            => 'route_fonds_versement_enregistrer',
    'fonds_campagne_structure_ajouter'   => 'route_fonds_campagne_structure_ajouter',
]);

ajouter_routes_module($handlers, $routeModules, 'evenements', [
    'evenements'   => 'route_evenements',
    'evenements_geocoder' => 'route_evenements_geocoder',
    'evenements_suisa_exporter' => 'route_evenements_suisa_exporter',
    'evenements_suisa_exporter_apercu' => 'route_evenements_suisa_exporter_apercu',
    'evenement'          => 'route_evenement',
    'evenement_informations_enregistrer' => 'route_evenement_informations_enregistrer',
    'evenement_localisation_enregistrer' => 'route_evenement_localisation_enregistrer',
    'evenement_organisation_enregistrer' => 'route_evenement_organisation_enregistrer',
    'evenement_supprimer'   => 'route_evenement_supprimer',
    'evenement_suisa_enregistrer'    => 'route_evenement_suisa_enregistrer',
    'evenement_production_externe_enregistrer' => 'route_evenement_production_externe_enregistrer',
    'evenement_employe_lier'   => 'route_evenement_employe_lier',
    'evenement_employe_retirer' => 'route_evenement_employe_retirer',
    'evenement_ligne_ajouter'     => 'route_evenement_ligne_ajouter',
    'evenement_feuille_ajouter'   => 'route_evenement_feuille_ajouter',
    'evenement_feuille_imprimer'  => 'route_evenement_feuille_imprimer',
    'evenement_feuille_envoyer'     => 'route_evenement_feuille_envoyer',
    'evenement_feuille_modifier'  => 'route_evenement_feuille_modifier',
    'evenement_feuille_supprimer' => 'route_evenement_feuille_supprimer',
    'evenement_feuille_deplacer'  => 'route_evenement_feuille_deplacer',
    'evenement_feuille_ordre'     => 'route_evenement_feuille_ordre',
    'evenement_facture_lier'   => 'route_evenement_facture_lier',
    'evenement_facture_retirer' => 'route_evenement_facture_retirer',
    'facture_evenement_lier'   => 'route_facture_evenement_lier',
    'spectacles'         => 'route_spectacles',
    'spectacle'          => 'route_spectacle',
    'spectacle_delete'   => 'route_spectacle_delete',
    'spectacle_image'    => 'route_spectacle_image',
    'evenements_reglages' => 'route_evenements_reglages',
    'evenements_importer'  => 'route_evenements_importer',
]);
// Export public (site web / agenda externe) : protégé par un jeton dédié
// (evenements_verifier_token()), pas par une session utilisateur — reste
// accessible même à un visiteur non connecté, donc jamais soumis à
// peut_lire()/peut_ecrire() comme le reste du module.
if (module_actif('evenements')) {
    $handlers['evenements_exporter_json'] = 'route_evenements_exporter_json';
    $handlers['evenements_exporter_ical'] = 'route_evenements_exporter_ical';
    // Calendrier de l'équipe et pièces jointes qu'il référence : hors session,
    // protégés par leur propre jeton (feuille_jeton_equipe_fourni()). La route
    // des fichiers accepte AUSSI une session — c'est par elle que la fiche d'un
    // événement les télécharge.
    $handlers['evenements_equipe_exporter_ical'] = 'route_evenements_equipe_exporter_ical';
    $handlers['evenement_feuille_fichier']      = 'route_evenement_feuille_fichier';
}

// Géocodage d'une seule ville (mini-carte de localisation sur ?p=structure,
// ?p=evenement) : commun aux deux modules, accessible dès que l'un des deux
// est actif — écriture sur l'un ou l'autre suffit (le géocodage n'écrit que
// dans le cache partagé lieux_geocodage, jamais dans la fiche
// structure/événement elle-même).
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
        'employeur'       => 'route_employeur',
        'emails'          => 'route_emails',
        'emails_booking'  => 'route_emails_booking',
        'export'          => 'route_export',
        'pays' => 'route_pays',
    ];
    foreach (['parametres', 'employeur', 'emails', 'emails_booking', 'export', 'pays'] as $r) {
        $routeModules[$r] = ['coeur'];
    }
}
if (peut_ecrire('coeur')) {
    // Pas d'entrée dans $routeModules : ces routes n'ont pas de mode lecture
    // seule, elles sont entièrement réservées à l'écriture cœur (déjà
    // conditionnées par leur présence même dans $handlers, ci-dessus).
    $handlers += [
        'utilisateurs'             => 'route_utilisateurs',
        'utilisateur_enregistrer'     => 'route_utilisateur_enregistrer',
        'utilisateur_supprimer'       => 'route_utilisateur_supprimer',
        'modules'  => 'route_modules',
        'maj'                 => 'route_maj',
        'diagnostic'          => 'route_diagnostic',
        'apparence'           => 'route_apparence',
        'apparence_fond_supprimer' => 'route_apparence_fond_supprimer',
        'sauvegarde'              => 'route_sauvegarde',
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
