<?php
// Module booking : CRM des structures (salles, festivals, médias, entourage).
// Fonctions partagées par les routes (lib/routes_booking.php) : dernier contact
// dérivé, normalisation de nom pour l'import, filtrage des destinataires de
// mailing (réutilisé par l'aperçu et par la constitution de la file d'attente,
// voir SPEC_BOOKING.md §6/§7).

declare(strict_types=1);

require_once __DIR__ . '/compta.php'; // plan_pid()/plan_enfants()/plan_est_feuille()/plan_liste_ordonnee() (arbre générique id/parent_id/ordre)

// Statut unique d'une structure (structures.statut, migration_63 — remplace
// actif+desinscrit) : « contact_privilegie » (prioritaire, jamais déduit
// automatiquement d'un import/backfill — uniquement une action manuelle sur
// la fiche), « actif », « ne_pas_contacter » (active mais désinscrite du
// mailing), « inactif ». Ordre = ordre d'affichage du sélecteur segmenté
// (structure_statut_toggle_html(), lib/helpers.php) et des filtres/bulk
// (?p=structures). « actif »/« contact_privilegie » comptent pour éligibles
// au mailing (mailing_structures_eligibles()) ; « ne_pas_contacter »/
// « inactif » en sont toujours exclus.
const STRUCTURE_STATUTS = ['contact_privilegie', 'actif', 'ne_pas_contacter', 'inactif'];

const STRUCTURE_STATUTS_LIBELLES = [
    'contact_privilegie' => 'Contact privilégié',
    'actif'               => 'Actif',
    'ne_pas_contacter'    => 'Ne pas contacter',
    'inactif'             => 'Inactif',
];

const STRUCTURE_STATUTS_ICONES = [
    'contact_privilegie' => 'heart',
    'actif'               => 'circle-check',
    'ne_pas_contacter'    => 'circle-x',
    'inactif'             => 'circle-dashed',
];

// Classe CSS de couleur de l'icône (assets/app.css) — utilisée par la colonne
// « Statut » de ?p=structures (icône seule, voir views/structures_liste.php).
const STRUCTURE_STATUTS_CLASSES_ICONE = [
    'contact_privilegie' => 'ico-pink',
    'actif'               => 'ico-ok',
    'ne_pas_contacter'    => 'ico-danger',
    'inactif'             => 'muted',
];

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

// Catégorie CRM d'une structure (booking), voir SPEC_BOOKING.md §5.
// Configurable (Paramètres → Catégories). Une sous-catégorie
// est nécessairement imbriquée dans une catégorie (structure_categories.parent_id,
// même principe que spectacles/groupe-spectacle — voir lib/evenements.php) : une
// catégorie racine a parent_id NULL, une sous-catégorie a pour parent_id l'id
// d'une catégorie racine (2 niveaux max, imposé par l'UI plutôt que le schéma).
// structures.categorie/sous_categorie restent des colonnes texte (comparaison
// par nom) — renommer une catégorie/sous-catégorie dans les paramètres met à
// jour les structures existantes en même temps (voir route_parametres_structures()).

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
// (parametres_structures, voir route_parametres_structures()).
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

// Noms des catégories racines — utilisé pour valider structures.categorie
// (import CSV, formulaire, filtres, bulk change).
function structure_categories_noms(?array $map = null): array
{
    return array_column(structure_categories_racines($map), 'nom');
}

// Noms de toutes les sous-catégories (tous parents confondus) — utilisé pour
// valider structures.sous_categorie (filtres).
function structure_sous_categories_noms(?array $map = null): array
{
    $map ??= structure_categorie_map();
    $noms = [];
    foreach ($map as $r) {
        if (plan_pid($r['parent_id'] ?? null) !== 0) {
            $noms[] = (string) $r['nom'];
        }
    }
    return $noms;
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

// Une sous-catégorie est-elle marquée « booking » (décrit un lieu où on peut
// faire des concerts — Salle, Festival, Théâtre… — voir migration_59) ?
// Résolution par nom, insensible à la casse : sert notamment à qualifier la
// sous-catégorie d'une structure au moment de l'import (structure_lier_lieu_importe()
// devenu obsolète) et à la fusion lieux→structures (lib/dev.php).
function structure_sous_categorie_est_booking(string $nom): bool
{
    if (trim($nom) === '') {
        return false;
    }
    $stmt = db()->prepare(
        'SELECT est_booking FROM structure_categories WHERE nom = ? COLLATE NOCASE AND parent_id IS NOT NULL LIMIT 1'
    );
    $stmt->execute([$nom]);
    return (bool) $stmt->fetchColumn();
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
// principe que normaliser_nom_spectacle() (lib/evenements.php), dupliqué
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
    // spectacle = feuille rattachée à l'événement (evenements.spectacle_id,
    // toujours une feuille — voir spectacle_assignable()) ; spectacle_groupe =
    // son parent (l'artiste), si elle est imbriquée sous un groupe — sinon
    // NULL (spectacle autonome, pas de groupe). Voir views/structure_form.php,
    // carte « Événements » : affiche le groupe en priorité, la feuille en
    // second si distincte.
    $stmt = db()->prepare(
        'SELECT DISTINCT e.id, e.date, e.statut, e.ville, sp.nom AS spectacle, spg.nom AS spectacle_groupe
         FROM evenements e
         JOIN evenement_structures es ON es.evenement_id = e.id
         LEFT JOIN spectacles sp ON sp.id = e.spectacle_id
         LEFT JOIN spectacles spg ON spg.id = sp.parent_id
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
// volée à l'affichage, cf. SPEC_BOOKING.md §6).
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
        'Étiquette ajoutée'   => ['tag', 'Étiquette ajoutée'],
        'Étiquette retirée'   => ['tag', 'Étiquette retirée'],
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
function journaliser(string $entiteType, int $id, string $type, string $contenu = '', ?string $creeLe = null): void
{
    if ($id <= 0) {
        return;
    }
    $u = current_user();
    if ($creeLe !== null && $creeLe !== '') {
        db()->prepare('INSERT INTO historique (entite_type, entite_id, type, contenu, utilisateur_id, cree_le) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$entiteType, $id, $type, $contenu, $u ? (int) $u['id'] : null, $creeLe]);
    } else {
        db()->prepare('INSERT INTO historique (entite_type, entite_id, type, contenu, utilisateur_id) VALUES (?, ?, ?, ?, ?)')
            ->execute([$entiteType, $id, $type, $contenu, $u ? (int) $u['id'] : null]);
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

// Contenu de la cellule « Étiquettes » d'une ligne de ?p=structures : les
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
function structure_tags_cellule_html(int $structureId, array $paires, bool $peutEcrire): string
{
    $h = '';
    foreach ($paires as [$id, $nom, $couleur]) {
        $h .= '<span class="badge"' . badge_style_html((string) $couleur) . '>' . e((string) $nom);
        if ($peutEcrire) {
            $h .= '<button type="button" class="btn-tag-x" data-tag-retirer="' . (int) $id
                . '" title="Retirer cette étiquette" aria-label="Retirer cette étiquette">×</button>';
        }
        $h .= '</span> ';
    }
    // Pas de tiret quand il n'y a aucune étiquette : sur une colonne où la
    // plupart des cellules en portent une ou deux, une rangée de tirets attirait
    // l'œil sur ce qui n'existe pas. Une cellule vide se lit d'elle-même — et le
    // « + » reste là pour qui veut en ajouter une.
    if ($peutEcrire) {
        $h .= '<button type="button" class="badge tag-ajouter-btn" data-tag-structure="' . $structureId
            . '" title="Ajouter une étiquette" aria-label="Ajouter une étiquette">+</button>';
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
    foreach (['categorie_id', 'statut', 'pays', 'departement_canton', 'tag_id',
              'avec_evenements', 'contact_periode', 'maj_periode'] as $f) {
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
    // « region » n'est pas une case à cocher mais un filtre d'appoint porté par
    // l'URL seule (voir structures_filtres()) : son absence suffit à l'éteindre,
    // il n'a donc pas de marqueur « _set » à poser.
    if (isset($filtres['region'])) {
        $params['region'] = $filtres['region'];
    }
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

// Un pays : filtre « pays » ordinaire. Une région : le pays PLUS la région,
// car deux pays peuvent porter une région homonyme et le compte affiché est
// lui-même calculé sur le couple (voir route_parametres_pays()).
function lien_structures_pays(string $pays, string $region = ''): string
{
    $filtres = ['pays' => [$pays]];
    if ($region !== '') {
        $filtres['region'] = $region;
    }
    return lien_structures_filtre($filtres);
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
    // ?p=parametres_pays est un écran « cœur » : son visiteur peut très bien
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
// a rien à propager. Partagée par Paramètres → Étiquettes et par le filtre
// « Étiquettes » de ?p=structures, pour que les deux appliquent la même règle.
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

// Nombre de structures portant une étiquette — ce que l'écran annonce avant de
// confirmer une suppression.
function tag_nb_structures(int $id): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM structure_tag_liens WHERE tag_id = ?');
    $stmt->execute([$id]);
    return (int) $stmt->fetchColumn();
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

// Historique d'une fiche, plus récent d'abord, avec l'auteur.
function historique_entite(string $entiteType, int $id): array
{
    if ($id <= 0) {
        return [];
    }
    $stmt = db()->prepare(
        "SELECT h.*, u.prenom AS u_prenom, u.nom AS u_nom
         FROM historique h LEFT JOIN utilisateurs u ON u.id = h.utilisateur_id
         WHERE h.entite_type = ? AND h.entite_id = ?
         ORDER BY h.cree_le DESC, h.id DESC"
    );
    $stmt->execute([$entiteType, $id]);
    return $stmt->fetchAll();
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
                'u_prenom' => '', 'u_nom' => '', 'source_label' => '',
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
    foreach (['structure_contacts', 'mailing_envois', 'mailing_file_attente', 'factures'] as $table) {
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

// Un mois de festival (mois_debut..mois_fin, ex. 6..9) chevauche-t-il la plage
// filtrée (ex. avril=4..septembre=9) ? Gère le passage d'année (ex. nov.=11 à
// fév.=2) en testant les deux découpages possibles.
function periode_chevauche(int $debut, int $fin, int $filtreDebut, int $filtreFin): bool
{
    $moisDans = function (int $mois, int $d, int $f): bool {
        return $d <= $f ? ($mois >= $d && $mois <= $f) : ($mois >= $d || $mois <= $f);
    };
    for ($m = $debut; ; $m = $m % 12 + 1) {
        if ($moisDans($m, $filtreDebut, $filtreFin)) {
            return true;
        }
        if ($m === $fin) {
            break;
        }
    }
    return false;
}

// Filtre les structures éligibles à un mailing (aperçu ET constitution réelle
// de la file d'attente doivent utiliser cette même fonction, cf. §7 — jamais
// deux implémentations divergentes). $criteres (voir mailing_criteres_depuis()) :
// categorie_id (int[], arbre catégorie/sous-catégorie — mêmes ids que le
// filtre de ?p=structures, une catégorie racine choisie inclut tout son
// sous-arbre, résolu ici via structure_categorie_champs() comme dans
// structures_filtres()), pays/grande_region/departement_canton/ville
// (string[]), tag_id (int[]), mois_debut/mois_fin (int|null, période de
// programmation des lieux liés), mois_evenement_debut/fin (int|null, période
// des événements), contact_jamais (bool), contact_avant (date string|'').
// Exclut toujours les statuts ne_pas_contacter/inactif, en dernière étape,
// non contournable.
function mailing_structures_eligibles(array $criteres): array
{
    $where = ["s.statut IN ('actif','contact_privilegie')"];
    $params = [];

    // Catégorie/sous-catégorie : une condition par id coché, unies en OR —
    // même logique que structures_filtres() dans lib/routes_facturation.php.
    if (!empty($criteres['categorie_id'])) {
        $map = structure_categorie_map();
        $catConds = [];
        foreach ($criteres['categorie_id'] as $cid) {
            $champs = structure_categorie_champs((int) $cid, $map);
            if ($champs['categorie'] === '') {
                continue;
            }
            if ($champs['sous_categorie'] === '') {
                $catConds[] = 's.categorie = ?';
                $params[] = $champs['categorie'];
            } else {
                $catConds[] = '(s.categorie = ? AND s.sous_categorie = ?)';
                $params[] = $champs['categorie'];
                $params[] = $champs['sous_categorie'];
            }
        }
        if ($catConds) {
            $where[] = '(' . implode(' OR ', $catConds) . ')';
        }
    }
    if (!empty($criteres['pays'])) {
        $where[] = 's.adresse_pays IN (' . sql_in($criteres['pays']) . ')';
        $params = array_merge($params, $criteres['pays']);
    }
    // grande_region = « Région » (Normandie, Romandie…) ; departement_canton = « Département / canton ».
    if (!empty($criteres['grande_region'])) {
        $where[] = 's.grande_region IN (' . sql_in($criteres['grande_region']) . ')';
        $params = array_merge($params, $criteres['grande_region']);
    }
    if (!empty($criteres['departement_canton'])) {
        $where[] = 's.departement_canton IN (' . sql_in($criteres['departement_canton']) . ')';
        $params = array_merge($params, $criteres['departement_canton']);
    }
    if (!empty($criteres['ville'])) {
        $where[] = 's.adresse_localite IN (' . sql_in($criteres['ville']) . ')';
        $params = array_merge($params, $criteres['ville']);
    }
    if (!empty($criteres['tag_id'])) {
        $where[] = 's.id IN (SELECT structure_id FROM structure_tag_liens WHERE tag_id IN (' . sql_in($criteres['tag_id']) . '))';
        $params = array_merge($params, $criteres['tag_id']);
    }
    if (!empty($criteres['contact_jamais'])) {
        $where[] = "s.dernier_contact_le = ''";
    } elseif (!empty($criteres['contact_avant'])) {
        $where[] = "(s.dernier_contact_le = '' OR s.dernier_contact_le < ?)";
        $params[] = (string) $criteres['contact_avant'];
    }

    $sql = 'SELECT s.* FROM structures s WHERE ' . implode(' AND ', $where) . ' ORDER BY s.nom';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $structures = $stmt->fetchAll();

    // Filtres de période — appliqués en PHP (pas en SQL) car le chevauchement
    // gère le passage d'année (periode_chevauche()), pas exprimable simplement
    // en SQL. Deux plages, directement sur la structure depuis la fusion
    // lieux→structures (migration_59/60) : mois_debut/mois_fin = « Préparé
    // de… à » (période de programmation), mois_evenement_debut/fin =
    // « Événements de… à » (quand ça a lieu).
    $filtrePeriode = function (array $structures, string $colDebut, string $colFin, int $md, int $mf): array {
        return array_values(array_filter($structures, function ($s) use ($colDebut, $colFin, $md, $mf) {
            if ($s[$colDebut] === null || $s[$colFin] === null) {
                return false;
            }
            return periode_chevauche((int) $s[$colDebut], (int) $s[$colFin], $md, $mf);
        }));
    };
    if (!empty($criteres['mois_debut']) && !empty($criteres['mois_fin'])) {
        $structures = $filtrePeriode($structures, 'mois_debut', 'mois_fin', (int) $criteres['mois_debut'], (int) $criteres['mois_fin']);
    }
    if (!empty($criteres['mois_evenement_debut']) && !empty($criteres['mois_evenement_fin'])) {
        $structures = $filtrePeriode($structures, 'mois_evenement_debut', 'mois_evenement_fin', (int) $criteres['mois_evenement_debut'], (int) $criteres['mois_evenement_fin']);
    }

    return $structures;
}

// Destinataires réels d'un mailing pour les structures éligibles : un contact
// actif et non désinscrit par structure (email renseigné), ou à défaut
// l'e-mail de la structure elle-même (si renseigné et la structure n'a pas de
// contact exploitable) — jamais un contact désinscrit même si la structure ne
// l'est pas (opt-out par contact, §4).
function mailing_destinataires(array $criteres): array
{
    $structures = mailing_structures_eligibles($criteres);
    // Liste d'exclusion « ne pas contacter » (mailing_exclusions) : chargée une
    // fois, appliquée en dernier — non contournable, y compris sur l'e-mail de
    // repli de la structure.
    $exclus = [];
    foreach (db()->query('SELECT email FROM mailing_exclusions') as $x) {
        $exclus[mb_strtolower(trim((string) $x['email']), 'UTF-8')] = true;
    }
    $estExclu = fn (string $e): bool => isset($exclus[mb_strtolower(trim($e), 'UTF-8')]);
    $destinataires = [];
    foreach ($structures as $s) {
        $stmt = db()->prepare(
            "SELECT * FROM structure_contacts
             WHERE structure_id = ? AND actif = 1 AND desinscrit = 0 AND email <> ''
             ORDER BY id"
        );
        $stmt->execute([(int) $s['id']]);
        $contacts = $stmt->fetchAll();
        // Plusieurs contacts et au moins un marqué « booking » : mailing réservé à
        // ceux-là (voir la fiche structure) — sinon, comportement inchangé
        // (envoi à tous les contacts actifs de la structure).
        $booking = array_values(array_filter($contacts, fn ($c) => (int) $c['est_booking'] === 1));
        if ($booking) {
            $contacts = $booking;
        }
        if ($contacts) {
            foreach ($contacts as $c) {
                if (!$estExclu((string) $c['email'])) {
                    $destinataires[] = ['structure' => $s, 'contact' => $c, 'email' => $c['email']];
                }
            }
        } elseif ($s['email'] !== '' && !$estExclu((string) $s['email'])) {
            $destinataires[] = ['structure' => $s, 'contact' => null, 'email' => $s['email']];
        }
    }
    return $destinataires;
}

// Résout les variables {{prenom}}/{{nom_structure}} d'un gabarit de mailing.
function mailing_personnaliser(string $texte, array $structure, ?array $contact): string
{
    return str_replace(
        ['{{prenom}}', '{{nom_structure}}'],
        [$contact['prenom'] ?? '', $structure['nom'] ?? ''],
        $texte
    );
}

// ------------------------------------------------------- IMPORT CSV (carnets d'adresses)
// Champs importables → libellé affiché dans l'écran de correspondance des
// colonnes (§8 de SPEC_BOOKING.md). Seul « nom » est obligatoire.
const STRUCTURE_IMPORT_CHAMPS = [
    'nom'              => 'Nom de la structure',
    'categorie'        => 'Catégorie (organisateur/média/autres/entourage)',
    'sous_categorie'   => 'Sous-catégorie (ex. Journaliste, Salle de concert…)',
    'type_lieu'        => 'Type de lieu (salle, festival, salle de location…)',
    'adresse_rue'      => 'Adresse (rue)',
    'adresse_npa'      => 'NPA',
    'adresse_localite' => 'Localité',
    'departement_canton' => 'Département / canton',
    'grande_region'    => 'Région (Normandie, Romandie, Acadie…)',
    'pays'             => 'Pays',
    'periode_evenement'     => 'Période de l\'événement (mois, ex. « 6 7 8 » ou « jun jul aug »)',
    'periode_programmation' => 'Période de programmation (mois, ex. « 1 2 3 » ou « jan feb mar »)',
    'dernier_concert'  => 'Dernier concert ou diffusion (date, JJ/MM/AAAA)',
    'site_web'         => 'Site web',
    'via'              => 'Via (source du contact)',
    'notes'            => 'Notes',
    'prenom'           => 'Prénom (contact)',
    'nom_contact'      => 'Nom (contact)',
    'role'             => 'Rôle (contact)',
    'email_contact'    => 'E-mail (contact)',
    'telephone'        => 'Téléphone (contact)',
    'formulaire_url'   => 'Formulaire (contact)',
    'langue'           => 'Langue (contact)',
    'dernier_contact'  => 'Dernier contact (date, JJ/MM/AAAA)',
    'mise_a_jour'      => 'Dernière mise à jour (date, JJ/MM/AAAA)',
    'jauge_min'        => 'Jauge min (salle/festival)',
    'jauge_max'        => 'Jauge max (salle/festival)',
    'tags_statut'      => 'Étiquettes (colonne codée, ex. « & E T »)',
];

// Dictionnaire des symboles de statut utilisés dans certains carnets
// d'adresses (ex. colonne « Statut » d'un ancien outil de mailing) — une
// cellule peut contenir plusieurs symboles séparés par des espaces (ex.
// « & E T »), chacun devient une étiquette distincte. Sensible à la casse :
// 'x'/'xx' (minuscule) et 'X'/'XX' (majuscule) ont des sens différents selon
// les carnets sources, tous deux couverts ici.
const STRUCTURE_IMPORT_TAGS_LEGENDE = [
    '>'    => 'À contacter',
    '...>' => 'À contacter bientôt',
    '>>'   => 'À contacter vite',
    'T'    => 'À contacter en cas de tournée',
    'E'    => 'À contacter en cas d\'événement',
    '<'    => 'Contacté',
    '<<'   => 'Relancé',
    '<<<'  => 'Méga-relancé',
    '&'    => 'Actif',
    'x'    => 'Ne pas contacter (trop gros, autre style)',
    'xx'   => 'Inactif - fermé',
    'XX'   => 'Inactif - fermé',
    '...'  => 'Ne pas contacter pour l\'instant',
    'N'    => 'Réponse négative',
    '!'    => 'Contact privilégié - éviter les mailings',
    'PP'   => 'Trop gros - premières parties',
    '@'    => 'Problème adresse e-mail',
    'D'    => 'Désinscrit',
    'X'    => 'Ne pas contacter (autre raison)',
    'C'    => 'À informer des dates de concert',
    '?'    => 'Info à chercher',
];

// Découpe un CSV brut en [en-tête (noms de colonnes bruts), lignes (tableaux
// de valeurs brutes)] — sans mapping, juste la structure du fichier (étape 1
// de l'import, avant correspondance manuelle des colonnes).
//
// Lecture par flux (fgetcsv), jamais par découpage du texte brut sur les
// retours à la ligne : un champ entre guillemets peut légitimement contenir
// un retour à la ligne (cellule multi-ligne d'un export Excel/LibreOffice,
// ex. une adresse ou une note sur plusieurs lignes) — un pré-découpage
// regex couperait ce champ au mauvais endroit et décalerait toute la suite
// du fichier. fgetcsv() sait nativement où s'arrête un enregistrement même
// quand il s'étend sur plusieurs lignes physiques.
function structures_lire_csv(string $csv): array
{
    $csv = (string) preg_replace('/^\xEF\xBB\xBF/', '', $csv); // BOM UTF-8 (export Excel)
    if (trim($csv) === '') {
        return [[], []];
    }
    // Délimiteur détecté sur la seule première ligne physique (l'en-tête n'a jamais
    // de guillemets/retours à la ligne à ce stade, contrairement aux lignes de données).
    $delim = csv_detecter_delimiteur(substr($csv, 0, strcspn($csv, "\r\n")));

    $flux = fopen('php://memory', 'r+');
    fwrite($flux, $csv);
    rewind($flux);
    $lignesBrutes = [];
    while (($ligne = fgetcsv($flux, 0, $delim, '"', '')) !== false) {
        if ($ligne === [null]) {
            continue; // ligne blanche entre deux enregistrements
        }
        $lignesBrutes[] = $ligne;
    }
    fclose($flux);
    if (!$lignesBrutes) {
        return [[], []];
    }
    $entete = array_shift($lignesBrutes);
    return [$entete, $lignesBrutes];
}

// Date CSV JJ/MM/AAAA → ISO AAAA-MM-JJ, ou null si absente/invalide (jamais devinée).
function structure_date_csv_vers_iso(string $s): ?string
{
    $s = trim($s);
    if ($s === '') {
        return null;
    }
    // Numéro de série Excel/LibreOffice (date exportée « brute » : nombre de jours
    // depuis le 30/12/1899, éventuellement avec une fraction horaire). Plage
    // prudente [15000, 60000] ≈ 1941–2064 pour ne pas confondre avec un autre
    // nombre (ex. une jauge). 25569 = décalage entre l'époque Excel et Unix.
    if (preg_match('#^(\d{5})(?:\.\d+)?$#', $s, $m)) {
        $serial = (int) $m[1];
        if ($serial >= 15000 && $serial <= 60000) {
            return gmdate('Y-m-d', ($serial - 25569) * 86400);
        }
    }
    if (preg_match('#^(\d{4})-(\d{1,2})-(\d{1,2})$#', $s, $m)) {
        // Déjà au format ISO (AAAA-MM-JJ).
        [, $annee, $mois, $jour] = $m;
    } elseif (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2}|\d{4})$#', $s, $m)) {
        // JJ/MM/AAAA, JJ.MM.AA, JJ-MM-AAAA… (séparateurs / . -, année sur 2 ou 4
        // chiffres). Année sur 2 chiffres : pivot à 70 (00–69 → 20xx, 70–99 → 19xx).
        [, $jour, $mois, $annee] = $m;
        if (strlen($annee) === 2) {
            $annee = ((int) $annee <= 69 ? 2000 : 1900) + (int) $annee;
        }
    } else {
        return null;
    }
    if (!checkdate((int) $mois, (int) $jour, (int) $annee)) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', (int) $annee, (int) $mois, (int) $jour);
}

// Décode une cellule « Statut » codée (ex. « & E T ») en libellés d'étiquette
// (STRUCTURE_IMPORT_TAGS_LEGENDE) — un symbole par groupe d'espaces, chaque
// symbole reconnu devient une étiquette ; les symboles non reconnus sont
// ignorés (jamais devinés). Retourne des libellés dédupliqués.
function structure_tags_depuis_statut(string $cellule): array
{
    $tokens = preg_split('/\s+/', trim($cellule), -1, PREG_SPLIT_NO_EMPTY);
    $labels = [];
    foreach ($tokens as $t) {
        if (isset(STRUCTURE_IMPORT_TAGS_LEGENDE[$t])) {
            $labels[STRUCTURE_IMPORT_TAGS_LEGENDE[$t]] = true;
        }
    }
    return array_keys($labels);
}

// Trouve ou crée un structure_tags par nom (insensible à la casse) et
// l'attache à la structure — même logique que route_structure_tag_ajouter().
function structure_attacher_tag(int $structureId, string $nomTag): void
{
    $stmt = db()->prepare('SELECT id FROM structure_tags WHERE nom = ? COLLATE NOCASE');
    $stmt->execute([$nomTag]);
    $tagId = $stmt->fetchColumn();
    if ($tagId === false) {
        db()->prepare('INSERT INTO structure_tags (nom) VALUES (?)')->execute([$nomTag]);
        $tagId = (int) db()->lastInsertId();
    }
    db()->prepare('INSERT OR IGNORE INTO structure_tag_liens (structure_id, tag_id) VALUES (?, ?)')
        ->execute([$structureId, (int) $tagId]);
}

// Structure existante correspondant à une ligne importée : e-mail de contact
// exact (prioritaire) sinon nom normalisé (casse/espaces/ponctuation ignorés).
function structure_trouver_correspondance(string $nom, string $emailContact): ?int
{
    if ($emailContact !== '') {
        $stmt = db()->prepare('SELECT structure_id FROM structure_contacts WHERE email = ? COLLATE NOCASE LIMIT 1');
        $stmt->execute([$emailContact]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
    }
    $nomNorm = normaliser_nom_structure($nom);
    if ($nomNorm === '') {
        return null;
    }
    foreach (db()->query('SELECT id, nom FROM structures') as $s) {
        if (normaliser_nom_structure($s['nom']) === $nomNorm) {
            return (int) $s['id'];
        }
    }
    return null;
}

// Mémorise, pour le prochain import, la correspondance choisie sous forme de
// NOMS de colonnes (pas d'index : l'ordre des colonnes peut changer d'un fichier
// à l'autre). Enregistrée dans les paramètres (champ → intitulé de colonne).
function structure_import_memoriser_mapping(array $mapping, array $entete): void
{
    $noms = [];
    foreach ($mapping as $champ => $idx) {
        if ($idx !== null && isset($entete[$idx])) {
            $nom = trim((string) $entete[$idx]);
            if ($nom !== '') {
                $noms[$champ] = $nom;
            }
        }
    }
    db()->prepare('INSERT OR REPLACE INTO parametres (cle, valeur) VALUES (?, ?)')
        ->execute(['import_structures_mapping_noms', json_encode($noms, JSON_UNESCAPED_UNICODE)]);
}

// Propose une correspondance (champ → index de colonne) pour un nouveau fichier,
// à partir des noms de colonnes mémorisés au dernier import : un champ est
// pré-sélectionné si l'intitulé mémorisé se retrouve dans l'en-tête courant
// (comparaison insensible à la casse et aux espaces). Champs absents = ignorés.
function structure_import_mapping_suggere(array $entete): array
{
    $brut = (string) param('import_structures_mapping_noms', '');
    $noms = $brut !== '' ? json_decode($brut, true) : null;
    if (!is_array($noms)) {
        return [];
    }
    $parNom = [];
    foreach ($entete as $i => $col) {
        $parNom[mb_strtolower(trim((string) $col), 'UTF-8')] = (int) $i;
    }
    $suggere = [];
    foreach ($noms as $champ => $nom) {
        $k = mb_strtolower(trim((string) $nom), 'UTF-8');
        if (isset($parNom[$k]) && isset(STRUCTURE_IMPORT_CHAMPS[$champ])) {
            $suggere[$champ] = $parNom[$k];
        }
    }
    return $suggere;
}

// Convertit un jeton de mois (numéro « 6 », nom « juin », abréviation « juil »)
// en numéro de mois 1–12, ou null si non reconnu.
function mois_token_vers_numero(string $tok): ?int
{
    $tok = trim($tok);
    if ($tok === '') {
        return null;
    }
    if (ctype_digit($tok)) {
        $n = (int) $tok;
        return ($n >= 1 && $n <= 12) ? $n : null;
    }
    $t = mb_strtolower($tok, 'UTF-8');
    $t = strtr($t, ['é' => 'e', 'ê' => 'e', 'è' => 'e', 'ë' => 'e', 'û' => 'u', 'ù' => 'u', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'à' => 'a', 'â' => 'a', 'ç' => 'c']);
    // Codes anglais à 3 lettres (jan feb mar…) — plusieurs ne sont pas des
    // préfixes du nom français (feb≠fev, apr≠avr, jun≠jui, aug≠aou), d'où la
    // table explicite. Prioritaire sur la déduction par préfixe français.
    $anglais = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
                'jul' => 7, 'aug' => 8, 'sep' => 9, 'sept' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
    if (isset($anglais[$t])) {
        return $anglais[$t];
    }
    $noms = ['janvier', 'fevrier', 'mars', 'avril', 'mai', 'juin', 'juillet', 'aout', 'septembre', 'octobre', 'novembre', 'decembre'];
    foreach ($noms as $i => $nom) {
        if ($t === $nom) {
            return $i + 1;
        }
    }
    // Abréviation : le jeton (≥ 3 lettres) est un préfixe du nom du mois. « jui »
    // est ambigu (juin/juillet) → on retient le premier (juin) ; « juil » lève
    // l'ambiguïté. Assez robuste pour un champ saisi à la main.
    if (mb_strlen($t, 'UTF-8') >= 3) {
        foreach ($noms as $i => $nom) {
            if (str_starts_with($nom, $t)) {
                return $i + 1;
            }
        }
    }
    return null;
}

// Convertit un champ « mois séparés par des espaces » (ex. « 6 7 8 », « nov déc
// jan fév ») en une plage [début, fin] compatible avec les colonnes mois_*.
// Gère le passage d'année : la plage démarre juste après le plus grand écart
// circulaire entre mois cochés (ex. {11,12,1,2} → début 11, fin 2). Renvoie
// ['debut' => ?int, 'fin' => ?int] (null/null si aucun mois reconnu).
function mois_plage_depuis_liste(string $champ): array
{
    $nums = [];
    foreach (preg_split('/[^0-9A-Za-zÀ-ÿ]+/u', trim($champ)) ?: [] as $tok) {
        $n = mois_token_vers_numero((string) $tok);
        if ($n !== null) {
            $nums[$n] = true;
        }
    }
    $nums = array_keys($nums);
    sort($nums);
    $c = count($nums);
    if ($c === 0) {
        return ['debut' => null, 'fin' => null];
    }
    if ($c === 1) {
        return ['debut' => $nums[0], 'fin' => $nums[0]];
    }
    if ($c === 12) {
        return ['debut' => 1, 'fin' => 12];
    }
    $bestGap = -1;
    $startIdx = 0;
    for ($i = 0; $i < $c; $i++) {
        $gap = ($nums[($i + 1) % $c] - $nums[$i] + 12) % 12;
        if ($gap > $bestGap) {
            $bestGap = $gap;
            $startIdx = ($i + 1) % $c;
        }
    }
    return ['debut' => $nums[$startIdx], 'fin' => $nums[($startIdx - 1 + $c) % $c]];
}

// Applique le mapping (champ → index de colonne CSV) à chaque ligne, et
// détecte les correspondances existantes. Ne modifie rien (analyse pure) —
// réutilisée à la fois pour afficher l'écran de résolution des conflits et
// pour appliquer l'import (mêmes données, pas de re-parsing divergent).
// Résout la catégorie de STRUCTURE d'une ligne d'import contre la taxonomie
// complète (insensible casse + accents, indexes fournis pour rester pur/testable) :
//   1. la valeur est une catégorie racine     → cette racine ;
//   2. la valeur est une sous-catégorie        → sa catégorie parente, et la
//      sous-catégorie est renseignée si vide (sauf si cette valeur est en fait
//      un type de lieu déjà routé vers le lieu, $typeCat non vide) ;
//   3. sinon                                    → la catégorie par défaut.
// $racines : clé pliée → nom racine. $subs : clé pliée → ['parent'=>…, 'nom'=>…].
// Corrige le bug « tout ce qui n'est pas une racine finit dans Organisateur »
// (ex. une colonne catégorie contenant « Radio », « Journaliste », « Média »).
function structure_import_resoudre_categorie(string $cat, string $sous, string $typeCat, array $racines, array $subs, string $defaut): array
{
    $cle = texte_sans_accents($cat);
    if (isset($racines[$cle])) {
        return ['categorie' => $racines[$cle], 'sous_categorie' => $sous];
    }
    if (isset($subs[$cle])) {
        $nouvSous = ($sous === '' && $typeCat === '') ? $subs[$cle]['nom'] : $sous;
        return ['categorie' => $subs[$cle]['parent'], 'sous_categorie' => $nouvSous];
    }
    return ['categorie' => $defaut, 'sous_categorie' => $sous];
}

function structures_analyser_import(array $lignes, array $mapping): array
{
    $col = function (array $r, string $champ) use ($mapping): string {
        $i = $mapping[$champ] ?? null;
        return $i !== null && $i !== '' && isset($r[(int) $i]) ? trim((string) $r[(int) $i]) : '';
    };

    // Préchargements UNE seule fois (l'analyse tourne à chaque étape de l'import
    // sur potentiellement des milliers de lignes) : sans ces index, chaque ligne
    // re-scannait toute la table structures et relançait plusieurs requêtes de
    // catégorie — coût quadratique. Ici : deux requêtes groupées + des tableaux.
    $indexNom = [];   // nom normalisé → [ ['id'=>…, 'ville'=>ville normalisée], … ]
    $indexEmail = []; // e-mail (minuscule) → id de structure existante (1er gagnant)
    foreach (db()->query('SELECT id, nom, adresse_localite FROM structures') as $s) {
        $cle = normaliser_nom_structure((string) $s['nom']);
        if ($cle !== '') {
            $indexNom[$cle][] = [
                'id'    => (int) $s['id'],
                'ville' => normaliser_nom_structure((string) ($s['adresse_localite'] ?? '')),
            ];
        }
    }
    foreach (db()->query("SELECT email, structure_id FROM structure_contacts WHERE email <> ''") as $c) {
        $cle = mb_strtolower(trim((string) $c['email']), 'UTF-8');
        if ($cle !== '' && !isset($indexEmail[$cle])) {
            $indexEmail[$cle] = (int) $c['structure_id'];
        }
    }
    // Taxonomie des structures, indexée par clé « pliée » (minuscule sans accents)
    // pour un rapprochement tolérant à l'import : racines, sous-catégories (→ parent).
    $catMap = structure_categorie_map();
    $catsRacines = []; // clé pliée → nom racine canonique
    $catsSub = [];     // clé pliée → ['parent' => nom racine, 'nom' => nom sous-cat]
    foreach ($catMap as $r) {
        $nom = (string) $r['nom'];
        if (plan_pid($r['parent_id'] ?? null) === 0) {
            $catsRacines[texte_sans_accents($nom)] = $nom;
        }
    }
    foreach ($catMap as $r) {
        $pid = plan_pid($r['parent_id'] ?? null);
        if ($pid !== 0 && isset($catMap[$pid])) {
            $catsSub[texte_sans_accents((string) $r['nom'])] = [
                'parent' => (string) $catMap[$pid]['nom'],
                'nom'    => (string) $r['nom'],
            ];
        }
    }
    $catDefaut = structure_categorie_par_defaut();
    $lieuCats = []; // clé pliée → nom canonique (sous-catégorie « booking »)
    foreach (structure_sous_categories_booking_noms() as $n) {
        $lieuCats[texte_sans_accents((string) $n)] = (string) $n;
    }
    $normLieuCat = fn (string $v): string => $lieuCats[texte_sans_accents($v)] ?? '';

    $analyse = [];
    foreach ($lignes as $index => $r) {
        $donnees = [];
        foreach (array_keys(STRUCTURE_IMPORT_CHAMPS) as $champ) {
            $donnees[$champ] = $col($r, $champ);
        }
        if ($donnees['nom'] === '') {
            continue; // ligne sans nom : ignorée silencieusement (pas assez d'information)
        }
        // Type de lieu (salle de concert, festival, saison culturelle…). Priorité
        // à la colonne dédiée « type_lieu » si elle est mappée (normalisée sur la
        // taxonomie booking — structure_categories.est_booking, voir migration_59
        // —, valeur libre conservée sinon) ; à défaut, détecté sur les valeurs
        // BRUTES de catégorie / sous-catégorie AVANT normalisation. La valeur part
        // sur structures.sous_categorie (voir structure_appliquer_champs_booking_importe())
        // et n'encombre pas le champ catégorie/sous-catégorie « organisation » ;
        // la sous-catégorie qui n'était qu'un type de lieu est vidée.
        $typeMappeRaw = trim((string) ($donnees['type_lieu'] ?? ''));
        $typeSous = $normLieuCat($donnees['sous_categorie']);
        $typeCat  = $normLieuCat($donnees['categorie']);
        if ($typeMappeRaw !== '') {
            $donnees['type_lieu'] = structure_sous_categorie_booking_nom_pour($typeMappeRaw) ?: $typeMappeRaw;
        } else {
            $donnees['type_lieu'] = $typeSous !== '' ? $typeSous : $typeCat;
        }
        if ($typeSous !== '') {
            $donnees['sous_categorie'] = '';
        }
        // La catégorie a-t-elle été RÉELLEMENT fournie par l'import ? (avant
        // résolution, qui applique sinon une catégorie par défaut). Sert à la
        // fusion : sans catégorie fournie, on ne doit pas la compter comme un
        // conflit face à la catégorie existante (on garde celle de la base).
        $donnees['categorie_fournie'] = trim((string) $donnees['categorie']) !== ''
            || trim((string) $donnees['sous_categorie']) !== '';
        // Catégorie de structure : racine, sinon sous-catégorie remontée à son
        // parent, sinon défaut (insensible casse + accents) — voir le helper.
        $res = structure_import_resoudre_categorie(
            $donnees['categorie'], $donnees['sous_categorie'], $typeCat, $catsRacines, $catsSub, $catDefaut
        );
        $donnees['categorie'] = $res['categorie'];
        $donnees['sous_categorie'] = $res['sous_categorie'];

        // Correspondance avec l'existant : e-mail exact (prioritaire, identifiant
        // fort) sinon nom normalisé — MAIS discriminé par la VILLE : deux
        // structures homonymes de villes différentes ne doivent JAMAIS être
        // rapprochées (sinon on fusionne à tort deux lieux distincts). Règle sur
        // le nom : ville renseignée → seule une homonyme de la même ville matche ;
        // ville absente → on ne rapproche que s'il n'y a aucune ambiguïté (un
        // seul homonyme). Via les index préchargés, sans requête par ligne.
        $correspondanceId = null;
        $email = mb_strtolower(trim($donnees['email_contact']), 'UTF-8');
        if ($email !== '' && isset($indexEmail[$email])) {
            $correspondanceId = $indexEmail[$email];
        } else {
            $nomNorm = normaliser_nom_structure($donnees['nom']);
            $villeNorm = normaliser_nom_structure((string) ($donnees['adresse_localite'] ?? ''));
            $candidats = ($nomNorm !== '') ? ($indexNom[$nomNorm] ?? []) : [];
            if ($villeNorm !== '') {
                foreach ($candidats as $cand) {
                    if ($cand['ville'] === $villeNorm) {
                        $correspondanceId = $cand['id'];
                        break;
                    }
                }
            } elseif (count($candidats) === 1) {
                $correspondanceId = $candidats[0]['id'];
            }
        }
        $structureExistante = null;
        if ($correspondanceId) {
            $stmt = db()->prepare('SELECT * FROM structures WHERE id = ?');
            $stmt->execute([$correspondanceId]);
            $structureExistante = $stmt->fetch() ?: null;
        }
        $analyse[] = [
            'index' => $index,
            'donnees' => $donnees,
            'correspondance_id' => $correspondanceId,
            'structure_existante' => $structureExistante,
        ];
    }
    return $analyse;
}

// Cœur : pour une liste de paires ['categorie' => racine, 'sous' => sous-cat],
// crée dans la taxonomie (structure_categories) les sous-catégories manquantes —
// ex. « Blog » sous « Media » — pour qu'elles figurent dans Paramètres →
// Catégories. Seulement sous une catégorie RACINE connue, sans doublon
// (insensible casse + accents ; contrainte UNIQUE(parent_id, nom)). L'ordre suit
// le max existant du même parent. Renvoie le nombre de sous-catégories créées.
function structures_taxonomie_assurer(array $paires): int
{
    $aCreer = structures_taxonomie_a_creer($paires);
    if (!$aCreer) {
        return 0;
    }
    // Ordre : à la suite des sous-catégories déjà présentes sous le même parent.
    $maxOrdre = [];
    foreach (structure_categorie_map() as $r) {
        $pid = plan_pid($r['parent_id'] ?? null);
        $maxOrdre[$pid] = max($maxOrdre[$pid] ?? 0, (int) $r['ordre']);
    }
    $ins = db()->prepare('INSERT OR IGNORE INTO structure_categories (nom, parent_id, ordre) VALUES (?, ?, ?)');
    foreach ($aCreer as $c) {
        $ordre = ($maxOrdre[$c['parent']] ?? 0) + 1;
        $maxOrdre[$c['parent']] = $ordre;
        $ins->execute([$c['nom'], $c['parent'], $ordre]);
    }
    return count($aCreer);
}

// Ce que structures_taxonomie_assurer() créerait, sans rien écrire : clé pliée
// « idParent|nom sans accents » => ['parent' => id racine, 'nom' => sous-cat,
// 'categorie' => nom de la racine]. Séparé pour que ?p=dev puisse MONTRER les
// manques avant de proposer de les combler.
function structures_taxonomie_a_creer(array $paires): array
{
    $map = structure_categorie_map();
    $racineId = [];   // clé pliée d'une racine → id
    $racineNom = [];  // id d'une racine → nom affichable
    $existants = [];  // "parentId|nom plié" déjà présents
    foreach ($map as $r) {
        $pid = plan_pid($r['parent_id'] ?? null);
        if ($pid === 0) {
            $racineId[texte_sans_accents((string) $r['nom'])] = (int) $r['id'];
            $racineNom[(int) $r['id']] = (string) $r['nom'];
        } else {
            $existants[$pid . '|' . texte_sans_accents((string) $r['nom'])] = true;
        }
    }
    $aCreer = [];
    foreach ($paires as $p) {
        $sous = trim((string) ($p['sous'] ?? ''));
        if ($sous === '') {
            continue;
        }
        $rid = $racineId[texte_sans_accents((string) ($p['categorie'] ?? ''))] ?? null;
        if ($rid === null) {
            continue;
        }
        $cle = $rid . '|' . texte_sans_accents($sous);
        if (isset($existants[$cle]) || isset($aCreer[$cle])) {
            continue;
        }
        $aCreer[$cle] = ['parent' => $rid, 'nom' => $sous, 'categorie' => $racineNom[$rid] ?? ''];
    }
    return $aCreer;
}

// Enregistre les sous-catégories rencontrées à l'import (paires issues de
// l'analyse). Appelé pendant l'application de l'import.
function structures_import_assurer_sous_categories(array $analyse): int
{
    $paires = array_map(
        fn ($l) => ['categorie' => $l['donnees']['categorie'] ?? '', 'sous' => $l['donnees']['sous_categorie'] ?? ''],
        $analyse
    );
    return structures_taxonomie_assurer($paires);
}

// Réparation : enregistre dans la taxonomie toutes les sous-catégories déjà
// présentes sur des structures mais absentes de structure_categories (ex.
// données importées avant l'ajout de l'enregistrement automatique). Idempotent.
function structures_taxonomie_synchroniser(?array $paires = null): int
{
    return structures_taxonomie_assurer($paires ?? structures_taxonomie_paires_utilisees());
}

// Paires (catégorie racine, sous-catégorie) réellement portées par des
// structures, avec le nombre de fiches concernées.
function structures_taxonomie_paires_utilisees(): array
{
    return db()->query(
        "SELECT categorie, sous_categorie AS sous, COUNT(*) AS nb
           FROM structures WHERE sous_categorie <> ''
          GROUP BY categorie, sous_categorie
          ORDER BY categorie, sous_categorie"
    )->fetchAll(PDO::FETCH_ASSOC);
}

// Sous-catégories vues sur des structures mais absentes de la taxonomie — la
// détection derrière la section « Sous-catégories non déclarées » de ?p=dev.
// Chaque entrée : ['cle' => clé stable pour la sélection, 'categorie', 'nom',
// 'nb' => structures concernées].
function structures_taxonomie_manquantes(): array
{
    $utilisees = structures_taxonomie_paires_utilisees();
    $nbParPaire = [];
    foreach ($utilisees as $p) {
        $nbParPaire[(string) $p['categorie'] . "\0" . (string) $p['sous']] = (int) $p['nb'];
    }
    $manques = [];
    foreach (structures_taxonomie_a_creer($utilisees) as $c) {
        $manques[] = [
            'cle'       => structures_taxonomie_cle((string) $c['categorie'], (string) $c['nom']),
            'categorie' => (string) $c['categorie'],
            'nom'       => (string) $c['nom'],
            'nb'        => $nbParPaire[(string) $c['categorie'] . "\0" . (string) $c['nom']] ?? 0,
        ];
    }
    return $manques;
}

// Clé d'une paire dans les cases à cocher de ?p=dev. Encodée : un nom de
// catégorie peut contenir n'importe quel caractère, séparateur compris.
function structures_taxonomie_cle(string $categorie, string $sous): string
{
    return base64_encode($categorie . "\0" . $sous);
}

// Paires correspondant aux clés cochées, RECONFRONTÉES à la détection : une clé
// forgée ou périmée ne crée rien (même principe que doublons_potentiels_*).
function structures_taxonomie_lire_cles(array $cles): array
{
    $valides = [];
    foreach (structures_taxonomie_manquantes() as $m) {
        $valides[$m['cle']] = ['categorie' => $m['categorie'], 'sous' => $m['nom']];
    }
    $paires = [];
    foreach ($cles as $cle) {
        if (isset($valides[(string) $cle])) {
            $paires[] = $valides[(string) $cle];
        }
    }
    return $paires;
}

// Champs d'une structure fusionnés à l'import (colonne DB => libellé). Le nom
// est inclus (une correspondance par e-mail peut avoir un nom différent). Clé =
// nom de colonne réel (whitelist : sûr à interpoler dans l'UPDATE dynamique).
const STRUCTURE_IMPORT_FUSION_CHAMPS = [
    'nom'              => 'Nom',
    'categorie'        => 'Catégorie',
    'sous_categorie'   => 'Sous-catégorie',
    'adresse_rue'      => 'Rue',
    'adresse_npa'      => 'NPA',
    'adresse_localite' => 'Localité',
    'departement_canton' => 'Département / canton',
    'grande_region'    => 'Région',
    'adresse_pays'     => 'Pays',
    'site_web'         => 'Site web',
    'via'              => 'Via',
    'notes'            => 'Remarques',
];

// Compare une fiche existante (ligne DB) aux données importées, champ par champ.
// Renvoie ['remplissages' => [colonne => valeur], 'conflits' => [colonne =>
// ['label','actuel','importe']]] :
//   • import vide           → ignoré (on garde la base) ;
//   • base vide, import plein → remplissage (à appliquer d'office) ;
//   • égaux                 → ignoré ;
//   • les deux pleins, différents → conflit (choix utilisateur).
// La colonne DB adresse_pays correspond à la clé 'pays' des données importées.
function structure_import_fusion(array $existante, array $donnees): array
{
    $imp = [
        'nom'              => (string) ($donnees['nom'] ?? ''),
        'categorie'        => (string) ($donnees['categorie'] ?? ''),
        'sous_categorie'   => (string) ($donnees['sous_categorie'] ?? ''),
        'adresse_rue'      => (string) ($donnees['adresse_rue'] ?? ''),
        'adresse_npa'      => (string) ($donnees['adresse_npa'] ?? ''),
        'adresse_localite' => (string) ($donnees['adresse_localite'] ?? ''),
        'departement_canton' => (string) ($donnees['departement_canton'] ?? ''),
        'grande_region'    => (string) ($donnees['grande_region'] ?? ''),
        'adresse_pays'     => (string) ($donnees['pays'] ?? ''),
        'site_web'         => (string) ($donnees['site_web'] ?? ''),
        'via'              => (string) ($donnees['via'] ?? ''),
        'notes'            => (string) ($donnees['notes'] ?? ''),
    ];
    // Catégorie/sous-catégorie non fournies par l'import → à traiter comme vides
    // (garder la base), sinon la catégorie par défaut créerait un faux conflit.
    if (empty($donnees['categorie_fournie'])) {
        $imp['categorie'] = '';
        $imp['sous_categorie'] = '';
    }
    $remplissages = [];
    $conflits = [];
    foreach (STRUCTURE_IMPORT_FUSION_CHAMPS as $col => $label) {
        $db = trim((string) ($existante[$col] ?? ''));
        $iv = trim($imp[$col]);
        if ($iv === '' || $iv === $db) {
            continue;
        }
        if ($db === '') {
            $remplissages[$col] = $iv;
        } else {
            $conflits[$col] = ['label' => $label, 'actuel' => $db, 'importe' => $iv];
        }
    }
    return ['remplissages' => $remplissages, 'conflits' => $conflits];
}

// Parmi les lignes analysées, celles qui correspondent à une fiche existante ET
// présentent au moins un conflit de champ (à trancher par l'utilisateur).
// Chaque entrée = la ligne + 'conflits' (cf. structure_import_fusion).
function structures_import_conflits(array $analyse): array
{
    $out = [];
    foreach ($analyse as $l) {
        if ($l['correspondance_id'] === null) {
            continue;
        }
        $existante = is_array($l['structure_existante'] ?? null) ? $l['structure_existante'] : [];
        $fusion = structure_import_fusion($existante, $l['donnees']);
        if ($fusion['conflits']) {
            $l['conflits'] = $fusion['conflits'];
            $out[] = $l;
        }
    }
    return $out;
}

// Applique l'import : chaque ligne est insérée si sans correspondance, sinon
// FUSIONNÉE champ par champ avec la fiche existante ($choix : index →
// [colonne => true] = prendre l'import pour un champ en conflit ; défaut =
// garder l'actuel ; jamais d'écrasement global). Retourne un résumé.
function structures_appliquer_import(array $analyse, array $choix): array
{
    $resume = ['nouvelles' => 0, 'mises_a_jour' => 0, 'ignorees' => 0, 'sous_categories' => 0];

    // Enregistre d'abord les sous-catégories nouvelles dans la taxonomie (pour
    // qu'elles figurent dans Paramètres → Catégories).
    $resume['sous_categories'] = structures_import_assurer_sous_categories($analyse);

    foreach ($analyse as $ligne) {
        structure_import_appliquer_structure($ligne, $choix, $resume);
    }
    return $resume;
}

// Insère (si nouvelle) ou FUSIONNE champ par champ (si déjà présente) la
// structure d'une ligne d'import, puis y rattache contact/étiquettes/note.
// Pour une fiche existante : remplissage des champs vides + application des
// choix de conflit ($choix[index][colonne] = prendre l'import), défaut =
// garder l'actuel. Renvoie l'id de la structure.
function structure_import_appliquer_structure(array $ligne, array $choix, array &$resume): ?int
{
    $d = $ligne['donnees'];
    $pays = $d['pays'] !== '' ? $d['pays'] : 'Suisse';
    $majLe = structure_date_csv_vers_iso($d['mise_a_jour']) ?? '';
    if ($ligne['correspondance_id'] === null) {
        // Grande région déduite du département/canton importé (France/Suisse
        // hors cantons bilingues) : remplace toute valeur CSV mappée sur
        // grande_region quand le département/canton est reconnu ; sinon
        // comportement actuel (valeur CSV acceptée telle quelle).
        $grandeRegionDeduite = grande_region_deduite($pays, $d['departement_canton']);
        if ($grandeRegionDeduite !== null) {
            $d['grande_region'] = $grandeRegionDeduite;
        }
        db()->prepare(
            'INSERT INTO structures (nom, categorie, sous_categorie, adresse_rue, adresse_npa, adresse_localite, departement_canton, grande_region, adresse_pays,
                                      site_web, via, notes, mise_a_jour_le, statut)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'actif\')'
        )->execute([
            $d['nom'], $d['categorie'], $d['sous_categorie'], $d['adresse_rue'], $d['adresse_npa'], $d['adresse_localite'],
            $d['departement_canton'], $d['grande_region'], $pays, $d['site_web'], $d['via'], $d['notes'], $majLe,
        ]);
        $structureId = (int) db()->lastInsertId();
        $resume['nouvelles']++;
        $grandeRegionFinale = $d['grande_region'];
    } else {
        // Fiche déjà présente : FUSION champ par champ (jamais d'écrasement
        // global). Remplissage des champs vides côté base + application des
        // choix de conflit ($choix[index][colonne] présent = prendre l'import),
        // défaut = garder l'actuel. Seuls les « prendre l'import » sont postés
        // (cases cochées) → borne PHP max_input_vars. Si rien ne change, la
        // fiche est comptée « ignorée » (aucune écriture).
        $structureId = (int) $ligne['correspondance_id'];
        $existante = is_array($ligne['structure_existante'] ?? null) ? $ligne['structure_existante'] : [];
        $fusion = structure_import_fusion($existante, $d);
        $maj = $fusion['remplissages'];
        foreach ($fusion['conflits'] as $col => $info) {
            if (!empty($choix[$ligne['index']][$col])) {
                $maj[$col] = $info['importe'];
            }
        }
        // Le pays effectif de la fiche (pour la taxonomie des régions) : import
        // s'il est renseigné, sinon celui déjà en base.
        $pays = $d['pays'] !== '' ? $d['pays'] : (string) ($existante['adresse_pays'] ?? '');
        // Grande région déduite du département/canton EFFECTIF (celui retenu
        // par la fusion, pas forcément celui du CSV) — seulement si elle
        // diffère de la valeur déjà en base, pour ne pas ajouter une écriture
        // inutile quand tout est déjà cohérent.
        $departementCantonEffectif = array_key_exists('departement_canton', $maj) ? $maj['departement_canton'] : (string) ($existante['departement_canton'] ?? '');
        $grandeRegionDeduite = grande_region_deduite($pays, $departementCantonEffectif);
        if ($grandeRegionDeduite !== null && $grandeRegionDeduite !== (string) ($existante['grande_region'] ?? '')) {
            $maj['grande_region'] = $grandeRegionDeduite;
        }
        if ($maj) {
            // Colonnes issues d'une whitelist (STRUCTURE_IMPORT_FUSION_CHAMPS) →
            // interpolation sûre.
            $sets = [];
            $vals = [];
            foreach ($maj as $col => $v) {
                $sets[] = "$col = ?";
                $vals[] = $v;
            }
            if ($majLe !== '') {
                $sets[] = 'mise_a_jour_le = ?';
                $vals[] = $majLe;
            }
            $vals[] = $structureId;
            db()->prepare('UPDATE structures SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
            $resume['mises_a_jour']++;
        } else {
            $resume['ignorees']++;
        }
        // $d['grande_region'] mis à jour vers la valeur effective (déduite ou
        // fusionnée) : structure_lier_lieu_importe() ci-dessous lit $d, pas $maj.
        $grandeRegionFinale = array_key_exists('grande_region', $maj) ? $maj['grande_region'] : (string) ($existante['grande_region'] ?? '');
        $d['grande_region'] = $grandeRegionFinale;
    }

    // La région (grande_region effective, déduite ou importée) rejoint la
    // taxonomie sous le pays de la fiche si elle en est absente — la liste
    // reste stricte dans les formulaires, mais l'import peut l'enrichir (le
    // lieu auto éventuel réutilise la même).
    pays_region_assurer($pays, (string) $grandeRegionFinale);

    structure_import_attacher_contact($structureId, $d);
    foreach (structure_tags_depuis_statut($d['tags_statut']) as $nomTag) {
        structure_attacher_tag($structureId, $nomTag);
    }

    // Application des champs « booking » (sous-catégorie, jauge, mois, dernier
    // concert) directement sur LA STRUCTURE elle-même (voir
    // structure_appliquer_champs_booking_importe(), migration_59/60) : le type
    // de lieu résolu doit être un VRAI type de la taxonomie booking (ex.
    // « Salle de location »). Les médias et l'entourage n'appliquent jamais
    // ces champs (« Radio », « Journaliste »… n'en sont pas).
    if (structure_sous_categorie_booking_nom_pour((string) ($d['type_lieu'] ?? '')) !== '') {
        structure_appliquer_champs_booking_importe($structureId, $d);
    }

    $dateContact = structure_date_csv_vers_iso($d['dernier_contact']);
    if ($dateContact !== null) {
        journaliser_contact_import($structureId, $dateContact, 'Import CSV — dernier contact connu.');
    }
    structure_recalculer_dernier_contact($structureId);
    return $structureId;
}

// Rattache le contact d'une ligne d'import à une structure (dédoublonnage par
// e-mail, insensible à la casse). Ne fait rien si la ligne n'a aucun contact.
function structure_import_attacher_contact(int $structureId, array $d): void
{
    if ($d['prenom'] === '' && $d['nom_contact'] === '' && $d['email_contact'] === '' && $d['telephone'] === '') {
        return;
    }
    if ($d['email_contact'] !== '') {
        $stmt = db()->prepare('SELECT 1 FROM structure_contacts WHERE structure_id = ? AND email = ? COLLATE NOCASE');
        $stmt->execute([$structureId, $d['email_contact']]);
        if ($stmt->fetchColumn()) {
            return;
        }
    }
    // Liste « ne pas contacter » : un e-mail exclu peut être (ré)importé comme
    // contact, mais naît désinscrit — l'exclusion n'est jamais contournée.
    $desinscrit = ($d['email_contact'] !== '' && mailing_email_exclu($d['email_contact'])) ? 1 : 0;
    db()->prepare(
        'INSERT INTO structure_contacts (structure_id, prenom, nom, role, email, telephone, formulaire_url, langue, desinscrit)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $structureId, $d['prenom'], $d['nom_contact'], $d['role'], $d['email_contact'],
        $d['telephone'], $d['formulaire_url'], $d['langue'], $desinscrit,
    ]);
}

// Type d'un lieu à l'import : si une catégorie de lieu a été détectée (colonne
// catégorie/sous-catégorie), on la prend telle quelle ; sinon on déduit du nom
// (« festival » dans le nom → Festival, à défaut Salle).
function structure_import_type_lieu(string $nom, string $typeLieu): string
{
    if (trim($typeLieu) !== '') {
        // Normalise vers l'intitulé canonique de la taxonomie booking (tolère
        // casse et accents, ex. « salle de location » → « Salle de location ») ;
        // conserve la valeur nettoyée si elle n'y figure pas (type libre).
        $reconnu = structure_sous_categorie_booking_nom_pour($typeLieu);
        return $reconnu !== '' ? $reconnu : trim($typeLieu);
    }
    return str_contains(mb_strtolower($nom, 'UTF-8'), 'festival') ? 'Festival' : 'Salle';
}

// Applique les champs « booking » (sous-catégorie déduite, jauge, mois de
// programmation/d'événement, dernier concert) directement sur la structure
// importée — remplace l'ancienne création d'un lieu séparé
// (structure_import_creer_lieu()/structure_lier_lieu_importe()) : depuis la
// fusion lieux→structures (migration_59/60), un lieu EST une structure, plus
// besoin d'une fiche à part ni de structure_lieux. Remplissage des champs
// vides uniquement (jamais d'écrasement, même politique que
// structure_import_fusion()) ; dernier_concert_le prend le MAXIMUM des deux
// valeurs (chaîne ISO, comparable lexicalement) pour ne jamais perdre une
// date plus récente déjà connue.
function structure_appliquer_champs_booking_importe(int $structureId, array $d): void
{
    $stmt = db()->prepare(
        'SELECT sous_categorie, dernier_concert_le FROM structures WHERE id = ?'
    );
    $stmt->execute([$structureId]);
    $s = $stmt->fetch();
    if (!$s) {
        return;
    }

    $sousCategorie = trim((string) $s['sous_categorie']) === ''
        ? structure_import_type_lieu((string) $d['nom'], (string) ($d['type_lieu'] ?? ''))
        : (string) $s['sous_categorie'];

    $jaugeMin = $d['jauge_min'] !== '' ? (int) $d['jauge_min'] : null;
    $jaugeMax = $d['jauge_max'] !== '' ? (int) $d['jauge_max'] : null;
    $evt = mois_plage_depuis_liste($d['periode_evenement'] ?? '');
    $prog = mois_plage_depuis_liste($d['periode_programmation'] ?? '');
    $ancienDC = (string) $s['dernier_concert_le'];
    $dernierConcert = max($ancienDC, structure_date_csv_vers_iso($d['dernier_concert'] ?? '') ?? '');

    db()->prepare(
        'UPDATE structures SET
            sous_categorie = ?,
            jauge_min = COALESCE(jauge_min, ?),
            jauge_max = COALESCE(jauge_max, ?),
            mois_debut = COALESCE(mois_debut, ?),
            mois_fin = COALESCE(mois_fin, ?),
            mois_evenement_debut = COALESCE(mois_evenement_debut, ?),
            mois_evenement_fin = COALESCE(mois_evenement_fin, ?),
            dernier_concert_le = ?
         WHERE id = ?'
    )->execute([
        $sousCategorie, $jaugeMin, $jaugeMax, $prog['debut'], $prog['fin'], $evt['debut'], $evt['fin'],
        $dernierConcert, $structureId,
    ]);

    if ($dernierConcert !== '' && $dernierConcert !== $ancienDC) {
        journaliser('structure', $structureId, 'dernier_concert', 'Dernier concert / diffusion (import) : ' . $dernierConcert, $dernierConcert);
    }
}

// Ajoute chaque e-mail à la liste « ne pas contacter » (table mailing_exclusions,
// §8 point 5) — SANS créer de structure fantôme pour un e-mail inconnu. Si des
// contacts existants portent cette adresse, ils sont aussi marqués désinscrits
// (opt-out visible sur leur fiche). La table reste la référence, non
// contournable : filtrée à l'envoi (mailing_destinataires) et respectée par
// tout import ultérieur (structure_import_attacher_contact).
function structures_importer_liste_exclusion(array $emails): int
{
    $n = 0;
    $ins = db()->prepare('INSERT OR IGNORE INTO mailing_exclusions (email) VALUES (?)');
    $maj = db()->prepare('UPDATE structure_contacts SET desinscrit = 1 WHERE email = ? COLLATE NOCASE');
    foreach ($emails as $email) {
        $email = trim($email);
        if ($email === '') {
            continue;
        }
        $ins->execute([$email]);
        $maj->execute([$email]);
        $n++;
    }
    return $n;
}

// L'adresse figure-t-elle dans la liste d'exclusion « ne pas contacter » ?
function mailing_email_exclu(string $email): bool
{
    $email = trim($email);
    if ($email === '') {
        return false;
    }
    $stmt = db()->prepare('SELECT 1 FROM mailing_exclusions WHERE email = ? COLLATE NOCASE');
    $stmt->execute([$email]);
    return (bool) $stmt->fetchColumn();
}
