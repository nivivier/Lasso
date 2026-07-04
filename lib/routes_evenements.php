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
    $retourFiltres = [
        'annee' => $annee, 'statut_suisa' => $statutSuisa, 'spectacle_id' => $spectacleId,
        'statut' => $statut, 'visibilite' => $visibilite,
    ];

    // Modification groupée (sélection de lignes + barre flottante, même esprit que
    // le lettrage/l'axe analytique en masse sur les écritures comptables).
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $section = $_POST['section'] ?? '';
            if ($section === 'spectacle') {
                $spId = ($_POST['bulk_spectacle_id'] ?? '') !== '' ? (int) $_POST['bulk_spectacle_id'] : null;
                if ($spId === null || spectacle_existe($spId)) {
                    db()->prepare("UPDATE evenements SET spectacle_id = ? WHERE id IN ($in)")
                        ->execute(array_merge([$spId], $ids));
                }
            } elseif ($section === 'visibilite' && in_array($_POST['bulk_visibilite'] ?? '', EVENEMENTS_VISIBILITES, true)) {
                db()->prepare("UPDATE evenements SET visibilite = ? WHERE id IN ($in)")
                    ->execute(array_merge([$_POST['bulk_visibilite']], $ids));
            } elseif ($section === 'statut' && in_array($_POST['bulk_statut'] ?? '', EVENEMENTS_STATUTS, true)) {
                db()->prepare("UPDATE evenements SET statut = ? WHERE id IN ($in)")
                    ->execute(array_merge([$_POST['bulk_statut']], $ids));
            }
        }
        redirect('evenements_liste', $retourFiltres);
    }

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
            'paysDisponibles' => evenements_pays_disponibles(),
            'err'            => $err,
            'post'           => $_POST,
        ], $id ? "Modifier l'événement" : 'Nouvel événement');
    };

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $renderForm(null);
        return;
    }

    check_csrf();
    // Carte « Informations » uniquement — SUISA (route_evenement_suisa) et les
    // liens employés/fiches/factures (routes dédiées) se sauvegardent séparément.
    $date = trim($_POST['date'] ?? '');
    $statut = in_array($_POST['statut'] ?? '', EVENEMENTS_STATUTS, true) ? $_POST['statut'] : 'option';
    $visibilite = in_array($_POST['visibilite'] ?? '', EVENEMENTS_VISIBILITES, true) ? $_POST['visibilite'] : 'non_repertorie';
    $spectacleId = ($_POST['spectacle_id'] ?? '') !== '' ? (int) $_POST['spectacle_id'] : null;
    $ville = trim($_POST['ville'] ?? '');
    $region = trim($_POST['region'] ?? '');
    $pays = in_array($_POST['pays'] ?? '', evenements_pays_disponibles(), true) ? $_POST['pays'] : '';
    $salle = trim($_POST['salle'] ?? '');
    $festival = trim($_POST['festival'] ?? '');
    $lienInfos = trim($_POST['lien_infos'] ?? '');
    $lienTexte = trim($_POST['lien_texte'] ?? '');
    $remarques = trim($_POST['remarques'] ?? '');

    $err = null;
    if (!date_valide($date)) {
        $err = 'La date est invalide.';
    } elseif ($spectacleId !== null && !spectacle_existe($spectacleId)) {
        $err = 'Spectacle invalide.';
    } elseif ($lienInfos !== '' && !preg_match('#^https?://#i', $lienInfos)) {
        $err = "Le lien doit être une URL valide (commençant par http:// ou https://).";
    } elseif ($lienInfos !== '' && !filter_var($lienInfos, FILTER_VALIDATE_URL)) {
        $err = "Le lien n'est pas une URL valide.";
    }
    if ($err) {
        $renderForm($err);
        return;
    }

    $champs = [
        'spectacle_id' => $spectacleId, 'date' => $date, 'statut' => $statut, 'visibilite' => $visibilite,
        'ville' => $ville, 'region' => $region, 'pays' => $pays, 'salle' => $salle, 'festival' => $festival,
        'lien_infos' => $lienInfos, 'lien_texte' => $lienTexte, 'remarques' => $remarques,
    ];

    if ($id) {
        $champs['id'] = $id;
        db()->prepare('UPDATE evenements SET spectacle_id=:spectacle_id, date=:date, statut=:statut,
                        visibilite=:visibilite, ville=:ville, region=:region, pays=:pays, salle=:salle, festival=:festival,
                        lien_infos=:lien_infos, lien_texte=:lien_texte, remarques=:remarques WHERE id=:id')->execute($champs);
        $evenementId = $id;
    } else {
        // suisa_applicable/suisa_envoye_*/suisa_decompte_le gardent leurs valeurs
        // par défaut du schéma (applicable=1, dates vides) — modifiables ensuite
        // depuis la carte « Suivi SUISA », visible une fois l'événement créé.
        db()->prepare('INSERT INTO evenements (spectacle_id, date, statut, visibilite, ville, region, pays, salle, festival,
                        lien_infos, lien_texte, remarques)
                        VALUES (:spectacle_id, :date, :statut, :visibilite, :ville, :region, :pays, :salle, :festival, :lien_infos,
                        :lien_texte, :remarques)')
            ->execute($champs);
        $evenementId = (int) db()->lastInsertId();
    }

    redirect('evenement', ['id' => $evenementId, 'ok' => 'infos']);
}

// Carte « Suivi SUISA » — sauvegarde indépendante de la carte « Informations ».
function route_evenement_suisa(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('evenements_liste');
    }
    check_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    if (!evenement_charger($id)) {
        redirect('evenements_liste');
    }
    $suisaApplicable = isset($_POST['suisa_applicable']) ? 1 : 0;
    $suisaEnvoyeA = in_array($_POST['suisa_envoye_a'] ?? '', EVENEMENTS_SUISA_ENVOYE_A, true) ? $_POST['suisa_envoye_a'] : '';
    $suisaEnvoyeLe = trim($_POST['suisa_envoye_le'] ?? '');
    $suisaDecompteLe = trim($_POST['suisa_decompte_le'] ?? '');
    db()->prepare('UPDATE evenements SET suisa_applicable=?, suisa_envoye_a=?, suisa_envoye_le=?, suisa_decompte_le=? WHERE id=?')
        ->execute([$suisaApplicable, $suisaEnvoyeA, $suisaEnvoyeLe, $suisaDecompteLe, $id]);
    redirect('evenement', ['id' => $id, 'ok' => 'suisa']);
}

// Carte « Employés » — lien/délien immédiat (pas de bouton Enregistrer), même
// esprit que route_evenement_facture_lier()/delier().
function route_evenement_employe_lier(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('evenements_liste');
    }
    check_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $employeId = (int) ($_POST['employe_id'] ?? 0);
    if ($employeId && evenement_charger($id)) {
        $stmt = db()->prepare('SELECT 1 FROM employes WHERE id = ?');
        $stmt->execute([$employeId]);
        if ($stmt->fetchColumn()) {
            db()->prepare('INSERT OR IGNORE INTO evenement_employes (evenement_id, employe_id) VALUES (?, ?)')
                ->execute([$id, $employeId]);
        }
    }
    redirect('evenement', ['id' => $id]);
}

function route_evenement_employe_delier(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('evenements_liste');
    }
    check_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $employeId = (int) ($_POST['employe_id'] ?? 0);
    db()->prepare('DELETE FROM evenement_employes WHERE evenement_id = ? AND employe_id = ?')->execute([$id, $employeId]);
    redirect('evenement', ['id' => $id]);
}

function route_evenement_fiche_lier(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('evenements_liste');
    }
    check_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $ficheId = (int) ($_POST['fiche_id'] ?? 0);
    if ($ficheId && evenement_charger($id)) {
        $stmt = db()->prepare('SELECT 1 FROM fiches WHERE id = ?');
        $stmt->execute([$ficheId]);
        if ($stmt->fetchColumn()) {
            db()->prepare('INSERT OR IGNORE INTO evenement_fiches (evenement_id, fiche_id) VALUES (?, ?)')
                ->execute([$id, $ficheId]);
        }
    }
    redirect('evenement', ['id' => $id]);
}

function route_evenement_fiche_delier(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('evenements_liste');
    }
    check_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $ficheId = (int) ($_POST['fiche_id'] ?? 0);
    db()->prepare('DELETE FROM evenement_fiches WHERE evenement_id = ? AND fiche_id = ?')->execute([$id, $ficheId]);
    redirect('evenement', ['id' => $id]);
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
    render('spectacles', ['spectacles' => $spectacles, 'token' => evenements_export_token()], 'Spectacles');
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
            $spectacleErr = array_merge((array) $spectacle, ['id' => $id, 'nom' => $nom, 'notes' => $notes]);
            render('spectacle_form', ['spectacle' => $spectacleErr, 'err' => $err], 'Spectacle');
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
            $lienTexteDefaut = trim($_POST['evenements_lien_texte_defaut'] ?? '');
            $paysListe = array_values(array_filter(array_map(
                fn ($p) => mb_strtoupper(trim($p), 'UTF-8'),
                explode(',', (string) ($_POST['evenements_pays_disponibles'] ?? ''))
            ), fn ($p) => $p !== ''));
            $ins = db()->prepare('INSERT OR REPLACE INTO parametres (cle, valeur) VALUES (?, ?)');
            $ins->execute(['suisa_delai_decompte_mois', (string) $delai]);
            $ins->execute(['evenements_lien_texte_defaut', $lienTexteDefaut]);
            $ins->execute(['evenements_pays_disponibles', implode(',', $paysListe)]);
        }
        redirect('parametres_evenements', ['ok' => 1]);
    }

    render('parametres_evenements', [
        'delai' => evenements_delai_decompte_mois(),
        'lienTexteDefaut' => evenements_lien_texte_defaut(),
        'paysDisponibles' => evenements_pays_disponibles(),
        'saved' => $_GET['ok'] ?? null,
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

// --- Import CSV (agenda de tournée) ------------------------------------------
function route_import_evenements(): void
{
    require_login();
    $err = null; $resultats = null; $resume = null; $simule = true;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $simule = !isset($_POST['appliquer']);
        $csv = null;
        $up = $_FILES['fichier'] ?? null;
        if ($up && ($up['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            if (($up['size'] ?? 0) > 2 * 1024 * 1024) {
                $err = 'Fichier trop volumineux (2 Mo maximum).';
            } else {
                $csv = (string) file_get_contents($up['tmp_name']);
            }
        } elseif (!empty($_POST['depuis_session']) && !empty($_SESSION['import_evenements_csv'])) {
            $csv = (string) $_SESSION['import_evenements_csv'];
        } else {
            $err = 'Veuillez choisir un fichier CSV à importer.';
        }
        if ($err === null) {
            try {
                [$resultats, $resume] = importer_evenements_csv((string) $csv, $simule);
                if ($simule) {
                    $_SESSION['import_evenements_csv'] = $csv;
                } else {
                    unset($_SESSION['import_evenements_csv']);
                }
            } catch (Throwable $e) {
                $err = "Erreur pendant l'import : " . $e->getMessage();
            }
        }
    }
    render('import_fiches', [
        'errFiches' => null, 'resultatsFiches' => null, 'resumeFiches' => null, 'simuleFiches' => true,
        'errFactures' => null, 'resultatsFactures' => null, 'resumeFactures' => null, 'simuleFactures' => true,
        'msgEcritures' => null,
        'errEvenements' => $err, 'resultatsEvenements' => $resultats, 'resumeEvenements' => $resume, 'simuleEvenements' => $simule,
    ], 'Importer');
}
