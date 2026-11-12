<?php
// Migrations 76 à 100, dans l'ordre — chargé par lib/db/migrations.php,
// jamais directement. Son en-tête dit la marche à suivre et les pièges.

// Migration 76 : provenance de la contre-partie. « tiers » mélangeait deux
// choses de fiabilité très différente — ce que le relevé déclare en clair
// (RltdPties) et ce qu'on devine de son libellé — sans qu'on puisse les
// distinguer après coup. Les autres colonnes ajoutées en 75 (iban_tiers,
// reference, nature, ref_bancaire) sont, elles, de purs miroirs du fichier :
// « tiers » était devenue l'exception du groupe.
// Reprise de l'existant, seulement là où c'est certain : une écriture qui porte
// une « nature » vient d'un import camt postérieur au correctif, où la déduction
// depuis le libellé n'existait pas encore — sa contre-partie est donc structurée.
// Le reste garde une provenance vide plutôt qu'une supposition.
function migration_76(PDO $pdo): void
{
    $cols = [];
    foreach ($pdo->query('PRAGMA table_info(ecritures)') as $row) {
        $cols[$row['name']] = true;
    }
    if (!isset($cols['tiers_source'])) {
        $pdo->exec("ALTER TABLE ecritures ADD COLUMN tiers_source TEXT NOT NULL DEFAULT ''");
    }
    $pdo->exec("UPDATE ecritures SET tiers_source = 'camt'
                 WHERE tiers_source = '' AND tiers <> '' AND nature <> ''");
    $pdo->exec("UPDATE ecritures SET tiers_source = 'csv'
                 WHERE tiers_source = '' AND tiers <> '' AND nature = '' AND import_id IS NOT NULL");
}

// Migration 77 : nom de l'établissement sur le compte bancaire. Les frais de
// tenue de compte n'ont aucune contre-partie dans le relevé — ni nom ni IBAN —
// alors qu'ils sont bien versés à la banque. Aucune ligne ne permet de la
// deviner (« PRIX POUR LA GESTION DU COMPTE » ne nomme personne) : elle se
// renseigne donc une fois, sur le compte.
function migration_77(PDO $pdo): void
{
    $cols = [];
    foreach ($pdo->query('PRAGMA table_info(comptes_bancaires)') as $row) {
        $cols[$row['name']] = true;
    }
    if (!isset($cols['banque'])) {
        $pdo->exec("ALTER TABLE comptes_bancaires ADD COLUMN banque TEXT NOT NULL DEFAULT ''");
    }
}

// Migration 78 : pastille d'identité d'un employé. La teinte par défaut est
// dérivée de son nom (avatar_initiales(), lib/helpers.php) et n'a donc rien à
// stocker ; ces colonnes ne servent qu'à la remplacer — une couleur choisie, ou
// une photo recadrée. Vides pour tout le monde au départ, l'affichage ne change
// pas tant que personne n'y touche.
function migration_78(PDO $pdo): void
{
    $cols = [];
    foreach ($pdo->query('PRAGMA table_info(employes)') as $row) {
        $cols[$row['name']] = true;
    }
    foreach (['avatar_couleur', 'avatar_photo'] as $nom) {
        if (!isset($cols[$nom])) {
            $pdo->exec("ALTER TABLE employes ADD COLUMN $nom TEXT NOT NULL DEFAULT ''");
        }
    }
}

// Migration 79 : rapprochement d'une fiche de salaire avec l'écriture bancaire
// qui l'a payée — le pendant exact de ce que fait déjà la facture
// (migration_19). Lien des DEUX côtés, comme pour la facture : la fiche pointe
// son écriture (affichage sur ?p=fiche), l'écriture pointe sa fiche (icône de
// retour dans la liste des écritures), et les deux sont écrits dans la même
// transaction. ON DELETE SET NULL des deux côtés : supprimer un import
// d'écritures ne doit pas emporter des fiches de salaire.
function migration_79(PDO $pdo): void
{
    $colonnes = function (string $table) use ($pdo): array {
        $out = [];
        foreach ($pdo->query("PRAGMA table_info($table)") as $c) {
            $out[$c['name']] = true;
        }
        return $out;
    };
    if (!isset($colonnes('fiches')['ecriture_id'])) {
        $pdo->exec('ALTER TABLE fiches ADD COLUMN ecriture_id INTEGER REFERENCES ecritures(id) ON DELETE SET NULL');
    }
    if (!isset($colonnes('ecritures')['fiche_id'])) {
        $pdo->exec('ALTER TABLE ecritures ADD COLUMN fiche_id INTEGER REFERENCES fiches(id) ON DELETE SET NULL');
    }
    // Même raison que l'index de migration_20 sur ecritures.facture_id : la
    // liste des écritures libres l'interroge à chaque ouverture d'une fiche.
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_ecritures_fiche ON ecritures(fiche_id)');
}

// Migration 74 : préférences d'affichage propres à un compte — le dernier
// choix fait dans un widget, une vue préférée… Une table clé/valeur par
// utilisateur plutôt qu'une colonne par besoin : ces choix n'ont ni schéma
// stable ni valeur métier, et une nouvelle préférence ne doit pas coûter une
// migration.
// À distinguer de la SESSION, où vivent déjà les filtres de liste
// (filtre_persistant()) : ce qui est ici survit à la déconnexion, ce qui est en
// session s'oublie avec elle. Et de « parametres », qui est global à
// l'association, pas propre à une personne.
// Migration 80 — ÉTAPE 1 du passage aux postes salariaux configurables.
//
// Ne change AUCUN comportement : elle crée les tables, y déclare les quinze
// lignes actuellement en dur dans calculer_fiche(), et recopie les fiches
// existantes dans fiche_postes. Le calcul et l'affichage continuent de lire les
// colonnes ded_*/emp_* ; la bascule viendra aux étapes suivantes.
//
// Les libellés reprennent MOT POUR MOT ceux de views/_fiche_body.php : la copie
// figée doit se lire exactement comme la fiche d'origine, sans quoi rouvrir un
// décompte de 2024 après la bascule montrerait des intitulés différents.
//
// Les taux, eux, viennent de fiches.taux_json — jamais des taux de l'année
// courante : une fiche a figé les siens à sa création, c'est la règle.
function migration_80(PDO $pdo): void
{
    // [code, libellé, sens, mode, base, rubrique certificat, groupe compta,
    //  masquer si zéro, ordre]
    // Rubriques : 9 = AVS/AC/A.mat/LAA, 10.1 = LPP, 12 = impôt à la source.
    // masquer_si_zero à 0 pour AVS/AC/LAA/LPP côté employé : un décompte les
    // montre même nulles, c'est la règle actuelle de views/_fiche_body.php.
    $postes = [
        ['avs',          'AVS / AI / APG',         'deduction', 'taux',         'brut',      '9',    '',     0,  10],
        ['ac',           'AC',                     'deduction', 'taux',         'brut',      '9',    '',     0,  20],
        ['amat',         'Assurance maternité',    'deduction', 'taux',         'brut',      '9',    '',     1,  30],
        ['laa',          'LAA',                    'deduction', 'laa_seuil',    'brut',      '9',    '',     0,  40],
        ['lpp',          'LPP',                    'deduction', 'taux',         'coordonne', '10.1', '',     0,  50],
        ['impot_source', 'Impôt à la source',      'deduction', 'taux_employe', 'brut',      '12',   '',     1,  60],
        ['emp_avs',      'AVS / AI / APG',         'charge',    'taux',         'brut',      '',     'ocas', 1, 110],
        ['emp_ac',       'AC',                     'charge',    'taux',         'brut',      '',     'ocas', 1, 120],
        ['emp_amat',     'Assurance maternité',    'charge',    'taux',         'brut',      '',     'ocas', 1, 130],
        ['emp_af',       'Allocations familiales', 'charge',    'taux',         'brut',      '',     'ocas', 1, 140],
        ['emp_laa',      'LAA (accidents prof.)',  'charge',    'laa_seuil',    'brut',      '',     '',     1, 150],
        ['emp_frais',    "Frais d'administration", 'charge',    'taux',         'brut',      '',     '',     1, 160],
        ['emp_cpe',      'CPE',                    'charge',    'taux',         'brut',      '',     '',     1, 170],
        ['emp_lfp',      'Formation pro. (LFP)',   'charge',    'taux',         'brut',      '',     '',     1, 180],
        ['emp_lpp',      'LPP',                    'charge',    'taux',         'coordonne', '',     '',     1, 190],
    ];
    $ins = $pdo->prepare(
        'INSERT OR IGNORE INTO postes_salariaux
            (code, libelle, sens, mode, base, rubrique_certificat, groupe_compta, masquer_si_zero, ordre)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($postes as $p) {
        $ins->execute($p);
    }

    // Reprise des taux déjà configurés par année. Les clés de taux_par_annee
    // portent le préfixe « taux_ » côté employé et « emp_taux_ » côté
    // employeur ; le poste, lui, s'appelle « avs » ou « emp_avs ».
    $cleTaux = function (string $code): string {
        return str_starts_with($code, 'emp_')
            ? 'emp_taux_' . substr($code, 4)
            : 'taux_' . $code;
    };
    $ids = [];
    foreach ($pdo->query('SELECT id, code, mode FROM postes_salariaux') as $r) {
        $ids[$r['code']] = ['id' => (int) $r['id'], 'mode' => (string) $r['mode']];
    }
    $anciens = [];
    foreach ($pdo->query('SELECT annee, cle, valeur FROM taux_par_annee') as $r) {
        $anciens[(int) $r['annee']][(string) $r['cle']] = (float) $r['valeur'];
    }
    $insTaux = $pdo->prepare(
        'INSERT OR IGNORE INTO poste_taux (poste_id, annee, valeur, valeur_alt) VALUES (?, ?, ?, ?)'
    );
    foreach ($anciens as $annee => $valeurs) {
        foreach ($ids as $code => $poste) {
            if ($code === 'impot_source') {
                continue; // taux porté par l'employé, pas par l'année
            }
            if ($poste['mode'] === 'laa_seuil') {
                // Deux taux : réduit dans « valeur », plein dans « valeur_alt ».
                $reduit = $valeurs[$cleTaux($code) . '_reduit'] ?? null;
                $plein  = $valeurs[$cleTaux($code) . '_plein'] ?? null;
                if ($reduit !== null || $plein !== null) {
                    $insTaux->execute([$poste['id'], $annee, (float) ($reduit ?? 0), $plein]);
                }
                continue;
            }
            if (isset($valeurs[$cleTaux($code)])) {
                $insTaux->execute([$poste['id'], $annee, $valeurs[$cleTaux($code)], null]);
            }
        }
    }

    // Copie figée des fiches existantes. Idempotente : une fiche déjà recopiée
    // est laissée telle quelle (la migration peut être rejouée sans doubler).
    $dejaFait = (int) $pdo->query('SELECT COUNT(*) FROM fiche_postes')->fetchColumn();
    if ($dejaFait > 0) {
        return;
    }
    $insLigne = $pdo->prepare(
        'INSERT INTO fiche_postes
            (fiche_id, poste_id, code, libelle, sens, rubrique_certificat, groupe_compta,
             masquer_si_zero, taux, montant, ordre)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $meta = [];
    foreach ($pdo->query('SELECT * FROM postes_salariaux') as $r) {
        $meta[(string) $r['code']] = $r;
    }
    foreach ($pdo->query('SELECT * FROM fiches') as $f) {
        $taux = json_decode((string) ($f['taux_json'] ?? '{}'), true) ?: [];
        foreach ($meta as $code => $p) {
            // La colonne de montant porte le même nom que le poste, sauf pour
            // l'impôt à la source (ded_impot_source) : les codes ont été choisis
            // pour que la correspondance soit directe partout ailleurs.
            $colonne = str_starts_with($code, 'emp_') ? $code : 'ded_' . $code;
            if (!array_key_exists($colonne, $f)) {
                continue;
            }
            // Toutes les lignes sont recopiées, y compris à zéro : c'est
            // l'affichage qui décide de les montrer (masquer_si_zero), pas la
            // copie. Une fiche à 0 garde ainsi ses rubriques AVS/AC/LAA/LPP.
            $insLigne->execute([
                (int) $f['id'], (int) $p['id'], $code, (string) $p['libelle'], (string) $p['sens'],
                (string) $p['rubrique_certificat'], (string) $p['groupe_compta'],
                (int) $p['masquer_si_zero'], $taux[$code] ?? null, (float) $f[$colonne], (int) $p['ordre'],
            ]);
        }
    }
}

// Réinitialisation de mot de passe en libre-service. Le jeton part par e-mail ;
// la base n'en garde que l'empreinte SHA-256, donc une copie de la base ne
// permet pas de prendre la main sur un compte.
function migration_81(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS reinit_motdepasse (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            utilisateur_id INTEGER NOT NULL REFERENCES utilisateurs(id) ON DELETE CASCADE,
            jeton_hash     TEXT NOT NULL,
            ip             TEXT NOT NULL DEFAULT '',
            expire_le      INTEGER NOT NULL,
            utilise_le     INTEGER,
            cree_le        INTEGER NOT NULL
        )
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reinit_jeton ON reinit_motdepasse(jeton_hash)');
}

// Dernière connexion réussie d'un compte, affichée dans ?p=utilisateurs. Vide pour
// les comptes existants jusqu'à leur prochaine connexion : on ne peut pas
// inventer une date qui n'a jamais été enregistrée.
function migration_82(PDO $pdo): void
{
    $cols = [];
    foreach ($pdo->query('PRAGMA table_info(utilisateurs)') as $col) {
        $cols[] = $col['name'];
    }
    if (!in_array('derniere_connexion_le', $cols, true)) {
        $pdo->exec("ALTER TABLE utilisateurs ADD COLUMN derniere_connexion_le TEXT NOT NULL DEFAULT ''");
    }
}

// Les coordonnées d'une structure vivent dans ses CONTACTS depuis la carte
// « Contacts » de sa fiche. Trois colonnes en portaient encore d'anciennes
// valeurs : invisibles à l'écran, mais toujours lues en dernier repli par la
// facturation, l'export SUISA et la recherche. On les reprend en contacts, ce
// qui unifie le modèle et permet à migration_84 de supprimer les colonnes.
//
// Seules les structures SANS aucun contact sont reprises : là où un contact
// existe déjà, c'est lui qui fait foi, et une reprise créerait un doublon.
//
// « personne_contact » est un seul champ texte : on coupe au premier espace
// (« Marie Dupont » → prénom « Marie », nom « Dupont »), et une valeur en un
// seul mot part dans le nom — c'est ainsi qu'on lit « Dupont » ou « Accueil ».
function migration_83(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(structures)')->fetchAll(), 'name');
    foreach (['email', 'telephone', 'personne_contact'] as $c) {
        if (!in_array($c, $cols, true)) {
            return; // colonnes déjà retirées : rien à reprendre
        }
    }
    $lignes = $pdo->query(
        "SELECT id, email, telephone, personne_contact FROM structures s
          WHERE (TRIM(COALESCE(email, '')) <> ''
                 OR TRIM(COALESCE(telephone, '')) <> ''
                 OR TRIM(COALESCE(personne_contact, '')) <> '')
            AND NOT EXISTS (SELECT 1 FROM structure_contacts c WHERE c.structure_id = s.id)"
    )->fetchAll();
    $ins = $pdo->prepare('INSERT INTO structure_contacts (structure_id, prenom, nom, email, telephone)
                          VALUES (?, ?, ?, ?, ?)');
    foreach ($lignes as $l) {
        $personne = trim((string) $l['personne_contact']);
        $prenom = '';
        $nom    = $personne;
        if ($personne !== '' && str_contains($personne, ' ')) {
            [$prenom, $nom] = explode(' ', $personne, 2);
        }
        $ins->execute([(int) $l['id'], $prenom, $nom,
            trim((string) $l['email']), trim((string) $l['telephone'])]);
    }
}

// Retrait des trois colonnes reprises par migration_83. DROP COLUMN direct
// (aucune clé étrangère ni index dessus, pas de RENAME de table — ce n'est pas
// le cas à risque documenté en tête de fichier), mais il exige SQLite ≥ 3.35 :
// best-effort, jamais bloquant, exactement comme migration_63 qui a rencontré
// une version plus ancienne en production. Si le DROP échoue, les colonnes
// restent en base, inertes — plus aucun code ne les lit ni ne les écrit.
function migration_84(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(structures)')->fetchAll(), 'name');
    foreach (['email', 'telephone', 'personne_contact'] as $c) {
        if (in_array($c, $cols, true)) {
            try { $pdo->exec("ALTER TABLE structures DROP COLUMN $c"); } catch (\Throwable $e) { /* SQLite < 3.35 */ }
        }
    }
}

// Campagnes de contact du booking : une sélection de structures à contacter
// pour un ou plusieurs projets, sur une période. Distinctes de
// « mailing_campagnes », qui est l'historique des envois groupés — ici on suit
// un travail de démarchage, contact par contact.
//
// Le projet devient aussi une propriété d'une PRISE DE CONTACT
// (historique_spectacles) et d'un MODÈLE de message : c'est ce qui permet à la
// jauge d'une campagne de compter un appel téléphonique noté à la main, et pas
// seulement un e-mail parti depuis la campagne.
function migration_85(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS campagnes (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            nom        TEXT NOT NULL,
            -- Avant la date de début, la campagne se prépare : aucun message ne
            -- part. Après la date de fin sans avoir tout contacté, elle est en
            -- retard. Voir campagne_statut().
            date_debut TEXT NOT NULL DEFAULT '',
            date_fin   TEXT NOT NULL DEFAULT '',
            -- Le ciblage d'origine, gardé pour mémoire : la sélection réelle
            -- vit dans campagne_structures, qu'on peut élaguer à la main.
            criteres   TEXT NOT NULL DEFAULT '',
            cree_le    TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE TABLE IF NOT EXISTS campagne_spectacles (
            campagne_id  INTEGER NOT NULL REFERENCES campagnes(id) ON DELETE CASCADE,
            spectacle_id INTEGER NOT NULL REFERENCES spectacles(id) ON DELETE CASCADE,
            PRIMARY KEY (campagne_id, spectacle_id)
        );
        CREATE TABLE IF NOT EXISTS campagne_structures (
            campagne_id  INTEGER NOT NULL REFERENCES campagnes(id) ON DELETE CASCADE,
            structure_id INTEGER NOT NULL REFERENCES structures(id) ON DELETE CASCADE,
            PRIMARY KEY (campagne_id, structure_id)
        );
        CREATE INDEX IF NOT EXISTS idx_campagne_structures_structure ON campagne_structures(structure_id);
        -- Projets concernés par une entrée d'historique (une prise de contact).
        CREATE TABLE IF NOT EXISTS historique_spectacles (
            historique_id INTEGER NOT NULL REFERENCES historique(id) ON DELETE CASCADE,
            spectacle_id  INTEGER NOT NULL REFERENCES spectacles(id) ON DELETE CASCADE,
            PRIMARY KEY (historique_id, spectacle_id)
        );
        CREATE INDEX IF NOT EXISTS idx_historique_spectacles_spectacle ON historique_spectacles(spectacle_id);
        -- Projets par défaut d'un modèle de message : charger le modèle coche
        -- ces projets dans la fenêtre « Contacter ».
        CREATE TABLE IF NOT EXISTS mailing_modele_spectacles (
            modele_id    INTEGER NOT NULL REFERENCES mailing_modeles(id) ON DELETE CASCADE,
            spectacle_id INTEGER NOT NULL REFERENCES spectacles(id) ON DELETE CASCADE,
            PRIMARY KEY (modele_id, spectacle_id)
        );
    ");
}

// Réponse reçue d'une structure pour UNE campagne : aucune (défaut), pas
// intéressé, intéressé. Portée par le lien campagne↔structure et non par la
// structure : la même salle peut décliner une tournée et prendre la suivante,
// et c'est le démarchage en cours que la campagne suit.
function migration_86(PDO $pdo): void
{
    $cols = [];
    foreach ($pdo->query('PRAGMA table_info(campagne_structures)') as $col) {
        $cols[] = $col['name'];
    }
    if (!in_array('reponse', $cols, true)) {
        $pdo->exec("ALTER TABLE campagne_structures ADD COLUMN reponse TEXT NOT NULL DEFAULT ''");
    }
}

// Icône d'un spectacle : le chemin web d'une vignette carrée recadrée dans le
// navigateur, comme la photo d'un employé (avatar_photo_enregistrer()). Vide =
// pas d'icône, la pastille retombe alors sur les initiales du nom.
function migration_87(PDO $pdo): void
{
    $cols = [];
    foreach ($pdo->query('PRAGMA table_info(spectacles)') as $col) {
        $cols[] = $col['name'];
    }
    if (!in_array('image', $cols, true)) {
        $pdo->exec("ALTER TABLE spectacles ADD COLUMN image TEXT NOT NULL DEFAULT ''");
    }
}

// Adresse précise et horaire d'un événement. La ville seule suffisait à situer
// une date sur une carte ; elle ne suffit pas à s'y rendre, ni à savoir quand.
//
// Ces quatre champs sont PUBLICS, au même titre que la ville, la salle ou le
// festival : ils partent dans l'export JSON et dans le flux iCal d'un événement
// public — c'est le propre d'une adresse de représentation et d'une heure de
// spectacle. Ce qui relève de la feuille de route (hôtel, codes d'accès,
// get-in, balances) ne passera jamais par là.
//
// Heures stockées en « HH:MM », chaîne vide quand elles ne sont pas connues :
// une date de tournée est souvent posée des mois avant que l'horaire le soit.
function migration_88(PDO $pdo): void
{
    $cols = [];
    foreach ($pdo->query('PRAGMA table_info(evenements)') as $col) {
        $cols[] = $col['name'];
    }
    foreach (['adresse_rue', 'adresse_npa', 'heure_debut', 'heure_fin'] as $nouvelle) {
        if (!in_array($nouvelle, $cols, true)) {
            $pdo->exec("ALTER TABLE evenements ADD COLUMN $nouvelle TEXT NOT NULL DEFAULT ''");
        }
    }
}

// Feuille de route d'un événement : ce qu'il faut avoir sous les yeux le jour
// même — le déroulé des horaires, les adresses et leurs codes, les gens à
// appeler, les fiches techniques et confirmations d'hôtel.
//
// UNE table pour cinq types d'éléments, et non cinq tables. L'ordre d'une
// feuille de route court à TRAVERS les types — un horaire, puis l'adresse de
// l'hôtel, puis le contact du régisseur — et cinq tables auraient obligé à
// recomposer cette liste en PHP à chaque lecture. Le prix est une table large
// et creuse : chaque ligne ne remplit que les colonnes de son type.
//
// Rien de ce qui est ici n'est public : la feuille de route ne part jamais dans
// l'export du site. Elle alimentera le calendrier de l'équipe, qui a son propre
// jeton.
function migration_89(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS evenement_feuille (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        evenement_id  INTEGER NOT NULL REFERENCES evenements(id) ON DELETE CASCADE,
        -- horaire | adresse | contact | fichier | note (voir FEUILLE_TYPES,
        -- lib/feuille_route.php)
        type          TEXT NOT NULL,
        ordre         INTEGER NOT NULL DEFAULT 0,
        -- Commun à tous : « Get-in », « Hôtel Ibis », « Régisseur général »…
        libelle       TEXT NOT NULL DEFAULT '',
        -- type horaire
        debut         TEXT NOT NULL DEFAULT '',
        fin           TEXT NOT NULL DEFAULT '',
        -- type adresse. Pas de colonne pour le code d'entrée : il se note dans
        -- la remarque, avec l'étage et la place de parking — un champ par
        -- information de ce genre aurait fini par en faire six.
        adresse       TEXT NOT NULL DEFAULT '',
        -- type contact, saisi librement (un hébergement chez l'habitant n'est
        -- dans aucune structure)…
        prenom        TEXT NOT NULL DEFAULT '',
        nom           TEXT NOT NULL DEFAULT '',
        telephone     TEXT NOT NULL DEFAULT '',
        email         TEXT NOT NULL DEFAULT '',
        -- …ou repris du carnet d'adresses, auquel cas il suit la fiche.
        structure_id  INTEGER REFERENCES structures(id) ON DELETE SET NULL,
        contact_id    INTEGER REFERENCES structure_contacts(id) ON DELETE SET NULL,
        -- type fichier : chemin sous data/fichiers/, hors racine web
        fichier       TEXT NOT NULL DEFAULT '',
        nom_origine   TEXT NOT NULL DEFAULT '',
        mime          TEXT NOT NULL DEFAULT '',
        taille        INTEGER NOT NULL DEFAULT 0,
        -- commun à tous
        remarque      TEXT NOT NULL DEFAULT '',
        cree_le       TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_evenement_feuille_evenement ON evenement_feuille(evenement_id, ordre)');
}

// Le fond de l'application devient un choix (?p=apparence) : quatre décors
// calculés — maillage, vagues, grille, courbes — ou l'image personnalisée.
// « Maillage » devient le défaut, y compris pour les installations existantes :
// les vagues restent disponibles d'un clic, et c'est un changement voulu, pas
// un effet de bord. Seule exception, une installation qui affichait une image
// personnalisée : elle la garde, sans quoi la mise à jour l'aurait effacée de
// l'écran sans que personne l'ait demandé.
function migration_90(PDO $pdo): void
{
    $fond = $pdo->query("SELECT valeur FROM parametres WHERE cle = 'employeur_fond'")->fetchColumn();
    $decor = ($fond !== false && (string) $fond !== '') ? 'image' : 'maillage';
    $pdo->prepare('INSERT OR REPLACE INTO parametres (cle, valeur) VALUES (?, ?)')
        ->execute(['employeur_fond_decor', $decor]);
}

// L'axe analytique remonte de la DATE au PROJET. Il se choisissait date par
// date (evenements.axe_analytique_id_defaut) alors que c'est le projet qui le
// sait : toutes les dates d'une même création se ventilent au même endroit, et
// le régler à chaque fois était autant d'occasions de se tromper. Le module de
// recherche de fonds s'appuiera sur le même axe (docs/SPEC_SUBVENTIONS.md § 3 ter) —
// raison de plus pour qu'il n'ait qu'un seul domicile.
//
// Report : un projet dont les dates portaient TOUTES le même axe le reçoit. Là
// où elles divergeaient, rien n'est inventé — le projet reste sans axe et se
// règle à la main, une fois pour toutes ses dates.
//
// L'ancienne colonne part en best-effort : DROP COLUMN exige SQLite >= 3.35,
// pas garanti en mutualisé (voir migration_63 et docs/DECISIONS.md § Le SQLite
// d'un hébergement mutualisé). Si le retrait échoue, elle reste en base,
// inerte : plus aucun code ne la lit.
function migration_91(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(spectacles)')->fetchAll(), 'name');
    if (!in_array('axe_analytique_id', $cols, true)) {
        $pdo->exec('ALTER TABLE spectacles ADD COLUMN axe_analytique_id INTEGER REFERENCES axes_analytiques(id)');
    }
    $evCols = array_column($pdo->query('PRAGMA table_info(evenements)')->fetchAll(), 'name');
    if (!in_array('axe_analytique_id_defaut', $evCols, true)) {
        return; // déjà migrée
    }
    // MIN() ne choisit rien : la clause ne garde que les projets où il n'y a
    // qu'une seule valeur distincte, donc MIN est cette valeur-là.
    $pdo->exec(
        "UPDATE spectacles SET axe_analytique_id = (
             SELECT MIN(e.axe_analytique_id_defaut) FROM evenements e
              WHERE e.spectacle_id = spectacles.id AND e.axe_analytique_id_defaut IS NOT NULL
         )
         WHERE (SELECT COUNT(DISTINCT e.axe_analytique_id_defaut) FROM evenements e
                 WHERE e.spectacle_id = spectacles.id AND e.axe_analytique_id_defaut IS NOT NULL) = 1"
    );
    try { $pdo->exec('ALTER TABLE evenements DROP COLUMN axe_analytique_id_defaut'); } catch (\Throwable $e) { /* SQLite < 3.35 */ }
}

// Module « Recherche de fonds » (docs/SPEC_SUBVENTIONS.md) : le suivi des demandes
// de subvention, bâti sur ce qui existe. Un bailleur est une `structure` — la
// même fiche que celle du booking et de la facturation, avec ses contacts et
// son historique —, et le projet financé est un `spectacle`, dont l'axe
// analytique porte la ventilation (migration_91).
//
// Cinq tables, et rien d'autre :
//   fonds_campagnes            une recherche de fonds (période, cible, drive)
//   fonds_campagne_spectacles  les projets qu'elle finance
//   fonds_demandes             un dossier chez un bailleur
//   fonds_versements           l'argent reçu — une seule ligne en v1 (§ 3)
//   fonds_pieces / fonds_bailleur_pieces   ce que chaque bailleur exige
//
// fonds_versements existe dès maintenant alors que l'échelonnement est hors
// périmètre : c'est la TABLE qui serait coûteuse à ajouter après coup, pas
// l'écran. Déplacer plus tard deux colonnes d'une table peuplée demanderait la
// manœuvre décrite en tête de ce fichier.
function migration_92(PDO $pdo): void
{
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS fonds_campagnes (
            id                INTEGER PRIMARY KEY AUTOINCREMENT,
            nom               TEXT NOT NULL,
            date_debut        TEXT NOT NULL DEFAULT '',
            date_fin          TEXT NOT NULL DEFAULT '',
            -- Le budget à boucler : c'est lui qui donne son sens à la jauge.
            montant_cible     REAL NOT NULL DEFAULT 0,
            -- L'axe du projet financé, recopié à la création et modifiable.
            axe_analytique_id INTEGER REFERENCES axes_analytiques(id) ON DELETE SET NULL,
            -- Le dossier externe où vivent toutes les pièces : l'application
            -- n'en stocke aucune (docs/SPEC_SUBVENTIONS.md § 3 quater).
            drive_url         TEXT NOT NULL DEFAULT '',
            -- La sélection de bailleurs, même format que campagnes.criteres.
            criteres          TEXT NOT NULL DEFAULT '',
            notes             TEXT NOT NULL DEFAULT '',
            cree_le           TEXT NOT NULL DEFAULT (datetime('now'))
        );

        CREATE TABLE IF NOT EXISTS fonds_campagne_spectacles (
            campagne_id  INTEGER NOT NULL REFERENCES fonds_campagnes(id) ON DELETE CASCADE,
            spectacle_id INTEGER NOT NULL REFERENCES spectacles(id) ON DELETE CASCADE,
            PRIMARY KEY (campagne_id, spectacle_id)
        );

        CREATE TABLE IF NOT EXISTS fonds_demandes (
            id                INTEGER PRIMARY KEY AUTOINCREMENT,
            -- Obligatoire : une demande appartient toujours à une recherche.
            campagne_id       INTEGER NOT NULL REFERENCES fonds_campagnes(id) ON DELETE CASCADE,
            structure_id      INTEGER NOT NULL REFERENCES structures(id) ON DELETE CASCADE,
            statut            TEXT NOT NULL DEFAULT 'a_preparer',
            montant_demande   REAL NOT NULL DEFAULT 0,
            montant_accorde   REAL NOT NULL DEFAULT 0,
            -- Le délai imposé par CE bailleur pour CETTE recherche : il change
            -- d'une année à l'autre, d'où sa place ici et non sur la structure.
            date_limite       TEXT NOT NULL DEFAULT '',
            date_depot        TEXT NOT NULL DEFAULT '',
            date_reponse      TEXT NOT NULL DEFAULT '',
            -- La seconde échéance, celle qu'on oublie une fois l'argent reçu.
            date_limite_bilan TEXT NOT NULL DEFAULT '',
            date_bilan        TEXT NOT NULL DEFAULT '',
            -- Les pièces hors catalogue réclamées cette fois-là.
            pieces_autres     TEXT NOT NULL DEFAULT '',
            facture_id        INTEGER REFERENCES factures(id) ON DELETE SET NULL,
            reference         TEXT NOT NULL DEFAULT '',
            notes             TEXT NOT NULL DEFAULT '',
            cree_le           TEXT NOT NULL DEFAULT (datetime('now')),
            -- Un seul dossier par bailleur et par recherche.
            UNIQUE (campagne_id, structure_id)
        );

        CREATE TABLE IF NOT EXISTS fonds_versements (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            demande_id  INTEGER NOT NULL REFERENCES fonds_demandes(id) ON DELETE CASCADE,
            montant     REAL NOT NULL DEFAULT 0,
            date_prevue TEXT NOT NULL DEFAULT '',
            date_recue  TEXT NOT NULL DEFAULT '',
            ecriture_id INTEGER REFERENCES ecritures(id) ON DELETE SET NULL,
            notes       TEXT NOT NULL DEFAULT '',
            cree_le     TEXT NOT NULL DEFAULT (datetime('now'))
        );

        -- Catalogue des pièces couramment exigées. Une entrée de plus s'ajoute
        -- par l'interface, sans migration.
        CREATE TABLE IF NOT EXISTS fonds_pieces (
            id    INTEGER PRIMARY KEY AUTOINCREMENT,
            nom   TEXT NOT NULL,
            ordre INTEGER NOT NULL DEFAULT 0
        );

        -- Ce que CE bailleur exige, et à quel moment : au dépôt ou au bilan.
        -- « Comptes vérifiés » se demande souvent aux deux.
        CREATE TABLE IF NOT EXISTS fonds_bailleur_pieces (
            structure_id INTEGER NOT NULL REFERENCES structures(id) ON DELETE CASCADE,
            piece_id     INTEGER NOT NULL REFERENCES fonds_pieces(id) ON DELETE CASCADE,
            moment       TEXT NOT NULL DEFAULT 'demande',
            PRIMARY KEY (structure_id, piece_id, moment)
        );

        CREATE INDEX IF NOT EXISTS idx_fonds_demandes_campagne  ON fonds_demandes(campagne_id);
        CREATE INDEX IF NOT EXISTS idx_fonds_demandes_structure ON fonds_demandes(structure_id);
        CREATE INDEX IF NOT EXISTS idx_fonds_versements_demande ON fonds_versements(demande_id);
        CREATE INDEX IF NOT EXISTS idx_fonds_bp_structure       ON fonds_bailleur_pieces(structure_id);
        SQL);

    // Le texte libre « Autres » au niveau du bailleur : ce qu'il réclame
    // toujours, par-dessus les cases du catalogue.
    $cols = array_column($pdo->query('PRAGMA table_info(structures)')->fetchAll(), 'name');
    if (!in_array('fonds_pieces_autres', $cols, true)) {
        $pdo->exec("ALTER TABLE structures ADD COLUMN fonds_pieces_autres TEXT NOT NULL DEFAULT ''");
    }

    // Les trois pièces du quotidien, semées seulement si le catalogue est vide
    // — on ne réintroduit pas une entrée que l'utilisateur aurait retirée.
    if (!$pdo->query('SELECT 1 FROM fonds_pieces LIMIT 1')->fetchColumn()) {
        $ins = $pdo->prepare('INSERT INTO fonds_pieces (nom, ordre) VALUES (?, ?)');
        foreach (["Rapport d'activité", 'Bilan financier du projet', 'Comptes vérifiés'] as $i => $nom) {
            $ins->execute([$nom, $i + 1]);
        }
    }
}

// Une campagne de recherche de fonds vise DEUX montants, pas un : le minimum
// sans lequel le projet ne se fait pas, et ce qu'il faudrait pour le faire
// comme on le voudrait. Un budget unique ne disait ni l'un ni l'autre — il
// laissait croire qu'au-dessous de la barre tout est perdu, et au-dessus qu'il
// n'y a plus rien à chercher.
//
// Report : l'ancien « budget à trouver » devient le MINIMAL. C'est la lecture
// prudente — il désignait ce que le projet exige, pas ce qu'on espérait de
// mieux —, et elle ne fait apparaître aucun objectif idéal qui n'aurait été
// saisi par personne.
//
// L'ancienne colonne part en best-effort : DROP COLUMN exige SQLite >= 3.35,
// pas garanti en mutualisé (docs/DECISIONS.md § Le SQLite d'un hébergement
// mutualisé). Si le retrait échoue, elle reste en base, inerte — plus aucun
// code ne la lit, et son DEFAULT suffit aux INSERT qui ne la nomment plus.
function migration_93(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(fonds_campagnes)')->fetchAll(), 'name');
    if (!in_array('montant_minimal', $cols, true)) {
        $pdo->exec('ALTER TABLE fonds_campagnes ADD COLUMN montant_minimal REAL NOT NULL DEFAULT 0');
        if (in_array('montant_cible', $cols, true)) {
            $pdo->exec('UPDATE fonds_campagnes SET montant_minimal = montant_cible');
        }
    }
    if (!in_array('montant_ideal', $cols, true)) {
        $pdo->exec('ALTER TABLE fonds_campagnes ADD COLUMN montant_ideal REAL NOT NULL DEFAULT 0');
    }
    if (in_array('montant_cible', $cols, true)) {
        try { $pdo->exec('ALTER TABLE fonds_campagnes DROP COLUMN montant_cible'); } catch (\Throwable $e) { /* SQLite < 3.35 */ }
    }
}

// « Spectacle » devient « projet » (docs/NOMMAGE.md § 4.6). Le mot visible
// était déjà réglable et semé à « Projets » ; le code, lui, parlait encore de
// spectacles jusque dans le schéma. Un projet n'est pas toujours un
// spectacle — une campagne de recherche de fonds en finance un.
//
// RENOMMER, et surtout pas dupliquer. `ALTER TABLE … RENAME TO` reporte le
// nouveau nom dans les clauses REFERENCES des autres tables — c'est le
// comportement que docs/DECISIONS.md § Migrations SQLite consigne comme un
// PIÈGE, et qui est ici exactement ce qu'on veut. Dupliquer obligerait à
// reconstruire les quatre tables de liaison ET `evenements`, dont les colonnes
// se sont accumulées sur quatre-vingt-dix migrations.
//
// Deux instructions suffisent, et toutes deux demandent SQLite >= 3.25 :
// RENAME TO (avec report des FK) et RENAME COLUMN. L'hébergeur est en 3.34.
// DROP COLUMN, qui exige 3.35, n'est pas utilisé ici.
//
// TRANSACTION explicite, contrairement aux autres migrations : run_migrations()
// n'enveloppe pas ses étapes, et celle-ci touche six tables. Une étape qui
// échouerait à mi-chemin laisserait PRAGMA user_version en arrière et ferait
// planter toute requête sur db(). On vérifie donc avant de valider, et on
// annule tout si le schéma n'est pas net.
function migration_94(PDO $pdo): void
{
    $existe = function (string $t) use ($pdo): bool {
        $st = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = ?");
        $st->execute([$t]);
        return (bool) $st->fetchColumn();
    };
    $colonnes = fn (string $t): array => array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(), 'name');

    $pdo->beginTransaction();
    try {
        foreach ([
            'spectacles'                => 'projets',
            'campagne_spectacles'       => 'campagne_projets',
            'historique_spectacles'     => 'historique_projets',
            'mailing_modele_spectacles' => 'mailing_modele_projets',
            'fonds_campagne_spectacles' => 'fonds_campagne_projets',
        ] as $ancien => $neuf) {
            if ($existe($ancien) && !$existe($neuf)) {
                $pdo->exec("ALTER TABLE $ancien RENAME TO $neuf");
            }
        }
        foreach (['evenements', 'campagne_projets', 'historique_projets',
                  'mailing_modele_projets', 'fonds_campagne_projets'] as $t) {
            if (!$existe($t)) {
                continue;
            }
            $cols = $colonnes($t);
            if (in_array('spectacle_id', $cols, true) && !in_array('projet_id', $cols, true)) {
                $pdo->exec("ALTER TABLE $t RENAME COLUMN spectacle_id TO projet_id");
            }
        }
        // SQLite n'a pas de RENAME INDEX : on refait les trois index nommés.
        // Ceux des clés primaires suivent le renommage de leur table tout seuls.
        $pdo->exec('DROP INDEX IF EXISTS idx_spectacles_parent');
        $pdo->exec('DROP INDEX IF EXISTS idx_evenements_spectacle');
        $pdo->exec('DROP INDEX IF EXISTS idx_historique_spectacles_spectacle');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_projets_parent ON projets(parent_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_evenements_projet ON evenements(projet_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_historique_projets_projet ON historique_projets(projet_id)');

        // Le terme réglable : UN paramètre au pluriel dont le singulier était
        // deviné en retirant un « s » devient DEUX paramètres saisis. Le
        // français ne suit pas toujours la règle — « Festival »/« Festivals » —,
        // et une valeur saisie au singulier ressortait telle quelle au pluriel.
        // L'existant est repris comme PLURIEL ; le singulier part de l'ancienne
        // devinette, à corriger à la main là où elle se trompe.
        $st = $pdo->prepare("SELECT valeur FROM parametres WHERE cle = 'evenements_terme_spectacle'");
        $st->execute();
        $terme = trim((string) ($st->fetchColumn() ?: ''));
        if ($terme !== '') {
            $pdo->prepare("INSERT OR REPLACE INTO parametres (cle, valeur) VALUES ('evenements_terme_projet', ?)")
                ->execute([$terme]);
            $singulier = rtrim($terme, 's') ?: $terme;
            $pdo->prepare("INSERT OR REPLACE INTO parametres (cle, valeur) VALUES ('evenements_terme_projet_singulier', ?)")
                ->execute([$singulier]);
            $pdo->exec("DELETE FROM parametres WHERE cle = 'evenements_terme_spectacle'");
        }

        // --- les deux vérifications, avant de valider ----------------------
        $restes = $pdo->query("SELECT name FROM sqlite_master WHERE sql LIKE '%spectacle%'")
            ->fetchAll(PDO::FETCH_COLUMN);
        if ($restes) {
            throw new RuntimeException('« spectacle » subsiste dans le schéma : ' . implode(', ', $restes));
        }
        if ($pdo->query('PRAGMA foreign_key_check')->fetchAll()) {
            throw new RuntimeException('clés étrangères cassées après le renommage');
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function migration_95(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(fonds_demandes)')->fetchAll(), 'name');
    if (in_array('reference', $cols, true)) {
        try { $pdo->exec('ALTER TABLE fonds_demandes DROP COLUMN reference'); } catch (\Throwable $e) { /* SQLite < 3.35 */ }
    }
}

function migration_96(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(historique)')->fetchAll(), 'name');
    if (!in_array('modifie_par', $cols, true)) {
        $pdo->exec('ALTER TABLE historique ADD COLUMN modifie_par INTEGER REFERENCES utilisateurs(id) ON DELETE SET NULL');
    }
    if (!in_array('modifie_le', $cols, true)) {
        $pdo->exec("ALTER TABLE historique ADD COLUMN modifie_le TEXT NOT NULL DEFAULT ''");
    }
}

function migration_97(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(structure_message_brouillons)')->fetchAll(), 'name');
    foreach (['cc', 'cci'] as $col) {
        if (!in_array($col, $cols, true)) {
            $pdo->exec("ALTER TABLE structure_message_brouillons ADD COLUMN $col TEXT NOT NULL DEFAULT ''");
        }
    }
}

function migration_98(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(projets)')->fetchAll(), 'name');
    if (!in_array('actif', $cols, true)) {
        $pdo->exec('ALTER TABLE projets ADD COLUMN actif INTEGER NOT NULL DEFAULT 1');
    }
}

function migration_99(PDO $pdo): void
{
    // Les justificatifs de la comptabilité (docs/SPEC_PIECES.md). Une pièce est une
    // ENTITÉ, pas une pièce jointe sur une écriture : elle se saisit avant que
    // le moindre mouvement bancaire existe, et c'est tout son intérêt — une
    // facture arrive le 3, se paie le 30, et entre les deux elle est déjà la
    // liste de ce qu'il y a à payer.
    //
    // Trois états, et non deux, parce que les relevés n'arrivent qu'une fois
    // par mois : à payer (date_payee vide), payée mais pas encore rapprochée,
    // classée (une écriture, ou un paiement hors du compte suivi). Sans cette
    // distinction, une facture réglée le 30 serait restée « à payer » trois
    // semaines.
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS pieces (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            -- facture_recue | ticket | quittance | autre (PIECE_TYPES, lib/pieces.php)
            type           TEXT NOT NULL DEFAULT 'facture_recue',
            -- televersee (un fichier reçu) | emise (un document que Lasso produit)
            origine        TEXT NOT NULL DEFAULT 'televersee',
            libelle        TEXT NOT NULL DEFAULT '',
            tiers          TEXT NOT NULL DEFAULT '',
            -- SIGNÉ COMME UNE ÉCRITURE : < 0 dépense, > 0 recette. C'est ce qui
            -- permet de comparer une pièce et son écriture sans conversion.
            montant        REAL NOT NULL DEFAULT 0,
            date_piece     TEXT NOT NULL DEFAULT '',
            date_echeance  TEXT NOT NULL DEFAULT '',
            date_payee     TEXT NOT NULL DEFAULT '',
            -- '' tant qu'impayée, puis compte | hors_compte. « hors_compte »
            -- (espèces, carte privée, autre banque) dit qu'AUCUNE écriture ne
            -- viendra jamais : c'est lui qui classe la pièce faute d'écriture.
            mode_paiement  TEXT NOT NULL DEFAULT '',
            plan_compte_id INTEGER REFERENCES plan_comptes(id) ON DELETE SET NULL,
            -- Effacer une écriture ne doit pas emporter le justificatif : la
            -- pièce redevient simplement non rapprochée.
            ecriture_id    INTEGER REFERENCES ecritures(id) ON DELETE SET NULL,
            fichier        TEXT NOT NULL DEFAULT '',
            nom_origine    TEXT NOT NULL DEFAULT '',
            mime           TEXT NOT NULL DEFAULT '',
            taille         INTEGER NOT NULL DEFAULT 0,
            -- Q-2026-001 pour une quittance émise, '' sinon.
            numero         TEXT NOT NULL DEFAULT '',
            notes          TEXT NOT NULL DEFAULT '',
            cree_le        TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE INDEX IF NOT EXISTS idx_pieces_ecriture ON pieces(ecriture_id);
        CREATE INDEX IF NOT EXISTS idx_pieces_echeance ON pieces(date_echeance);
    SQL);

    // Le justificatif attendu se déclare UNE FOIS, sur la catégorie : les frais
    // de tenue de compte n'ont pas de facture, les cotisations des membres non
    // plus. La question ne se repose alors plus ligne par ligne.
    $cols = array_column($pdo->query('PRAGMA table_info(plan_comptes)')->fetchAll(), 'name');
    if (!in_array('piece_attendue', $cols, true)) {
        $pdo->exec('ALTER TABLE plan_comptes ADD COLUMN piece_attendue INTEGER NOT NULL DEFAULT 1');
    }

    // …et l'exception isolée se note sur l'écriture. NON VIDE = dispensée, et
    // le texte dit pourquoi : une colonne, pas deux, comme origine_lettrage —
    // on ne peut pas se retrouver avec une dispense sans raison.
    $cols = array_column($pdo->query('PRAGMA table_info(ecritures)')->fetchAll(), 'name');
    if (!in_array('piece_dispense_motif', $cols, true)) {
        $pdo->exec("ALTER TABLE ecritures ADD COLUMN piece_dispense_motif TEXT NOT NULL DEFAULT ''");
    }

    // Reprise de l'état de la comptabilité, exactement comme migration_73 l'a
    // fait pour le mailing — et pour les deux mêmes régressions silencieuses :
    //   • « modules_actifs » est une liste EXPLICITE une fois enregistrée, donc
    //     un identifiant qui n'y figure pas est inactif, et l'onglet Pièces
    //     n'apparaîtrait jamais sans un passage par ?p=modules ;
    //   • les droits sont par module (absence de ligne = aucun accès), donc
    //     personne n'y aurait accès, pas même l'administrateur.
    // Le module naît donc actif et accessible là où la comptabilité l'est — un
    // « git pull » suffit. Le droit de dépôt séparé se règle ensuite, en
    // retirant « compta » à qui ne doit que déposer.
    $st = $pdo->prepare('SELECT valeur FROM parametres WHERE cle = ?');
    $st->execute(['modules_actifs']);
    $val = $st->fetchColumn();
    if ($val !== false) {
        $ids = array_values(array_filter(array_map('trim', explode(',', (string) $val)), fn ($v) => $v !== ''));
        if (in_array('compta', $ids, true) && !in_array('pieces', $ids, true)) {
            $ids[] = 'pieces';
            $pdo->prepare('INSERT OR REPLACE INTO parametres (cle, valeur) VALUES (?, ?)')
                ->execute(['modules_actifs', implode(',', $ids)]);
        }
    }
    $pdo->exec(
        "INSERT OR IGNORE INTO utilisateur_permissions (utilisateur_id, module, niveau)
         SELECT utilisateur_id, 'pieces', niveau FROM utilisateur_permissions WHERE module = 'compta'"
    );
}

function migration_100(PDO $pdo): void
{
    $colonnes = fn (string $t): array => array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(), 'name');
    if (in_array('personne_id', $colonnes('employes'), true)) {
        return; // déjà jouée
    }

    // ⚠️⚠️ PRAGMA foreign_keys = OFF SUR TOUTE LA MIGRATION, et c'est vital.
    //
    // Cette migration recrée `employes` par DROP + RENAME. Or
    // `fiches.employe_id` porte ON DELETE CASCADE : avec les clés étrangères
    // actives — et db() les active —, le DROP a emporté EN SILENCE toutes les
    // fiches de salaire, et derrière elles fiche_postes, fiche_lignes,
    // evenement_fiches et evenement_employes, tous en cascade eux aussi.
    // Aucune erreur, aucun avertissement : la table est simplement vide après
    // la mise à jour. C'est arrivé en production au passage en 3.1.0.
    //
    // Le motif x_new + copie + DROP + RENAME était le bon (docs/DECISIONS.md
    // § Migrations SQLite) ; il lui manquait ce que migration_12 et
    // migration_21 posent déjà autour du leur. Une recréation de table ne
    // coupe pas seulement les FK qui POINTENT vers elle au RENAME : elle
    // déclenche aussi celles qui en DÉPENDENT au DROP.
    $pdo->exec('PRAGMA foreign_keys = OFF');

    // --- 1. Les personnes --------------------------------------------------
    // desinscrit monte ici : qui se désinscrit ne veut plus rien recevoir, pas
    // « plus rien de cette salle-là ». C'est ce qu'on croit demander en
    // cliquant, et c'est un changement de comportement assumé.
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS personnes (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            prenom         TEXT NOT NULL DEFAULT '',
            nom            TEXT NOT NULL DEFAULT '',
            email          TEXT NOT NULL DEFAULT '',
            telephone      TEXT NOT NULL DEFAULT '',
            langue         TEXT NOT NULL DEFAULT '',
            rue            TEXT NOT NULL DEFAULT '',
            npa_localite   TEXT NOT NULL DEFAULT '',
            date_naissance TEXT NOT NULL DEFAULT '',
            desinscrit     INTEGER NOT NULL DEFAULT 0,
            notes          TEXT NOT NULL DEFAULT '',
            cree_le        TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE INDEX IF NOT EXISTS idx_personnes_nom   ON personnes(nom, prenom);
        CREATE INDEX IF NOT EXISTS idx_personnes_email ON personnes(email);
    SQL);

    // Une personne par contact, AUCUN regroupement automatique : avec ~2900
    // lignes, des boîtes partagées (info@…) et des homonymes, un
    // dédoublonnage automatique fabriquerait des fusions fausses et muettes —
    // et une fusion fausse coûte bien plus cher qu'un doublon. Un écran de
    // fusion manuelle viendra.
    $pdo->exec(
        "INSERT INTO personnes (id, prenom, nom, email, telephone, langue, desinscrit, cree_le)
         SELECT id, prenom, nom, email, telephone, langue, desinscrit, cree_le FROM structure_contacts"
    );

    // --- 2. Le lien, qui garde les identifiants ----------------------------
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS personne_structures (
            id                 INTEGER PRIMARY KEY AUTOINCREMENT,
            personne_id        INTEGER NOT NULL REFERENCES personnes(id) ON DELETE CASCADE,
            structure_id       INTEGER NOT NULL REFERENCES structures(id) ON DELETE CASCADE,
            role               TEXT NOT NULL DEFAULT '',
            formulaire_url     TEXT NOT NULL DEFAULT '',
            est_administration INTEGER NOT NULL DEFAULT 0,
            est_booking        INTEGER NOT NULL DEFAULT 0,
            actif              INTEGER NOT NULL DEFAULT 1,
            cree_le            TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE INDEX IF NOT EXISTS idx_ps_structure ON personne_structures(structure_id);
        CREATE INDEX IF NOT EXISTS idx_ps_personne  ON personne_structures(personne_id);
    SQL);
    $pdo->exec(
        "INSERT INTO personne_structures
            (id, personne_id, structure_id, role, formulaire_url, est_administration, est_booking, actif, cree_le)
         SELECT id, id, structure_id, role, formulaire_url, est_administration, est_booking, actif, cree_le
           FROM structure_contacts"
    );

    // --- 3. Les quatre tables qui pointaient les contacts ------------------
    // Le MAILING vise désormais une personne : c'est à elle qu'on écrit, et
    // c'est elle qui se désinscrit. Les deux autres désignent un interlocuteur
    // DANS une structure donnée — elles suivent donc le lien.
    $pdo->exec(<<<SQL
        CREATE TABLE mailing_file_attente_new (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            structure_id  INTEGER NOT NULL REFERENCES structures(id) ON DELETE CASCADE,
            personne_id   INTEGER REFERENCES personnes(id) ON DELETE CASCADE,
            sujet         TEXT NOT NULL DEFAULT '',
            corps         TEXT NOT NULL DEFAULT '',
            statut        TEXT NOT NULL DEFAULT 'attente',
            cree_le       TEXT NOT NULL DEFAULT (datetime('now')),
            campagne_id   INTEGER,
            expediteur_id INTEGER REFERENCES mailing_expediteurs(id) ON DELETE SET NULL
        );
        INSERT INTO mailing_file_attente_new
            (id, structure_id, personne_id, sujet, corps, statut, cree_le, campagne_id, expediteur_id)
        SELECT id, structure_id, contact_id, sujet, corps, statut, cree_le, campagne_id, expediteur_id
          FROM mailing_file_attente;
        DROP TABLE mailing_file_attente;
        ALTER TABLE mailing_file_attente_new RENAME TO mailing_file_attente;
        CREATE INDEX IF NOT EXISTS idx_mailing_file_attente_statut ON mailing_file_attente(statut);

        CREATE TABLE mailing_envois_new (
            id                 INTEGER PRIMARY KEY AUTOINCREMENT,
            structure_id       INTEGER NOT NULL REFERENCES structures(id) ON DELETE CASCADE,
            personne_id        INTEGER REFERENCES personnes(id) ON DELETE SET NULL,
            sujet              TEXT NOT NULL DEFAULT '',
            destinataire_email TEXT NOT NULL DEFAULT '',
            succes             INTEGER NOT NULL DEFAULT 0,
            envoye_le          TEXT NOT NULL DEFAULT (datetime('now')),
            campagne_id        INTEGER
        );
        INSERT INTO mailing_envois_new
            (id, structure_id, personne_id, sujet, destinataire_email, succes, envoye_le, campagne_id)
        SELECT id, structure_id, contact_id, sujet, destinataire_email, succes, envoye_le, campagne_id
          FROM mailing_envois;
        DROP TABLE mailing_envois;
        ALTER TABLE mailing_envois_new RENAME TO mailing_envois;
        CREATE INDEX IF NOT EXISTS idx_mailing_envois_structure ON mailing_envois(structure_id);
        CREATE INDEX IF NOT EXISTS idx_mailing_envois_envoye_le ON mailing_envois(envoye_le);

        CREATE TABLE structure_message_brouillons_new (
            structure_id  INTEGER PRIMARY KEY REFERENCES structures(id) ON DELETE CASCADE,
            contact_id    INTEGER REFERENCES personne_structures(id) ON DELETE SET NULL,
            expediteur_id INTEGER REFERENCES mailing_expediteurs(id) ON DELETE SET NULL,
            sujet         TEXT NOT NULL DEFAULT '',
            corps         TEXT NOT NULL DEFAULT '',
            maj_le        TEXT NOT NULL DEFAULT (datetime('now')),
            cc            TEXT NOT NULL DEFAULT '',
            cci           TEXT NOT NULL DEFAULT ''
        );
        INSERT INTO structure_message_brouillons_new
        SELECT structure_id, contact_id, expediteur_id, sujet, corps, maj_le, cc, cci
          FROM structure_message_brouillons;
        DROP TABLE structure_message_brouillons;
        ALTER TABLE structure_message_brouillons_new RENAME TO structure_message_brouillons;
    SQL);

    // evenement_feuille : reconstruite colonne pour colonne, en reprenant
    // l'ordre réel de la table plutôt qu'une liste écrite ici — elle a gagné
    // des colonnes par migrations successives (code_acces, mime, taille…) et
    // une liste figée en oublierait une sur une base plus ancienne.
    $colsFeuille = $colonnes('evenement_feuille');
    $listeFeuille = implode(', ', $colsFeuille);
    $pdo->exec(<<<SQL
        CREATE TABLE evenement_feuille_new (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            evenement_id  INTEGER NOT NULL REFERENCES evenements(id) ON DELETE CASCADE,
            type          TEXT NOT NULL,
            ordre         INTEGER NOT NULL DEFAULT 0,
            libelle       TEXT NOT NULL DEFAULT '',
            debut         TEXT NOT NULL DEFAULT '',
            fin           TEXT NOT NULL DEFAULT '',
            adresse       TEXT NOT NULL DEFAULT '',
            code_acces    TEXT NOT NULL DEFAULT '',
            prenom        TEXT NOT NULL DEFAULT '',
            nom           TEXT NOT NULL DEFAULT '',
            telephone     TEXT NOT NULL DEFAULT '',
            email         TEXT NOT NULL DEFAULT '',
            structure_id  INTEGER REFERENCES structures(id) ON DELETE SET NULL,
            contact_id    INTEGER REFERENCES personne_structures(id) ON DELETE SET NULL,
            fichier       TEXT NOT NULL DEFAULT '',
            nom_origine   TEXT NOT NULL DEFAULT '',
            mime          TEXT NOT NULL DEFAULT '',
            taille        INTEGER NOT NULL DEFAULT 0,
            remarque      TEXT NOT NULL DEFAULT '',
            cree_le       TEXT NOT NULL DEFAULT (datetime('now'))
        );
        INSERT INTO evenement_feuille_new ($listeFeuille) SELECT $listeFeuille FROM evenement_feuille;
        DROP TABLE evenement_feuille;
        ALTER TABLE evenement_feuille_new RENAME TO evenement_feuille;
        CREATE INDEX IF NOT EXISTS idx_evenement_feuille_evenement ON evenement_feuille(evenement_id, ordre);

        DROP TABLE structure_contacts;
    SQL);

    // --- 4. Les employés : l'identité sort, le métier reste ----------------
    // Ce qui part : prénom, nom, e-mail, adresse, date de naissance. Ce qui
    // reste : le numéro AVS, le canton, la procédure, le taux horaire —
    // l'EMPLOI, pas la personne.
    //
    // ⚠️ Les fiches déjà enregistrées ne bougent pas : elles FIGENT leur copie
    // (fiches.employe_nom/employe_rue/employe_npa/employe_avs) à
    // l'enregistrement. Seul le certificat de salaire lit l'adresse en direct,
    // et il la lira par la relation.
    $pdo->exec(<<<SQL
        INSERT INTO personnes (prenom, nom, email, rue, npa_localite, date_naissance)
        SELECT prenom, nom, email, rue, npa_localite, date_naissance FROM employes ORDER BY id;

        CREATE TABLE employes_new (
            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
            personne_id         INTEGER REFERENCES personnes(id) ON DELETE SET NULL,
            numero_avs          TEXT NOT NULL DEFAULT '',
            canton              TEXT NOT NULL DEFAULT 'Genève',
            procedure           TEXT NOT NULL DEFAULT 'Ordinaire',
            salaire_horaire     REAL NOT NULL DEFAULT 0,
            supplement_vacances REAL NOT NULL DEFAULT 0.0833,
            impot_source_taux   REAL NOT NULL DEFAULT 0,
            actif               INTEGER NOT NULL DEFAULT 1,
            avatar_couleur      TEXT NOT NULL DEFAULT '',
            avatar_photo        TEXT NOT NULL DEFAULT '',
            cree_le             TEXT NOT NULL DEFAULT (datetime('now'))
        );
        INSERT INTO employes_new
            (id, personne_id, numero_avs, canton, procedure, salaire_horaire,
             supplement_vacances, impot_source_taux, actif, avatar_couleur, avatar_photo, cree_le)
        SELECT e.id,
               (SELECT p.id FROM personnes p
                 WHERE p.prenom = e.prenom AND p.nom = e.nom AND p.rue = e.rue
                   AND p.npa_localite = e.npa_localite AND p.date_naissance = e.date_naissance
                   AND p.id > (SELECT COALESCE(MAX(id), 0) FROM personne_structures)
                 ORDER BY p.id LIMIT 1),
               e.numero_avs, e.canton, e.procedure, e.salaire_horaire,
               e.supplement_vacances, e.impot_source_taux, e.actif, e.avatar_couleur, e.avatar_photo, e.cree_le
          FROM employes e;
        DROP TABLE employes;
        ALTER TABLE employes_new RENAME TO employes;
    SQL);

    // --- 5. Les comptes ----------------------------------------------------
    // Un compte est d'abord un identifiant de connexion ; son porteur est une
    // personne. prenom/nom restent sur utilisateurs tant que personne_id est
    // vide — un compte peut exister sans que l'on sache qui le tient.
    if (!in_array('personne_id', $colonnes('utilisateurs'), true)) {
        $pdo->exec('ALTER TABLE utilisateurs ADD COLUMN personne_id INTEGER REFERENCES personnes(id) ON DELETE SET NULL');
    }

    // --- 6. L'historique, sur deux axes ------------------------------------
    // La table est déjà polymorphe (entite_type + entite_id). Une colonne
    // facultative de plus, et une entrée se lit depuis la structure comme
    // depuis la personne — plutôt que deux lignes pour un même échange. Les
    // entrées existantes, qui n'ont pas de personne, restent valides.
    if (!in_array('personne_id', $colonnes('historique'), true)) {
        $pdo->exec('ALTER TABLE historique ADD COLUMN personne_id INTEGER REFERENCES personnes(id) ON DELETE SET NULL');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_historique_personne ON historique(personne_id, cree_le)');
    }

    $pdo->exec('PRAGMA foreign_keys = ON');
}
