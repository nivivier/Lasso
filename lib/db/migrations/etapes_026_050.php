<?php
// Migrations 26 à 50, dans l'ordre — chargé par lib/db/migrations.php,
// jamais directement. Son en-tête dit la marche à suivre et les pièges.

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
// voir route_evenement_production_externe_enregistrer().
function migration_33(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(evenements)')->fetchAll(), 'name');
    if (!in_array('production_externe', $cols, true)) {
        $pdo->exec('ALTER TABLE evenements ADD COLUMN production_externe INTEGER NOT NULL DEFAULT 0');
    }
}

// Droits par module (lecture/écriture) par utilisateur — voir docs/SPEC_PERMISSIONS.md
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
// (France) pour normaliser structures.region à l'import. Voir docs/SPEC_BOOKING.md.
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
    // CSV quand seul un code département est fourni — voir docs/SPEC_BOOKING.md §4/§9.
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
// un festival — voir docs/SPEC_BOOKING.md et l'analyse du carnet d'adresses fourni.
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

// Migration 44 : le champ « region » existant devient le « département / canton » ;
// on ajoute une « grande_region » distincte (Normandie, Romandie, Acadie…) sur les
// structures et les lieux, et une date de « dernier concert ou diffusion » sur les
// lieux (booking). Voir docs/SPEC_BOOKING.md.
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
