<?php
// Migrations de schéma versionnées (PRAGMA user_version).
//
// Extrait de lib/db.php pour que la chaîne de migrations vive dans son propre
// fichier : c'est la partie qui grossit à chaque évolution du schéma, et la
// seule que l'on relit en archéologue. Chargé par lib/db.php, jamais
// directement.
//
// Pour ajouter une évolution : une entrée dans $steps (numéro suivant) + une
// fonction migration_N() dans le dernier fichier de lib/db/migrations/.
// Chaque étape doit rester idempotente.
//
// ⚠ Piège SQLite vérifié empiriquement (voir migration_21) : pour recréer une
// table avec un schéma modifié, ne PAS faire « ALTER TABLE x RENAME TO x_old »
// puis recréer x — « PRAGMA foreign_keys = OFF » ne suffit pas à empêcher
// SQLite de réécrire les clauses REFERENCES des AUTRES tables vers x_old, qui
// devient une FK cassée une fois x_old supprimée. Créer la nouvelle table sous
// un nom temporaire (x_new), y copier les données, DROP TABLE x, puis
// ALTER TABLE x_new RENAME TO x. tests/migrations_test.php vérifie désormais
// PRAGMA foreign_key_check après toute la chaîne.

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
        67 => 'migration_67', // retire structures.type (organisation/particulier) — la catégorie suffit, plus utilisé
        68 => 'migration_68', // index sur login_attempts (comptage anti-force-brute par IP et par compte)
        69 => 'migration_69', // structure_doublons_ignores : paires de structures écartées de la détection des doublons potentiels
        70 => 'migration_70', // vidée : sa colonne texte « expediteur » est remplacée par « expediteur_id » dès la 71
        71 => 'migration_71', // mailing_expediteurs : plusieurs boîtes d'envoi, chacune avec son propre SMTP — l'expéditeur devient une référence, plus un texte
        72 => 'migration_72', // structure_message_brouillons : le message en cours d'écriture depuis une fiche structure (bouton « Contacter »)
        73 => 'migration_73', // sous-module « Envois groupés » : reprend l'activation et les droits du booking, pour que personne ne perde l'accès au mailing
        74 => 'migration_74', // utilisateur_preferences : les choix d'affichage propres à un compte (widget « Suivi du booking » du tableau de bord)
        75 => 'migration_75', // ecritures : champs structurés camt.053 (référence QR, IBAN de la contre-partie, référence bancaire, nature) — voir parse_camt053()
        76 => 'migration_76', // ecritures.tiers_source : d'où vient la contre-partie (champ structuré ou déduite du libellé), donc à quel point s'y fier
        77 => 'migration_77', // comptes_bancaires.banque : l'établissement, contre-partie des frais bancaires que le relevé n'attribue à personne
        78 => 'migration_78', // employes.avatar_couleur / avatar_photo : la pastille d'identité des listes, personnalisable
        79 => 'migration_79', // rapprochement fiche de salaire ↔ écriture bancaire (fiches.ecriture_id + ecritures.fiche_id), symétrique de la facture
        80 => 'migration_80', // postes salariaux : les lignes d'un décompte deviennent des enregistrements, et chaque fiche en fige une copie (étape 1, aucun changement de comportement)
        81 => 'migration_81', // reinit_motdepasse : jetons à usage unique du « mot de passe oublié » (empreinte seule, jamais le jeton)
        82 => 'migration_82', // utilisateurs.derniere_connexion_le : la dernière connexion réussie, affichée dans ?p=utilisateurs
        83 => 'migration_83', // structures.email/telephone/personne_contact → un contact de la structure (structure_contacts)
        84 => 'migration_84', // …puis retrait de ces trois colonnes, devenues sans lecteur
        85 => 'migration_85', // campagnes de contact du booking : sélection de structures, projets concernés, et le projet sur un contact / un modèle
        86 => 'migration_86', // réponse reçue d'une structure démarchée, propre à la campagne
        87 => 'migration_87', // icône (image recadrée) d'un spectacle
        88 => 'migration_88', // adresse (rue, NPA) et heures de début/fin d'un événement — champs publics, exportés
        89 => 'migration_89', // feuille de route d'un événement : une liste ordonnée d'éléments de types différents
        90 => 'migration_90', // choix du fond (employeur_fond_decor) : quatre décors calculés ou l'image personnalisée
        91 => 'migration_91', // l'axe analytique appartient au PROJET (spectacles), plus à chaque date
        92 => 'migration_92', // module « Recherche de fonds » : campagnes, demandes, versements, pièces exigées
        93 => 'migration_93', // recherche de fonds : deux paliers d'objectif (minimal, idéal) au lieu d'un budget unique
        94 => 'migration_94', // « spectacle » devient « projet » : tables, colonnes, index, et le terme réglable scindé en singulier/pluriel
        95 => 'migration_95', // recherche de fonds : retrait du numéro de dossier (jamais utilisé)
        96 => 'migration_96', // historique : qui a modifié une note, et quand
        97 => 'migration_97', // brouillon de message : les copies (cc) et copies cachées (cci)
        98 => 'migration_98', // projets : actif/inactif, pour alléger les menus de projet
        99 => 'migration_99', // pièces comptables : la table, et le justificatif attendu d'une écriture
        100 => 'migration_100', // les personnes : l'identité sort des contacts, des employés et des comptes
        101 => 'migration_101', // quittances : le bénéficiaire d'une pièce émise
        102 => 'migration_102', // le carnet d'adresses devient un module à part entière
        103 => 'migration_103', // doublons de l'annuaire : les suggestions qu'on a écartées
        104 => 'migration_104', // clôture d'exercice : le verrou des écritures, dans la base
        105 => 'migration_105', // journal d'activité : qui a fait quoi, dans toute l'application
        106 => 'migration_106', // mots de passe des boîtes d'envoi chiffrés en base (lib/secrets.php)
        107 => 'migration_107', // journal des erreurs du serveur (lib/journal.php)
        108 => 'migration_108', // n° AVS chiffrés en base : employés et copie figée des fiches (lib/secrets.php)
    ];
    foreach ($steps as $num => $fn) {
        if ($version < $num) {
            $fn($pdo);
            $pdo->exec('PRAGMA user_version = ' . (int) $num);
        }
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

// Les migration_N(), par tranches de 25 et dans l'ordre des numéros : c'est
// la partie qui grossit à chaque évolution du schéma. Une migration nouvelle
// va dans le dernier fichier.
require_once __DIR__ . '/migrations/etapes_001_025.php';
require_once __DIR__ . '/migrations/etapes_026_050.php';
require_once __DIR__ . '/migrations/etapes_051_075.php';
require_once __DIR__ . '/migrations/etapes_076_100.php';
require_once __DIR__ . '/migrations/etapes_101_suite.php';
