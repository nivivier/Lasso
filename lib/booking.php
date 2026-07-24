<?php
// Module booking : CRM des structures (salles, festivals, médias, entourage).
// Fonctions partagées par les routes (lib/routes_booking.php) : dernier contact
// dérivé, normalisation de nom pour l'import, filtrage des destinataires de
// mailing (réutilisé par l'aperçu et par la constitution de la file d'attente,
// voir SPEC_BOOKING.md §6/§7).

declare(strict_types=1);

require_once __DIR__ . '/compta.php'; // plan_pid()/plan_enfants()/plan_est_feuille()/plan_liste_ordonnee() (arbre générique id/parent_id/ordre)

// Catégorie CRM d'une structure (booking) — axe distinct de structures.type
// (organisation/particulier, forme juridique pour la facturation), voir
// SPEC_BOOKING.md §5. Configurable (Paramètres → Catégories). Une sous-catégorie
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

// Catégorie de repli si aucune n'est fournie/reconnue : celle marquée
// « organisateur », sinon la première catégorie racine (ordre configuré).
function structure_categorie_par_defaut(): string
{
    $racines = structure_categories_racines();
    foreach ($racines as $c) {
        if ($c['est_organisateur']) {
            return (string) $c['nom'];
        }
    }
    return $racines ? (string) $racines[0]['nom'] : '';
}

// Une structure de cette catégorie (racine) représente-t-elle un lieu
// (salle/festival), pour déclencher la création automatique d'un lieu lié à
// l'import CSV (structures_appliquer_import()) ? Remplace l'ancien test en dur
// categorie === 'organisateur'.
function structure_categorie_est_organisateur(string $nom): bool
{
    $stmt = db()->prepare('SELECT est_organisateur FROM structure_categories WHERE nom = ? AND parent_id IS NULL');
    $stmt->execute([$nom]);
    return (bool) $stmt->fetchColumn();
}

// Nom de structure normalisé pour un rapprochement insensible à la casse, aux
// espaces et à la ponctuation (ex. « anti concert » ↔ « Anti-Concert ») — même
// principe que normaliser_nom_spectacle() (lib/evenements.php), dupliqué
// volontairement plutôt que partagé entre modules indépendants.
function normaliser_nom_structure(string $s): string
{
    return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim($s), 'UTF-8'));
}

// Minuscule + repli des accents sur la lettre de base (é→e, ç→c…), en gardant
// espaces et ponctuation — pour un rapprochement lisible d'intitulés à l'import
// (ex. « Média » ↔ « Media »). Différent de normaliser_nom_structure() qui, lui,
// SUPPRIME accents et ponctuation (pour comparer des noms propres).
function texte_sans_accents(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    return strtr($s, [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae', 'ÿ' => 'y',
    ]);
}

// Taxonomie propre aux lieux (« catégories de lieu » : Salle, Festival, Salle de
// concert, Saison culturelle…), gérée à part dans Paramètres → Catégories de
// lieu. Remplace le binaire salle/festival historique du champ lieux.type.
function lieu_categories_liste(): array
{
    return db()->query('SELECT nom FROM lieu_categories ORDER BY ordre, nom')->fetchAll(PDO::FETCH_COLUMN);
}

// Première catégorie de la liste — valeur de repli (jamais vide : au moins une
// catégorie existe, garantie par la migration et la garde de suppression).
function lieu_categorie_defaut(): string
{
    $n = db()->query('SELECT nom FROM lieu_categories ORDER BY ordre, nom LIMIT 1')->fetchColumn();
    return $n !== false ? (string) $n : 'Salle';
}

// Intitulé canonique si $valeur correspond à une catégorie de lieu (insensible
// à la casse), sinon null.
function lieu_categorie_normaliser(string $valeur): ?string
{
    $v = trim($valeur);
    if ($v === '') {
        return null;
    }
    $stmt = db()->prepare('SELECT nom FROM lieu_categories WHERE nom = ? COLLATE NOCASE LIMIT 1');
    $stmt->execute([$v]);
    $n = $stmt->fetchColumn();
    return $n !== false ? (string) $n : null;
}

// Type de lieu valide (repli sur le défaut si inconnu ou vide).
function lieu_categorie_valide(string $valeur): string
{
    return lieu_categorie_normaliser($valeur) ?? lieu_categorie_defaut();
}

// Recalcule et stocke structures.dernier_contact_le (colonne dénormalisée) :
// MAX() des notes marquées « contact » et des mailings envoyés avec succès.
// Appelée après chaque ajout de note-contact ou envoi de mailing (jamais à la
// volée à l'affichage, cf. SPEC_BOOKING.md §6).
function structure_recalculer_dernier_contact(int $structureId): void
{
    $stmt = db()->prepare(
        "SELECT MAX(d) FROM (
            SELECT MAX(cree_le) AS d FROM structure_notes WHERE structure_id = ? AND est_contact = 1
            UNION ALL
            SELECT MAX(envoye_le) AS d FROM mailing_envois WHERE structure_id = ? AND succes = 1
        )"
    );
    $stmt->execute([$structureId, $structureId]);
    $date = (string) ($stmt->fetchColumn() ?: '');
    db()->prepare('UPDATE structures SET dernier_contact_le = ? WHERE id = ?')->execute([$date, $structureId]);
}

// Fusionne $autres dans $idGarde : le profil (nom, adresse, catégorie…) de
// $idGarde est conservé tel quel — seules les relations (contacts, notes,
// mailing, factures, tags, lieux liés) des structures fusionnées sont
// reprises, puis les structures fusionnées sont supprimées. Contacts/notes/
// mailing/factures : simple réaffectation (pas de contrainte d'unicité).
// Tags/lieux : clé primaire composite (structure_id, tag_id|lieu_id) — copie
// de ce qui manque (INSERT OR IGNORE) puis suppression des anciennes lignes,
// pour ne jamais entrer en conflit avec un tag/lieu déjà présent sur $idGarde.
function structures_fusionner(int $idGarde, array $autres): void
{
    $autres = array_values(array_unique(array_diff(array_map('intval', $autres), [$idGarde])));
    if (!$autres) {
        return;
    }
    $in = implode(',', array_fill(0, count($autres), '?'));

    // Transaction : la fusion enchaîne une dizaine de mutations (réaffectations
    // + suppressions) ; une erreur en cours de route laisserait des relations à
    // moitié reprises et des structures fusionnées non supprimées, sans retour
    // arrière possible.
    db()->beginTransaction();
    foreach (['structure_contacts', 'structure_notes', 'mailing_envois', 'mailing_file_attente', 'factures'] as $table) {
        db()->prepare("UPDATE $table SET structure_id = ? WHERE structure_id IN ($in)")
            ->execute(array_merge([$idGarde], $autres));
    }

    $stmtTags = db()->prepare("SELECT DISTINCT tag_id FROM structure_tag_liens WHERE structure_id IN ($in)");
    $stmtTags->execute($autres);
    $insTag = db()->prepare('INSERT OR IGNORE INTO structure_tag_liens (structure_id, tag_id) VALUES (?, ?)');
    foreach ($stmtTags->fetchAll(PDO::FETCH_COLUMN) as $tagId) {
        $insTag->execute([$idGarde, $tagId]);
    }
    db()->prepare("DELETE FROM structure_tag_liens WHERE structure_id IN ($in)")->execute($autres);

    $stmtLieux = db()->prepare("SELECT DISTINCT lieu_id FROM structure_lieux WHERE structure_id IN ($in)");
    $stmtLieux->execute($autres);
    $insLieu = db()->prepare('INSERT OR IGNORE INTO structure_lieux (structure_id, lieu_id) VALUES (?, ?)');
    foreach ($stmtLieux->fetchAll(PDO::FETCH_COLUMN) as $lieuId) {
        $insLieu->execute([$idGarde, $lieuId]);
    }
    db()->prepare("DELETE FROM structure_lieux WHERE structure_id IN ($in)")->execute($autres);

    db()->prepare("DELETE FROM structures WHERE id IN ($in)")->execute($autres);
    db()->commit();
    structure_recalculer_dernier_contact($idGarde);
}

// Transforme une structure en salle/festival (lieu) d'une structure organisateur :
// la structure « rétrogradée » devient un lieu lié à $orgId, et ses relations
// (contacts, notes, factures, étiquettes, autres lieux liés) sont reprises par
// l'organisateur avant sa suppression — même sémantique que structures_fusionner().
// Réutilise un lieu déjà lié portant le même nom normalisé (cas d'un import
// « organisateur » qui en avait auto-créé un) plutôt que d'en dupliquer un.
// $type : une catégorie de lieu (repli sur le défaut si inconnue). Renvoie false
// si structure/organisateur invalides.
function structure_transformer_en_lieu(int $structureId, int $orgId, string $type): bool
{
    if ($structureId === $orgId || $structureId <= 0 || $orgId <= 0) {
        return false;
    }
    $type = lieu_categorie_valide($type);
    $stmt = db()->prepare('SELECT * FROM structures WHERE id = ?');
    $stmt->execute([$structureId]);
    $s = $stmt->fetch();
    $stmtOrg = db()->prepare('SELECT 1 FROM structures WHERE id = ?');
    $stmtOrg->execute([$orgId]);
    if (!$s || !$stmtOrg->fetchColumn()) {
        return false;
    }

    db()->beginTransaction();
    // Lieu représentant la structure : réutiliser un lieu déjà lié du même nom
    // normalisé, sinon en créer un (jauge inconnue — la table structures n'a pas
    // de jauge ; à compléter ensuite sur la fiche lieu).
    $nomNorm = normaliser_nom_structure((string) $s['nom']);
    $lieuId = null;
    $stmtLieux = db()->prepare(
        'SELECT l.id, l.nom FROM lieux l JOIN structure_lieux sl ON sl.lieu_id = l.id WHERE sl.structure_id = ?'
    );
    $stmtLieux->execute([$structureId]);
    foreach ($stmtLieux->fetchAll() as $l) {
        if (normaliser_nom_structure((string) $l['nom']) === $nomNorm) {
            $lieuId = (int) $l['id'];
            break;
        }
    }
    if ($lieuId === null) {
        db()->prepare(
            'INSERT INTO lieux (type, nom, ville, region, pays) VALUES (?, ?, ?, ?, ?)'
        )->execute([$type, (string) $s['nom'], (string) $s['adresse_localite'], (string) $s['region'], (string) $s['adresse_pays']]);
        $lieuId = (int) db()->lastInsertId();
    } else {
        db()->prepare('UPDATE lieux SET type = ? WHERE id = ?')->execute([$type, $lieuId]);
    }
    db()->prepare('INSERT OR IGNORE INTO structure_lieux (structure_id, lieu_id) VALUES (?, ?)')
        ->execute([$orgId, $lieuId]);
    db()->commit();

    // Reprise des relations de la structure d'origine par l'organisateur, puis
    // suppression (structures_fusionner gère sa propre transaction, y compris le
    // déplacement du lien du lieu ci-dessus s'il était rattaché à la structure).
    structures_fusionner($orgId, [$structureId]);
    return true;
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
// deux implémentations divergentes). $criteres : categorie + sous_categorie
// (string|'', mêmes clés que le filtre de ?p=structures), pays (string|''),
// grande_region (Région, string|''), region (Département/canton, string|''),
// ville (string|''), type_lieu (catégorie de lieu, string|''), tag_id (int|0),
// mois_debut/mois_fin (int|null, période de
// programmation des lieux liés), mois_evenement_debut/fin (int|null, période
// des événements), contact_jamais (bool), contact_avant (date string|'').
// Exclut toujours desinscrit=1, en dernière étape, non contournable.
function mailing_structures_eligibles(array $criteres): array
{
    $where = ['s.desinscrit = 0'];
    $params = [];

    // Catégorie/sous-catégorie : mêmes clés que le filtre de ?p=structures
    // (choisir une catégorie racine inclut toutes ses sous-catégories).
    if (!empty($criteres['categorie'])) {
        $where[] = 's.categorie = ?';
        $params[] = $criteres['categorie'];
    }
    if (!empty($criteres['sous_categorie'])) {
        $where[] = 's.sous_categorie = ?';
        $params[] = $criteres['sous_categorie'];
    }
    if (!empty($criteres['pays'])) {
        $where[] = 's.adresse_pays = ?';
        $params[] = $criteres['pays'];
    }
    // grande_region = « Région » (Normandie, Romandie…) ; region = « Département / canton ».
    if (!empty($criteres['grande_region'])) {
        $where[] = 's.grande_region = ?';
        $params[] = $criteres['grande_region'];
    }
    if (!empty($criteres['region'])) {
        $where[] = 's.region = ?';
        $params[] = $criteres['region'];
    }
    if (!empty($criteres['ville'])) {
        $where[] = 's.adresse_localite = ?';
        $params[] = $criteres['ville'];
    }
    // Type de lieu (lieu_categories) : structure ayant au moins un lieu lié de
    // cette catégorie (ex. « Festival », « Salle de concert »).
    if (!empty($criteres['type_lieu'])) {
        $where[] = 's.id IN (SELECT sl.structure_id FROM structure_lieux sl
                             JOIN lieux l ON l.id = sl.lieu_id WHERE l.type = ?)';
        $params[] = $criteres['type_lieu'];
    }
    if (!empty($criteres['tag_id'])) {
        $where[] = 's.id IN (SELECT structure_id FROM structure_tag_liens WHERE tag_id = ?)';
        $params[] = (int) $criteres['tag_id'];
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

    // Filtres de période via les lieux liés — appliqués en PHP (pas en SQL) car
    // le chevauchement gère le passage d'année (periode_chevauche()), pas
    // exprimable simplement en SQL. Deux plages, mêmes notions que la fiche
    // lieu : mois_debut/mois_fin = « Préparé de… à » (période de programmation),
    // mois_evenement_debut/fin = « Événements de… à » (quand ça a lieu).
    $filtrePeriode = function (array $structures, string $colDebut, string $colFin, int $md, int $mf): array {
        $stmtDetail = db()->query(
            "SELECT sl.structure_id, l.$colDebut AS d, l.$colFin AS f FROM structure_lieux sl
             JOIN lieux l ON l.id = sl.lieu_id
             WHERE l.$colDebut IS NOT NULL AND l.$colFin IS NOT NULL"
        );
        $eligibles = [];
        foreach ($stmtDetail->fetchAll() as $r) {
            if (periode_chevauche((int) $r['d'], (int) $r['f'], $md, $mf)) {
                $eligibles[(int) $r['structure_id']] = true;
            }
        }
        return array_values(array_filter($structures, fn ($s) => isset($eligibles[(int) $s['id']])));
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
    'organisateur'     => 'Organisateur (asso mère — regroupe ses salles/festivals)',
    'categorie'        => 'Catégorie (organisateur/média/autres/entourage)',
    'sous_categorie'   => 'Sous-catégorie (ex. Journaliste, Salle de concert…)',
    'adresse_rue'      => 'Adresse (rue)',
    'adresse_npa'      => 'NPA',
    'adresse_localite' => 'Localité',
    'region'           => 'Département / canton',
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

// Détecte le séparateur (virgule ou point-virgule, fréquent dans les exports
// Excel francophones) à partir de la ligne d'en-tête.
function structures_detecter_delimiteur(string $ligneEntete): string
{
    return substr_count($ligneEntete, ';') > substr_count($ligneEntete, ',') ? ';' : ',';
}

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
    $delim = structures_detecter_delimiteur(substr($csv, 0, strcspn($csv, "\r\n")));

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
    if (!preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', trim($s), $m)) {
        return null;
    }
    [, $jour, $mois, $annee] = $m;
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

// Détermine l'organisateur d'une ligne d'import et le nom « propre » du lieu
// (festival/salle) qu'elle décrit. Deux sources : la colonne « Organisateur »
// mappée (prioritaire, déterministe) ; à défaut, une parenthèse finale dans le
// nom (« Festival X (Asso Y) » → organisateur « Asso Y », lieu « Festival X »).
// Renvoie ['organisateur' => nom|'', 'nom_lieu' => nom sans la parenthèse,
// 'org_source' => 'colonne'|'parenthese'|'']. La source sert à écarter les faux
// positifs : une parenthèse ne vaut regroupement que si l'organisateur est une
// entité réelle (cf. structures_grouper), car « X (Le Havre) » désigne le plus
// souvent une ville, pas un organisateur.
function structure_import_groupe(array $d): array
{
    $nom = trim((string) ($d['nom'] ?? ''));
    $orgCol = trim((string) ($d['organisateur'] ?? ''));
    $base = $nom;
    $paren = '';
    if (preg_match('/^(.+?)\s*\((.+)\)\s*$/u', $nom, $m)) {
        $base = trim($m[1]);
        $paren = trim($m[2]);
    }
    $org = $orgCol !== '' ? $orgCol : $paren;
    if ($org === '') {
        return ['organisateur' => '', 'nom_lieu' => $nom, 'org_source' => ''];
    }
    $source = $orgCol !== '' ? 'colonne' : 'parenthese';
    // Nom du lieu : sans la parenthèse si celle-ci nomme bien l'organisateur ;
    // sinon le nom tel quel (cas d'une colonne organisateur séparée).
    $nomLieu = ($paren !== '' && normaliser_nom_structure($paren) === normaliser_nom_structure($org)) ? $base : $nom;
    return ['organisateur' => $org, 'nom_lieu' => $nomLieu, 'org_source' => $source];
}

// Regroupe les lignes analysées par organisateur (clé = nom normalisé). Un
// groupe n'est retenu que s'il contient au moins un « lieu » (ligne dont le nom
// diffère de l'organisateur) — sinon il n'y a rien à regrouper. Chaque groupe :
// ['organisateur' => nom affiché, 'membres' => [index], 'lieux' => [[index,nom]],
//  'self_index' => index de la ligne qui EST l'organisateur (ou null)].
function structures_grouper(array $analyse): array
{
    $groupes = [];
    foreach ($analyse as $l) {
        $org = (string) ($l['organisateur'] ?? '');
        if ($org === '') {
            continue;
        }
        $key = normaliser_nom_structure($org);
        if ($key === '') {
            continue;
        }
        if (!isset($groupes[$key])) {
            $groupes[$key] = ['organisateur' => $org, 'membres' => [], 'lieux' => [], 'self_index' => null, 'source_colonne' => false];
        }
        if (($l['org_source'] ?? '') === 'colonne') {
            $groupes[$key]['source_colonne'] = true;
        }
        $groupes[$key]['membres'][] = (int) $l['index'];
        if (normaliser_nom_structure((string) $l['nom_lieu']) === $key) {
            $groupes[$key]['self_index'] = (int) $l['index'];
        } elseif (!empty($l['cat_organisateur'])) {
            // Seules les lignes d'une catégorie « organisateur » (salles/festivals)
            // peuvent devenir des lieux ; médias/radios/entourage n'en sont jamais.
            $groupes[$key]['lieux'][] = ['index' => (int) $l['index'], 'nom' => (string) $l['nom_lieu']];
        }
    }
    // Deuxième passe : une ligne « autonome » (sans organisateur déclaré) dont le
    // nom correspond à un organisateur EST la fiche de cet organisateur — on la
    // rattache au groupe pour la réutiliser plutôt que d'en créer une en double.
    foreach ($analyse as $l) {
        if ((string) ($l['organisateur'] ?? '') !== '') {
            continue;
        }
        $key = normaliser_nom_structure((string) $l['nom_lieu']);
        if (isset($groupes[$key]) && $groupes[$key]['self_index'] === null) {
            $groupes[$key]['self_index'] = (int) $l['index'];
            $groupes[$key]['membres'][] = (int) $l['index'];
        }
    }
    // On ne garde un groupe que s'il a au moins un lieu (salle/festival) ET que
    // l'organisateur est une entité RÉELLE : soit désigné par la colonne
    // « Organisateur » (intention explicite), soit présent comme sa propre ligne
    // (self_index). Une parenthèse seule ne suffit pas — « X (Le Havre) » nomme
    // une ville, pas un organisateur, et ne doit pas regrouper les lignes.
    return array_filter(
        $groupes,
        fn ($g) => count($g['lieux']) > 0 && ($g['source_colonne'] || $g['self_index'] !== null)
    );
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
    $indexNom = [];   // nom normalisé → id de structure existante (1re gagnante)
    $indexEmail = []; // e-mail (minuscule) → id de structure existante (1er gagnant)
    foreach (db()->query('SELECT id, nom FROM structures') as $s) {
        $cle = normaliser_nom_structure((string) $s['nom']);
        if ($cle !== '' && !isset($indexNom[$cle])) {
            $indexNom[$cle] = (int) $s['id'];
        }
    }
    foreach (db()->query("SELECT email, structure_id FROM structure_contacts WHERE email <> ''") as $c) {
        $cle = mb_strtolower(trim((string) $c['email']), 'UTF-8');
        if ($cle !== '' && !isset($indexEmail[$cle])) {
            $indexEmail[$cle] = (int) $c['structure_id'];
        }
    }
    // Taxonomie des structures, indexée par clé « pliée » (minuscule sans accents)
    // pour un rapprochement tolérant à l'import : racines, sous-catégories (→ parent),
    // et catégories « organisateur ».
    $catMap = structure_categorie_map();
    $catsRacines = []; // clé pliée → nom racine canonique
    $catsSub = [];     // clé pliée → ['parent' => nom racine, 'nom' => nom sous-cat]
    $catsOrg = [];     // nom racine canonique → true si catégorie « organisateur »
    foreach ($catMap as $r) {
        $nom = (string) $r['nom'];
        if (plan_pid($r['parent_id'] ?? null) === 0) {
            $catsRacines[texte_sans_accents($nom)] = $nom;
            if (!empty($r['est_organisateur'])) {
                $catsOrg[$nom] = true;
            }
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
    $lieuCats = []; // clé pliée → nom canonique (catégorie de lieu)
    foreach (lieu_categories_liste() as $n) {
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
        // Type de lieu (salle de concert, festival, saison culturelle…) : détecté
        // sur les valeurs BRUTES de catégorie / sous-catégorie AVANT normalisation.
        // C'est une taxonomie propre au lieu (lieu_categories) : la valeur part sur
        // le lieu (lieux.type) et n'encombre plus la structure. La sous-catégorie
        // qui n'était qu'un type de lieu est donc vidée côté structure.
        $typeSous = $normLieuCat($donnees['sous_categorie']);
        $typeCat  = $normLieuCat($donnees['categorie']);
        $donnees['type_lieu'] = $typeSous !== '' ? $typeSous : $typeCat;
        if ($typeSous !== '') {
            $donnees['sous_categorie'] = '';
        }
        // Catégorie de structure : racine, sinon sous-catégorie remontée à son
        // parent, sinon défaut (insensible casse + accents) — voir le helper.
        $res = structure_import_resoudre_categorie(
            $donnees['categorie'], $donnees['sous_categorie'], $typeCat, $catsRacines, $catsSub, $catDefaut
        );
        $donnees['categorie'] = $res['categorie'];
        $donnees['sous_categorie'] = $res['sous_categorie'];

        // Correspondance avec l'existant : e-mail exact (prioritaire) sinon nom
        // normalisé — via les index préchargés, sans requête par ligne.
        $correspondanceId = null;
        $email = mb_strtolower(trim($donnees['email_contact']), 'UTF-8');
        if ($email !== '' && isset($indexEmail[$email])) {
            $correspondanceId = $indexEmail[$email];
        } else {
            $nomNorm = normaliser_nom_structure($donnees['nom']);
            $correspondanceId = ($nomNorm !== '' && isset($indexNom[$nomNorm])) ? $indexNom[$nomNorm] : null;
        }
        $structureExistante = null;
        if ($correspondanceId) {
            $stmt = db()->prepare('SELECT * FROM structures WHERE id = ?');
            $stmt->execute([$correspondanceId]);
            $structureExistante = $stmt->fetch() ?: null;
        }
        $groupe = structure_import_groupe($donnees);
        $analyse[] = [
            'index' => $index,
            'donnees' => $donnees,
            'correspondance_id' => $correspondanceId,
            'structure_existante' => $structureExistante,
            'organisateur' => $groupe['organisateur'],
            'nom_lieu' => $groupe['nom_lieu'],
            'org_source' => $groupe['org_source'],
            // Éligible à devenir un lieu (salle/festival) : uniquement les
            // catégories « organisateur » — exclut médias/radios/entourage.
            'cat_organisateur' => isset($catsOrg[$donnees['categorie']]),
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
    $map = structure_categorie_map();
    $racineId = [];   // clé pliée d'une racine → id
    $existants = [];  // "parentId|nom plié" déjà présents
    $maxOrdre = [];   // parentId (0 = racine) → ordre max actuel
    foreach ($map as $r) {
        $pid = plan_pid($r['parent_id'] ?? null);
        if ($pid === 0) {
            $racineId[texte_sans_accents((string) $r['nom'])] = (int) $r['id'];
        } else {
            $existants[$pid . '|' . texte_sans_accents((string) $r['nom'])] = true;
        }
        $maxOrdre[$pid] = max($maxOrdre[$pid] ?? 0, (int) $r['ordre']);
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
        $aCreer[$cle] = ['parent' => $rid, 'nom' => $sous];
    }
    if (!$aCreer) {
        return 0;
    }
    $ins = db()->prepare('INSERT OR IGNORE INTO structure_categories (nom, parent_id, est_organisateur, ordre) VALUES (?, ?, 0, ?)');
    foreach ($aCreer as $c) {
        $ordre = ($maxOrdre[$c['parent']] ?? 0) + 1;
        $maxOrdre[$c['parent']] = $ordre;
        $ins->execute([$c['nom'], $c['parent'], $ordre]);
    }
    return count($aCreer);
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
function structures_taxonomie_synchroniser(): int
{
    $paires = db()->query(
        "SELECT DISTINCT categorie, sous_categorie AS sous FROM structures WHERE sous_categorie <> ''"
    )->fetchAll(PDO::FETCH_ASSOC);
    return structures_taxonomie_assurer($paires);
}

// Applique l'import. Deux régimes cohabitent :
//   • Regroupements confirmés ($groupesConfirmes = clés normalisées d'orga-
//     nisateur, cf. structures_grouper()) : l'organisateur devient/reste UNE
//     structure, les lignes « lieu » du groupe deviennent des salles/festivals
//     qui lui sont rattachés (leurs contacts/notes/étiquettes vont à
//     l'organisateur, comme la transformation a posteriori — SPEC_BOOKING.md).
//   • Lignes hors regroupement : logique par ligne habituelle — insérées si
//     sans correspondance, sinon selon $resolutions (index → 'ignorer'|'maj',
//     absence = 'ignorer', jamais d'écrasement silencieux).
// Retourne un résumé.
function structures_appliquer_import(array $analyse, array $resolutions, array $groupesConfirmes = [], string $decisionDefaut = 'ignorer'): array
{
    $resume = ['nouvelles' => 0, 'mises_a_jour' => 0, 'ignorees' => 0, 'lieux' => 0, 'sous_categories' => 0];

    // Enregistre d'abord les sous-catégories nouvelles dans la taxonomie (pour
    // qu'elles figurent dans Paramètres → Catégories).
    $resume['sous_categories'] = structures_import_assurer_sous_categories($analyse);

    $groupes = structures_grouper($analyse);
    $confirmes = array_flip($groupesConfirmes);
    $parIndex = [];
    foreach ($analyse as $l) {
        $parIndex[$l['index']] = $l;
    }
    $consommes = []; // index des lignes déjà traitées via un regroupement

    foreach ($groupes as $key => $g) {
        if (!isset($confirmes[$key])) {
            continue;
        }
        // 1. L'organisateur : sa propre ligne si présente dans l'import,
        //    sinon une structure minimale créée/retrouvée par le nom.
        if ($g['self_index'] !== null && isset($parIndex[$g['self_index']])) {
            $orgId = structure_import_appliquer_structure($parIndex[$g['self_index']], $resolutions, $resume, false, $decisionDefaut);
            $consommes[$g['self_index']] = true;
            if ($orgId === null) {
                continue; // ligne organisateur ignorée (conflit non résolu) → on laisse le reste tel quel
            }
        } else {
            [$orgId, $creee] = structure_import_trouver_ou_creer_organisateur($g['organisateur']);
            if ($creee) {
                $resume['nouvelles']++;
            }
        }
        // 2. Les lignes « lieu » du groupe → salles/festivals rattachés.
        foreach ($g['lieux'] as $lieu) {
            if (!isset($parIndex[$lieu['index']])) {
                continue;
            }
            structure_import_rattacher_lieu_membre($orgId, $parIndex[$lieu['index']]['donnees'], $lieu['nom']);
            $consommes[$lieu['index']] = true;
            $resume['lieux']++;
        }
        structure_recalculer_dernier_contact($orgId);
    }

    // Lignes hors regroupement.
    foreach ($analyse as $ligne) {
        if (isset($consommes[$ligne['index']])) {
            continue;
        }
        structure_import_appliquer_structure($ligne, $resolutions, $resume, true, $decisionDefaut);
    }
    return $resume;
}

// Insère ou met à jour la structure d'une ligne d'import, puis y rattache
// contact/étiquettes/note (et, si $autoLieu, crée le lieu auto des catégories
// « organisateur »). Renvoie l'id de la structure, ou null si la ligne était un
// conflit non résolu (ignorée). Facteur commun aux lignes normales et aux
// lignes « organisateur » d'un regroupement.
function structure_import_appliquer_structure(array $ligne, array $resolutions, array &$resume, bool $autoLieu, string $decisionDefaut = 'ignorer'): ?int
{
    $d = $ligne['donnees'];
    $pays = $d['pays'] !== '' ? $d['pays'] : 'Suisse';
    $majLe = structure_date_csv_vers_iso($d['mise_a_jour']) ?? '';
    if ($ligne['correspondance_id'] === null) {
        db()->prepare(
            'INSERT INTO structures (nom, categorie, sous_categorie, adresse_rue, adresse_npa, adresse_localite, region, grande_region, adresse_pays,
                                      site_web, via, notes, mise_a_jour_le, actif)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
        )->execute([
            $d['nom'], $d['categorie'], $d['sous_categorie'], $d['adresse_rue'], $d['adresse_npa'], $d['adresse_localite'],
            $d['region'], $d['grande_region'], $pays, $d['site_web'], $d['via'], $d['notes'], $majLe,
        ]);
        $structureId = (int) db()->lastInsertId();
        $resume['nouvelles']++;
    } else {
        // Absence de décision explicite → décision globale par défaut. Cette
        // valeur par défaut est essentielle : sur un gros import, le formulaire
        // ne peut pas envoyer une décision par ligne (PHP max_input_vars ~1000
        // tronque silencieusement decision[]), donc seules les EXCEPTIONS sont
        // transmises, tout le reste suit le défaut.
        $decision = $resolutions[$ligne['index']] ?? $decisionDefaut;
        if ($decision !== 'maj') {
            $resume['ignorees']++;
            return null;
        }
        $structureId = (int) $ligne['correspondance_id'];
        db()->prepare(
            'UPDATE structures SET nom=?, categorie=?, sous_categorie=?, adresse_rue=?, adresse_npa=?, adresse_localite=?,
                                    region=?, grande_region=?, adresse_pays=?, site_web=?, via=?, notes=?, mise_a_jour_le=? WHERE id=?'
        )->execute([
            $d['nom'], $d['categorie'], $d['sous_categorie'], $d['adresse_rue'], $d['adresse_npa'], $d['adresse_localite'],
            $d['region'], $d['grande_region'], $pays, $d['site_web'], $d['via'], $d['notes'], $majLe, $structureId,
        ]);
        $resume['mises_a_jour']++;
    }

    // La région importée (grande_region) rejoint la taxonomie sous le pays de la
    // fiche si elle en est absente — la liste reste stricte dans les formulaires,
    // mais l'import peut l'enrichir (le lieu auto éventuel réutilise la même).
    pays_region_assurer($pays, (string) $d['grande_region']);

    structure_import_attacher_contact($structureId, $d);
    foreach (structure_tags_depuis_statut($d['tags_statut']) as $nomTag) {
        structure_attacher_tag($structureId, $nomTag);
    }

    // Catégorie marquée « organisateur » (Paramètres → Catégories) : la ligne
    // décrit une salle ou un festival — création/liaison automatique d'un lieu
    // du même nom, avec la même adresse et la jauge éventuellement mappée
    // (SPEC_BOOKING.md §9).
    if ($autoLieu && structure_categorie_est_organisateur($d['categorie'])) {
        structure_lier_lieu_importe($structureId, $d);
    }

    $dateContact = structure_date_csv_vers_iso($d['dernier_contact']);
    if ($dateContact !== null) {
        db()->prepare('INSERT INTO structure_notes (structure_id, contenu, est_contact, cree_le) VALUES (?, ?, 1, ?)')
            ->execute([$structureId, 'Import CSV — dernier contact connu.', $dateContact]);
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

// Retrouve (par nom normalisé) ou crée une structure organisateur minimale,
// pour un regroupement dont aucune ligne ne décrit l'organisateur lui-même.
// Renvoie [id, créée?].
function structure_import_trouver_ou_creer_organisateur(string $nom): array
{
    $id = structure_trouver_correspondance($nom, '');
    if ($id) {
        return [(int) $id, false];
    }
    // Catégorie « organisateur » si elle existe (une salle/festival lui sera
    // rattaché), sinon la catégorie par défaut.
    $categorie = structure_categorie_par_defaut();
    foreach (structure_categories_noms() as $catNom) {
        if (structure_categorie_est_organisateur($catNom)) {
            $categorie = $catNom;
            break;
        }
    }
    db()->prepare('INSERT INTO structures (nom, categorie, actif) VALUES (?, ?, 1)')->execute([$nom, $categorie]);
    return [(int) db()->lastInsertId(), true];
}

// Rattache une ligne « lieu » d'un regroupement à l'organisateur : crée/lie le
// lieu (nom nettoyé de la parenthèse), et reporte le contact, les étiquettes et
// la note de dernier contact de la ligne SUR l'organisateur (choix « rattacher
// à l'organisateur »).
function structure_import_rattacher_lieu_membre(int $orgId, array $d, string $nomLieu): void
{
    structure_import_creer_lieu($orgId, $nomLieu, structure_import_type_lieu($nomLieu, (string) ($d['type_lieu'] ?? '')), $d);
    structure_import_attacher_contact($orgId, $d);
    foreach (structure_tags_depuis_statut($d['tags_statut']) as $nomTag) {
        structure_attacher_tag($orgId, $nomTag);
    }
    $dateContact = structure_date_csv_vers_iso($d['dernier_contact']);
    if ($dateContact !== null) {
        db()->prepare('INSERT INTO structure_notes (structure_id, contenu, est_contact, cree_le) VALUES (?, ?, 1, ?)')
            ->execute([$orgId, 'Import CSV — dernier contact connu (' . $nomLieu . ').', $dateContact]);
    }
}

// Type d'un lieu à l'import : si une catégorie de lieu a été détectée (colonne
// catégorie/sous-catégorie), on la prend telle quelle ; sinon on déduit du nom
// (« festival » dans le nom → Festival, à défaut Salle).
function structure_import_type_lieu(string $nom, string $typeLieu): string
{
    if ($typeLieu !== '') {
        return $typeLieu;
    }
    return str_contains(mb_strtolower($nom, 'UTF-8'), 'festival') ? 'Festival' : 'Salle';
}

// Crée (ou met à jour la jauge d') un lieu de nom/type donnés et le lie à la
// structure. Adresse copiée depuis les données de la ligne ; jauge min/max
// depuis les colonnes mappées, si présentes. Rapprochement par nom normalisé +
// type, pour ne pas dupliquer un lieu déjà créé par une ligne précédente.
function structure_import_creer_lieu(int $structureId, string $nom, string $type, array $d): void
{
    $nomNorm = normaliser_nom_structure($nom);
    $lieuId = null;
    $stmt = db()->prepare("SELECT id, nom FROM lieux WHERE type = ?");
    $stmt->execute([$type]);
    foreach ($stmt->fetchAll() as $l) {
        if (normaliser_nom_structure($l['nom']) === $nomNorm) {
            $lieuId = (int) $l['id'];
            break;
        }
    }
    $jaugeMin = $d['jauge_min'] !== '' ? (int) $d['jauge_min'] : null;
    $jaugeMax = $d['jauge_max'] !== '' ? (int) $d['jauge_max'] : null;
    $evt = mois_plage_depuis_liste($d['periode_evenement'] ?? '');
    $prog = mois_plage_depuis_liste($d['periode_programmation'] ?? '');
    $grande = (string) ($d['grande_region'] ?? '');
    $dernierConcert = structure_date_csv_vers_iso($d['dernier_concert'] ?? '') ?? '';
    if ($lieuId === null) {
        db()->prepare(
            'INSERT INTO lieux (type, nom, ville, region, grande_region, pays, jauge_min, jauge_max,
                                mois_debut, mois_fin, mois_evenement_debut, mois_evenement_fin, dernier_concert_le)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $type, $nom, $d['adresse_localite'], $d['region'], $grande, $d['pays'], $jaugeMin, $jaugeMax,
            $prog['debut'], $prog['fin'], $evt['debut'], $evt['fin'], $dernierConcert,
        ]);
        $lieuId = (int) db()->lastInsertId();
    } else {
        // Lieu déjà créé par une ligne précédente : on complète sans écraser une
        // valeur déjà présente (COALESCE pour les mois, garde l'existant si le
        // champ texte importé est vide).
        db()->prepare(
            "UPDATE lieux SET
                jauge_min = COALESCE(?, jauge_min),
                jauge_max = COALESCE(?, jauge_max),
                mois_debut = COALESCE(?, mois_debut),
                mois_fin = COALESCE(?, mois_fin),
                mois_evenement_debut = COALESCE(?, mois_evenement_debut),
                mois_evenement_fin = COALESCE(?, mois_evenement_fin),
                grande_region = CASE WHEN ? <> '' THEN ? ELSE grande_region END,
                dernier_concert_le = CASE WHEN ? <> '' THEN ? ELSE dernier_concert_le END
             WHERE id = ?"
        )->execute([
            $jaugeMin, $jaugeMax, $prog['debut'], $prog['fin'], $evt['debut'], $evt['fin'],
            $grande, $grande, $dernierConcert, $dernierConcert, $lieuId,
        ]);
    }
    db()->prepare('INSERT OR IGNORE INTO structure_lieux (structure_id, lieu_id) VALUES (?, ?)')
        ->execute([$structureId, $lieuId]);
}

// Crée (ou met à jour) le lieu correspondant à une structure « organisateur »
// importée (catégorie organisateur), et le lie. Le nom du lieu = le nom de la
// structure. Type = catégorie de lieu détectée, sinon déduit du nom.
function structure_lier_lieu_importe(int $structureId, array $d): void
{
    structure_import_creer_lieu($structureId, $d['nom'], structure_import_type_lieu($d['nom'], (string) ($d['type_lieu'] ?? '')), $d);
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
