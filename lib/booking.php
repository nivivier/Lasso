<?php
// Module booking : CRM des structures (salles, festivals, médias, entourage).
// Fonctions partagées par les routes (lib/routes_booking.php) : dernier contact
// dérivé, normalisation de nom pour l'import, filtrage des destinataires de
// mailing (réutilisé par l'aperçu et par la constitution de la file d'attente,
// voir docs/SPEC_BOOKING.md §6/§7).

declare(strict_types=1);

require_once __DIR__ . '/compta.php'; // plan_pid()/plan_enfants()/plan_est_feuille()/plan_liste_ordonnee() (arbre générique id/parent_id/ordre)

// Trois métiers sont sortis de ce fichier (3 378 lignes) : ils sont chargés ICI
// et ne se requièrent jamais directement, pour que « require lib/booking.php »
// continue de tout apporter — même règle que lib/helpers/.
//
//   lib/booking/campagnes.php  campagnes de démarchage, carte du tableau de
//                              bord, ciblage des structures
//   lib/booking/mailing.php    éligibilité à un envoi, expéditeurs, message
//                              individuel, liste d'exclusion
//   lib/booking/import.php     import CSV d'un carnet d'adresses
//
// Restent ici : statuts, catégories et étiquettes des structures, filtre
// « Lieu », historique, fusion. Découpage par DÉPLACEMENT de blocs entiers.
require_once __DIR__ . '/booking/campagnes.php';
require_once __DIR__ . '/booking/mailing.php';
require_once __DIR__ . '/booking/import.php';

// Statut unique d'une structure (structures.statut, migration_63 — remplace
// actif+desinscrit) : « contact_privilegie » (prioritaire, jamais déduit
// automatiquement d'un import/backfill — uniquement une action manuelle sur
// la fiche), « actif », « a_verifier » (on lui écrit toujours, mais on doute
// de ce qu'on a d'elle), « ne_pas_contacter » (active mais désinscrite du
// mailing), « inactif ». Ordre = ordre d'affichage du sélecteur segmenté
// (structure_statut_toggle_html(), lib/helpers.php) et des filtres/bulk
// (?p=structures) : il va du plus engagé au plus éteint, et « a_verifier »
// occupe le centre des cinq, entre ce qui marche et ce à quoi on n'écrit plus.
// « actif »/« contact_privilegie »/« a_verifier » comptent pour éligibles
// au mailing (mailing_structures_eligibles()) ; « ne_pas_contacter »/
// « inactif » en sont toujours exclus.
const STRUCTURE_STATUTS = ['contact_privilegie', 'actif', 'a_verifier', 'ne_pas_contacter', 'inactif'];

// Celui des cinq que du code doit pouvoir NOMMER : la fenêtre « Contacter »
// l'affiche en mise en garde (views/_structure_contacter.php). Une constante
// plutôt que la chaîne écrite une seconde fois — c'est ainsi qu'un statut
// renommé ne laisse pas un test d'égalité muet derrière lui.
const STRUCTURE_STATUT_A_VERIFIER = 'a_verifier';

// Les statuts qui acceptent qu'on écrive à la structure — mailing groupé comme
// message individuel. UNE seule liste : elle vivait en trois exemplaires (une
// liste blanche dans mailing_structures_eligibles(), deux listes noires dans le
// bouton « Contacter » et sa vue), qui ne s'accordaient que parce qu'il n'y a
// que quatre statuts. Un cinquième les aurait fait diverger en silence.
const STRUCTURE_STATUTS_CONTACTABLES = ['actif', 'contact_privilegie', 'a_verifier'];

const STRUCTURE_STATUTS_LIBELLES = [
    'contact_privilegie' => 'Contact privilégié',
    'actif'               => 'Actif',
    'a_verifier'          => 'À vérifier',
    'ne_pas_contacter'    => 'Ne pas contacter',
    'inactif'             => 'Inactif',
];

// La coche est réservée à ce qui a ÉTÉ FAIT — un envoi, une confirmation, un
// seuil franchi (docs/UI.md § 12). « Actif » n'est pas un geste accompli, c'est
// un état : d'où le point plein, qui dit « celle-ci compte » sans prétendre
// qu'on lui a écrit.
// « À vérifier » : un point d'interrogation, parce que ce statut dit un DOUTE —
// l'adresse a peut-être changé, la personne a peut-être quitté le poste — et
// non un manque de champs, qui appellerait une autre icône.
const STRUCTURE_STATUTS_ICONES = [
    'contact_privilegie' => 'heart',
    'actif'               => 'circle-dot',
    'a_verifier'          => 'circle-question-mark',
    'ne_pas_contacter'    => 'circle-x',
    'inactif'             => 'circle-dashed',
];

// Classe CSS de couleur de l'icône (assets/app.css) — utilisée par la colonne
// « Statut » de ?p=structures (icône seule, voir views/structures.php).
const STRUCTURE_STATUTS_CLASSES_ICONE = [
    'contact_privilegie' => 'ico-pink',
    'actif'               => 'ico-ok',
    // Ambre : la fiche demande un geste de notre part (docs/UI.md § 13).
    'a_verifier'          => 'ico-amber',
    'ne_pas_contacter'    => 'ico-danger',
    'inactif'             => 'muted',
];

// Interlocuteur retenu pour un lot de structures : [structure_id => contact].
//
// La règle, du plus prioritaire au moins : le contact coché « administration »
// de la structure, sinon son premier contact actif, sinon — la structure n'en
// ayant aucun — le contact de sa ou ses structures mères, avec la même
// priorité entre elles. Un contact propre passe TOUJOURS avant un contact
// repris de la mère, même non coché.
//
// Résolu en PHP, et non par un sous-select dans une clause ON : le SQLite de
// certains hébergements mutualisés ne sait pas y référencer l'alias d'une autre
// table jointe (« no such column: d.id », constaté en production). Trois
// requêtes à listes IN, portables partout, valent mieux qu'une requête élégante
// qui ne tourne qu'ici.
function structures_contact_reference(array $structureIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $structureIds))));
    if (!$ids) {
        return [];
    }
    // Les mères, dont on reprendra le contact à défaut.
    $meres = [];
    $stmt = db()->prepare('SELECT structure_id, organisateur_id FROM structure_organisateurs
                            WHERE structure_id IN (' . sql_in($ids) . ')');
    $stmt->execute($ids);
    foreach ($stmt as $l) {
        $meres[(int) $l['structure_id']][] = (int) $l['organisateur_id'];
    }

    // Contacts actifs de toutes les structures concernées, mères comprises,
    // déjà triés : l'« administration » d'abord, puis l'ordre de création.
    $aChercher = array_values(array_unique(array_merge($ids, array_merge([], ...array_values($meres)))));
    $stmt = db()->prepare('SELECT ' . CONTACT_SELECT . CONTACT_FROM . '
                            WHERE cs.actif = 1 AND cs.structure_id IN (' . sql_in($aChercher) . ')
                            ORDER BY cs.est_administration DESC, cs.id ASC');
    $stmt->execute($aChercher);
    $parStructure = [];
    foreach ($stmt as $c) {
        $parStructure[(int) $c['structure_id']][] = $c;
    }

    $out = [];
    foreach ($ids as $id) {
        $contact = $parStructure[$id][0] ?? null;
        foreach ($meres[$id] ?? [] as $mere) {
            if ($contact !== null) {
                break;
            }
            $contact = $parStructure[$mere][0] ?? null;
        }
        if ($contact !== null) {
            $out[$id] = $contact;
        }
    }
    return $out;
}

// Jauge d'une structure, telle qu'on l'écrit à l'écran.
//
// jauge_min et jauge_max sont la PLUS PETITE et la PLUS GRANDE jauge de la
// structure — une salle peut en avoir plusieurs. Le cas le plus courant est
// celui d'une salle unique : une seule des deux valeurs est renseignée, et il
// faut alors afficher ce nombre tel quel. L'écrire « ≥ 200 » laissait croire à
// une borne, c'est-à-dire à une jauge inconnue plus grande que 200.
//
// Renvoie '' quand aucune jauge n'est connue ; l'appelant décide de ce qu'il
// met à la place (un tiret, rien du tout).
function structure_jauge_texte($min, $max): string
{
    $nombre = function ($v): ?int {
        return ($v === null || $v === '' || (int) $v <= 0) ? null : (int) $v;
    };
    $a = $nombre($min);
    $b = $nombre($max);
    if ($a === null && $b === null) {
        return '';
    }
    if ($a === null || $b === null) {
        return (string) ($a ?? $b);           // une seule salle connue
    }
    if ($a === $b) {
        return (string) $a;                   // deux valeurs, une seule jauge
    }
    // Saisie inversée : on remet dans l'ordre plutôt que d'afficher « 800 – 200 ».
    return $a < $b ? "$a – $b" : "$b – $a";
}

// Période d'une structure (réalisation, préparation), telle qu'on l'écrit à
// l'écran : « Juin – Septembre », ou « Juillet » tout court.
//
// UN SEUL MOIS quand les deux bornes se rejoignent — un festival qui tient sur
// trois jours a la même en début et en fin — ou quand une seule est renseignée.
// « Juillet – Juillet » et « Juillet – — » disaient tous deux moins bien la
// même chose : que ça n'a lieu que ce mois-là.
//
// Pas de remise en ordre, contrairement à structure_jauge_texte() : les mois
// tournent, « Novembre – Février » est une saison d'hiver, pas une saisie
// inversée.
//
// Renvoie '' quand aucun mois n'est connu ; l'appelant décide de ce qu'il met à
// la place (« Toute l'année » sur la fiche).
function structure_periode_texte($debut, $fin): string
{
    $mois = fn ($v): ?int => ($v === null || $v === '' || (int) $v < 1 || (int) $v > 12) ? null : (int) $v;
    $a = $mois($debut);
    $b = $mois($fin);
    if ($a === null && $b === null) {
        return '';
    }
    if ($a === null || $b === null || $a === $b) {
        return mois_nom($a ?? $b);
    }
    return mois_nom($a) . ' – ' . mois_nom($b);
}

function structure_statut_libelle(string $statut): string
{
    return STRUCTURE_STATUTS_LIBELLES[$statut] ?? $statut;
}

function structure_statut_icone(string $statut): string
{
    return STRUCTURE_STATUTS_ICONES[$statut] ?? 'circle-dashed';
}

function structure_statut_icone_classe(string $statut): string
{
    return STRUCTURE_STATUTS_CLASSES_ICONE[$statut] ?? 'muted';
}

// Catégorie CRM d'une structure (booking), voir docs/SPEC_BOOKING.md §5.
// Configurable (Paramètres → Catégories). Une sous-catégorie
// est nécessairement imbriquée dans une catégorie (structure_categories.parent_id,
// même principe que projets/groupe-projet — voir lib/evenements.php) : une
// catégorie racine a parent_id NULL, une sous-catégorie a pour parent_id l'id
// d'une catégorie racine (2 niveaux max, imposé par l'UI plutôt que le schéma).
// structures.categorie/sous_categorie restent des colonnes texte (comparaison
// par nom) — renommer une catégorie/sous-catégorie dans les paramètres met à
// jour les structures existantes en même temps (voir route_categories_structures()).

// Toutes les catégories/sous-catégories indexées par id, pour les fonctions
// d'arbre génériques (lib/compta.php) et la résolution catégorie/sous-catégorie.
function structure_categorie_map(): array
{
    $map = [];
    foreach (db()->query('SELECT * FROM structure_categories ORDER BY ordre, id') as $r) {
        $map[(int) $r['id']] = $r;
    }
    return $map;
}

// Liste à plat dans l'ordre d'affichage de l'arbre (catégorie puis ses
// sous-catégories), avec 'profondeur' (0 = catégorie, 1 = sous-catégorie) — pour
// le dropdown unique de structure_form.php.
function structure_categories_pour_select(?array $map = null): array
{
    $map ??= structure_categorie_map();
    $out = [];
    foreach (plan_liste_ordonnee($map) as $r) {
        $out[] = ['id' => (int) $r['id'], 'nom' => (string) $r['nom'], 'profondeur' => (int) $r['profondeur']];
    }
    return $out;
}

// Liste à plat avec toutes les métadonnées d'arbre (profondeur, a_enfants,
// est_premier, est_dernier) — pour l'écran d'admin en glisser-déposer
// (parametres_structures, voir route_categories_structures()).
function structure_categories_liste_ordonnee(?array $map = null): array
{
    return plan_liste_ordonnee($map ?? structure_categorie_map());
}

// Catégories racines uniquement (parent_id NULL).
function structure_categories_racines(?array $map = null): array
{
    $map ??= structure_categorie_map();
    return array_values(array_filter($map, fn($r) => plan_pid($r['parent_id'] ?? null) === 0));
}

// Résout un id sélectionné dans le dropdown unique (catégorie OU sous-catégorie)
// en ['categorie' => ..., 'sous_categorie' => ...] — sous_categorie reste vide si
// une catégorie racine a été choisie directement.
function structure_categorie_champs(int $id, ?array $map = null): array
{
    $map ??= structure_categorie_map();
    if (!isset($map[$id])) {
        return ['categorie' => '', 'sous_categorie' => ''];
    }
    $noeud = $map[$id];
    $pid = plan_pid($noeud['parent_id'] ?? null);
    if ($pid === 0) {
        return ['categorie' => (string) $noeud['nom'], 'sous_categorie' => ''];
    }
    return ['categorie' => (string) ($map[$pid]['nom'] ?? ''), 'sous_categorie' => (string) $noeud['nom']];
}

// Id du nœud (catégorie ou sous-catégorie) correspondant à une paire
// categorie/sous_categorie texte — pour présélectionner le dropdown unique en
// modification d'une structure existante. 0 si non trouvé.
function structure_categorie_id_pour(string $categorie, string $sousCategorie, ?array $map = null): int
{
    $map ??= structure_categorie_map();
    foreach ($map as $id => $r) {
        $pid = plan_pid($r['parent_id'] ?? null);
        if ($sousCategorie !== '') {
            if ($pid !== 0 && (string) $r['nom'] === $sousCategorie && (string) ($map[$pid]['nom'] ?? '') === $categorie) {
                return (int) $id;
            }
        } elseif ($pid === 0 && (string) $r['nom'] === $categorie) {
            return (int) $id;
        }
    }
    return 0;
}

// Catégorie de repli si aucune n'est fournie/reconnue : la première catégorie
// racine (ordre configuré).
function structure_categorie_par_defaut(): string
{
    $racines = structure_categories_racines();
    return $racines ? (string) $racines[0]['nom'] : '';
}

// Noms des sous-catégories « booking » (Salle, Festival, Théâtre…), dans
// l'ordre configuré — remplace lieu_categories_liste() pour le sélecteur
// « Type » du formulaire de création rapide d'un lieu (carte « Lieux liés »).
function structure_sous_categories_booking_noms(): array
{
    return db()->query(
        "SELECT nom FROM structure_categories WHERE est_booking = 1 AND parent_id IS NOT NULL ORDER BY ordre, nom"
    )->fetchAll(PDO::FETCH_COLUMN);
}

// Intitulé canonique (casse de structure_categories) de la sous-catégorie
// « booking » correspondant à un nom (ex. l'ancien lieux.type), insensible à
// la casse en entrée. Chaîne vide si aucune sous-catégorie booking ne porte ce
// nom (le nom est alors laissé de côté par la fusion plutôt que d'écraser la
// sous-catégorie existante d'une structure avec une valeur non reconnue).
function structure_sous_categorie_booking_nom_pour(string $nom): string
{
    if (trim($nom) === '') {
        return '';
    }
    $stmt = db()->prepare(
        'SELECT nom FROM structure_categories WHERE nom = ? COLLATE NOCASE AND parent_id IS NOT NULL AND est_booking = 1 LIMIT 1'
    );
    $stmt->execute([$nom]);
    return (string) ($stmt->fetchColumn() ?: '');
}

// Nom de structure normalisé pour un rapprochement insensible à la casse, aux
// espaces et à la ponctuation (ex. « anti concert » ↔ « Anti-Concert ») — même
// principe que normaliser_nom_projet() (lib/evenements.php), dupliqué
// volontairement plutôt que partagé entre modules indépendants.
function normaliser_nom_structure(string $s): string
{
    return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim($s), 'UTF-8'));
}

// texte_sans_accents() a déménagé dans lib/helpers.php : c'est un utilitaire de
// chaîne générique, et lib/db.php l'expose désormais à SQLite (fonction
// SANS_ACCENTS, utilisée par la recherche unifiée). db.php ne peut pas dépendre
// de ce fichier-ci, qui n'est chargé qu'avec le module booking.

// --- Événements liés (table evenement_structures, voir migration_58/66) ----

// Événements rattachés à une STRUCTURE (evenement_structures — plus de
// distinction lieu/organisateur). Dédoublonné, plus récents d'abord.
function structure_evenements(int $structureId): array
{
    if (!module_actif('evenements')) {
        return [];
    }
    // projet = feuille rattachée à l'événement (evenements.projet_id,
    // toujours une feuille — voir projet_assignable()) ; projet_groupe =
    // son parent (l'artiste), si elle est imbriquée sous un groupe — sinon
    // NULL (projet autonome, pas de groupe). Voir views/structure.php,
    // carte « Événements » : affiche le groupe en priorité, la feuille en
    // second si distincte.
    $stmt = db()->prepare(
        'SELECT DISTINCT e.id, e.date, e.statut, e.ville, e.pays, e.departement_canton,
                e.salle, e.festival, sp.nom AS projet, spg.nom AS projet_groupe
         FROM evenements e
         JOIN evenement_structures es ON es.evenement_id = e.id
         LEFT JOIN projets sp ON sp.id = e.projet_id
         LEFT JOIN projets spg ON spg.id = sp.parent_id
         WHERE es.structure_id = :sid
         ORDER BY e.date DESC, e.id DESC'
    );
    $stmt->execute([':sid' => $structureId]);
    return $stmt->fetchAll();
}

// Nombre d'événements par structure (evenement_structures — plus de
// distinction lieu/organisateur), dédoublonné. Colonne « Événements » de
// ?p=structures. [id => n]. Inclut aussi les événements des structures
// qu'elle organise (structure_organisateurs, sens « organise » — voir
// structures_liste.php/structure_donnees_crm()) : sinon un organisateur dont
// les événements sont portés par ses salles/festivals liés (liés à eux dans
// evenement_structures, pas à lui) apparaîtrait à tort sans aucun événement.
function structures_nb_evenements(array $structureIds): array
{
    $ids = array_values(array_filter(array_map('intval', $structureIds)));
    if (!$ids || !module_actif('evenements')) {
        return [];
    }
    $in = implode(',', $ids);

    $organiseesParOrg = [];
    $stmtLiens = db()->query("SELECT organisateur_id, structure_id FROM structure_organisateurs WHERE organisateur_id IN ($in)");
    foreach ($stmtLiens as $l) {
        $organiseesParOrg[(int) $l['organisateur_id']][] = (int) $l['structure_id'];
    }

    $tousIds = $ids;
    foreach ($organiseesParOrg as $liees) {
        $tousIds = array_merge($tousIds, $liees);
    }
    $tousIds = array_values(array_unique($tousIds));
    $inTous = implode(',', $tousIds);

    // Événements de chaque structure impliquée (d'origine ou liée), pour
    // ensuite regrouper par structure d'origine.
    $eidsParSid = [];
    $sql = "SELECT structure_id AS sid, evenement_id AS eid FROM evenement_structures WHERE structure_id IN ($inTous)";
    foreach (db()->query($sql) as $r) {
        $eidsParSid[(int) $r['sid']][(int) $r['eid']] = true;
    }

    $out = [];
    foreach ($ids as $sid) {
        $eids = $eidsParSid[$sid] ?? [];
        foreach ($organiseesParOrg[$sid] ?? [] as $lieeId) {
            $eids += $eidsParSid[$lieeId] ?? [];
        }
        if ($eids) {
            $out[$sid] = count($eids);
        }
    }
    return $out;
}

// Recalcule et stocke structures.dernier_contact_le (colonne dénormalisée) :
// MAX() des notes marquées « contact » et des mailings envoyés avec succès.
// Appelée après chaque ajout de note-contact ou envoi de mailing (jamais à la
// volée à l'affichage, cf. docs/SPEC_BOOKING.md §6).
function structure_recalculer_dernier_contact(int $structureId): void
{
    $stmt = db()->prepare(
        "SELECT MAX(d) FROM (
            SELECT MAX(cree_le) AS d FROM historique WHERE entite_type = 'structure' AND entite_id = ? AND type = 'mailing'
            UNION ALL
            SELECT MAX(envoye_le) AS d FROM mailing_envois WHERE structure_id = ? AND succes = 1
        )"
    );
    $stmt->execute([$structureId, $structureId]);
    $date = (string) ($stmt->fetchColumn() ?: '');
    db()->prepare('UPDATE structures SET dernier_contact_le = ? WHERE id = ?')->execute([$date, $structureId]);
}

// Recalcule et stocke structures.dernier_concert_le (« dernier concert ou
// diffusion » — champ général, pas réservé aux lieux : une structure media/
// radio peut aussi l'utiliser pour dire qu'elle a déjà diffusé un titre, voir
// migration_59) : MAX() de la valeur déjà connue (saisie manuelle/import — la
// seule source pour une diffusion radio ou un concert antérieur à l'usage de
// l'appli) et des dates des événements que l'appli sait lui avoir rattachés
// (evenement_structures, voir migration_66 — plus de distinction lieu/
// organisateur). Ne fait donc que REMONTER la date, jamais la faire reculer.
// Appelée par evenement_resynchroniser_miroirs() (lib/evenements.php) après
// toute modification des structures liées d'un événement.
function structure_recalculer_dernier_concert(int $structureId): void
{
    $stmt = db()->prepare(
        "SELECT MAX(d) FROM (
            SELECT dernier_concert_le AS d FROM structures WHERE id = ?
            UNION ALL
            SELECT MAX(e.date) AS d FROM evenement_structures es JOIN evenements e ON e.id = es.evenement_id WHERE es.structure_id = ?
        )"
    );
    $stmt->execute([$structureId, $structureId]);
    $date = (string) ($stmt->fetchColumn() ?: '');
    db()->prepare('UPDATE structures SET dernier_concert_le = ? WHERE id = ?')->execute([$date, $structureId]);
}

// --- Historique typé unifié (structures + lieux, table historique, migr. 52) --

// Types d'entrées d'historique → libellé + icône Lucide (pour l'affichage).
const HISTORIQUE_TYPES = [
    'edition'         => ['Modification', 'pencil'],
    'note'            => ['Note', 'message-square'],
    'mailing'         => ['Contact / mailing', 'mail'],
    'dernier_concert' => ['Dernier concert', 'music'],
    // Type SYNTHÉTIQUE : aucune ligne de la table ne le porte, il est fabriqué
    // à l'affichage par historique_fusionne() à partir de structures.cree_le.
    'creation'        => ['Créée / importée', 'house-plus'],
];

// Icône et couleur d'une entrée d'historique, d'après ce qu'elle RACONTE et
// non seulement d'après son type. Un flux où tout « Modification » porte le
// même crayon ne se balaie pas : à l'œil, un changement de statut, une
// liaison de lieu et un ajout d'étiquette se ressemblent alors qu'ils n'ont
// rien à voir.
//
// La reconnaissance se fait sur le début du contenu, que ce même code écrit
// (journaliser_diff(), journaliser_lien_structure_lieu(), route_structure_statut()…) :
// c'est un rapprochement de chaînes, donc faillible si un libellé change — d'où
// le repli systématique sur l'icône du type, jamais d'entrée sans icône.
// Renvoie [icône, classe de couleur, libellé au survol].
function historique_icone(array $entree): array
{
    $type = (string) ($entree['type'] ?? '');
    $hi = HISTORIQUE_TYPES[$type] ?? ['Entrée', 'message-square'];
    $contenu = trim((string) ($entree['contenu'] ?? ''));
    $premiere = strtok($contenu, "\n") ?: '';

    // « Statut : Contact privilégié » → l'icône DU statut atteint, avec sa
    // couleur : c'est l'information, pas le fait qu'il y ait eu modification.
    if (preg_match('/^Statut\s*:\s*(.+)$/u', $premiere, $m)) {
        $libelle = trim($m[1]);
        // Les entrées d'avant migration_63 disent « active »/« inactive » : le
        // statut n'était alors qu'un booléen, doublé d'un « désinscrite du
        // mailing ». Elles restent en base telles quelles — c'est de
        // l'historique, on ne le récrit pas — donc c'est la lecture qui les
        // traduit. 34 entrées sur 34 dans la base actuelle, contre 3 sans ça.
        $ancien = ['active' => 'actif', 'inactive' => 'inactif'];
        $cleAncienne = $ancien[mb_strtolower($libelle)] ?? null;
        foreach (STRUCTURE_STATUTS_LIBELLES as $cle => $lib) {
            if ($lib === $libelle || $cle === $cleAncienne) {
                return [structure_statut_icone($cle), structure_statut_icone_classe($cle), 'Statut : ' . $lib];
            }
        }
    }
    // Une seule entrée par cas, dans l'ordre où elle est testée : les préfixes
    // « lié »/« délié » se ressemblent, le plus long d'abord.
    $prefixes = [
        'Lieu délié'          => ['unlink', 'Lieu délié'],
        'Organisateur délié'  => ['unlink', 'Organisateur délié'],
        'Lieu lié'            => ['link', 'Lieu lié'],
        'Organisateur lié'    => ['link', 'Organisateur lié'],
        'Tag ajouté'          => ['tag', 'Tag ajouté'],
        'Tag retiré'          => ['tag', 'Tag retiré'],
        // Entrées écrites quand ces libellés disaient « Étiquette » : elles
        // restent en base telles quelles — c'est de l'historique — donc c'est
        // la lecture qui les harmonise avec le mot employé aujourd'hui.
        'Étiquette ajoutée'   => ['tag', 'Tag ajouté'],
        'Étiquette retirée'   => ['tag', 'Tag retiré'],
        'Contact ajouté'      => ['user-plus', 'Contact ajouté'],
        'Contact modifié'     => ['user', 'Contact modifié'],
        'Contact supprimé'    => ['user', 'Contact supprimé'],
        'Désinscrite du mailing' => ['mail-x', 'Désinscrite du mailing'],
    ];
    foreach ($prefixes as $prefixe => [$icone, $libelle]) {
        if (str_starts_with($premiere, $prefixe)) {
            return [$icone, 'muted', $libelle];
        }
    }
    return [$hi[1], 'muted', $hi[0]];
}

// Ajoute une entrée d'historique pour une fiche ($entiteType : 'structure' |
// 'lieu'). $creeLe permet de dater l'entrée (import) ; sinon = maintenant.
//
// $personneId rattache l'entrée à QUI l'on a écrit, en plus d'à quelle
// structure. Une colonne de plus et non une seconde ligne : on retrouve ainsi
// le fil d'une personne qui change de salle, sans dédoubler l'historique d'une
// structure (docs/SPEC_PERSONNES.md § 6). Les entrées d'avant la migration, qui
// n'en ont pas, restent valides telles quelles.
function journaliser(string $entiteType, int $id, string $type, string $contenu = '', ?string $creeLe = null, ?int $personneId = null): void
{
    if ($id <= 0) {
        return;
    }
    $u = current_user();
    $uid = $u ? (int) $u['id'] : null;
    if ($creeLe !== null && $creeLe !== '') {
        db()->prepare('INSERT INTO historique (entite_type, entite_id, type, contenu, utilisateur_id, personne_id, cree_le) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$entiteType, $id, $type, $contenu, $uid, $personneId ?: null, $creeLe]);
    } else {
        db()->prepare('INSERT INTO historique (entite_type, entite_id, type, contenu, utilisateur_id, personne_id) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$entiteType, $id, $type, $contenu, $uid, $personneId ?: null]);
    }
}

// Journalise un « dernier contact » importé (entrée mailing datée du jour du
// contact), sans doublon : au ré-import, on ne réempile pas une entrée d'import
// de même date pour la même structure.
function journaliser_contact_import(int $structureId, string $dateIso, string $contenu): void
{
    if ($structureId <= 0 || $dateIso === '') {
        return;
    }
    $s = db()->prepare("SELECT 1 FROM historique WHERE entite_type = 'structure' AND entite_id = ? AND type = 'mailing' AND cree_le = ? AND contenu LIKE 'Import CSV — dernier contact%' LIMIT 1");
    $s->execute([$structureId, $dateIso]);
    if (!$s->fetchColumn()) {
        journaliser('structure', $structureId, 'mailing', $contenu, $dateIso);
    }
}

// Journalise une entrée « edition » avec le diff des champs modifiés
// ($champs : [colonne => libellé]). Ne fait rien si aucun champ n'a changé.
// Les valeurs vides sont affichées « (vide) ».
function journaliser_diff(string $entiteType, int $id, array $avant, array $apres, array $champs): void
{
    $lignes = [];
    foreach ($champs as $col => $label) {
        $a = trim((string) ($avant[$col] ?? ''));
        $b = trim((string) ($apres[$col] ?? ''));
        if ($a !== $b) {
            $lignes[] = $label . ' : ' . ($a !== '' ? $a : '(vide)') . ' → ' . ($b !== '' ? $b : '(vide)');
        }
    }
    if ($lignes) {
        journaliser($entiteType, $id, 'edition', implode("\n", $lignes));
    }
}

// Contenu de la cellule « Tags » d'une ligne de ?p=structures : les
// badges, leur croix de retrait, et le « + » d'ajout.
//
// Une seule fonction pour deux appelants — la vue, qui la rend pour chacune des
// 2959 lignes, et route_structure_tag_ajouter()/_retirer(), qui la renvoient en
// JSON après coup. Le balisage ne peut donc pas diverger entre le premier rendu
// et sa mise à jour.
//
// Les étiquettes sont PASSÉES, pas relues : la liste les agrège déjà en une
// requête pour toutes les lignes (tags_noms), et les rechercher ici ferait
// 2959 requêtes. structure_tags_paires() sert aux appelants qui n'en ont qu'une.
// Colonnes d'affichage d'une ligne de structure — étiquettes, contacts,
// structures liées, nombre de factures, adresse retenue. Ce sont celles que rend
// views/_structures_table.php, et elles sont écrites ICI plutôt que dans chaque
// requête : ?p=structures, la sélection d'une campagne et son suivi montrent le
// même tableau, il n'y a donc qu'une définition de ses colonnes.
//
// Sous-requêtes corrélées à l'alias `s`, qui doit être la PREMIÈRE table du FROM :
// le SQLite d'un hébergement mutualisé refuse qu'une sous-requête vise l'alias
// d'une table jointe plus loin (docs/DECISIONS.md § Le SQLite d'un hébergement
// mutualisé). L'appelant préfixe de « s.*, » s'il veut aussi la structure entière.
//
// Structures liées, DANS LES DEUX SENS (même principe que structure_donnees_crm()) :
// celles que la structure organise (sens='organise', ex. ses salles/festivals)
// et celle(s) qui l'organisent (sens='organise_par', si c'est elle-même un
// lieu) — fusionnées dans une seule colonne, affichage distingué par icône.
function structures_colonnes_liste_sql(): string
{
    return "(SELECT COUNT(*) FROM factures f WHERE f.structure_id = s.id) AS nb_factures,
        (SELECT GROUP_CONCAT(nom || char(31) || id || char(31) || sens, char(30)) FROM (
            SELECT l.nom AS nom, l.id AS id, 'organise' AS sens FROM structure_organisateurs so JOIN structures l ON l.id = so.structure_id WHERE so.organisateur_id = s.id
            UNION ALL
            SELECT o.nom AS nom, o.id AS id, 'organise_par' AS sens FROM structure_organisateurs so JOIN structures o ON o.id = so.organisateur_id WHERE so.structure_id = s.id
            ORDER BY nom
        )) AS structures_liees,
        -- Étiquettes par ordre alphabétique. GROUP_CONCAT n'a pas d'ordre
        -- garanti : il suit le plan d'exécution. On l'applique donc à une
        -- sous-requête triée, comme pour contacts_noms juste au-dessus.
        -- SANS_ACCENTS() et non COLLATE NOCASE : ce dernier ne replie que
        -- l'ASCII, et « À contacter… » se retrouvait donc après « Ne pas
        -- contacter » — un ordre alphabétique qui n'en est pas un en français.
        -- Le même tri est appliqué dans structure_tags_paires() (lib/booking.php)
        -- qui re-rend la cellule après un ajout : sans cela, l'ordre changerait
        -- sous les yeux au premier ajout d'étiquette.
        (SELECT GROUP_CONCAT(paire, char(30)) FROM (
            SELECT t.id || char(31) || t.nom || char(31) || COALESCE(t.couleur, '') AS paire
              FROM structure_tag_liens tl JOIN structure_tags t ON t.id = tl.tag_id
             WHERE tl.structure_id = s.id ORDER BY SANS_ACCENTS(t.nom)
        )) AS tags_noms,
        (SELECT GROUP_CONCAT(nom, char(30)) FROM (
            SELECT TRIM(pe.prenom || ' ' || pe.nom) AS nom FROM personne_structures cs JOIN personnes pe ON pe.id = cs.personne_id WHERE cs.structure_id = s.id AND TRIM(pe.prenom || ' ' || pe.nom) <> '' ORDER BY cs.actif DESC, cs.id
        )) AS contacts_noms,
        " . structure_email_sql() . " AS email_affiche,
        " . structure_formulaire_sql() . " AS formulaire_affiche";
}

// Les étiquettes d'une structure, en SQL, pour une MINI-FICHE : nom et couleur
// par étiquette, séparés par char(31), les étiquettes entre elles par char(30)
// — c'est ce que views/_structure_mini.php sait relire. Deux champs et non
// trois (la liste y ajoute l'id pour sa croix de retrait) : une mini-fiche ne
// retire pas d'étiquette, on le fait depuis la fiche de la structure.
//
// Fragment partagé plutôt que recopié, comme structure_email_sql() juste en
// dessous : les mini-fiches d'un événement et celles d'une personne doivent
// afficher exactement les mêmes étiquettes, dans le même ordre. SANS_ACCENTS()
// et non COLLATE NOCASE — ce dernier ne replie que l'ASCII, et « À contacter »
// se retrouverait après « Ne pas contacter ».
//
// $colStructureId : l'expression qui désigne la structure dans la requête
// appelante (« s.id », « structures.id »…), jamais une donnée d'utilisateur.
function structure_tags_mini_sql(string $colStructureId = 's.id'): string
{
    return "(SELECT GROUP_CONCAT(paire, char(30)) FROM (
                SELECT t.nom || char(31) || COALESCE(t.couleur, '') AS paire
                  FROM structure_tag_liens tl JOIN structure_tags t ON t.id = tl.tag_id
                 WHERE tl.structure_id = " . $colStructureId . " ORDER BY SANS_ACCENTS(t.nom)
            ))";
}

// L'e-mail qui REPRÉSENTE une structure, en SQL : celui du contact marqué
// « administration » s'il existe, sinon le premier contact qui en a un.
// Depuis migration_84, `structures` n'a plus de colonne email — l'adresse vit
// sur les contacts (structure_contacts), et plusieurs écrans ont besoin d'en
// montrer une seule. Fragment partagé plutôt que recopié : une requête restée
// sur l'ancienne colonne est un écran qui plante (c'était le cas de la liste
// d'exclusion du mailing).
//
// $colStructureId : l'expression qui désigne la structure dans la requête
// appelante (« s.id », « structures.id »…), jamais une donnée d'utilisateur.
function structure_email_sql(string $colStructureId = 's.id'): string
{
    return "COALESCE(
            (SELECT pe.email FROM personne_structures cs JOIN personnes pe ON pe.id = cs.personne_id WHERE cs.structure_id = $colStructureId AND cs.est_administration = 1 LIMIT 1),
            (SELECT pe.email FROM personne_structures cs JOIN personnes pe ON pe.id = cs.personne_id WHERE cs.structure_id = $colStructureId AND pe.email <> '' ORDER BY cs.id LIMIT 1)
        )";
}

// Le formulaire de contact d'une structure, en SQL — même forme et même ordre de
// préférence que l'adresse ci-dessus. Beaucoup d'interlocuteurs n'exposent pas
// d'adresse mais un formulaire sur leur site : une salle qui filtre ses
// propositions, une commune, une fondation qui veut son propre guichet. Sans
// lui, ces structures-là paraissent injoignables alors que le chemin existe.
//
// Un contact DÉSINSCRIT n'est pas écarté ici, contrairement à l'envoi d'un
// message : se désinscrire d'un mailing ne ferme pas le formulaire public du
// site, qui n'est pas à nous.
function structure_formulaire_sql(string $colStructureId = 's.id'): string
{
    return "COALESCE(
            (SELECT cs.formulaire_url FROM personne_structures cs WHERE cs.structure_id = $colStructureId AND cs.est_administration = 1 AND cs.formulaire_url <> '' LIMIT 1),
            (SELECT cs.formulaire_url FROM personne_structures cs WHERE cs.structure_id = $colStructureId AND cs.formulaire_url <> '' ORDER BY cs.id LIMIT 1),
            ''
        )";
}

// La même chose pour UNE structure déjà identifiée (sa fiche) : une requête,
// la chaîne vide s'il n'y en a pas.
function structure_formulaire_url(int $structureId): string
{
    $stmt = db()->prepare('SELECT ' . structure_formulaire_sql('?'));
    $stmt->execute([$structureId, $structureId]);
    return trim((string) $stmt->fetchColumn());
}

// Le bouton « Formulaire de contact » d'une structure : le lien externe
// générique (bouton_lien_externe_html(), lib/helpers.php) avec le libellé et
// l'infobulle de ce cas-là. Posé partout où l'on propose d'écrire à une
// structure — sa fiche, le suivi d'une campagne, le suivi d'une recherche de
// fonds.
//
// Ce n'est pas « Contacter » : rien ne part de l'application, donc rien ne
// s'inscrit dans l'historique. C'est au retour qu'on note le contact à la main
// (bouton « Marquer comme contacté »), et le libellé dit bien qu'on sort.
function bouton_formulaire_contact_html(string $url, array $opts = []): string
{
    return bouton_lien_externe_html($url, 'Formulaire de contact', $opts);
}

// Les mêmes colonnes, pour un lot d'identifiants déjà connus : une seule requête
// qui complète des lignes obtenues ailleurs (le ciblage d'une campagne passe par
// mailing_structures_eligibles(), qui ne rend que `structures`). Rendu : id => colonnes.
function structures_colonnes_liste(array $ids): array
{
    // En lots : le ciblage d'une campagne en compte des milliers, et lier un
    // paramètre par identifiant dépasserait le plafond de SQLite (lots_ids()).
    $out = [];
    foreach (lots_ids($ids) as $lot) {
        $stmt = db()->prepare(
            'SELECT s.id, ' . structures_colonnes_liste_sql() . ' FROM structures s WHERE s.id IN (' . sql_in($lot) . ')'
        );
        $stmt->execute($lot);
        foreach ($stmt->fetchAll() as $ligne) {
            $out[(int) $ligne['id']] = $ligne;
        }
    }
    return $out;
}

function structure_tags_cellule_html(int $structureId, array $paires, bool $peutEcrire): string
{
    $h = '';
    foreach ($paires as [$id, $nom, $couleur]) {
        $h .= '<span class="badge"' . badge_style_html((string) $couleur) . '>' . e((string) $nom);
        if ($peutEcrire) {
            $h .= '<button type="button" class="btn-tag-x" data-tag-retirer="' . (int) $id
                . '" title="Retirer ce tag" aria-label="Retirer ce tag">×</button>';
        }
        $h .= '</span> ';
    }
    // Pas de tiret quand il n'y a aucune étiquette : sur une colonne où la
    // plupart des cellules en portent une ou deux, une rangée de tirets attirait
    // l'œil sur ce qui n'existe pas. Une cellule vide se lit d'elle-même — et le
    // « + » reste là pour qui veut en ajouter une.
    if ($peutEcrire) {
        $h .= '<button type="button" class="badge tag-ajouter-btn" data-tag-structure="' . $structureId
            . '" title="Ajouter un tag" aria-label="Ajouter un tag">+</button>';
    }
    return $h;
}

// Étiquettes d'UNE structure, au format attendu par structure_tags_cellule_html().
function structure_tags_paires(int $structureId): array
{
    $stmt = db()->prepare(
        'SELECT t.id, t.nom, COALESCE(t.couleur, \'\') AS couleur
           FROM structure_tag_liens l JOIN structure_tags t ON t.id = l.tag_id
          WHERE l.structure_id = ? ORDER BY SANS_ACCENTS(t.nom)'
    );
    $stmt->execute([$structureId]);
    return array_map(fn ($r) => [(int) $r['id'], (string) $r['nom'], (string) $r['couleur']], $stmt->fetchAll());
}

// --- Le filtre « Lieu » -----------------------------------------------------
//
// Une seule question — « où ? » — au lieu des trois entonnoirs Pays, Région et
// Département/canton qu'elle remplace, et la ville en plus, qui n'était pas
// filtrable. Chaque valeur cochée est un JETON « niveau:pays|valeur » :
//
//     pays:Suisse            region:Suisse|Romandie
//     dept:France|69         ville:Suisse|Genève
//
// Le pays accompagne tout ce qui est sous lui : deux pays peuvent porter une
// région, un département ou une ville de même nom (une seule ville homonyme
// dans la base actuelle, mais le compte affiché sous Paramètres → Pays est déjà
// calculé sur le couple — voir lien_structures_pays()). La barre verticale n'a
// jamais à être échappée : aucune valeur n'en contient, et lieu_jeton() écarte
// celles qui en contiendraient.
//
// Les jetons sont unis en OU, contrairement aux trois entonnoirs d'avant qui se
// combinaient en ET : dans une liste unique, cocher « Suisse » puis « Lyon »
// demande manifestement les deux, pas leur intersection — qui serait vide.
const LIEU_NIVEAUX = [
    'pays'   => 'Pays',
    'region' => 'Région',
    'dept'   => 'Département / canton',
    'ville'  => 'Ville',
];

// Colonne de `structures` correspondant à chaque niveau.
const LIEU_COLONNES = [
    'pays'   => 'adresse_pays',
    'region' => 'grande_region',
    'dept'   => 'departement_canton',
    'ville'  => 'adresse_localite',
];

function lieu_jeton(string $niveau, string $pays, string $valeur): string
{
    if (!isset(LIEU_NIVEAUX[$niveau]) || $valeur === '' || str_contains($valeur, '|') || str_contains($pays, '|')) {
        return '';
    }
    return $niveau === 'pays' ? 'pays:' . $valeur : $niveau . ':' . $pays . '|' . $valeur;
}

// ['niveau', 'pays', 'valeur'] ou null si le jeton ne dit rien de lisible —
// une URL bricolée à la main, un niveau disparu.
function lieu_decoder(string $jeton): ?array
{
    $p = strpos($jeton, ':');
    if ($p === false) {
        return null;
    }
    $niveau = substr($jeton, 0, $p);
    $reste = substr($jeton, $p + 1);
    if (!isset(LIEU_NIVEAUX[$niveau]) || $reste === '') {
        return null;
    }
    if ($niveau === 'pays') {
        return ['niveau' => 'pays', 'pays' => $reste, 'valeur' => $reste];
    }
    $b = strpos($reste, '|');
    if ($b === false || $b === 0 || $b === strlen($reste) - 1) {
        return null;
    }
    return ['niveau' => $niveau, 'pays' => substr($reste, 0, $b), 'valeur' => substr($reste, $b + 1)];
}

// La condition SQL d'un lot de jetons, et ses paramètres. Les jetons illisibles
// sont ignorés ; si aucun ne tient debout, la condition est vide et le filtre ne
// s'applique pas — mieux vaut tout montrer qu'une liste vide inexplicable.
// $alias est celui de la table `structures` dans la requête appelante.
function lieu_where(array $jetons, string $alias = 's'): array
{
    $conds = [];
    $params = [];
    foreach ($jetons as $jeton) {
        $l = lieu_decoder((string) $jeton);
        if (!$l) {
            continue;
        }
        $col = $alias . '.' . LIEU_COLONNES[$l['niveau']];
        if ($l['niveau'] === 'pays') {
            $conds[] = "$col = ?";
            $params[] = $l['valeur'];
            continue;
        }
        $conds[] = "($alias." . LIEU_COLONNES['pays'] . ' = ? AND ' . $col . ' = ?)';
        $params[] = $l['pays'];
        $params[] = $l['valeur'];
    }
    return $conds ? ['(' . implode(' OR ', $conds) . ')', $params] : ['', []];
}

// Tous les lieux connus des structures, par niveau :
//   ['pays' => [jeton => ['libelle', 'pays', 'n']], 'region' => …]
// Une seule requête, groupée sur les quatre colonnes. $niveaux restreint le
// travail — la liste des villes (plus de quinze cents) n'est pas écrite dans la
// page, elle est demandée à part quand on ouvre le panneau.
function lieux_options(array $niveaux = ['pays', 'region', 'dept', 'ville']): array
{
    $out = array_fill_keys($niveaux, []);
    $sql = 'SELECT adresse_pays AS pays, grande_region AS region, departement_canton AS dept,
                   adresse_localite AS ville, COUNT(*) AS n
              FROM structures WHERE adresse_pays <> ""
             GROUP BY adresse_pays, grande_region, departement_canton, adresse_localite';
    foreach (db()->query($sql) as $l) {
        foreach ($niveaux as $niveau) {
            $valeur = trim((string) $l[$niveau]);
            // « - » est la valeur que certains imports ont laissée pour « rien » :
            // ce n'est pas un lieu, et l'offrir en option ne filtrerait rien d'utile.
            if ($valeur === '' || $valeur === '-') {
                continue;
            }
            $jeton = lieu_jeton($niveau, (string) $l['pays'], $valeur);
            if ($jeton === '') {
                continue;
            }
            $out[$niveau][$jeton] ??= ['libelle' => $valeur, 'pays' => (string) $l['pays'], 'n' => 0];
            $out[$niveau][$jeton]['n'] += (int) $l['n'];
        }
    }
    foreach ($out as &$liste) {
        uasort($liste, fn ($a, $b) => strcoll($a['libelle'], $b['libelle']) ?: strcmp($a['pays'], $b['pays']));
    }
    unset($liste);
    return $out;
}

// Lien vers ?p=structures filtré sur UNE valeur (une catégorie, une étiquette,
// un pays, une région), tous les autres filtres remis à zéro — pour les
// compteurs « N structures » des trois écrans de Paramètres → Catégories, qui
// doivent mener à exactement ces N fiches.
//
// Deux pièges que ce lien contournait mal :
//   - filtre_coche() n'accepte une valeur de l'URL que si le marqueur « _set »
//     l'accompagne ; sans lui elle relit la SESSION. Un lien « categorie_id=27 »
//     était donc purement ignoré, et la liste s'ouvrait sur le dernier filtre en
//     date (mesuré : 383 fiches affichées au lieu de 75).
//   - les filtres sont mémorisés en session : ne poser que la catégorie
//     laisserait les autres actifs, et le compte annoncé ne serait pas celui
//     obtenu. Chaque filtre est donc explicitement vidé — « _set » présent avec
//     une valeur vide, ce que filtre_coche() interprète comme « aucun ».
//
// « statut » vidé et non laissé par défaut : la page Paramètres compte TOUTES
// les structures, y compris inactives, et son lien doit montrer les mêmes.
function lien_structures_filtre(array $filtres): string
{
    $params = ['p' => 'structures'];
    foreach (['categorie_id', 'statut', 'lieu', 'tag_id',
              'campagne_id', 'avec_evenements', 'contact_periode', 'maj_periode'] as $f) {
        $params[$f . '_set'] = 1;
        if (isset($filtres[$f])) {
            $params[$f] = $filtres[$f];
        }
    }
    // Jauge et mois ne sont pas des cases à cocher : filtre_persistant() les
    // écrase dès que la clé est présente en GET, même vide.
    foreach (['lieu_jauge_min', 'lieu_jauge_max'] as $f) {
        $params[$f] = '';
    }
    $params['lieu_mois_evenement'] = 0;
    $params['lieu_mois_prog'] = 0;
    $params['q'] = '';
    return '?' . http_build_query($params);
}

function lien_structures_categorie(int $categorieId): string
{
    return lien_structures_filtre(['categorie_id' => [$categorieId]]);
}

function lien_structures_tag(int $tagId): string
{
    return lien_structures_filtre(['tag_id' => [$tagId]]);
}

// Un pays, ou une région DE ce pays : un seul jeton de l'entonnoir « Lieu »
// (lieu_jeton()), qui porte déjà le couple — deux pays peuvent nommer
// pareillement une région, et le compte affiché à côté du lien est lui-même
// calculé sur le couple (voir route_pays()).
function lien_structures_pays(string $pays, string $region = ''): string
{
    $jeton = $region !== '' ? lieu_jeton('region', $pays, $region) : lieu_jeton('pays', $pays, $pays);
    return lien_structures_filtre($jeton !== '' ? ['lieu' => [$jeton]] : []);
}

// Compte de structures affiché sous une entrée de paramètres — étiquette,
// catégorie, pays ou région. Même forme sur les trois écrans (celle des
// étiquettes, la plus ancienne) : un texte discret, cliquable quand la liste
// des structures est accessible. $vide porte l'accord du mot (« inutilisé »
// pour un pays, « inutilisée » pour une étiquette).
function compte_structures_html(int $nb, string $lien = '', string $vide = 'inutilisée'): string
{
    if ($nb <= 0) {
        return '<span class="muted small">' . e($vide) . '</span>';
    }
    $txt = $nb . ' structure' . ($nb > 1 ? 's' : '');
    // ?p=pays est un écran « cœur » : son visiteur peut très bien
    // n'avoir aucun droit sur le booking. Le compte reste (il sert aussi à la
    // réaffectation avant suppression), mais pas le lien vers une liste qui lui
    // serait refusée.
    if ($lien === '' || !module_accessible('booking')) {
        return '<span class="muted small">' . $txt . '</span>';
    }
    return '<a class="muted small compte-structures" href="' . e($lien) . '" title="Voir ces structures">' . $txt . '</a>';
}

// Renomme une étiquette. Renvoie false si le nom est vide ou déjà pris par une
// autre (unicité insensible à la casse, comme à la création) — l'appelant en
// fait ce qu'il veut : message d'erreur ou silence.
// Le lien structure↔étiquette porte l'id, pas le nom : renommer suffit, il n'y
// a rien à propager. Partagée par Paramètres → Tags et par le filtre
// « Tags » de ?p=structures, pour que les deux appliquent la même règle.
function tag_renommer(int $id, string $nom): bool
{
    $nom = trim($nom);
    if ($id <= 0 || $nom === '') {
        return false;
    }
    $conflit = db()->prepare('SELECT 1 FROM structure_tags WHERE nom = ? COLLATE NOCASE AND id <> ?');
    $conflit->execute([$nom, $id]);
    if ($conflit->fetchColumn()) {
        return false;
    }
    db()->prepare('UPDATE structure_tags SET nom = ? WHERE id = ?')->execute([$nom, $id]);
    return true;
}

// Supprime une étiquette. Les liens structure↔étiquette tombent en cascade
// (structure_tag_liens ON DELETE CASCADE, voir migration_46) : aucune structure
// n'est supprimée, elles perdent seulement l'étiquette.
function tag_supprimer(int $id): void
{
    if ($id > 0) {
        db()->prepare('DELETE FROM structure_tags WHERE id = ?')->execute([$id]);
    }
}

// Nom d'une structure ou d'un lieu (table en liste blanche). '' si introuvable.
function nom_entite(string $table, int $id): string
{
    $t = $table === 'lieux' ? 'lieux' : 'structures';
    $s = db()->prepare("SELECT nom FROM $t WHERE id = ?");
    $s->execute([$id]);
    return (string) ($s->fetchColumn() ?: '');
}

// Journalise, DES DEUX CÔTÉS, la liaison/déliaison entre une structure et son
// lieu organisé ($lieuId — structures(id) depuis la fusion lieux→structures,
// migration_59/60 : les deux id sont désormais des structures).
function journaliser_lien_structure_lieu(int $structureId, int $lieuId, bool $lie): void
{
    $sNom = nom_entite('structures', $structureId);
    $lNom = nom_entite('structures', $lieuId);
    journaliser('structure', $structureId, 'edition', ($lie ? 'Lieu lié : ' : 'Lieu délié : ') . $lNom);
    journaliser('structure', $lieuId, 'edition', ($lie ? 'Organisateur lié : ' : 'Organisateur délié : ') . $sNom);
}

// Historique d'une fiche, plus récent d'abord, avec l'auteur — et, le cas
// échéant, qui l'a modifié en dernier (migration_96).
//
// L'e-mail est ramené avec le nom parce qu'il en tient lieu quand le compte n'en
// a pas (utilisateur_nom_affiche()) : sans lui, une note écrite par un compte
// sans prénom ni nom s'affichait sans auteur.
function historique_entite(string $entiteType, int $id): array
{
    if ($id <= 0) {
        return [];
    }
    $stmt = db()->prepare(
        // ⚠️ coalesce(personne, compte) : un compte rattaché à une personne n'a
        // plus de prénom/nom à lui (utilisateur_nom()), et l'historique aurait
        // signé ses notes de l'adresse e-mail.
        "SELECT h.*,
                coalesce(nullif(trim(pu.prenom),''), u.prenom) AS u_prenom,
                coalesce(nullif(trim(pu.nom),''), u.nom)       AS u_nom,
                u.email AS u_email,
                coalesce(nullif(trim(pm.prenom),''), m.prenom) AS m_prenom,
                coalesce(nullif(trim(pm.nom),''), m.nom)       AS m_nom,
                m.email AS m_email
         FROM historique h
         LEFT JOIN utilisateurs u ON u.id = h.utilisateur_id
         LEFT JOIN personnes pu   ON pu.id = u.personne_id
         LEFT JOIN utilisateurs m ON m.id = h.modifie_par
         LEFT JOIN personnes pm   ON pm.id = m.personne_id
         WHERE h.entite_type = ? AND h.entite_id = ?
         ORDER BY h.cree_le DESC, h.id DESC"
    );
    $stmt->execute([$entiteType, $id]);
    $entrees = $stmt->fetchAll();
    // Projets de chaque entrée, en UNE requête : c'est ce qui rattache une
    // prise de contact à une campagne, et ce que la fiche affiche.
    $parEntree = [];
    foreach (lots_ids(array_map(fn ($e) => (int) $e['id'], $entrees)) as $lot) {
        $stmtS = db()->prepare(
            'SELECT hs.historique_id, hs.projet_id, sp.nom
               FROM historique_projets hs JOIN projets sp ON sp.id = hs.projet_id
              WHERE hs.historique_id IN (' . sql_in($lot) . ') ORDER BY sp.nom'
        );
        $stmtS->execute($lot);
        foreach ($stmtS as $l) {
            $parEntree[(int) $l['historique_id']][(int) $l['projet_id']] = (string) $l['nom'];
        }
    }
    foreach ($entrees as &$e) {
        $e['projets'] = $parEntree[(int) $e['id']] ?? [];
    }
    unset($e);
    return $entrees;
}

// Historique FUSIONNÉ pour l'affichage : celui de la fiche + celui des lieux
// qu'elle organise (structure_organisateurs, depuis la fusion lieux→structures,
// migration_59/60), trié dans le temps. Chaque entrée porte 'source_label'
// (vide = la fiche elle-même, sinon « Lieu « X » »).
function historique_fusionne(string $entiteType, int $id): array
{
    $entrees = [];
    foreach (historique_entite($entiteType, $id) as $e) {
        $e['source_label'] = '';
        $entrees[] = $e;
    }
    if ($entiteType === 'structure') {
        $stmt = db()->prepare('SELECT l.id, l.nom FROM structures l JOIN structure_organisateurs so ON so.structure_id = l.id WHERE so.organisateur_id = ?');
        $stmt->execute([$id]);
        foreach ($stmt->fetchAll() as $l) {
            foreach (historique_entite('structure', (int) $l['id']) as $e) {
                $e['source_label'] = 'Lieu « ' . (string) $l['nom'] . ' »';
                $entrees[] = $e;
            }
        }
    }
    // La création de la fiche ouvre le récit : c'est le premier acte, et sans
    // elle le flux commence au milieu de nulle part. Elle n'est PAS une ligne
    // de la table historique (structures.cree_le est la seule trace, et les
    // fiches importées n'ont jamais eu d'entrée), elle est donc synthétisée à
    // l'affichage — d'où l'id 0, qui la tient hors de tout ce qui se modifie.
    // Elle se range à sa date, donc en bas d'une liste antichronologique.
    if ($entiteType === 'structure') {
        $stmt = db()->prepare('SELECT cree_le FROM structures WHERE id = ?');
        $stmt->execute([$id]);
        $creeLe = (string) ($stmt->fetchColumn() ?: '');
        if ($creeLe !== '') {
            $entrees[] = [
                'id' => 0, 'entite_type' => 'structure', 'entite_id' => $id,
                'type' => 'creation', 'contenu' => 'Fiche créée ou importée',
                'cree_le' => $creeLe, 'utilisateur_id' => null,
                'u_prenom' => '', 'u_nom' => '', 'u_email' => '',
                'modifie_par' => null, 'modifie_le' => '',
                'm_prenom' => '', 'm_nom' => '', 'm_email' => '',
                'source_label' => '',
            ];
        }
    }
    usort($entrees, fn ($a, $b) => [(string) $b['cree_le'], (int) $b['id']] <=> [(string) $a['cree_le'], (int) $a['id']]);
    return $entrees;
}

// Fusionne $autres dans $idGarde : le profil (nom, adresse, catégorie…) de
// $idGarde est conservé tel quel — seules les relations (contacts, notes,
// mailing, factures, tags, lieux liés, événements liés) des structures
// fusionnées sont reprises, puis les structures fusionnées sont supprimées.
// Contacts/notes/mailing/factures : simple réaffectation (pas de contrainte
// d'unicité). Tags/lieux/événements : clé (composite ou UNIQUE) — copie de ce
// qui manque (INSERT OR IGNORE/UPDATE OR IGNORE) puis suppression des
// anciennes lignes, pour ne jamais entrer en conflit avec un tag/lieu/
// événement déjà présent sur $idGarde.
function structures_fusionner(int $idGarde, array $autres): void
{
    $autres = array_values(array_unique(array_diff(array_map('intval', $autres), [$idGarde])));
    if (!$autres) {
        return;
    }
    $in = sql_in($autres);

    // Transaction : la fusion enchaîne une dizaine de mutations (réaffectations
    // + suppressions) ; une erreur en cours de route laisserait des relations à
    // moitié reprises et des structures fusionnées non supprimées, sans retour
    // arrière possible.
    db()->beginTransaction();
    foreach (['personne_structures', 'mailing_envois', 'mailing_file_attente', 'factures'] as $table) {
        db()->prepare("UPDATE $table SET structure_id = ? WHERE structure_id IN ($in)")
            ->execute(array_merge([$idGarde], $autres));
    }
    // Historique unifié (schéma entite_type/entite_id, migr. 52) — reprend les
    // entrées des structures fusionnées vers celle conservée.
    db()->prepare("UPDATE historique SET entite_id = ? WHERE entite_type = 'structure' AND entite_id IN ($in)")
        ->execute(array_merge([$idGarde], $autres));

    // Événements liés (evenement_structures, migration_66) : reprend les
    // structures fusionnées vers celle conservée. Contrainte UNIQUE
    // (evenement_id, structure_id) : un même événement peut déjà être lié à
    // $idGarde ET à une structure fusionnée — dans ce cas UPDATE OR IGNORE
    // laisse la ligne fusionnée inchangée (conflit), supprimée ensuite comme
    // doublon désormais inutile.
    $stmtEvts = db()->prepare("SELECT DISTINCT evenement_id FROM evenement_structures WHERE structure_id IN ($in)");
    $stmtEvts->execute($autres);
    $evenementIds = array_map('intval', $stmtEvts->fetchAll(PDO::FETCH_COLUMN));
    if ($evenementIds) {
        // Remonte le marquage « à facturer » (référence pré-remplissage
        // facture/export SUISA) avant de fusionner les liens : sinon perdu si
        // l'événement était déjà aussi lié à $idGarde (ligne fusionnée alors
        // simplement supprimée par le doublon ci-dessous, sans transférer son
        // marquage).
        $stmtFacturation = db()->prepare("SELECT evenement_id FROM evenement_structures WHERE structure_id IN ($in) AND est_facturation = 1");
        $stmtFacturation->execute($autres);
        $updFacturation = db()->prepare('UPDATE evenement_structures SET est_facturation = 1 WHERE evenement_id = ? AND structure_id = ?');
        foreach ($stmtFacturation->fetchAll(PDO::FETCH_COLUMN) as $evId) {
            $updFacturation->execute([(int) $evId, $idGarde]);
        }

        db()->prepare("UPDATE OR IGNORE evenement_structures SET structure_id = ? WHERE structure_id IN ($in)")
            ->execute(array_merge([$idGarde], $autres));
        db()->prepare("DELETE FROM evenement_structures WHERE structure_id IN ($in)")->execute($autres);
    }

    $stmtTags = db()->prepare("SELECT DISTINCT tag_id FROM structure_tag_liens WHERE structure_id IN ($in)");
    $stmtTags->execute($autres);
    $insTag = db()->prepare('INSERT OR IGNORE INTO structure_tag_liens (structure_id, tag_id) VALUES (?, ?)');
    foreach ($stmtTags->fetchAll(PDO::FETCH_COLUMN) as $tagId) {
        $insTag->execute([$idGarde, $tagId]);
    }
    db()->prepare("DELETE FROM structure_tag_liens WHERE structure_id IN ($in)")->execute($autres);

    // structure_organisateurs (many-to-many auto-référencé, remplace
    // structure_lieux depuis la fusion lieux→structures, migration_59/60/61) :
    // reprend les deux sens — les lieux organisés PAR une structure fusionnée,
    // et les organisateurs D'un lieu fusionné. Jamais de boucle sur soi-même
    // (contrainte CHECK structure_id <> organisateur_id).
    $stmtOrgaLieux = db()->prepare("SELECT DISTINCT structure_id FROM structure_organisateurs WHERE organisateur_id IN ($in)");
    $stmtOrgaLieux->execute($autres);
    $insOrgaLieu = db()->prepare('INSERT OR IGNORE INTO structure_organisateurs (structure_id, organisateur_id) VALUES (?, ?)');
    foreach ($stmtOrgaLieux->fetchAll(PDO::FETCH_COLUMN) as $lieuId) {
        if ($lieuId !== $idGarde) {
            $insOrgaLieu->execute([$lieuId, $idGarde]);
        }
    }
    $stmtOrgaOrgs = db()->prepare("SELECT DISTINCT organisateur_id FROM structure_organisateurs WHERE structure_id IN ($in)");
    $stmtOrgaOrgs->execute($autres);
    $insOrgaOrg = db()->prepare('INSERT OR IGNORE INTO structure_organisateurs (structure_id, organisateur_id) VALUES (?, ?)');
    foreach ($stmtOrgaOrgs->fetchAll(PDO::FETCH_COLUMN) as $organisateurId) {
        if ($organisateurId !== $idGarde) {
            $insOrgaOrg->execute([$idGarde, $organisateurId]);
        }
    }
    db()->prepare("DELETE FROM structure_organisateurs WHERE structure_id IN ($in)")->execute($autres);
    db()->prepare("DELETE FROM structure_organisateurs WHERE organisateur_id IN ($in)")->execute($autres);

    db()->prepare("DELETE FROM structures WHERE id IN ($in)")->execute($autres);
    db()->commit();
    structure_recalculer_dernier_contact($idGarde);
    // Recale evenements.organisateur_structure_id (référence facture/SUISA)
    // et structures.dernier_concert_le pour $idGarde sur les événements
    // repris ci-dessus.
    foreach ($evenementIds as $evenementId) {
        evenement_resynchroniser_miroirs($evenementId);
    }
}
