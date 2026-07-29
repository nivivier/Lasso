<?php
// Handlers de routes du module booking (préfixes « structure_contact »/
// « structure_note »/« structure_tag »/« structure_lieu »/« lieu »/« mailing »).
// Inclus depuis index.php après lib/routes_evenements.php. S'appuie sur
// lib/booking.php. Deux routes (mailing_traiter / desinscription) sont
// publiques, protégées par jeton/signature — voir plus bas.

require_once __DIR__ . '/booking.php';

// ------------------------------------------------------------- CONTACTS
function route_structure_contact_ajouter(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('structures');
    }
    check_csrf();
    $structureId = (int) ($_POST['structure_id'] ?? 0);
    $contactId   = (int) ($_POST['contact_id'] ?? 0);
    $estAdministration = isset($_POST['est_administration']) ? 1 : 0;
    $champs = [
        'structure_id'       => $structureId,
        'prenom'             => trim($_POST['prenom'] ?? ''),
        'nom'                => trim($_POST['nom'] ?? ''),
        'role'               => trim($_POST['role'] ?? ''),
        'email'              => trim($_POST['email'] ?? ''),
        'telephone'          => trim($_POST['telephone'] ?? ''),
        'formulaire_url'     => trim($_POST['formulaire_url'] ?? ''),
        'langue'             => trim($_POST['langue'] ?? ''),
        'est_administration' => $estAdministration,
        'est_booking'        => isset($_POST['est_booking']) ? 1 : 0,
    ];
    // « Administration » est exclusif : un seul contact à la fois par structure
    // (utilisé comme destinataire par défaut de la facturation) — « booking »
    // ne l'est pas (le mailing peut cibler plusieurs contacts à la fois).
    if ($estAdministration) {
        db()->prepare('UPDATE structure_contacts SET est_administration = 0 WHERE structure_id = ?')->execute([$structureId]);
    }
    if ($contactId) {
        db()->prepare('UPDATE structure_contacts SET prenom=:prenom, nom=:nom, role=:role, email=:email,
                        telephone=:telephone, formulaire_url=:formulaire_url, langue=:langue,
                        est_administration=:est_administration, est_booking=:est_booking
                        WHERE id=' . (int) $contactId . ' AND structure_id=:structure_id')->execute($champs);
    } else {
        db()->prepare('INSERT INTO structure_contacts (structure_id, prenom, nom, role, email, telephone, formulaire_url, langue, est_administration, est_booking)
                        VALUES (:structure_id, :prenom, :nom, :role, :email, :telephone, :formulaire_url, :langue, :est_administration, :est_booking)')
            ->execute($champs);
    }
    $nomContact = trim($champs['prenom'] . ' ' . $champs['nom']);
    $nomContact = $nomContact !== '' ? $nomContact : ($champs['email'] !== '' ? $champs['email'] : 'contact');
    journaliser('structure', $structureId, 'edition', ($contactId ? 'Contact modifié : ' : 'Contact ajouté : ') . $nomContact);
    redirect('structure', ['id' => $structureId]);
}

function route_structure_contact_delete(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $id = (int) ($_POST['id'] ?? 0);
        $structureId = (int) ($_POST['structure_id'] ?? 0);
        $stmtC = db()->prepare('SELECT prenom, nom, email FROM structure_contacts WHERE id = ? AND structure_id = ?');
        $stmtC->execute([$id, $structureId]);
        $row = $stmtC->fetch();
        db()->prepare('DELETE FROM structure_contacts WHERE id = ? AND structure_id = ?')->execute([$id, $structureId]);
        if ($row) {
            $n = trim((string) $row['prenom'] . ' ' . (string) $row['nom']);
            $n = $n !== '' ? $n : ((string) $row['email'] !== '' ? (string) $row['email'] : 'contact');
            journaliser('structure', $structureId, 'edition', 'Contact supprimé : ' . $n);
        }
        redirect('structure', ['id' => $structureId]);
    }
    redirect('structures');
}

// ------------------------------------------------------------- NOTES (flux CRM)
function route_structure_note_ajouter(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('structures');
    }
    check_csrf();
    $structureId = (int) ($_POST['structure_id'] ?? 0);
    $contenu = trim($_POST['contenu'] ?? '');
    $estContact = isset($_POST['est_contact']) ? 1 : 0;
    if ($contenu !== '') {
        journaliser('structure', $structureId, $estContact ? 'mailing' : 'note', $contenu);
        if ($estContact) {
            structure_recalculer_dernier_contact($structureId);
        }
    }
    redirect('structure', ['id' => $structureId]);
}

// ------------------------------------------------------------- TAGS
function route_structure_tag_ajouter(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('structures');
    }
    check_csrf();
    $structureId = (int) ($_POST['structure_id'] ?? 0);
    $nom = trim($_POST['nom'] ?? '');
    if ($nom !== '') {
        $stmt = db()->prepare('SELECT id FROM structure_tags WHERE nom = ? COLLATE NOCASE');
        $stmt->execute([$nom]);
        $tagId = $stmt->fetchColumn();
        if ($tagId === false) {
            db()->prepare('INSERT INTO structure_tags (nom) VALUES (?)')->execute([$nom]);
            $tagId = (int) db()->lastInsertId();
        }
        db()->prepare('INSERT OR IGNORE INTO structure_tag_liens (structure_id, tag_id) VALUES (?, ?)')
            ->execute([$structureId, (int) $tagId]);
        journaliser('structure', $structureId, 'edition', 'Étiquette ajoutée : ' . $nom);
    }
    redirect('structure', ['id' => $structureId]);
}

function route_structure_tag_retirer(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $structureId = (int) ($_POST['structure_id'] ?? 0);
        $tagId = (int) ($_POST['tag_id'] ?? 0);
        $stmtT = db()->prepare('SELECT nom FROM structure_tags WHERE id = ?');
        $stmtT->execute([$tagId]);
        $nomTag = (string) ($stmtT->fetchColumn() ?: '');
        db()->prepare('DELETE FROM structure_tag_liens WHERE structure_id = ? AND tag_id = ?')->execute([$structureId, $tagId]);
        if ($nomTag !== '') {
            journaliser('structure', $structureId, 'edition', 'Étiquette retirée : ' . $nomTag);
        }
        redirect('structure', ['id' => $structureId]);
    }
    redirect('structures');
}

// ------------------------------------------------------------- ÉTIQUETTES
// Gestion des étiquettes de structures (Paramètres → Catégories → Étiquettes) :
// ajout, renommage, suppression. Liste plate triée par nom (pas d'ordre manuel).
// Les liens structure↔étiquette tombent en cascade à la suppression
// (structure_tag_liens ON DELETE CASCADE) : l'écran annonce le nombre de fiches
// concernées avant de confirmer.
function route_parametres_tags(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $section = $_POST['section'] ?? '';
        if ($section === 'add') {
            $nom = trim($_POST['nom'] ?? '');
            if ($nom !== '') {
                // Nom unique (insensible à la casse) : on ne crée pas de doublon.
                $existe = db()->prepare('SELECT 1 FROM structure_tags WHERE nom = ? COLLATE NOCASE');
                $existe->execute([$nom]);
                if (!$existe->fetchColumn()) {
                    db()->prepare('INSERT INTO structure_tags (nom) VALUES (?)')->execute([$nom]);
                }
            }
        } elseif ($section === 'edit') {
            $id = (int) ($_POST['id'] ?? 0);
            $nom = trim($_POST['nom'] ?? '');
            if ($nom !== '' && $id) {
                $conflit = db()->prepare('SELECT 1 FROM structure_tags WHERE nom = ? COLLATE NOCASE AND id <> ?');
                $conflit->execute([$nom, $id]);
                if (!$conflit->fetchColumn()) {
                    // Le lien porte l'id : renommer suffit, rien à propager.
                    db()->prepare('UPDATE structure_tags SET nom = ? WHERE id = ?')->execute([$nom, $id]);
                }
            }
        } elseif ($section === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id) {
                db()->prepare('DELETE FROM structure_tags WHERE id = ?')->execute([$id]);
            }
        }
        redirect('parametres_tags', ['ok' => 1]);
    }

    // Étiquettes + nombre de structures qui les portent (une seule requête).
    $lignes = db()->query(
        'SELECT t.id, t.nom, (SELECT COUNT(*) FROM structure_tag_liens l WHERE l.tag_id = t.id) AS nb
         FROM structure_tags t ORDER BY t.nom COLLATE NOCASE'
    )->fetchAll();

    render('parametres_tags', [
        'saved'  => isset($_GET['ok']),
        'lignes' => $lignes,
    ], 'Paramètres — Étiquettes');
}

// ------------------------------------------------------- CATÉGORIES DE LIEU (taxonomie)
// Gestion de la liste « catégories de lieu » (lieux.type) : ajout, renommage
// (met à jour les lieux qui l'utilisent), suppression (bloquée si utilisée ou si
// c'est la dernière), réordonnancement. Même patron que route_parametres_pays().
function route_parametres_lieux_categories(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $section = $_POST['section'] ?? '';
        if ($section === 'add') {
            $nom = trim($_POST['nom'] ?? '');
            if ($nom !== '') {
                $existe = db()->prepare('SELECT 1 FROM lieu_categories WHERE nom = ? COLLATE NOCASE');
                $existe->execute([$nom]);
                if (!$existe->fetchColumn()) {
                    $ordre = (int) db()->query('SELECT COALESCE(MAX(ordre),0)+1 FROM lieu_categories')->fetchColumn();
                    db()->prepare('INSERT INTO lieu_categories (nom, ordre) VALUES (?, ?)')->execute([$nom, $ordre]);
                }
            }
        } elseif ($section === 'edit') {
            $id = (int) ($_POST['id'] ?? 0);
            $nom = trim($_POST['nom'] ?? '');
            if ($nom !== '') {
                $stmt = db()->prepare('SELECT nom FROM lieu_categories WHERE id = ?');
                $stmt->execute([$id]);
                $ancien = $stmt->fetchColumn();
                $conflit = db()->prepare('SELECT 1 FROM lieu_categories WHERE nom = ? COLLATE NOCASE AND id <> ?');
                $conflit->execute([$nom, $id]);
                if ($ancien !== false && !$conflit->fetchColumn()) {
                    db()->beginTransaction();
                    db()->prepare('UPDATE lieu_categories SET nom = ? WHERE id = ?')->execute([$nom, $id]);
                    if ($ancien !== $nom) {
                        db()->prepare('UPDATE lieux SET type = ? WHERE type = ?')->execute([$nom, $ancien]);
                    }
                    db()->commit();
                }
            }
        } elseif ($section === 'move') {
            $id = (int) ($_POST['id'] ?? 0);
            $dir = ($_POST['dir'] ?? '') === 'up' ? 'up' : 'down';
            $ids = array_map('intval', db()->query('SELECT id FROM lieu_categories ORDER BY ordre, nom')->fetchAll(PDO::FETCH_COLUMN));
            $pos = array_search($id, $ids, true);
            $swap = $dir === 'up' ? $pos - 1 : $pos + 1;
            if ($pos !== false && $swap >= 0 && $swap < count($ids)) {
                [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
                $upd = db()->prepare('UPDATE lieu_categories SET ordre = ? WHERE id = ?');
                db()->beginTransaction();
                foreach ($ids as $i => $cid) {
                    $upd->execute([$i, $cid]);
                }
                db()->commit();
            }
        } elseif ($section === 'reorder') {
            $order = array_values(array_filter(array_map('intval', explode(',', $_POST['order'] ?? ''))));
            if ($order) {
                $upd = db()->prepare('UPDATE lieu_categories SET ordre = ? WHERE id = ?');
                db()->beginTransaction();
                foreach ($order as $i => $cid) {
                    $upd->execute([$i, $cid]);
                }
                db()->commit();
            }
        } elseif ($section === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            $stmt = db()->prepare('SELECT nom FROM lieu_categories WHERE id = ?');
            $stmt->execute([$id]);
            $nom = $stmt->fetchColumn();
            if ($nom !== false) {
                $total = (int) db()->query('SELECT COUNT(*) FROM lieu_categories')->fetchColumn();
                $ref = db()->prepare('SELECT COUNT(*) FROM lieux WHERE type = ?');
                $ref->execute([$nom]);
                $nbUtil = (int) $ref->fetchColumn();
                if ($total <= 1) {
                    redirect('parametres_lieux_categories', ['err' => 'derniere']); // jamais supprimer la dernière
                }
                if ($nbUtil > 0) {
                    // Réaffecter les lieux vers une autre catégorie avant suppression.
                    $cible = trim($_POST['reaffecter_vers'] ?? '');
                    $ok = db()->prepare('SELECT 1 FROM lieu_categories WHERE nom = ? COLLATE NOCASE');
                    $ok->execute([$cible]);
                    if ($cible !== '' && strcasecmp($cible, (string) $nom) !== 0 && $ok->fetchColumn()) {
                        db()->beginTransaction();
                        db()->prepare('UPDATE lieux SET type = ? WHERE type = ?')->execute([$cible, $nom]);
                        db()->prepare('DELETE FROM lieu_categories WHERE id = ?')->execute([$id]);
                        db()->commit();
                    } else {
                        redirect('parametres_lieux_categories', ['err' => 'used']);
                    }
                } else {
                    db()->prepare('DELETE FROM lieu_categories WHERE id = ?')->execute([$id]);
                }
            }
        }
        redirect('parametres_lieux_categories', ['ok' => 1]);
    }

    render('parametres_lieux_categories', [
        'saved' => isset($_GET['ok']),
        'err' => $_GET['err'] ?? null,
        'lignes' => db()->query(
            "SELECT lc.id, lc.nom, (SELECT COUNT(*) FROM lieux l WHERE l.type = lc.nom) AS nb
             FROM lieu_categories lc ORDER BY lc.ordre, lc.nom"
        )->fetchAll(),
    ], 'Paramètres — Catégories de lieu');
}

// ------------------------------------------------------------- LIEUX (salles/festivals)
function lieu_creer_depuis_post(string $prefixe): int
{
    db()->prepare("INSERT INTO lieux (type, nom, ville, region, pays, mois_debut, mois_fin, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([
            lieu_categorie_valide((string) ($_POST[$prefixe . 'type'] ?? '')),
            trim($_POST[$prefixe . 'nom'] ?? ''),
            trim($_POST[$prefixe . 'ville'] ?? ''),
            trim($_POST[$prefixe . 'region'] ?? ''),
            trim($_POST[$prefixe . 'pays'] ?? ''),
            ($_POST[$prefixe . 'mois_debut'] ?? '') !== '' ? (int) $_POST[$prefixe . 'mois_debut'] : null,
            ($_POST[$prefixe . 'mois_fin'] ?? '') !== '' ? (int) $_POST[$prefixe . 'mois_fin'] : null,
            '',
        ]);
    return (int) db()->lastInsertId();
}

function route_structure_lieu_lier(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('structures');
    }
    check_csrf();
    $structureId = (int) ($_POST['structure_id'] ?? 0);
    $lieuRaw = (string) ($_POST['lieu_id'] ?? '');
    if ($lieuRaw === '__new__') {
        if (trim($_POST['nl_nom'] ?? '') === '') {
            redirect('structure', ['id' => $structureId]);
        }
        $lieuId = lieu_creer_depuis_post('nl_');
    } else {
        $lieuId = (int) $lieuRaw;
        $stmt = db()->prepare('SELECT 1 FROM lieux WHERE id = ?');
        $stmt->execute([$lieuId]);
        if (!$stmt->fetchColumn()) {
            redirect('structure', ['id' => $structureId]);
        }
    }
    db()->prepare('INSERT OR IGNORE INTO structure_lieux (structure_id, lieu_id) VALUES (?, ?)')
        ->execute([$structureId, $lieuId]);
    journaliser_lien_structure_lieu($structureId, $lieuId, true);
    redirect('structure', ['id' => $structureId]);
}

function route_structure_lieu_delier(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $structureId = (int) ($_POST['structure_id'] ?? 0);
        $lieuId = (int) ($_POST['lieu_id'] ?? 0);
        journaliser_lien_structure_lieu($structureId, $lieuId, false);
        db()->prepare('DELETE FROM structure_lieux WHERE structure_id = ? AND lieu_id = ?')->execute([$structureId, $lieuId]);
        redirect('structure', ['id' => $structureId]);
    }
    redirect('structures');
}

// Filtres de la liste des lieux (?p=lieux) — factorisé pour être réutilisé par
// la vue carte (mêmes critères, résultat non paginé, voir lieux_carte_points()).
// Mêmes conventions que ?p=structures : type, ville, jauge (bornes de
// capacité), mois d'événement / de programmation (le mois choisi doit tomber
// dans la plage du lieu, en gérant le passage d'année comme periode_chevauche()).
// Chaque filtre structuré est mémorisé en session (filtre_persistant(), comme
// evenements_lire_filtres()) : revenir sur ?p=lieux sans query string (lien de
// la sidebar, retour contextuel…) rouvre les derniers filtres actifs. Seule la
// recherche texte (q) ne l'est jamais, comme pagination_page().
function lieux_filtres(): array
{
    $type = lieu_categorie_normaliser((string) filtre_persistant('type', 'lieux_type', '')) ?? '';
    $ville = trim((string) filtre_persistant('ville', 'lieux_ville', ''));
    $pays = trim((string) filtre_persistant('pays', 'lieux_pays', ''));
    $grandeRegion = trim((string) filtre_persistant('grande_region', 'lieux_grande_region', ''));
    $jaugeMinBrut = (string) filtre_persistant('jauge_min', 'lieux_jauge_min', '');
    $jaugeMin = $jaugeMinBrut !== '' ? max(0, (int) $jaugeMinBrut) : null;
    $jaugeMaxBrut = (string) filtre_persistant('jauge_max', 'lieux_jauge_max', '');
    $jaugeMax = $jaugeMaxBrut !== '' ? max(0, (int) $jaugeMaxBrut) : null;
    $moisEvenement = (int) filtre_persistant('mois_evenement', 'lieux_mois_evenement', 0);
    $moisProg = (int) filtre_persistant('mois_prog', 'lieux_mois_prog', 0);
    if ($moisEvenement < 1 || $moisEvenement > 12) { $moisEvenement = 0; }
    if ($moisProg < 1 || $moisProg > 12) { $moisProg = 0; }
    // Statut : « actif » par défaut (les lieux inactifs sont du bruit dans le
    // travail courant) ; 'inactif' ou 'tous' pour les voir.
    $statut = valeur_autorisee((string) filtre_persistant('statut', 'lieux_statut', 'actif'), ['actif', 'inactif', 'tous'], 'actif');
    // Villes jamais géolocalisées avec succès (cache lieux_geocodage) — filtre
    // d'appoint pour traiter les cas où Nominatim ne trouve pas la ville
    // (typo, lieu-dit trop précis…), accessible depuis le lien de la vue carte
    // (voir views/_lieux_carte.php). Jamais mémorisé en session : lien
    // ponctuel, pas un mode de travail courant.
    $nonLocalises = ($_GET['non_localises'] ?? '') === '1';

    $where = '';
    $params = [];
    if ($nonLocalises) {
        $where .= " AND TRIM(ville) <> '' AND (LOWER(TRIM(ville)) || '|' || LOWER(TRIM(pays))) NOT IN "
            . "(SELECT cle FROM lieux_geocodage WHERE statut = 'ok')";
    }
    if ($statut === 'actif') {
        $where .= ' AND actif = 1';
    } elseif ($statut === 'inactif') {
        $where .= ' AND actif = 0';
    }
    if ($type !== '') {
        $where .= ' AND type = ?';
        $params[] = $type;
    }
    if ($ville !== '') {
        $where .= ' AND ville = ?';
        $params[] = $ville;
    }
    if ($pays !== '') {
        $where .= ' AND pays = ?';
        $params[] = $pays;
    }
    if ($grandeRegion !== '') {
        $where .= ' AND grande_region = ?';
        $params[] = $grandeRegion;
    }
    // Capacité = fourchette [jauge_min, jauge_max] du lieu ; un lieu sans jauge
    // est exclu dès qu'un critère de jauge est actif. Chevauchement avec la
    // fourchette demandée. Bornes injectées telles quelles (déjà validées en
    // entiers) : un paramètre PDO serait lié en texte, or SQLite classe tout
    // entier avant tout texte, donc « jauge_max >= '500' » ne matcherait jamais.
    if ($jaugeMin !== null) {
        $where .= ' AND COALESCE(jauge_max, jauge_min, 0) >= ' . $jaugeMin;
    }
    if ($jaugeMax !== null) {
        $where .= ' AND COALESCE(jauge_min, jauge_max) IS NOT NULL AND COALESCE(jauge_min, jauge_max) <= ' . $jaugeMax;
    }
    // Le mois choisi doit être dans la plage [début, fin] du lieu ; plage
    // renseignée aux deux bornes, passage d'année géré (ex. nov→fév). $mois est
    // un entier validé (1–12), injecté directement pour la même raison d'affinité.
    $filtreMois = function (string $colDebut, string $colFin, int $mois) use (&$where): void {
        $where .= " AND $colDebut IS NOT NULL AND $colFin IS NOT NULL AND ("
            . "($colDebut <= $colFin AND $mois BETWEEN $colDebut AND $colFin) OR "
            . "($colDebut > $colFin AND ($mois >= $colDebut OR $mois <= $colFin)))";
    };
    if ($moisEvenement) { $filtreMois('mois_evenement_debut', 'mois_evenement_fin', $moisEvenement); }
    if ($moisProg) { $filtreMois('mois_debut', 'mois_fin', $moisProg); }

    return [
        'where' => $where, 'params' => $params,
        'type' => $type, 'ville' => $ville, 'pays' => $pays, 'grandeRegion' => $grandeRegion,
        'jaugeMin' => $jaugeMin, 'jaugeMax' => $jaugeMax, 'moisEvenement' => $moisEvenement, 'moisProg' => $moisProg,
        'statut' => $statut, 'nonLocalises' => $nonLocalises,
    ];
}

// Points de la vue carte (?p=lieux&vue=carte) : lieux filtrés (mêmes critères
// que la liste, $where/$params de lieux_filtres()), groupés par ville
// géolocalisée — jamais paginé (un marqueur regroupe déjà tous les lieux d'une
// même ville, la carte doit montrer la totalité du résultat filtré). Un lieu
// dont la ville n'est pas encore dans le cache de géocodage (lib/geocodage.php)
// est compté à part plutôt qu'ignoré silencieusement.
// Retourne [points, nbLieuxNonGeolocalises].
function lieux_carte_points(string $where, array $params): array
{
    [$rechSql, $rechParams] = recherche_sql(['nom', 'ville', 'region', 'grande_region', 'type']);
    $stmt = db()->prepare("SELECT id, nom, type, ville, pays FROM lieux WHERE ville <> ''" . $where . $rechSql . ' ORDER BY ville, nom');
    $stmt->execute(array_merge($params, $rechParams));

    $parCle = [];
    $nonGeolocalises = 0;
    foreach ($stmt->fetchAll() as $r) {
        $geo = geocodage_lire((string) $r['ville'], (string) $r['pays']);
        if (!$geo || $geo['statut'] !== 'ok') {
            $nonGeolocalises++;
            continue;
        }
        $cle = geocodage_cle((string) $r['ville'], (string) $r['pays']);
        if (!isset($parCle[$cle])) {
            $parCle[$cle] = [
                'lat' => (float) $geo['latitude'], 'lon' => (float) $geo['longitude'],
                'ville' => (string) $r['ville'], 'pays' => (string) $r['pays'], 'lieux' => [],
            ];
        }
        $parCle[$cle]['lieux'][] = ['id' => (int) $r['id'], 'nom' => (string) $r['nom'], 'type' => (string) $r['type']];
    }
    return [array_values($parCle), $nonGeolocalises];
}

function route_lieux(): void
{
    require_login();
    $recherche = trim((string) ($_GET['q'] ?? ''));
    // Mémorise la dernière vue utilisée (comme pagination_taille()) : un lien
    // « Lieux » sans ?vue= explicite (sidebar) rouvre la dernière consultée.
    $vue = filtre_persistant('vue', 'lieux_vue', 'liste') === 'carte' ? 'carte' : 'liste';
    $pgTaille = pagination_taille('lieux_taille');

    // Modification/suppression groupée (sélection + barre flottante), même esprit
    // que les structures ci-dessus — un lieu lié à une structure est ignoré (jamais
    // supprimé en masse par erreur) plutôt que de bloquer toute la sélection.
    $retourFiltres = ['q' => $recherche];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $section = $_POST['section'] ?? '';
        if ($section === 'bulk_undo') {
            $r = bulk_undo_appliquer();
            redirect($r['route'] ?? 'lieux', ($r['retour'] ?? $retourFiltres) + ($r ? ['ok' => 'annule'] : []));
        }
        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
        $lieuxBloques = 0;
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            unset($_SESSION['bulk_undo']);
            if ($section === 'delete') {
                $stmtRef = db()->prepare("SELECT DISTINCT lieu_id FROM structure_lieux WHERE lieu_id IN ($in)");
                $stmtRef->execute($ids);
                $refs = array_map('intval', $stmtRef->fetchAll(PDO::FETCH_COLUMN));
                $idsSupprimables = array_values(array_diff($ids, $refs));
                if ($idsSupprimables) {
                    $inSup = implode(',', array_fill(0, count($idsSupprimables), '?'));
                    db()->prepare("DELETE FROM lieux WHERE id IN ($inSup)")->execute($idsSupprimables);
                }
                $lieuxBloques = count($refs);
            } elseif ($section === 'type' && lieu_categorie_normaliser((string) ($_POST['bulk_type'] ?? '')) !== null) {
                bulk_undo_memoriser('lieux', $ids, ['type'], 'lieux', $retourFiltres);
                db()->prepare("UPDATE lieux SET type = ? WHERE id IN ($in)")
                    ->execute(array_merge([lieu_categorie_valide((string) $_POST['bulk_type'])], $ids));
            } elseif ($section === 'ville') {
                bulk_undo_memoriser('lieux', $ids, ['ville'], 'lieux', $retourFiltres);
                db()->prepare("UPDATE lieux SET ville = ? WHERE id IN ($in)")
                    ->execute(array_merge([trim($_POST['bulk_ville'] ?? '')], $ids));
            } elseif ($section === 'region') {
                bulk_undo_memoriser('lieux', $ids, ['region'], 'lieux', $retourFiltres);
                db()->prepare("UPDATE lieux SET region = ? WHERE id IN ($in)")
                    ->execute(array_merge([trim($_POST['bulk_region'] ?? '')], $ids));
            } elseif ($section === 'pays') {
                bulk_undo_memoriser('lieux', $ids, ['pays'], 'lieux', $retourFiltres);
                db()->prepare("UPDATE lieux SET pays = ? WHERE id IN ($in)")
                    ->execute(array_merge([trim($_POST['bulk_pays'] ?? '')], $ids));
            }
            if (!in_array($section, ['delete', ''], true) && isset($_SESSION['bulk_undo'])) {
                $retourFiltres['bulk'] = count($ids);
            }
        }
        $retourFiltres['lieuxBloquees'] = $lieuxBloques;
        redirect('lieux', $retourFiltres);
    }

    $f = lieux_filtres();
    $where = $f['where'];
    $params = $f['params'];
    $type = $f['type'];
    $ville = $f['ville'];
    $pays = $f['pays'];
    $grandeRegion = $f['grandeRegion'];
    $jaugeMin = $f['jaugeMin'];
    $jaugeMax = $f['jaugeMax'];
    $moisEvenement = $f['moisEvenement'];
    $moisProg = $f['moisProg'];
    $statut = $f['statut'];
    $nonLocalises = $f['nonLocalises'];

    if ($vue === 'carte') {
        [$cartePoints, $carteVillesManquantes] = lieux_carte_points($where, $params);
        render('lieux_liste', [
            'vue' => $vue, 'cartePoints' => $cartePoints, 'carteVillesManquantes' => $carteVillesManquantes,
            'lieux' => [], 'organisateurs' => [], 'nbEvenements' => [],
            'recherche' => $recherche, 'type' => $type, 'categoriesLieu' => lieu_categories_liste(),
            'ville' => $ville, 'pays' => $pays, 'grandeRegion' => $grandeRegion, 'statut' => $statut,
            'jaugeMin' => $jaugeMin, 'jaugeMax' => $jaugeMax, 'moisEvenement' => $moisEvenement, 'moisProg' => $moisProg,
            'nonLocalises' => $nonLocalises,
            'villesDispo' => [], 'grandesRegionsDispo' => [],
            'modeClient' => true, 'pgRoute' => 'lieux', 'pgParams' => [], 'pgPage' => 1, 'pgTaille' => $pgTaille, 'pgTotal' => 0,
            'bulkCount' => null, 'okAnnule' => false,
        ], 'Lieux');
        return;
    }

    $stmtTotFiltres = db()->prepare('SELECT COUNT(*) FROM lieux WHERE 1=1' . $where);
    $stmtTotFiltres->execute($params);
    $totalSansRecherche = (int) $stmtTotFiltres->fetchColumn();
    $modeClient = pagination_mode_client($totalSansRecherche);

    if ($modeClient) {
        $stmt = db()->prepare('SELECT * FROM lieux WHERE 1=1' . $where . ' ORDER BY type, nom');
        $stmt->execute($params);
        $lieux = $stmt->fetchAll();
        $pgPage  = 1;
        $pgTotal = $totalSansRecherche;
    } else {
        [$rechSql, $rechParams] = recherche_sql(['nom', 'ville', 'region', 'grande_region', 'type']);
        $whereRech = $where . $rechSql;
        $paramsRech = array_merge($params, $rechParams);

        $stmtTot = db()->prepare('SELECT COUNT(*) FROM lieux WHERE 1=1' . $whereRech);
        $stmtTot->execute($paramsRech);
        $pgTotal = (int) $stmtTot->fetchColumn();

        $pgPage = pagination_page();
        [$limitSql, $limitParams] = pagination_sql($pgPage, $pgTaille);

        $stmt = db()->prepare('SELECT * FROM lieux WHERE 1=1' . $whereRech . ' ORDER BY type, nom' . $limitSql);
        $stmt->execute(array_merge($paramsRech, $limitParams));
        $lieux = $stmt->fetchAll();
    }

    // Organisateur(s) de chaque lieu affiché — une seule requête (pas de N+1),
    // regroupés par lieu_id pour la colonne « Organisateur » de la liste.
    $organisateurs = [];
    if ($lieux) {
        $lieuIds = array_map(fn ($l) => (int) $l['id'], $lieux);
        $inOrg = implode(',', array_fill(0, count($lieuIds), '?'));
        $stmtOrg = db()->prepare(
            "SELECT sl.lieu_id, s.id, s.nom FROM structure_lieux sl
             JOIN structures s ON s.id = sl.structure_id
             WHERE sl.lieu_id IN ($inOrg) ORDER BY s.nom"
        );
        $stmtOrg->execute($lieuIds);
        foreach ($stmtOrg->fetchAll() as $r) {
            $organisateurs[(int) $r['lieu_id']][] = ['id' => (int) $r['id'], 'nom' => (string) $r['nom']];
        }
    }

    $villesDispo = db()->query("SELECT DISTINCT ville FROM lieux WHERE ville <> '' ORDER BY ville")->fetchAll(PDO::FETCH_COLUMN);
    $grandesRegionsDispo = pays_regions_map(); // régions groupées par pays (taxonomie, migration_49)

    render('lieux_liste', [
        'vue' => $vue,
        'cartePoints' => [],
        'carteVillesManquantes' => 0,
        'lieux' => $lieux,
        'organisateurs' => $organisateurs,
        'nbEvenements' => lieux_nb_evenements(array_column($lieux, 'id')),
        'recherche' => $recherche,
        'type' => $type,
        'categoriesLieu' => lieu_categories_liste(),
        'ville' => $ville,
        'pays' => $pays,
        'grandeRegion' => $grandeRegion,
        'statut' => $statut,
        'jaugeMin' => $jaugeMin,
        'jaugeMax' => $jaugeMax,
        'moisEvenement' => $moisEvenement,
        'moisProg' => $moisProg,
        'nonLocalises' => $nonLocalises,
        'villesDispo' => $villesDispo,
        'grandesRegionsDispo' => $grandesRegionsDispo,
        'modeClient' => $modeClient,
        'pgRoute'   => 'lieux',
        'pgParams'  => ['q' => $recherche, 'type' => $type, 'ville' => $ville, 'pays' => $pays, 'grande_region' => $grandeRegion, 'statut' => $statut, 'jauge_min' => $jaugeMin ?? '', 'jauge_max' => $jaugeMax ?? '', 'mois_evenement' => $moisEvenement ?: '', 'mois_prog' => $moisProg ?: '', 'non_localises' => $nonLocalises ? 1 : ''],
        'pgPage'    => $pgPage,
        'pgTaille'  => $pgTaille,
        'pgTotal'   => $pgTotal,
        'bulkCount' => isset($_GET['bulk']) ? (int) $_GET['bulk'] : null,
        'okAnnule'  => ($_GET['ok'] ?? '') === 'annule',
    ], 'Lieux');
}

// Structure(s) rattachée(s) à un lieu, avec leur contexte CRM (contacts,
// dernières notes, autres salles/festivals de la même structure) — affiché en
// lecture seule sur la fiche lieu (l'édition reste sur la fiche structure).
function lieu_structures_liees(int $lieuId): array
{
    $stmt = db()->prepare(
        'SELECT s.* FROM structures s JOIN structure_lieux sl ON sl.structure_id = s.id
         WHERE sl.lieu_id = ? ORDER BY s.nom'
    );
    $stmt->execute([$lieuId]);
    $out = [];
    foreach ($stmt->fetchAll() as $s) {
        $sid = (int) $s['id'];
        $contacts = db()->prepare('SELECT * FROM structure_contacts WHERE structure_id = ? ORDER BY actif DESC, id');
        $contacts->execute([$sid]);
        $notes = db()->prepare(
            "SELECT h.*, u.prenom AS u_prenom, u.nom AS u_nom FROM historique h
             LEFT JOIN utilisateurs u ON u.id = h.utilisateur_id
             WHERE h.entite_type = 'structure' AND h.entite_id = ? ORDER BY h.cree_le DESC, h.id DESC LIMIT 8"
        );
        $notes->execute([$sid]);
        $autresLieux = db()->prepare(
            'SELECT l.* FROM lieux l JOIN structure_lieux sl ON sl.lieu_id = l.id
             WHERE sl.structure_id = ? AND l.id <> ? ORDER BY l.type, l.nom'
        );
        $autresLieux->execute([$sid, $lieuId]);
        $out[] = [
            'structure'    => $s,
            'contacts'     => $contacts->fetchAll(),
            'notes'        => $notes->fetchAll(),
            'autres_lieux' => $autresLieux->fetchAll(),
        ];
    }
    return $out;
}

function route_lieu(): void
{
    require_login();
    $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
    $lieu = null;
    if ($id) {
        $stmt = db()->prepare(
            'SELECT l.*, (SELECT COUNT(*) FROM structure_lieux sl WHERE sl.lieu_id = l.id) AS nb_structures
             FROM lieux l WHERE l.id = ?'
        );
        $stmt->execute([$id]);
        $lieu = $stmt->fetch();
        if (!$lieu) {
            redirect('lieux');
        }
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $champs = [
            'type'       => lieu_categorie_valide((string) ($_POST['type'] ?? '')),
            'nom'        => trim($_POST['nom'] ?? ''),
            'ville'      => trim($_POST['ville'] ?? ''),
            'region'     => trim($_POST['region'] ?? ''),
            'grande_region' => trim($_POST['grande_region'] ?? ''),
            'pays'       => trim($_POST['pays'] ?? ''),
            'mois_debut' => ($_POST['mois_debut'] ?? '') !== '' ? (int) $_POST['mois_debut'] : null,
            'mois_fin'   => ($_POST['mois_fin'] ?? '') !== '' ? (int) $_POST['mois_fin'] : null,
            'mois_evenement_debut' => ($_POST['mois_evenement_debut'] ?? '') !== '' ? (int) $_POST['mois_evenement_debut'] : null,
            'mois_evenement_fin'   => ($_POST['mois_evenement_fin'] ?? '') !== '' ? (int) $_POST['mois_evenement_fin'] : null,
            'jauge_min'  => ($_POST['jauge_min'] ?? '') !== '' ? (int) $_POST['jauge_min'] : null,
            'jauge_max'  => ($_POST['jauge_max'] ?? '') !== '' ? (int) $_POST['jauge_max'] : null,
            'dernier_concert_le' => trim($_POST['dernier_concert_le'] ?? ''),
            'site_web'   => trim($_POST['site_web'] ?? ''),
            'notes'      => trim($_POST['notes'] ?? ''),
        ];
        // Grande région déduite du département/canton quand c'est possible
        // (France/Suisse hors cantons bilingues) : jamais laissée à la saisie
        // manuelle dans ce cas, quoi que le formulaire ait envoyé.
        $grandeRegionDeduite = grande_region_deduite($champs['pays'], $champs['region']);
        if ($grandeRegionDeduite !== null) {
            $champs['grande_region'] = $grandeRegionDeduite;
        }
        $err = $champs['nom'] === '' ? 'Le nom est obligatoire.' : null;
        if ($err) {
            render('lieu_form', ['lieu' => array_merge((array) $lieu, $champs, ['id' => $id]), 'err' => $err,
                'structuresLiees' => $id ? lieu_structures_liees($id) : [],
                'categoriesLieu' => lieu_categories_liste()], 'Lieu');
            return;
        }
        pays_region_assurer($champs['pays'], $champs['grande_region']);
        if ($id) {
            $champs['id'] = $id;
            // actif n'est PAS touché ici : il est géré à part par le bloc « Statut »
            // de la sidebar (route_lieu_statut), sinon chaque enregistrement de la
            // fiche le réinitialiserait.
            db()->prepare('UPDATE lieux SET type=:type, nom=:nom, ville=:ville, region=:region, grande_region=:grande_region, pays=:pays,
                            mois_debut=:mois_debut, mois_fin=:mois_fin,
                            mois_evenement_debut=:mois_evenement_debut, mois_evenement_fin=:mois_evenement_fin,
                            jauge_min=:jauge_min, jauge_max=:jauge_max, dernier_concert_le=:dernier_concert_le,
                            site_web=:site_web, notes=:notes WHERE id=:id')->execute($champs);
            // Historique (module booking) : diff des champs + « dernier concert »
            // à part si sa date a changé pour une valeur non vide.
            if (module_actif('booking')) {
                journaliser_diff('lieu', $id, (array) $lieu, $champs, [
                    'nom' => 'Nom', 'type' => 'Type', 'ville' => 'Ville',
                    'region' => 'Département / canton', 'grande_region' => 'Région', 'pays' => 'Pays',
                    'jauge_min' => 'Jauge min', 'jauge_max' => 'Jauge max',
                    'site_web' => 'Site web', 'notes' => 'Notes',
                ]);
                $ancienDC = trim((string) ($lieu['dernier_concert_le'] ?? ''));
                if ($champs['dernier_concert_le'] !== '' && $champs['dernier_concert_le'] !== $ancienDC) {
                    journaliser('lieu', $id, 'dernier_concert', 'Dernier concert / diffusion : ' . $champs['dernier_concert_le']);
                }
            }
        } else {
            db()->prepare('INSERT INTO lieux (type, nom, ville, region, grande_region, pays, mois_debut, mois_fin,
                            mois_evenement_debut, mois_evenement_fin, jauge_min, jauge_max, dernier_concert_le, site_web, notes)
                            VALUES (:type, :nom, :ville, :region, :grande_region, :pays, :mois_debut, :mois_fin,
                            :mois_evenement_debut, :mois_evenement_fin, :jauge_min, :jauge_max, :dernier_concert_le, :site_web, :notes)')->execute($champs);
        }
        redirect('lieux');
    }
    render('lieu_form', [
        'lieu' => $lieu,
        'err' => null,
        'evenementsLies' => $id ? lieu_evenements($id) : [],
        'historique' => $id ? historique_fusionne('lieu', $id) : [],
        'structuresLiees' => $id ? lieu_structures_liees($id) : [],
        // La liste des structures (potentiellement des milliers) n'est plus
        // injectée dans la page : le sélecteur d'organisateur la charge à la
        // demande via ?p=structures_options (route_structures_options).
        'categoriesLieu' => lieu_categories_liste(),
    ], $id ? 'Modifier le lieu' : 'Nouveau lieu');
}

// Liste JSON { id, nom } de toutes les structures, pour alimenter à la demande
// le sélecteur d'organisateur de la fiche lieu (évite d'injecter des milliers
// de <li> dans chaque page). Lecture seule, GET.
function route_structures_options(): void
{
    require_login();
    header('Content-Type: application/json; charset=utf-8');
    $rows = db()->query('SELECT id, nom FROM structures ORDER BY nom')->fetchAll();
    echo json_encode(
        array_map(fn ($r) => ['id' => (int) $r['id'], 'nom' => (string) $r['nom']], $rows),
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

// Liste JSON { id, nom } des lieux (nom suffixé de la ville pour distinguer les
// homonymes), pour alimenter à la demande le sélecteur de lieu d'un événement.
// Lecture seule, GET.
function route_lieux_options(): void
{
    require_login();
    header('Content-Type: application/json; charset=utf-8');
    $rows = db()->query('SELECT id, nom, ville FROM lieux ORDER BY nom, ville')->fetchAll();
    echo json_encode(
        array_map(fn ($r) => [
            'id'  => (int) $r['id'],
            'nom' => (string) $r['nom'] . ((string) $r['ville'] !== '' ? ' — ' . (string) $r['ville'] : ''),
        ], $rows),
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

// Change (ou définit) l'organisateur d'un lieu depuis la fiche lieu : retire le
// lien vers l'ancienne structure (si fourni) et lie la nouvelle. Réciproque de
// structure_lieu_lier/délier côté structure. La relation reste many-to-many :
// on ne touche qu'au lien désigné (ancien_structure_id), pas aux autres.
function route_lieu_organisateur(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('lieux'); }
    check_csrf();
    $lieuId = (int) ($_POST['lieu_id'] ?? 0);
    $ancien = (int) ($_POST['ancien_structure_id'] ?? 0);
    $nouveau = (int) ($_POST['structure_id'] ?? 0);
    if (!$lieuId || !$nouveau) { redirect('lieu', ['id' => $lieuId]); }
    // La nouvelle structure doit exister.
    $ok = db()->prepare('SELECT 1 FROM structures WHERE id = ?');
    $ok->execute([$nouveau]);
    if (!$ok->fetchColumn()) { redirect('lieu', ['id' => $lieuId]); }
    db()->beginTransaction();
    if ($ancien && $ancien !== $nouveau) {
        db()->prepare('DELETE FROM structure_lieux WHERE lieu_id = ? AND structure_id = ?')->execute([$lieuId, $ancien]);
        journaliser_lien_structure_lieu($ancien, $lieuId, false);
    }
    db()->prepare('INSERT OR IGNORE INTO structure_lieux (structure_id, lieu_id) VALUES (?, ?)')->execute([$nouveau, $lieuId]);
    if ($ancien !== $nouveau) {
        journaliser_lien_structure_lieu($nouveau, $lieuId, true);
    }
    db()->commit();
    redirect('lieu', ['id' => $lieuId]);
}

// Géocode un lot de villes encore manquantes (bouton de la vue carte,
// ?p=lieux&vue=carte) — voir lib/geocodage.php. Un lot reste volontairement
// petit (politique Nominatim : 1 requête/seconde) ; l'utilisateur reclique
// (ou le JS de la vue le fait pour lui) jusqu'à ce qu'il n'en reste plus.
function route_lieux_geocoder(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('lieux', ['vue' => 'carte']); }
    check_csrf();
    $n = geocodage_traiter_lot(fn () => geocodage_villes_manquantes('lieux', 'ville', 'pays'));
    // Reprend les filtres actifs (transmis en champs cachés par la vue carte) pour
    // que le clic « Géocoder » n'en fasse pas perdre le fil.
    $retour = array_intersect_key($_POST, array_flip([
        'q', 'type', 'ville', 'pays', 'grande_region', 'statut', 'jauge_min', 'jauge_max', 'mois_evenement', 'mois_prog',
    ]));
    redirect('lieux', $retour + ['vue' => 'carte', 'geocode' => $n]);
}

// Géocode une seule ville (bouton « Géocoder cette ville », mini-carte de
// localisation sur ?p=lieu/?p=structure/?p=evenement — voir _mini_carte.php).
// $retour_route est restreint à une liste blanche : jamais d'URL arbitraire
// fournie par le POST (redirect() reconstruit l'URL depuis la route + id).
function route_geocoder_ville_unique(): void
{
    require_login();
    $retourRoute = in_array($_POST['retour_route'] ?? '', ['lieu', 'structure', 'evenement'], true)
        ? $_POST['retour_route'] : 'resumes';
    $retourId = (int) ($_POST['retour_id'] ?? 0);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect($retourRoute, $retourId ? ['id' => $retourId] : []);
    }
    check_csrf();
    $ville = trim($_POST['ville'] ?? '');
    $pays = trim($_POST['pays'] ?? '');
    if ($ville !== '') {
        geocodage_geocoder_ville($ville, $pays);
    }
    redirect($retourRoute, $retourId ? ['id' => $retourId] : []);
}

// Bascule immédiate actif/inactif d'un lieu (bloc « Statut » de la sidebar),
// sans passer par le bouton Enregistrer de la fiche (cf. route_structure_statut).
function route_lieu_statut(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('lieux'); }
    check_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    if (!$id) { redirect('lieux'); }
    $actif = isset($_POST['actif']) ? 1 : 0;
    $avant = (int) (db()->query('SELECT actif FROM lieux WHERE id = ' . $id)->fetchColumn());
    db()->prepare('UPDATE lieux SET actif = ? WHERE id = ?')->execute([$actif, $id]);
    if ($avant !== $actif) {
        journaliser('lieu', $id, 'edition', 'Statut : ' . ($actif ? 'actif' : 'inactif'));
    }
    redirect('lieu', ['id' => $id]);
}

function route_lieu_renommer(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('lieux'); }
    check_csrf();
    $id  = (int) ($_POST['id'] ?? 0);
    $nom = trim($_POST['nom'] ?? '');
    if ($id && $nom !== '') {
        $ancien = nom_entite('lieux', $id);
        db()->prepare('UPDATE lieux SET nom = ? WHERE id = ?')->execute([$nom, $id]);
        if ($ancien !== $nom) {
            journaliser('lieu', $id, 'edition', 'Nom : ' . ($ancien !== '' ? $ancien : '(vide)') . ' → ' . $nom);
        }
    }
    redirect('lieu', ['id' => $id]);
}

function route_lieu_delete(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $id = (int) ($_POST['id'] ?? 0);
        if ($id) {
            // Un lieu lié à des structures est tout de même supprimable : on
            // retire d'abord les liens (journalisés côté structure) et
            // l'historique du lieu, puis le lieu lui-même (les événements liés
            // sont dénoués par la clé étrangère ON DELETE SET NULL).
            $lNom = nom_entite('lieux', $id);
            $stmt = db()->prepare('SELECT structure_id FROM structure_lieux WHERE lieu_id = ?');
            $stmt->execute([$id]);
            db()->beginTransaction();
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $structureId) {
                journaliser('structure', (int) $structureId, 'edition', 'Lieu supprimé : ' . ($lNom !== '' ? $lNom : '#' . $id));
            }
            db()->prepare('DELETE FROM structure_lieux WHERE lieu_id = ?')->execute([$id]);
            db()->prepare("DELETE FROM historique WHERE entite_type = 'lieu' AND entite_id = ?")->execute([$id]);
            db()->prepare('DELETE FROM lieux WHERE id = ?')->execute([$id]);
            db()->commit();
        }
    }
    redirect('lieux');
}

// ------------------------------------------------------------- MAILING
// Jeton du traitement de la file d'attente (même mécanisme que
// evenements_export_token()) et signature de désinscription (sans état,
// vérifiable sans stocker de jeton par destinataire).
function mailing_traiter_token(): string
{
    $token = (string) param('mailing_traiter_token', '');
    if ($token === '') {
        $token = bin2hex(random_bytes(16));
        db()->prepare('INSERT OR REPLACE INTO parametres (cle, valeur) VALUES (?, ?)')->execute(['mailing_traiter_token', $token]);
    }
    return $token;
}

function mailing_regenerer_token(): string
{
    $token = bin2hex(random_bytes(16));
    db()->prepare('INSERT OR REPLACE INTO parametres (cle, valeur) VALUES (?, ?)')->execute(['mailing_traiter_token', $token]);
    return $token;
}

function desinscription_secret(): string
{
    $s = (string) param('desinscription_secret', '');
    if ($s === '') {
        $s = bin2hex(random_bytes(16));
        db()->prepare('INSERT OR REPLACE INTO parametres (cle, valeur) VALUES (?, ?)')->execute(['desinscription_secret', $s]);
    }
    return $s;
}

function desinscription_signature(int $structureId, ?int $contactId): string
{
    return hash_hmac('sha256', $structureId . ':' . ($contactId ?? 0), desinscription_secret());
}

function desinscription_url(int $structureId, ?int $contactId): string
{
    $scheme = is_https() ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $qs = 'p=desinscription&structure_id=' . $structureId . '&sig=' . desinscription_signature($structureId, $contactId);
    if ($contactId) {
        $qs .= '&contact_id=' . $contactId;
    }
    return $scheme . '://' . $host . '/?' . $qs;
}

// --- Paramètres — Catégories/sous-catégories de structures --------------------
// Arbre à 2 niveaux (structure_categories.parent_id, voir migration_42) : une
// sous-catégorie est toujours imbriquée dans une catégorie racine — jamais
// promue racine, jamais nichée sous une autre sous-catégorie (contrôlé ici,
// pas par le schéma). Même interface de glisser-déposer que spectacles.php et
// compta_plan.php (lassoPlanArbre(), voir assets/app.js) : add/rename+reparent
// (« edit »)/move (haut/bas, repli sans JS)/reorder (glisser-déposer)/delete.
// Renommer une catégorie/sous-catégorie met aussi à jour les structures qui
// l'utilisent déjà (comparaison par nom, pas par id). Suppression refusée si
// des structures l'utilisent encore, ou si une catégorie a encore des
// sous-catégories (pas de suppression en cascade silencieuse).
function route_parametres_structures(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $section = $_POST['section'] ?? '';
        $map = structure_categorie_map();
        if ($section === 'add') {
            $nom = trim($_POST['nom'] ?? '');
            $parent = ($_POST['parent_id'] ?? '') === '' ? null : (int) $_POST['parent_id'];
            // Une sous-catégorie ne peut être créée que sous une catégorie racine.
            $parentValide = $parent === null || (isset($map[$parent]) && plan_pid($map[$parent]['parent_id'] ?? null) === 0);
            if ($nom !== '' && $parentValide) {
                if ($parent === null) {
                    $stmtExiste = db()->prepare('SELECT 1 FROM structure_categories WHERE parent_id IS NULL AND nom = ?');
                    $stmtExiste->execute([$nom]);
                    $stmtOrdre = db()->query('SELECT COALESCE(MAX(ordre),0)+1 FROM structure_categories WHERE parent_id IS NULL');
                } else {
                    $stmtExiste = db()->prepare('SELECT 1 FROM structure_categories WHERE parent_id = ? AND nom = ?');
                    $stmtExiste->execute([$parent, $nom]);
                    $stmtOrdre = db()->prepare('SELECT COALESCE(MAX(ordre),0)+1 FROM structure_categories WHERE parent_id = ?');
                    $stmtOrdre->execute([$parent]);
                }
                if (!$stmtExiste->fetchColumn()) {
                    $ordre = (int) $stmtOrdre->fetchColumn();
                    $estOrganisateur = $parent === null && isset($_POST['est_organisateur']) ? 1 : 0;
                    db()->prepare('INSERT INTO structure_categories (nom, parent_id, est_organisateur, ordre) VALUES (?, ?, ?, ?)')
                        ->execute([$nom, $parent, $estOrganisateur, $ordre]);
                }
            }
        } elseif ($section === 'edit') {
            // Formulaire unique (renommage inline ou repli complet) : soumis en
            // entier à chaque fois (voir lassoPlanArbre()), donc parent_id/
            // est_organisateur sont présents même si seul le nom a changé.
            $id = (int) ($_POST['id'] ?? 0);
            $nom = trim($_POST['nom'] ?? '');
            if ($nom !== '' && isset($map[$id])) {
                $estRacine = plan_pid($map[$id]['parent_id'] ?? null) === 0;
                if ($estRacine) {
                    $parent = null;
                    $estOrganisateur = isset($_POST['est_organisateur']) ? 1 : 0;
                } else {
                    $parentPost = ($_POST['parent_id'] ?? '') === '' ? null : (int) $_POST['parent_id'];
                    $parentValide = $parentPost !== null && isset($map[$parentPost]) && plan_pid($map[$parentPost]['parent_id'] ?? null) === 0;
                    $parent = $parentValide ? $parentPost : plan_pid($map[$id]['parent_id'] ?? null);
                    $estOrganisateur = 0;
                }
                $ancien = (string) $map[$id]['nom'];
                db()->beginTransaction();
                db()->prepare('UPDATE structure_categories SET nom=?, parent_id=?, est_organisateur=? WHERE id=?')
                    ->execute([$nom, $parent, $estOrganisateur, $id]);
                if ($ancien !== $nom) {
                    if ($estRacine) {
                        db()->prepare('UPDATE structures SET categorie=? WHERE categorie=?')->execute([$nom, $ancien]);
                    } else {
                        // Les noms de sous-catégorie ne sont uniques QUE dans leur
                        // catégorie parente (UNIQUE(parent_id, nom)) : deux parents
                        // peuvent avoir une « Lieu de création ». Le renommage doit
                        // donc être limité aux structures de la catégorie parente,
                        // sinon il renommerait aussi l'homonyme d'une autre catégorie.
                        $parentNom = (string) ($map[plan_pid($map[$id]['parent_id'] ?? null)]['nom'] ?? '');
                        db()->prepare('UPDATE structures SET sous_categorie=? WHERE sous_categorie=? AND categorie=?')
                            ->execute([$nom, $ancien, $parentNom]);
                    }
                }
                db()->commit();
            }
        } elseif ($section === 'move') {
            $id = (int) ($_POST['id'] ?? 0);
            $dir = ($_POST['dir'] ?? '') === 'up' ? 'up' : 'down';
            if (isset($map[$id])) {
                $pidParent = plan_pid($map[$id]['parent_id'] ?? null);
                $freres = plan_enfants($map)[$pidParent] ?? [];
                $ids = array_map(fn($r) => (int) $r['id'], $freres);
                $pos = array_search($id, $ids, true);
                $swap = $dir === 'up' ? $pos - 1 : $pos + 1;
                if ($pos !== false && $swap >= 0 && $swap < count($ids)) {
                    [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
                    $upd = db()->prepare('UPDATE structure_categories SET ordre = ? WHERE id = ?');
                    db()->beginTransaction();
                    foreach ($ids as $i => $cid) {
                        $upd->execute([$i, $cid]);
                    }
                    db()->commit();
                }
            }
        } elseif ($section === 'reorder') {
            $id = (int) ($_POST['id'] ?? 0);
            $parentPost = ($_POST['parent_id'] ?? '') === '' ? null : (int) $_POST['parent_id'];
            $order = array_values(array_filter(array_map('intval', explode(',', $_POST['order'] ?? ''))));
            if (isset($map[$id]) && $order) {
                $estRacine = plan_pid($map[$id]['parent_id'] ?? null) === 0;
                if ($estRacine) {
                    // Une catégorie racine ne peut jamais devenir une sous-catégorie :
                    // le glisser-déposer ne fait que la réordonner parmi les racines.
                    $parent = null;
                    $parentValide = true;
                } else {
                    // Une sous-catégorie reste forcément imbriquée dans une catégorie
                    // racine (jamais promue racine, jamais nichée sous une autre
                    // sous-catégorie).
                    $parentValide = $parentPost !== null && isset($map[$parentPost]) && plan_pid($map[$parentPost]['parent_id'] ?? null) === 0;
                    $parent = $parentPost;
                }
                if ($parentValide) {
                    db()->beginTransaction();
                    db()->prepare('UPDATE structure_categories SET parent_id = ? WHERE id = ?')->execute([$parent, $id]);
                    $upd = db()->prepare('UPDATE structure_categories SET ordre = ? WHERE id = ?');
                    $i = 0;
                    foreach ($order as $oid) {
                        if ($oid === $id || (isset($map[$oid]) && plan_pid($map[$oid]['parent_id'] ?? null) === plan_pid($parent))) {
                            $upd->execute([$i++, $oid]);
                        }
                    }
                    db()->commit();
                }
            }
        } elseif ($section === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            $cible = trim($_POST['reaffecter_vers'] ?? '');
            if (isset($map[$id])) {
                $estRacine = plan_pid($map[$id]['parent_id'] ?? null) === 0;
                $nom = (string) $map[$id]['nom'];
                if ($estRacine) {
                    $stmtEnfants = db()->prepare('SELECT COUNT(*) FROM structure_categories WHERE parent_id = ?');
                    $stmtEnfants->execute([$id]);
                    if ((int) $stmtEnfants->fetchColumn() > 0) {
                        redirect('parametres_structures', ['err' => 'cat_a_des_enfants']);
                        return;
                    }
                    $racines = (int) db()->query('SELECT COUNT(*) FROM structure_categories WHERE parent_id IS NULL')->fetchColumn();
                    if ($racines <= 1) {
                        redirect('parametres_structures', ['err' => 'cat_used']); // jamais la dernière racine
                        return;
                    }
                    $stmtRef = db()->prepare('SELECT COUNT(*) FROM structures WHERE categorie = ?');
                    $stmtRef->execute([$nom]);
                    if ((int) $stmtRef->fetchColumn() === 0) {
                        db()->prepare('DELETE FROM structure_categories WHERE id = ?')->execute([$id]);
                    } else {
                        // Réaffecter les structures vers une autre catégorie racine.
                        $ok = db()->prepare('SELECT 1 FROM structure_categories WHERE nom = ? AND parent_id IS NULL');
                        $ok->execute([$cible]);
                        if ($cible !== '' && strcasecmp($cible, $nom) !== 0 && $ok->fetchColumn()) {
                            db()->beginTransaction();
                            db()->prepare('UPDATE structures SET categorie = ? WHERE categorie = ?')->execute([$cible, $nom]);
                            db()->prepare('DELETE FROM structure_categories WHERE id = ?')->execute([$id]);
                            db()->commit();
                        } else {
                            redirect('parametres_structures', ['err' => 'cat_used']);
                            return;
                        }
                    }
                } else {
                    // Sous-catégorie : les noms ne sont uniques que dans leur
                    // catégorie parente — comptage et réaffectation sont donc
                    // limités aux structures de CETTE catégorie, sinon on
                    // toucherait les fiches d'une sous-catégorie homonyme.
                    $parentNom = (string) ($map[plan_pid($map[$id]['parent_id'] ?? null)]['nom'] ?? '');
                    $stmtRef = db()->prepare('SELECT COUNT(*) FROM structures WHERE sous_categorie = ? AND categorie = ?');
                    $stmtRef->execute([$nom, $parentNom]);
                    if ((int) $stmtRef->fetchColumn() === 0) {
                        db()->prepare('DELETE FROM structure_categories WHERE id = ?')->execute([$id]);
                    } else {
                        // Réaffecter la sous-catégorie : '' = aucune, sinon une autre
                        // sous-catégorie de la MÊME catégorie parente.
                        $cibleValide = $cible === '';
                        if (!$cibleValide) {
                            $ok = db()->prepare('SELECT 1 FROM structure_categories WHERE nom = ? AND parent_id = ?');
                            $ok->execute([$cible, plan_pid($map[$id]['parent_id'] ?? null)]);
                            $cibleValide = (bool) $ok->fetchColumn();
                        }
                        if ($cibleValide && strcasecmp($cible, $nom) !== 0) {
                            db()->beginTransaction();
                            db()->prepare('UPDATE structures SET sous_categorie = ? WHERE sous_categorie = ? AND categorie = ?')
                                ->execute([$cible, $nom, $parentNom]);
                            db()->prepare('DELETE FROM structure_categories WHERE id = ?')->execute([$id]);
                            db()->commit();
                        } else {
                            redirect('parametres_structures', ['err' => 'souscat_used']);
                            return;
                        }
                    }
                }
            }
        } elseif ($section === 'sync') {
            // Enregistre les sous-catégories déjà présentes sur des structures
            // (ex. importées) mais absentes de la taxonomie. Idempotent.
            $n = structures_taxonomie_synchroniser();
            redirect('parametres_structures', ['sync' => $n]);
            return;
        }
        redirect('parametres_structures', ['ok' => 1]);
    }

    $map = structure_categorie_map();
    // Nombre de structures utilisant chaque catégorie/sous-catégorie (pour la
    // réaffectation à la suppression) : deux agrégats, indexés par id de nœud.
    $usageCat = [];
    foreach (db()->query("SELECT categorie AS nom, COUNT(*) n FROM structures GROUP BY categorie") as $r) {
        $usageCat[(string) $r['nom']] = (int) $r['n'];
    }
    // Sous-catégories : indexées par « catégorie parente \0 nom », car un même
    // nom peut exister sous plusieurs catégories (UNIQUE(parent_id, nom)) —
    // compter par nom seul cumulerait les homonymes de catégories différentes.
    $usageSous = [];
    foreach (db()->query("SELECT categorie, sous_categorie AS nom, COUNT(*) n FROM structures WHERE sous_categorie <> '' GROUP BY categorie, sous_categorie") as $r) {
        $usageSous[(string) $r['categorie'] . "\0" . (string) $r['nom']] = (int) $r['n'];
    }
    $usage = [];
    foreach ($map as $cid => $r) {
        $estRacine = plan_pid($r['parent_id'] ?? null) === 0;
        if ($estRacine) {
            $usage[(int) $cid] = $usageCat[(string) $r['nom']] ?? 0;
        } else {
            $parentNom = (string) ($map[plan_pid($r['parent_id'] ?? null)]['nom'] ?? '');
            $usage[(int) $cid] = $usageSous[$parentNom . "\0" . (string) $r['nom']] ?? 0;
        }
    }
    render('parametres_structures', [
        'saved' => isset($_GET['ok']),
        'sync' => isset($_GET['sync']) ? (int) $_GET['sync'] : null,
        'err' => $_GET['err'] ?? null,
        'lignes' => structure_categories_liste_ordonnee($map),
        'map' => $map,
        'usage' => $usage,
    ], 'Paramètres — Catégories');
}

// Onglet « Aperçu » : état de la file d'attente + historique des campagnes.
function route_mailing(): void
{
    require_login();
    $enAttente = (int) db()->query("SELECT COUNT(*) FROM mailing_file_attente WHERE statut = 'attente'")->fetchColumn();
    $envoyes24h = (int) db()->query("SELECT COUNT(*) FROM mailing_envois WHERE succes = 1 AND envoye_le >= datetime('now', '-1 day')")->fetchColumn();
    // Historique : chaque campagne + ses stats (envoyés / échecs / en attente),
    // calculées depuis la file (attente) et les envois (résultats).
    $campagnes = db()->query(
        "SELECT c.id, c.sujet, c.nb_destinataires, c.cree_le,
                (SELECT COUNT(*) FROM mailing_envois e WHERE e.campagne_id = c.id AND e.succes = 1) AS envoyes,
                (SELECT COUNT(*) FROM mailing_envois e WHERE e.campagne_id = c.id AND e.succes = 0) AS echecs,
                (SELECT COUNT(*) FROM mailing_file_attente f WHERE f.campagne_id = c.id AND f.statut = 'attente') AS attente
         FROM mailing_campagnes c ORDER BY c.cree_le DESC, c.id DESC LIMIT 100"
    )->fetchAll();
    render('mailing', [
        'enAttente' => $enAttente,
        'envoyes24h' => $envoyes24h,
        'plafondJour' => (int) param('mailing_max_par_jour', '200'),
        'traiterUrl' => '?p=mailing_traiter&token=' . mailing_traiter_token(),
        'campagnes' => $campagnes,
        'ok' => $_GET['ok'] ?? null,
    ], 'Mailing — Aperçu');
}

// Onglet « Nouvelle campagne » : ciblage + message + prévisualisation + test.
function route_mailing_campagne(): void
{
    require_login();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $section = $_POST['section'] ?? '';
        if ($section === 'ciblage_save') {
            $nom = trim($_POST['ciblage_nom'] ?? '');
            $criteres = mailing_criteres_depuis($_POST);
            if ($nom !== '') {
                db()->prepare('INSERT OR REPLACE INTO mailing_ciblages (nom, criteres) VALUES (?, ?)')
                    ->execute([$nom, json_encode(mailing_criteres_vers_url($criteres), JSON_UNESCAPED_UNICODE)]);
            }
            redirect('mailing_campagne', mailing_criteres_vers_url($criteres) + ['previsualiser' => '1']);
        } elseif ($section === 'ciblage_delete') {
            db()->prepare('DELETE FROM mailing_ciblages WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
            redirect('mailing_campagne');
        } elseif ($section === 'test') {
            // Envoi test immédiat à une adresse, personnalisé avec le premier
            // destinataire réel du ciblage (ou générique) — n'alimente ni la file
            // ni l'historique.
            $adresse = trim($_POST['test_email'] ?? '') ?: (string) param('employeur_email_expediteur');
            $sujet = trim($_POST['sujet'] ?? '');
            $corps = trim($_POST['corps'] ?? '');
            $res = 'test_vide';
            if ($adresse !== '' && $sujet !== '' && $corps !== '') {
                $dest = mailing_destinataires(mailing_criteres_depuis($_POST));
                $ex = $dest[0] ?? ['structure' => ['id' => 0, 'nom' => 'Structure exemple'], 'contact' => ['prenom' => 'Prénom']];
                $sujetP = '[TEST] ' . mailing_personnaliser($sujet, $ex['structure'], $ex['contact']);
                $corpsP = mailing_personnaliser($corps, $ex['structure'], $ex['contact'])
                    . "\n\n---\n(Test — la désinscription réelle figure dans la campagne envoyée.)";
                [$ok] = envoyer_mailing_email($adresse, (string) param('employeur_email_expediteur'), $sujetP, $corpsP);
                $res = $ok ? 'test_ok' : 'test_ko';
            }
            redirect('mailing_campagne', mailing_criteres_vers_url(mailing_criteres_depuis($_POST)) + ['previsualiser' => '1', 'msg' => $res]);
        }
        redirect('mailing_campagne');
    }

    // Chargement d'un ciblage type : redirection vers l'URL équivalente + aperçu.
    if (!empty($_GET['ciblage'])) {
        $stmt = db()->prepare('SELECT criteres FROM mailing_ciblages WHERE id = ?');
        $stmt->execute([(int) $_GET['ciblage']]);
        $json = $stmt->fetchColumn();
        $stocke = $json ? json_decode((string) $json, true) : null;
        redirect('mailing_campagne', (is_array($stocke) ? $stocke : []) + ['previsualiser' => '1']);
    }

    $criteres = mailing_criteres_depuis($_GET);
    $apercu = ($_GET['previsualiser'] ?? '') === '1' ? mailing_destinataires($criteres) : null;

    // Aperçu présenté par STRUCTURE (colonnes identiques à ?p=structures) : on
    // regroupe les destinataires par structure, on enrichit les 20 premières avec
    // les salles/festivals liés, et on collecte les adresses réellement ciblées.
    $structuresApercu = [];
    $totalStructures = 0;
    if ($apercu) {
        $parId = [];
        foreach ($apercu as $d) {
            $sid = (int) $d['structure']['id'];
            if (!isset($parId[$sid])) {
                $parId[$sid] = $d['structure'];
                $parId[$sid]['emails'] = [];
                $parId[$sid]['prenoms'] = [];
                $parId[$sid]['lieux_noms'] = null;
            }
            $parId[$sid]['emails'][(string) $d['email']] = true;
            $prenom = trim((string) ($d['contact']['prenom'] ?? ''));
            if ($prenom !== '') {
                $parId[$sid]['prenoms'][$prenom] = true;
            }
        }
        $totalStructures = count($parId);
        $premiers = array_slice($parId, 0, 20, true);
        $ids = array_keys($premiers);
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmtL = db()->prepare(
                "SELECT sl.structure_id AS sid, GROUP_CONCAT(l.nom, ', ') AS noms
                 FROM structure_lieux sl JOIN lieux l ON l.id = sl.lieu_id
                 WHERE sl.structure_id IN ($in) GROUP BY sl.structure_id"
            );
            $stmtL->execute($ids);
            foreach ($stmtL->fetchAll() as $r) {
                $premiers[(int) $r['sid']]['lieux_noms'] = $r['noms'];
            }
        }
        $structuresApercu = array_values($premiers);
    }

    render('mailing_campagne', [
        'tags' => db()->query('SELECT * FROM structure_tags ORDER BY nom')->fetchAll(),
        'regions' => db()->query("SELECT DISTINCT region FROM structures WHERE region <> '' ORDER BY region")->fetchAll(PDO::FETCH_COLUMN),
        'grandesRegions' => pays_regions_map(), // régions groupées par pays (taxonomie, migration_49)
        'villes' => db()->query("SELECT DISTINCT adresse_localite FROM structures WHERE adresse_localite <> '' ORDER BY adresse_localite")->fetchAll(PDO::FETCH_COLUMN),
        'typesLieu' => lieu_categories_liste(),
        'categoriesPourSelect' => structure_categories_pour_select(),
        'ciblages' => db()->query('SELECT id, nom FROM mailing_ciblages ORDER BY nom')->fetchAll(),
        'modeles' => db()->query('SELECT id, nom, sujet, corps FROM mailing_modeles ORDER BY nom')->fetchAll(),
        'criteres' => $criteres,
        'apercu' => $apercu,
        'structuresApercu' => $structuresApercu,
        'totalStructures' => $totalStructures,
        'testEmailDefaut' => (string) param('employeur_email_expediteur'),
        'msg' => $_GET['msg'] ?? null,
    ], 'Mailing — Nouvelle campagne');
}

// Onglet « Modèles » : modèles de message réutilisables (sujet + corps).
function route_mailing_modeles(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $section = $_POST['section'] ?? '';
        if ($section === 'modele_save') {
            $nom = trim($_POST['nom'] ?? '');
            $sujet = trim($_POST['sujet'] ?? '');
            $corps = trim($_POST['corps'] ?? '');
            if ($nom !== '') {
                db()->prepare('INSERT OR REPLACE INTO mailing_modeles (nom, sujet, corps) VALUES (?, ?, ?)')
                    ->execute([$nom, $sujet, $corps]);
            }
        } elseif ($section === 'modele_delete') {
            db()->prepare('DELETE FROM mailing_modeles WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
        }
        redirect('mailing_modeles', ['ok' => 1]);
    }
    render('mailing_modeles', [
        'modeles' => db()->query('SELECT id, nom, sujet, corps FROM mailing_modeles ORDER BY nom')->fetchAll(),
        'saved' => isset($_GET['ok']),
    ], 'Mailing — Modèles');
}

// Critères de ciblage d'un mailing, depuis $_GET (prévisualisation) ou $_POST
// (création de la campagne) — une seule construction pour garantir que
// l'aperçu et l'envoi réel ciblent exactement les mêmes structures. La
// catégorie arrive en categorie_id (arbre catégorie/sous-catégorie, comme le
// filtre de ?p=structures) et est résolue en noms ici.
function mailing_criteres_depuis(array $src): array
{
    $categorieId = (int) ($src['categorie_id'] ?? 0);
    $champs = structure_categorie_champs($categorieId);
    return [
        'categorie_id'   => $categorieId,
        'categorie'      => $champs['categorie'],
        'sous_categorie' => $champs['sous_categorie'],
        'tag_id'         => (int) ($src['tag_id'] ?? 0),
        'pays'           => trim((string) ($src['pays'] ?? '')),
        'grande_region'  => trim((string) ($src['grande_region'] ?? '')),
        'region'         => trim((string) ($src['region'] ?? '')),
        'ville'          => trim((string) ($src['ville'] ?? '')),
        'type_lieu'      => trim((string) ($src['type_lieu'] ?? '')),
        'mois_debut'     => $src['mois_debut'] ?? '',
        'mois_fin'       => $src['mois_fin'] ?? '',
        'mois_evenement_debut' => $src['mois_evenement_debut'] ?? '',
        'mois_evenement_fin'   => $src['mois_evenement_fin'] ?? '',
        'contact_jamais' => !empty($src['contact_jamais']),
        'contact_avant'  => trim((string) ($src['contact_avant'] ?? '')),
    ];
}

// Paramètres d'URL correspondant à des critères (pour recharger un ciblage ou
// revenir sur ?p=mailing avec l'état conservé) — l'inverse de
// mailing_criteres_depuis(), en ne gardant que les clés « source ».
function mailing_criteres_vers_url(array $criteres): array
{
    $url = [];
    foreach (['categorie_id', 'tag_id', 'pays', 'grande_region', 'region', 'ville', 'type_lieu',
              'mois_debut', 'mois_fin', 'mois_evenement_debut', 'mois_evenement_fin', 'contact_avant'] as $k) {
        if (!empty($criteres[$k])) {
            $url[$k] = (string) $criteres[$k];
        }
    }
    if (!empty($criteres['contact_jamais'])) {
        $url['contact_jamais'] = '1';
    }
    return $url;
}

// Liste d'exclusion « ne pas contacter » : la table mailing_exclusions (les
// adresses exclues, gérables ici — retrait possible car elles n'ont pas
// forcément de fiche), plus, en consultation, les contacts désinscrits et les
// structures entièrement désinscrites (réinscription sur la fiche structure).
function route_mailing_exclusions(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        if (($_POST['section'] ?? '') === 'retirer') {
            db()->prepare('DELETE FROM mailing_exclusions WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
            // Volontaire : les contacts marqués désinscrits restent désinscrits —
            // retirer l'adresse de la liste n'a d'effet que sur les imports futurs
            // et les e-mails de repli ; la réinscription d'un contact se fait sur
            // sa fiche.
        }
        redirect('mailing_exclusions', ['ok' => 1]);
    }
    $emails = db()->query('SELECT id, email, cree_le FROM mailing_exclusions ORDER BY email')->fetchAll();
    $contacts = db()->query(
        "SELECT c.email, c.prenom, c.nom, s.id AS structure_id, s.nom AS structure_nom
         FROM structure_contacts c JOIN structures s ON s.id = c.structure_id
         WHERE c.desinscrit = 1 AND c.email <> '' ORDER BY c.email"
    )->fetchAll();
    $structures = db()->query(
        "SELECT id, nom, email FROM structures WHERE desinscrit = 1 ORDER BY nom"
    )->fetchAll();
    render('mailing_exclusions', [
        'emails' => $emails,
        'contacts' => $contacts,
        'structures' => $structures,
        'saved' => isset($_GET['ok']),
    ], 'Liste d\'exclusion');
}

function route_mailing_envoyer(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('mailing');
    }
    check_csrf();
    $criteres = mailing_criteres_depuis($_POST);
    $sujet = trim($_POST['sujet'] ?? '');
    $corps = trim($_POST['corps'] ?? '');
    if ($sujet === '' || $corps === '') {
        redirect('mailing_campagne', mailing_criteres_vers_url($criteres) + ['previsualiser' => '1', 'msg' => 'vide']);
    }
    $destinataires = mailing_destinataires($criteres);
    // Trace la campagne (historique) puis met chaque destinataire en file en
    // le rattachant à cette campagne (campagne_id) pour les statistiques.
    db()->prepare('INSERT INTO mailing_campagnes (sujet, corps, criteres, nb_destinataires) VALUES (?, ?, ?, ?)')
        ->execute([$sujet, $corps, json_encode(mailing_criteres_vers_url($criteres), JSON_UNESCAPED_UNICODE), count($destinataires)]);
    $campagneId = (int) db()->lastInsertId();
    $ins = db()->prepare('INSERT INTO mailing_file_attente (structure_id, contact_id, sujet, corps, campagne_id) VALUES (?, ?, ?, ?, ?)');
    foreach ($destinataires as $d) {
        $sujetP = mailing_personnaliser($sujet, $d['structure'], $d['contact']);
        $corpsP = mailing_personnaliser($corps, $d['structure'], $d['contact'])
            . "\n\n---\nPour ne plus recevoir ces e-mails : " . desinscription_url((int) $d['structure']['id'], $d['contact']['id'] ?? null);
        $ins->execute([(int) $d['structure']['id'], $d['contact']['id'] ?? null, $sujetP, $corpsP, $campagneId]);
    }
    redirect('mailing', ['ok' => count($destinataires)]);
}

// Traite un lot de la file d'attente en respectant le délai/plafond configurés.
// ⚠️ Dérogation délibérée à la convention « aucune mutation sur un GET » (voir
// CLAUDE.md) : cette route est déclenchée par le planificateur de tâches de
// l'hébergeur (URL fetchée périodiquement, sans possibilité de CSRF ni de
// session) — même carve-out que route_backup(), protégée par jeton
// (hash_equals()) plutôt que par check_csrf(). Voir SPEC_BOOKING.md §7/§13.
function route_mailing_traiter(): void
{
    $token = (string) param('mailing_traiter_token', '');
    if ($token === '' || !hash_equals($token, (string) ($_GET['token'] ?? ''))) {
        http_response_code(403);
        exit('Jeton invalide.');
    }

    $delai = max(0, (int) param('mailing_delai_secondes', '10'));
    $plafond = max(0, (int) param('mailing_max_par_jour', '200'));
    $expediteur = (string) param('employeur_email_expediteur');
    $budgetFin = time() + 50; // reste sous un max_execution_time typique d'hébergement mutualisé.
    $dev = APP_ENV === 'dev';

    $traites = 0;
    while (true) {
        $envoyes24h = (int) db()->query("SELECT COUNT(*) FROM mailing_envois WHERE succes = 1 AND envoye_le >= datetime('now', '-1 day')")->fetchColumn();
        if ($envoyes24h >= $plafond) {
            break;
        }
        $item = db()->query("SELECT * FROM mailing_file_attente WHERE statut = 'attente' ORDER BY id LIMIT 1")->fetch();
        if (!$item) {
            break;
        }
        $stmtC = db()->prepare('SELECT * FROM structure_contacts WHERE id = ?');
        $stmtC->execute([(int) ($item['contact_id'] ?? 0)]);
        $contact = $stmtC->fetch() ?: null;
        $stmtS = db()->prepare('SELECT email FROM structures WHERE id = ?');
        $stmtS->execute([(int) $item['structure_id']]);
        $destinataire = $contact['email'] ?? (string) $stmtS->fetchColumn();

        [$ok] = envoyer_mailing_email($destinataire, $expediteur, $item['sujet'], $item['corps']);

        db()->prepare("UPDATE mailing_file_attente SET statut = ? WHERE id = ?")
            ->execute([$ok ? 'envoye' : 'echec', $item['id']]);
        db()->prepare('INSERT INTO mailing_envois (structure_id, contact_id, sujet, destinataire_email, succes, campagne_id, envoye_le)
                        VALUES (?, ?, ?, ?, ?, ?, datetime(\'now\'))')
            ->execute([(int) $item['structure_id'], $item['contact_id'], $item['sujet'], $destinataire, $ok ? 1 : 0, $item['campagne_id'] ?? null]);
        if ($ok) {
            structure_recalculer_dernier_contact((int) $item['structure_id']);
        }
        $traites++;

        if (time() >= $budgetFin) {
            break;
        }
        if (!$dev && $delai > 0) {
            sleep($delai);
        }
    }

    header('Content-Type: text/plain; charset=utf-8');
    echo "OK, $traites e-mail(s) traité(s).\n";
    exit;
}

// Désinscription par lien e-mail (GET, sans session) : voir commentaire de
// route_mailing_traiter() ci-dessus pour la dérogation à la convention POST.
function route_desinscription(): void
{
    $structureId = (int) ($_GET['structure_id'] ?? 0);
    $contactId = ($_GET['contact_id'] ?? '') !== '' ? (int) $_GET['contact_id'] : null;
    $sig = (string) ($_GET['sig'] ?? '');
    if (!$structureId || !hash_equals(desinscription_signature($structureId, $contactId), $sig)) {
        http_response_code(403);
        exit('Lien invalide.');
    }
    if ($contactId) {
        db()->prepare('UPDATE structure_contacts SET desinscrit = 1 WHERE id = ? AND structure_id = ?')->execute([$contactId, $structureId]);
    } else {
        db()->prepare('UPDATE structures SET desinscrit = 1 WHERE id = ?')->execute([$structureId]);
    }
    echo '<!doctype html><meta charset="utf-8"><p style="font-family:sans-serif;padding:2rem">'
        . 'Vous ne recevrez plus de mailing de notre part.</p>';
    exit;
}

// ------------------------------------------------------------- IMPORT CSV
// Déroulé en 3 étapes (upload → correspondance des colonnes → résolution des
// conflits un par un), voir SPEC_BOOKING.md §8. Le contenu du fichier est
// mémorisé en session entre les étapes (lire_fichier_importe(), même
// mécanisme que les imports existants) — pas besoin de re-téléverser à chaque
// étape ; seul le mapping choisi doit être reposté (formulaire intermédiaire).
function route_import_structures(): void
{
    require_login();
    $etape = $_POST['etape'] ?? '';
    // Toujours le même jeu complet de variables, quel que soit l'état affiché
    // (render() ne fournit aucun défaut pour une clé absente, cf. extract()).
    $vars = [
        'etape' => 'upload', 'err' => null, 'entete' => [], 'conflits' => [],
        'nNouvelles' => 0, 'nFusion' => 0, 'resume' => [], 'nExclusion' => 0,
        'groupes' => [], 'groupesConfirmes' => [], 'mappingSuggere' => [],
    ];

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $etape === 'exclusion') {
        check_csrf();
        $emails = preg_split('/[\r\n,;]+/', (string) ($_POST['emails'] ?? ''));
        $vars['etape'] = 'exclusion_ok';
        $vars['nExclusion'] = structures_importer_liste_exclusion(array_filter(array_map('trim', $emails)));
        render('import_structures', $vars, 'Importer');
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $etape === 'mapper') {
        check_csrf();
        $r = lire_fichier_importe(2 * 1024 * 1024, 'Fichier trop volumineux (2 Mo maximum).', 'import_structures_csv',
            'Veuillez choisir un fichier CSV à importer.', 'import_structures_nom');
        if ($r['err'] !== null) {
            $vars['err'] = $r['err'];
            render('import_structures', $vars, 'Importer');
            return;
        }
        $_SESSION['import_structures_csv'] = $r['contenu'];
        $_SESSION['import_structures_nom'] = $r['nom'];
        [$entete, ] = structures_lire_csv((string) $r['contenu']);
        if (!$entete) {
            $vars['err'] = 'Fichier vide ou illisible.';
            render('import_structures', $vars, 'Importer');
            return;
        }
        $vars['etape'] = 'mapping';
        $vars['entete'] = $entete;
        // Pré-remplissage depuis les noms de colonnes mémorisés au dernier import.
        $vars['mappingSuggere'] = structure_import_mapping_suggere($entete);
        render('import_structures', $vars, 'Importer');
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($etape, ['analyser', 'grouper', 'appliquer'], true)) {
        check_csrf();
        $csv = (string) ($_SESSION['import_structures_csv'] ?? '');
        [$entete, $lignes] = structures_lire_csv($csv);
        $mapping = [];
        foreach (array_keys(STRUCTURE_IMPORT_CHAMPS) as $champ) {
            $v = $_POST['mapping'][$champ] ?? ($_SESSION['import_structures_mapping'][$champ] ?? '');
            $mapping[$champ] = $v !== '' ? (int) $v : null;
        }
        $_SESSION['import_structures_mapping'] = $mapping;
        // Mémorise la correspondance choisie (par noms de colonnes) pour le
        // prochain import, dès que l'utilisateur valide l'écran de mapping.
        if ($etape === 'analyser') {
            structure_import_memoriser_mapping($mapping, $entete);
        }
        $analyse = structures_analyser_import($lignes, $mapping);
        $groupes = structures_grouper($analyse);

        // Étape 1 (analyser → grouper) : si des regroupements sont détectés,
        // on propose une étape de revue ; sinon on passe directement aux conflits.
        if ($etape === 'analyser' && $groupes) {
            $parIndex = [];
            foreach ($analyse as $l) {
                $parIndex[$l['index']] = $l;
            }
            foreach ($groupes as $key => &$g) {
                $existe = false;
                if ($g['self_index'] !== null && isset($parIndex[$g['self_index']])) {
                    $existe = $parIndex[$g['self_index']]['correspondance_id'] !== null;
                } else {
                    $existe = (bool) structure_trouver_correspondance($g['organisateur'], '');
                }
                $g['cle'] = $key;
                $g['existe'] = $existe;
            }
            unset($g);
            $vars['etape'] = 'grouper';
            $vars['groupes'] = $groupes;
            render('import_structures', $vars, 'Importer');
            return;
        }

        // Regroupements confirmés (étapes grouper/appliquer). On ne garde que
        // les clés réellement détectées, et on note les lignes « lieu » consommées
        // par un regroupement pour les retirer de la liste des conflits.
        $confirmes = ($etape === 'analyser')
            ? []
            : array_values(array_filter((array) ($_POST['groupes'] ?? []), fn ($k) => isset($groupes[$k])));
        $exclus = [];
        foreach ($confirmes as $k) {
            foreach ($groupes[$k]['lieux'] as $lieu) {
                $exclus[$lieu['index']] = true;
            }
        }

        if ($etape !== 'appliquer') {
            // analyser (sans groupe) ou grouper → écran de résolution. On ne
            // présente QUE les fiches existantes ayant un vrai conflit de champ
            // (deux valeurs remplies et différentes) ; le reste (nouvelles +
            // fusions sans conflit) s'applique tout seul.
            $conflits = structures_import_conflits($analyse, $exclus);
            $restants = array_filter($analyse, fn ($l) => !isset($exclus[$l['index']]));
            $nCorrespondances = count(array_filter($restants, fn ($l) => $l['correspondance_id'] !== null));
            $vars['etape'] = 'resoudre';
            $vars['conflits'] = $conflits;
            $vars['nNouvelles'] = count($restants) - $nCorrespondances;
            $vars['nFusion'] = $nCorrespondances - count($conflits); // fusionnées sans conflit
            $vars['groupesConfirmes'] = $confirmes;
            render('import_structures', $vars, 'Importer');
            return;
        }

        // etape === 'appliquer'
        // Fusion champ par champ : le formulaire n'envoie que les cases cochées
        // (« prendre l'import » pour un champ en conflit) → borne PHP
        // max_input_vars. Défaut (case absente) = garder la valeur actuelle.
        $choix = [];
        foreach ((array) ($_POST['prendre'] ?? []) as $index => $champs) {
            foreach ((array) $champs as $col => $v) {
                $choix[(int) $index][(string) $col] = true;
            }
        }
        // Filet de sécurité : snapshot de la base AVANT d'écrire, pour pouvoir
        // revenir en arrière si l'import se passe mal (data/…_avant_import_*.bak).
        sauvegarder_base('avant_import_structures');
        $vars['etape'] = 'resume';
        $vars['resume'] = structures_appliquer_import($analyse, $choix, $confirmes);
        unset($_SESSION['import_structures_csv'], $_SESSION['import_structures_nom'], $_SESSION['import_structures_mapping']);
        render('import_structures', $vars, 'Importer');
        return;
    }

    render('import_structures', $vars, 'Importer');
}
