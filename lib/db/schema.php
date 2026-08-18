<?php
// Création du schéma initial et données par défaut (« seeds »).
//
// Extrait de lib/db.php, devenu un fourre-tout de 2 449 lignes mêlant connexion,
// schéma, migrations et valeurs par défaut. Chargé par lib/db.php, jamais
// directement : db() reste le point d'entrée unique.
//
// init_schema() est rejouée à CHAQUE ouverture de la base : tout y est en
// CREATE TABLE IF NOT EXISTS, et les seed_*() vérifient d'abord que leur table
// est vide. Ne jamais y écrire une instruction qui ne supporterait pas d'être
// exécutée deux fois.

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
