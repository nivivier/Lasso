<?php
// Connexion SQLite + initialisation du schéma et des données par défaut.

require_once __DIR__ . '/config.php'; // APP_DB_PATH, APP_ENV…
require_once __DIR__ . '/calc.php';   // TAUX_DEFAUT

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $path    = APP_DB_PATH;
    $dataDir = dirname($path);
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0770, true);
    }

    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    // LOWER() intégré de SQLite ne repasse en minuscule que l'ASCII (ex.
    // « GENÈVE » -> « genÈve », le È reste inchangé) : sans extension ICU
    // (absente d'un hébergement mutualisé), une ville saisie avec une
    // majuscule accentuée ne matche jamais sa clé de cache géocodage
    // (lieux_geocodage.cle, construite en PHP via mb_strtolower) — voir
    // geocodage_non_localises_where()/geocodage_villes_manquantes(),
    // lib/geocodage.php, qui utilisent LOWER_UTF8() à la place.
    // @ : sqliteCreateFunction() est dépréciée depuis PHP 8.5 au profit de
    // Pdo\Sqlite::createFunction(), mais new PDO('sqlite:...') ne renvoie pas
    // cette sous-classe ici — l'ancienne méthode reste la seule disponible.
    @$pdo->sqliteCreateFunction('LOWER_UTF8', fn ($s) => mb_strtolower((string) $s, 'UTF-8'), 1);

    init_schema($pdo);
    return $pdo;
}

// Sauvegarde horodatée de la base dans le dossier data/, snapshot cohérent via
// VACUUM INTO (indépendant du WAL). À appeler AVANT une opération de masse
// risquée (import…) pour pouvoir revenir en arrière. Renvoie le chemin du
// fichier créé, ou null en cas d'échec (sans interrompre l'opération). Les
// fichiers .bak sont exclus du versionnement (.gitignore).
function sauvegarder_base(string $etiquette): ?string
{
    $etiquette = preg_replace('/[^a-z0-9]+/i', '_', $etiquette);
    $etiquette = trim((string) $etiquette, '_') ?: 'sauvegarde';
    $chemin = dirname(APP_DB_PATH) . '/' . basename(APP_DB_PATH, '.sqlite')
        . '_' . $etiquette . '_' . date('Ymd_His') . '.sqlite.bak';
    try {
        db()->exec('VACUUM INTO ' . db()->quote($chemin));
        return is_file($chemin) ? $chemin : null;
    } catch (\Throwable $e) {
        return null;
    }
}

function init_schema(PDO $pdo): void
{
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS utilisateurs (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            email        TEXT NOT NULL UNIQUE,
            mot_de_passe TEXT NOT NULL,
            cree_le      TEXT NOT NULL DEFAULT (datetime('now'))
        );

        CREATE TABLE IF NOT EXISTS parametres (
            cle    TEXT PRIMARY KEY,
            valeur TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS login_attempts (
            id      INTEGER PRIMARY KEY AUTOINCREMENT,
            ip      TEXT NOT NULL,
            email   TEXT NOT NULL DEFAULT '',
            cree_le INTEGER NOT NULL
        );

        CREATE TABLE IF NOT EXISTS taux_par_annee (
            annee  INTEGER NOT NULL,
            cle    TEXT NOT NULL,
            valeur TEXT NOT NULL,
            PRIMARY KEY (annee, cle)
        );

        CREATE TABLE IF NOT EXISTS taux_horaires (
            id      INTEGER PRIMARY KEY AUTOINCREMENT,
            libelle TEXT NOT NULL,
            montant REAL NOT NULL
        );

        CREATE TABLE IF NOT EXISTS unites (
            id      INTEGER PRIMARY KEY AUTOINCREMENT,
            libelle TEXT NOT NULL,
            heures  REAL NOT NULL
        );

        CREATE TABLE IF NOT EXISTS fiche_lignes (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            fiche_id     INTEGER NOT NULL REFERENCES fiches(id) ON DELETE CASCADE,
            libelle      TEXT NOT NULL,
            heures_unite REAL NOT NULL,
            quantite     REAL NOT NULL,
            taux_horaire REAL NOT NULL DEFAULT 0,
            ordre        INTEGER NOT NULL DEFAULT 0
        );

        CREATE TABLE IF NOT EXISTS employes (
            id                 INTEGER PRIMARY KEY AUTOINCREMENT,
            prenom             TEXT NOT NULL,
            nom                TEXT NOT NULL,
            email              TEXT NOT NULL DEFAULT '',
            rue                TEXT NOT NULL DEFAULT '',
            npa_localite       TEXT NOT NULL DEFAULT '',
            numero_avs         TEXT NOT NULL DEFAULT '',
            date_naissance     TEXT NOT NULL DEFAULT '',
            canton             TEXT NOT NULL DEFAULT 'Genève',
            procedure          TEXT NOT NULL DEFAULT 'Ordinaire',
            salaire_horaire    REAL NOT NULL DEFAULT 0,
            supplement_vacances REAL NOT NULL DEFAULT 0.0833,
            impot_source_taux  REAL NOT NULL DEFAULT 0,
            actif              INTEGER NOT NULL DEFAULT 1,
            cree_le            TEXT NOT NULL DEFAULT (datetime('now'))
        );

        CREATE TABLE IF NOT EXISTS fiches (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            employe_id      INTEGER NOT NULL REFERENCES employes(id) ON DELETE CASCADE,
            annee           INTEGER NOT NULL,
            mois            INTEGER NOT NULL,
            date_paiement   TEXT NOT NULL DEFAULT '',
            -- Snapshot des données employé au moment de la création
            employe_nom         TEXT NOT NULL,
            employe_rue         TEXT NOT NULL,
            employe_npa         TEXT NOT NULL,
            employe_avs         TEXT NOT NULL,
            canton              TEXT NOT NULL,
            procedure           TEXT NOT NULL,
            salaire_horaire     REAL NOT NULL,
            nombre_heures       REAL NOT NULL,
            supplement_taux     REAL NOT NULL,
            -- Montants calculés (figés)
            salaire_travail     REAL NOT NULL,
            supplement_montant  REAL NOT NULL,
            salaire_brut        REAL NOT NULL,
            ded_avs             REAL NOT NULL,
            ded_ac              REAL NOT NULL,
            ded_amat            REAL NOT NULL,
            ded_laa             REAL NOT NULL,
            ded_lpp             REAL NOT NULL,
            ded_impot_source    REAL NOT NULL,
            ded_caf             REAL NOT NULL,
            total_deductions    REAL NOT NULL,
            salaire_net         REAL NOT NULL,
            -- Charges patronales (employeur), figées
            emp_avs             REAL NOT NULL DEFAULT 0,
            emp_ac              REAL NOT NULL DEFAULT 0,
            emp_amat            REAL NOT NULL DEFAULT 0,
            emp_af              REAL NOT NULL DEFAULT 0,
            emp_laa             REAL NOT NULL DEFAULT 0,
            emp_frais           REAL NOT NULL DEFAULT 0,
            emp_cpe             REAL NOT NULL DEFAULT 0,
            emp_lfp             REAL NOT NULL DEFAULT 0,
            emp_lpp             REAL NOT NULL DEFAULT 0,
            total_charges_emp   REAL NOT NULL DEFAULT 0,
            cout_total_emp      REAL NOT NULL DEFAULT 0,
            afficher_cout_emp   INTEGER NOT NULL DEFAULT 0,
            email_envoye_le     TEXT NOT NULL DEFAULT '',
            -- Taux utilisés (figés), JSON
            taux_json           TEXT NOT NULL DEFAULT '{}',
            cree_le             TEXT NOT NULL DEFAULT (datetime('now')),
            UNIQUE(employe_id, annee, mois)
        );

        -- ===================================================== COMPTABILITÉ
        -- Comptes bancaires d'où proviennent les écritures (1 IBAN = 1 compte).
        CREATE TABLE IF NOT EXISTS comptes_bancaires (
            id      INTEGER PRIMARY KEY AUTOINCREMENT,
            libelle TEXT NOT NULL,
            iban    TEXT NOT NULL DEFAULT '' UNIQUE,
            ordre   INTEGER NOT NULL DEFAULT 0,
            actif   INTEGER NOT NULL DEFAULT 1,
            cree_le TEXT NOT NULL DEFAULT (datetime('now'))
        );

        -- Plan comptable « de caisse » : catégories de produits / charges,
        -- regroupées par « groupe » pour le compte de résultat.
        CREATE TABLE IF NOT EXISTS plan_comptes (
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            libelle   TEXT NOT NULL,
            sens      TEXT NOT NULL DEFAULT 'charge', -- 'produit' | 'charge' (= sens de la racine)
            parent_id INTEGER REFERENCES plan_comptes(id), -- NULL = catégorie principale (racine)
            groupe    TEXT NOT NULL DEFAULT '',           -- hérité (ancien schéma) ; remplacé par parent_id
            ordre     INTEGER NOT NULL DEFAULT 0,          -- rang parmi les frères
            actif     INTEGER NOT NULL DEFAULT 1,
            cree_le   TEXT NOT NULL DEFAULT (datetime('now'))
        );

        -- Un import = un fichier CSV chargé pour un compte (traçabilité).
        CREATE TABLE IF NOT EXISTS imports (
            id                 INTEGER PRIMARY KEY AUTOINCREMENT,
            compte_bancaire_id INTEGER NOT NULL REFERENCES comptes_bancaires(id) ON DELETE CASCADE,
            nom_fichier        TEXT NOT NULL DEFAULT '',
            date_debut         TEXT NOT NULL DEFAULT '',
            date_fin           TEXT NOT NULL DEFAULT '',
            nb_total           INTEGER NOT NULL DEFAULT 0,
            nb_importees       INTEGER NOT NULL DEFAULT 0,
            nb_doublons        INTEGER NOT NULL DEFAULT 0,
            importe_le         TEXT NOT NULL DEFAULT (datetime('now'))
        );

        -- Écritures (mouvements bancaires). montant > 0 crédit, < 0 débit.
        -- plan_compte_id NULL = non lettré. hash = clé de dédoublonnage.
        CREATE TABLE IF NOT EXISTS ecritures (
            id                 INTEGER PRIMARY KEY AUTOINCREMENT,
            compte_bancaire_id INTEGER NOT NULL REFERENCES comptes_bancaires(id) ON DELETE CASCADE,
            import_id          INTEGER REFERENCES imports(id) ON DELETE SET NULL,
            date_op            TEXT NOT NULL,
            texte              TEXT NOT NULL DEFAULT '',
            tiers              TEXT NOT NULL DEFAULT '',  -- contre-partie extraite (donneur d'ordre / expéditeur)
            communication      TEXT NOT NULL DEFAULT '',  -- communication / référence extraite
            montant            REAL NOT NULL DEFAULT 0,
            solde              REAL,
            plan_compte_id     INTEGER REFERENCES plan_comptes(id) ON DELETE SET NULL,
            origine_lettrage   TEXT NOT NULL DEFAULT '', -- 'regle' | 'manuel' | ''
            hash               TEXT NOT NULL UNIQUE,
            cree_le            TEXT NOT NULL DEFAULT (datetime('now'))
        );

        -- Règles de lettrage automatique. compte_bancaire_id NULL = globale.
        -- motif/type_match/sens_filtre conservés pour compatibilité ascendante (migration_10 → conditions_lettrage).
        CREATE TABLE IF NOT EXISTS regles_lettrage (
            id                 INTEGER PRIMARY KEY AUTOINCREMENT,
            compte_bancaire_id INTEGER REFERENCES comptes_bancaires(id) ON DELETE CASCADE,
            motif              TEXT,
            type_match         TEXT,
            sens_filtre        TEXT,
            montant_min        REAL,
            montant_max        REAL,
            plan_compte_id     INTEGER NOT NULL REFERENCES plan_comptes(id) ON DELETE CASCADE,
            operateur          TEXT NOT NULL DEFAULT 'ET',
            priorite           INTEGER NOT NULL DEFAULT 0,
            actif              INTEGER NOT NULL DEFAULT 1,
            cree_le            TEXT NOT NULL DEFAULT (datetime('now'))
        );

        -- Postes de patrimoine saisis à la main (Garantie loyer, Prêt…) par année.
        CREATE TABLE IF NOT EXISTS postes_bilan (
            id      INTEGER PRIMARY KEY AUTOINCREMENT,
            libelle TEXT NOT NULL,
            ordre   INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS postes_bilan_valeurs (
            poste_id INTEGER NOT NULL REFERENCES postes_bilan(id) ON DELETE CASCADE,
            annee    INTEGER NOT NULL,
            montant  REAL NOT NULL DEFAULT 0,
            PRIMARY KEY (poste_id, annee)
        );
        SQL);

    run_migrations($pdo);
    seed_parametres($pdo);
    migrate_taux($pdo);
    seed_unites($pdo);
    seed_plan_comptes($pdo);
}

// Plan comptable par défaut (catégories génériques d'association), seulement si
// la table est vide. Modifiable/supprimable ensuite via l'interface.
function seed_plan_comptes(PDO $pdo): void
{
    if ((int) $pdo->query('SELECT COUNT(*) FROM plan_comptes')->fetchColumn() > 0) {
        return;
    }
    // Catégories principales (racines, sans parent). L'utilisateur peut ensuite
    // créer des sous-catégories sous n'importe laquelle. [libellé, sens]
    $defauts = [
        ['Cotisations des membres', 'produit'],
        ['Dons',                    'produit'],
        ['Subventions',             'produit'],
        ['Ventes',                  'produit'],
        ['Autres recettes',         'produit'],
        ['Salaires et mandats',     'charge'],
        ['Loyer',                   'charge'],
        ['Électricité',             'charge'],
        ['Matériel',                'charge'],
        ['Frais bancaires',         'charge'],
        ['Frais informatiques',     'charge'],
        ['Impôts',                  'charge'],
        ['Autres charges',          'charge'],
    ];
    $ins = $pdo->prepare('INSERT INTO plan_comptes (libelle, sens, ordre) VALUES (?, ?, ?)');
    foreach ($defauts as $i => $d) {
        $ins->execute([$d[0], $d[1], $i]);
    }
}

// Migrations de schéma versionnées (PRAGMA user_version).
// Pour ajouter une évolution : ajouter une entrée à $steps avec le numéro suivant.
// Chaque étape doit rester idempotente (vérifier l'existant) pour les bases
// déjà partiellement migrées par l'ancien mécanisme.
function run_migrations(PDO $pdo): void
{
    $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
    $steps = [
        1 => 'migration_1', // charges patronales, afficher_cout_emp, fiche_lignes.taux_horaire
        2 => 'migration_2', // colonnes CPE / LFP (charges patronales)
        3 => 'migration_3', // colonne email sur les employés
        4 => 'migration_4', // colonne email_envoye_le sur les fiches
        5 => 'migration_5', // colonne date_naissance sur les employés
        6 => 'migration_6', // table login_attempts (anti-force-brute)
        7 => 'migration_7', // plan comptable hiérarchique (parent_id ← groupe)
        8 => 'migration_8', // ecritures : tiers + communication (+ backfill)
        9  => 'migration_9',  // regles_lettrage : montant_min / montant_max
        10 => 'migration_10', // conditions_lettrage (builder ET/OU) + operateur sur regles_lettrage
        11 => 'migration_11', // prenom + nom sur les utilisateurs
        12 => 'migration_12', // regles_lettrage : supprime NOT NULL sur motif/type_match/sens_filtre
        13 => 'migration_13', // répare FK conditions_lettrage cassé par migration_12 (RENAME side-effect)
        14 => 'migration_14', // comptes_bancaires : colonne solde_initial (solde de départ)
        15 => 'migration_15', // axes_analytiques + ecritures.axe_analytique_id
        16 => 'migration_16', // ecritures_ventilations (multi-axe)
        17 => 'migration_17', // fiche_lignes.axe_analytique_id
        18 => 'migration_18', // module facturation : debiteurs, factures, facture_lignes
        19 => 'migration_19', // ecritures.facture_id (rapprochement facture ↔ écriture bancaire)
        20 => 'migration_20', // index manquants sur factures.statut / ecritures.facture_id
        21 => 'migration_21', // factures.numero : UNIQUE inline → index unique partiel (autorise plusieurs brouillons)
        22 => 'migration_22', // factures.numero : préfixe "F-" (ex. 2025-001 → F-2025-001)
        23 => 'migration_23', // module événements : spectacles, evenements, liens employés/fiches, factures.evenement_id
        24 => 'migration_24', // evenements.region (canton/département), pour l'import CSV de tournée
        25 => 'migration_25', // evenements.lien_texte (texte du bouton de lien), pour l'import CSV de tournée
        26 => 'migration_26', // evenements.pays (champ propre, ne se recoupe plus avec region)
        27 => 'migration_27', // fiche_lignes.evenement_id : ligne de prestation ajoutée depuis un événement
        28 => 'migration_28', // evenements.axe_analytique_id_defaut : axe analytique par défaut
        29 => 'migration_29', // spectacles.parent_id / ordre : hiérarchie (imbrication façon plan comptable)
        30 => 'migration_30', // paramètre suisa_delai_abandon_mois (statut SUISA « abandonné »)
        31 => 'migration_31', // evenements.organisateur_debiteur_id : lien vers le débiteur organisateur
        32 => 'migration_32', // debiteurs.telephone / personne_contact
        33 => 'migration_33', // evenements.production_externe
        34 => 'migration_34', // utilisateur_permissions (droits par module, lecture/écriture)
        35 => 'migration_35', // module booking : debiteurs → structures (table + colonnes FK), nouveaux champs
        36 => 'migration_36', // module booking : structure_contacts, lieux, structure_lieux, tags, notes, mailing, régions
        37 => 'migration_37', // module booking : structures.sous_categorie/mise_a_jour_le, lieux.jauge_min/jauge_max
        38 => 'migration_38', // module booking : structures.via (source du contact, texte libre)
        39 => 'migration_39', // module booking : catégories/sous-catégories configurables (structure_categories, structure_sous_categories)
        40 => 'migration_40', // module booking : structure_contacts.est_administration/est_booking
        41 => 'migration_41', // module booking : lieux.mois_evenement_debut/fin (dates de l'événement, distinct de mois_debut/fin = période de programmation)
        42 => 'migration_42', // module booking : structure_categories.parent_id (sous-catégories imbriquées dans une catégorie, façon spectacle/groupe-spectacle) — fusion de structure_sous_categories
        43 => 'migration_43', // liste de pays configurable (pays_liste), partagée par tous les champs pays de l'app (structures, lieux, employeur, événements, facturation)
        44 => 'migration_44', // module booking : region → département/canton + grande_region distincte (structures/lieux) + lieux.dernier_concert_le
        45 => 'migration_45', // module booking : taxonomie lieu_categories (remplace salle/festival du champ lieux.type)
        46 => 'migration_46', // module booking : table mailing_exclusions (liste « ne pas contacter » sans structures fantômes) + reprise des placeholders
        47 => 'migration_47', // module booking : table mailing_ciblages (ciblages types réutilisables du mailing)
        48 => 'migration_48', // module booking : campagnes (historique) + modèles de message + campagne_id sur file/envois
        49 => 'migration_49', // module booking : régions (grandes régions) = taxonomie imbriquée sous les pays (pays_liste devient un arbre à 2 niveaux)
        50 => 'migration_50', // module booking : lieux.actif (actif/inactif) + lieux.site_web
        51 => 'migration_51', // module booking : evenements.lieu_id (lien vers un lieu de la base)
        52 => 'migration_52', // module booking : table historique unifiée (structures + lieux), migration des structure_notes
        53 => 'migration_53', // module booking : table lieux_geocodage (cache ville+pays → latitude/longitude, vue carte)
        54 => 'migration_54', // module booking : evenements.grande_region (déduite du département/canton, voir grande_region_deduite())
        55 => 'migration_55', // module booking : structures.flag / lieux.flag (marquage rapide étoile/cœur)
        56 => 'migration_56', // renomme evenements/structures/lieux.region → departement_canton (clarté vs grande_region)
        57 => 'migration_57', // géocodage : departement_canton entre dans la clé de cache (désambiguïse les homonymes, ex. Bonneville)
        58 => 'migration_58', // module évenements : lieux/organisateurs multiples (evenement_lieux, evenement_organisateurs)
        59 => 'migration_59', // fusion lieux → structures, étape 1/N (schéma additif) : structure_categories.est_booking, colonnes touring sur structures, table structure_organisateurs
        60 => 'migration_60', // fusion lieux → structures, étape 2/N : evenements.lieu_id / evenement_lieux.lieu_id repointés vers structures(id) (au lieu de lieux(id))
        61 => 'migration_61', // fusion lieux → structures, étape 3/3 : suppression de lieux/structure_lieux/lieu_categories (plus aucun code applicatif ne les utilise)
        62 => 'migration_62', // structure_tags.couleur (couleur choisie pour le badge de l'étiquette)
        63 => 'migration_63', // structures.actif + desinscrit → statut unique (contact_privilegie/actif/ne_pas_contacter/inactif)
        64 => 'migration_64', // retire structure_categories.est_organisateur (catégorie « organisateur » + auto-groupement import CSV supprimés)
        65 => 'migration_65', // lieux_geocodage.cle : repli des accents (voir geocodage_cle()) — reclé les lignes déjà en cache pour qu'elles restent trouvées
        66 => 'migration_66', // fusion evenement_lieux/evenement_organisateurs → evenement_structures (plus de distinction lieu/organisateur sur ?p=evenement, une structure marquée « à facturer »)
    ];
    foreach ($steps as $num => $fn) {
        if ($version < $num) {
            $fn($pdo);
            $pdo->exec('PRAGMA user_version = ' . (int) $num);
        }
    }
}

// Unités de temps par défaut (seulement si aucune n'existe).
function seed_unites(PDO $pdo): void
{
    if ((int) $pdo->query('SELECT COUNT(*) FROM unites')->fetchColumn() > 0) {
        return;
    }
    $ins = $pdo->prepare('INSERT INTO unites (libelle, heures) VALUES (?, ?)');
    foreach ([['Heure', 1], ['Demi-journée', 4], ['Jour', 8], ['Service', 3]] as $u) {
        $ins->execute($u);
    }
}

// Migre les taux : de globaux (table parametres, ancienne version) vers
// par année (table taux_par_annee). Ne s'exécute qu'une fois.
function migrate_taux(PDO $pdo): void
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM taux_par_annee')->fetchColumn();
    if ($count > 0) {
        return;
    }
    // Récupère d'éventuels taux déjà enregistrés dans parametres (v1).
    $anciens = [];
    $q = $pdo->query("SELECT cle, valeur FROM parametres WHERE cle LIKE 'taux_%' OR cle LIKE 'emp_taux_%'");
    foreach ($q as $r) {
        $anciens[$r['cle']] = $r['valeur'];
    }
    $annee = (int) date('Y');
    $ins = $pdo->prepare('INSERT OR IGNORE INTO taux_par_annee (annee, cle, valeur) VALUES (?, ?, ?)');
    foreach (TAUX_DEFAUT as $cle => $defaut) {
        $ins->execute([$annee, $cle, (string) ($anciens[$cle] ?? $defaut)]);
    }
    // Nettoie les anciennes clés de taux dans parametres.
    $pdo->exec("DELETE FROM parametres WHERE cle LIKE 'taux_%' OR cle LIKE 'emp_taux_%'");
}

// Migration 1 : colonnes ajoutées après coup (idempotent — vérifie l'existant).
function migration_1(PDO $pdo): void
{
    $cols = [];
    foreach ($pdo->query("PRAGMA table_info(fiches)") as $row) {
        $cols[$row['name']] = true;
    }
    $ajouts = [
        'emp_avs'           => 'REAL NOT NULL DEFAULT 0',
        'emp_ac'            => 'REAL NOT NULL DEFAULT 0',
        'emp_amat'          => 'REAL NOT NULL DEFAULT 0',
        'emp_af'            => 'REAL NOT NULL DEFAULT 0',
        'emp_laa'           => 'REAL NOT NULL DEFAULT 0',
        'emp_frais'         => 'REAL NOT NULL DEFAULT 0',
        'emp_lpp'           => 'REAL NOT NULL DEFAULT 0',
        'total_charges_emp' => 'REAL NOT NULL DEFAULT 0',
        'cout_total_emp'    => 'REAL NOT NULL DEFAULT 0',
        'afficher_cout_emp' => 'INTEGER NOT NULL DEFAULT 0',
    ];
    foreach ($ajouts as $nom => $def) {
        if (!isset($cols[$nom])) {
            $pdo->exec("ALTER TABLE fiches ADD COLUMN $nom $def");
        }
    }

    // Colonne taux horaire par ligne (ajoutée après coup)
    $lcols = [];
    foreach ($pdo->query("PRAGMA table_info(fiche_lignes)") as $row) {
        $lcols[$row['name']] = true;
    }
    if ($lcols && !isset($lcols['taux_horaire'])) {
        $pdo->exec('ALTER TABLE fiche_lignes ADD COLUMN taux_horaire REAL NOT NULL DEFAULT 0');
    }
}

// Migration 2 : colonnes CPE / LFP sur les fiches (idempotent).
function migration_2(PDO $pdo): void
{
    $cols = [];
    foreach ($pdo->query("PRAGMA table_info(fiches)") as $row) {
        $cols[$row['name']] = true;
    }
    foreach (['emp_cpe', 'emp_lfp'] as $nom) {
        if (!isset($cols[$nom])) {
            $pdo->exec("ALTER TABLE fiches ADD COLUMN $nom REAL NOT NULL DEFAULT 0");
        }
    }
}

// Migration 3 : colonne email sur les employés (idempotent).
function migration_3(PDO $pdo): void
{
    $cols = [];
    foreach ($pdo->query("PRAGMA table_info(employes)") as $row) {
        $cols[$row['name']] = true;
    }
    if (!isset($cols['email'])) {
        $pdo->exec("ALTER TABLE employes ADD COLUMN email TEXT NOT NULL DEFAULT ''");
    }
}

// Migration 4 : colonne email_envoye_le sur les fiches (idempotent).
function migration_4(PDO $pdo): void
{
    $cols = [];
    foreach ($pdo->query("PRAGMA table_info(fiches)") as $row) {
        $cols[$row['name']] = true;
    }
    if (!isset($cols['email_envoye_le'])) {
        $pdo->exec("ALTER TABLE fiches ADD COLUMN email_envoye_le TEXT NOT NULL DEFAULT ''");
    }
}

// Migration 5 : colonne date_naissance sur les employés (idempotent).
function migration_5(PDO $pdo): void
{
    $cols = [];
    foreach ($pdo->query("PRAGMA table_info(employes)") as $row) {
        $cols[$row['name']] = true;
    }
    if (!isset($cols['date_naissance'])) {
        $pdo->exec("ALTER TABLE employes ADD COLUMN date_naissance TEXT NOT NULL DEFAULT ''");
    }
}

// Migration 6 : table login_attempts (idempotent).
function migration_6(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip TEXT NOT NULL,
        email TEXT NOT NULL DEFAULT '',
        cree_le INTEGER NOT NULL
    )");
}

// Migration 7 : plan comptable hiérarchique. Ajoute parent_id et convertit
// l'ancien champ texte « groupe » en catégories parentes (idempotent).
function migration_7(PDO $pdo): void
{
    $cols = [];
    foreach ($pdo->query('PRAGMA table_info(plan_comptes)') as $row) {
        $cols[$row['name']] = true;
    }
    if (!isset($cols['parent_id'])) {
        $pdo->exec('ALTER TABLE plan_comptes ADD COLUMN parent_id INTEGER');
    }
    if (!isset($cols['groupe'])) {
        return;
    }
    // Pour chaque (sens, groupe) non vide encore à la racine, crée une catégorie
    // parente du nom du groupe et y rattache ses catégories.
    $rows = $pdo->query("SELECT id, sens, groupe, ordre FROM plan_comptes
                         WHERE parent_id IS NULL AND groupe <> '' ORDER BY ordre, id")->fetchAll();
    if (!$rows) {
        return;
    }
    $insParent = $pdo->prepare("INSERT INTO plan_comptes (libelle, sens, groupe, ordre) VALUES (?, ?, '', ?)");
    $setParent = $pdo->prepare('UPDATE plan_comptes SET parent_id = ? WHERE id = ?');
    $ordre = (int) $pdo->query('SELECT COALESCE(MAX(ordre),0) FROM plan_comptes WHERE parent_id IS NULL')->fetchColumn();
    $parents = [];
    foreach ($rows as $r) {
        $key = $r['sens'] . '|' . $r['groupe'];
        if (!isset($parents[$key])) {
            $insParent->execute([$r['groupe'], $r['sens'], ++$ordre]);
            $parents[$key] = (int) $pdo->lastInsertId();
        }
        $setParent->execute([$parents[$key], $r['id']]);
    }
}

// Migration 8 : colonnes tiers + communication sur les écritures, et backfill
// des écritures existantes via extraire_tiers() (idempotent).
function migration_8(PDO $pdo): void
{
    $cols = [];
    foreach ($pdo->query('PRAGMA table_info(ecritures)') as $row) {
        $cols[$row['name']] = true;
    }
    foreach (['tiers', 'communication'] as $nom) {
        if (!isset($cols[$nom])) {
            $pdo->exec("ALTER TABLE ecritures ADD COLUMN $nom TEXT NOT NULL DEFAULT ''");
        }
    }
    require_once __DIR__ . '/compta.php'; // extraire_tiers()
    $lignes = $pdo->query("SELECT id, texte FROM ecritures WHERE tiers = '' AND communication = ''")->fetchAll();
    if (!$lignes) {
        return;
    }
    $upd = $pdo->prepare('UPDATE ecritures SET tiers = ?, communication = ? WHERE id = ?');
    foreach ($lignes as $l) {
        $ex = extraire_tiers((string) $l['texte']);
        $upd->execute([$ex['tiers'], $ex['communication'], (int) $l['id']]);
    }
}

// Migration 9 : conditions de montant sur les règles de lettrage (idempotent).
function migration_9(PDO $pdo): void
{
    $cols = [];
    foreach ($pdo->query('PRAGMA table_info(regles_lettrage)') as $row) {
        $cols[$row['name']] = true;
    }
    foreach (['montant_min', 'montant_max'] as $nom) {
        if (!isset($cols[$nom])) {
            $pdo->exec("ALTER TABLE regles_lettrage ADD COLUMN $nom REAL");
        }
    }
}

// Migration 10 : table conditions_lettrage + colonne operateur ET/OU sur regles_lettrage.
function migration_10(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS conditions_lettrage (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            regle_id INTEGER NOT NULL REFERENCES regles_lettrage(id) ON DELETE CASCADE,
            type TEXT NOT NULL DEFAULT 'texte',
            op TEXT NOT NULL DEFAULT 'contient',
            valeur TEXT NOT NULL DEFAULT '',
            ordre INTEGER NOT NULL DEFAULT 0
        )
    ");
    $cols = [];
    foreach ($pdo->query('PRAGMA table_info(regles_lettrage)') as $row) {
        $cols[$row['name']] = true;
    }
    if (!isset($cols['operateur'])) {
        $pdo->exec("ALTER TABLE regles_lettrage ADD COLUMN operateur TEXT NOT NULL DEFAULT 'ET'");
    }
    // Migrer les conditions existantes depuis les colonnes plates (idempotent).
    $regles = $pdo->query('SELECT id, motif, type_match, sens_filtre, montant_min, montant_max FROM regles_lettrage')->fetchAll();
    $ins = $pdo->prepare('INSERT INTO conditions_lettrage (regle_id, type, op, valeur, ordre) VALUES (?, ?, ?, ?, ?)');
    foreach ($regles as $r) {
        $rid = (int) $r['id'];
        if ((int) $pdo->query("SELECT COUNT(*) FROM conditions_lettrage WHERE regle_id = $rid")->fetchColumn() > 0) {
            continue;
        }
        $ordre = 0;
        if ((string) $r['motif'] !== '') {
            $ins->execute([$rid, 'texte', $r['type_match'] ?: 'contient', $r['motif'], $ordre++]);
        }
        if ((string) $r['sens_filtre'] !== '') {
            $ins->execute([$rid, 'sens', '=', $r['sens_filtre'], $ordre++]);
        }
        if ($r['montant_min'] !== null) {
            $ins->execute([$rid, 'montant_min', '>=', (string) $r['montant_min'], $ordre++]);
        }
        if ($r['montant_max'] !== null) {
            $ins->execute([$rid, 'montant_max', '<=', (string) $r['montant_max'], $ordre++]);
        }
    }
}

function migration_11(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(utilisateurs)')->fetchAll(), 'name');
    if (!in_array('prenom', $cols)) {
        $pdo->exec("ALTER TABLE utilisateurs ADD COLUMN prenom TEXT NOT NULL DEFAULT ''");
    }
    if (!in_array('nom', $cols)) {
        $pdo->exec("ALTER TABLE utilisateurs ADD COLUMN nom TEXT NOT NULL DEFAULT ''");
    }
}

function migration_12(PDO $pdo): void
{
    // Supprime le NOT NULL sur motif/type_match/sens_filtre, obsolètes depuis migration_10.
    // SQLite ne supporte pas ALTER COLUMN → recréation de la table.
    // ATTENTION : FK = OFF obligatoire car SQLite met à jour les FK des autres tables
    // lors d'un RENAME (conditions_lettrage.regle_id pointerait sur _regles_lettrage_old).
    $info = $pdo->query('PRAGMA table_info(regles_lettrage)')->fetchAll(PDO::FETCH_ASSOC);
    $motifNotnull = false;
    foreach ($info as $col) {
        if ($col['name'] === 'motif' && (int) $col['notnull'] === 1) {
            $motifNotnull = true;
            break;
        }
    }
    if (!$motifNotnull) return;

    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->exec("ALTER TABLE regles_lettrage RENAME TO _regles_lettrage_old");
    $pdo->exec("CREATE TABLE regles_lettrage (
        id                 INTEGER PRIMARY KEY AUTOINCREMENT,
        compte_bancaire_id INTEGER REFERENCES comptes_bancaires(id) ON DELETE CASCADE,
        motif              TEXT,
        type_match         TEXT,
        sens_filtre        TEXT,
        montant_min        REAL,
        montant_max        REAL,
        plan_compte_id     INTEGER NOT NULL REFERENCES plan_comptes(id) ON DELETE CASCADE,
        operateur          TEXT NOT NULL DEFAULT 'ET',
        priorite           INTEGER NOT NULL DEFAULT 0,
        actif              INTEGER NOT NULL DEFAULT 1,
        cree_le            TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("INSERT INTO regles_lettrage (id, compte_bancaire_id, motif, type_match, sens_filtre,
        montant_min, montant_max, plan_compte_id, operateur, priorite, actif, cree_le)
        SELECT id, compte_bancaire_id, motif, type_match, sens_filtre,
        montant_min, montant_max, plan_compte_id,
        COALESCE(operateur, 'ET'), priorite, actif, cree_le
        FROM _regles_lettrage_old");
    $pdo->exec("DROP TABLE _regles_lettrage_old");
    $pdo->exec('PRAGMA foreign_keys = ON');
}

function migration_13(PDO $pdo): void
{
    // Répare le FK de conditions_lettrage si migration_12 a été exécutée sans FK=OFF :
    // SQLite ayant mis à jour la référence lors du RENAME, conditions_lettrage.regle_id
    // pointait sur _regles_lettrage_old (droppée), cassant tout INSERT dans cette table.
    $sql = (string) $pdo->query(
        "SELECT COALESCE(sql,'') FROM sqlite_master WHERE type='table' AND name='conditions_lettrage'"
    )->fetchColumn();
    if (strpos($sql, '_regles_lettrage_old') === false) return;

    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->exec('ALTER TABLE conditions_lettrage RENAME TO _conditions_lettrage_old');
    $pdo->exec("CREATE TABLE conditions_lettrage (
        id       INTEGER PRIMARY KEY AUTOINCREMENT,
        regle_id INTEGER NOT NULL REFERENCES regles_lettrage(id) ON DELETE CASCADE,
        type     TEXT NOT NULL DEFAULT 'texte',
        op       TEXT NOT NULL DEFAULT 'contient',
        valeur   TEXT NOT NULL DEFAULT '',
        ordre    INTEGER NOT NULL DEFAULT 0
    )");
    $pdo->exec('INSERT INTO conditions_lettrage SELECT * FROM _conditions_lettrage_old');
    $pdo->exec('DROP TABLE _conditions_lettrage_old');
    $pdo->exec('PRAGMA foreign_keys = ON');
}

function migration_14(PDO $pdo): void
{
    foreach ($pdo->query('PRAGMA table_info(comptes_bancaires)') as $col) {
        if ($col['name'] === 'solde_initial') return;
    }
    $pdo->exec('ALTER TABLE comptes_bancaires ADD COLUMN solde_initial REAL NOT NULL DEFAULT 0');
}

function migration_17(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(fiche_lignes)')->fetchAll(), 'name');
    if (!in_array('axe_analytique_id', $cols, true)) {
        $pdo->exec('ALTER TABLE fiche_lignes ADD COLUMN axe_analytique_id INTEGER REFERENCES axes_analytiques(id)');
    }
}

function migration_16(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS ecritures_ventilations (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        ecriture_id INTEGER NOT NULL REFERENCES ecritures(id),
        axe_id      INTEGER NOT NULL REFERENCES axes_analytiques(id),
        montant     REAL NOT NULL,
        cree_le     TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ev_ecriture ON ecritures_ventilations(ecriture_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ev_axe      ON ecritures_ventilations(axe_id)');
    // Backfill : chaque écriture avec un axe unique → une ligne de ventilation
    // avec le montant complet de l'écriture.
    $pdo->exec("INSERT INTO ecritures_ventilations (ecriture_id, axe_id, montant)
        SELECT id, axe_analytique_id, montant FROM ecritures
        WHERE axe_analytique_id IS NOT NULL");
    // L'ancienne colonne n'est plus la source de vérité.
    // SQLite ne supporte pas DROP COLUMN sans reconstruction, on la met à NULL.
    $pdo->exec('UPDATE ecritures SET axe_analytique_id = NULL');
}

function migration_15(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS axes_analytiques (
        id      INTEGER PRIMARY KEY AUTOINCREMENT,
        libelle TEXT NOT NULL,
        code    TEXT NOT NULL DEFAULT '',
        ordre   INTEGER NOT NULL DEFAULT 0,
        actif   INTEGER NOT NULL DEFAULT 1,
        cree_le TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    foreach ($pdo->query('PRAGMA table_info(ecritures)') as $col) {
        if ($col['name'] === 'axe_analytique_id') return;
    }
    $pdo->exec('ALTER TABLE ecritures ADD COLUMN axe_analytique_id INTEGER REFERENCES axes_analytiques(id) ON DELETE SET NULL');
}

// Migration 18 : module facturation — débiteurs, factures, lignes de facture.
// Axe analytique par ligne (comme fiche_lignes), réutilise axes_analytiques existant.
function migration_18(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS debiteurs (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            type            TEXT NOT NULL DEFAULT 'organisation', -- 'organisation' | 'particulier'
            nom             TEXT NOT NULL,
            adresse_rue     TEXT NOT NULL DEFAULT '',
            adresse_npa     TEXT NOT NULL DEFAULT '',
            adresse_localite TEXT NOT NULL DEFAULT '',
            adresse_pays    TEXT NOT NULL DEFAULT 'Suisse',
            email           TEXT NOT NULL DEFAULT '',
            notes           TEXT NOT NULL DEFAULT '',
            actif           INTEGER NOT NULL DEFAULT 1,
            cree_le         TEXT NOT NULL DEFAULT (datetime('now'))
        );

        -- statut : 'brouillon' | 'emise' | 'payee' | 'annulee'. « En retard » est dérivé
        -- (statut = emise, date_echeance dépassée), jamais stocké.
        CREATE TABLE IF NOT EXISTS factures (
            id                 INTEGER PRIMARY KEY AUTOINCREMENT,
            debiteur_id        INTEGER NOT NULL REFERENCES debiteurs(id),
            compte_bancaire_id INTEGER REFERENCES comptes_bancaires(id),
            numero             TEXT NOT NULL DEFAULT '', -- unicité : index partiel plus bas (brouillons = '' à volonté)
            reference_paiement TEXT NOT NULL DEFAULT '', -- référence structurée SCOR (ISO 11649)
            date_emission      TEXT NOT NULL DEFAULT '',
            date_echeance      TEXT NOT NULL DEFAULT '',
            delai_jours        INTEGER NOT NULL DEFAULT 30,
            statut             TEXT NOT NULL DEFAULT 'brouillon',
            montant_total      REAL NOT NULL DEFAULT 0,
            communication      TEXT NOT NULL DEFAULT '',
            ecriture_id        INTEGER REFERENCES ecritures(id) ON DELETE SET NULL,
            envoyee_le         TEXT NOT NULL DEFAULT '',
            payee_le           TEXT NOT NULL DEFAULT '',
            cree_le            TEXT NOT NULL DEFAULT (datetime('now'))
        );

        CREATE TABLE IF NOT EXISTS facture_lignes (
            id                 INTEGER PRIMARY KEY AUTOINCREMENT,
            facture_id         INTEGER NOT NULL REFERENCES factures(id) ON DELETE CASCADE,
            description        TEXT NOT NULL DEFAULT '',
            quantite           REAL NOT NULL DEFAULT 1,
            prix_unitaire      REAL NOT NULL DEFAULT 0,
            montant            REAL NOT NULL DEFAULT 0,
            axe_analytique_id  INTEGER REFERENCES axes_analytiques(id),
            ordre              INTEGER NOT NULL DEFAULT 0
        );

        CREATE INDEX IF NOT EXISTS idx_factures_debiteur ON factures(debiteur_id);
        CREATE INDEX IF NOT EXISTS idx_factures_statut ON factures(statut);
        CREATE INDEX IF NOT EXISTS idx_facture_lignes_facture ON facture_lignes(facture_id);
        -- Unicité du numéro, mais seulement une fois attribué : plusieurs brouillons
        -- (numero = '') doivent pouvoir coexister (SQLite traite '' comme une valeur
        -- comme une autre pour un UNIQUE inline, contrairement à NULL).
        CREATE UNIQUE INDEX IF NOT EXISTS idx_factures_numero_unique ON factures(numero) WHERE numero <> '';
    ");

    $pdo->prepare('INSERT OR IGNORE INTO parametres (cle, valeur) VALUES (?, ?)')
        ->execute(['facturation_delai_jours_defaut', '30']);
}

// Migration 19 : colonne facture_id sur les écritures, pour le rapprochement
// automatique (import compta) d'un paiement reçu avec une facture émise.
function migration_19(PDO $pdo): void
{
    $existe = false;
    foreach ($pdo->query('PRAGMA table_info(ecritures)') as $col) {
        if ($col['name'] === 'facture_id') { $existe = true; break; }
    }
    if (!$existe) {
        $pdo->exec('ALTER TABLE ecritures ADD COLUMN facture_id INTEGER REFERENCES factures(id) ON DELETE SET NULL');
    }
}

// Migration 20 : index manquants sur factures.statut (filtré à chaque requête —
// liste, badge « en retard » dans le menu sur toutes les pages, rapprochement à
// l'import) et ecritures.facture_id (rapprochement automatique). Séparée des
// migrations 18/19 : celles-ci ne se rejouent pas sur une base déjà migrée.
function migration_20(PDO $pdo): void
{
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_factures_statut ON factures(statut)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ecritures_facture ON ecritures(facture_id)');
}

// Migration 21 : la contrainte UNIQUE inline sur factures.numero (migration_18)
// empêche plusieurs brouillons à la fois — SQLite traite '' comme une valeur
// comme une autre pour un UNIQUE inline, contrairement à NULL. Remplacée par un
// index unique partiel (numero <> '').
// ⚠️ Ne PAS utiliser le pattern « RENAME vers un nom temporaire » habituel ici :
// testé empiriquement (SQLite 3.53), PRAGMA foreign_keys=OFF ne suffit PAS à
// empêcher SQLite de réécrire la clause REFERENCES de facture_lignes/ecritures
// vers ce nom temporaire — qui devient une FK cassée une fois la table
// temporaire droppée (le correctif documenté plus haut dans ce fichier pour
// migration_12/13 est insuffisant sur les versions récentes de SQLite). À la
// place : créer la nouvelle table sous un nom temporaire, y copier les
// données, DROP l'ancienne « factures » (une suppression, pas un renommage —
// ne déclenche aucune réécriture de schéma ailleurs), puis RENAME le nom
// temporaire vers « factures ». À ce moment-là, aucune autre table ne
// référence le nom temporaire, donc rien à réécrire.
function migration_21(PDO $pdo): void
{
    $sql = (string) $pdo->query(
        "SELECT COALESCE(sql,'') FROM sqlite_master WHERE type='table' AND name='factures'"
    )->fetchColumn();
    if (!str_contains($sql, 'UNIQUE')) {
        return; // déjà corrigé (nouvelle installation via migration_18 mise à jour)
    }

    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->exec("
        CREATE TABLE factures_v21 (
            id                 INTEGER PRIMARY KEY AUTOINCREMENT,
            debiteur_id        INTEGER NOT NULL REFERENCES debiteurs(id),
            compte_bancaire_id INTEGER REFERENCES comptes_bancaires(id),
            numero             TEXT NOT NULL DEFAULT '',
            reference_paiement TEXT NOT NULL DEFAULT '',
            date_emission      TEXT NOT NULL DEFAULT '',
            date_echeance      TEXT NOT NULL DEFAULT '',
            delai_jours        INTEGER NOT NULL DEFAULT 30,
            statut             TEXT NOT NULL DEFAULT 'brouillon',
            montant_total      REAL NOT NULL DEFAULT 0,
            communication      TEXT NOT NULL DEFAULT '',
            ecriture_id        INTEGER REFERENCES ecritures(id) ON DELETE SET NULL,
            envoyee_le         TEXT NOT NULL DEFAULT '',
            payee_le           TEXT NOT NULL DEFAULT '',
            cree_le            TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");
    $pdo->exec('INSERT INTO factures_v21 SELECT * FROM factures');
    $pdo->exec('DROP TABLE factures');
    $pdo->exec('ALTER TABLE factures_v21 RENAME TO factures');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_factures_debiteur ON factures(debiteur_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_factures_statut ON factures(statut)');
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_factures_numero_unique ON factures(numero) WHERE numero <> ''");
    $pdo->exec('PRAGMA foreign_keys = ON');

    // Vérification : si une FK d'une autre table a quand même été cassée par
    // cette migration, on préfère planter bruyamment ici plutôt que de laisser
    // une base incohérente en silence.
    $casse = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
    if ($casse) {
        throw new RuntimeException('migration_21 : clé étrangère cassée après migration — ' . json_encode($casse));
    }
}

// Migration 22 : préfixe "F-" devant tous les numéros de facture existants
// (nouveau format F-AAAA-NNN pour les futures factures, voir
// facturation_prochain_numero()). Ex. 2025-001 → F-2025-001,
// 2026-H02 → F-2026-H02 (numéros importés depuis l'historique). Idempotente :
// ignore les brouillons (numero = '') et les numéros déjà préfixés.
// reference_paiement n'est PAS touchée (historique figé — voir CLAUDE.md).
function migration_22(PDO $pdo): void
{
    $pdo->exec("UPDATE factures SET numero = 'F-' || numero WHERE numero <> '' AND numero NOT LIKE 'F-%'");
}

// Migration 23 : module événements — spectacles (dont la feuille SUISA
// pré-remplie en PDF), evenements (statut/visibilité, suivi SUISA), liens
// many-to-many vers employés et fiches, et colonne evenement_id sur factures
// (facture créée depuis un événement, cf. SPEC_EVENEMENTS.md).
function migration_23(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS spectacles (
            id                     INTEGER PRIMARY KEY AUTOINCREMENT,
            nom                    TEXT NOT NULL,
            notes                  TEXT NOT NULL DEFAULT '',
            suisa_feuille_fichier  TEXT NOT NULL DEFAULT '',
            parent_id              INTEGER REFERENCES spectacles(id) ON DELETE SET NULL,
            ordre                  INTEGER NOT NULL DEFAULT 0,
            cree_le                TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE INDEX IF NOT EXISTS idx_spectacles_parent ON spectacles(parent_id);

        -- statut : 'option' | 'confirme' | 'annule' — indépendant de la visibilité
        -- (une date public peut être annulée : elle reste affichée, marquée « Annulé »).
        CREATE TABLE IF NOT EXISTS evenements (
            id                 INTEGER PRIMARY KEY AUTOINCREMENT,
            spectacle_id       INTEGER REFERENCES spectacles(id) ON DELETE SET NULL,
            date               TEXT NOT NULL,
            statut             TEXT NOT NULL DEFAULT 'option',
            visibilite         TEXT NOT NULL DEFAULT 'non_repertorie',
            ville              TEXT NOT NULL DEFAULT '',
            salle              TEXT NOT NULL DEFAULT '',
            festival           TEXT NOT NULL DEFAULT '',
            lien_infos         TEXT NOT NULL DEFAULT '',
            remarques          TEXT NOT NULL DEFAULT '',
            suisa_applicable   INTEGER NOT NULL DEFAULT 1,
            suisa_envoye_a     TEXT NOT NULL DEFAULT '',
            suisa_envoye_le    TEXT NOT NULL DEFAULT '',
            suisa_decompte_le  TEXT NOT NULL DEFAULT '',
            cree_le            TEXT NOT NULL DEFAULT (datetime('now'))
        );

        CREATE TABLE IF NOT EXISTS evenement_employes (
            evenement_id INTEGER NOT NULL REFERENCES evenements(id) ON DELETE CASCADE,
            employe_id   INTEGER NOT NULL REFERENCES employes(id) ON DELETE CASCADE,
            PRIMARY KEY (evenement_id, employe_id)
        );

        CREATE TABLE IF NOT EXISTS evenement_fiches (
            evenement_id INTEGER NOT NULL REFERENCES evenements(id) ON DELETE CASCADE,
            fiche_id     INTEGER NOT NULL REFERENCES fiches(id) ON DELETE CASCADE,
            PRIMARY KEY (evenement_id, fiche_id)
        );

        CREATE INDEX IF NOT EXISTS idx_evenements_date ON evenements(date);
        CREATE INDEX IF NOT EXISTS idx_evenements_spectacle ON evenements(spectacle_id);
    ");

    $cols = array_column($pdo->query('PRAGMA table_info(factures)')->fetchAll(), 'name');
    if (!in_array('evenement_id', $cols, true)) {
        $pdo->exec('ALTER TABLE factures ADD COLUMN evenement_id INTEGER REFERENCES evenements(id) ON DELETE SET NULL');
    }

    $ins = $pdo->prepare('INSERT OR IGNORE INTO parametres (cle, valeur) VALUES (?, ?)');
    $ins->execute(['suisa_delai_decompte_mois', '12']);
    $ins->execute(['evenements_export_token', '']);
}

// Migration 24 : colonne region sur les événements (canton suisse ou
// département français, ex. « VD », « 25 ») — pas de champ dédié jusqu'ici,
// nécessaire pour l'import CSV de tournée (voir importer_evenements_csv()).
function migration_24(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(evenements)')->fetchAll(), 'name');
    if (!in_array('region', $cols, true)) {
        $pdo->exec("ALTER TABLE evenements ADD COLUMN region TEXT NOT NULL DEFAULT ''");
    }
}

// Migration 25 : texte du bouton de lien (ex. « Réserver »), propre à chaque
// événement — vide = texte par défaut configurable (evenements_lien_texte_defaut()).
function migration_25(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(evenements)')->fetchAll(), 'name');
    if (!in_array('lien_texte', $cols, true)) {
        $pdo->exec("ALTER TABLE evenements ADD COLUMN lien_texte TEXT NOT NULL DEFAULT ''");
    }
}

// Migration 26 : colonne pays dédiée (auparavant repliée dans les remarques à
// l'import CSV faute de champ propre — voir migration_24/région).
function migration_26(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(evenements)')->fetchAll(), 'name');
    if (!in_array('pays', $cols, true)) {
        $pdo->exec("ALTER TABLE evenements ADD COLUMN pays TEXT NOT NULL DEFAULT ''");
    }
}

// Migration 27 : rattache une ligne de prestation à l'événement qui l'a générée
// (ajout depuis la carte « Employés » de la fiche événement) — une seule ligne
// par événement/employé ; NULL pour les lignes créées via le formulaire de
// fiche classique. Voir evenement_ligne_pour()/route_evenement_ligne_ajouter().
function migration_27(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(fiche_lignes)')->fetchAll(), 'name');
    if (!in_array('evenement_id', $cols, true)) {
        $pdo->exec('ALTER TABLE fiche_lignes ADD COLUMN evenement_id INTEGER REFERENCES evenements(id) ON DELETE SET NULL');
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_fiche_lignes_evenement ON fiche_lignes(evenement_id)');
}

// Migration 28 : axe analytique par défaut d'un événement (carte « Comptabilité
// analytique ») — présélectionné pour les nouvelles prestations et pour les
// lignes d'une facture créée depuis cet événement, modifiable au cas par cas.
function migration_28(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(evenements)')->fetchAll(), 'name');
    if (!in_array('axe_analytique_id_defaut', $cols, true)) {
        $pdo->exec('ALTER TABLE evenements ADD COLUMN axe_analytique_id_defaut INTEGER REFERENCES axes_analytiques(id)');
    }
}

// Migration 29 : hiérarchie des spectacles (imbrication façon plan comptable —
// un spectacle-parent représente un artiste, ses enfants ses dates/tournées).
function migration_29(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(spectacles)')->fetchAll(), 'name');
    if (!in_array('parent_id', $cols, true)) {
        $pdo->exec('ALTER TABLE spectacles ADD COLUMN parent_id INTEGER REFERENCES spectacles(id) ON DELETE SET NULL');
    }
    if (!in_array('ordre', $cols, true)) {
        $pdo->exec('ALTER TABLE spectacles ADD COLUMN ordre INTEGER NOT NULL DEFAULT 0');
        // Comble l'ordre à partir de l'ancien tri alphabétique (ORDER BY nom),
        // sinon toutes les lignes existantes se retrouvent à ordre=0 et
        // retombent sur l'ordre de création (id) — perte silencieuse du tri.
        $upd = $pdo->prepare('UPDATE spectacles SET ordre = ? WHERE id = ?');
        $i = 0;
        foreach ($pdo->query('SELECT id FROM spectacles ORDER BY nom') as $row) {
            $upd->execute([$i++, (int) $row['id']]);
        }
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_spectacles_parent ON spectacles(parent_id)');
}

// Statut SUISA « abandonné » : délai (mois, depuis la date de l'événement)
// au-delà duquel un événement sans décompte cesse d'être compté « à faire »/
// « manquant » — voir evenements_delai_abandon_mois().
function migration_30(PDO $pdo): void
{
    $ins = $pdo->prepare('INSERT OR IGNORE INTO parametres (cle, valeur) VALUES (?, ?)');
    $ins->execute(['suisa_delai_abandon_mois', '60']);
}

// Carte « Organisateur » (fiche événement) : lien optionnel vers le débiteur à
// facturer pour cet événement (recherche d'un débiteur existant ou création
// rapide) — voir route_evenement_organisateur_lier().
function migration_31(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(evenements)')->fetchAll(), 'name');
    if (!in_array('organisateur_debiteur_id', $cols, true)) {
        $pdo->exec('ALTER TABLE evenements ADD COLUMN organisateur_debiteur_id INTEGER REFERENCES debiteurs(id) ON DELETE SET NULL');
    }
}

function migration_32(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(debiteurs)')->fetchAll(), 'name');
    if (!in_array('telephone', $cols, true)) {
        $pdo->exec("ALTER TABLE debiteurs ADD COLUMN telephone TEXT NOT NULL DEFAULT ''");
    }
    if (!in_array('personne_contact', $cols, true)) {
        $pdo->exec("ALTER TABLE debiteurs ADD COLUMN personne_contact TEXT NOT NULL DEFAULT ''");
    }
}

// Carte « Employés » (fiche événement) : bascule « production externe » — les
// employés liés n'ont alors pas de prestation/fiche de salaire (cachet géré
// par l'organisateur externe) ; cocher détache les prestations déjà liées,
// voir route_evenement_production_externe().
function migration_33(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(evenements)')->fetchAll(), 'name');
    if (!in_array('production_externe', $cols, true)) {
        $pdo->exec('ALTER TABLE evenements ADD COLUMN production_externe INTEGER NOT NULL DEFAULT 0');
    }
}

// Droits par module (lecture/écriture) par utilisateur — voir SPEC_PERMISSIONS.md
// et lib/modules.php (peut_lire()/peut_ecrire()). Absence de ligne pour une paire
// (utilisateur, module) = aucun accès. « coeur » est un module à part (jamais dans
// MODULES, voir modules.php) qui recouvre paramètres/apparence/gestion des comptes ;
// écriture sur coeur = administrateur.
//
// Backfill : liste de modules figée ici (pas de référence à la constante MODULES,
// qui peut évoluer) — tous les comptes déjà créés à cette date gardent l'accès
// complet dont ils disposaient avant l'introduction des permissions, aucune
// régression au déploiement. Les comptes créés après coup démarrent sans aucun
// droit (principe du moindre privilège), à attribuer explicitement par un admin.
function migration_34(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS utilisateur_permissions (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            utilisateur_id INTEGER NOT NULL REFERENCES utilisateurs(id) ON DELETE CASCADE,
            module         TEXT NOT NULL,
            niveau         TEXT NOT NULL CHECK (niveau IN ('lecture','ecriture')),
            UNIQUE (utilisateur_id, module)
        )
        SQL);
    $modules = ['coeur', 'salaires', 'compta', 'analytique', 'facturation', 'evenements'];
    $ids = $pdo->query('SELECT id FROM utilisateurs')->fetchAll(PDO::FETCH_COLUMN);
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO utilisateur_permissions (utilisateur_id, module, niveau) VALUES (?, ?, ?)');
    foreach ($ids as $uid) {
        foreach ($modules as $module) {
            $stmt->execute([$uid, $module, 'ecriture']);
        }
    }
}

// Module booking : renommage intégral debiteurs → structures (table + colonnes
// FK qui la référencent), + nouveaux champs propres au CRM. Renommage direct
// (pas de passage par un nom temporaire type « x_old ») : validé empiriquement
// que SQLite met à jour lui-même les clauses REFERENCES/les index des autres
// tables lors d'un simple ALTER TABLE … RENAME TO / RENAME COLUMN — c'est
// spécifiquement le pattern « renommer puis recréer sous l'ancien nom » qui est
// piégeux (voir migration_21 et le commentaire de CLAUDE.md), pas un renommage
// direct et définitif comme celui-ci. Vérifié après coup avec
// PRAGMA foreign_key_check en développement.
function migration_35(PDO $pdo): void
{
    $tables = array_column($pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(), 'name');
    if (in_array('debiteurs', $tables, true) && !in_array('structures', $tables, true)) {
        $pdo->exec('ALTER TABLE debiteurs RENAME TO structures');
    }

    $colsFactures = array_column($pdo->query('PRAGMA table_info(factures)')->fetchAll(), 'name');
    if (in_array('debiteur_id', $colsFactures, true) && !in_array('structure_id', $colsFactures, true)) {
        $pdo->exec('ALTER TABLE factures RENAME COLUMN debiteur_id TO structure_id');
        $pdo->exec('DROP INDEX IF EXISTS idx_factures_debiteur');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_factures_structure ON factures(structure_id)');
    }

    $colsEvenements = array_column($pdo->query('PRAGMA table_info(evenements)')->fetchAll(), 'name');
    if (in_array('organisateur_debiteur_id', $colsEvenements, true) && !in_array('organisateur_structure_id', $colsEvenements, true)) {
        $pdo->exec('ALTER TABLE evenements RENAME COLUMN organisateur_debiteur_id TO organisateur_structure_id');
    }

    $colsStructures = array_column($pdo->query('PRAGMA table_info(structures)')->fetchAll(), 'name');
    $ajouts = [
        'categorie'          => "TEXT NOT NULL DEFAULT 'organisateur'", // 'organisateur' | 'media' | 'autres' | 'entourage'
        'region'             => "TEXT NOT NULL DEFAULT ''",
        'site_web'           => "TEXT NOT NULL DEFAULT ''",
        'dernier_contact_le' => "TEXT NOT NULL DEFAULT ''", // dérivé, voir structure_recalculer_dernier_contact()
        'desinscrit'         => 'INTEGER NOT NULL DEFAULT 0',
    ];
    foreach ($ajouts as $col => $ddl) {
        if (!in_array($col, $colsStructures, true)) {
            $pdo->exec("ALTER TABLE structures ADD COLUMN $col $ddl");
        }
    }
}

// Module booking : CRM (contacts multiples, lieux/salles-festivals, tags,
// notes en flux, mailing par file d'attente) + référentiel départements→région
// (France) pour normaliser structures.region à l'import. Voir SPEC_BOOKING.md.
function migration_36(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS structure_contacts (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            structure_id   INTEGER NOT NULL REFERENCES structures(id) ON DELETE CASCADE,
            prenom         TEXT NOT NULL DEFAULT '',
            nom            TEXT NOT NULL DEFAULT '',
            role           TEXT NOT NULL DEFAULT '',
            email          TEXT NOT NULL DEFAULT '',
            telephone      TEXT NOT NULL DEFAULT '',
            formulaire_url TEXT NOT NULL DEFAULT '',
            langue         TEXT NOT NULL DEFAULT '',
            desinscrit     INTEGER NOT NULL DEFAULT 0,
            actif          INTEGER NOT NULL DEFAULT 1,
            cree_le        TEXT NOT NULL DEFAULT (datetime('now'))
        );

        CREATE TABLE IF NOT EXISTS lieux (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            type       TEXT NOT NULL DEFAULT 'Salle', -- catégorie de lieu (table lieu_categories), voir migration_45
            nom        TEXT NOT NULL,
            ville      TEXT NOT NULL DEFAULT '',
            region     TEXT NOT NULL DEFAULT '',        -- département / canton
            grande_region TEXT NOT NULL DEFAULT '',      -- grande région (Normandie, Romandie…) — voir migration_44
            pays       TEXT NOT NULL DEFAULT '',
            mois_debut INTEGER,
            mois_fin   INTEGER,
            dernier_concert_le TEXT NOT NULL DEFAULT '', -- dernier concert / diffusion — voir migration_44
            notes      TEXT NOT NULL DEFAULT '',
            cree_le    TEXT NOT NULL DEFAULT (datetime('now'))
        );

        CREATE TABLE IF NOT EXISTS structure_lieux (
            structure_id INTEGER NOT NULL REFERENCES structures(id) ON DELETE CASCADE,
            lieu_id      INTEGER NOT NULL REFERENCES lieux(id) ON DELETE CASCADE,
            role         TEXT NOT NULL DEFAULT '',
            PRIMARY KEY (structure_id, lieu_id)
        );

        CREATE TABLE IF NOT EXISTS structure_tags (
            id  INTEGER PRIMARY KEY AUTOINCREMENT,
            nom TEXT NOT NULL UNIQUE
        );

        CREATE TABLE IF NOT EXISTS structure_tag_liens (
            structure_id INTEGER NOT NULL REFERENCES structures(id) ON DELETE CASCADE,
            tag_id       INTEGER NOT NULL REFERENCES structure_tags(id) ON DELETE CASCADE,
            PRIMARY KEY (structure_id, tag_id)
        );

        CREATE TABLE IF NOT EXISTS structure_notes (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            structure_id   INTEGER NOT NULL REFERENCES structures(id) ON DELETE CASCADE,
            contenu        TEXT NOT NULL DEFAULT '',
            est_contact    INTEGER NOT NULL DEFAULT 0,
            utilisateur_id INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
            cree_le        TEXT NOT NULL DEFAULT (datetime('now'))
        );

        CREATE TABLE IF NOT EXISTS mailing_file_attente (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            structure_id INTEGER NOT NULL REFERENCES structures(id) ON DELETE CASCADE,
            contact_id   INTEGER REFERENCES structure_contacts(id) ON DELETE CASCADE,
            sujet        TEXT NOT NULL DEFAULT '',
            corps        TEXT NOT NULL DEFAULT '',
            statut       TEXT NOT NULL DEFAULT 'attente', -- 'attente' | 'envoye' | 'echec'
            cree_le      TEXT NOT NULL DEFAULT (datetime('now'))
        );

        CREATE TABLE IF NOT EXISTS mailing_envois (
            id                 INTEGER PRIMARY KEY AUTOINCREMENT,
            structure_id       INTEGER NOT NULL REFERENCES structures(id) ON DELETE CASCADE,
            contact_id         INTEGER REFERENCES structure_contacts(id) ON DELETE SET NULL,
            sujet              TEXT NOT NULL DEFAULT '',
            destinataire_email TEXT NOT NULL DEFAULT '',
            succes             INTEGER NOT NULL DEFAULT 0,
            envoye_le          TEXT NOT NULL DEFAULT (datetime('now'))
        );

        CREATE TABLE IF NOT EXISTS departements_regions (
            code        TEXT PRIMARY KEY,
            departement TEXT NOT NULL,
            region      TEXT NOT NULL
        );

        CREATE INDEX IF NOT EXISTS idx_structure_contacts_structure ON structure_contacts(structure_id);
        CREATE INDEX IF NOT EXISTS idx_structure_notes_structure ON structure_notes(structure_id);
        CREATE INDEX IF NOT EXISTS idx_mailing_file_attente_statut ON mailing_file_attente(statut);
        CREATE INDEX IF NOT EXISTS idx_mailing_envois_structure ON mailing_envois(structure_id);
        CREATE INDEX IF NOT EXISTS idx_mailing_envois_envoye_le ON mailing_envois(envoye_le);
        SQL);

    // Backfill : le contact unique déjà saisi sur structures (personne_contact/
    // telephone, champs texte libres existants) devient le premier
    // structure_contacts — une seule fois (skip si déjà fait, ex. relance de migration).
    if ((int) $pdo->query('SELECT COUNT(*) FROM structure_contacts')->fetchColumn() === 0) {
        $rows = $pdo->query("SELECT id, personne_contact, telephone, email FROM structures
                              WHERE TRIM(personne_contact) <> '' OR TRIM(telephone) <> ''")->fetchAll();
        $ins = $pdo->prepare('INSERT INTO structure_contacts (structure_id, prenom, nom, email, telephone)
                               VALUES (?, ?, ?, ?, ?)');
        foreach ($rows as $r) {
            $ins->execute([(int) $r['id'], '', (string) $r['personne_contact'], (string) $r['email'], (string) $r['telephone']]);
        }
    }

    // Référentiel départements français → région administrative (source : liste
    // officielle des départements), pour normaliser structures.region à l'import
    // CSV quand seul un code département est fourni — voir SPEC_BOOKING.md §4/§9.
    if ((int) $pdo->query('SELECT COUNT(*) FROM departements_regions')->fetchColumn() === 0) {
        $departements = [
        ['1', 'Ain', 'Auvergne-Rhône-Alpes'],
        ['2', 'Aisne', 'Hauts-de-France'],
        ['3', 'Allier', 'Auvergne-Rhône-Alpes'],
        ['4', 'Alpes-de-Haute-Provence', 'Sud'],
        ['5', 'Hautes-Alpes', 'Sud'],
        ['6', 'Alpes-Maritimes', 'Sud'],
        ['7', 'Ardèche', 'Auvergne-Rhône-Alpes'],
        ['8', 'Ardennes', 'Grand Est'],
        ['9', 'Ariège', 'Occitanie'],
        ['10', 'Aube', 'Grand Est'],
        ['11', 'Aude', 'Occitanie'],
        ['12', 'Aveyron', 'Occitanie'],
        ['13', 'Bouches-du-Rhône', 'Sud'],
        ['14', 'Calvados', 'Normandie'],
        ['15', 'Cantal', 'Auvergne-Rhône-Alpes'],
        ['16', 'Charente', 'Nouvelle-Aquitaine'],
        ['17', 'Charente-Maritime', 'Nouvelle-Aquitaine'],
        ['18', 'Cher', 'Centre-Val de Loire'],
        ['19', 'Corrèze', 'Nouvelle-Aquitaine'],
        ['2A', 'Corse-du-Sud', 'Corse'],
        ['2B', 'Haute-Corse', 'Corse'],
        ['21', "Côte-d'Or", 'Bourgogne-Franche-Comté'],
        ['22', "Côtes-d'Armor", 'Bretagne'],
        ['23', 'Creuse', 'Nouvelle-Aquitaine'],
        ['24', 'Dordogne', 'Nouvelle-Aquitaine'],
        ['25', 'Doubs', 'Bourgogne-Franche-Comté'],
        ['26', 'Drôme', 'Auvergne-Rhône-Alpes'],
        ['27', 'Eure', 'Normandie'],
        ['28', 'Eure-et-Loir', 'Centre-Val de Loire'],
        ['29', 'Finistère', 'Bretagne'],
        ['30', 'Gard', 'Occitanie'],
        ['31', 'Haute-Garonne', 'Occitanie'],
        ['32', 'Gers', 'Occitanie'],
        ['33', 'Gironde', 'Nouvelle-Aquitaine'],
        ['34', 'Hérault', 'Occitanie'],
        ['35', 'Ille-et-Vilaine', 'Bretagne'],
        ['36', 'Indre', 'Centre-Val de Loire'],
        ['37', 'Indre-et-Loire', 'Centre-Val de Loire'],
        ['38', 'Isère', 'Auvergne-Rhône-Alpes'],
        ['39', 'Jura', 'Bourgogne-Franche-Comté'],
        ['40', 'Landes', 'Nouvelle-Aquitaine'],
        ['41', 'Loir-et-Cher', 'Centre-Val de Loire'],
        ['42', 'Loire', 'Auvergne-Rhône-Alpes'],
        ['43', 'Haute-Loire', 'Auvergne-Rhône-Alpes'],
        ['44', 'Loire-Atlantique', 'Pays de la Loire'],
        ['45', 'Loiret', 'Centre-Val de Loire'],
        ['46', 'Lot', 'Occitanie'],
        ['47', 'Lot-et-Garonne', 'Nouvelle-Aquitaine'],
        ['48', 'Lozère', 'Occitanie'],
        ['49', 'Maine-et-Loire', 'Pays de la Loire'],
        ['50', 'Manche', 'Normandie'],
        ['51', 'Marne', 'Grand Est'],
        ['52', 'Haute-Marne', 'Grand Est'],
        ['53', 'Mayenne', 'Pays de la Loire'],
        ['54', 'Meurthe-et-Moselle', 'Grand Est'],
        ['55', 'Meuse', 'Grand Est'],
        ['56', 'Morbihan', 'Bretagne'],
        ['57', 'Moselle', 'Grand Est'],
        ['58', 'Nièvre', 'Bourgogne-Franche-Comté'],
        ['59', 'Nord', 'Hauts-de-France'],
        ['60', 'Oise', 'Hauts-de-France'],
        ['61', 'Orne', 'Normandie'],
        ['62', 'Pas-de-Calais', 'Hauts-de-France'],
        ['63', 'Puy-de-Dôme', 'Auvergne-Rhône-Alpes'],
        ['64', 'Pyrénées-Atlantiques', 'Nouvelle-Aquitaine'],
        ['65', 'Hautes-Pyrénées', 'Occitanie'],
        ['66', 'Pyrénées-Orientales', 'Occitanie'],
        ['67', 'Bas-Rhin', 'Grand Est'],
        ['68', 'Haut-Rhin', 'Grand Est'],
        ['69', 'Rhône', 'Auvergne-Rhône-Alpes'],
        ['70', 'Haute-Saône', 'Bourgogne-Franche-Comté'],
        ['71', 'Saône-et-Loire', 'Bourgogne-Franche-Comté'],
        ['72', 'Sarthe', 'Pays de la Loire'],
        ['73', 'Savoie', 'Auvergne-Rhône-Alpes'],
        ['74', 'Haute-Savoie', 'Auvergne-Rhône-Alpes'],
        ['75', 'Paris', 'Île-de-France'],
        ['76', 'Seine-Maritime', 'Normandie'],
        ['77', 'Seine-et-Marne', 'Île-de-France'],
        ['78', 'Yvelines', 'Île-de-France'],
        ['79', 'Deux-Sèvres', 'Nouvelle-Aquitaine'],
        ['80', 'Somme', 'Hauts-de-France'],
        ['81', 'Tarn', 'Occitanie'],
        ['82', 'Tarn-et-Garonne', 'Occitanie'],
        ['83', 'Var', 'Sud'],
        ['84', 'Vaucluse', 'Sud'],
        ['85', 'Vendée', 'Pays de la Loire'],
        ['86', 'Vienne', 'Nouvelle-Aquitaine'],
        ['87', 'Haute-Vienne', 'Nouvelle-Aquitaine'],
        ['88', 'Vosges', 'Grand Est'],
        ['89', 'Yonne', 'Bourgogne-Franche-Comté'],
        ['90', 'Territoire de Belfort', 'Bourgogne-Franche-Comté'],
        ['91', 'Essonne', 'Île-de-France'],
        ['92', 'Hauts-de-Seine', 'Île-de-France'],
        ['93', 'Seine-Saint-Denis', 'Île-de-France'],
        ['94', 'Val-de-Marne', 'Île-de-France'],
        ['95', "Val-d'Oise", 'Île-de-France'],
        ['971', 'Guadeloupe', 'Guadeloupe'],
        ['972', 'Martinique', 'Martinique'],
        ['973', 'Guyane', 'Guyane'],
        ['974', 'La Réunion', 'La Réunion'],
        ['976', 'Mayotte', 'Mayotte'],
        ];
        $insDep = $pdo->prepare('INSERT INTO departements_regions (code, departement, region) VALUES (?, ?, ?)');
        foreach ($departements as $d) {
            $insDep->execute($d);
        }
    }
}

// Module booking : sous-catégorie (ex. catégorie « média » / sous-catégorie
// « journaliste »), date de dernière mise à jour importée (distincte de
// dernier_contact_le, qui reste dérivée), et jauge min/max pour une salle ou
// un festival — voir SPEC_BOOKING.md et l'analyse du carnet d'adresses fourni.
function migration_37(PDO $pdo): void
{
    $colsStructures = array_column($pdo->query('PRAGMA table_info(structures)')->fetchAll(), 'name');
    if (!in_array('sous_categorie', $colsStructures, true)) {
        $pdo->exec("ALTER TABLE structures ADD COLUMN sous_categorie TEXT NOT NULL DEFAULT ''");
    }
    if (!in_array('mise_a_jour_le', $colsStructures, true)) {
        $pdo->exec("ALTER TABLE structures ADD COLUMN mise_a_jour_le TEXT NOT NULL DEFAULT ''");
    }

    $colsLieux = array_column($pdo->query('PRAGMA table_info(lieux)')->fetchAll(), 'name');
    if (!in_array('jauge_min', $colsLieux, true)) {
        $pdo->exec('ALTER TABLE lieux ADD COLUMN jauge_min INTEGER');
    }
    if (!in_array('jauge_max', $colsLieux, true)) {
        $pdo->exec('ALTER TABLE lieux ADD COLUMN jauge_max INTEGER');
    }
}

// Module booking : « Via » — texte libre notant d'où vient le contact (ex. un
// intermédiaire, une recommandation), pour se souvenir de la source d'une
// relation sans devoir fouiller les notes.
function migration_38(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(structures)')->fetchAll(), 'name');
    if (!in_array('via', $cols, true)) {
        $pdo->exec("ALTER TABLE structures ADD COLUMN via TEXT NOT NULL DEFAULT ''");
    }
}

// Module booking : catégories/sous-catégories configurables (Paramètres),
// remplaçant la liste figée STRUCTURE_CATEGORIES. structures.categorie/
// sous_categorie restent des colonnes texte (comparaison par nom, pas par id)
// — un renommage dans les paramètres met à jour les structures existantes en
// même temps (voir route_parametres_structure_categories()), donc pas besoin
// d'une FK par id pour rester cohérent. Seedé une seule fois à partir des
// 4 catégories historiques + des sous-catégories déjà utilisées dans les
// structures existantes (import CSV notamment) : aucune perte de données.
function migration_39(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS structure_categories (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            nom              TEXT NOT NULL UNIQUE,
            est_organisateur INTEGER NOT NULL DEFAULT 0,
            ordre            INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS structure_sous_categories (
            id    INTEGER PRIMARY KEY AUTOINCREMENT,
            nom   TEXT NOT NULL UNIQUE,
            ordre INTEGER NOT NULL DEFAULT 0
        );
        SQL);

    if ((int) $pdo->query('SELECT COUNT(*) FROM structure_categories')->fetchColumn() === 0) {
        $ins = $pdo->prepare('INSERT INTO structure_categories (nom, est_organisateur, ordre) VALUES (?, ?, ?)');
        foreach ([['organisateur', 1, 0], ['media', 0, 1], ['autres', 0, 2], ['entourage', 0, 3]] as $c) {
            $ins->execute($c);
        }
    }
    if ((int) $pdo->query('SELECT COUNT(*) FROM structure_sous_categories')->fetchColumn() === 0) {
        $existantes = $pdo->query("SELECT DISTINCT sous_categorie FROM structures WHERE sous_categorie <> '' ORDER BY sous_categorie")
            ->fetchAll(PDO::FETCH_COLUMN);
        $ins = $pdo->prepare('INSERT OR IGNORE INTO structure_sous_categories (nom, ordre) VALUES (?, ?)');
        foreach ($existantes as $i => $nom) {
            $ins->execute([$nom, $i]);
        }
    }
}

// Module booking : un contact peut être marqué « administration » (utilisé
// comme destinataire par défaut d'une facture) et/ou « booking » (seul
// destinataire du mailing quand plusieurs contacts existent) — remplace
// structures.email/telephone/personne_contact comme source de vérité pour
// ces usages (colonnes conservées, mais plus modifiées depuis le formulaire).
// Rétrocompatibilité : une structure avec un e-mail mais encore aucun contact
// en reçoit un minimal ; un contact resté seul sur sa structure (le cas le
// plus fréquent) est marqué des deux à la place de l'admin, sans ambiguïté.
function migration_40(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(structure_contacts)')->fetchAll(), 'name');
    if (!in_array('est_administration', $cols, true)) {
        $pdo->exec('ALTER TABLE structure_contacts ADD COLUMN est_administration INTEGER NOT NULL DEFAULT 0');
    }
    if (!in_array('est_booking', $cols, true)) {
        $pdo->exec('ALTER TABLE structure_contacts ADD COLUMN est_booking INTEGER NOT NULL DEFAULT 0');
    }

    $manquants = $pdo->query(
        "SELECT id, email FROM structures
         WHERE email <> '' AND id NOT IN (SELECT DISTINCT structure_id FROM structure_contacts)"
    )->fetchAll();
    $ins = $pdo->prepare('INSERT INTO structure_contacts (structure_id, email, est_administration, est_booking) VALUES (?, ?, 1, 1)');
    foreach ($manquants as $s) {
        $ins->execute([(int) $s['id'], $s['email']]);
    }

    $seuls = $pdo->query(
        "SELECT id FROM structure_contacts
         WHERE structure_id IN (SELECT structure_id FROM structure_contacts GROUP BY structure_id HAVING COUNT(*) = 1)
           AND est_administration = 0 AND est_booking = 0"
    )->fetchAll(PDO::FETCH_COLUMN);
    $upd = $pdo->prepare('UPDATE structure_contacts SET est_administration = 1, est_booking = 1 WHERE id = ?');
    foreach ($seuls as $id) {
        $upd->execute([(int) $id]);
    }
}

function migration_41(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(lieux)')->fetchAll(), 'name');
    if (!in_array('mois_evenement_debut', $cols, true)) {
        $pdo->exec('ALTER TABLE lieux ADD COLUMN mois_evenement_debut INTEGER');
    }
    if (!in_array('mois_evenement_fin', $cols, true)) {
        $pdo->exec('ALTER TABLE lieux ADD COLUMN mois_evenement_fin INTEGER');
    }
}

// Migration 42 : les sous-catégories doivent être imbriquées dans une catégorie
// (même principe que spectacles.parent_id — voir lib/compta.php plan_*(), réutilisées
// telles quelles pour cet arbre à 2 niveaux). structure_categories gagne parent_id ;
// structure_sous_categories est fusionnée dedans (chaque sous-catégorie existante
// devient un enfant de la catégorie « organisateur », seule catégorie avec laquelle
// elle était utilisée dans les vraies données — vérifié empiriquement, aucune
// ambiguïté) puis supprimée.
// ⚠️ La contrainte UNIQUE(nom) doit devenir UNIQUE(parent_id, nom) (une
// sous-catégorie peut porter le même nom qu'une catégorie racine — c'est déjà le
// cas réel pour « Autres »). SQLite ne sait pas retirer une contrainte de colonne
// via ALTER TABLE : recréation de la table, même procédure sûre que migration_21
// (nom temporaire, copie, DROP de l'originale — pas de RENAME vers un nom
// temporaire — puis RENAME du nom temporaire).
function migration_42(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(structure_categories)')->fetchAll(), 'name');
    if (in_array('parent_id', $cols, true)) {
        return; // déjà migré
    }

    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->exec('
        CREATE TABLE structure_categories_v42 (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            nom              TEXT NOT NULL,
            parent_id        INTEGER REFERENCES structure_categories_v42(id) ON DELETE SET NULL,
            est_organisateur INTEGER NOT NULL DEFAULT 0,
            ordre            INTEGER NOT NULL DEFAULT 0,
            UNIQUE(parent_id, nom)
        )
    ');
    $pdo->exec(
        'INSERT INTO structure_categories_v42 (id, nom, parent_id, est_organisateur, ordre)
         SELECT id, nom, NULL, est_organisateur, ordre FROM structure_categories'
    );

    $sousCatTableExiste = (bool) $pdo->query(
        "SELECT 1 FROM sqlite_master WHERE type='table' AND name='structure_sous_categories'"
    )->fetchColumn();
    if ($sousCatTableExiste) {
        $racineId = (int) $pdo->query(
            'SELECT id FROM structure_categories_v42 WHERE est_organisateur = 1 ORDER BY ordre LIMIT 1'
        )->fetchColumn();
        if (!$racineId) {
            $racineId = (int) $pdo->query('SELECT id FROM structure_categories_v42 ORDER BY ordre LIMIT 1')->fetchColumn();
        }
        if ($racineId) {
            $ins = $pdo->prepare(
                'INSERT OR IGNORE INTO structure_categories_v42 (nom, parent_id, est_organisateur, ordre) VALUES (?, ?, 0, ?)'
            );
            foreach ($pdo->query('SELECT nom, ordre FROM structure_sous_categories ORDER BY ordre')->fetchAll() as $sc) {
                $ins->execute([$sc['nom'], $racineId, (int) $sc['ordre']]);
            }
        }
    }

    $pdo->exec('DROP TABLE structure_categories');
    $pdo->exec('ALTER TABLE structure_categories_v42 RENAME TO structure_categories');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_structure_categories_parent ON structure_categories(parent_id)');
    $pdo->exec('DROP TABLE IF EXISTS structure_sous_categories');
    $pdo->exec('PRAGMA foreign_keys = ON');

    $casse = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
    if ($casse) {
        throw new RuntimeException('migration_42 : clé étrangère cassée après migration — ' . json_encode($casse));
    }
}

// Migration 43 : liste de pays configurable, unique pour toute l'app (structures,
// lieux, employeur, événements, facturation) — remplace le texte libre saisi
// dans chaque champ pays, et le paramètre séparé evenements_pays_disponibles.
// Idempotente ; seed uniquement à la création (n'écrase jamais une liste déjà
// personnalisée par l'utilisateur). Les colonnes pays existantes (texte libre)
// ne sont pas touchées : seule l'interface de saisie change (input → select).
function migration_43(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS pays_liste (
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            nom       TEXT NOT NULL UNIQUE,
            code_iso2 TEXT NOT NULL UNIQUE,
            ordre     INTEGER NOT NULL DEFAULT 0
        )
    ');
    if ((int) $pdo->query('SELECT COUNT(*) FROM pays_liste')->fetchColumn() === 0) {
        $defaut = [
            ['Suisse', 'CH'], ['France', 'FR'], ['Belgique', 'BE'], ['Luxembourg', 'LU'],
            ['Allemagne', 'DE'], ['Italie', 'IT'], ['Espagne', 'ES'], ['Canada', 'CA'],
            ['Royaume-Uni', 'GB'], ['Pays-Bas', 'NL'], ['Autriche', 'AT'], ['Portugal', 'PT'],
        ];
        $ins = $pdo->prepare('INSERT INTO pays_liste (nom, code_iso2, ordre) VALUES (?, ?, ?)');
        foreach ($defaut as $i => $p) {
            $ins->execute([$p[0], $p[1], $i]);
        }
    }
}

// Migration 45 : taxonomie propre aux lieux (« catégories de lieu »), qui
// remplace le binaire salle/festival du champ lieux.type. Table gérée à part
// (Paramètres → Catégories de lieu). Les valeurs existantes 'salle'/'festival'
// sont converties vers les intitulés de la nouvelle liste.
function migration_45(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS lieu_categories (
            id    INTEGER PRIMARY KEY AUTOINCREMENT,
            nom   TEXT NOT NULL UNIQUE,
            ordre INTEGER NOT NULL DEFAULT 0
        )
    ');
    if ((int) $pdo->query('SELECT COUNT(*) FROM lieu_categories')->fetchColumn() === 0) {
        $defaut = [
            'Salle', 'Festival', 'Salle de concert', 'Salle communale', 'Salle de location',
            'Saison culturelle', 'Café-concert', 'Café associatif', 'Centre culturel',
            'Théâtre', 'MJC', 'Médiathèque', 'SMAC',
        ];
        $ins = $pdo->prepare('INSERT INTO lieu_categories (nom, ordre) VALUES (?, ?)');
        foreach ($defaut as $i => $nom) {
            $ins->execute([$nom, $i]);
        }
    }
    // Reprise des valeurs héritées (codes en minuscules → intitulés de la liste).
    $pdo->exec("UPDATE lieux SET type = 'Salle' WHERE type = 'salle'");
    $pdo->exec("UPDATE lieux SET type = 'Festival' WHERE type = 'festival'");
}

// Migration 46 : la liste « ne pas contacter » devient une table dédiée
// (mailing_exclusions) — ajouter un e-mail inconnu n'y crée plus de structure
// fantôme (nom = e-mail). Reprise : les placeholders créés par l'ancienne
// logique (structure désinscrite, inactive, nom = e-mail, un seul contact du
// même e-mail, aucune autre donnée) sont convertis en simples entrées de la
// table puis supprimés — critères volontairement stricts pour ne jamais
// toucher une vraie structure.
function migration_46(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS mailing_exclusions (
            id      INTEGER PRIMARY KEY AUTOINCREMENT,
            email   TEXT NOT NULL UNIQUE COLLATE NOCASE,
            cree_le TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )
    ');
    $places = $pdo->query(
        "SELECT s.id, s.email FROM structures s
         WHERE s.desinscrit = 1 AND s.actif = 0 AND s.email <> '' AND s.nom = s.email
           AND (SELECT COUNT(*) FROM structure_contacts c WHERE c.structure_id = s.id) = 1
           AND EXISTS (SELECT 1 FROM structure_contacts c WHERE c.structure_id = s.id
                         AND c.email = s.email AND c.desinscrit = 1)
           AND NOT EXISTS (SELECT 1 FROM factures f WHERE f.structure_id = s.id)
           AND NOT EXISTS (SELECT 1 FROM structure_notes n WHERE n.structure_id = s.id)
           AND NOT EXISTS (SELECT 1 FROM structure_lieux sl WHERE sl.structure_id = s.id)
           AND NOT EXISTS (SELECT 1 FROM structure_tag_liens t WHERE t.structure_id = s.id)"
    )->fetchAll();
    $ins = $pdo->prepare('INSERT OR IGNORE INTO mailing_exclusions (email) VALUES (?)');
    $delC = $pdo->prepare('DELETE FROM structure_contacts WHERE structure_id = ?');
    $delS = $pdo->prepare('DELETE FROM structures WHERE id = ?');
    foreach ($places as $p) {
        $ins->execute([trim((string) $p['email'])]);
        $delC->execute([(int) $p['id']]);
        $delS->execute([(int) $p['id']]);
    }
}

// Migration 47 : ciblages types du mailing — un nom + les critères (JSON) tels
// que construits par mailing_criteres_depuis(), rechargeables en un clic.
function migration_47(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS mailing_ciblages (
            id       INTEGER PRIMARY KEY AUTOINCREMENT,
            nom      TEXT NOT NULL UNIQUE,
            criteres TEXT NOT NULL,
            cree_le  TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )
    ');
}

// Migration 48 : historique des campagnes + modèles de message. Chaque campagne
// (sujet/corps/critères/nb visé) regroupe ses lignes de file et ses envois via
// campagne_id → statistiques (envoyés / échecs / en attente).
function migration_48(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS mailing_campagnes (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            sujet            TEXT NOT NULL DEFAULT \'\',
            corps            TEXT NOT NULL DEFAULT \'\',
            criteres         TEXT NOT NULL DEFAULT \'\',
            nb_destinataires INTEGER NOT NULL DEFAULT 0,
            cree_le          TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )
    ');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS mailing_modeles (
            id      INTEGER PRIMARY KEY AUTOINCREMENT,
            nom     TEXT NOT NULL UNIQUE,
            sujet   TEXT NOT NULL DEFAULT \'\',
            corps   TEXT NOT NULL DEFAULT \'\',
            cree_le TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )
    ');
    foreach (['mailing_file_attente', 'mailing_envois'] as $t) {
        $cols = array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(), 'name');
        if (!in_array('campagne_id', $cols, true)) {
            $pdo->exec("ALTER TABLE $t ADD COLUMN campagne_id INTEGER");
        }
    }
}

// Migration 49 : les « grandes régions » (Normandie, Romandie, Acadie…)
// deviennent une taxonomie imbriquée sous les pays. pays_liste devient un arbre
// à DEUX niveaux (parent_id NULL = pays ; sinon = région d'un pays) et
// code_iso2 passe nullable (les régions n'en ont pas). Les couples
// (pays, grande_region) déjà présents dans structures/lieux sont amorcés comme
// régions du bon pays. Les fiches continuent de stocker la région en TEXTE
// (grande_region) : la taxonomie ne pilote que les listes déroulantes et la
// propagation des renommages — comme categorie/sous_categorie/pays.
function migration_49(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(pays_liste)')->fetchAll(), 'name');
    if (in_array('parent_id', $cols, true)) {
        return; // déjà migré
    }
    // Aucune autre table ne référence pays_liste (valeurs stockées en texte) :
    // recréation directe (nouvelle table → copie des pays → drop → renommage),
    // pas de RENAME de l'ancienne (cf. avertissement CLAUDE.md sur la réécriture
    // des clauses REFERENCES). code_iso2 devient nullable (régions sans code).
    $pdo->exec('
        CREATE TABLE pays_liste_new (
            id        INTEGER PRIMARY KEY AUTOINCREMENT,
            parent_id INTEGER REFERENCES pays_liste_new(id) ON DELETE CASCADE,
            nom       TEXT NOT NULL,
            code_iso2 TEXT,
            ordre     INTEGER NOT NULL DEFAULT 0
        )
    ');
    $pdo->exec('INSERT INTO pays_liste_new (id, parent_id, nom, code_iso2, ordre)
                SELECT id, NULL, nom, code_iso2, ordre FROM pays_liste');
    $pdo->exec('DROP TABLE pays_liste');
    $pdo->exec('ALTER TABLE pays_liste_new RENAME TO pays_liste');
    // Index d'unicité partiels : nom de pays unique (parent NULL), nom de région
    // unique dans son pays, code ISO2 unique parmi les pays qui en ont un.
    $pdo->exec('CREATE UNIQUE INDEX pays_uniq_pays   ON pays_liste(nom)             WHERE parent_id IS NULL');
    $pdo->exec('CREATE UNIQUE INDEX pays_uniq_region ON pays_liste(parent_id, nom)  WHERE parent_id IS NOT NULL');
    $pdo->exec('CREATE UNIQUE INDEX pays_uniq_code   ON pays_liste(code_iso2)       WHERE code_iso2 IS NOT NULL');

    // Amorçage : chaque (pays, grande_region) distinct des fiches → région du pays.
    $pdo->exec("
        INSERT INTO pays_liste (parent_id, nom, code_iso2, ordre)
        SELECT p.id, r.gr, NULL, 0
        FROM (
            SELECT DISTINCT adresse_pays AS pays, grande_region AS gr FROM structures WHERE grande_region <> ''
            UNION
            SELECT DISTINCT pays          AS pays, grande_region AS gr FROM lieux      WHERE grande_region <> ''
        ) r
        JOIN pays_liste p ON p.nom = r.pays AND p.parent_id IS NULL
        WHERE NOT EXISTS (
            SELECT 1 FROM pays_liste x WHERE x.parent_id = p.id AND x.nom = r.gr
        )
    ");
}

// Migration 50 : module booking — un lieu peut être actif/inactif (comme les
// structures) et porter un site web propre.
function migration_50(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(lieux)')->fetchAll(), 'name');
    if (!in_array('actif', $cols, true)) {
        $pdo->exec("ALTER TABLE lieux ADD COLUMN actif INTEGER NOT NULL DEFAULT 1");
    }
    if (!in_array('site_web', $cols, true)) {
        $pdo->exec("ALTER TABLE lieux ADD COLUMN site_web TEXT NOT NULL DEFAULT ''");
    }
}

// Migration 51 : un événement peut être rattaché à un LIEU de la base (en plus
// des champs texte historiques salle/festival, conservés pour l'affichage).
function migration_51(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(evenements)')->fetchAll(), 'name');
    if (!in_array('lieu_id', $cols, true)) {
        $pdo->exec('ALTER TABLE evenements ADD COLUMN lieu_id INTEGER REFERENCES lieux(id) ON DELETE SET NULL');
    }
}

// Migration 52 : historique typé unifié des fiches (structures ET lieux). Chaque
// entrée porte un type ('edition' = modif de champ avec diff, 'note' = note
// manuelle, 'mailing' = contact/envoi, 'dernier_concert' = date de dernier
// concert). Les structure_notes existantes y sont recopiées (est_contact=1 →
// 'mailing', sinon 'note') ; la table structure_notes reste en place (legacy),
// mais toutes les lectures/écritures passent désormais par historique.
function migration_52(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS historique (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            entite_type    TEXT NOT NULL,
            entite_id      INTEGER NOT NULL,
            type           TEXT NOT NULL,
            contenu        TEXT NOT NULL DEFAULT '',
            utilisateur_id INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL,
            cree_le        TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_historique_entite ON historique(entite_type, entite_id, cree_le)");
    // Recopie unique des notes de structure existantes (idempotent : ne recopie
    // pas si des entrées de structure existent déjà dans historique).
    $deja = (int) $pdo->query("SELECT COUNT(*) FROM historique WHERE entite_type = 'structure'")->fetchColumn();
    if ($deja === 0) {
        $pdo->exec("
            INSERT INTO historique (entite_type, entite_id, type, contenu, utilisateur_id, cree_le)
            SELECT 'structure', structure_id,
                   CASE WHEN est_contact = 1 THEN 'mailing' ELSE 'note' END,
                   contenu, utilisateur_id, cree_le
            FROM structure_notes
        ");
    }
}

// Migration 53 : cache de géocodage pour la vue carte des lieux. Une entrée
// par couple (ville, pays) — pas par lieu : plusieurs lieux partagent
// généralement la même ville, et on ne veut interroger le service de
// géocodage (Nominatim/OSM) qu'une seule fois par ville, jamais à l'affichage.
// Voir lib/geocodage.php.
function migration_53(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS lieux_geocodage (
            cle       TEXT PRIMARY KEY,
            ville     TEXT NOT NULL DEFAULT '',
            pays      TEXT NOT NULL DEFAULT '',
            latitude  REAL,
            longitude REAL,
            statut    TEXT NOT NULL DEFAULT '',
            maj_le    TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");
}

// Migration 54 : grande région sur les événements (parité avec structures/lieux),
// déduite du département/canton — voir grande_region_deduite() (lib/helpers.php).
function migration_54(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(evenements)')->fetchAll(), 'name');
    if (!in_array('grande_region', $cols, true)) {
        $pdo->exec("ALTER TABLE evenements ADD COLUMN grande_region TEXT NOT NULL DEFAULT ''");
    }
}

// Migration 55 : marquage rapide (flag) sur structures et lieux — '' (aucun),
// 'star' ou 'heart', voir flag_toggle_html() (lib/helpers.php) et
// route_lieu_flag()/route_structure_flag().
function migration_55(PDO $pdo): void
{
    foreach (['structures', 'lieux'] as $table) {
        $cols = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');
        if (!in_array('flag', $cols, true)) {
            $pdo->exec("ALTER TABLE $table ADD COLUMN flag TEXT NOT NULL DEFAULT ''");
        }
    }
}

// Migration 56 : renomme « region » (canton/département) en
// « departement_canton » sur evenements/structures/lieux — nom sans
// ambiguïté avec grande_region (Romandie, Normandie…). ALTER TABLE ...
// RENAME COLUMN est sûr ici (contrairement à RENAME TABLE, voir le gotcha
// documenté plus haut dans ce fichier) : aucune FK ne référence `region`,
// pas de vue/trigger dans le schéma — déjà utilisé sans souci par
// migration_35 (debiteur_id → structure_id).
function migration_56(PDO $pdo): void
{
    foreach (['evenements', 'structures', 'lieux'] as $table) {
        $cols = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');
        if (in_array('region', $cols, true) && !in_array('departement_canton', $cols, true)) {
            $pdo->exec("ALTER TABLE $table RENAME COLUMN region TO departement_canton");
        }
    }
}

// Migration 57 : le département/canton entre dans la clé de cache du
// géocodage (voir geocodage_cle(), lib/geocodage.php) — indispensable pour
// distinguer les villes homonymes qu'un couple (ville, pays) seul ne peut
// pas départager (ex. plusieurs « Bonneville » en France selon le
// département). Le format de la clé change : les entrées existantes
// (indexées sur l'ancien format ville|pays) ne seront plus jamais relues,
// on vide donc le cache plutôt que de laisser des lignes mortes — un simple
// reclic sur « Géocoder » régénère le cache avec la désambiguïsation.
function migration_57(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(lieux_geocodage)')->fetchAll(), 'name');
    if (!in_array('departement_canton', $cols, true)) {
        $pdo->exec("ALTER TABLE lieux_geocodage ADD COLUMN departement_canton TEXT NOT NULL DEFAULT ''");
        $pdo->exec('DELETE FROM lieux_geocodage');
    }
}

// Migration 58 : un événement peut être rattaché à plusieurs lieux et
// plusieurs organisateurs (jusqu'ici : un seul de chaque, evenements.lieu_id/
// organisateur_structure_id) — voir lib/routes_evenements.php
// route_evenement_organisation(). Les deux colonnes existantes sont
// conservées comme miroir du premier lieu/organisateur lié (MIN(id) dans la
// table de jointure), pour que tout le code qui les lit déjà (fiche lieu/
// structure « événements liés », pré-remplissage facture, export SUISA,
// outil de rattrapage Incohérences) continue de fonctionner sans changement.
function migration_58(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS evenement_lieux (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            evenement_id INTEGER NOT NULL REFERENCES evenements(id) ON DELETE CASCADE,
            lieu_id      INTEGER NOT NULL REFERENCES lieux(id) ON DELETE CASCADE,
            UNIQUE(evenement_id, lieu_id)
        );
        CREATE TABLE IF NOT EXISTS evenement_organisateurs (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            evenement_id INTEGER NOT NULL REFERENCES evenements(id) ON DELETE CASCADE,
            structure_id INTEGER NOT NULL REFERENCES structures(id) ON DELETE CASCADE,
            UNIQUE(evenement_id, structure_id)
        );
        CREATE INDEX IF NOT EXISTS idx_evenement_lieux_evenement ON evenement_lieux(evenement_id);
        CREATE INDEX IF NOT EXISTS idx_evenement_organisateurs_evenement ON evenement_organisateurs(evenement_id);
    ");

    $insL = $pdo->prepare('INSERT OR IGNORE INTO evenement_lieux (evenement_id, lieu_id) VALUES (?, ?)');
    foreach ($pdo->query('SELECT id, lieu_id FROM evenements WHERE lieu_id IS NOT NULL')->fetchAll() as $r) {
        $insL->execute([(int) $r['id'], (int) $r['lieu_id']]);
    }
    $insO = $pdo->prepare('INSERT OR IGNORE INTO evenement_organisateurs (evenement_id, structure_id) VALUES (?, ?)');
    foreach ($pdo->query('SELECT id, organisateur_structure_id FROM evenements WHERE organisateur_structure_id IS NOT NULL')->fetchAll() as $r) {
        $insO->execute([(int) $r['id'], (int) $r['organisateur_structure_id']]);
    }
}

// Migration 59 : fusion lieux → structures — étape 1/N, additive uniquement
// (aucune donnée de `lieux` déplacée ni supprimée ici ; voir le script CLI de
// fusion pour la suite). Constat qui motive la fusion : dans la base réelle,
// 99,6 % des lieux liés à une seule structure (structure_lieux) partagent le
// même nom ET la même ville que cette structure — un lieu n'est presque
// jamais une entité distincte de son organisateur, juste sa fiche dupliquée
// par l'auto-création de l'import (structure_lier_lieu_importe()). Le seul
// vrai besoin many-to-many observé (une structure organisant plusieurs
// lieux/festivals distincts) se modélise avec un simple lien auto-référencé
// structure↔structure, pas deux tables séparées.
//   - structure_categories.est_booking : une sous-catégorie « booking » décrit
//     un lieu où on peut faire des concerts (Salle, Festival, Théâtre…), même
//     esprit que est_organisateur. Une seule taxonomie (pas de type_lieu
//     séparé) : on marque les sous-catégories existantes plutôt que d'ouvrir
//     un second axe de classification.
//   - Colonnes touring sur structures qui n'ont pas d'équivalent existant
//     (departement_canton/adresse_pays/grande_region/notes/actif/site_web/flag
//     ont déjà leur colonne, ville → adresse_localite) : jauge_min/jauge_max
//     (capacité), mois_debut/mois_fin (période de programmation),
//     mois_evenement_debut/mois_evenement_fin (mois où l'événement/festival a
//     lieu), dernier_concert_le (« dernier concert ou diffusion » — champ
//     général, pas réservé aux lieux : une structure media/radio peut aussi
//     l'utiliser pour dire qu'elle a déjà diffusé un titre).
//   - structure_organisateurs : many-to-many auto-référencé (structure_id =
//     la structure organisée, organisateur_id = celle qui l'organise),
//     remplace à terme structure_lieux (dont le seul champ, `role`, n'a
//     jamais été renseigné en pratique — aucune perte).
function migration_59(PDO $pdo): void
{
    $colsCat = array_column($pdo->query('PRAGMA table_info(structure_categories)')->fetchAll(), 'name');
    if (!in_array('est_booking', $colsCat, true)) {
        $pdo->exec('ALTER TABLE structure_categories ADD COLUMN est_booking INTEGER NOT NULL DEFAULT 0');
    }

    $colsStruct = array_column($pdo->query('PRAGMA table_info(structures)')->fetchAll(), 'name');
    $ajouts = [
        'mois_debut'           => 'INTEGER',
        'mois_fin'             => 'INTEGER',
        'mois_evenement_debut' => 'INTEGER',
        'mois_evenement_fin'   => 'INTEGER',
        'jauge_min'            => 'INTEGER',
        'jauge_max'            => 'INTEGER',
        'dernier_concert_le'   => "TEXT NOT NULL DEFAULT ''",
    ];
    foreach ($ajouts as $col => $ddl) {
        if (!in_array($col, $colsStruct, true)) {
            $pdo->exec("ALTER TABLE structures ADD COLUMN $col $ddl");
        }
    }

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS structure_organisateurs (
            structure_id    INTEGER NOT NULL REFERENCES structures(id) ON DELETE CASCADE,
            organisateur_id INTEGER NOT NULL REFERENCES structures(id) ON DELETE CASCADE,
            PRIMARY KEY (structure_id, organisateur_id),
            CHECK (structure_id <> organisateur_id)
        )
    ');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_structure_organisateurs_organisateur ON structure_organisateurs(organisateur_id)');

    // Seed taxonomie « booking », sous le nœud racine « Organisateur » :
    //   - 7 sous-catégories déjà existantes correspondent mot pour mot à un
    //     intitulé de lieu_categories (Centre culturel, Festival, MJC,
    //     Médiathèque, SMAC, Salle de concert, Théâtre) → marquées est_booking ;
    //   - 4 autres sous-catégories déjà existantes décrivent elles aussi sans
    //     ambiguïté un lieu, bien qu'absentes de lieu_categories (Salle
    //     municipale, Club, Scène ouverte, Lieu de création — vérifié : les
    //     lieux liés aux structures portant ces sous-catégories ont le même
    //     type) → marquées aussi ;
    //   - les 6 intitulés de lieu_categories sans équivalent (Salle, Salle
    //     communale, Salle de location, Café-concert, Café associatif, Saison
    //     culturelle) sont créés.
    // Les autres sous-catégories organisateur (Association, Service culturel,
    // Production, Autres, Chant'Appart, Programmation, Direction, Projet de
    // café, Programmation Radio, Radio…) décrivent la nature de la structure,
    // pas un lieu : volontairement non marquées — les lieux qui leur sont
    // liés passeront par la revue manuelle du script de fusion (Phase 2).
    $racineId = (int) $pdo->query('SELECT id FROM structure_categories WHERE est_organisateur = 1 ORDER BY ordre LIMIT 1')->fetchColumn();
    if ($racineId) {
        $nomsExistants = [
            'Centre culturel', 'Festival', 'MJC', 'Médiathèque', 'SMAC', 'Salle de concert', 'Théâtre',
            'Salle municipale', 'Club', 'Scène ouverte', 'Lieu de création',
        ];
        $maj = $pdo->prepare('UPDATE structure_categories SET est_booking = 1 WHERE parent_id = ? AND nom = ? COLLATE NOCASE');
        foreach ($nomsExistants as $nom) {
            $maj->execute([$racineId, $nom]);
        }

        $stmtOrdre = $pdo->prepare('SELECT COALESCE(MAX(ordre), 0) FROM structure_categories WHERE parent_id = ?');
        $stmtOrdre->execute([$racineId]);
        $ordre = (int) $stmtOrdre->fetchColumn();
        $nomsNouveaux = ['Salle', 'Salle communale', 'Salle de location', 'Café-concert', 'Café associatif', 'Saison culturelle'];
        $ins = $pdo->prepare('INSERT OR IGNORE INTO structure_categories (nom, parent_id, est_organisateur, est_booking, ordre) VALUES (?, ?, 0, 1, ?)');
        foreach ($nomsNouveaux as $nom) {
            $ordre++;
            $ins->execute([$nom, $racineId, $ordre]);
        }
    }
}

// Migration 60 : fusion lieux → structures — étape 2/N. evenements.lieu_id et
// evenement_lieux.lieu_id référencaient lieux(id) ; ils référencent désormais
// structures(id), avec les valeurs stockées traduites via structure_lieux
// (MIN(structure_id) si plusieurs structures liées à un même lieu — cas
// 'manuel' de la fusion Phase 2, laissés de côté par le script d'enrichissement,
// voir lib/dev.php fusion_lieux_analyser()). `lieux`/`structure_lieux` restent
// en place à ce stade (rien n'est supprimé ici) : la suppression attend que
// tout le code applicatif ait basculé sur `structures` (voir le fil de
// discussion). Recréation de table (nom temporaire, copie, DROP puis RENAME —
// jamais RENAME vers un nom temporaire, voir le commentaire de migration_21/
// CLAUDE.md) : 6 tables référencent evenements(id) par ailleurs
// (evenement_employes, evenement_fiches, factures.evenement_id,
// fiche_lignes.evenement_id, evenement_lieux, evenement_organisateurs) — leurs
// clauses REFERENCES restent valides puisque la table d'origine est DROP puis
// remplacée sous le MÊME nom, jamais renommée.
function migration_60(PDO $pdo): void
{
    $dejaFait = false;
    foreach ($pdo->query('PRAGMA foreign_key_list(evenements)')->fetchAll() as $fk) {
        if ($fk['from'] === 'lieu_id' && $fk['table'] === 'structures') {
            $dejaFait = true;
            break;
        }
    }
    if ($dejaFait) {
        return;
    }

    $pdo->exec('PRAGMA foreign_keys = OFF');

    // lieu_id → structure_id : le premier structure_id lié (MIN), pour les
    // lieux liés à plusieurs structures (cas 'manuel' non résolu).
    $mapping = [];
    foreach ($pdo->query('SELECT lieu_id, MIN(structure_id) AS sid FROM structure_lieux GROUP BY lieu_id') as $r) {
        $mapping[(int) $r['lieu_id']] = (int) $r['sid'];
    }

    // --- evenements ---
    $pdo->exec("
        CREATE TABLE evenements_v60 (
            id                         INTEGER PRIMARY KEY AUTOINCREMENT,
            spectacle_id               INTEGER REFERENCES spectacles(id) ON DELETE SET NULL,
            date                       TEXT NOT NULL,
            statut                     TEXT NOT NULL DEFAULT 'option',
            visibilite                 TEXT NOT NULL DEFAULT 'non_repertorie',
            ville                      TEXT NOT NULL DEFAULT '',
            salle                      TEXT NOT NULL DEFAULT '',
            festival                   TEXT NOT NULL DEFAULT '',
            lien_infos                 TEXT NOT NULL DEFAULT '',
            remarques                  TEXT NOT NULL DEFAULT '',
            suisa_applicable           INTEGER NOT NULL DEFAULT 1,
            suisa_envoye_a             TEXT NOT NULL DEFAULT '',
            suisa_envoye_le            TEXT NOT NULL DEFAULT '',
            suisa_decompte_le          TEXT NOT NULL DEFAULT '',
            cree_le                    TEXT NOT NULL DEFAULT (datetime('now')),
            departement_canton         TEXT NOT NULL DEFAULT '',
            lien_texte                 TEXT NOT NULL DEFAULT '',
            pays                       TEXT NOT NULL DEFAULT '',
            axe_analytique_id_defaut   INTEGER REFERENCES axes_analytiques(id),
            organisateur_structure_id  INTEGER REFERENCES structures(id),
            production_externe         INTEGER NOT NULL DEFAULT 0,
            lieu_id                    INTEGER REFERENCES structures(id) ON DELETE SET NULL,
            grande_region              TEXT NOT NULL DEFAULT ''
        )
    ");
    $colsSansLieu = [
        'id', 'spectacle_id', 'date', 'statut', 'visibilite', 'ville', 'salle', 'festival', 'lien_infos', 'remarques',
        'suisa_applicable', 'suisa_envoye_a', 'suisa_envoye_le', 'suisa_decompte_le', 'cree_le', 'departement_canton',
        'lien_texte', 'pays', 'axe_analytique_id_defaut', 'organisateur_structure_id', 'production_externe', 'grande_region',
    ];
    $stmtIns = $pdo->prepare(
        'INSERT INTO evenements_v60 (' . implode(',', $colsSansLieu) . ',lieu_id) VALUES ('
        . implode(',', array_fill(0, count($colsSansLieu) + 1, '?')) . ')'
    );
    foreach ($pdo->query('SELECT * FROM evenements')->fetchAll() as $row) {
        $vals = [];
        foreach ($colsSansLieu as $c) {
            $vals[] = $row[$c];
        }
        $vals[] = $row['lieu_id'] !== null ? ($mapping[(int) $row['lieu_id']] ?? null) : null;
        $stmtIns->execute($vals);
    }
    $pdo->exec('DROP TABLE evenements');
    $pdo->exec('ALTER TABLE evenements_v60 RENAME TO evenements');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_evenements_date ON evenements(date)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_evenements_spectacle ON evenements(spectacle_id)');

    // --- evenement_lieux ---
    $pdo->exec('
        CREATE TABLE evenement_lieux_v60 (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            evenement_id INTEGER NOT NULL REFERENCES evenements(id) ON DELETE CASCADE,
            lieu_id      INTEGER NOT NULL REFERENCES structures(id) ON DELETE CASCADE,
            UNIQUE(evenement_id, lieu_id)
        )
    ');
    $insEL = $pdo->prepare('INSERT OR IGNORE INTO evenement_lieux_v60 (evenement_id, lieu_id) VALUES (?, ?)');
    foreach ($pdo->query('SELECT evenement_id, lieu_id FROM evenement_lieux')->fetchAll() as $r) {
        $sid = $mapping[(int) $r['lieu_id']] ?? null;
        if ($sid !== null) {
            $insEL->execute([(int) $r['evenement_id'], $sid]);
        }
    }
    $pdo->exec('DROP TABLE evenement_lieux');
    $pdo->exec('ALTER TABLE evenement_lieux_v60 RENAME TO evenement_lieux');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_evenement_lieux_evenement ON evenement_lieux(evenement_id)');

    $pdo->exec('PRAGMA foreign_keys = ON');
    $casse = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
    if ($casse) {
        throw new RuntimeException('migration_60 : clé étrangère cassée après migration — ' . json_encode($casse));
    }
}

// Migration 61 : fusion lieux → structures — étape 3/3 (finale). Toutes les
// données récupérables ont déjà été reprises : les 1906 lieux « auto »
// enrichis sur leur structure (script scripts/fusion_lieux.php, Phase 2), les
// FK événements repointées sur structures(id) (migration_60). Les ~22 lieux
// restés non résolus (nom/ville différents de leur structure, ou liés à
// plusieurs structures — doublons visibles à l'usage) sont perdus avec la
// suppression de la table, décision actée avec l'utilisateur (base de dev, pas
// de traitement automatique pour un si petit nombre de cas ambigus). Plus
// aucun code applicatif ne lit/écrit lieux/structure_lieux/lieu_categories.
// Sauvegarde de sécurité avant la suppression, par prudence (voir
// sauvegarder_base(), même geste que les scripts de fusion en masse).
function migration_61(PDO $pdo): void
{
    $tables = array_column($pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(), 'name');
    if (!in_array('lieux', $tables, true)) {
        return; // déjà migré
    }
    sauvegarder_base('avant_suppression_lieux');
    $pdo->exec('DROP TABLE IF EXISTS structure_lieux');
    $pdo->exec('DROP TABLE IF EXISTS lieux');
    $pdo->exec('DROP TABLE IF EXISTS lieu_categories');
}

// Migration 62 : couleur (hex, "" = couleur par défaut du badge) sur les
// étiquettes de structure (?p=parametres_tags).
function migration_62(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(structure_tags)')->fetchAll(), 'name');
    if (!in_array('couleur', $cols, true)) {
        $pdo->exec("ALTER TABLE structure_tags ADD COLUMN couleur TEXT NOT NULL DEFAULT ''");
    }
}

// Remplace structures.actif (bool) + structures.desinscrit (bool) par un
// unique statut texte (voir STRUCTURE_STATUTS, lib/booking.php) : « actif »,
// « ne_pas_contacter » (active mais désinscrite du mailing), « inactif ».
// « contact_privilegie » est un 4e état PRIORITAIRE ajouté par ce même
// changement (bouton dédié dans structure_statut_toggle_html()) mais jamais
// déduit automatiquement d'une valeur existante — aucune fiche migrée ne
// peut y arriver seule, seule une action manuelle l'attribue ensuite.
// DROP COLUMN direct (pas de FK ni d'index sur ces colonnes : pas le cas à
// risque documenté en tête de fichier, pas de RENAME de table) — mais
// nécessite SQLite ≥ 3.35 (2021), pas garanti sur tous les hébergements
// mutualisés (constaté en prod : « near "DROP": syntax error », version plus
// ancienne). Best-effort, jamais bloquant : si indisponible, actif/desinscrit
// restent en base, inertes (plus aucun code applicatif ne les lit/écrit) —
// sans le try/catch, l'exception empêchait PRAGMA user_version d'avancer et
// donc TOUTE requête HTTP faisait planter db() (voir index.php:47).
function migration_63(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(structures)')->fetchAll(), 'name');
    if (!in_array('statut', $cols, true)) {
        $pdo->exec("ALTER TABLE structures ADD COLUMN statut TEXT NOT NULL DEFAULT 'actif'");
        $pdo->exec(
            "UPDATE structures SET statut = CASE
                WHEN actif = 1 AND desinscrit = 0 THEN 'actif'
                WHEN actif = 1 AND desinscrit = 1 THEN 'ne_pas_contacter'
                ELSE 'inactif'
            END"
        );
    }
    if (in_array('actif', $cols, true)) {
        try { $pdo->exec('ALTER TABLE structures DROP COLUMN actif'); } catch (\Throwable $e) { /* SQLite < 3.35 */ }
    }
    if (in_array('desinscrit', $cols, true)) {
        try { $pdo->exec('ALTER TABLE structures DROP COLUMN desinscrit'); } catch (\Throwable $e) { /* SQLite < 3.35 */ }
    }
}

// Retire la distinction « catégorie organisateur » (structure_categories.
// est_organisateur) : ne pilotait plus que l'auto-groupement organisateur↔lieu
// de l'import CSV (structures_grouper(), retiré du même coup — voir
// lib/booking.php) et le choix de la catégorie de repli
// (structure_categorie_par_defaut(), désormais la première racine tout
// court). DROP COLUMN best-effort (voir migration_63 pour le pourquoi du
// try/catch — pas garanti sur tous les hébergements).
function migration_64(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(structure_categories)')->fetchAll(), 'name');
    if (in_array('est_organisateur', $cols, true)) {
        try { $pdo->exec('ALTER TABLE structure_categories DROP COLUMN est_organisateur'); } catch (\Throwable $e) { /* SQLite < 3.35 */ }
    }
}

// geocodage_cle() (lib/geocodage.php) replie désormais les accents sur ville/
// pays (« Chambéry »/« Chambery » → même clé) — sans cette migration, les
// lignes déjà en cache sous l'ancienne clé (accentuée) ne seraient plus
// jamais retrouvées par un lookup calculé avec la nouvelle formule : la ville
// basculerait à tort en « non géolocalisée » et disparaîtrait de la vue carte
// au lieu de simplement fusionner avec son doublon. Recalcule la clé de
// chaque ligne existante avec la même logique de repli (dupliquée ici,
// volontairement autonome — pas de dépendance vers lib/booking.php depuis une
// migration) ; si la nouvelle clé est déjà prise par une autre ligne (ex. les
// deux variantes accentuée/non accentuée étaient toutes les deux en cache),
// la ligne redondante est supprimée plutôt que de violer la clé primaire.
function migration_65(PDO $pdo): void
{
    $repli = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae', 'ÿ' => 'y',
    ];
    $plie = fn (string $s): string => strtr(mb_strtolower(trim($s), 'UTF-8'), $repli);

    $lignes = $pdo->query('SELECT cle, ville, departement_canton, pays FROM lieux_geocodage')->fetchAll();
    $existe = $pdo->prepare('SELECT 1 FROM lieux_geocodage WHERE cle = ?');
    $supprime = $pdo->prepare('DELETE FROM lieux_geocodage WHERE cle = ?');
    $renomme = $pdo->prepare('UPDATE lieux_geocodage SET cle = ? WHERE cle = ?');
    foreach ($lignes as $l) {
        $nouvelleCle = $plie((string) $l['ville']) . '|' . mb_strtolower(trim((string) $l['departement_canton']), 'UTF-8')
            . '|' . $plie((string) $l['pays']);
        if ($nouvelleCle === $l['cle']) {
            continue;
        }
        $existe->execute([$nouvelleCle]);
        if ($existe->fetchColumn()) {
            $supprime->execute([$l['cle']]);
        } else {
            $renomme->execute([$nouvelleCle, $l['cle']]);
        }
    }
}

// Fusionne evenement_lieux + evenement_organisateurs (migration_58/60) en une
// seule table evenement_structures : ?p=evenement ne distingue plus « lieu »
// et « organisateur » parmi les structures liées à un événement — juste des
// structures liées, dont une seule peut être marquée « à facturer »
// (est_facturation), utilisée par le pré-remplissage facture et l'export CSV
// SUISA (qui lisaient jusqu'ici evenements.organisateur_structure_id). Le
// backfill de est_facturation reprend exactement ce que ces deux lectures
// voyaient déjà (la structure alors pointée par organisateur_structure_id),
// donc aucun changement de comportement pour elles à l'issue de la migration.
// evenements.lieu_id disparaît (plus de rôle « lieu » séparé à mettre en
// miroir) ; organisateur_structure_id reste, mais devient un miroir de la
// structure est_facturation=1 (voir evenement_resynchroniser_miroirs()).
function migration_66(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS evenement_structures (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        evenement_id    INTEGER NOT NULL REFERENCES evenements(id) ON DELETE CASCADE,
        structure_id    INTEGER NOT NULL REFERENCES structures(id) ON DELETE CASCADE,
        est_facturation INTEGER NOT NULL DEFAULT 0,
        UNIQUE(evenement_id, structure_id)
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_evenement_structures_evenement ON evenement_structures(evenement_id)');

    $tables = array_column($pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(), 'name');
    if (in_array('evenement_lieux', $tables, true)) {
        $pdo->exec('INSERT OR IGNORE INTO evenement_structures (evenement_id, structure_id)
                     SELECT evenement_id, lieu_id FROM evenement_lieux');
    }
    if (in_array('evenement_organisateurs', $tables, true)) {
        $pdo->exec('INSERT OR IGNORE INTO evenement_structures (evenement_id, structure_id)
                     SELECT evenement_id, structure_id FROM evenement_organisateurs');
    }
    // Sous-requête corrélée plutôt qu'une comparaison multi-colonnes (a, b) IN
    // (SELECT ...) : équivalente ici (une seule ligne par evenement_id est
    // possible côté evenements) mais ne dépend pas du support des « row
    // values » dans IN, moins universel selon les versions de SQLite.
    $pdo->exec("UPDATE evenement_structures SET est_facturation = 1
                WHERE structure_id = (
                    SELECT organisateur_structure_id FROM evenements WHERE evenements.id = evenement_structures.evenement_id
                )");

    $pdo->exec('DROP TABLE IF EXISTS evenement_lieux');
    $pdo->exec('DROP TABLE IF EXISTS evenement_organisateurs');

    $cols = array_column($pdo->query('PRAGMA table_info(evenements)')->fetchAll(), 'name');
    if (in_array('lieu_id', $cols, true)) {
        try { $pdo->exec('ALTER TABLE evenements DROP COLUMN lieu_id'); } catch (\Throwable $e) { /* SQLite < 3.35 */ }
    }
}

// Migration 44 : le champ « region » existant devient le « département / canton » ;
// on ajoute une « grande_region » distincte (Normandie, Romandie, Acadie…) sur les
// structures et les lieux, et une date de « dernier concert ou diffusion » sur les
// lieux (booking). Voir SPEC_BOOKING.md.
function migration_44(PDO $pdo): void
{
    $colsStructures = array_column($pdo->query('PRAGMA table_info(structures)')->fetchAll(), 'name');
    if (!in_array('grande_region', $colsStructures, true)) {
        $pdo->exec("ALTER TABLE structures ADD COLUMN grande_region TEXT NOT NULL DEFAULT ''");
    }
    $colsLieux = array_column($pdo->query('PRAGMA table_info(lieux)')->fetchAll(), 'name');
    if (!in_array('grande_region', $colsLieux, true)) {
        $pdo->exec("ALTER TABLE lieux ADD COLUMN grande_region TEXT NOT NULL DEFAULT ''");
    }
    if (!in_array('dernier_concert_le', $colsLieux, true)) {
        $pdo->exec("ALTER TABLE lieux ADD COLUMN dernier_concert_le TEXT NOT NULL DEFAULT ''");
    }
}

function seed_parametres(PDO $pdo): void
{
    $defauts = [
        'employeur_nom'                 => '',
        'employeur_rue'                 => '',
        'employeur_npa'                 => '',
        'employeur_pays'                => 'Suisse',
        'employeur_email_contact'       => '',
        'employeur_email_expediteur'    => '',
        'employeur_telephone'           => '',
        'employeur_heures_hebdo'        => '40.00',
        'employeur_contact_nom'         => '',
        'employeur_contact_tel'         => '',
        'employeur_logo_clair'          => '', // logo sur fond clair (auth, fiches, e-mail)
        'employeur_logo_sombre'         => '', // logo sur fond sombre (barre latérale)
        'employeur_couleur_principale'  => '#6d4ade', // couleur d'accent ; teintes dérivées via couleurs_derivees()
        'employeur_couleur_evidence'    => '#2563eb', // couleur de mise en évidence (boutons principaux, sommes de brut, liens, tags) ; teintes dérivées via couleurs_derivees()
    ];
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO parametres (cle, valeur) VALUES (?, ?)');
    foreach ($defauts as $cle => $valeur) {
        $stmt->execute([$cle, $valeur]);
    }
}

function param(string $cle, $defaut = null)
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT cle, valeur FROM parametres') as $row) {
            $cache[$row['cle']] = $row['valeur'];
        }
    }
    return $cache[$cle] ?? $defaut;
}
