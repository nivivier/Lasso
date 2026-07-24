<?php
// Handlers de routes du module facturation (préfixes « facturation_ »/« facture_ »)
// + des structures (ex-débiteurs, partagées avec le module booking, voir
// SPEC_BOOKING.md §3). Inclus depuis index.php après lib/routes.php. S'appuie
// sur lib/facturation.php + lib/booking.php (catégories configurables, fiche
// structure partagée).

require_once __DIR__ . '/facturation.php';
require_once __DIR__ . '/booking.php';

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
    $stmt = db()->prepare("SELECT f.*, d.nom AS structure_nom, d.adresse_rue, d.adresse_npa, d.adresse_localite,
                                   d.adresse_pays,
                                   COALESCE(
                                       (SELECT email FROM structure_contacts WHERE structure_id = d.id AND est_administration = 1 LIMIT 1),
                                       NULLIF(d.email, '')
                                   ) AS structure_email,
                                   c.libelle AS compte_libelle, c.iban
                            FROM factures f
                            JOIN structures d ON d.id = f.structure_id
                            LEFT JOIN comptes_bancaires c ON c.id = f.compte_bancaire_id
                            WHERE f.id = ?");
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
    $annee = (int) filtre_persistant('annee', 'facturation_annee', 0); // 0 = « Toutes les années » par défaut

    $avecEvenements = module_actif('evenements');
    $from = ' FROM factures f JOIN structures d ON d.id = f.structure_id';
    if ($avecEvenements) {
        $from .= ' LEFT JOIN evenements ev ON ev.id = f.evenement_id LEFT JOIN spectacles sp ON sp.id = ev.spectacle_id';
    }
    $where = ' WHERE 1=1';
    $params = [];
    if ($annee) {
        $where .= " AND strftime('%Y', COALESCE(NULLIF(f.date_emission,''), f.cree_le)) = ?";
        $params[] = (string) $annee;
    }
    if ($statut === 'en_retard') {
        $where .= ' AND ' . facturation_sql_en_retard('f.');
        $params[] = date('Y-m-d');
    } elseif (in_array($statut, FACTURATION_STATUTS, true)) {
        $where .= ' AND f.statut = ?';
        $params[] = $statut;
    }
    $recherche = trim((string) ($_GET['q'] ?? ''));

    // Total avec les seuls filtres structurés (hors recherche texte) : décide du
    // mode client vs serveur, voir pagination_mode_client() dans lib/helpers.php.
    $stmtTotStruct = db()->prepare('SELECT COUNT(*)' . $from . $where);
    $stmtTotStruct->execute($params);
    $totalSansRecherche = (int) $stmtTotStruct->fetchColumn();
    $modeClient = pagination_mode_client($totalSansRecherche);

    $pgTaille = pagination_taille('facturation_taille');
    $selectCols = 'f.*, d.nom AS structure_nom' . ($avecEvenements ? ', ev.date AS evenement_date, sp.nom AS spectacle_nom' : '');
    $orderBy = ' ORDER BY COALESCE(NULLIF(f.date_emission,\'\'), f.cree_le) DESC, f.id DESC';

    if ($modeClient) {
        $stmt = db()->prepare('SELECT ' . $selectCols . $from . $where . $orderBy);
        $stmt->execute($params);
        $factures = $stmt->fetchAll();
        $pgPage  = 1;
        $pgTotal = $totalSansRecherche;
    } else {
        [$rechSql, $rechParams] = recherche_sql(['f.numero', 'd.nom', 'CAST(f.montant_total AS TEXT)']);
        $where .= $rechSql;
        $params = array_merge($params, $rechParams);

        $stmtTot = db()->prepare('SELECT COUNT(*)' . $from . $where);
        $stmtTot->execute($params);
        $pgTotal = (int) $stmtTot->fetchColumn();

        $pgPage = pagination_page();
        [$limitSql, $limitParams] = pagination_sql($pgPage, $pgTaille);

        $sql = 'SELECT ' . $selectCols . $from . $where . $orderBy . $limitSql;
        $stmt = db()->prepare($sql);
        $stmt->execute(array_merge($params, $limitParams));
        $factures = $stmt->fetchAll();
    }

    render('facturation_liste', [
        'factures'       => $factures,
        'statut'         => $statut,
        'annee'          => $annee,
        'annees'         => $annees ?: [(int) date('Y')],
        'avecEvenements' => $avecEvenements,
        'recherche'      => $recherche,
        'modeClient'     => $modeClient,
        'pgRoute'        => 'facturation_liste',
        'pgParams'       => ['statut' => $statut, 'annee' => $annee, 'q' => $recherche],
        'pgPage'         => $pgPage,
        'pgTaille'       => $pgTaille,
        'pgTotal'        => $pgTotal,
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

    $structures = db()->query("SELECT * FROM structures WHERE actif = 1 ORDER BY nom")->fetchAll();
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
    // Lien resté ouvert vers un événement supprimé depuis (ex. onglet oublié) :
    // on ignore le lien plutôt que de laisser échouer l'enregistrement sur la
    // contrainte de clé étrangère factures.evenement_id.
    if ($evenementId && !evenement_charger($evenementId)) {
        $evenementId = null;
    }
    // Axe par défaut de l'événement lié (carte « Comptabilité analytique »),
    // présélectionné sur la première ligne d'une facture nouvellement créée
    // depuis cet événement — sans jamais toucher les lignes d'une facture existante.
    $axeDefautEvenement = null;
    // Organisateur lié à l'événement (carte du même nom) : présélectionné comme
    // structure d'une facture nouvellement créée depuis cet événement — sans
    // effet sur une facture déjà enregistrée.
    $structureDefautEvenement = null;
    if ($evenementId && !$facture) {
        $stmt = db()->prepare('SELECT axe_analytique_id_defaut, organisateur_structure_id FROM evenements WHERE id = ?');
        $stmt->execute([$evenementId]);
        $evRow = $stmt->fetch();
        $axeDefautEvenement = $axes ? ((int) ($evRow['axe_analytique_id_defaut'] ?? 0) ?: null) : null;
        $structureDefautEvenement = (int) ($evRow['organisateur_structure_id'] ?? 0) ?: null;
    }

    $renderForm = function (?string $err) use (
        $facture, $id, $structures, $comptes, $axes, $delaiDefaut, $evenementId, $axeDefautEvenement, $structureDefautEvenement
    ) {
        render('facturation_form', [
            'facture' => $facture, 'id' => $id, 'structures' => $structures, 'comptes' => $comptes, 'axes' => $axes,
            'delaiDefaut' => $delaiDefaut, 'evenementId' => $evenementId, 'axeDefautEvenement' => $axeDefautEvenement,
            'structureDefautEvenement' => $structureDefautEvenement,
            'err' => $err, 'post' => $_POST,
        ], $id ? 'Modifier la facture' : 'Nouvelle facture');
    };

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $renderForm(null);
        return;
    }

    check_csrf();
    $structureRaw     = (string) ($_POST['structure_id'] ?? '');
    $nouveauStructure = $structureRaw === '__new__';
    $compteId   = ($_POST['compte_bancaire_id'] ?? '') !== '' ? (int) $_POST['compte_bancaire_id'] : null;
    $delaiJours = max(1, (int) ($_POST['delai_jours'] ?? $delaiDefaut));
    $communication = trim($_POST['communication'] ?? '');
    $lignes = facturation_lire_lignes_postees();

    $err = null;
    $structureId = null;
    $ndNom = trim($_POST['nd_nom'] ?? '');
    if ($nouveauStructure) {
        if ($ndNom === '') {
            $err = 'Le nom de la nouvelle structure est obligatoire.';
        }
    } else {
        $structureId = (int) $structureRaw;
        $stmtD = db()->prepare('SELECT 1 FROM structures WHERE id = ?');
        $stmtD->execute([$structureId]);
        if (!$stmtD->fetchColumn()) {
            $err = 'Choisissez une structure.';
        }
    }
    if (!$err && !$lignes) {
        $err = 'Ajoutez au moins une ligne avec une description, une quantité et un prix unitaire.';
    }
    if ($err) {
        $renderForm($err);
        return;
    }

    if ($nouveauStructure) {
        $structureId = structure_creer_depuis_post('nd_');
    }

    try {
        $factureId = facturation_sauvegarder_brouillon($id ?: null, $structureId, $compteId, $delaiJours, $communication, $lignes, $evenementId);
    } catch (PDOException $ex) {
        $renderForm('Enregistrement impossible : ' . (str_contains($ex->getMessage(), 'FOREIGN KEY')
            ? "l'événement lié n'existe plus." : 'erreur inattendue.'));
        return;
    }
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
    // manuel (l'automatique n'a pas matché — nom de la structure absent du texte
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
    $structure = [
        'nom' => $facture['structure_nom'], 'adresse_rue' => $facture['adresse_rue'],
        'adresse_npa' => $facture['adresse_npa'], 'adresse_localite' => $facture['adresse_localite'],
        'adresse_pays' => $facture['adresse_pays'],
    ];
    return facturation_generer_pdf($facture, $lignes, $structure, $compte);
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
    $destinataire = trim((string) ($_POST['destinataire'] ?? $facture['structure_email']));
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

// --- Structures (ex-débiteurs) : liste/fiche partagées entre les modules
// facturation et booking (voir SPEC_BOOKING.md §3) --------------------------
function route_structures(): void
{
    require_login();
    $recherche = trim((string) ($_GET['q'] ?? ''));
    $categorieId = (int) ($_GET['categorie_id'] ?? 0);
    $categorieChamps = structure_categorie_champs($categorieId);
    $categorie = $categorieChamps['categorie'];
    $sousCategorie = $categorieChamps['sous_categorie'];
    $pays = trim((string) ($_GET['pays'] ?? ''));
    $region = trim((string) ($_GET['region'] ?? ''));
    $tagId = (int) ($_GET['tag_id'] ?? 0);
    $pgTaille = pagination_taille('structures_taille');
    $retourFiltres = ['q' => $recherche, 'categorie_id' => $categorieId, 'pays' => $pays, 'region' => $region, 'tag_id' => $tagId];

    // Modification groupée (sélection de lignes + barre flottante), même esprit que
    // le lettrage/l'axe analytique en masse sur les écritures ou les événements.
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $section = $_POST['section'] ?? '';
        if ($section === 'bulk_undo') {
            $r = bulk_undo_appliquer();
            redirect($r['route'] ?? 'structures', ($r['retour'] ?? $retourFiltres) + ($r ? ['ok' => 'annule'] : []));
        }
        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            unset($_SESSION['bulk_undo']);
            if ($section === 'delete') {
                // Une structure référencée par une facture est ignorée (jamais supprimée
                // en masse par erreur) plutôt que de bloquer toute la sélection.
                $stmtRef = db()->prepare("SELECT DISTINCT structure_id FROM factures WHERE structure_id IN ($in)");
                $stmtRef->execute($ids);
                $refs = array_map('intval', $stmtRef->fetchAll(PDO::FETCH_COLUMN));
                $idsSupprimables = array_values(array_diff($ids, $refs));
                if ($idsSupprimables) {
                    $inSup = implode(',', array_fill(0, count($idsSupprimables), '?'));
                    db()->prepare("DELETE FROM structures WHERE id IN ($inSup)")->execute($idsSupprimables);
                }
                if ($refs) {
                    $retourFiltres['structBloquees'] = count($refs);
                }
            } elseif ($section === 'categorie') {
                $bulkCategorieChamps = structure_categorie_champs((int) ($_POST['bulk_categorie_id'] ?? 0));
                if ($bulkCategorieChamps['categorie'] !== '') {
                    bulk_undo_memoriser('structures', $ids, ['categorie', 'sous_categorie'], 'structures', $retourFiltres);
                    db()->prepare("UPDATE structures SET categorie = ?, sous_categorie = ? WHERE id IN ($in)")
                        ->execute(array_merge([$bulkCategorieChamps['categorie'], $bulkCategorieChamps['sous_categorie']], $ids));
                }
            } elseif ($section === 'actif') {
                $actif = ($_POST['bulk_actif'] ?? '') === '1' ? 1 : 0;
                bulk_undo_memoriser('structures', $ids, ['actif', 'desinscrit'], 'structures', $retourFiltres);
                if ($actif) {
                    db()->prepare("UPDATE structures SET actif = ? WHERE id IN ($in)")->execute(array_merge([$actif], $ids));
                } else {
                    db()->prepare("UPDATE structures SET actif = 0, desinscrit = 1 WHERE id IN ($in)")->execute($ids);
                }
            } elseif ($section === 'desinscrit') {
                $desinscrit = ($_POST['bulk_desinscrit'] ?? '') === '1' ? 1 : 0;
                bulk_undo_memoriser('structures', $ids, ['desinscrit'], 'structures', $retourFiltres);
                db()->prepare("UPDATE structures SET desinscrit = ? WHERE id IN ($in)")->execute(array_merge([$desinscrit], $ids));
            } elseif ($section === 'type' && in_array($_POST['bulk_type'] ?? '', ['organisation', 'particulier'], true)) {
                bulk_undo_memoriser('structures', $ids, ['type'], 'structures', $retourFiltres);
                db()->prepare("UPDATE structures SET type = ? WHERE id IN ($in)")
                    ->execute(array_merge([$_POST['bulk_type']], $ids));
            } elseif ($section === 'ville') {
                bulk_undo_memoriser('structures', $ids, ['adresse_localite'], 'structures', $retourFiltres);
                db()->prepare("UPDATE structures SET adresse_localite = ? WHERE id IN ($in)")
                    ->execute(array_merge([trim($_POST['bulk_ville'] ?? '')], $ids));
            } elseif ($section === 'region') {
                bulk_undo_memoriser('structures', $ids, ['region'], 'structures', $retourFiltres);
                db()->prepare("UPDATE structures SET region = ? WHERE id IN ($in)")
                    ->execute(array_merge([trim($_POST['bulk_region'] ?? '')], $ids));
            } elseif ($section === 'pays') {
                bulk_undo_memoriser('structures', $ids, ['adresse_pays'], 'structures', $retourFiltres);
                db()->prepare("UPDATE structures SET adresse_pays = ? WHERE id IN ($in)")
                    ->execute(array_merge([trim($_POST['bulk_pays'] ?? '')], $ids));
            } elseif ($section === 'via') {
                bulk_undo_memoriser('structures', $ids, ['via'], 'structures', $retourFiltres);
                db()->prepare("UPDATE structures SET via = ? WHERE id IN ($in)")
                    ->execute(array_merge([trim($_POST['bulk_via'] ?? '')], $ids));
            } elseif ($section === 'fusionner' && count($ids) >= 2) {
                $_SESSION['fusion_ids'] = $ids;
                redirect('structure_fusion');
            } elseif ($section === 'transformer_lieu' && count($ids) >= 2) {
                $_SESSION['transformer_ids'] = $ids;
                redirect('structure_transformer');
            }
            if ($section !== '' && $section !== 'delete' && $section !== 'fusionner' && $section !== 'transformer_lieu' && isset($_SESSION['bulk_undo'])) {
                $retourFiltres['bulk'] = count($ids);
            }
        }
        redirect('structures', $retourFiltres);
    }

    $where = ' WHERE 1=1';
    $params = [];
    if ($categorie !== '') {
        $where .= ' AND s.categorie = ?';
        $params[] = $categorie;
    }
    if ($sousCategorie !== '') {
        $where .= ' AND s.sous_categorie = ?';
        $params[] = $sousCategorie;
    }
    if ($pays !== '') {
        $where .= ' AND s.adresse_pays = ?';
        $params[] = $pays;
    }
    if ($region !== '') {
        $where .= ' AND s.region = ?';
        $params[] = $region;
    }
    if ($tagId) {
        $where .= ' AND s.id IN (SELECT structure_id FROM structure_tag_liens WHERE tag_id = ?)';
        $params[] = $tagId;
    }

    $stmtTotStruct = db()->prepare('SELECT COUNT(*) FROM structures s' . $where);
    $stmtTotStruct->execute($params);
    $totalSansRecherche = (int) $stmtTotStruct->fetchColumn();
    $modeClient = pagination_mode_client($totalSansRecherche);

    $selectCols = "s.*, (SELECT COUNT(*) FROM factures f WHERE f.structure_id = s.id) AS nb_factures,
        (SELECT GROUP_CONCAT(l.nom, ', ') FROM structure_lieux sl JOIN lieux l ON l.id = sl.lieu_id WHERE sl.structure_id = s.id) AS lieux_noms,
        COALESCE(
            (SELECT email FROM structure_contacts WHERE structure_id = s.id AND est_administration = 1 LIMIT 1),
            (SELECT email FROM structure_contacts WHERE structure_id = s.id AND email <> '' ORDER BY id LIMIT 1),
            NULLIF(s.email, '')
        ) AS email_affiche";
    $orderBy = ' ORDER BY s.actif DESC, s.nom';

    if ($modeClient) {
        $stmt = db()->prepare('SELECT ' . $selectCols . ' FROM structures s' . $where . $orderBy);
        $stmt->execute($params);
        $structures = $stmt->fetchAll();
        $pgPage  = 1;
        $pgTotal = $totalSansRecherche;
    } else {
        [$rechSql, $rechParams] = recherche_sql(['s.nom', 's.adresse_rue', 's.adresse_npa', 's.adresse_localite', 's.email']);
        $where .= $rechSql;
        $params = array_merge($params, $rechParams);

        $stmtTot = db()->prepare('SELECT COUNT(*) FROM structures s' . $where);
        $stmtTot->execute($params);
        $pgTotal = (int) $stmtTot->fetchColumn();

        $pgPage = pagination_page();
        [$limitSql, $limitParams] = pagination_sql($pgPage, $pgTaille);

        $stmt = db()->prepare('SELECT ' . $selectCols . ' FROM structures s' . $where . $orderBy . $limitSql);
        $stmt->execute(array_merge($params, $limitParams));
        $structures = $stmt->fetchAll();
    }

    $regionsDispo = db()->query("SELECT DISTINCT region FROM structures WHERE region <> '' ORDER BY region")->fetchAll(PDO::FETCH_COLUMN);
    $tagsDispo = module_actif('booking') ? db()->query('SELECT * FROM structure_tags ORDER BY nom')->fetchAll() : [];

    render('structures_liste', [
        'structures' => $structures,
        'recherche' => $recherche,
        'categorieId' => $categorieId,
        'pays' => $pays,
        'region' => $region,
        'tagId' => $tagId,
        'categoriesPourSelect' => structure_categories_pour_select(),
        'regionsDispo' => $regionsDispo,
        'tagsDispo' => $tagsDispo,
        'modeClient' => $modeClient,
        'pgRoute'   => 'structures',
        'pgParams'  => ['q' => $recherche, 'categorie_id' => $categorieId, 'pays' => $pays, 'region' => $region, 'tag_id' => $tagId],
        'pgPage'    => $pgPage,
        'pgTaille'  => $pgTaille,
        'pgTotal'   => $pgTotal,
        'bulkCount' => isset($_GET['bulk']) ? (int) $_GET['bulk'] : null,
        'okAnnule'  => ($_GET['ok'] ?? '') === 'annule',
        'structBloquees' => isset($_GET['structBloquees']) ? (int) $_GET['structBloquees'] : 0,
    ], 'Structures');
}

// Données CRM de la fiche (booking) : contacts, flux de notes, tags, lieux
// liés — chargées seulement en modification (pas de sens sur une création) et
// seulement si le module est actif.
function structure_donnees_crm(int $id): array
{
    if (!$id || !module_actif('booking')) {
        return ['contacts' => [], 'notes' => [], 'tags' => [], 'tagsDispo' => [], 'lieuxLies' => [], 'lieuxDispo' => [], 'categoriesLieu' => []];
    }
    $stmtContacts = db()->prepare('SELECT * FROM structure_contacts WHERE structure_id = ? ORDER BY actif DESC, id');
    $stmtContacts->execute([$id]);

    $stmtNotes = db()->prepare(
        "SELECT n.*, u.prenom AS u_prenom, u.nom AS u_nom FROM structure_notes n
         LEFT JOIN utilisateurs u ON u.id = n.utilisateur_id
         WHERE n.structure_id = ? ORDER BY n.cree_le DESC, n.id DESC"
    );
    $stmtNotes->execute([$id]);

    $stmtTags = db()->prepare(
        'SELECT t.* FROM structure_tags t JOIN structure_tag_liens l ON l.tag_id = t.id
         WHERE l.structure_id = ? ORDER BY t.nom'
    );
    $stmtTags->execute([$id]);

    $stmtLieuxLies = db()->prepare(
        'SELECT l.* FROM lieux l JOIN structure_lieux sl ON sl.lieu_id = l.id
         WHERE sl.structure_id = ? ORDER BY l.type, l.nom'
    );
    $stmtLieuxLies->execute([$id]);

    return [
        'contacts'  => $stmtContacts->fetchAll(),
        'notes'     => $stmtNotes->fetchAll(),
        'tags'      => $stmtTags->fetchAll(),
        'tagsDispo' => db()->query('SELECT * FROM structure_tags ORDER BY nom')->fetchAll(),
        'lieuxLies' => $stmtLieuxLies->fetchAll(),
        'lieuxDispo' => db()->query('SELECT * FROM lieux ORDER BY type, nom')->fetchAll(),
        'categoriesLieu' => lieu_categories_liste(),
    ];
}

function route_structure(): void
{
    require_login();
    $id = (int) ($_GET['id'] ?? 0);
    $structure = null;
    if ($id) {
        $stmt = db()->prepare(
            'SELECT s.*, (SELECT COUNT(*) FROM factures f WHERE f.structure_id = s.id) AS nb_factures
             FROM structures s WHERE s.id = ?'
        );
        $stmt->execute([$id]);
        $structure = $stmt->fetch();
        if (!$structure) {
            redirect('structures');
        }
    }
    $renderForm = function (?string $err, array $structureAffichee) use ($id) {
        $map = structure_categorie_map();
        render('structure_form', array_merge(
            ['structure' => $structureAffichee, 'err' => $err,
             'categoriesPourSelect' => structure_categories_pour_select($map),
             'categorieIdSelectionnee' => structure_categorie_id_pour(
                 (string) ($structureAffichee['categorie'] ?? ''),
                 (string) ($structureAffichee['sous_categorie'] ?? ''),
                 $map
             )],
            structure_donnees_crm($id)
        ), $id ? 'Modifier la structure' : 'Nouvelle structure');
    };

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $categorieChamps = structure_categorie_champs((int) ($_POST['categorie_id'] ?? 0));
        if ($categorieChamps['categorie'] === '') {
            $categorieChamps = ['categorie' => structure_categorie_par_defaut(), 'sous_categorie' => ''];
        }
        $champs = [
            'type'             => ($_POST['type'] ?? '') === 'particulier' ? 'particulier' : 'organisation',
            'categorie'        => $categorieChamps['categorie'],
            'sous_categorie'   => $categorieChamps['sous_categorie'],
            'nom'              => trim($_POST['nom'] ?? ''),
            'adresse_rue'      => trim($_POST['adresse_rue'] ?? ''),
            'adresse_npa'      => trim($_POST['adresse_npa'] ?? ''),
            'adresse_localite' => trim($_POST['adresse_localite'] ?? ''),
            'adresse_pays'     => trim($_POST['adresse_pays'] ?? '') ?: 'Suisse',
            'region'           => trim($_POST['region'] ?? ''),
            'grande_region'    => trim($_POST['grande_region'] ?? ''),
            'site_web'         => trim($_POST['site_web'] ?? ''),
            'via'              => trim($_POST['via'] ?? ''),
            'notes'            => trim($_POST['notes'] ?? ''),
        ];
        // actif/desinscrit : gérés à part (bloc « Statut » de la sidebar, bascule
        // immédiate via route_structure_statut()) — jamais touchés par cet
        // enregistrement, sinon toute sauvegarde de la fiche les réinitialiserait.
        // email/telephone/personne_contact : plus dans ce formulaire (remplacés par
        // la card Contacts) — colonnes conservées mais volontairement absentes des
        // requêtes ci-dessous, pour ne jamais écraser une valeur historique.
        $err = $champs['nom'] === '' ? 'Le nom est obligatoire.' : null;
        if ($err) {
            $renderForm($err, array_merge((array) $structure, $champs, ['id' => $id]));
            return;
        }
        if ($id) {
            $champs['id'] = $id;
            db()->prepare('UPDATE structures SET type=:type, categorie=:categorie, sous_categorie=:sous_categorie, nom=:nom,
                            adresse_rue=:adresse_rue, adresse_npa=:adresse_npa,
                            adresse_localite=:adresse_localite, adresse_pays=:adresse_pays, region=:region, grande_region=:grande_region, site_web=:site_web,
                            via=:via, notes=:notes
                            WHERE id=:id')->execute($champs);
        } else {
            // Création : active et non désinscrite par défaut.
            db()->prepare('INSERT INTO structures (type, categorie, sous_categorie, nom, adresse_rue, adresse_npa, adresse_localite, adresse_pays,
                            region, grande_region, site_web, via, notes, actif, desinscrit)
                            VALUES (:type, :categorie, :sous_categorie, :nom, :adresse_rue, :adresse_npa, :adresse_localite, :adresse_pays,
                            :region, :grande_region, :site_web, :via, :notes, 1, 0)')
                ->execute($champs);
        }
        redirect('structures');
    }
    $renderForm(($_GET['err'] ?? '') === 'used' ? 'Suppression impossible : des factures sont rattachées à cette structure.' : null, (array) $structure);
}

// Bascule immédiate du statut (active / désinscrite du mailing) depuis le bloc
// « Statut » de la sidebar — enregistrée à chaque coche sans passer par le
// bouton Enregistrer de la fiche. Règle métier : une structure inactive est
// toujours désinscrite du mailing (comme le bulk change et l'ancien formulaire).
function route_structure_statut(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('structures'); }
    check_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    if (!$id) { redirect('structures'); }
    $actif = isset($_POST['actif']) ? 1 : 0;
    $desinscrit = isset($_POST['desinscrit']) ? 1 : 0;
    if (!$actif) { $desinscrit = 1; }
    db()->prepare('UPDATE structures SET actif = ?, desinscrit = ? WHERE id = ?')->execute([$actif, $desinscrit, $id]);
    redirect('structure', ['id' => $id]);
}

function route_structure_renommer(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('structures'); }
    check_csrf();
    $id  = (int) ($_POST['id'] ?? 0);
    $nom = trim($_POST['nom'] ?? '');
    if ($id && $nom !== '') {
        db()->prepare('UPDATE structures SET nom = ? WHERE id = ?')->execute([$nom, $id]);
    }
    redirect('structure', ['id' => $id]);
}

function route_structure_delete(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $id = (int) ($_POST['id'] ?? 0);
        if (!supprimer_si_non_reference('structures', $id, 'factures', 'structure_id')) {
            redirect('structure', ['id' => $id, 'err' => 'used']);
        }
    }
    redirect('structures');
}

// Fusion de structures (action groupée « Fusionner ») : candidats mémorisés en
// session par route_structures() ($_SESSION['fusion_ids'], ≥ 2 ids). Choix de
// la structure à garder (ses propres champs restent tels quels), puis
// structures_fusionner() réaffecte contacts/notes/mailing/factures/tags/lieux
// des autres avant de les supprimer — voir lib/booking.php.
function route_structure_fusion(): void
{
    require_login();
    $ids = array_values(array_unique(array_map('intval', (array) ($_SESSION['fusion_ids'] ?? []))));
    if (count($ids) < 2) {
        redirect('structures');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $garderId = (int) ($_POST['garder_id'] ?? 0);
        if (!in_array($garderId, $ids, true)) {
            redirect('structure_fusion');
        }
        $autres = array_values(array_diff($ids, [$garderId]));
        structures_fusionner($garderId, $autres);
        unset($_SESSION['fusion_ids']);
        redirect('structure', ['id' => $garderId, 'ok' => 'fusion']);
    }

    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare(
        "SELECT s.*, (SELECT COUNT(*) FROM structure_contacts WHERE structure_id = s.id) AS nb_contacts,
                (SELECT COUNT(*) FROM structure_notes WHERE structure_id = s.id) AS nb_notes,
                (SELECT COUNT(*) FROM factures WHERE structure_id = s.id) AS nb_factures,
                (SELECT COUNT(*) FROM structure_tag_liens WHERE structure_id = s.id) AS nb_tags,
                (SELECT COUNT(*) FROM structure_lieux WHERE structure_id = s.id) AS nb_lieux
         FROM structures s WHERE s.id IN ($in) ORDER BY s.nom"
    );
    $stmt->execute($ids);
    $candidats = $stmt->fetchAll();
    if (count($candidats) < 2) {
        unset($_SESSION['fusion_ids']);
        redirect('structures');
    }

    render('structure_fusion', ['candidats' => $candidats], 'Fusionner des structures');
}

// Transformation en salle/festival (action groupée « Transformer en lieu d'une
// structure ») : ids mémorisés en session ($_SESSION['transformer_ids'], ≥ 2).
// L'utilisateur désigne l'organisateur parmi la sélection ; les autres structures
// deviennent des lieux liés à lui (structure_transformer_en_lieu(), lib/booking.php,
// qui reprend leurs contacts/notes/factures/étiquettes puis les supprime). Type du
// lieu : déduit du nom (« festival » → festival, sinon salle) ou imposé globalement.
function route_structure_transformer(): void
{
    require_login();
    $ids = array_values(array_unique(array_map('intval', (array) ($_SESSION['transformer_ids'] ?? []))));
    if (count($ids) < 2) {
        redirect('structures');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $orgId = (int) ($_POST['organisateur_id'] ?? 0);
        // 'deduire' = déduire du nom ; sinon une catégorie de lieu explicite.
        $typeChoix = ($_POST['type'] ?? '') === 'deduire' ? 'deduire' : lieu_categorie_normaliser((string) ($_POST['type'] ?? ''));
        if ($typeChoix === null) { $typeChoix = 'deduire'; }
        if (!in_array($orgId, $ids, true)) {
            redirect('structure_transformer');
        }
        foreach (array_diff($ids, [$orgId]) as $structureId) {
            $stmt = db()->prepare('SELECT nom, sous_categorie FROM structures WHERE id = ?');
            $stmt->execute([$structureId]);
            $s = $stmt->fetch();
            if (!$s) { continue; }
            if ($typeChoix === 'deduire') {
                // Déduction festival/salle depuis le nom + la sous-catégorie
                // (même règle que l'import — helper partagé).
                $type = structure_import_type_lieu($s['nom'] . ' ' . $s['sous_categorie'], '');
            } else {
                $type = $typeChoix;
            }
            structure_transformer_en_lieu((int) $structureId, $orgId, $type);
        }
        unset($_SESSION['transformer_ids']);
        redirect('structure', ['id' => $orgId, 'ok' => 'transforme']);
    }

    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare(
        "SELECT s.*, (SELECT COUNT(*) FROM structure_contacts WHERE structure_id = s.id) AS nb_contacts,
                (SELECT COUNT(*) FROM structure_notes WHERE structure_id = s.id) AS nb_notes,
                (SELECT COUNT(*) FROM factures WHERE structure_id = s.id) AS nb_factures
         FROM structures s WHERE s.id IN ($in) ORDER BY s.nom"
    );
    $stmt->execute($ids);
    $candidats = $stmt->fetchAll();
    if (count($candidats) < 2) {
        unset($_SESSION['transformer_ids']);
        redirect('structures');
    }

    render('structure_transformer', ['candidats' => $candidats, 'categoriesLieu' => lieu_categories_liste()], 'Transformer en salles/festivals');
}

// --- Import de factures historiques (JSON) ----------------------------------
function route_import_factures(): void
{
    require_login();
    $err = null; $resultats = null; $resume = null; $simule = true;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $simule = !isset($_POST['appliquer']);
        $r = lire_fichier_importe(2 * 1024 * 1024, 'Fichier trop volumineux (2 Mo maximum).', 'import_factures_json', 'Veuillez choisir un fichier JSON à importer.');
        $err  = $r['err'];
        $json = $r['contenu'];
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
