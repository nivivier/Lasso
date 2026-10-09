<?php
// Registre des modules applicatifs, activables/désactivables indépendamment
// (association « salaires seuls », « compta seule », etc.). Le cœur — comptes
// utilisateurs, apparence, paramètres généraux — n'est jamais désactivable.
//
// Le schéma de base reste toujours créé en entier (lib/db.php) : désactiver un
// module masque ses routes et son entrée de menu, il ne touche pas aux données.
// Les réactiver restitue l'accès aux données existantes, intactes.

declare(strict_types=1);

const MODULES = [
    'salaires'   => [
        'label'       => 'Fiches de salaire',
        'description' => 'Employés, fiches de salaire, certificats de salaire, taux',
        'requires'    => [],
    ],
    'compta'     => [
        'label'       => 'Comptabilité',
        'description' => 'Relevés bancaires, plan comptable, écritures, comptes annuels',
        'requires'    => [],
    ],
    'analytique' => [
        'label'       => 'Comptabilité analytique',
        'description' => "Axes, ventilation des écritures, des charges sociales et des fiches de salaire",
        'requires'    => ['compta'],
    ],
    'facturation' => [
        'label'       => 'Facturation',
        'description' => 'Structures, factures (QR-facture suisse). Le rapprochement automatique des paiements et l\'import de relevés bancaires demandent en plus la Comptabilité, mais le marquage manuel « payée » fonctionne sans.',
        'requires'    => [],
    ],
    'evenements' => [
        'label'       => 'Événements',
        'description' => 'Dates de concert/projet, suivi SUISA, export public JSON/iCal',
        'requires'    => [],
    ],
    'booking' => [
        'label'       => 'Booking',
        'description' => 'CRM des structures (salles, festivals, médias) : catégorie, contacts, notes, lieux, messages individuels, import CSV. Réutilise les structures (ex-débiteurs) de la Facturation, sans en dépendre.',
        'requires'    => [],
    ],
    // Suivi des demandes de subvention (SPEC_SUBVENTIONS.md). Réutilise les
    // structures comme la Facturation et le Booking, sans dépendre d'eux : un
    // bailleur est une structure, et le projet financé un projet.
    'fonds' => [
        'label'       => 'Recherche de fonds',
        'description' => "Demandes de subvention : à qui l'on demande, pour quel projet, combien, où en est le dossier et ce qui a été obtenu. Les bailleurs sont des structures, comme les salles et les débiteurs.",
        'requires'    => [],
    ],
    // Sous-module : écrire à plusieurs structures d'un coup. Le booking seul
    // permet déjà d'écrire à UNE structure depuis sa fiche (bouton
    // « Contacter ») ; celui-ci ajoute le ciblage, la file d'attente et le
    // suivi des campagnes. Les modèles de message et la liste d'exclusion
    // restent au booking : le message individuel s'en sert aussi.
    'mailing' => [
        'label'       => 'Envois groupés',
        'description' => "Campagnes de mailing ciblé : sélection des structures, file d'attente à débit maîtrisé, suivi des envois et désinscription.",
        'requires'    => ['booking'],
    ],
];

// Couleur d'accent propre à chaque module — remplace --primary/--highlight
// (normalement personnalisables, Paramètres > Employeur > « Couleur
// principale ») sur les pages de ce module uniquement : rail (icône active)
// et interface (boutons, liens, badges, sommes…), pour les distinguer
// visuellement au premier coup d'œil. Fixe, jamais personnalisable — sinon
// deux modules pourraient entrer en collision avec la couleur choisie par
// l'employeur. Login, tableau de bord et Paramètres restent sur la couleur
// principale de l'employeur (voir module_couleur_css_vars(), lib/helpers.php,
// et nav_groupe_actif() : ces trois-là ne correspondent à aucun groupe).
// Contraste texte blanc dessus >= 4.5:1 (WCAG AA) vérifié pour les 5 —
// utilisées comme fond sous du texte blanc (.pagination-page.on,
// .param-subtabs a.on, voir assets/app.css) : une teinte trop claire y
// rendrait le texte illisible.
const MODULE_COULEURS = [
    'salaires'    => '#168176', // teal
    'compta'      => '#c25415', // ambre
    'facturation' => '#526be3', // indigo
    'evenements'  => '#e01670', // rose
    'booking'     => '#855cd5', // violet
    'fonds'       => '#2f6d2a', // vert — 6.28:1 sous texte blanc
];

// Cœur de l'application : jamais désactivable, listé à titre indicatif dans
// les paramètres de modules.
const MODULE_COEUR = [
    'label'       => 'Cœur',
    'description' => 'Comptes utilisateurs, apparence, informations de l\'employeur, mises à jour, gestion des modules',
];

// Modules actuellement activés. Par défaut : tous — préserve le comportement
// des installations existantes, qui n'ont jamais configuré ce réglage.
function modules_actifs(): array
{
    $val = param('modules_actifs', implode(',', array_keys(MODULES)));
    $ids = array_filter(array_map('trim', explode(',', (string) $val)), fn ($id) => $id !== '');
    return array_values(array_intersect(array_keys(MODULES), $ids));
}

function module_actif(string $id): bool
{
    return in_array($id, modules_actifs(), true);
}

// Enregistre la sélection de modules activés. Un module dont une dépendance
// est absente est retiré automatiquement : jamais d'état incohérent (ex.
// « analytique » actif sans « compta »).
function set_modules_actifs(array $ids): void
{
    $ids = array_values(array_intersect(array_keys(MODULES), $ids));
    do {
        $avant = $ids;
        foreach (MODULES as $id => $def) {
            if (!in_array($id, $ids, true)) {
                continue;
            }
            foreach ($def['requires'] as $req) {
                if (!in_array($req, $ids, true)) {
                    $ids = array_values(array_diff($ids, [$id]));
                }
            }
        }
    } while ($ids !== $avant);

    db()->prepare('INSERT OR REPLACE INTO parametres (cle, valeur) VALUES (?, ?)')
        ->execute(['modules_actifs', implode(',', $ids)]);
}

// Route d'atterrissage par défaut : le tableau de bord fait partie du cœur,
// toujours accessible quels que soient les modules actifs.
function route_defaut(): string
{
    return 'tableau_bord';
}

// --------------------------------------------------------------------------
// Droits par module (lecture/écriture) — voir SPEC_PERMISSIONS.md.
//
// « coeur » n'est pas dans MODULES (jamais désactivable globalement, cf.
// MODULE_COEUR ci-dessus) mais c'est un module comme un autre du point de
// vue des droits : écriture sur coeur = administrateur (gestion des
// comptes/permissions, modules actifs, mises à jour, sauvegarde — voir
// index.php). Une table de permissions vide pour un utilisateur = aucun
// accès nulle part ; c'est le premier compte créé (route_installation) qui reçoit
// tout par défaut, pas les comptes suivants.
const PERMISSION_MODULES = ['coeur', 'salaires', 'compta', 'analytique', 'facturation', 'evenements', 'booking', 'fonds', 'mailing'];

// --- Fonctions pures (testées sans base de données, tests/permissions_test.php) ---

// Présence d'une ligne (quel que soit son niveau) = accès en lecture — une
// ligne « ecriture » donne donc aussi la lecture, pas besoin d'une deuxième ligne.
function permission_donne_lecture(array $niveaux, string $module): bool
{
    return isset($niveaux[$module]);
}

function permission_donne_ecriture(array $niveaux, string $module): bool
{
    return ($niveaux[$module] ?? null) === 'ecriture';
}

// Un module dépendant (ex. analytique) ne peut jamais dépasser le niveau de
// sa dépendance (ex. compta) — même principe que la résolution en cascade de
// set_modules_actifs() pour l'activation globale. $niveaux : module =>
// 'lecture'|'ecriture' (absence de clé = aucun droit sur ce module).
function clamp_permissions_dependantes(array $niveaux): array
{
    $rang = ['lecture' => 1, 'ecriture' => 2];
    foreach (MODULES as $id => $def) {
        foreach ($def['requires'] as $req) {
            $niveauModule = $niveaux[$id] ?? null;
            if ($niveauModule === null) {
                continue;
            }
            $niveauReq = $niveaux[$req] ?? null;
            if ($niveauReq === null) {
                unset($niveaux[$id]);
            } elseif ($rang[$niveauModule] > $rang[$niveauReq]) {
                $niveaux[$id] = $niveauReq;
            }
        }
    }
    return $niveaux;
}

// --- Base de données --------------------------------------------------------

function permissions_utilisateur(int $utilisateurId): array
{
    $stmt = db()->prepare('SELECT module, niveau FROM utilisateur_permissions WHERE utilisateur_id = ?');
    $stmt->execute([$utilisateurId]);
    $out = [];
    foreach ($stmt as $r) {
        $out[$r['module']] = $r['niveau'];
    }
    return $out;
}

// Droits de l'utilisateur courant. Mémoïsé (même esprit que current_user()) :
// appelé plusieurs fois par requête (dispatch, sidebar, vues).
function permissions_utilisateur_courant(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $u = current_user();
    return $cache = $u ? permissions_utilisateur((int) $u['id']) : [];
}

function peut_lire(string $module): bool
{
    return permission_donne_lecture(permissions_utilisateur_courant(), $module);
}

// « Ce module est activé ET l'utilisateur courant a le droit de le lire. »
//
// Les deux conditions sont indépendantes et doivent TOUJOURS être posées
// ensemble pour décider d'afficher quelque chose : module_actif() est un
// réglage global de l'installation, peut_lire() un droit propre au compte.
// Tester le premier seul laisse fuiter les données d'un module vers un
// utilisateur qui n'y a pas accès — c'était exactement le cas du tableau de
// bord, qui affichait fiches de salaire, factures et événements sur la seule
// foi de module_actif(), alors que le rail de navigation, lui, vérifiait bien
// les deux. Un compte limité au booking y voyait les salaires.
function module_accessible(string $id): bool
{
    return module_actif($id) && peut_lire($id);
}

function peut_ecrire(string $module): bool
{
    return permission_donne_ecriture(permissions_utilisateur_courant(), $module);
}

// Administrateur = écriture sur le module coeur (voir commentaire PERMISSION_MODULES).
function est_admin(): bool
{
    return peut_ecrire('coeur');
}

function require_lecture(string $module): void
{
    require_login();
    if (!peut_lire($module)) {
        redirect(route_defaut(), ['refuse' => 1]);
    }
}

function require_ecriture(string $module): void
{
    require_login();
    if (!peut_ecrire($module)) {
        redirect(route_defaut(), ['refuse' => 1]);
    }
}

// Nombre de comptes ayant l'écriture sur coeur (administrateurs) — garde-fou :
// il doit toujours en rester au moins un (voir enregistrer_permissions_utilisateur()
// et route_utilisateur_supprimer()).
function nb_admins(): int
{
    return (int) db()
        ->query("SELECT COUNT(DISTINCT utilisateur_id) FROM utilisateur_permissions WHERE module = 'coeur' AND niveau = 'ecriture'")
        ->fetchColumn();
}

// Enregistre la matrice de droits d'un utilisateur (POST de l'écran Comptes).
// $niveauxBruts : module => valeur brute d'un <select> HTML ('', 'lecture'
// ou 'ecriture' ; '' = aucun droit). Refuse silencieusement (retourne false)
// si l'opération viderait le dernier compte administrateur.
function enregistrer_permissions_utilisateur(int $utilisateurId, array $niveauxBruts): bool
{
    $niveaux = [];
    foreach (PERMISSION_MODULES as $module) {
        $v = (string) ($niveauxBruts[$module] ?? '');
        if (in_array($v, ['lecture', 'ecriture'], true)) {
            $niveaux[$module] = $v;
        }
    }
    $niveaux = clamp_permissions_dependantes($niveaux);

    $etaitAdmin = permission_donne_ecriture(permissions_utilisateur($utilisateurId), 'coeur');
    $resteAdmin = permission_donne_ecriture($niveaux, 'coeur');
    if ($etaitAdmin && !$resteAdmin && nb_admins() <= 1) {
        return false;
    }

    db()->beginTransaction();
    db()->prepare('DELETE FROM utilisateur_permissions WHERE utilisateur_id = ?')->execute([$utilisateurId]);
    $stmt = db()->prepare('INSERT INTO utilisateur_permissions (utilisateur_id, module, niveau) VALUES (?, ?, ?)');
    foreach ($niveaux as $module => $niveau) {
        $stmt->execute([$utilisateurId, $module, $niveau]);
    }
    db()->commit();
    return true;
}

// --- Support du dispatch (index.php) ---------------------------------------

// Ajoute un bloc de routes propres à un module optionnel, seulement s'il est
// actif — et mémorise pour chacune le module dont dépend le droit d'accès
// (route_autorisee(), ci-dessous).
function ajouter_routes_module(array &$handlers, array &$routeModules, string $module, array $routes): void
{
    if (!module_actif($module)) {
        return;
    }
    $handlers += $routes;
    foreach (array_keys($routes) as $r) {
        $routeModules[$r] = [$module];
    }
}

// Vrai si l'utilisateur courant a le droit d'accéder à la route associée à
// ces module(s) — lecture pour un affichage (GET), écriture pour une
// mutation (POST ; convention stricte du projet, voir index.php). Plusieurs
// modules = accès si l'un d'eux suffit (ex. comptes bancaires, partagés
// compta/facturation).
// Cœur de la décision, sans base ni superglobale — testable en isolation
// (tests/permissions_test.php), même découpage que
// permission_donne_lecture()/peut_lire() plus haut. C'est ici que vit
// l'invariante centrale du modèle de droits : lecture pour un affichage,
// écriture pour une mutation, et « au moins un des modules suffit » quand une
// route en couvre plusieurs. $niveaux : module => 'lecture'|'ecriture'.
function route_autorisee_pour(array $niveaux, array $modules, string $methode): bool
{
    $lecture  = false;
    $ecriture = false;
    foreach ($modules as $m) {
        $lecture  = $lecture  || permission_donne_lecture($niveaux, $m);
        $ecriture = $ecriture || permission_donne_ecriture($niveaux, $m);
    }
    if (!$lecture) {
        return false;
    }
    return $methode !== 'POST' || $ecriture;
}

function route_autorisee(array $modules): bool
{
    return route_autorisee_pour(
        permissions_utilisateur_courant(),
        $modules,
        (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')
    );
}

// --- Navigation (rail d'icônes + bandeau d'onglets) -------------------------
// Source de vérité unique pour le regroupement des pages par module, utilisée
// à la fois par le rail (views/layout.php) et le bandeau d'onglets
// (views/_module_tabs.php) — pour ne jamais avoir la liste des routes/onglets
// à tenir synchronisée à deux endroits (comme c'était le cas avant, avec la
// logique inline dans layout.php). Un groupe = [Libellé, icône, [onglets]] ;
// chaque onglet = clé de route => [Libellé, [routes qui le mettent en
// surbrillance], badge (0 = aucun), icône]. Absent du tableau = module
// inactif ou hors des droits de l'utilisateur courant (mêmes conditions
// qu'avant).
// Mémoïsée (même pattern que permissions_utilisateur_courant() plus haut) :
// appelée une fois par layout.php (rail) et une seconde fois par
// _module_tabs.php (bandeau) sur chacune des pages retrofitées — sans cache,
// chacun des 4 compteurs de badge embarqués (nb_fiches_a_payer(),
// nb_ecritures_a_lettrer(), nb_factures_en_retard(),
// nb_evenements_suisa_a_faire()) exécutait sa requête DB deux fois par
// page, pour un résultat strictement identique dans la même requête HTTP.
function nav_groupes(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $g = [];

    if (module_actif('salaires') && peut_lire('salaires')) {
        $g['salaires'] = ['Salaires', 'file-text', [
            // « Fiches » et non « Fiches de salaire » : le titre du module, à
            // deux centimètres à gauche, dit déjà « Salaires ». Un onglet ne
            // répète pas le bandeau qui le porte.
            'fiches'   => ['Fiches', ['fiches', 'fiche', 'fiche_form', 'fiche_modifier'], nb_fiches_a_payer(), 'file-text'],
            'employes' => ['Employés', ['employes', 'employe_form', 'employe'], 0, 'users'],
            'cotisations'   => ['Cotisations', ['cotisations'], 0, 'bar-chart'],
        ]];
    }

    $analytiqueOk = module_actif('analytique') && peut_lire('analytique');
    if (module_actif('compta') && peut_lire('compta')) {
        $onglets = [
            'compta_ecritures' => ['Écritures', ['compta_ecritures', 'compta_ecritures_importer'], nb_ecritures_a_lettrer(), 'banknote'],
            'compta_bilan'     => ['Comptes annuels', ['compta_bilan'], 0, 'book-open'],
        ];
        if ($analytiqueOk) {
            $onglets['compta_analyse'] = ['Analyse', ['compta_analyse', 'compta_analyse_axe', 'compta_axes'], 0, 'layers'];
        }
        // Le plan comptable TOUT À DROITE, et ajouté après l'analyse pour y
        // rester que celle-ci soit active ou non : on y règle la machine, on
        // ne s'en sert pas au quotidien. Les onglets vont ainsi du travail
        // courant à ce qui le rend possible.
        //
        // Il porte TROIS écrans, en sous-onglets. Les deux premiers sont le
        // même objet — la liste des comptes —, l'un pour ce qu'on gagne et
        // dépense, l'autre pour les endroits où l'argent dort. Le troisième dit
        // comment une écriture y tombe toute seule : une règle de lettrage ne
        // vaut que par la catégorie du plan qu'elle désigne, elle n'a pas de
        // sens hors de lui.
        // 5e élément = les sous-onglets [route => libellé] ; la 2e entrée (les
        // routes) doit les contenir tous, c'est elle qui allume l'onglet.
        $onglets['compta_plan'] = ['Plan comptable', ['compta_plan', 'compta_comptes', 'compta_regles'], 0, 'rows-3', [
            'compta_plan'    => 'Produits et charges',
            'compta_comptes' => 'Comptes bancaires',
            'compta_regles'  => 'Lettrage automatique',
        ]];
        $g['compta'] = ['Comptabilité', 'banknote', $onglets];
    }

    if (module_actif('facturation') && peut_lire('facturation')) {
        $g['facturation'] = ['Factures', 'receipt-swiss-franc', [
            'factures' => ['Factures', ['factures', 'facture_form', 'facture'], nb_factures_en_retard(), 'receipt-swiss-franc'],
            'compta_comptes'    => ['Comptes bancaires', ['compta_comptes'], 0, 'landmark'],
            'structures'        => ['Structures', ['structures', 'structure', 'structure_fusion'], 0, 'house'],
        ]];
    }

    // L'onglet des projets (projets). Il appartient au module Événements —
    // c'est lui qui les tient —, mais trois modules travaillent dessus : une
    // campagne de démarchage vise des projets, une campagne de recherche de
    // fonds en finance un. L'onglet est donc repris dans Booking et dans
    // Recherche de fonds, à la condition stricte que le module Événements soit
    // allumé ET lisible : la route `projets` lui est rattachée (index.php),
    // un compte qui n'y a pas accès se verrait proposer une page refusée.
    $ongletProjets = [evenements_terme_projet(), ['projets', 'projet'], 0, 'music'];
    $projetsAccessibles = module_accessible('evenements');

    if (module_actif('evenements') && peut_lire('evenements')) {
        $g['evenements'] = ['Événements', 'calendar', [
            'evenements' => ['Événements', ['evenements', 'evenement'], nb_evenements_suisa_a_faire(), 'calendar'],
            'structures'       => ['Structures', ['structures', 'structure', 'structure_fusion'], 0, 'house'],
            'projets'       => $ongletProjets,
        ]];
    }

    if (module_actif('booking') && peut_lire('booking')) {
        // Mailing n'a plus de sous-onglets propres (voir l'ancien
        // views/_mailing_tabs.php, retiré) : ses 4 pages deviennent des
        // onglets de premier niveau au même titre que Structures.
        $ongletsBooking = [
            'structures'         => ['Structures', ['structures', 'structure', 'structure_fusion'], 0, 'house'],
            // Campagnes de contact : indépendantes du sous-module « Envois
            // groupés », puisqu'on y démarche structure par structure.
            'booking_campagnes'          => ['Campagnes', ['booking_campagnes', 'booking_campagne', 'booking_campagne_form'], 0, 'target'],
        ];
        // Les projets, juste après les campagnes qui les portent : c'est sur eux
        // qu'on démarche, et l'onglet évite de sortir du module pour les régler.
        if ($projetsAccessibles) {
            $ongletsBooking['projets'] = $ongletProjets;
        }
        // Suivi et Nouvelle campagne appartiennent au sous-module « Envois
        // groupés » : sans lui, le booking garde ses structures, ses modèles de
        // message et sa liste d'exclusion — dont se sert le bouton
        // « Contacter » d'une fiche — mais plus le mailing de masse.
        if (module_actif('mailing') && peut_lire('mailing')) {
            $ongletsBooking['mailing'] = ['Suivi', ['mailing'], 0, 'mail'];
            $ongletsBooking['mailing_campagne'] = ['Nouvelle campagne', ['mailing_campagne'], 0, 'send'];
        }
        $ongletsBooking['mailing_modeles'] = ['Modèles', ['mailing_modeles'], 0, 'file-text'];
        $ongletsBooking['mailing_exclusions'] = ["Liste d'exclusion", ['mailing_exclusions'], 0, 'mail-x'];
        // Raccourci vers le groupe « Catégories » des paramètres (Pays,
        // Catégories, Tags) : ces trois référentiels ne servent
        // pratiquement qu'au booking, mais vivent dans Paramètres — l'onglet
        // évite d'en ressortir pour y aller. Il mène à la première sous-page
        // ACCESSIBLE : Pays relève du module « cœur » et non du booking (voir
        // index.php), un compte booking sans droit cœur y serait refusé.
        // Les trois routes restent listées pour la mise en surbrillance : une
        // fois sur place, c'est la barre d'onglets des paramètres qui prend le
        // relais pour naviguer entre elles (views/_param_tabs.php).
        $ongletsBooking[peut_lire('coeur') ? 'pays' : 'categories_structures'] =
            ['Catégories', ['pays', 'categories_structures', 'tags'], 0, 'blocks'];
        $g['booking'] = ['Booking', 'house', $ongletsBooking];
    }

    if (module_actif('fonds') && peut_lire('fonds')) {
        $ongletsFonds = [
            'fonds_campagnes' => ['Campagnes', ['fonds_campagnes', 'fonds_campagne', 'fonds_campagne_form', 'fonds_demande'], 0, 'landmark'],
            // Les bailleurs sont des structures : le même écran que le booking
            // et la facturation, pas une seconde liste à tenir.
            'structures' => ['Structures', ['structures', 'structure', 'structure_fusion'], 0, 'house'],
        ];
        // Ce qu'une campagne finance est un projet, et c'est lui qui porte l'axe
        // analytique de la ventilation (migration_91) : on le règle sans quitter
        // le module.
        if ($projetsAccessibles) {
            $ongletsFonds['projets'] = $ongletProjets;
        }
        $g['fonds'] = ['Recherche de fonds', 'landmark', $ongletsFonds];
    }

    return $cache = $g;
}

// Résout quel groupe de nav_groupes() doit être mis en surbrillance (rail
// ET bandeau d'onglets) pour la route courante. La plupart des routes
// n'appartiennent qu'à un seul groupe — mais 'structures'/'structure' peut
// appartenir à trois (Factures/Événements/Booking) puisque c'est une page
// partagée : on préfère $depuis s'il désigne un groupe valide contenant la
// route, sinon on retombe sur un ordre de priorité fixe (Booking d'abord,
// propriétaire du CRM des structures — voir SPEC_BOOKING.md ; Comptabilité
// avant Facturation pour compta_comptes, également partagée entre ces deux
// groupes, car les comptes bancaires sont d'abord une notion comptable).
// Les groupes de la section « Paramètres » : [clé => [libellé, [route => section],
// [alias de route]]]. Même rôle que nav_groupes() pour les modules, et même
// raison d'être ici plutôt que dans la vue : le gabarit doit savoir, AVANT que
// la vue ne s'exécute, si la route courante appartient aux paramètres — c'est
// ce qui met « Paramètres » dans la barre supérieure sur téléphone. Le partiel
// views/_param_tabs.php rend les onglets à partir de la même liste : une seule
// source, sinon les deux divergent au premier écran ajouté.
//
// Le contenu dépend des droits et des modules actifs : un groupe dont aucune
// section n'est lisible n'existe pas.
function parametres_groupes(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $groupes = [];

    // Application : administration (écriture cœur), voir index.php.
    if (peut_ecrire('coeur')) {
        $groupes['application'] = ['Application', [
            'maj'                => 'Mises à jour',
            'apparence'          => 'Apparence',
            'modules'            => 'Modules',
            'utilisateurs'       => 'Utilisateurs',
            'diagnostic'         => 'Serveur',
        ]];
    }

    $groupes['employeur'] = ['Employeur', ['employeur' => 'Employeur']];
    // Deux écrans bien distincts : rien n'est partagé entre les envois généraux
    // (fiches de salaire, factures) et ceux du booking — ni adresses, ni serveur,
    // ni rythme d'envoi.
    $emails = ['emails' => 'Envois généraux'];
    if (module_actif('booking')) {
        $emails['emails_booking'] = 'Envois pour le booking';
    }
    $groupes['emails']    = ['E-mails', $emails];

    // « Valeurs et libellés » plutôt que « Taux » : le groupe ne porte pas que des
    // pourcentages. Une ligne de décompte y a aussi son intitulé — celui que les
    // fiches figent à l'enregistrement —, et les réglages des événements y posent
    // le terme qui désigne un projet, dans toute l'interface.
    //
    // Construit section par section, comme « Catégories » juste en dessous : deux
    // modules l'alimentent, et le groupe doit exister dès que l'un des deux est là.
    $valeurs = [];
    if (module_actif('salaires') && peut_lire('salaires')) {
        $valeurs['postes']        = 'Lignes du décompte';
        $valeurs['taux_horaires'] = 'Salaires horaires et unités';
    }
    if (module_actif('evenements') && peut_lire('evenements')) {
        $valeurs['evenements_reglages'] = 'Événements';
    }
    if (module_actif('fonds') && peut_lire('fonds')) {
        $valeurs['fonds_reglages'] = 'Recherche de fonds';
    }
    if ($valeurs) {
        $groupes['valeurs'] = ['Valeurs et libellés', $valeurs];
    }

    $categories = ['pays' => 'Pays'];
    if (module_actif('booking') && peut_lire('booking')) {
        $categories['categories_structures'] = 'Catégories';
        $categories['tags']                  = 'Tags';
    }
    $groupes['categories'] = ['Catégories', $categories];

    // Données : Importer/Exporter/Incohérences regroupés sous un seul onglet
    // principal, avec ces 3 sections en sous-onglets.
    // Importer : le sous-onglet pointe sur ?p=import, la page elle-même. Les routes
    // de traitement (une par module) reviennent rendre cette même page avec leurs
    // résultats : elles comptent donc aussi comme la section active, en alias (3ᵉ
    // élément du groupe, voir la mise en surbrillance plus bas). Pas de sous-onglet
    // du tout si aucun module importable n'est lisible — la page n'offrirait rien.
    $routesImport = [];
    if (module_actif('salaires')    && peut_lire('salaires'))    $routesImport[] = 'fiches_importer';
    if (module_actif('facturation') && peut_lire('facturation')) $routesImport[] = 'factures_importer';
    if (module_actif('compta')      && peut_lire('compta'))      $routesImport[] = 'compta_ecritures_importer';
    if (module_actif('evenements')  && peut_lire('evenements'))  $routesImport[] = 'evenements_importer';
    if (module_actif('booking')     && peut_lire('booking'))     $routesImport[] = 'structures_importer';
    $donnees = [];
    if ($routesImport) {
        $donnees['import'] = 'Importer';
        array_unshift($routesImport, 'import');
    }
    $donnees['export'] = 'Exporter';
    // Les jetons d'export : une porte vers l'extérieur, pas un réglage d'affichage.
    if (module_actif('evenements') && peut_lire('evenements')) {
        $donnees['synchronisation'] = 'Synchronisation';
    }
    if (peut_ecrire('coeur')) {
        $donnees['dev'] = 'Incohérences';
    }
    $groupes['donnees'] = ['Données', $donnees, $routesImport];

    return $cache = $groupes;
}

// L'adresse d'accueil d'un module : la première route de son bandeau d'onglets,
// celle sur laquelle son icône du rail mène déjà. Rend '' si le module n'est
// pas dans nav_groupes() — éteint, ou hors des droits du compte —, ce qui
// permet à l'appelant de ne PAS poser de lien plutôt que d'en poser un mort.
//
// Sert aux titres des cartes du tableau de bord (views/tableau_bord.php) :
// chacune mène au module dont elle montre un extrait, sans que la vue ait à
// écrire sept adresses qui dérivaient au premier onglet renommé.
function nav_groupe_accueil(string $cle): string
{
    $groupes = nav_groupes();
    if (!isset($groupes[$cle][2])) {
        return '';
    }
    $premiere = array_key_first($groupes[$cle][2]);
    return $premiere === null ? '' : '?p=' . $premiere;
}

// Le groupe actif pour une route — par ses sections ou ses alias. Null si la
// route n'est pas une page de paramètres.
function parametres_groupe_actif(array $groupes, string $route): ?string
{
    foreach ($groupes as $cle => $g) {
        if (in_array($route, array_keys($g[1]), true) || in_array($route, $g[2] ?? [], true)) {
            return $cle;
        }
    }
    return null;
}

function nav_groupe_actif(array $groupes, string $route, string $depuis = ''): ?string
{
    $candidats = [];
    foreach ($groupes as $cle => $g) {
        foreach ($g[2] as $ongletCle => $onglet) {
            if ($ongletCle === $route || in_array($route, $onglet[1], true)) {
                $candidats[] = $cle;
                break;
            }
        }
    }
    if (!$candidats) {
        return null;
    }
    // Les trois référentiels de Paramètres → Catégories figurent dans le
    // bandeau Booking (onglet « Catégories »), mais ils VIVENT dans les
    // paramètres : ils n'appartiennent à un module que si le lien le dit.
    // Sans ce cas particulier, y arriver par Paramètres allumait quand même
    // Booking dans le rail, sous un bandeau « Paramètres ».
    if (in_array($route, ['pays', 'categories_structures', 'tags'], true)) {
        return $depuis !== '' && in_array($depuis, $candidats, true) ? $depuis : null;
    }
    if ($depuis !== '' && in_array($depuis, $candidats, true)) {
        return $depuis;
    }
    // $depuis peut aussi être une référence d'objet « type:id » (convention de
    // lien_retour_contextuel(), lib/helpers.php — ex. depuis=evenement:42,
    // posée par un lien qui veut à la fois mettre en surbrillance le bon
    // groupe de nav ICI et permettre un retour précis vers cet objet une fois
    // sur la page cible). Complété au fil des besoins : lien vers une structure
    // depuis un événement, ou depuis le suivi d'une campagne.
    if ($depuis !== '' && preg_match('/^([a-z_]+):\d+$/', $depuis, $m)) {
        $groupeDuType = ['evenement' => 'evenements', 'booking_campagne' => 'booking',
                         'fonds_demande' => 'fonds'][$m[1]] ?? null;
        if ($groupeDuType !== null && in_array($groupeDuType, $candidats, true)) {
            return $groupeDuType;
        }
    }
    // Certaines routes partagées ont un groupe PROPRIÉTAIRE sans ambiguïté :
    // les projets sont tenus par le module Événements, Booking et Recherche de
    // fonds ne font que les reprendre en onglet. Sans cette table, l'ordre
    // fixe ci-dessous allumerait Booking sur un ?p=projets arrivé sans
    // provenance — depuis le rail, un signet ou la recherche unifiée.
    $proprietaire = ['projets' => 'evenements', 'projet' => 'evenements'][$route] ?? null;
    if ($proprietaire !== null && in_array($proprietaire, $candidats, true)) {
        return $proprietaire;
    }
    foreach (['booking', 'compta', 'facturation', 'evenements'] as $prefere) {
        if (in_array($prefere, $candidats, true)) {
            return $prefere;
        }
    }
    return $candidats[0];
}
