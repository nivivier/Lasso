<?php
// Handlers de routes du module facturation (préfixes « facturation_ »/« facture_ »
// « debiteur »). Inclus depuis index.php après lib/routes.php. S'appuie sur
// lib/facturation.php.

require_once __DIR__ . '/facturation.php';

// ----------------------------------------------------------- Helpers internes
// Lit les lignes de facture postées (description, quantité, prix unitaire, axe).
function facturation_lire_lignes_postees(): array
{
    $descriptions = $_POST['l_description'] ?? [];
    $quantites    = $_POST['l_quantite'] ?? [];
    $prix         = $_POST['l_prix'] ?? [];
    $axes         = $_POST['l_axe'] ?? [];
    $lignes = [];
    foreach ($descriptions as $i => $desc) {
        $desc = trim((string) $desc);
        $qte  = (float) str_replace(',', '.', $quantites[$i] ?? '0');
        $pu   = (float) str_replace(',', '.', $prix[$i] ?? '0');
        if ($desc === '' || $qte <= 0) {
            continue;
        }
        $axeId = ($axes[$i] ?? '') !== '' ? (int) $axes[$i] : null;
        $lignes[] = [
            'description'       => $desc,
            'quantite'          => $qte,
            'prix_unitaire'     => $pu,
            'montant'           => facturation_calc_ligne($qte, $pu),
            'axe_analytique_id' => $axeId,
        ];
    }
    return $lignes;
}

function facturation_charger(int $id): ?array
{
    $stmt = db()->prepare('SELECT f.*, d.nom AS debiteur_nom, d.adresse_rue, d.adresse_npa, d.adresse_localite,
                                   d.adresse_pays, d.email AS debiteur_email, c.libelle AS compte_libelle, c.iban
                            FROM factures f
                            JOIN debiteurs d ON d.id = f.debiteur_id
                            LEFT JOIN comptes_bancaires c ON c.id = f.compte_bancaire_id
                            WHERE f.id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function facturation_lignes_de(int $factureId): array
{
    $stmt = db()->prepare('SELECT fl.*, a.code AS axe_code, a.libelle AS axe_libelle
                            FROM facture_lignes fl LEFT JOIN axes_analytiques a ON a.id = fl.axe_analytique_id
                            WHERE fl.facture_id = ? ORDER BY fl.ordre, fl.id');
    $stmt->execute([$factureId]);
    return $stmt->fetchAll();
}

// ------------------------------------------------------------------- ROUTES
function route_facturation(): void
{
    require_login();
    redirect('facturation_liste');
}

function route_facturation_liste(): void
{
    require_login();
    $statut = filtre_persistant('statut', 'facturation_statut', 'tous');
    $annees = array_map('intval', db()->query(
        "SELECT DISTINCT strftime('%Y', COALESCE(NULLIF(date_emission,''), cree_le)) FROM factures ORDER BY 1 DESC"
    )->fetchAll(PDO::FETCH_COLUMN));
    $annee = (int) filtre_persistant('annee', 'facturation_annee', $annees[0] ?? date('Y'));

    $avecEvenements = module_actif('evenements');
    $sql = 'SELECT f.*, d.nom AS debiteur_nom' . ($avecEvenements ? ', ev.date AS evenement_date, sp.nom AS spectacle_nom' : '')
         . ' FROM factures f JOIN debiteurs d ON d.id = f.debiteur_id';
    if ($avecEvenements) {
        $sql .= ' LEFT JOIN evenements ev ON ev.id = f.evenement_id LEFT JOIN spectacles sp ON sp.id = ev.spectacle_id';
    }
    $sql .= ' WHERE 1=1';
    $params = [];
    if ($annee) {
        $sql .= " AND strftime('%Y', COALESCE(NULLIF(f.date_emission,''), f.cree_le)) = ?";
        $params[] = (string) $annee;
    }
    if ($statut === 'en_retard') {
        $sql .= ' AND ' . facturation_sql_en_retard('f.');
        $params[] = date('Y-m-d');
    } elseif (in_array($statut, FACTURATION_STATUTS, true)) {
        $sql .= ' AND f.statut = ?';
        $params[] = $statut;
    }
    $sql .= ' ORDER BY COALESCE(NULLIF(f.date_emission,\'\'), f.cree_le) DESC, f.id DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $factures = $stmt->fetchAll();

    render('facturation_liste', [
        'factures'       => $factures,
        'statut'         => $statut,
        'annee'          => $annee,
        'annees'         => $annees ?: [(int) date('Y')],
        'avecEvenements' => $avecEvenements,
    ], 'Facturation');
}

// Formulaire (brouillon) — création ou modification, tant que non émise.
function route_facturation_form(): void
{
    require_login();
    $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
    $facture = $id ? facturation_charger($id) : null;
    if ($id && !$facture) {
        redirect('facturation_liste');
    }
    if ($facture && $facture['statut'] !== 'brouillon') {
        redirect('facture', ['id' => $id]);
    }

    $debiteurs = db()->query("SELECT * FROM debiteurs WHERE actif = 1 ORDER BY nom")->fetchAll();
    $comptes   = compta_comptes();
    $axes      = module_actif('analytique')
        ? db()->query('SELECT * FROM axes_analytiques WHERE actif = 1 ORDER BY ordre, id')->fetchAll()
        : [];
    $delaiDefaut = (int) param('facturation_delai_jours_defaut', '30');
    // Facture créée depuis un événement (bouton « Créer une facture liée »,
    // module événements) : evenement_id porté par l'URL à la création, ou déjà
    // figé sur la facture en modification.
    $evenementId = ($_GET['evenement_id'] ?? $_POST['evenement_id'] ?? '') !== ''
        ? (int) ($_GET['evenement_id'] ?? $_POST['evenement_id'])
        : (isset($facture['evenement_id']) ? (int) $facture['evenement_id'] ?: null : null);

    $renderForm = function (?string $err) use ($facture, $id, $debiteurs, $comptes, $axes, $delaiDefaut, $evenementId) {
        render('facturation_form', [
            'facture' => $facture, 'id' => $id, 'debiteurs' => $debiteurs, 'comptes' => $comptes, 'axes' => $axes,
            'delaiDefaut' => $delaiDefaut, 'evenementId' => $evenementId, 'err' => $err, 'post' => $_POST,
        ], $id ? 'Modifier la facture' : 'Nouvelle facture');
    };

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $renderForm(null);
        return;
    }

    check_csrf();
    $debiteurRaw     = (string) ($_POST['debiteur_id'] ?? '');
    $nouveauDebiteur = $debiteurRaw === '__new__';
    $compteId   = ($_POST['compte_bancaire_id'] ?? '') !== '' ? (int) $_POST['compte_bancaire_id'] : null;
    $delaiJours = max(1, (int) ($_POST['delai_jours'] ?? $delaiDefaut));
    $communication = trim($_POST['communication'] ?? '');
    $lignes = facturation_lire_lignes_postees();

    $err = null;
    $debiteurId = null;
    $ndNom = trim($_POST['nd_nom'] ?? '');
    if ($nouveauDebiteur) {
        if ($ndNom === '') {
            $err = 'Le nom du nouveau débiteur est obligatoire.';
        }
    } else {
        $debiteurId = (int) $debiteurRaw;
        $stmtD = db()->prepare('SELECT 1 FROM debiteurs WHERE id = ?');
        $stmtD->execute([$debiteurId]);
        if (!$stmtD->fetchColumn()) {
            $err = 'Choisissez un débiteur.';
        }
    }
    if (!$err && !$lignes) {
        $err = 'Ajoutez au moins une ligne avec une description, une quantité et un prix unitaire.';
    }
    if ($err) {
        $renderForm($err);
        return;
    }

    if ($nouveauDebiteur) {
        db()->prepare('INSERT INTO debiteurs (type, nom, adresse_rue, adresse_npa, adresse_localite, adresse_pays, email, actif)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 1)')
            ->execute([
                ($_POST['nd_type'] ?? '') === 'particulier' ? 'particulier' : 'organisation',
                $ndNom,
                trim($_POST['nd_adresse_rue'] ?? ''),
                trim($_POST['nd_adresse_npa'] ?? ''),
                trim($_POST['nd_adresse_localite'] ?? ''),
                trim($_POST['nd_adresse_pays'] ?? '') ?: 'Suisse',
                trim($_POST['nd_email'] ?? ''),
            ]);
        $debiteurId = (int) db()->lastInsertId();
    }

    $factureId = facturation_sauvegarder_brouillon($id ?: null, $debiteurId, $compteId, $delaiJours, $communication, $lignes, $evenementId);
    redirect('facture', ['id' => $factureId]);
}

function route_facture(): void
{
    require_login();
    $id = (int) ($_GET['id'] ?? 0);
    $facture = facturation_charger($id);
    if (!$facture) {
        redirect('facturation_liste');
    }
    // Écritures créditrices pas encore liées à une facture (+ celle déjà liée
    // à cette facture, le cas échéant) : proposées pour un rapprochement
    // manuel (l'automatique n'a pas matché — nom du débiteur absent du texte
    // bancaire, montant fractionné, etc.), modifiable tant que la facture
    // n'est pas annulée.
    $ecrituresLibres = [];
    if (in_array($facture['statut'], ['emise', 'payee'], true) && module_actif('compta')) {
        $stmt = db()->prepare(
            "SELECT id, date_op, texte, montant FROM ecritures
             WHERE (facture_id IS NULL OR facture_id = ?) AND montant > 0 ORDER BY date_op DESC"
        );
        $stmt->execute([$id]);
        $ecrituresLibres = $stmt->fetchAll();
    }
    // Liste des événements pour le picker « Événement lié » (lib/routes_evenements.php,
    // toujours chargé — voir index.php — mais n'a de sens que si le module est actif).
    $evenementsListe = module_actif('evenements') ? evenements_pour_selection() : [];
    render('facturation_voir', [
        'facture' => $facture,
        'lignes'  => facturation_lignes_de($id),
        'statutEffectif' => facturation_statut_effectif($facture),
        'ecrituresLibres' => $ecrituresLibres,
        'evenementsListe' => $evenementsListe,
        'saved'   => $_GET['ok'] ?? null,
    ], 'Facture ' . ($facture['numero'] ?: '(brouillon)'));
}

// Marquage manuel « payée » : pour les cas où le rapprochement automatique
// (au moment de l'import bancaire) n'a pas trouvé de correspondance, alors
// que l'utilisateur voit l'écriture correspondante dans Écritures. $ecriture_id
// optionnel : lie l'écriture choisie (si encore libre, ou déjà liée à cette
// facture) en plus de marquer payée. Rejouable tant que la facture est déjà
// « payée » : permet de corriger la date ou l'écriture liée après coup.
function route_facture_payee(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('facturation_liste');
    }
    check_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $facture = facturation_charger($id);
    if (!$facture || !in_array($facture['statut'], ['emise', 'payee'], true)) {
        redirect('facturation_liste');
    }
    $payeeLe = trim($_POST['payee_le'] ?? '') ?: date('Y-m-d');

    $ecritureId = null;
    $ecritureRaw = (int) ($_POST['ecriture_id'] ?? 0);
    if ($ecritureRaw) {
        $stmt = db()->prepare('SELECT 1 FROM ecritures WHERE id = ? AND (facture_id IS NULL OR facture_id = ?)');
        $stmt->execute([$ecritureRaw, $id]);
        if ($stmt->fetchColumn()) {
            $ecritureId = $ecritureRaw;
        }
    }

    db()->beginTransaction();
    $ancienEcritureId = (int) ($facture['ecriture_id'] ?? 0);
    if ($ancienEcritureId && $ancienEcritureId !== $ecritureId) {
        db()->prepare('UPDATE ecritures SET facture_id = NULL WHERE id = ? AND facture_id = ?')->execute([$ancienEcritureId, $id]);
    }
    db()->prepare("UPDATE factures SET statut='payee', payee_le=?, ecriture_id=? WHERE id=?")
        ->execute([$payeeLe, $ecritureId, $id]);
    if ($ecritureId) {
        db()->prepare('UPDATE ecritures SET facture_id = ? WHERE id = ?')->execute([$id, $ecritureId]);
    }
    db()->commit();
    redirect('facture', ['id' => $id, 'ok' => 'payee']);
}

// Émission : fige numéro, référence de paiement, dates et statut.
function route_facture_emettre(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('facturation_liste');
    }
    check_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $facture = facturation_charger($id);
    if (!$facture || $facture['statut'] !== 'brouillon') {
        redirect('facturation_liste');
    }
    if (!$facture['compte_bancaire_id']) {
        redirect('facture', ['id' => $id, 'err' => 'compte']);
    }
    $lignes = facturation_lignes_de($id);
    if (!$lignes) {
        redirect('facture', ['id' => $id, 'err' => 'lignes']);
    }

    try {
        $annee = (int) date('Y');
        $numero = facturation_prochain_numero(db(), $annee);
        $reference = facturation_generer_reference($numero);
        $dateEmission = date('Y-m-d');
        $dateEcheance = facturation_date_echeance($dateEmission, (int) $facture['delai_jours']);

        db()->prepare("UPDATE factures SET numero=?, reference_paiement=?, date_emission=?, date_echeance=?, statut='emise' WHERE id=?")
            ->execute([$numero, $reference, $dateEmission, $dateEcheance, $id]);
    } catch (Throwable $e) {
        // La facture reste 'brouillon' (rien n'a été modifié avant l'échec) —
        // on peut réessayer l'émission sans risque de doublon.
        redirect('facture', ['id' => $id, 'err' => 'emission']);
    }
    redirect('facture', ['id' => $id, 'ok' => 'emise']);
}

function route_facture_annuler(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('facturation_liste');
    }
    check_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $facture = facturation_charger($id);
    if ($facture && $facture['statut'] !== 'payee' && $facture['statut'] !== 'annulee') {
        db()->prepare("UPDATE factures SET statut='annulee' WHERE id=?")->execute([$id]);
    }
    redirect('facture', ['id' => $id]);
}

function route_facture_delete(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('facturation_liste');
    }
    check_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    db()->prepare("DELETE FROM factures WHERE id = ? AND statut = 'brouillon'")->execute([$id]);
    redirect('facturation_liste');
}

// Construit le PDF d'une facture émise (ou payée/annulée), pour téléchargement/e-mail.
function facturation_pdf_de(array $facture): string
{
    $lignes = facturation_lignes_de((int) $facture['id']);
    $stmt = db()->prepare('SELECT * FROM comptes_bancaires WHERE id = ?');
    $stmt->execute([(int) $facture['compte_bancaire_id']]);
    $compte = $stmt->fetch();
    if (!$compte) {
        throw new RuntimeException('Compte bancaire créancier introuvable.');
    }
    $debiteur = [
        'nom' => $facture['debiteur_nom'], 'adresse_rue' => $facture['adresse_rue'],
        'adresse_npa' => $facture['adresse_npa'], 'adresse_localite' => $facture['adresse_localite'],
        'adresse_pays' => $facture['adresse_pays'],
    ];
    return facturation_generer_pdf($facture, $lignes, $debiteur, $compte);
}

function route_facture_pdf(): void
{
    require_login();
    $id = (int) ($_GET['id'] ?? 0);
    $facture = facturation_charger($id);
    if (!$facture || $facture['statut'] === 'brouillon') {
        redirect('facturation_liste');
    }
    try {
        $pdf = facturation_pdf_de($facture);
    } catch (Throwable $e) {
        redirect('facture', ['id' => $id, 'err' => 'pdf']);
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="facture-' . $facture['numero'] . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

function route_facture_email(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('facturation_liste');
    }
    check_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $facture = facturation_charger($id);
    if (!$facture || $facture['statut'] === 'brouillon') {
        redirect('facturation_liste');
    }
    $destinataire = trim((string) ($_POST['destinataire'] ?? $facture['debiteur_email']));
    if (!filter_var($destinataire, FILTER_VALIDATE_EMAIL)) {
        redirect('facture', ['id' => $id, 'mail' => 'err']);
    }
    $expediteur = (string) param('employeur_email_expediteur');
    try {
        $pdf = facturation_pdf_de($facture);
    } catch (Throwable $e) {
        redirect('facture', ['id' => $id, 'err' => 'pdf']);
    }
    [$ok, ] = envoyer_facture_email($facture, $pdf, $destinataire, $expediteur);
    if ($ok) {
        db()->prepare('UPDATE factures SET envoyee_le = ? WHERE id = ?')->execute([date('c'), $id]);
    }
    redirect('facture', ['id' => $id, 'mail' => $ok ? 'ok' : 'err']);
}

// Lettre de rappel (impression) pour une facture émise en retard de paiement.
function route_facture_rappel(): void
{
    require_login();
    $id = (int) ($_GET['id'] ?? 0);
    $facture = facturation_charger($id);
    if (!$facture || $facture['statut'] === 'brouillon') {
        redirect('facturation_liste');
    }
    render_bare('facturation_rappel_print', ['facture' => $facture]);
}

// --- Débiteurs ---------------------------------------------------------------
function route_facturation_debiteurs(): void
{
    require_login();
    $debiteurs = db()->query('SELECT d.*, (SELECT COUNT(*) FROM factures f WHERE f.debiteur_id = d.id) AS nb_factures
                               FROM debiteurs d ORDER BY d.actif DESC, d.nom')->fetchAll();
    render('facturation_debiteurs', ['debiteurs' => $debiteurs], 'Facturation — Débiteurs');
}

function route_debiteur(): void
{
    require_login();
    $id = (int) ($_GET['id'] ?? 0);
    $debiteur = null;
    if ($id) {
        $stmt = db()->prepare('SELECT * FROM debiteurs WHERE id = ?');
        $stmt->execute([$id]);
        $debiteur = $stmt->fetch();
        if (!$debiteur) {
            redirect('facturation_debiteurs');
        }
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $champs = [
            'type'             => ($_POST['type'] ?? '') === 'particulier' ? 'particulier' : 'organisation',
            'nom'              => trim($_POST['nom'] ?? ''),
            'adresse_rue'      => trim($_POST['adresse_rue'] ?? ''),
            'adresse_npa'      => trim($_POST['adresse_npa'] ?? ''),
            'adresse_localite' => trim($_POST['adresse_localite'] ?? ''),
            'adresse_pays'     => trim($_POST['adresse_pays'] ?? '') ?: 'Suisse',
            'email'            => trim($_POST['email'] ?? ''),
            'notes'            => trim($_POST['notes'] ?? ''),
            'actif'            => isset($_POST['actif']) ? 1 : 0,
        ];
        $err = null;
        if ($champs['nom'] === '') {
            $err = 'Le nom est obligatoire.';
        } elseif ($champs['email'] !== '' && !filter_var($champs['email'], FILTER_VALIDATE_EMAIL)) {
            $err = 'Adresse e-mail invalide.';
        }
        if ($err) {
            render('facturation_debiteur_form', ['debiteur' => array_merge((array) $debiteur, $champs, ['id' => $id]), 'err' => $err], 'Débiteur');
            return;
        }
        if ($id) {
            $champs['id'] = $id;
            db()->prepare('UPDATE debiteurs SET type=:type, nom=:nom, adresse_rue=:adresse_rue, adresse_npa=:adresse_npa,
                            adresse_localite=:adresse_localite, adresse_pays=:adresse_pays, email=:email, notes=:notes, actif=:actif
                            WHERE id=:id')->execute($champs);
        } else {
            db()->prepare('INSERT INTO debiteurs (type, nom, adresse_rue, adresse_npa, adresse_localite, adresse_pays, email, notes, actif)
                            VALUES (:type, :nom, :adresse_rue, :adresse_npa, :adresse_localite, :adresse_pays, :email, :notes, :actif)')
                ->execute($champs);
        }
        redirect('facturation_debiteurs');
    }
    render('facturation_debiteur_form', ['debiteur' => $debiteur, 'err' => null], $id ? 'Modifier le débiteur' : 'Nouveau débiteur');
}

function route_debiteur_delete(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $id = (int) ($_POST['id'] ?? 0);
        if (!supprimer_si_non_reference('debiteurs', $id, 'factures', 'debiteur_id')) {
            redirect('facturation_debiteurs', ['err' => 'used']);
        }
    }
    redirect('facturation_debiteurs');
}

// --- Import de factures historiques (JSON) ----------------------------------
function route_import_factures(): void
{
    require_login();
    $err = null; $resultats = null; $resume = null; $simule = true;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $simule = !isset($_POST['appliquer']);
        $json = null;
        $up = $_FILES['fichier'] ?? null;
        if ($up && ($up['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            if (($up['size'] ?? 0) > 2 * 1024 * 1024) {
                $err = 'Fichier trop volumineux (2 Mo maximum).';
            } else {
                $json = (string) file_get_contents($up['tmp_name']);
            }
        } elseif (!empty($_POST['depuis_session']) && !empty($_SESSION['import_factures_json'])) {
            $json = (string) $_SESSION['import_factures_json'];
        } else {
            $err = 'Veuillez choisir un fichier JSON à importer.';
        }
        if ($err === null) {
            $doc = json_decode((string) $json, true);
            if (!is_array($doc) || ($doc['type'] ?? '') !== 'factures_historique' || !is_array($doc['factures'] ?? null)) {
                $err = 'Fichier non reconnu : un export de factures historiques (JSON) est attendu.';
                unset($_SESSION['import_factures_json']);
            } else {
                try {
                    [$resultats, $resume] = importer_factures_historique($doc['factures'], $simule);
                    if ($simule) {
                        $_SESSION['import_factures_json'] = $json;
                    } else {
                        unset($_SESSION['import_factures_json']);
                    }
                } catch (Throwable $e) {
                    $err = "Erreur pendant l'import : " . $e->getMessage();
                }
            }
        }
    }
    render('import_fiches', [
        'errFiches' => null, 'resultatsFiches' => null, 'resumeFiches' => null, 'simuleFiches' => true,
        'errFactures' => $err, 'resultatsFactures' => $resultats, 'resumeFactures' => $resume, 'simuleFactures' => $simule,
        'msgEcritures' => null,
        'errEvenements' => null, 'resultatsEvenements' => null, 'resumeEvenements' => null, 'simuleEvenements' => true,
    ], 'Importer');
}
