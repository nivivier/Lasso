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
        SQL);

    run_migrations($pdo);
    seed_parametres($pdo);
    migrate_taux($pdo);
    seed_unites($pdo);
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
