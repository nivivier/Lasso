<?php
// Migrations 101 à 106, dans l'ordre — chargé par lib/db/migrations.php,
// jamais directement. Son en-tête dit la marche à suivre et les pièges.
// Une migration nouvelle s'ajoute ici, à la suite.

function migration_101(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(pieces)')->fetchAll(), 'name');
    if (!in_array('structure_id', $cols, true)) {
        $pdo->exec('ALTER TABLE pieces ADD COLUMN structure_id INTEGER REFERENCES structures(id) ON DELETE SET NULL');
    }
    if (!in_array('personne_id', $cols, true)) {
        $pdo->exec('ALTER TABLE pieces ADD COLUMN personne_id INTEGER REFERENCES personnes(id) ON DELETE SET NULL');
    }
}

function migration_102(PDO $pdo): void
{
    // Le carnet d'adresses devient un MODULE : structures, personnes, employés.
    // Rien à migrer dans les données — les tables ne bougent pas —, mais tout à
    // reprendre du côté des droits, et pour les deux mêmes régressions
    // silencieuses que migration_73 (mailing) et migration_99 (pièces) :
    //
    //   • « modules_actifs » est une liste EXPLICITE une fois enregistrée : un
    //     identifiant qui n'y figure pas est inactif. Sans cette reprise, le
    //     carnet naîtrait éteint — et avec lui les quatre modules qui le
    //     « requires » désormais, qui s'éteindraient au premier passage par
    //     ?p=modules (set_modules_actifs() retire un module dont la dépendance
    //     manque). Toute l'installation serait tombée d'un coup.
    //   • les droits sont par module, et l'absence de ligne vaut aucun accès :
    //     sans reprise, plus personne n'ouvrirait une structure ni une
    //     personne, pas même l'administrateur.
    $st = $pdo->prepare('SELECT valeur FROM parametres WHERE cle = ?');
    $st->execute(['modules_actifs']);
    $val = $st->fetchColumn();
    if ($val !== false) {
        $ids = array_values(array_filter(array_map('trim', explode(',', (string) $val)), fn ($v) => $v !== ''));
        // Le carnet s'allume si l'un de ceux qui s'en servent était allumé.
        $utilisateurs = ['facturation', 'evenements', 'booking', 'fonds'];
        if (array_intersect($utilisateurs, $ids) && !in_array('carnet', $ids, true)) {
            $ids[] = 'carnet';
            $pdo->prepare('INSERT OR REPLACE INTO parametres (cle, valeur) VALUES (?, ?)')
                ->execute(['modules_actifs', implode(',', $ids)]);
        }
    }

    // Le droit reçu est LE PLUS FORT de ceux qu'on avait sur les quatre : qui
    // pouvait créer une structure depuis le booking doit pouvoir continuer. Le
    // rabattre à « lecture » aurait cassé le geste courant sans rien dire.
    $pdo->exec(
        "INSERT OR IGNORE INTO utilisateur_permissions (utilisateur_id, module, niveau)
         SELECT utilisateur_id, 'carnet',
                CASE WHEN SUM(niveau = 'ecriture') > 0 THEN 'ecriture' ELSE 'lecture' END
         FROM utilisateur_permissions
         WHERE module IN ('facturation', 'evenements', 'booking', 'fonds')
         GROUP BY utilisateur_id"
    );
    // Les personnes vivaient sous « coeur » (docs/SPEC_PERSONNES.md) : un
    // administrateur qui n'aurait aucun des quatre modules garde son carnet.
    $pdo->exec(
        "INSERT OR IGNORE INTO utilisateur_permissions (utilisateur_id, module, niveau)
         SELECT utilisateur_id, 'carnet', niveau FROM utilisateur_permissions WHERE module = 'coeur'"
    );
}

function migration_103(PDO $pdo): void
{
    // Les suggestions de doublons de l'annuaire qu'on a écartées. Même rôle que
    // structure_doublons_ignores (migration_69) pour les structures, mais la
    // clé n'est pas une PAIRE : les personnes se groupent par adresse ou par
    // nom, et c'est ce GROUPE qu'on écarte.
    //
    // ⚠️ On mémorise la CLÉ (« l'équipe », « info@salle.ch »), pas les
    // identifiants du groupe. L'annuaire grossit à chaque import, et un groupe
    // repéré par ses membres reviendrait dès qu'une fiche de plus porte le même
    // nom — c'est-à-dire tout le temps, et précisément pour les clés qui ne
    // désignent personne (« L'équipe » en compte plus de mille). Écarter dit
    // donc « ce nom-là n'est pas un doublon », ce qui reste vrai après l'import
    // suivant.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS personne_doublons_ignores (
            motif   TEXT NOT NULL,   -- email | nom (voir personnes_doublons())
            cle     TEXT NOT NULL,   -- la valeur commune, déjà normalisée en minuscules
            cree_le TEXT NOT NULL DEFAULT (datetime('now')),
            PRIMARY KEY (motif, cle)
        )
    ");
}

function migration_104(PDO $pdo): void
{
    // Le verrou de clôture d'exercice (lib/compta.php § Clôture d'exercice).
    // Sans clôture posée, les déclencheurs ne refusent rien : la condition
    // compare à un seuil NULL. ecritures_ventilations peut manquer sur une
    // base très ancienne qui n'a jamais eu l'analytique : la table vient d'une
    // migration antérieure, mais on ne suppose rien.
    require_once __DIR__ . '/../../compta.php';
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
    foreach (compta_cloture_declencheurs_sql() as $sql) {
        if (str_contains($sql, 'ON ecritures_ventilations') && !in_array('ecritures_ventilations', $tables, true)) {
            continue;
        }
        $pdo->exec($sql);
    }
}

// Le numéro de dossier d'une demande de subvention : retiré, personne ne s'en
// servait. Le bailleur et la campagne suffisent à désigner un dossier, et un
// champ vide sur chaque ligne en dit plus long sur ce qu'on n'a pas fait que
// sur ce qu'on suit.
//
// DROP COLUMN en best-effort : il exige SQLite >= 3.35 et l'hébergeur est en
// 3.34 (docs/DECISIONS.md § Le SQLite d'un hébergement mutualisé). Si le
// retrait échoue, la colonne reste en base, inerte — plus aucun code ne la lit
// ni ne l'écrit, et son DEFAULT suffit aux INSERT qui ne la nomment pas.
// Migration 96 : une note d'historique garde l'auteur de sa DERNIÈRE
// modification, à côté de celui de sa création (utilisateur_id).
//
// Une note se corrige à plusieurs mains — une date rectifiée, un compte rendu
// complété — et l'écran ne disait que qui l'avait écrite. Deux colonnes et non
// une : « modifiée » sans dire quand ne situe rien, et la date de création ne
// peut pas servir de repère puisque c'est précisément elle qu'une modification
// peut changer.
//
// Vides par défaut : une entrée jamais modifiée n'a rien à déclarer, et c'est
// ce qui distingue « jamais touchée » de « modifiée par celui qui l'a écrite ».
// Migration 97 : un brouillon de message garde ses copies.
//
// La fenêtre « Contacter » accepte des Cc et des Cci ; sans ces deux colonnes,
// les saisir puis enregistrer le brouillon les perdait en silence — et c'est
// précisément ce qu'on ne retape pas volontiers.
// Migration 98 : un projet peut être mis de côté.
//
// Les menus de projet — assignation d'un événement, projets financés par une
// campagne — proposent TOUS les projets, à jamais : une tournée de 2019 y reste
// entre deux projets de la saison. « Inactif » ne supprime rien et ne cache
// rien de ce qui existe (un événement garde le sien, la liste des projets les
// montre tous) : il retire seulement de ce qui est PROPOSÉ par défaut, et une
// case « Actifs seulement » les rappelle à l'écran.
//
// Actif par défaut : une base existante ne change pas de comportement.
// Migration 100 : LES PERSONNES (docs/SPEC_PERSONNES.md).
//
// Une personne existait dans trois tables qui ne se parlaient pas :
// structure_contacts (~2900 lignes), employes, utilisateurs. Le musicien qui
// joue chez nous et figure dans l'annuaire de booking était deux lignes sans
// rapport ; le programmateur qui change de salle, deux contacts et un
// historique coupé en deux.
//
// structure_contacts CONFONDAIT deux choses : une personne, et son rôle dans
// une structure. C'est aussi ce qui rend la correction abordable — elle EST
// déjà la table de liaison, elle a son structure_id. On n'en sort que
// l'identité, et elle devient personne_structures, ce qu'elle a toujours été.
//
// ⚠️ LES IDENTIFIANTS SONT PRÉSERVÉS, et ce n'est pas un détail : personnes.id
// et personne_structures.id reprennent structure_contacts.id. Quatre tables
// pointaient ce contact_id, et les données restent donc justes sans la moindre
// table de correspondance. On aurait pu renuméroter — aucun mailing n'est parti
// en production, le lien de désinscription signé n'existe nulle part dans la
// nature — mais préserver ne coûte rien ici et supprime toute une classe
// d'erreurs de reprise.
//
// ⚠️ Les quatre tables qui pointaient structure_contacts doivent être
// RECONSTRUITES avant qu'on ne la supprime, sans quoi leurs clés étrangères
// désignent une table disparue (PRAGMA foreign_key_check le dirait, et
// tests/migrations_test.php le vérifie sur toute la chaîne). Reconstruction
// par x_new + copie + DROP + RENAME, jamais RENAME TO x_old puis recréation —
// voir docs/DECISIONS.md § Migrations SQLite.
// Migration 101 : le bénéficiaire d'une quittance (docs/SPEC_PIECES.md § 10).
//
// Une quittance se fait à une structure OU à une personne. Deux colonnes
// nullables plutôt qu'un couple type+id : SQLite peut alors vérifier la clé
// étrangère, et une fiche supprimée ne laisse pas une quittance pointant un
// identifiant devenu celui de quelqu'un d'autre. Avec un couple polymorphe,
// cette erreur-là est silencieuse et indétectable.
function migration_105(PDO $pdo): void
{
    // Journal d'activité (lib/journal.php). `utilisateur` garde le NOM au
    // moment du geste : un compte supprimé ne doit pas rendre ses lignes
    // anonymes — c'est précisément ce qu'on vient y chercher. Pas de clé
    // étrangère vers utilisateurs pour la même raison.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS journal_activite (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            cree_le        TEXT NOT NULL DEFAULT (datetime('now')),
            utilisateur_id INTEGER NOT NULL,
            utilisateur    TEXT NOT NULL DEFAULT '',
            route          TEXT NOT NULL,
            geste          TEXT NOT NULL DEFAULT '',
            cible_type     TEXT NOT NULL DEFAULT '',
            cible_id       INTEGER,
            issue          TEXT NOT NULL DEFAULT 'ok'   -- ok | refus
        )
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_journal_activite_date ON journal_activite(cree_le)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_journal_activite_cible ON journal_activite(cible_type, cible_id)');
}

// Les mots de passe SMTP quittent le clair : paramètres smtp_pass et
// smtp_booking_pass, et chaque boîte de mailing_expediteurs. Idempotent — une
// valeur déjà chiffrée est laissée telle quelle. La clé est créée ici si elle
// n'existe pas encore (lib/secrets.php).
function migration_106(PDO $pdo): void
{
    require_once __DIR__ . '/../../secrets.php';
    $maj = $pdo->prepare('UPDATE parametres SET valeur = ? WHERE cle = ?');
    foreach (['smtp_pass', 'smtp_booking_pass'] as $cle) {
        $v = $pdo->prepare('SELECT valeur FROM parametres WHERE cle = ?');
        $v->execute([$cle]);
        $valeur = (string) $v->fetchColumn();
        if ($valeur !== '' && !secret_est_chiffre($valeur)) {
            $maj->execute([secret_chiffrer($valeur), $cle]);
        }
    }
    $existe = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'mailing_expediteurs'")->fetchColumn();
    if ($existe) {
        $majExp = $pdo->prepare('UPDATE mailing_expediteurs SET smtp_pass = ? WHERE id = ?');
        foreach ($pdo->query("SELECT id, smtp_pass FROM mailing_expediteurs WHERE smtp_pass <> ''")->fetchAll() as $r) {
            if (!secret_est_chiffre((string) $r['smtp_pass'])) {
                $majExp->execute([secret_chiffrer((string) $r['smtp_pass']), (int) $r['id']]);
            }
        }
    }
}

// Journal des erreurs du serveur (lib/journal.php § Erreurs). Une table à part
// et non une issue de plus dans journal_activite : une erreur survient aussi
// sur un GET, ou sans compte connecté, et la même erreur répétée mille fois
// doit rester UNE ligne — d'où l'empreinte unique et le compteur.
function migration_107(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS journal_erreurs (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            empreinte   TEXT NOT NULL UNIQUE,
            premier_le  TEXT NOT NULL DEFAULT (datetime('now')),
            dernier_le  TEXT NOT NULL DEFAULT (datetime('now')),
            occurrences INTEGER NOT NULL DEFAULT 1,
            niveau      TEXT NOT NULL,
            message     TEXT NOT NULL DEFAULT '',
            fichier     TEXT NOT NULL DEFAULT '',
            ligne       INTEGER NOT NULL DEFAULT 0,
            route       TEXT NOT NULL DEFAULT '',
            utilisateur TEXT NOT NULL DEFAULT '',
            vue         INTEGER NOT NULL DEFAULT 0
        )
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_journal_erreurs_date ON journal_erreurs(dernier_le)');
}

// N° AVS chiffrés en base (lib/secrets.php § N° AVS) : employes.numero_avs et
// la copie figée de chaque fiche, fiches.employe_avs. La valeur ne change pas,
// seule sa forme stockée : avs_lire() rend exactement le numéro d'avant.
// Une copie de la base est posée avant, s'il y a quelque chose à convertir —
// c'est la règle de toute réécriture de fiches (CLAUDE.md § Historique figé).
// Idempotente : une valeur déjà chiffrée n'est pas retouchée.
function migration_108(PDO $pdo): void
{
    require_once __DIR__ . '/../../secrets.php';
    $aConvertir = (int) $pdo->query("SELECT
        (SELECT COUNT(*) FROM employes WHERE numero_avs <> '' AND numero_avs NOT LIKE 'chiffre:%')
      + (SELECT COUNT(*) FROM fiches WHERE employe_avs <> '' AND employe_avs NOT LIKE 'chiffre:%')")->fetchColumn();
    if ($aConvertir === 0) {
        return;
    }
    if (sauvegarder_base('avant_chiffrement_avs', $pdo) === null) {
        throw new RuntimeException('Chiffrement des n° AVS : la copie préalable de la base a échoué, rien n\'a été converti.');
    }
    $pdo->beginTransaction();
    try {
        foreach ([['employes', 'numero_avs'], ['fiches', 'employe_avs']] as [$table, $col]) {
            $maj = $pdo->prepare("UPDATE $table SET $col = ? WHERE id = ?");
            foreach ($pdo->query("SELECT id, $col AS v FROM $table WHERE $col <> ''")->fetchAll() as $r) {
                if (!secret_est_chiffre((string) $r['v'])) {
                    $maj->execute([secret_chiffrer((string) $r['v']), (int) $r['id']]);
                }
            }
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
