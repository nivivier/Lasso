<?php
// Handlers de routes du module événements (préfixes « evenement »/« spectacle »).
// Inclus depuis index.php après lib/routes.php. S'appuie sur lib/evenements.php.
// Deux routes (evenements_json / evenements_ical) sont publiques, protégées par
// jeton — pas de require_login() sur celles-ci (voir SPEC_EVENEMENTS.md §8).

require_once __DIR__ . '/evenements.php';

// ----------------------------------------------------------- Helpers internes
function evenement_charger(int $id): ?array
{
    $stmt = db()->prepare('SELECT e.*, s.nom AS spectacle_nom FROM evenements e
                            LEFT JOIN spectacles s ON s.id = e.spectacle_id WHERE e.id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function evenement_employe_ids(int $evenementId): array
{
    $stmt = db()->prepare('SELECT employe_id FROM evenement_employes WHERE evenement_id = ?');
    $stmt->execute([$evenementId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function evenement_fiche_ids(int $evenementId): array
{
    $stmt = db()->prepare('SELECT fiche_id FROM evenement_fiches WHERE evenement_id = ?');
    $stmt->execute([$evenementId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function evenement_factures_liees(int $evenementId): array
{
    $stmt = db()->prepare('SELECT f.*, d.nom AS debiteur_nom FROM factures f
                            JOIN debiteurs d ON d.id = f.debiteur_id
                            WHERE f.evenement_id = ? ORDER BY f.cree_le DESC');
    $stmt->execute([$evenementId]);
    return $stmt->fetchAll();
}

// Factures pas encore liées à un événement (candidates pour le lien depuis la
// fiche événement) — une facture n'est jamais liée qu'à un seul événement à la fois.
function factures_sans_evenement(): array
{
    return db()->query(
        "SELECT f.*, d.nom AS debiteur_nom FROM factures f
         JOIN debiteurs d ON d.id = f.debiteur_id
         WHERE f.evenement_id IS NULL ORDER BY f.cree_le DESC"
    )->fetchAll();
}

// Liste des événements pour un <select> (ex. lier une facture existante à un
// événement depuis la fiche facture).
function evenements_pour_selection(): array
{
    return db()->query(
        "SELECT e.id, e.date, e.ville, s.nom AS spectacle_nom FROM evenements e
         LEFT JOIN spectacles s ON s.id = e.spectacle_id ORDER BY e.date DESC"
    )->fetchAll();
}

// ------------------------------------------------------------------- ROUTES
function route_evenements(): void
{
    require_login();
    redirect('evenements_liste');
}

function route_evenements_liste(): void
{
    require_login();
    $annees = array_map('intval', db()->query(
        "SELECT DISTINCT strftime('%Y', date) FROM evenements ORDER BY 1 DESC"
    )->fetchAll(PDO::FETCH_COLUMN));
    $annee        = (int) filtre_persistant('annee', 'evenements_annee', $annees[0] ?? date('Y'));
    $statutSuisa  = filtre_persistant('statut_suisa', 'evenements_statut_suisa', 'tous');
    $spectacleId  = (int) filtre_persistant('spectacle_id', 'evenements_spectacle_id', 0);
    $statut       = filtre_persistant('statut', 'evenements_statut', 'tous');
    $visibilite   = filtre_persistant('visibilite', 'evenements_visibilite', 'tous');

    $sql = "SELECT e.*, s.nom AS spectacle_nom FROM evenements e
            LEFT JOIN spectacles s ON s.id = e.spectacle_id WHERE 1=1";
    $params = [];
    if ($annee) {
        $sql .= " AND strftime('%Y', e.date) = ?";
        $params[] = (string) $annee;
    }
    if ($spectacleId) {
        $sql .= ' AND e.spectacle_id = ?';
        $params[] = $spectacleId;
    }
    if (in_array($statut, EVENEMENTS_STATUTS, true)) {
        $sql .= ' AND e.statut = ?';
        $params[] = $statut;
    }
    if (in_array($visibilite, EVENEMENTS_VISIBILITES, true)) {
        $sql .= ' AND e.visibilite = ?';
        $params[] = $visibilite;
    }
    if (in_array($statutSuisa, EVENEMENTS_STATUTS_SUISA_FILTRE, true)) {
        $sql .= ' AND (' . evenement_sql_statut_suisa($statutSuisa, 'e.') . ')';
        if (in_array($statutSuisa, ['envoye', 'manquant'], true)) {
            $params[] = evenements_delai_decompte_mois();
        }
    }
    $sql .= ' ORDER BY e.date DESC, e.id DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $evenements = $stmt->fetchAll();

    $spectacles = spectacles_pour_selection();

    render('evenements_liste', [
        'evenements'   => $evenements,
        'annee'        => $annee,
        'annees'       => $annees ?: [(int) date('Y')],
        'statutSuisa'  => $statutSuisa,
        'spectacleId'  => $spectacleId,
        'statut'       => $statut,
        'visibilite'   => $visibilite,
        'spectacles'   => $spectacles,
    ], 'Événements');
}

function route_evenement(): void
{
    require_login();
    $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
    $evenement = $id ? evenement_charger($id) : null;
    if ($id && !$evenement) {
        redirect('evenements_liste');
    }

    $spectacles = spectacles_pour_selection();
    $employesTous = db()->query('SELECT id, prenom, nom FROM employes ORDER BY nom, prenom')->fetchAll();
    $fichesTous = db()->query(
        "SELECT f.id, f.annee, f.mois, e.prenom, e.nom FROM fiches f
         JOIN employes e ON e.id = f.employe_id ORDER BY f.annee DESC, f.mois DESC, e.nom"
    )->fetchAll();

    // Sépare « déjà liés » / « disponibles » pour le picker (select + bouton
    // Ajouter, cf. views/evenement_form.php) — évite d'afficher une case à
    // cocher par employé/fiche, peu lisible dès que la liste grossit.
    $employeIds = $id ? evenement_employe_ids($id) : [];
    $employesLies  = array_values(array_filter($employesTous, fn ($e) => in_array((int) $e['id'], $employeIds, true)));
    $employesDispo = array_values(array_filter($employesTous, fn ($e) => !in_array((int) $e['id'], $employeIds, true)));
    $ficheIds = $id ? evenement_fiche_ids($id) : [];
    $fichesLiees  = array_values(array_filter($fichesTous, fn ($f) => in_array((int) $f['id'], $ficheIds, true)));
    $fichesDispo  = array_values(array_filter($fichesTous, fn ($f) => !in_array((int) $f['id'], $ficheIds, true)));

    $renderForm = function (?string $err) use (
        $evenement, $id, $spectacles, $employesLies, $employesDispo, $fichesLiees, $fichesDispo
    ) {
        render('evenement_form', [
            'evenement'      => $evenement,
            'id'             => $id,
            'spectacles'     => $spectacles,
            'employesLies'   => $employesLies,
            'employesDispo'  => $employesDispo,
            'fichesLiees'    => $fichesLiees,
            'fichesDispo'    => $fichesDispo,
            'factures'       => $id ? evenement_factures_liees($id) : [],
            'facturesDispo'  => ($id && module_actif('facturation')) ? factures_sans_evenement() : [],
            'err'            => $err,
            'post'           => $_POST,
        ], $id ? "Modifier l'événement" : 'Nouvel événement');
    };

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $renderForm(null);
        return;
    }

    check_csrf();
    $date = trim($_POST['date'] ?? '');
    $statut = in_array($_POST['statut'] ?? '', EVENEMENTS_STATUTS, true) ? $_POST['statut'] : 'option';
    $visibilite = in_array($_POST['visibilite'] ?? '', EVENEMENTS_VISIBILITES, true) ? $_POST['visibilite'] : 'non_repertorie';
    $spectacleId = ($_POST['spectacle_id'] ?? '') !== '' ? (int) $_POST['spectacle_id'] : null;
    $ville = trim($_POST['ville'] ?? '');
    $salle = trim($_POST['salle'] ?? '');
    $festival = trim($_POST['festival'] ?? '');
    $lienInfos = trim($_POST['lien_infos'] ?? '');
    $remarques = trim($_POST['remarques'] ?? '');
    $suisaApplicable = isset($_POST['suisa_applicable']) ? 1 : 0;
    $suisaEnvoyeA = in_array($_POST['suisa_envoye_a'] ?? '', EVENEMENTS_SUISA_ENVOYE_A, true) ? $_POST['suisa_envoye_a'] : '';
    $suisaEnvoyeLe = trim($_POST['suisa_envoye_le'] ?? '');
    $suisaDecompteLe = trim($_POST['suisa_decompte_le'] ?? '');

    $err = null;
    if (!date_valide($date)) {
        $err = 'La date est invalide.';
    } elseif ($spectacleId !== null && !spectacle_existe($spectacleId)) {
        $err = 'Spectacle invalide.';
    } elseif ($lienInfos !== '' && !preg_match('#^https?://#i', $lienInfos)) {
        $err = "Le lien « plus d'infos » doit être une URL valide (commençant par http:// ou https://).";
    } elseif ($lienInfos !== '' && !filter_var($lienInfos, FILTER_VALIDATE_URL)) {
        $err = "Le lien « plus d'infos » n'est pas une URL valide.";
    }
    if ($err) {
        $renderForm($err);
        return;
    }

    // Ids postés filtrés contre les employés/fiches réellement proposés dans
    // le formulaire (jamais une valeur tierce bidouillée) — évite une
    // violation de clé étrangère silencieuse sur un id inconnu.
    $employeIdsValides = array_map(fn ($e) => (int) $e['id'], $employesTous);
    $employeIdsPost = array_values(array_intersect(array_map('intval', $_POST['employe_ids'] ?? []), $employeIdsValides));
    $ficheIdsValides = array_map(fn ($f) => (int) $f['id'], $fichesTous);
    $ficheIdsPost = array_values(array_intersect(array_map('intval', $_POST['fiche_ids'] ?? []), $ficheIdsValides));

    $champs = [
        'spectacle_id' => $spectacleId, 'date' => $date, 'statut' => $statut, 'visibilite' => $visibilite,
        'ville' => $ville, 'salle' => $salle, 'festival' => $festival, 'lien_infos' => $lienInfos,
        'remarques' => $remarques, 'suisa_applicable' => $suisaApplicable, 'suisa_envoye_a' => $suisaEnvoyeA,
        'suisa_envoye_le' => $suisaEnvoyeLe, 'suisa_decompte_le' => $suisaDecompteLe,
    ];

    db()->beginTransaction();
    if ($id) {
        $champs['id'] = $id;
        db()->prepare('UPDATE evenements SET spectacle_id=:spectacle_id, date=:date, statut=:statut,
                        visibilite=:visibilite, ville=:ville, salle=:salle, festival=:festival,
                        lien_infos=:lien_infos, remarques=:remarques, suisa_applicable=:suisa_applicable,
                        suisa_envoye_a=:suisa_envoye_a, suisa_envoye_le=:suisa_envoye_le,
                        suisa_decompte_le=:suisa_decompte_le WHERE id=:id')->execute($champs);
        $evenementId = $id;
    } else {
        db()->prepare('INSERT INTO evenements (spectacle_id, date, statut, visibilite, ville, salle, festival,
                        lien_infos, remarques, suisa_applicable, suisa_envoye_a, suisa_envoye_le, suisa_decompte_le)
                        VALUES (:spectacle_id, :date, :statut, :visibilite, :ville, :salle, :festival, :lien_infos,
                        :remarques, :suisa_applicable, :suisa_envoye_a, :suisa_envoye_le, :suisa_decompte_le)')
            ->execute($champs);
        $evenementId = (int) db()->lastInsertId();
    }

    // Employés : sélectionnables dès la création (le picker est affiché sur le
    // formulaire de création, cf. views/evenement_form.php).
    evenement_sync_liens($evenementId, 'evenement_employes', 'employe_id', $employeIdsPost);

    // Fiches : seulement modifiables une fois l'événement créé (formulaire
    // d'édition) — absentes du POST à la création initiale, donc inoffensif ici.
    if ($id) {
        evenement_sync_liens($id, 'evenement_fiches', 'fiche_id', $ficheIdsPost);
    }
    db()->commit();

    redirect('evenement', ['id' => $evenementId, 'ok' => 1]);
}

function route_evenement_delete(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $id = (int) ($_POST['id'] ?? 0);
        db()->prepare('DELETE FROM evenements WHERE id = ?')->execute([$id]);
    }
    redirect('evenements_liste');
}

// Lie une facture existante (pas encore liée) à cet événement, depuis la fiche
// événement. Ne vole jamais le lien d'un autre événement (WHERE evenement_id
// IS NULL) — pour changer de facture liée, il faut d'abord la délier.
function route_evenement_facture_lier(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !module_actif('facturation')) {
        redirect('evenements_liste');
    }
    check_csrf();
    $evenementId = (int) ($_POST['id'] ?? 0);
    $factureId   = (int) ($_POST['facture_id'] ?? 0);
    if ($factureId && evenement_charger($evenementId)) {
        db()->prepare('UPDATE factures SET evenement_id = ? WHERE id = ? AND evenement_id IS NULL')
            ->execute([$evenementId, $factureId]);
    }
    redirect('evenement', ['id' => $evenementId]);
}

// Détache une facture de cet événement (la facture elle-même n'est jamais
// supprimée ni modifiée à part son lien).
function route_evenement_facture_delier(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('evenements_liste');
    }
    check_csrf();
    $evenementId = (int) ($_POST['id'] ?? 0);
    $factureId   = (int) ($_POST['facture_id'] ?? 0);
    db()->prepare('UPDATE factures SET evenement_id = NULL WHERE id = ? AND evenement_id = ?')
        ->execute([$factureId, $evenementId]);
    redirect('evenement', ['id' => $evenementId]);
}

// Lie (ou délie, si vide) une facture à un événement, depuis la fiche facture
// (sens inverse de route_evenement_facture_lier — ici la facture porte l'action).
function route_facture_evenement_lier(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('facturation_liste');
    }
    check_csrf();
    $factureId    = (int) ($_POST['facture_id'] ?? 0);
    $evenementRaw = trim((string) ($_POST['evenement_id'] ?? ''));
    $evenementId  = $evenementRaw !== '' ? (int) $evenementRaw : null;
    if ($evenementId !== null && !evenement_charger($evenementId)) {
        redirect('facture', ['id' => $factureId]);
    }
    db()->prepare('UPDATE factures SET evenement_id = ? WHERE id = ?')->execute([$evenementId, $factureId]);
    redirect('facture', ['id' => $factureId]);
}

// --- Spectacles ---------------------------------------------------------------
function route_spectacles(): void
{
    require_login();
    $spectacles = db()->query(
        'SELECT s.*, (SELECT COUNT(*) FROM evenements e WHERE e.spectacle_id = s.id) AS nb_evenements
         FROM spectacles s ORDER BY s.nom'
    )->fetchAll();
    render('spectacles', ['spectacles' => $spectacles], 'Spectacles');
}

function route_spectacle(): void
{
    require_login();
    $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
    $spectacle = null;
    if ($id) {
        $stmt = db()->prepare('SELECT * FROM spectacles WHERE id = ?');
        $stmt->execute([$id]);
        $spectacle = $stmt->fetch();
        if (!$spectacle) {
            redirect('spectacles');
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $nom = trim($_POST['nom'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $err = null;
        if ($nom === '') {
            $err = 'Le nom est obligatoire.';
        }
        $fichier = $spectacle['suisa_feuille_fichier'] ?? '';
        if (!$err) {
            try {
                $upload = handle_pdf_upload('suisa_feuille');
                if ($upload !== null) {
                    $fichier = $upload;
                } elseif (!empty($_POST['suisa_feuille_supprimer'])) {
                    $fichier = '';
                }
            } catch (RuntimeException $e) {
                $err = $e->getMessage();
            }
        }
        if ($err) {
            render('spectacle_form', ['spectacle' => array_merge((array) $spectacle, ['id' => $id, 'nom' => $nom, 'notes' => $notes]), 'err' => $err], 'Spectacle');
            return;
        }
        if ($id) {
            db()->prepare('UPDATE spectacles SET nom=?, notes=?, suisa_feuille_fichier=? WHERE id=?')
                ->execute([$nom, $notes, $fichier, $id]);
        } else {
            db()->prepare('INSERT INTO spectacles (nom, notes, suisa_feuille_fichier) VALUES (?, ?, ?)')
                ->execute([$nom, $notes, $fichier]);
        }
        redirect('spectacles');
    }
    render('spectacle_form', ['spectacle' => $spectacle, 'err' => null], $id ? 'Modifier le spectacle' : 'Nouveau spectacle');
}

function route_spectacle_delete(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $id = (int) ($_POST['id'] ?? 0);
        if (!supprimer_si_non_reference('spectacles', $id, 'evenements', 'spectacle_id')) {
            redirect('spectacles', ['err' => 'used']);
        }
    }
    redirect('spectacles');
}

// --- Paramètres — onglet Événements -------------------------------------------
function route_parametres_evenements(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        if (isset($_POST['regenerer_token'])) {
            evenements_regenerer_token();
        } else {
            $delai = max(1, (int) ($_POST['suisa_delai_decompte_mois'] ?? 12));
            db()->prepare('INSERT OR REPLACE INTO parametres (cle, valeur) VALUES (?, ?)')
                ->execute(['suisa_delai_decompte_mois', (string) $delai]);
        }
        redirect('parametres_evenements', ['ok' => 1]);
    }

    $token = evenements_export_token();
    $spectacles = spectacles_pour_selection();
    render('parametres_evenements', [
        'delai'      => evenements_delai_decompte_mois(),
        'token'      => $token,
        'urlJson'    => evenements_export_url('evenements_json', $token),
        'urlIcal'    => evenements_export_url('evenements_ical', $token),
        'spectacles' => $spectacles,
        'saved'      => $_GET['ok'] ?? null,
    ], 'Paramètres — Événements');
}

// --- Export public (JSON / iCal) — sans session, protégé par jeton -----------
function evenements_verifier_token(): void
{
    $token = (string) param('evenements_export_token', '');
    $fourni = (string) ($_GET['token'] ?? '');
    if ($token === '' || !hash_equals($token, $fourni)) {
        http_response_code(403);
        exit('Jeton invalide.');
    }
}

function route_evenements_json(): void
{
    evenements_verifier_token();
    $spectacleId = isset($_GET['spectacle_id']) ? (int) $_GET['spectacle_id'] : null;
    $items = evenements_a_exporter($spectacleId);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function route_evenements_ical(): void
{
    evenements_verifier_token();
    $spectacleId = isset($_GET['spectacle_id']) ? (int) $_GET['spectacle_id'] : null;
    $items = evenements_a_exporter($spectacleId);
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: inline; filename="evenements.ics"');
    echo evenements_generer_ical($items);
    exit;
}
