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
        'description' => 'Dates de concert/spectacle, suivi SUISA, export public JSON/iCal',
        'requires'    => [],
    ],
    'booking' => [
        'label'       => 'Booking',
        'description' => 'CRM des structures (salles, festivals, médias) : catégorie, contacts, notes, lieux, mailing ciblé, import CSV. Réutilise les structures (ex-débiteurs) de la Facturation, sans en dépendre.',
        'requires'    => [],
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
    return 'resumes';
}

// --------------------------------------------------------------------------
// Droits par module (lecture/écriture) — voir SPEC_PERMISSIONS.md.
//
// « coeur » n'est pas dans MODULES (jamais désactivable globalement, cf.
// MODULE_COEUR ci-dessus) mais c'est un module comme un autre du point de
// vue des droits : écriture sur coeur = administrateur (gestion des
// comptes/permissions, modules actifs, mises à jour, sauvegarde — voir
// index.php). Une table de permissions vide pour un utilisateur = aucun
// accès nulle part ; c'est le premier compte créé (route_setup) qui reçoit
// tout par défaut, pas les comptes suivants.
const PERMISSION_MODULES = ['coeur', 'salaires', 'compta', 'analytique', 'facturation', 'evenements', 'booking'];

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
// et route_compte_delete()).
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
            'fiches'   => ['Fiches de salaire', ['fiches', 'fiche', 'fiche_new'], nb_fiches_a_payer(), 'file-text'],
            'employes' => ['Employés', ['employes', 'employe', 'employe_voir'], 0, 'users'],
            'resume'   => ['Cotisations', ['resume'], 0, 'bar-chart'],
        ]];
    }

    $analytiqueOk = module_actif('analytique') && peut_lire('analytique');
    if (module_actif('compta') && peut_lire('compta')) {
        $onglets = [
            'compta_ecritures' => ['Écritures', ['compta', 'compta_ecritures', 'compta_lettrage', 'compta_import'], nb_ecritures_a_lettrer(), 'banknote'],
            'compta_comptes'   => ['Comptes bancaires', ['compta_comptes'], 0, 'landmark'],
            'compta_plan'      => ['Plan comptable', ['compta_plan'], 0, 'rows-3'],
            'compta_regles'    => ['Lettrage automatique', ['compta_regles'], 0, 'settings'],
            'compta_bilan'     => ['Comptes annuels', ['compta_bilan'], 0, 'book-open'],
        ];
        if ($analytiqueOk) {
            $onglets['compta_analyse'] = ['Analyse', ['compta_analyse', 'compta_analyse_axe', 'compta_axes'], 0, 'layers'];
        }
        $g['compta'] = ['Comptabilité', 'banknote', $onglets];
    }

    if (module_actif('facturation') && peut_lire('facturation')) {
        $g['facturation'] = ['Factures', 'receipt-swiss-franc', [
            'facturation_liste' => ['Factures', ['facturation', 'facturation_liste', 'facturation_form', 'facture'], nb_factures_en_retard(), 'receipt-swiss-franc'],
            'compta_comptes'    => ['Comptes bancaires', ['compta_comptes'], 0, 'landmark'],
            'structures'        => ['Structures', ['structures', 'structure', 'structure_fusion'], 0, 'house'],
        ]];
    }

    if (module_actif('evenements') && peut_lire('evenements')) {
        $g['evenements'] = ['Événements', 'calendar', [
            'evenements_liste' => ['Événements', ['evenements', 'evenements_liste', 'evenement'], nb_evenements_suisa_a_faire(), 'calendar'],
            'structures'       => ['Structures', ['structures', 'structure', 'structure_fusion'], 0, 'house'],
            'spectacles'       => [evenements_terme_spectacle(), ['spectacles', 'spectacle'], 0, 'music'],
        ]];
    }

    if (module_actif('booking') && peut_lire('booking')) {
        // Mailing n'a plus de sous-onglets propres (voir l'ancien
        // views/_mailing_tabs.php, retiré) : ses 4 pages deviennent des
        // onglets de premier niveau au même titre que Structures.
        $g['booking'] = ['Booking', 'house', [
            'structures'         => ['Structures', ['structures', 'structure', 'structure_fusion'], 0, 'house'],
            'mailing'            => ['Suivi', ['mailing'], 0, 'mail'],
            'mailing_campagne'   => ['Nouvelle campagne', ['mailing_campagne'], 0, 'send'],
            'mailing_modeles'    => ['Modèles', ['mailing_modeles'], 0, 'file-text'],
            'mailing_exclusions' => ["Liste d'exclusion", ['mailing_exclusions'], 0, 'mail-x'],
        ]];
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
    if ($depuis !== '' && in_array($depuis, $candidats, true)) {
        return $depuis;
    }
    // $depuis peut aussi être une référence d'objet « type:id » (convention de
    // lien_retour_contextuel(), lib/helpers.php — ex. depuis=evenement:42,
    // posée par un lien qui veut à la fois mettre en surbrillance le bon
    // groupe de nav ICI et permettre un retour précis vers cet objet une fois
    // sur la page cible). Complété au fil des besoins — seul le cas
    // evenement:id est utilisé aujourd'hui (lien structure depuis ?p=evenement).
    if ($depuis !== '' && preg_match('/^([a-z_]+):\d+$/', $depuis, $m)) {
        $groupeDuType = ['evenement' => 'evenements'][$m[1]] ?? null;
        if ($groupeDuType !== null && in_array($groupeDuType, $candidats, true)) {
            return $groupeDuType;
        }
    }
    foreach (['booking', 'compta', 'facturation', 'evenements'] as $prefere) {
        if (in_array($prefere, $candidats, true)) {
            return $prefere;
        }
    }
    return $candidats[0];
}
