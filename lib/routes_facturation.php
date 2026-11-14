<?php
// Handlers de routes du module facturation (préfixes « facturation_ »/« facture_ »).
// Inclus depuis index.php après lib/routes.php. S'appuie sur lib/facturation.php.
// Les structures (débiteurs, salles, bailleurs…) ne sont pas ici : elles
// appartiennent au carnet d'adresses, lib/routes_carnet.php.

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
                                   -- Contact « administration » d'abord, sinon le
                                   -- premier qui a une adresse : même règle que
                                   -- la liste des structures et l'export SUISA.
                                   COALESCE(
                                       (SELECT pe.email FROM personne_structures cs JOIN personnes pe ON pe.id = cs.personne_id WHERE cs.structure_id = d.id AND cs.est_administration = 1 AND pe.email <> '' LIMIT 1),
                                       (SELECT pe.email FROM personne_structures cs JOIN personnes pe ON pe.id = cs.personne_id WHERE cs.structure_id = d.id AND pe.email <> '' ORDER BY cs.id LIMIT 1)
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

function route_factures(): void
{
    require_login();
    // Filtres de colonne (EXPÉRIMENTAL — même mécanique que ?p=fiches, voir
    // filtre_coche() dans lib/helpers.php) : Émission (ex-Année) et Paiement
    // (ex-Statut), à la place des 2 anciens <select> de la toolbar.
    $statut = filtre_coche('statut', 'facturation_statut', array_merge(FACTURATION_STATUTS, ['en_retard']));
    $annees = array_map('intval', db()->query(
        "SELECT DISTINCT strftime('%Y', COALESCE(NULLIF(date_emission,''), cree_le)) FROM factures ORDER BY 1 DESC"
    )->fetchAll(PDO::FETCH_COLUMN));
    $annee = filtre_coche('annee', 'facturation_annee');

    $avecEvenements = module_actif('evenements');
    $from = ' FROM factures f JOIN structures d ON d.id = f.structure_id';
    if ($avecEvenements) {
        $from .= ' LEFT JOIN evenements ev ON ev.id = f.evenement_id LEFT JOIN projets sp ON sp.id = ev.projet_id';
    }
    $where = ' WHERE 1=1';
    $params = [];
    if ($annee) {
        $where .= " AND strftime('%Y', COALESCE(NULLIF(f.date_emission,''), f.cree_le)) IN (" . sql_in($annee) . ')';
        $params = array_merge($params, array_map('strval', $annee));
    }
    if ($statut) {
        // "En retard" (dérivé) + statuts réels unis en OR — mêmes valeurs
        // possibles que FACTURATION_STATUTS, jamais exclusives entre elles.
        $statutConds  = [];
        $statutParams = [];
        if (in_array('en_retard', $statut, true)) {
            $statutConds[]  = facturation_sql_en_retard('f.');
            $statutParams[] = date('Y-m-d');
        }
        $reels = array_values(array_intersect($statut, FACTURATION_STATUTS));
        if ($reels) {
            $statutConds[] = 'f.statut IN (' . sql_in($reels) . ')';
            $statutParams  = array_merge($statutParams, $reels);
        }
        if ($statutConds) {
            $where .= ' AND (' . implode(' OR ', $statutConds) . ')';
            $params = array_merge($params, $statutParams);
        }
    }
    $recherche = trim((string) ($_GET['q'] ?? ''));

    // Total avec les seuls filtres structurés (hors recherche texte) : décide du
    // mode client vs serveur, voir pagination_mode_client() dans lib/helpers.php.
    $stmtTotStruct = db()->prepare('SELECT COUNT(*)' . $from . $where);
    $stmtTotStruct->execute($params);
    $totalSansRecherche = (int) $stmtTotStruct->fetchColumn();
    $modeClient = pagination_mode_client($totalSansRecherche);

    $pgTaille = pagination_taille('facturation_taille');
    $selectCols = 'f.*, d.nom AS structure_nom' . ($avecEvenements ? ', ev.date AS evenement_date, sp.nom AS projet_nom' : '');
    // Tri de colonne. L'ordre naturel — la plus récemment émise d'abord —
    // reste celui de l'arrivée sur la page. Le numéro se trie en TEXTE : il est
    // de la forme « 2026-014 », un tri alphabétique y est chronologique.
    $tri = tri_colonne('factures', [
        'numero'    => 'f.numero COLLATE NOCASE',
        'structure' => 'd.nom COLLATE NOCASE',
        'emission'  => "COALESCE(NULLIF(f.date_emission, ''), f.cree_le)",
        'echeance'  => 'f.date_echeance',
        'montant'   => 'f.montant_total',
        // La colonne s'appelle payee_le (une facture n'a pas de
        // « date_paiement », c'est la fiche de salaire qui en a une) : le tri
        // la nommait mal et tombait en erreur. Vu par tests/pages_test.php.
        'paiement'  => "COALESCE(NULLIF(f.payee_le, ''), '')",
    ]);
    $orderBy = $tri['sql'] !== ''
        ? $tri['sql'] . ', f.id DESC'
        : ' ORDER BY COALESCE(NULLIF(f.date_emission,\'\'), f.cree_le) DESC, f.id DESC';

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

    // Vue deux colonnes : la facture choisie (?sel=), à défaut la première de
    // la liste. Mêmes données que la page pleine — facture_donnees_vue() —
    // pour que le volet droit montre la vraie fiche et non un résumé.
    $vue = vue_liste('factures_vue');
    $duo = duo_selection($vue, $factures, 'facture_donnees_vue');

    render('factures', array_merge($duo, [
        'vue'            => $vue,
        'duoSel'         => $duo['duoSel'],
        'factures'       => $factures,
        'statut'         => $statut,
        'annee'          => $annee,
        'annees'         => $annees ?: [(int) date('Y')],
        'avecEvenements' => $avecEvenements,
        'recherche'      => $recherche,
        'modeClient'     => $modeClient,
        'tri'            => $tri,
        'pgRoute'        => 'factures',
        'pgParams'       => array_filter(['statut' => $statut, 'annee' => $annee, 'q' => $recherche]),
        'pgPage'         => $pgPage,
        'pgTaille'       => $pgTaille,
        'pgTotal'        => $pgTotal,
    ]), 'Facturation — Factures');
}

// Formulaire (brouillon) — création ou modification, tant que non émise.
function route_facture_form(): void
{
    require_login();
    $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
    $facture = $id ? facturation_charger($id) : null;
    if ($id && !$facture) {
        redirect('factures');
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
    // Facture créée depuis un dossier de subvention (bouton « Créer » de sa
    // fiche, module Recherche de fonds). À la création, l'identifiant du
    // dossier est porté par l'URL ; en modification, c'est le dossier qui
    // garde le lien (fonds_demandes.facture_id) et on le retrouve par là.
    // Ne sert qu'à l'affichage et aux valeurs de départ : sans droit de
    // LECTURE sur le module, le lien reste posé en base mais ne se montre pas.
    $fondsDemandeId = null;
    $fondsDemande = null;
    if (module_accessible('fonds')) {
        $fondsDemandeId = ($_GET['fonds_demande_id'] ?? $_POST['fonds_demande_id'] ?? '') !== ''
            ? (int) ($_GET['fonds_demande_id'] ?? $_POST['fonds_demande_id'])
            : 0;
        if (!$fondsDemandeId && $id) {
            $stmtFd = db()->prepare('SELECT id FROM fonds_demandes WHERE facture_id = ?');
            $stmtFd->execute([$id]);
            $fondsDemandeId = (int) $stmtFd->fetchColumn();
        }
        // Même précaution que pour l'événement : un lien resté ouvert vers un
        // dossier supprimé depuis s'ignore, il ne fait pas échouer la saisie.
        $fondsDemande = $fondsDemandeId ? fonds_demande_charger($fondsDemandeId) : null;
        $fondsDemandeId = $fondsDemande ? $fondsDemandeId : null;
    }

    // Valeurs de départ d'une facture créée DEPUIS AILLEURS — une date du
    // booking, un dossier de subvention. Elles ne touchent qu'une facture
    // nouvelle : sur une facture déjà enregistrée, elles ne doivent rien
    // écraser, d'où le « !$facture » qui les garde.
    //   $axeDefaut       axe présélectionné sur la première ligne ;
    //   $structureDefaut destinataire présélectionné ;
    //   $ligneDefaut     ['description', 'prix'] de la première ligne — seul un
    //                    dossier de subvention en pose une : son montant est
    //                    connu d'avance, pas celui d'un cachet.
    $axeDefaut = null;
    $structureDefaut = null;
    $ligneDefaut = null;
    if ($evenementId && !$facture) {
        // L'axe vient du PROJET de la date, plus de la date elle-même
        // (migration_91) : toutes les dates d'une création se ventilent au même
        // endroit, et c'est le projet qui le sait.
        $stmt = db()->prepare('SELECT s.axe_analytique_id, e.organisateur_structure_id FROM evenements e
                                LEFT JOIN projets s ON s.id = e.projet_id WHERE e.id = ?');
        $stmt->execute([$evenementId]);
        $evRow = $stmt->fetch();
        $axeDefaut = $axes ? ((int) ($evRow['axe_analytique_id'] ?? 0) ?: null) : null;
        $structureDefaut = (int) ($evRow['organisateur_structure_id'] ?? 0) ?: null;
    } elseif ($fondsDemande && !$facture) {
        $def = fonds_facture_defauts($fondsDemande);
        $axeDefaut = $axes ? $def['axe_analytique_id'] : null;
        $structureDefaut = $def['structure_id'];
        $ligneDefaut = ['description' => $def['description'], 'prix' => $def['montant']];
    }

    $renderForm = function (?string $err) use (
        $facture, $id, $structures, $comptes, $axes, $delaiDefaut, $evenementId,
        $axeDefaut, $structureDefaut, $ligneDefaut, $fondsDemandeId, $fondsDemande
    ) {
        render('facture_form', [
            'facture' => $facture, 'id' => $id, 'structures' => $structures, 'comptes' => $comptes, 'axes' => $axes,
            'delaiDefaut' => $delaiDefaut, 'evenementId' => $evenementId, 'axeDefaut' => $axeDefaut,
            'structureDefaut' => $structureDefaut, 'ligneDefaut' => $ligneDefaut,
            'fondsDemandeId' => $fondsDemandeId, 'fondsDemande' => $fondsDemande,
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
    // Le dossier de subvention garde le lien de son côté. Il est posé à
    // l'ENREGISTREMENT et pas à l'ouverture du formulaire, pour qu'une facture
    // abandonnée en cours de saisie ne laisse aucune trace — et APRÈS le bloc
    // ci-dessus, dont le message d'erreur ne parle que de l'événement lié.
    // Écrire sur le dossier demande le droit d'écriture sur son module.
    if ($fondsDemandeId && peut_ecrire('fonds')) {
        fonds_facture_lier($fondsDemandeId, $factureId);
    }
    redirect('facture', ['id' => $factureId]);
}

// Les données d'une facture pour sa fiche — la page pleine (route_facture())
// comme le volet droit de la vue deux colonnes (route_factures(), ?vue=duo).
// Rendre null quand elle n'existe pas : à l'appelant de décider s'il redirige
// ou s'il affiche sa liste sans fiche.
function facture_donnees_vue(int $id): ?array
{
    $facture = facturation_charger($id);
    if (!$facture) {
        return null;
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
    return [
        'facture' => $facture,
        'lignes'  => facturation_lignes_de($id),
        'statutEffectif' => facturation_statut_effectif($facture),
        'ecrituresLibres' => $ecrituresLibres,
        'evenementsListe' => $evenementsListe,
        // Axes actifs : la colonne « Axe » du tableau des lignes est modifiable
        // même sur une facture émise (voir route_facture_ligne_axe_enregistrer()).
        'axes' => module_actif('analytique')
            ? db()->query('SELECT * FROM axes_analytiques WHERE actif = 1 ORDER BY ordre, id')->fetchAll()
            : [],
        'saved'   => $_GET['ok'] ?? null,
    ];
}

function route_facture(): void
{
    require_login();
    $donnees = facture_donnees_vue((int) ($_GET['id'] ?? 0));
    if ($donnees === null) {
        redirect('factures');
    }
    render('facture', $donnees, 'Facture ' . ($donnees['facture']['numero'] ?: '(brouillon)'));
}

// Marquage manuel « payée » : pour les cas où le rapprochement automatique
// (au moment de l'import bancaire) n'a pas trouvé de correspondance, alors
// que l'utilisateur voit l'écriture correspondante dans Écritures. $ecriture_id
// optionnel : lie l'écriture choisie (si encore libre, ou déjà liée à cette
// facture) en plus de marquer payée. Rejouable tant que la facture est déjà
// « payée » : permet de corriger la date ou l'écriture liée après coup.
function route_facture_paiement(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('factures');
    }
    check_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $facture = facturation_charger($id);
    if (!$facture || !in_array($facture['statut'], ['emise', 'payee'], true)) {
        redirect('factures');
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
        redirect('factures');
    }
    check_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $facture = facturation_charger($id);
    if (!$facture || $facture['statut'] !== 'brouillon') {
        redirect('factures');
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
        redirect('factures');
    }
    check_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $facture = facturation_charger($id);
    if ($facture && $facture['statut'] !== 'payee' && $facture['statut'] !== 'annulee') {
        db()->prepare("UPDATE factures SET statut='annulee' WHERE id=?")->execute([$id]);
    }
    redirect('facture', ['id' => $id]);
}

function route_facture_supprimer(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('factures');
    }
    check_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    db()->prepare("DELETE FROM factures WHERE id = ? AND statut = 'brouillon'")->execute([$id]);
    redirect('factures');
}

// Axe analytique d'UNE ligne de facture, modifiable quel que soit le statut —
// y compris sur une facture déjà émise. L'axe ne figure pas sur le document
// envoyé au débiteur : c'est une donnée de comptabilité analytique, qu'on
// affine souvent après coup, une fois le rattachement au projet tranché. Les
// montants, eux, restent figés dès l'émission (route_facture_form()).
function route_facture_ligne_axe_enregistrer(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !module_actif('analytique')) {
        redirect('factures');
    }
    check_csrf();
    $factureId = (int) ($_POST['facture_id'] ?? 0);
    $ligneId   = (int) ($_POST['ligne_id'] ?? 0);
    $axeBrut   = trim((string) ($_POST['axe_analytique_id'] ?? ''));
    // Axe vide = aucun. Sinon il doit exister ET être actif : un axe archivé ne
    // se choisit plus, même en forgeant la requête.
    $axeId = null;
    if ($axeBrut !== '') {
        $stmt = db()->prepare('SELECT id FROM axes_analytiques WHERE id = ? AND actif = 1');
        $stmt->execute([(int) $axeBrut]);
        $axeId = $stmt->fetchColumn();
        if ($axeId === false) {
            redirect('facture', ['id' => $factureId]);
        }
        $axeId = (int) $axeId;
    }
    // La ligne doit appartenir à CETTE facture : un identifiant posté ne le
    // prouve pas.
    db()->prepare('UPDATE facture_lignes SET axe_analytique_id = ? WHERE id = ? AND facture_id = ?')
        ->execute([$axeId, $ligneId, $factureId]);
    // Envoi parti en arrière-plan (assets/app.js, data-ajax) : rien à re-rendre,
    // le menu porte déjà l'état choisi, et la facture n'affiche aucun total par
    // axe qui dépendrait de lui. La recharger entière renvoyait en haut de page.
    if (($_POST['retour'] ?? '') === 'json') {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        return;
    }
    redirect('facture', ['id' => $factureId, 'ok' => 'axe']);
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

function route_facture_exporter_pdf(): void
{
    require_login();
    $id = (int) ($_GET['id'] ?? 0);
    $facture = facturation_charger($id);
    if (!$facture || $facture['statut'] === 'brouillon') {
        redirect('factures');
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

function route_facture_envoyer(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('factures');
    }
    check_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $facture = facturation_charger($id);
    if (!$facture || $facture['statut'] === 'brouillon') {
        redirect('factures');
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
function route_facture_rappel_imprimer(): void
{
    require_login();
    $id = (int) ($_GET['id'] ?? 0);
    $facture = facturation_charger($id);
    if (!$facture || $facture['statut'] === 'brouillon') {
        redirect('factures');
    }
    render_bare('facture_rappel_imprimer', ['facture' => $facture]);
}

// --- Import de factures historiques (JSON) ----------------------------------
function route_factures_importer(): void
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
    rendre_import([
        'errFactures' => $err, 'resultatsFactures' => $resultats, 'resumeFactures' => $resume, 'simuleFactures' => $simule,
    ]);
}
