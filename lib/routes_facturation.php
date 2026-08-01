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

    // Toutes sauf inactives (une structure « ne pas contacter » — mailing —
    // peut très bien rester facturable), même portée que l'ancien actif = 1.
    $structures = db()->query("SELECT * FROM structures WHERE statut != 'inactif' ORDER BY nom")->fetchAll();
    $comptes   = compta_comptes();
    $axes      = module_actif('analytique')
        ? db()->query('SELECT * FROM axes_analytiques WHERE actif = 1 ORDER BY ordre, id')->fetchAll()
        : [];
    $delaiDefaut = (int) param('facturation_delai_jours_defaut', '30');
    // Facture créée depuis un événement (bouton « Créer », carte Factures
    // liées, module événements) : evenement_id porté par l'URL à la création, ou déjà
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
// Filtres de la liste des structures (?p=structures) — factorisé pour être
// réutilisé par la vue carte (mêmes critères, résultat non paginé, voir
// structures_carte_points()).
// Chaque filtre structuré est mémorisé en session (filtre_persistant(), comme
// evenements_lire_filtres()/lieux_filtres()) : revenir sur ?p=structures sans
// query string (lien de la sidebar, retour contextuel…) rouvre les derniers
// filtres actifs. Seule la recherche texte (q) ne l'est jamais.
function structures_filtres(): array
{
    $categorieId = (int) filtre_persistant('categorie_id', 'structures_categorie_id', 0);
    $categorieChamps = structure_categorie_champs($categorieId);
    $categorie = $categorieChamps['categorie'];
    $sousCategorie = $categorieChamps['sous_categorie'];
    $pays = trim((string) filtre_persistant('pays', 'structures_pays', ''));
    $departementCanton = trim((string) filtre_persistant('departement_canton', 'structures_departement_canton', ''));
    $tagId = (int) filtre_persistant('tag_id', 'structures_tag_id', 0);
    // Statut : « actif » par défaut = structures.statut IN ('actif',
    // 'contact_privilegie') — les deux sont des variantes actives au quotidien
    // (les fiches ne_pas_contacter/inactif sont du bruit dans le travail
    // courant), voir STRUCTURE_STATUTS (lib/booking.php). 'contact_privilegie'
    // en filtre à part ne montre qu'eux ; 'ne_pas_contacter'/'inactif' idem ;
    // 'tous' pour tout voir.
    $statut = valeur_autorisee((string) filtre_persistant('statut', 'structures_statut', 'actif'), array_merge(STRUCTURE_STATUTS, ['tous']), 'actif');
    // Marquage rapide (flag_toggle_html()) : '' = tous, 'aucun' = non marquées,
    // 'star'/'heart' = marquées.
    $flag = valeur_autorisee((string) filtre_persistant('flag', 'structures_flag', ''), ['', 'aucun', 'star', 'heart'], '');
    // Villes jamais géolocalisées avec succès (cache lieux_geocodage) — filtre
    // d'appoint, accessible depuis le lien de la vue carte (voir
    // views/_structures_carte.php, carte_banner_geocodage_html()). Jamais
    // mémorisé en session : lien ponctuel, pas un mode de travail courant.
    $nonLocalises = ($_GET['non_localises'] ?? '') === '1';
    // Filtres avancés « lieu » (jauge, mois) : depuis la fusion
    // lieux→structures (migration_59/60), ces champs vivent directement sur la
    // structure — filtre simple sur ses propres colonnes, plus d'indirection
    // par structure_lieux.
    $lieuJaugeMinBrut = (string) filtre_persistant('lieu_jauge_min', 'structures_lieu_jauge_min', '');
    $lieuJaugeMin = $lieuJaugeMinBrut !== '' ? max(0, (int) $lieuJaugeMinBrut) : null;
    $lieuJaugeMaxBrut = (string) filtre_persistant('lieu_jauge_max', 'structures_lieu_jauge_max', '');
    $lieuJaugeMax = $lieuJaugeMaxBrut !== '' ? max(0, (int) $lieuJaugeMaxBrut) : null;
    $lieuMoisEvenement = (int) filtre_persistant('lieu_mois_evenement', 'structures_lieu_mois_evenement', 0);
    $lieuMoisProg = (int) filtre_persistant('lieu_mois_prog', 'structures_lieu_mois_prog', 0);
    if ($lieuMoisEvenement < 1 || $lieuMoisEvenement > 12) { $lieuMoisEvenement = 0; }
    if ($lieuMoisProg < 1 || $lieuMoisProg > 12) { $lieuMoisProg = 0; }

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
    if ($departementCanton !== '') {
        $where .= ' AND s.departement_canton = ?';
        $params[] = $departementCanton;
    }
    if ($nonLocalises) {
        $where .= geocodage_non_localises_where('s.adresse_localite', 's.departement_canton', 's.adresse_pays');
    }
    if ($tagId) {
        $where .= ' AND s.id IN (SELECT structure_id FROM structure_tag_liens WHERE tag_id = ?)';
        $params[] = $tagId;
    }
    if ($statut === 'actif') {
        $where .= " AND s.statut IN ('actif','contact_privilegie')";
    } elseif (in_array($statut, STRUCTURE_STATUTS, true)) {
        $where .= ' AND s.statut = ?';
        $params[] = $statut;
    }
    if ($flag === 'aucun') {
        $where .= " AND s.flag = ''";
    } elseif ($flag !== '') {
        $where .= ' AND s.flag = ?';
        $params[] = $flag;
    }
    if ($lieuJaugeMin !== null || $lieuJaugeMax !== null || $lieuMoisEvenement || $lieuMoisProg) {
        // Bornes de jauge/mois injectées telles quelles (déjà validées en
        // entiers), même raison qu'ailleurs : un paramètre PDO serait lié en
        // texte, or SQLite classe tout entier avant tout texte.
        if ($lieuJaugeMin !== null) {
            $where .= ' AND COALESCE(s.jauge_max, s.jauge_min, 0) >= ' . $lieuJaugeMin;
        }
        if ($lieuJaugeMax !== null) {
            $where .= ' AND COALESCE(s.jauge_min, s.jauge_max) IS NOT NULL AND COALESCE(s.jauge_min, s.jauge_max) <= ' . $lieuJaugeMax;
        }
        $filtreMoisLieu = function (string $colDebut, string $colFin, int $mois) use (&$where): void {
            $where .= " AND s.$colDebut IS NOT NULL AND s.$colFin IS NOT NULL AND ("
                . "(s.$colDebut <= s.$colFin AND $mois BETWEEN s.$colDebut AND s.$colFin) OR "
                . "(s.$colDebut > s.$colFin AND ($mois >= s.$colDebut OR $mois <= s.$colFin)))";
        };
        if ($lieuMoisEvenement) { $filtreMoisLieu('mois_evenement_debut', 'mois_evenement_fin', $lieuMoisEvenement); }
        if ($lieuMoisProg) { $filtreMoisLieu('mois_debut', 'mois_fin', $lieuMoisProg); }
    }

    return [
        'where' => $where, 'params' => $params, 'categorieId' => $categorieId,
        'pays' => $pays, 'departementCanton' => $departementCanton, 'tagId' => $tagId, 'statut' => $statut, 'flag' => $flag,
        'nonLocalises' => $nonLocalises,
        'lieuJaugeMin' => $lieuJaugeMin, 'lieuJaugeMax' => $lieuJaugeMax,
        'lieuMoisEvenement' => $lieuMoisEvenement, 'lieuMoisProg' => $lieuMoisProg,
    ];
}

// Points de la vue carte (?p=structures&vue=carte) : structures filtrées
// (mêmes critères que la liste), groupées par ville géolocalisée — jamais
// paginé (voir carte_points_grouper(), lib/geocodage.php). Retourne
// [points, nbNonGeolocalises].
function structures_carte_points(string $where, array $params): array
{
    [$rechSql, $rechParams] = recherche_sql(['s.nom', 's.adresse_rue', 's.adresse_npa', 's.adresse_localite', 's.email',
        '(SELECT GROUP_CONCAT(t.nom) FROM structure_tag_liens tl JOIN structure_tags t ON t.id = tl.tag_id WHERE tl.structure_id = s.id)']);
    $stmt = db()->prepare(
        "SELECT s.id, s.nom, s.categorie, s.adresse_localite AS ville, s.departement_canton, s.adresse_pays AS pays
         FROM structures s" . $where . " AND s.adresse_localite <> ''" . $rechSql . ' ORDER BY s.adresse_localite, s.nom'
    );
    $stmt->execute(array_merge($params, $rechParams));
    return carte_points_grouper(
        $stmt->fetchAll(),
        fn ($r) => ['id' => (int) $r['id'], 'nom' => (string) $r['nom'], 'type' => (string) $r['categorie']]
    );
}

function route_structures(): void
{
    require_login();
    $recherche = trim((string) ($_GET['q'] ?? ''));
    // Mémorise la dernière vue utilisée (comme lieux) : un lien « Structures »
    // sans ?vue= explicite (sidebar) rouvre la dernière consultée.
    $vue = filtre_persistant('vue', 'structures_vue', 'liste') === 'carte' ? 'carte' : 'liste';
    $pgTaille = pagination_taille('structures_taille');
    $f = structures_filtres();
    $where = $f['where'];
    $params = $f['params'];
    $categorieId = $f['categorieId'];
    $pays = $f['pays'];
    $departementCanton = $f['departementCanton'];
    $tagId = $f['tagId'];
    $statut = $f['statut'];
    $lieuJaugeMin = $f['lieuJaugeMin'];
    $lieuJaugeMax = $f['lieuJaugeMax'];
    $lieuMoisEvenement = $f['lieuMoisEvenement'];
    $lieuMoisProg = $f['lieuMoisProg'];
    $flag = $f['flag'];
    $nonLocalises = $f['nonLocalises'];
    $retourFiltres = [
        'q' => $recherche, 'categorie_id' => $categorieId, 'pays' => $pays, 'departement_canton' => $departementCanton, 'tag_id' => $tagId, 'statut' => $statut,
        'lieu_jauge_min' => $lieuJaugeMin ?? '', 'lieu_jauge_max' => $lieuJaugeMax ?? '',
        'lieu_mois_evenement' => $lieuMoisEvenement ?: '', 'lieu_mois_prog' => $lieuMoisProg ?: '', 'flag' => $flag,
    ];

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
            } elseif ($section === 'statut' && in_array($_POST['bulk_statut'] ?? '', STRUCTURE_STATUTS, true)) {
                bulk_undo_memoriser('structures', $ids, ['statut'], 'structures', $retourFiltres);
                db()->prepare("UPDATE structures SET statut = ? WHERE id IN ($in)")->execute(array_merge([$_POST['bulk_statut']], $ids));
            } elseif ($section === 'type' && in_array($_POST['bulk_type'] ?? '', ['organisation', 'particulier'], true)) {
                bulk_undo_memoriser('structures', $ids, ['type'], 'structures', $retourFiltres);
                db()->prepare("UPDATE structures SET type = ? WHERE id IN ($in)")
                    ->execute(array_merge([$_POST['bulk_type']], $ids));
            } elseif ($section === 'ville') {
                bulk_undo_memoriser('structures', $ids, ['adresse_localite'], 'structures', $retourFiltres);
                db()->prepare("UPDATE structures SET adresse_localite = ? WHERE id IN ($in)")
                    ->execute(array_merge([trim($_POST['bulk_ville'] ?? '')], $ids));
            } elseif ($section === 'departement_canton') {
                bulk_undo_memoriser('structures', $ids, ['departement_canton'], 'structures', $retourFiltres);
                db()->prepare("UPDATE structures SET departement_canton = ? WHERE id IN ($in)")
                    ->execute(array_merge([trim($_POST['bulk_departement_canton'] ?? '')], $ids));
            } elseif ($section === 'pays') {
                bulk_undo_memoriser('structures', $ids, ['adresse_pays'], 'structures', $retourFiltres);
                db()->prepare("UPDATE structures SET adresse_pays = ? WHERE id IN ($in)")
                    ->execute(array_merge([trim($_POST['bulk_pays'] ?? '')], $ids));
            // 'heart' reste dans la liste bien qu'inatteignable depuis l'UI
            // (cœur désactivé, voir route_structure_flag()) : simple
            // validation d'entrée, pas une réactivation de la fonctionnalité.
            } elseif ($section === 'flag' && in_array($_POST['bulk_flag'] ?? '', ['', 'star', 'heart'], true)) {
                bulk_undo_memoriser('structures', $ids, ['flag'], 'structures', $retourFiltres);
                db()->prepare("UPDATE structures SET flag = ? WHERE id IN ($in)")
                    ->execute(array_merge([$_POST['bulk_flag']], $ids));
            } elseif ($section === 'via') {
                bulk_undo_memoriser('structures', $ids, ['via'], 'structures', $retourFiltres);
                db()->prepare("UPDATE structures SET via = ? WHERE id IN ($in)")
                    ->execute(array_merge([trim($_POST['bulk_via'] ?? '')], $ids));
            } elseif ($section === 'tag_ajouter' && trim($_POST['bulk_tag_ajouter'] ?? '') !== '') {
                // Étiquette posée sur toute la sélection : créée si elle n'existe
                // pas encore (comme depuis une fiche). Pas d'annulation groupée
                // (les liens ne sont pas des colonnes de structures) — le retrait
                // groupé fait l'inverse ; chaque fiche garde une trace en historique.
                $nomTag = trim($_POST['bulk_tag_ajouter']);
                $stmtT = db()->prepare('SELECT id FROM structure_tags WHERE nom = ? COLLATE NOCASE');
                $stmtT->execute([$nomTag]);
                $tagId = $stmtT->fetchColumn();
                if ($tagId === false) {
                    db()->prepare('INSERT INTO structure_tags (nom) VALUES (?)')->execute([$nomTag]);
                    $tagId = (int) db()->lastInsertId();
                }
                $ins = db()->prepare('INSERT OR IGNORE INTO structure_tag_liens (structure_id, tag_id) VALUES (?, ?)');
                $n = 0;
                db()->beginTransaction();
                foreach ($ids as $sid) {
                    $ins->execute([$sid, (int) $tagId]);
                    if ((int) db()->query('SELECT changes()')->fetchColumn() > 0) {
                        journaliser('structure', (int) $sid, 'edition', 'Étiquette ajoutée : ' . $nomTag);
                        $n++;
                    }
                }
                db()->commit();
                $retourFiltres['tagbulk'] = $n;
                $retourFiltres['tagact'] = 'ajout';
                $retourFiltres['tagnom'] = $nomTag;
            } elseif ($section === 'tag_retirer' && (int) ($_POST['bulk_tag_retirer'] ?? 0) > 0) {
                $tagId = (int) $_POST['bulk_tag_retirer'];
                $stmtT = db()->prepare('SELECT nom FROM structure_tags WHERE id = ?');
                $stmtT->execute([$tagId]);
                $nomTag = (string) ($stmtT->fetchColumn() ?: '');
                $del = db()->prepare('DELETE FROM structure_tag_liens WHERE structure_id = ? AND tag_id = ?');
                $n = 0;
                db()->beginTransaction();
                foreach ($ids as $sid) {
                    $del->execute([$sid, $tagId]);
                    if ((int) db()->query('SELECT changes()')->fetchColumn() > 0) {
                        if ($nomTag !== '') {
                            journaliser('structure', (int) $sid, 'edition', 'Étiquette retirée : ' . $nomTag);
                        }
                        $n++;
                    }
                }
                db()->commit();
                $retourFiltres['tagbulk'] = $n;
                $retourFiltres['tagact'] = 'retrait';
                $retourFiltres['tagnom'] = $nomTag;
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

    if ($vue === 'carte') {
        [$cartePoints, $carteVillesManquantes] = structures_carte_points($where, $params);
        render('structures_liste', [
            'vue' => $vue, 'cartePoints' => $cartePoints, 'carteVillesManquantes' => $carteVillesManquantes,
            'structures' => [], 'nbEvenements' => [],
            'recherche' => $recherche, 'categorieId' => $categorieId, 'pays' => $pays, 'departementCanton' => $departementCanton,
            'tagId' => $tagId, 'statut' => $statut,
            'lieuJaugeMin' => $lieuJaugeMin, 'lieuJaugeMax' => $lieuJaugeMax,
            'lieuMoisEvenement' => $lieuMoisEvenement, 'lieuMoisProg' => $lieuMoisProg, 'flag' => $flag,
            'nonLocalises' => $nonLocalises,
            'tagBulk' => null, 'tagBulkAction' => '', 'tagBulkNom' => '',
            'categoriesPourSelect' => structure_categories_pour_select(), 'regionsDispo' => [], 'tagsDispo' => [],
            'modeClient' => true, 'pgRoute' => 'structures', 'pgParams' => [], 'pgPage' => 1, 'pgTaille' => $pgTaille, 'pgTotal' => 0,
            'bulkCount' => null, 'okAnnule' => false, 'structBloquees' => 0,
        ], 'Structures');
        return;
    }

    $stmtTotStruct = db()->prepare('SELECT COUNT(*) FROM structures s' . $where);
    $stmtTotStruct->execute($params);
    $totalSansRecherche = (int) $stmtTotStruct->fetchColumn();
    $modeClient = pagination_mode_client($totalSansRecherche);

    // Structures liées, DANS LES DEUX SENS (même principe que structure_donnees_crm()) :
    // celles que la structure organise (sens='organise', ex. ses salles/festivals)
    // et celle(s) qui l'organisent (sens='organise_par', si c'est elle-même un
    // lieu) — fusionnées dans une seule colonne « Structures liées », affichage
    // distingué par icône (voir views/structures_liste.php).
    $selectCols = "s.*, (SELECT COUNT(*) FROM factures f WHERE f.structure_id = s.id) AS nb_factures,
        (SELECT GROUP_CONCAT(nom || char(31) || id || char(31) || sens, char(30)) FROM (
            SELECT l.nom AS nom, l.id AS id, 'organise' AS sens FROM structure_organisateurs so JOIN structures l ON l.id = so.structure_id WHERE so.organisateur_id = s.id
            UNION ALL
            SELECT o.nom AS nom, o.id AS id, 'organise_par' AS sens FROM structure_organisateurs so JOIN structures o ON o.id = so.organisateur_id WHERE so.structure_id = s.id
            ORDER BY nom
        )) AS structures_liees,
        (SELECT GROUP_CONCAT(t.nom || char(31) || COALESCE(t.couleur, ''), char(30)) FROM structure_tag_liens tl JOIN structure_tags t ON t.id = tl.tag_id WHERE tl.structure_id = s.id) AS tags_noms,
        COALESCE(
            (SELECT email FROM structure_contacts WHERE structure_id = s.id AND est_administration = 1 LIMIT 1),
            (SELECT email FROM structure_contacts WHERE structure_id = s.id AND email <> '' ORDER BY id LIMIT 1),
            NULLIF(s.email, '')
        ) AS email_affiche";
    // « Contact privilégié » puis « actif » d'abord, « ne_pas_contacter » puis
    // « inactif » en dernier (même esprit que l'ancien ORDER BY s.actif DESC).
    $orderBy = " ORDER BY CASE s.statut WHEN 'contact_privilegie' THEN 0 WHEN 'actif' THEN 1 WHEN 'ne_pas_contacter' THEN 2 ELSE 3 END, s.nom";

    if ($modeClient) {
        $stmt = db()->prepare('SELECT ' . $selectCols . ' FROM structures s' . $where . $orderBy);
        $stmt->execute($params);
        $structures = $stmt->fetchAll();
        $pgPage  = 1;
        $pgTotal = $totalSansRecherche;
    } else {
        [$rechSql, $rechParams] = recherche_sql(['s.nom', 's.adresse_rue', 's.adresse_npa', 's.adresse_localite', 's.email',
        '(SELECT GROUP_CONCAT(t.nom) FROM structure_tag_liens tl JOIN structure_tags t ON t.id = tl.tag_id WHERE tl.structure_id = s.id)']);
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

    $regionsDispo = db()->query("SELECT DISTINCT departement_canton FROM structures WHERE departement_canton <> '' ORDER BY departement_canton")->fetchAll(PDO::FETCH_COLUMN);
    $tagsDispo = module_actif('booking') ? db()->query('SELECT * FROM structure_tags ORDER BY nom')->fetchAll() : [];

    render('structures_liste', [
        'vue' => $vue,
        'cartePoints' => [],
        'carteVillesManquantes' => 0,
        'structures' => $structures,
        'nbEvenements' => structures_nb_evenements(array_column($structures, 'id')),
        'recherche' => $recherche,
        'categorieId' => $categorieId,
        'pays' => $pays,
        'departementCanton' => $departementCanton,
        'tagId' => $tagId,
        'statut' => $statut,
        'lieuJaugeMin' => $lieuJaugeMin,
        'lieuJaugeMax' => $lieuJaugeMax,
        'lieuMoisEvenement' => $lieuMoisEvenement,
        'lieuMoisProg' => $lieuMoisProg,
        'flag' => $flag,
        'nonLocalises' => $nonLocalises,
        'tagBulk' => isset($_GET['tagbulk']) ? (int) $_GET['tagbulk'] : null,
        'tagBulkAction' => (string) ($_GET['tagact'] ?? ''),
        'tagBulkNom' => (string) ($_GET['tagnom'] ?? ''),
        'categoriesPourSelect' => structure_categories_pour_select(),
        'regionsDispo' => $regionsDispo,
        'tagsDispo' => $tagsDispo,
        'modeClient' => $modeClient,
        'pgRoute'   => 'structures',
        'pgParams'  => [
            'q' => $recherche, 'categorie_id' => $categorieId, 'pays' => $pays, 'departement_canton' => $departementCanton, 'tag_id' => $tagId, 'statut' => $statut,
            'lieu_jauge_min' => $lieuJaugeMin ?? '', 'lieu_jauge_max' => $lieuJaugeMax ?? '',
            'lieu_mois_evenement' => $lieuMoisEvenement ?: '', 'lieu_mois_prog' => $lieuMoisProg ?: '', 'flag' => $flag,
            'non_localises' => $nonLocalises ? 1 : '',
        ],
        'pgPage'    => $pgPage,
        'pgTaille'  => $pgTaille,
        'pgTotal'   => $pgTotal,
        'bulkCount' => isset($_GET['bulk']) ? (int) $_GET['bulk'] : null,
        'okAnnule'  => ($_GET['ok'] ?? '') === 'annule',
        'structBloquees' => isset($_GET['structBloquees']) ? (int) $_GET['structBloquees'] : 0,
    ], 'Structures');
}

// Géocode un lot de villes de structures encore manquantes (bouton de la vue
// carte, ?p=structures&vue=carte) — même principe que route_lieux_geocoder().
function route_structures_geocoder(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('structures', ['vue' => 'carte']); }
    check_csrf();
    $n = geocodage_traiter_lot(fn () => geocodage_villes_manquantes('structures', 'adresse_localite', 'departement_canton', 'adresse_pays'));
    $retour = array_intersect_key($_POST, array_flip(['q', 'categorie_id', 'pays', 'departement_canton', 'tag_id', 'statut']));
    redirect('structures', $retour + ['vue' => 'carte', 'geocode' => $n]);
}

// Données CRM de la fiche (booking) : contacts, flux de notes, tags, lieux
// liés — chargées seulement en modification (pas de sens sur une création) et
// seulement si le module est actif.
function structure_donnees_crm(int $id): array
{
    if (!$id || !module_actif('booking')) {
        return ['contacts' => [], 'contactsLies' => [], 'notes' => [], 'tags' => [], 'tagsDispo' => [], 'lieuxLies' => [], 'lieuxDispo' => [],
                'organisateurDispo' => [], 'categoriesLieu' => [], 'evenementsLies' => []];
    }
    $stmtContacts = db()->prepare('SELECT * FROM structure_contacts WHERE structure_id = ? ORDER BY actif DESC, id');
    $stmtContacts->execute([$id]);

    // Historique typé FUSIONNÉ (fiche + ses lieux, table historique migr. 52) —
    // remplace la lecture de structure_notes.
    $notesHistorique = historique_fusionne('structure', $id);

    $stmtTags = db()->prepare(
        'SELECT t.* FROM structure_tags t JOIN structure_tag_liens l ON l.tag_id = t.id
         WHERE l.structure_id = ? ORDER BY t.nom'
    );
    $stmtTags->execute([$id]);

    // Structures liées, DANS LES DEUX SENS (structure_organisateurs, table
    // auto-référencée depuis la fusion lieux→structures, migration_59/60) :
    // celles que $id organise (sens='organise'), et celle(s) qui organisent
    // $id (sens='organise_par' — $id est alors elle-même un lieu). ville/type
    // alias sur les colonnes structures pour ne pas devoir changer le
    // formulaire (views/structure_form.php).
    $stmtOrganise = db()->prepare(
        "SELECT s.id, s.nom, s.sous_categorie AS type, s.adresse_localite AS ville, 'organise' AS sens FROM structures s
         JOIN structure_organisateurs so ON so.structure_id = s.id
         WHERE so.organisateur_id = ? ORDER BY s.sous_categorie, s.nom"
    );
    $stmtOrganise->execute([$id]);
    $stmtOrganisePar = db()->prepare(
        "SELECT s.id, s.nom, s.sous_categorie AS type, s.adresse_localite AS ville, 'organise_par' AS sens FROM structures s
         JOIN structure_organisateurs so ON so.organisateur_id = s.id
         WHERE so.structure_id = ? ORDER BY s.sous_categorie, s.nom"
    );
    $stmtOrganisePar->execute([$id]);
    $lieuxLies = array_merge($stmtOrganise->fetchAll(), $stmtOrganisePar->fetchAll());

    // Contacts des structures liées (organise/organisée par), affichés en
    // complément dans la carte Contacts — utile pour retrouver d'un coup
    // d'œil qui contacter côté organisateur ET côté lieu/festival.
    $contactsLies = [];
    if ($lieuxLies) {
        $idsLies = array_column($lieuxLies, 'id');
        $in = implode(',', array_fill(0, count($idsLies), '?'));
        $stmtContactsLies = db()->prepare(
            "SELECT sc.*, s.nom AS structure_nom FROM structure_contacts sc
             JOIN structures s ON s.id = sc.structure_id
             WHERE sc.structure_id IN ($in) ORDER BY s.nom, sc.actif DESC, sc.id"
        );
        $stmtContactsLies->execute($idsLies);
        $contactsLies = $stmtContactsLies->fetchAll();
    }

    // Candidats pour « organise » : structures « booking » (un lieu). Pour
    // « organisé par » : n'importe quelle autre structure (l'organisateur
    // n'est pas forcément lui-même un lieu).
    $stmtLieuxDispo = db()->prepare(
        "SELECT s.id, s.nom, s.sous_categorie AS type, s.adresse_localite AS ville FROM structures s
         JOIN structure_categories c ON c.nom = s.sous_categorie COLLATE NOCASE
         WHERE c.est_booking = 1 AND s.id <> ? ORDER BY s.sous_categorie, s.nom"
    );
    $stmtLieuxDispo->execute([$id]);
    $stmtOrganisateurDispo = db()->prepare('SELECT id, nom FROM structures WHERE id <> ? ORDER BY nom');
    $stmtOrganisateurDispo->execute([$id]);

    // Événements de la structure ET de ses structures liées (organise/organisée
    // par) — un événement lié à la fois à $id et à une structure liée (ou à
    // deux structures liées) ne doit apparaître qu'une fois (id vu = exclu des
    // tours suivants). Les événements provenant uniquement d'une structure
    // liée portent structure_id/structure_nom/sens pour l'affichage (icône).
    $evenementsLies = structure_evenements($id);
    $vusEvenements = array_fill_keys(array_column($evenementsLies, 'id'), true);
    foreach ($lieuxLies as $l) {
        foreach (structure_evenements((int) $l['id']) as $ev) {
            if (isset($vusEvenements[$ev['id']])) {
                continue;
            }
            $vusEvenements[$ev['id']] = true;
            $ev['structure_id'] = (int) $l['id'];
            $ev['structure_nom'] = $l['nom'];
            $ev['sens'] = $l['sens'];
            $evenementsLies[] = $ev;
        }
    }
    usort($evenementsLies, fn ($a, $b) => strcmp((string) $b['date'], (string) $a['date']) ?: $b['id'] <=> $a['id']);

    return [
        'contacts'  => $stmtContacts->fetchAll(),
        'contactsLies' => $contactsLies,
        'notes'     => $notesHistorique,
        'tags'      => $stmtTags->fetchAll(),
        'tagsDispo' => db()->query('SELECT * FROM structure_tags ORDER BY nom')->fetchAll(),
        'lieuxLies' => $lieuxLies,
        'lieuxDispo' => $stmtLieuxDispo->fetchAll(),
        'organisateurDispo' => $stmtOrganisateurDispo->fetchAll(),
        'categoriesLieu' => structure_sous_categories_booking_noms(),
        'evenementsLies' => $evenementsLies,
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
        ), $id ? (string) $structureAffichee['nom'] : 'Nouvelle structure');
    };

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $categorieChamps = structure_categorie_champs((int) ($_POST['categorie_id'] ?? 0));
        if ($categorieChamps['categorie'] === '') {
            $categorieChamps = ['categorie' => structure_categorie_par_defaut(), 'sous_categorie' => ''];
        }
        // Champs communs à la création et à l'édition (carte « Informations
        // générales ») : Coordonnées/localisation en sont sorties, gérées à
        // part par route_structure_localisation() (carte « Localisation »
        // dédiée) — sauf en création, où tout reste dans un seul formulaire.
        $champs = [
            'type'             => ($_POST['type'] ?? '') === 'particulier' ? 'particulier' : 'organisation',
            'categorie'        => $categorieChamps['categorie'],
            'sous_categorie'   => $categorieChamps['sous_categorie'],
            'nom'              => trim($_POST['nom'] ?? ''),
            'site_web'         => trim($_POST['site_web'] ?? ''),
            'via'              => trim($_POST['via'] ?? ''),
            'notes'            => trim($_POST['notes'] ?? ''),
        ];
        // statut : géré à part (bloc « Statut », bascule immédiate via
        // route_structure_statut()) — jamais touché par cet enregistrement,
        // sinon toute sauvegarde de la fiche le réinitialiserait.
        // email/telephone/personne_contact : plus dans ce formulaire (remplacés par
        // la card Contacts) — colonnes conservées mais volontairement absentes des
        // requêtes ci-dessous, pour ne jamais écraser une valeur historique.
        $err = $champs['nom'] === '' ? 'Le nom est obligatoire.' : null;
        if ($id) {
            if ($err) {
                $renderForm($err, array_merge((array) $structure, $champs, ['id' => $id]));
                return;
            }
            $champs['id'] = $id;
            $sqlSet = 'type=:type, categorie=:categorie, sous_categorie=:sous_categorie, nom=:nom, site_web=:site_web, via=:via, notes=:notes';
            $diffChamps = [
                'nom' => 'Nom', 'categorie' => 'Catégorie', 'sous_categorie' => 'Sous-catégorie',
                'site_web' => 'Site web', 'via' => 'Via', 'notes' => 'Remarques',
            ];
            // Sans accès en lecture au module booking (mêmes conditions que
            // $avecAside, views/structure_form.php), pas de carte
            // « Localisation » séparée : les coordonnées restent dans ce même
            // formulaire, comme avant route_structure_localisation().
            if (!(module_actif('booking') && peut_lire('booking'))) {
                $champs['adresse_rue']        = trim($_POST['adresse_rue'] ?? '');
                $champs['adresse_npa']        = trim($_POST['adresse_npa'] ?? '');
                $champs['adresse_localite']   = trim($_POST['adresse_localite'] ?? '');
                $champs['adresse_pays']       = trim($_POST['adresse_pays'] ?? '') ?: 'Suisse';
                $champs['departement_canton'] = trim($_POST['departement_canton'] ?? '');
                $champs['grande_region']      = trim($_POST['grande_region'] ?? '');
                $grandeRegionDeduite = grande_region_deduite($champs['adresse_pays'], $champs['departement_canton']);
                if ($grandeRegionDeduite !== null) {
                    $champs['grande_region'] = $grandeRegionDeduite;
                }
                pays_region_assurer($champs['adresse_pays'], $champs['grande_region']);
                $sqlSet .= ', adresse_rue=:adresse_rue, adresse_npa=:adresse_npa, adresse_localite=:adresse_localite,
                             adresse_pays=:adresse_pays, departement_canton=:departement_canton, grande_region=:grande_region';
                $diffChamps += [
                    'adresse_rue' => 'Rue', 'adresse_npa' => 'NPA', 'adresse_localite' => 'Localité',
                    'adresse_pays' => 'Pays', 'departement_canton' => 'Département / canton', 'grande_region' => 'Région',
                ];
            }
            db()->prepare('UPDATE structures SET ' . $sqlSet . ' WHERE id=:id')->execute($champs);
            // Historique : diff des champs modifiés (module booking).
            if (module_actif('booking')) {
                journaliser_diff('structure', $id, (array) $structure, $champs, $diffChamps);
            }
            redirect('structure', ['id' => $id]);
        } else {
            // Création : formulaire unique, coordonnées incluses (pas de carte
            // « Localisation » séparée tant que la structure n'existe pas).
            $champs['adresse_rue']         = trim($_POST['adresse_rue'] ?? '');
            $champs['adresse_npa']         = trim($_POST['adresse_npa'] ?? '');
            $champs['adresse_localite']    = trim($_POST['adresse_localite'] ?? '');
            $champs['adresse_pays']        = trim($_POST['adresse_pays'] ?? '') ?: 'Suisse';
            $champs['departement_canton']  = trim($_POST['departement_canton'] ?? '');
            $champs['grande_region']       = trim($_POST['grande_region'] ?? '');
            // Grande région déduite du département/canton quand c'est possible
            // (France/Suisse hors cantons bilingues) : jamais laissée à la
            // saisie manuelle dans ce cas, quoi que le formulaire ait envoyé.
            $grandeRegionDeduite = grande_region_deduite($champs['adresse_pays'], $champs['departement_canton']);
            if ($grandeRegionDeduite !== null) {
                $champs['grande_region'] = $grandeRegionDeduite;
            }
            if ($err) {
                $renderForm($err, array_merge((array) $structure, $champs, ['id' => $id]));
                return;
            }
            pays_region_assurer($champs['adresse_pays'], $champs['grande_region']);
            // Statut « actif » par défaut.
            db()->prepare("INSERT INTO structures (type, categorie, sous_categorie, nom, adresse_rue, adresse_npa, adresse_localite, adresse_pays,
                            departement_canton, grande_region, site_web, via, notes, statut)
                            VALUES (:type, :categorie, :sous_categorie, :nom, :adresse_rue, :adresse_npa, :adresse_localite, :adresse_pays,
                            :departement_canton, :grande_region, :site_web, :via, :notes, 'actif')")
                ->execute($champs);
        }
        redirect('structures');
    }
    $renderForm(($_GET['err'] ?? '') === 'used' ? 'Suppression impossible : des factures sont rattachées à cette structure.' : null, (array) $structure);
}

// Carte « Localisation » (?p=structure, édition) — adresse postale complète
// (rue/NPA/localité) + département-canton/région/pays, sauvegardée à part
// de la carte « Informations générales » (route_structure() ci-dessus).
function route_structure_localisation(): void
{
    require_login();
    $id = (int) ($_POST['id'] ?? 0);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$id) {
        redirect('structures');
    }
    $stmt = db()->prepare('SELECT * FROM structures WHERE id = ?');
    $stmt->execute([$id]);
    $structureAvant = $stmt->fetch();
    if (!$structureAvant) {
        redirect('structures');
    }
    check_csrf();
    $champs = [
        'adresse_rue'        => trim($_POST['adresse_rue'] ?? ''),
        'adresse_npa'        => trim($_POST['adresse_npa'] ?? ''),
        'adresse_localite'   => trim($_POST['adresse_localite'] ?? ''),
        'adresse_pays'       => trim($_POST['adresse_pays'] ?? '') ?: 'Suisse',
        'departement_canton' => trim($_POST['departement_canton'] ?? ''),
        'grande_region'      => trim($_POST['grande_region'] ?? ''),
    ];
    $grandeRegionDeduite = grande_region_deduite($champs['adresse_pays'], $champs['departement_canton']);
    if ($grandeRegionDeduite !== null) {
        $champs['grande_region'] = $grandeRegionDeduite;
    }
    pays_region_assurer($champs['adresse_pays'], $champs['grande_region']);
    $champs['id'] = $id;
    db()->prepare('UPDATE structures SET adresse_rue=:adresse_rue, adresse_npa=:adresse_npa, adresse_localite=:adresse_localite,
                    adresse_pays=:adresse_pays, departement_canton=:departement_canton, grande_region=:grande_region
                    WHERE id=:id')->execute($champs);
    if (module_actif('booking')) {
        journaliser_diff('structure', $id, $structureAvant, $champs, [
            'adresse_rue' => 'Rue', 'adresse_npa' => 'NPA', 'adresse_localite' => 'Localité',
            'adresse_pays' => 'Pays', 'departement_canton' => 'Département / canton', 'grande_region' => 'Région',
        ]);
    }
    redirect('structure', ['id' => $id, 'ok' => 'localisation']);
}

// Carte « Période » (?p=structure, édition) — mois de début/fin de
// réalisation (mois_evenement_debut/fin) et de préparation (mois_debut/fin),
// sauvegardée à part de la carte « Informations générales ». Pas de colonne
// dédiée pour la case « Toute l'année » (structure_form.php) : son état est
// entièrement dérivé des 4 champs (tous vides = toute l'année) — le JS vide
// les <select> quand elle est cochée avant soumission, donc il suffit ici
// d'enregistrer ce qui est posté, vide ou non (NULL).
function route_structure_periode(): void
{
    require_login();
    $id = (int) ($_POST['id'] ?? 0);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$id) {
        redirect('structures');
    }
    $stmt = db()->prepare('SELECT * FROM structures WHERE id = ?');
    $stmt->execute([$id]);
    $structureAvant = $stmt->fetch();
    if (!$structureAvant) {
        redirect('structures');
    }
    check_csrf();
    $moisValide = function (string $champ): ?int {
        $v = trim((string) ($_POST[$champ] ?? ''));
        return ($v !== '' && (int) $v >= 1 && (int) $v <= 12) ? (int) $v : null;
    };
    $champs = [
        'mois_evenement_debut' => $moisValide('mois_evenement_debut'),
        'mois_evenement_fin'   => $moisValide('mois_evenement_fin'),
        'mois_debut'           => $moisValide('mois_debut'),
        'mois_fin'             => $moisValide('mois_fin'),
        'id' => $id,
    ];
    db()->prepare('UPDATE structures SET mois_evenement_debut=:mois_evenement_debut, mois_evenement_fin=:mois_evenement_fin,
                    mois_debut=:mois_debut, mois_fin=:mois_fin WHERE id=:id')->execute($champs);
    if (module_actif('booking')) {
        journaliser_diff('structure', $id, $structureAvant, $champs, [
            'mois_evenement_debut' => 'Début de réalisation', 'mois_evenement_fin' => 'Fin de réalisation',
            'mois_debut' => 'Début de préparation', 'mois_fin' => 'Fin de préparation',
        ]);
    }
    redirect('structure', ['id' => $id, 'ok' => 'periode']);
}

// Bascule immédiate du statut d'une structure (structures.statut, voir
// STRUCTURE_STATUTS/lib/booking.php) depuis le bloc Statut — sélecteur
// segmenté, valeur cliquée directement (structure_statut_toggle_html(),
// lib/helpers.php). Appelé en AJAX (lassoInitStatutToggle(), assets/app.js) :
// répond en JSON, pas de redirect (même mécanique que route_structure_flag()).
function route_structure_statut(): void
{
    require_login();
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['ok' => false]);
        return;
    }
    check_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $etatSuivant = (string) ($_POST['etat'] ?? '');
    if (!in_array($etatSuivant, STRUCTURE_STATUTS, true)) {
        echo json_encode(['ok' => false]);
        return;
    }
    $stmt = db()->prepare('SELECT statut FROM structures WHERE id = ?');
    $stmt->execute([$id]);
    $avant = $stmt->fetchColumn();
    if ($avant === false) {
        echo json_encode(['ok' => false]);
        return;
    }
    db()->prepare('UPDATE structures SET statut = ? WHERE id = ?')->execute([$etatSuivant, $id]);
    if (module_actif('booking') && (string) $avant !== $etatSuivant) {
        journaliser('structure', $id, 'edition', 'Statut : ' . structure_statut_libelle($etatSuivant));
    }
    echo json_encode(['ok' => true, 'etat' => $etatSuivant]);
}

// Bascule le marquage rapide (flag) d'une structure — aucun → étoile → cœur →
// aucun (voir flag_toggle_html(), lib/helpers.php). Appelé en AJAX depuis
// lassoInitFlagToggle() (assets/app.js) : répond en JSON, pas de redirect.
function route_structure_flag(): void
{
    require_login();
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['ok' => false]);
        return;
    }
    check_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $stmt = db()->prepare('SELECT flag FROM structures WHERE id = ?');
    $stmt->execute([$id]);
    $actuel = $stmt->fetchColumn();
    if ($actuel === false) {
        echo json_encode(['ok' => false]);
        return;
    }
    // Cœur temporairement désactivé (cycle à 2 états) : réactiver la ligne
    // 'star' => 'heart' pour reprendre le cycle à 3 états aucun/étoile/cœur.
    $suivant = match ((string) $actuel) {
        ''      => 'star',
        // 'star'  => 'heart',
        default => '',
    };
    db()->prepare('UPDATE structures SET flag = ? WHERE id = ?')->execute([$suivant, $id]);
    echo json_encode(['ok' => true, 'flag' => $suivant]);
}

function route_structure_renommer(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('structures'); }
    check_csrf();
    $id  = (int) ($_POST['id'] ?? 0);
    $nom = trim($_POST['nom'] ?? '');
    if ($id && $nom !== '') {
        $ancien = nom_entite('structures', $id);
        db()->prepare('UPDATE structures SET nom = ? WHERE id = ?')->execute([$nom, $id]);
        if (module_actif('booking') && $ancien !== $nom) {
            journaliser('structure', $id, 'edition', 'Nom : ' . ($ancien !== '' ? $ancien : '(vide)') . ' → ' . $nom);
        }
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
                (SELECT COUNT(*) FROM historique WHERE entite_type = 'structure' AND entite_id = s.id) AS nb_notes,
                (SELECT COUNT(*) FROM factures WHERE structure_id = s.id) AS nb_factures,
                (SELECT COUNT(*) FROM structure_tag_liens WHERE structure_id = s.id) AS nb_tags,
                (SELECT COUNT(*) FROM structure_organisateurs WHERE organisateur_id = s.id) AS nb_lieux
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
// qui tague leur sous-catégorie « booking » et crée le lien structure_organisateurs —
// leurs contacts/notes/factures/étiquettes RESTENT sur elles, aucune fusion/
// suppression depuis la fusion lieux→structures). Type du lieu : déduit du nom
// (« festival » → festival, sinon salle) ou imposé globalement.
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
        // 'deduire' = déduire du nom ; sinon une sous-catégorie booking explicite.
        $typeBrut = trim((string) ($_POST['type'] ?? ''));
        $typeChoix = $typeBrut === 'deduire' ? 'deduire' : structure_sous_categorie_booking_nom_pour($typeBrut);
        if ($typeChoix === '') { $typeChoix = 'deduire'; }
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
                (SELECT COUNT(*) FROM historique WHERE entite_type = 'structure' AND entite_id = s.id) AS nb_notes,
                (SELECT COUNT(*) FROM factures WHERE structure_id = s.id) AS nb_factures
         FROM structures s WHERE s.id IN ($in) ORDER BY s.nom"
    );
    $stmt->execute($ids);
    $candidats = $stmt->fetchAll();
    if (count($candidats) < 2) {
        unset($_SESSION['transformer_ids']);
        redirect('structures');
    }

    render('structure_transformer', ['candidats' => $candidats, 'categoriesLieu' => structure_sous_categories_booking_noms()], 'Transformer en salles/festivals');
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
