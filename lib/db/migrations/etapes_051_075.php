<?php
// Migrations 51 à 75, dans l'ordre — chargé par lib/db/migrations.php,
// jamais directement. Son en-tête dit la marche à suivre et les pièges.

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
    // modifie_par / modifie_le arrivent par migration_96 : cette création-ci est
    // celle d'une base d'alors, la chaîne doit la rejouer telle quelle.
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
// route_evenement_organisation_enregistrer(). Les deux colonnes existantes sont
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
// étiquettes de structure (?p=tags).
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

// Retire structures.type (organisation/particulier, forme juridique pour la
// facturation, migration_18) : jamais utilisé en pratique, la catégorie
// (categorie/sous_categorie) suffit à distinguer les structures. best-effort
// (DROP COLUMN nécessite SQLite >= 3.35, pas garanti sur tous les
// hébergements — voir migration_63/64 pour le même motif).
function migration_67(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(structures)')->fetchAll(), 'name');
    if (in_array('type', $cols, true)) {
        try { $pdo->exec('ALTER TABLE structures DROP COLUMN type'); } catch (\Throwable $e) { /* SQLite < 3.35 */ }
    }
}

// Migration 68 : index sur login_attempts. La table n'en avait aucun — les deux
// compteurs anti-force-brute (login_failures_recent() par IP,
// login_failures_recent_email() par compte) faisaient donc un balayage complet.
// La purge opportuniste de login_record_failure() garde la table minuscule, mais
// l'index est gratuit et rend les deux lectures indépendantes de sa taille.
function migration_68(PDO $pdo): void
{
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_login_attempts_ip    ON login_attempts(ip, cree_le)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_login_attempts_email ON login_attempts(email, cree_le)');
}

// Migration 69 : table des paires de structures écartées de la détection des
// doublons POTENTIELS (?p=incoherences, noms voisins dans le même canton). Sans mémoire,
// l'outil reproposerait indéfiniment les mêmes rapprochements légitimes — une
// antenne régionale et sa maison mère, deux salles d'un même lieu.
// L'unité mémorisée est la PAIRE, pas la fiche : écarter « A ≈ B » ne doit rien
// dire de « A ≈ C ». Invariant tenu côté PHP : structure_a < structure_b, sinon
// la même paire s'enregistrerait deux fois dans les deux sens.
// ON DELETE CASCADE : une structure supprimée emporte ses exclusions, qui ne
// désigneraient plus rien (PRAGMA foreign_keys = ON, voir lib/db.php).
function migration_69(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS structure_doublons_ignores (
            structure_a INTEGER NOT NULL REFERENCES structures(id) ON DELETE CASCADE,
            structure_b INTEGER NOT NULL REFERENCES structures(id) ON DELETE CASCADE,
            cree_le     TEXT NOT NULL DEFAULT (datetime('now')),
            PRIMARY KEY (structure_a, structure_b)
        )
    ");
    // La lecture se fait toujours par la clé primaire (paire complète) ; cet
    // index sert au sens inverse, l'affichage des exclusions d'une structure.
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_doublons_ignores_b ON structure_doublons_ignores(structure_b)');
}

// Migration 70 : VIDE, à dessein. Elle ajoutait une colonne texte « expediteur »
// aux trois tables de mailing, avant qu'un expéditeur ne devienne une BOÎTE avec
// son propre serveur (migration 71, qui supprime cette colonne et la remplace
// par « expediteur_id »). Les bases qui l'ont appliquée ont déjà été rattrapées
// par la 71 ; la vider évite aux bases neuves de créer une colonne pour
// l'effacer trois lignes plus loin. Le numéro reste occupé : PRAGMA
// user_version ne se renumérote pas.
function migration_70(PDO $pdo): void
{
}

// Migration 71 : un expéditeur de mailing n'est pas qu'une adresse, c'est une
// BOÎTE — « diffusion@ » et « production@ » peuvent vivre sur deux serveurs
// distincts, avec chacun ses identifiants. D'où une table plutôt qu'un
// paramètre texte (migration_70, qui n'aura vécu qu'un temps), et une référence
// par identifiant sur les modèles, les campagnes et la file d'attente : renommer
// une boîte ou changer son mot de passe ne doit pas défaire les campagnes déjà
// en file.
// Les champs SMTP laissés vides retombent sur le profil booking, puis sur le
// profil général (smtp_config_booking(), lib/helpers.php) — une association qui
// n'a qu'une boîte n'a rien à ressaisir.
function migration_71(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mailing_expediteurs (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            nom         TEXT NOT NULL DEFAULT '',
            email       TEXT NOT NULL,
            smtp_host   TEXT NOT NULL DEFAULT '',
            smtp_port   TEXT NOT NULL DEFAULT '',
            smtp_secure TEXT NOT NULL DEFAULT '',
            smtp_user   TEXT NOT NULL DEFAULT '',
            smtp_pass   TEXT NOT NULL DEFAULT '',
            ordre       INTEGER NOT NULL DEFAULT 0,
            cree_le     TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");
    // Reprise de la configuration existante : le profil SMTP booking devient le
    // premier expéditeur, pour que les campagnes continuent de partir de la même
    // boîte sans intervention. Son adresse : celle d'expédition générale, seule
    // adresse que le mailing utilisait jusqu'ici.
    $vide = (int) $pdo->query('SELECT COUNT(*) FROM mailing_expediteurs')->fetchColumn() === 0;
    if ($vide) {
        $param = function (string $cle) use ($pdo): string {
            $st = $pdo->prepare('SELECT valeur FROM parametres WHERE cle = ?');
            $st->execute([$cle]);
            return trim((string) $st->fetchColumn());
        };
        $email = $param('employeur_email_expediteur') ?: $param('smtp_booking_user');
        $aConfigBooking = $param('smtp_booking_host') !== '' || $param('smtp_booking_user') !== '';
        if ($email !== '' && $aConfigBooking) {
            $pdo->prepare('INSERT INTO mailing_expediteurs (nom, email, smtp_host, smtp_port, smtp_secure, smtp_user, smtp_pass, ordre)
                           VALUES (?, ?, ?, ?, ?, ?, ?, 0)')
                ->execute(['', $email, $param('smtp_booking_host'), $param('smtp_booking_port'),
                           $param('smtp_booking_secure'), $param('smtp_booking_user'), $param('smtp_booking_pass')]);
        }
    }
    foreach (['mailing_modeles', 'mailing_campagnes', 'mailing_file_attente'] as $table) {
        $cols = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');
        if (!in_array('expediteur_id', $cols, true)) {
            $pdo->exec("ALTER TABLE $table ADD COLUMN expediteur_id INTEGER REFERENCES mailing_expediteurs(id) ON DELETE SET NULL");
        }
        // La colonne texte de migration_70 n'a plus d'objet. DROP COLUMN
        // best-effort, comme migration_63/67 (SQLite >= 3.35 seulement).
        if (in_array('expediteur', $cols, true)) {
            try { $pdo->exec("ALTER TABLE $table DROP COLUMN expediteur"); } catch (\Throwable $e) { /* SQLite < 3.35 */ }
        }
    }
}

// Migration 72 : brouillon du message écrit depuis une fiche structure (bouton
// « Contacter »). UN brouillon par structure — la clé primaire est la structure
// elle-même : on écrit à une structure, on s'interrompt, on reprend ; empiler
// plusieurs brouillons pour un même destinataire n'apporterait qu'un choix de
// plus à faire en rouvrant la fenêtre.
// Le contact et l'expéditeur sont des références : un contact supprimé ou une
// boîte d'envoi retirée ne doivent pas emporter le texte déjà écrit, seulement
// le destinataire à re-choisir (ON DELETE SET NULL).
function migration_72(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS structure_message_brouillons (
            structure_id  INTEGER PRIMARY KEY REFERENCES structures(id) ON DELETE CASCADE,
            contact_id    INTEGER REFERENCES structure_contacts(id) ON DELETE SET NULL,
            expediteur_id INTEGER REFERENCES mailing_expediteurs(id) ON DELETE SET NULL,
            sujet         TEXT NOT NULL DEFAULT '',
            corps         TEXT NOT NULL DEFAULT '',
            maj_le        TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");
}

// Migration 73 : le mailing de masse devient un sous-module du booking
// (« mailing », MODULES dans lib/modules.php). Sans reprise, deux régressions
// silencieuses le jour de la mise à jour :
//   • « modules_actifs » est une liste EXPLICITE une fois enregistrée — un
//     identifiant qui n'y figure pas est inactif, donc les pages du mailing
//     auraient disparu ;
//   • les droits sont par module (utilisateur_permissions, absence de ligne =
//     aucun accès) — chaque compte aurait perdu le mailing.
// On recopie donc l'état du booking : actif s'il l'était, et le même niveau de
// droit pour chaque compte qui en avait un.
function migration_73(PDO $pdo): void
{
    $st = $pdo->prepare('SELECT valeur FROM parametres WHERE cle = ?');
    $st->execute(['modules_actifs']);
    $val = $st->fetchColumn();
    if ($val !== false) {
        $ids = array_values(array_filter(array_map('trim', explode(',', (string) $val)), fn ($v) => $v !== ''));
        if (in_array('booking', $ids, true) && !in_array('mailing', $ids, true)) {
            $ids[] = 'mailing';
            $pdo->prepare('INSERT OR REPLACE INTO parametres (cle, valeur) VALUES (?, ?)')
                ->execute(['modules_actifs', implode(',', $ids)]);
        }
    }
    $pdo->exec(
        "INSERT OR IGNORE INTO utilisateur_permissions (utilisateur_id, module, niveau)
         SELECT utilisateur_id, 'mailing', niveau FROM utilisateur_permissions WHERE module = 'booking'"
    );
}

function migration_74(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS utilisateur_preferences (
            utilisateur_id INTEGER NOT NULL REFERENCES utilisateurs(id) ON DELETE CASCADE,
            cle            TEXT NOT NULL,
            valeur         TEXT NOT NULL DEFAULT '',
            maj_le         TEXT NOT NULL DEFAULT (datetime('now')),
            PRIMARY KEY (utilisateur_id, cle)
        )
    ");
}

// Migration 75 : les champs structurés d'un relevé camt.053 que l'import
// laissait tomber (référence QR, IBAN de la contre-partie, référence bancaire
// unique, nature de l'opération). Colonnes seulement : les écritures déjà
// importées gardent leurs valeurs vides — les réimporter n'est pas souhaitable
// (leur « hash » est inchangé, elles seraient reconnues comme doublons, ce qui
// est le comportement voulu).
function migration_75(PDO $pdo): void
{
    $cols = [];
    foreach ($pdo->query('PRAGMA table_info(ecritures)') as $row) {
        $cols[$row['name']] = true;
    }
    foreach (['reference', 'iban_tiers', 'ref_bancaire', 'nature'] as $nom) {
        if (!isset($cols[$nom])) {
            $pdo->exec("ALTER TABLE ecritures ADD COLUMN $nom TEXT NOT NULL DEFAULT ''");
        }
    }
    // Index partiel : voir schema.php pour le pourquoi du WHERE.
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_ecritures_ref_bancaire
                ON ecritures (compte_bancaire_id, ref_bancaire) WHERE ref_bancaire <> ''");
}
