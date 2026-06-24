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

    init_schema($pdo);
    return $pdo;
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
