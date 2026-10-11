<?php
// Routes des pièces comptables (SPEC_PIECES.md).
//
// Le module vit à côté de la comptabilité et non dedans : c'est ce qui permet
// de donner le dépôt sans la lecture des comptes. Les routes qui touchent une
// écriture (le rapprochement) exigent donc EN PLUS la lecture de « compta » —
// le dispatcher ne garde que le module déclaré, pas le second.

// La liste. Même forme que ?p=factures : filtres de colonne mémorisés en
// session, recherche instantanée sous le seuil de pagination client, tri.
function route_pieces(): void
{
    require_login();

    $etat = filtre_coche('etat', 'pieces_etat', array_keys(PIECE_ETATS));
    $type = filtre_coche('type', 'pieces_type', array_keys(PIECE_TYPES));
    $annees = array_map('intval', db()->query(
        "SELECT DISTINCT strftime('%Y', COALESCE(NULLIF(date_piece,''), cree_le)) FROM pieces ORDER BY 1 DESC"
    )->fetchAll(PDO::FETCH_COLUMN));
    $annee = filtre_coche('annee', 'pieces_annee');

    $from   = ' FROM pieces p LEFT JOIN plan_comptes pc ON pc.id = p.plan_compte_id';
    $where  = ' WHERE 1=1';
    $params = [];
    if ($annee) {
        $where .= " AND strftime('%Y', COALESCE(NULLIF(p.date_piece,''), p.cree_le)) IN (" . sql_in($annee) . ')';
        $params = array_merge($params, array_map('strval', $annee));
    }
    if ($type) {
        $where .= ' AND p.type IN (' . sql_in($type) . ')';
        $params = array_merge($params, $type);
    }
    if ($etat) {
        // Les trois états se partagent toute la liste et ne sont jamais
        // exclusifs entre eux : unis en OR, en cocher deux revient à montrer
        // les deux. La condition vient de piece_etat_sql(), la même qui sert
        // au compteur de la pastille.
        $where .= ' AND (' . implode(' OR ', array_map(fn ($e) => '(' . piece_etat_sql($e) . ')', $etat)) . ')';
    }
    $recherche = trim((string) ($_GET['q'] ?? ''));

    $stmtTotStruct = db()->prepare('SELECT COUNT(*)' . $from . $where);
    $stmtTotStruct->execute($params);
    $totalSansRecherche = (int) $stmtTotStruct->fetchColumn();
    $modeClient = pagination_mode_client($totalSansRecherche);

    $pgTaille = pagination_taille('pieces_taille');
    $selectCols = 'p.*, pc.libelle AS categorie_nom';
    $tri = tri_colonne('pieces', [
        'libelle'  => 'p.libelle COLLATE NOCASE',
        'tiers'    => 'p.tiers COLLATE NOCASE',
        'date'     => "COALESCE(NULLIF(p.date_piece,''), p.cree_le)",
        'echeance' => 'p.date_echeance',
        'montant'  => 'p.montant',
    ]);
    // L'ordre naturel : la plus récente d'abord, comme toutes les listes
    // datées de l'application.
    $orderBy = $tri['sql'] !== ''
        ? $tri['sql'] . ', p.id DESC'
        : " ORDER BY COALESCE(NULLIF(p.date_piece,''), p.cree_le) DESC, p.id DESC";

    if ($modeClient) {
        $stmt = db()->prepare('SELECT ' . $selectCols . $from . $where . $orderBy);
        $stmt->execute($params);
        $pieces  = $stmt->fetchAll();
        $pgPage  = 1;
        $pgTotal = $totalSansRecherche;
    } else {
        [$rechSql, $rechParams] = recherche_sql(['p.libelle', 'p.tiers', 'p.numero', 'CAST(p.montant AS TEXT)']);
        $where .= $rechSql;
        $params = array_merge($params, $rechParams);

        $stmtTot = db()->prepare('SELECT COUNT(*)' . $from . $where);
        $stmtTot->execute($params);
        $pgTotal = (int) $stmtTot->fetchColumn();

        $pgPage = pagination_page();
        [$limitSql, $limitParams] = pagination_sql($pgPage, $pgTaille);
        $stmt = db()->prepare('SELECT ' . $selectCols . $from . $where . $orderBy . $limitSql);
        $stmt->execute(array_merge($params, $limitParams));
        $pieces = $stmt->fetchAll();
    }

    render('pieces', [
        'pieces'     => $pieces,
        'etat'       => $etat,
        'type'       => $type,
        'annee'      => $annee,
        'annees'     => $annees ?: [(int) date('Y')],
        'recherche'  => $recherche,
        'modeClient' => $modeClient,
        'tri'        => $tri,
        'pgRoute'    => 'pieces',
        'pgParams'   => array_filter(['etat' => $etat, 'type' => $type, 'annee' => $annee, 'q' => $recherche]),
        'pgPage'     => $pgPage,
        'pgTaille'   => $pgTaille,
        'pgTotal'    => $pgTotal,
    ], 'Pièces comptables');
}

// La fiche d'une pièce : ce qu'elle dit, son fichier, et l'écriture qui la
// solde — ou la liste de celles auxquelles elle pourrait se rattacher.
function route_piece(): void
{
    require_login();
    $id = (int) ($_GET['id'] ?? 0);
    $piece = $id ? piece_charger($id) : null;
    if (!$piece) {
        redirect('pieces');
    }

    $ecriture = null;
    if (!empty($piece['ecriture_id']) && module_accessible('compta')) {
        $stmt = db()->prepare(
            'SELECT e.*, c.libelle AS compte_libelle FROM ecritures e
               JOIN comptes_bancaires c ON c.id = e.compte_bancaire_id WHERE e.id = ?'
        );
        $stmt->execute([(int) $piece['ecriture_id']]);
        $ecriture = $stmt->fetch() ?: null;
    }
    // Les candidates ne se calculent que si on peut les voir : sans lecture sur
    // la comptabilité, la fiche montre la pièce et rien du compte.
    $candidates = (module_accessible('compta') && peut_ecrire('pieces') && piece_etat($piece) !== 'classee')
        ? pieces_ecritures_rapprochables($piece)
        : [];

    render('piece', [
        'p'          => $piece,
        'ecriture'   => $ecriture,
        'candidates' => $candidates,
        'ok'         => $_GET['ok'] ?? null,
        'err'        => (string) ($_GET['err'] ?? ''),
    ], (string) ($piece['libelle'] ?: 'Pièce'));
}

// Création et modification. GET rend le formulaire, POST l'enregistre — la
// route porte les deux, comme route_facture_form().
function route_piece_form(): void
{
    require_login();
    $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
    $piece = $id ? piece_charger($id) : null;
    if ($id && !$piece) {
        redirect('pieces');
    }

    // Arrivée depuis la colonne « Pièce » d'une écriture : le justificatif
    // manque, on le dépose depuis là. L'écriture donne ses valeurs de départ —
    // date, montant, contre-partie, catégorie — et le rattachement se fait à
    // l'enregistrement : la saisie se réduit au fichier et à l'intitulé.
    $ecrSource = null;
    $ecrId = (int) ($_GET['ecriture_id'] ?? $_POST['ecriture_id'] ?? 0);
    if ($ecrId && !$piece && module_accessible('compta')) {
        $stmt = db()->prepare('SELECT * FROM ecritures WHERE id = ?');
        $stmt->execute([$ecrId]);
        $ecrSource = $stmt->fetch() ?: null;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $champs = piece_champs_postes();
        if ($champs['libelle'] === '') {
            $champs['libelle'] = $champs['tiers'] !== '' ? $champs['tiers'] : 'Pièce sans intitulé';
        }
        try {
            // Deux chemins d'arrivée pour un fichier : le champ classique, et
            // la photo réduite par le navigateur (data URI). Le second est
            // revalidé exactement comme le premier — c'est le procédé de
            // l'avatar, et le serveur ne fait confiance à aucun des deux.
            $fichier = fichier_prive_enregistrer('fichier', 'piece')
                ?? piece_photo_enregistrer('photo');
        } catch (RuntimeException $e) {
            render('piece_form', piece_form_data($piece, $champs, $e->getMessage(), $ecrSource), 'Pièce');
            return;
        }

        if ($piece) {
            $sql = 'UPDATE pieces SET type=?, libelle=?, tiers=?, montant=?, date_piece=?, date_echeance=?,
                    date_payee=?, mode_paiement=?, plan_compte_id=?, notes=?';
            $args = array_values($champs);
            if ($fichier) {
                // Le fichier remplacé s'en va : rien ne le désignerait plus.
                fichier_prive_supprimer((string) $piece['fichier']);
                $sql .= ', fichier=?, nom_origine=?, mime=?, taille=?';
                $args = array_merge($args, array_values($fichier));
            }
            $sql .= ' WHERE id=?';
            $args[] = $id;
            db()->prepare($sql)->execute($args);
        } else {
            $cols = array_keys($champs);
            $args = array_values($champs);
            if ($fichier) {
                $cols = array_merge($cols, ['fichier', 'nom_origine', 'mime', 'taille']);
                $args = array_merge($args, array_values($fichier));
            }
            $sql = 'INSERT INTO pieces (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
            db()->prepare($sql)->execute($args);
            $id = (int) db()->lastInsertId();
            if ($ecrSource) {
                piece_rapprocher($id, (int) $ecrSource['id']);
            }
        }
        redirect('piece', ['id' => $id, 'ok' => 1]);
    }

    render('piece_form', piece_form_data($piece, null, '', $ecrSource), $piece ? 'Modifier la pièce' : 'Nouvelle pièce');
}

// Ce que le formulaire renvoie, nettoyé. L'ordre des clés EST celui des
// colonnes de l'INSERT/UPDATE ci-dessus : ne pas le changer d'un côté seulement.
function piece_champs_postes(): array
{
    $mode = valeur_autorisee((string) ($_POST['mode_paiement'] ?? ''), array_keys(PIECE_MODES_PAIEMENT), '');
    $payee = trim((string) ($_POST['date_payee'] ?? ''));
    // Une date de paiement sans mode ne dit pas si une écriture viendra : on
    // suppose le compte suivi, qui est le cas courant. Et un mode sans date ne
    // veut rien dire — la pièce reste à payer.
    if ($payee !== '' && $mode === '') {
        $mode = 'compte';
    }
    if ($payee === '') {
        $mode = '';
    }
    return [
        'type'           => valeur_autorisee((string) ($_POST['type'] ?? ''), array_keys(PIECE_TYPES), 'facture_recue'),
        'libelle'        => trim((string) ($_POST['libelle'] ?? '')),
        'tiers'          => trim((string) ($_POST['tiers'] ?? '')),
        'montant'        => piece_montant_poste(),
        'date_piece'     => trim((string) ($_POST['date_piece'] ?? '')),
        'date_echeance'  => trim((string) ($_POST['date_echeance'] ?? '')),
        'date_payee'     => $payee,
        'mode_paiement'  => $mode,
        'plan_compte_id' => ((int) ($_POST['plan_compte_id'] ?? 0)) ?: null,
        'notes'          => trim((string) ($_POST['notes'] ?? '')),
    ];
}

// Le montant, signé par le SENS choisi plutôt que par un moins saisi à la main.
// On tape 240.00 et on dit « dépense » : le signe est une propriété du
// mouvement, pas une subtilité de frappe.
function piece_montant_poste(): float
{
    $brut = str_replace(["'", "\u{202F}", ' '], '', (string) ($_POST['montant'] ?? '0'));
    $val  = abs((float) $brut);
    return ($_POST['sens'] ?? 'depense') === 'recette' ? $val : -$val;
}

// Les données du formulaire, qu'on arrive dessus ou qu'on y revienne sur erreur.
function piece_form_data(?array $piece, ?array $champs, string $err, ?array $ecrSource = null): array
{
    return [
        'p'          => $piece,
        'champs'     => $champs,
        'err'        => $err,
        'ecrSource'  => $ecrSource,
        // Les feuilles de l'arbre : seules elles sont assignables, comme
        // pour le lettrage d'une écriture (plan_feuilles(), lib/compta.php).
        'categories' => plan_feuilles(compta_plan_actif()),
    ];
}

// Une photo prise au téléphone, réduite par le navigateur et envoyée en data
// URI (voir lassoPhotoPiece(), assets/app.js). Le serveur la revalide comme un
// fichier reçu : décodée, elle doit être une vraie image d'un format attendu et
// tenir dans la limite. C'est le procédé de l'avatar, pour la même raison — ce
// qui arrive d'un navigateur n'est jamais une preuve.
function piece_photo_enregistrer(string $champ): ?array
{
    $data = (string) ($_POST[$champ] ?? '');
    if ($data === '') {
        return null;
    }
    if (!preg_match('~^data:(image/(?:jpeg|png|webp));base64,~', $data, $m)) {
        throw new RuntimeException('Photo illisible.');
    }
    $brut = base64_decode(substr($data, strlen($m[0])), true);
    if ($brut === false || $brut === '') {
        throw new RuntimeException('Photo illisible.');
    }
    if (strlen($brut) > FICHIER_PRIVE_MAX) {
        throw new RuntimeException('Photo trop lourde (8 Mo maximum).');
    }
    if (@getimagesizefromstring($brut) === false) {
        throw new RuntimeException("Ce n'est pas une image.");
    }
    $mime = (string) $m[1];
    $ext  = FICHIERS_PRIVES_MIMES[$mime] ?? 'jpg';
    $dir  = fichiers_prives_dir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Impossible de créer le dossier des pièces jointes.");
    }
    $stocke = 'piece_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (file_put_contents($dir . '/' . $stocke, $brut) === false) {
        throw new RuntimeException("Impossible d'enregistrer la photo.");
    }
    @chmod($dir . '/' . $stocke, 0644);
    return [
        'fichier'     => $stocke,
        'nom_origine' => 'photo-' . date('Y-m-d') . '.' . $ext,
        'mime'        => $mime,
        'taille'      => strlen($brut),
    ];
}

function route_piece_supprimer(): void
{
    require_login();
    check_csrf();
    piece_supprimer((int) ($_POST['id'] ?? 0));
    redirect('pieces');
}

// Rattache ou détache. Appelée des DEUX côtés — depuis la fiche d'une pièce et
// depuis la liste des écritures —, d'où le paramètre de retour : on revient là
// d'où l'on vient, c'est le geste le plus attendu.
//
// ⚠️ Exige la lecture de la comptabilité EN PLUS du module : la route est
// déclarée sous « pieces », et le dispatcher ne vérifie que celui-là. Sans ce
// contrôle, un compte qui n'a que le dépôt pourrait désigner n'importe quelle
// écriture — et découvrir son existence par la réponse.
function route_piece_rapprocher(): void
{
    require_login();
    check_csrf();
    if (!module_accessible('compta')) {
        redirect('pieces');
    }
    $pieceId = (int) ($_POST['piece_id'] ?? 0);
    $ecrId   = (int) ($_POST['ecriture_id'] ?? 0);
    if ($pieceId && piece_charger($pieceId)) {
        piece_rapprocher($pieceId, $ecrId);
    }
    if (($_POST['retour'] ?? '') === 'compta_ecritures') {
        redirect('compta_ecritures');
    }
    redirect('piece', ['id' => $pieceId, 'ok' => $ecrId ? 'rapprochee' : 'detachee']);
}

// Marquer payée, ou revenir en arrière. Geste à part parce que c'est LE geste
// courant : une facture se paie le 30, et l'écriture n'arrivera qu'à l'import
// du mois suivant — sans ce bouton il faudrait ouvrir le formulaire entier
// pour poser une date.
function route_piece_payee(): void
{
    require_login();
    check_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    if (!$id || !piece_charger($id)) {
        redirect('pieces');
    }
    if (($_POST['section'] ?? '') === 'annuler') {
        // Détacher aussi l'écriture : une pièce « pas payée » qui garde son
        // rapprochement dirait deux choses contraires.
        db()->prepare("UPDATE pieces SET date_payee = '', mode_paiement = '', ecriture_id = NULL WHERE id = ?")
            ->execute([$id]);
    } else {
        $date = trim((string) ($_POST['date_payee'] ?? '')) ?: date('Y-m-d');
        $mode = valeur_autorisee((string) ($_POST['mode_paiement'] ?? ''), array_keys(PIECE_MODES_PAIEMENT), 'compte');
        db()->prepare('UPDATE pieces SET date_payee = ?, mode_paiement = ? WHERE id = ?')
            ->execute([$date, $mode, $id]);
    }
    redirect('piece', ['id' => $id, 'ok' => 'paiement']);
}

// Sert le fichier d'une pièce. Il vit hors racine web (data/fichiers/) : c'est
// cette route, et elle seule, qui décide qui le lit. Ici, une session avec
// lecture sur le module — pas de jeton, aucun flux extérieur n'y renvoie.
function route_piece_fichier(): void
{
    require_login();
    if (!module_accessible('pieces')) {
        redirect('tableau_bord');
    }
    $id = (int) ($_GET['id'] ?? 0);
    $p  = $id ? piece_charger($id) : null;
    if (!$p || trim((string) $p['fichier']) === '') {
        redirect('pieces');
    }
    $chemin = fichiers_prives_dir() . '/' . basename((string) $p['fichier']);
    if (!is_file($chemin)) {
        redirect('piece', ['id' => $id, 'err' => 'Fichier introuvable sur le serveur.']);
    }
    fichier_prive_envoyer($chemin, (string) $p['mime'], (string) $p['nom_origine']);
}
