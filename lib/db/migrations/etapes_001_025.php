<?php
// Migrations 1 à 25, dans l'ordre — chargé par lib/db/migrations.php,
// jamais directement. Son en-tête dit la marche à suivre et les pièges.

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
    // __DIR__ vaut lib/db/ depuis l'extraction de ce fichier : le chemin remonte
    // donc d'un cran vers lib/. Seul require relatif de toute la chaîne de
    // migrations — la suite de tests l'a signalé immédiatement après le
    // déplacement, ce qui est précisément son objet.
    require_once __DIR__ . '/../../compta.php'; // extraire_tiers()
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

function migration_17(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(fiche_lignes)')->fetchAll(), 'name');
    if (!in_array('axe_analytique_id', $cols, true)) {
        $pdo->exec('ALTER TABLE fiche_lignes ADD COLUMN axe_analytique_id INTEGER REFERENCES axes_analytiques(id)');
    }
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
// (facture créée depuis un événement, cf. docs/SPEC_EVENEMENTS.md).
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
