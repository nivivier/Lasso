<?php
// Routes des pièces comptables (docs/SPEC_PIECES.md).
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
    // Le type vient du menu « Nouvelle pièce » (pieces_menu_nouvelle_html()) :
    // on arrive donc sur le formulaire la première question déjà répondue, et
    // c'est lui qui décide des champs posés. Un type absent ou inconnu retombe
    // sur la facture reçue, de loin le cas le plus courant.
    $typeUrl = valeur_autorisee((string) ($_GET['type'] ?? ''), array_keys(PIECE_TYPES), 'facture_recue');

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
            render('piece_form', piece_form_data($piece, $champs, $e->getMessage(), $ecrSource, $typeUrl), 'Pièce');
            return;
        }

        if ($piece) {
            $sql = 'UPDATE pieces SET type=?, structure_id=?, personne_id=?, libelle=?, tiers=?, montant=?,
                    date_piece=?, date_echeance=?, date_payee=?, mode_paiement=?, plan_compte_id=?, notes=?';
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
            // Une quittance est un document que Lasso PRODUIT : elle naît
            // numérotée, et son numéro ne change plus — c'est ce qui en fait
            // une pièce comptable référençable.
            if ($champs['type'] === 'quittance') {
                $annee = (int) substr($champs['date_piece'] ?: date('Y-m-d'), 0, 4);
                $cols = array_merge($cols, ['origine', 'numero']);
                $args = array_merge($args, ['emise', quittance_prochain_numero($annee)]);
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

    $titre = $piece ? 'Modifier la pièce' : PIECE_TYPES[$typeUrl]['nouveau'];
    render('piece_form', piece_form_data($piece, null, '', $ecrSource, $typeUrl), $titre);
}

// Ce que le formulaire renvoie, nettoyé. L'ordre des clés EST celui des
// colonnes de l'INSERT/UPDATE ci-dessus : ne pas le changer d'un côté seulement.
function piece_champs_postes(): array
{
    $type = valeur_autorisee((string) ($_POST['type'] ?? ''), array_keys(PIECE_TYPES), 'facture_recue');
    $payee = trim((string) ($_POST['date_payee'] ?? ''));
    // Le moyen de paiement se saisit par une CASE « hors des comptes suivis »
    // et non par un menu de deux lignes : le cas courant est le compte suivi,
    // et une case cochée dit l'exception mieux qu'un menu qu'il faut ouvrir
    // pour découvrir qu'il n'offre que ce qu'on savait déjà.
    // Un mode sans date ne veut rien dire — la pièce reste à payer.
    $mode = $payee === '' ? '' : (!empty($_POST['hors_compte']) ? 'hors_compte' : 'compte');
    // Le bénéficiaire d'une quittance : une structure OU une personne, jamais
    // les deux. Le champ vient d'un sélecteur unique qui préfixe son choix,
    // « s:12 » ou « p:34 » — deux menus côte à côte auraient laissé en cocher
    // deux et posé la question de qui gagne.
    [$benefType, $benefId] = array_pad(explode(':', (string) ($_POST['beneficiaire'] ?? ''), 2), 2, '');
    $benefId = (int) $benefId;
    return [
        'type'           => $type,
        'structure_id'   => $benefType === 's' && $benefId ? $benefId : null,
        'personne_id'    => $benefType === 'p' && $benefId ? $benefId : null,
        'libelle'        => trim((string) ($_POST['libelle'] ?? '')),
        'tiers'          => trim((string) ($_POST['tiers'] ?? '')),
        'montant'        => piece_montant_poste($type),
        'date_piece'     => trim((string) ($_POST['date_piece'] ?? '')),
        'date_echeance'  => trim((string) ($_POST['date_echeance'] ?? '')),
        'date_payee'     => $payee,
        'mode_paiement'  => $mode,
        'plan_compte_id' => ((int) ($_POST['plan_compte_id'] ?? 0)) ?: null,
        'notes'          => trim((string) ($_POST['notes'] ?? '')),
    ];
}

// Le montant, signé par le SENS plutôt que par un moins saisi à la main : on
// tape 240.00, le signe est une propriété du mouvement.
//
// Et ce sens, le TYPE le connaît presque toujours (PIECE_TYPES) : une facture
// reçue est une dépense, une quittance une recette. Le formulaire ne pose donc
// la question que pour « autre », seul type dont on ne puisse rien déduire —
// la poser partout revenait à faire choisir ce qu'on savait déjà, et à laisser
// la porte ouverte à une facture reçue enregistrée en recette.
function piece_montant_poste(string $type = 'autre'): float
{
    $brut = str_replace(["'", "\u{202F}", ' '], '', (string) ($_POST['montant'] ?? '0'));
    $val  = abs((float) $brut);
    $sens = (string) (PIECE_TYPES[$type]['sens'] ?? '');
    if ($sens === '') {
        $sens = (string) ($_POST['sens'] ?? 'depense');
    }
    return $sens === 'recette' ? $val : -$val;
}

// Les données du formulaire, qu'on arrive dessus ou qu'on y revienne sur erreur.
function piece_form_data(?array $piece, ?array $champs, string $err, ?array $ecrSource = null, string $typeDefaut = 'facture_recue'): array
{
    return [
        'p'          => $piece,
        'champs'     => $champs,
        'err'        => $err,
        'ecrSource'  => $ecrSource,
        'typeDefaut' => $typeDefaut,
        // Les feuilles de l'arbre : seules elles sont assignables, comme
        // pour le lettrage d'une écriture (plan_feuilles(), lib/compta.php).
        'categories' => plan_feuilles(compta_plan_actif()),
        // Les deux carnets, pour le bénéficiaire d'une quittance. Chargés
        // seulement quand ils servent : 2900 personnes dans un menu qu'on
        // n'ouvrira pas sont 2900 lignes de trop sur chaque autre pièce.
        'beneficiaires' => piece_beneficiaires_options(),
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
    // Le décodage et la PREUVE que c'en est une vivent dans
    // image_data_uri_decoder() (lib/helpers.php), partagés avec le recadrage
    // d'avatar : la déclaration « data:image/… » du navigateur ne prouve rien,
    // et cette vérification-là ne doit exister qu'en un exemplaire.
    [$brut, $ext, $mime] = image_data_uri_decoder($data, FICHIER_PRIVE_MAX);
    $dir = fichiers_prives_dir();
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

// Les rapprochements proposés. GET les montre, POST applique ceux qu'on a
// cochés. Rien n'est stocké : la liste se recalcule à chaque affichage, donc
// elle ne peut pas proposer un rapprochement devenu impossible.
function route_pieces_rapprochements(): void
{
    require_login();
    if (!module_accessible('compta')) {
        redirect('pieces');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        // Les paires arrivent en « pieceId:ecritureId » : la pièce seule ne
        // suffirait pas, puisque la suggestion a pu changer entre l'affichage
        // et la validation. On revérifie donc que la pièce est toujours libre
        // et l'écriture toujours disponible avant de poser quoi que ce soit.
        $n = 0;
        foreach ((array) ($_POST['paires'] ?? []) as $paire) {
            [$pid, $eid] = array_pad(explode(':', (string) $paire, 2), 2, '');
            $pid = (int) $pid;
            $eid = (int) $eid;
            if (!$pid || !$eid) {
                continue;
            }
            $p = piece_charger($pid);
            if (!$p || !empty($p['ecriture_id'])) {
                continue;
            }
            $libre = db()->prepare('SELECT COUNT(*) FROM pieces WHERE ecriture_id = ?');
            $libre->execute([$eid]);
            if ((int) $libre->fetchColumn() > 0) {
                continue;
            }
            piece_rapprocher($pid, $eid);
            $n++;
        }
        redirect('pieces_rapprochements', $n ? ['ok' => $n] : []);
    }

    render('pieces_rapprochements', [
        'suggestions' => pieces_suggestions(),
        'ok'          => isset($_GET['ok']) ? (int) $_GET['ok'] : null,
    ], 'Rapprochements proposés');
}

// L'archive d'un exercice : tous les justificatifs de l'année dans un ZIP,
// avec un index CSV qui relie chaque pièce à son écriture. C'est ce qu'un
// vérificateur aux comptes ou une fiduciaire demande, et la sauvegarde
// complète ne le remplace pas : elle contient TOUT, sans tri par année ni
// lien avec la comptabilité — inexploitable pour un contrôle.
//
// Les fichiers sont nommés « 2026-03-14_Swisscom_Facture-janvier.pdf » plutôt
// que par leur nom de stockage tiré au sort : une archive se parcourt dans un
// explorateur de fichiers, pas en lisant l'index.
function route_pieces_archive(): void
{
    require_login();
    if (!peut_lire('pieces')) {
        redirect('pieces');
    }
    if (!class_exists('ZipArchive')) {
        redirect('pieces', ['err' => 'archive']);
    }
    $annee = (int) ($_GET['annee'] ?? date('Y'));
    $stmt = db()->prepare(
        "SELECT p.*, pc.libelle AS categorie_nom, e.date_op, e.texte AS ecriture_texte
           FROM pieces p
           LEFT JOIN plan_comptes pc ON pc.id = p.plan_compte_id
           LEFT JOIN ecritures e ON e.id = p.ecriture_id
          WHERE strftime('%Y', COALESCE(NULLIF(p.date_piece,''), p.cree_le)) = ?
       ORDER BY COALESCE(NULLIF(p.date_piece,''), p.cree_le), p.id"
    );
    $stmt->execute([(string) $annee]);
    $pieces = $stmt->fetchAll();
    if (!$pieces) {
        redirect('pieces', ['err' => 'archive_vide']);
    }

    $archive = tempnam(sys_get_temp_dir(), 'pieces_') . '.zip';
    @unlink($archive);
    $zip = new ZipArchive();
    if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        redirect('pieces', ['err' => 'archive']);
    }

    $index = fopen('php://temp', 'r+');
    fwrite($index, "\xEF\xBB\xBF"); // BOM UTF-8, pour qu'Excel lise les accents
    fputcsv($index, ['Date', 'Type', 'Intitulé', 'Tiers', 'Montant CHF', 'Échéance', 'Payée le',
                     'Moyen', 'Catégorie', 'Écriture', 'Fichier'], ';', '"', '\\');
    $utilises = [];
    foreach ($pieces as $p) {
        $nomDansZip = '';
        $fichier = basename(trim((string) $p['fichier']));
        if ($fichier !== '' && is_file(fichiers_prives_dir() . '/' . $fichier)) {
            $nomDansZip = pieces_nom_archive($p, $fichier, $utilises);
            $zip->addFile(fichiers_prives_dir() . '/' . $fichier, $nomDansZip);
        }
        fputcsv($index, [
            (string) $p['date_piece'],
            PIECE_TYPES[$p['type']]['libelle'] ?? (string) $p['type'],
            (string) $p['libelle'],
            (string) $p['tiers'],
            number_format((float) $p['montant'], 2, '.', ''),
            (string) $p['date_echeance'],
            (string) $p['date_payee'],
            PIECE_MODES_PAIEMENT[$p['mode_paiement']] ?? '',
            (string) ($p['categorie_nom'] ?? ''),
            $p['date_op'] ? $p['date_op'] . ' — ' . $p['ecriture_texte'] : '',
            $nomDansZip,
        ], ';', '"', '\\');
    }
    rewind($index);
    $zip->addFromString('index.csv', (string) stream_get_contents($index));
    fclose($index);
    if (!$zip->close()) {
        redirect('pieces', ['err' => 'archive']);
    }

    $employeur = preg_replace('/[^a-z0-9]+/i', '-', (string) param('employeur_nom'));
    $nom = 'pieces-' . $annee . ($employeur !== '' ? '-' . trim($employeur, '-') : '') . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $nom . '"');
    header('Content-Length: ' . filesize($archive));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($archive);
    @unlink($archive);
    exit;
}

// Le nom d'un fichier DANS l'archive : date, tiers, intitulé. Deux pièces
// peuvent produire le même — d'où le compteur, sans lequel ZipArchive écrase
// silencieusement la première.
function pieces_nom_archive(array $p, string $fichier, array &$utilises): string
{
    $ext = pathinfo($fichier, PATHINFO_EXTENSION);
    $propre = function (string $t): string {
        $t = (string) preg_replace('/[^\p{L}\p{N}]+/u', '-', $t);
        return trim(mb_substr($t, 0, 40, 'UTF-8'), '-');
    };
    $base = trim(implode('_', array_filter([
        (string) $p['date_piece'],
        $propre((string) $p['tiers']),
        $propre((string) $p['libelle']),
    ])), '_');
    $base = $base !== '' ? $base : 'piece-' . (int) $p['id'];
    $nom = $base . ($ext !== '' ? '.' . $ext : '');
    $n = 2;
    while (isset($utilises[$nom])) {
        $nom = $base . '-' . $n++ . ($ext !== '' ? '.' . $ext : '');
    }
    $utilises[$nom] = true;
    return $nom;
}

// Celui que la pièce désigne : le bénéficiaire d'une quittance, la personne à
// rembourser d'une note de frais. Trois groupes dans un seul sélecteur, chaque
// valeur préfixée (« p: » une personne, « s: » une structure).
//
// ⚠️ Les EMPLOYÉS d'abord, et c'est tout l'enjeu : rembourser une avance, c'est
// neuf fois sur dix rembourser l'un d'eux, et ils sont dix là où le carnet en
// compte trois mille. Ce sont des personnes comme les autres — même valeur
// « p:<id> » —, simplement sorties du lot et nommées. Un employé déjà listé là
// ne reparaît donc pas sous « Personnes » : deux lignes identiques dans le même
// menu feraient hésiter sans rien apporter.
//
// Chaque groupe reste derrière le droit de LECTURE de son module
// (module_accessible(), jamais module_actif() seul) : un compte qui n'a que la
// comptabilité ne doit pas y découvrir le carnet d'adresses ni la liste des
// employés. Il lui reste le champ « Tiers », qui prend un nom libre.
function piece_beneficiaires_options(): array
{
    $out = [];
    $deja = [];
    if (module_accessible('salaires')) {
        $sql = 'SELECT e.personne_id, pe.prenom, pe.nom' . EMPLOYE_FROM
             . ' WHERE e.actif = 1 ORDER BY pe.nom COLLATE NOCASE, pe.prenom COLLATE NOCASE';
        foreach (db()->query($sql) as $em) {
            $pid = (int) $em['personne_id'];
            if (!$pid) {
                continue;   // employé sans identité rattachée : rien à désigner
            }
            $deja[$pid] = true;
            $out[] = ['val' => 'p:' . $pid, 'libelle' => personne_nom($em), 'groupe' => 'Employés'];
        }
    }
    if (module_accessible('carnet')) {
        foreach (db()->query("SELECT id, nom FROM structures WHERE statut <> 'inactif' ORDER BY nom COLLATE NOCASE") as $s) {
            $out[] = ['val' => 's:' . (int) $s['id'], 'libelle' => (string) $s['nom'], 'groupe' => 'Structures'];
        }
    }
    if (peut_lire('coeur')) {
        foreach (db()->query("SELECT id, prenom, nom, email FROM personnes ORDER BY nom COLLATE NOCASE, prenom COLLATE NOCASE") as $pe) {
            if (isset($deja[(int) $pe['id']])) {
                continue;
            }
            $out[] = ['val' => 'p:' . (int) $pe['id'], 'libelle' => personne_nom($pe), 'groupe' => 'Personnes'];
        }
    }
    return $out;
}

// La quittance imprimable. Un document d'une page, comme les autres
// impressions de l'application : render_bare(), aucune commande, rien qui ne
// se retrouve sur le papier.
function route_quittance_imprimer(): void
{
    require_login();
    $id = (int) ($_GET['id'] ?? 0);
    $p  = $id ? piece_charger($id) : null;
    if (!$p || (string) $p['type'] !== 'quittance') {
        redirect('pieces');
    }
    render_bare('quittance_imprimer', [
        'p'            => $p,
        'beneficiaire' => piece_beneficiaire($p),
    ]);
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
    // ?apercu=1 : la même route, servie « inline », pour l'aperçu de ?p=piece.
    // Une route de plus aurait dupliqué le contrôle d'accès ci-dessus — et
    // c'est ce contrôle qui compte, pas la disposition. fichier_prive_envoyer()
    // retombe en téléchargement pour un type qu'un navigateur n'affiche pas.
    fichier_prive_envoyer($chemin, (string) $p['mime'], (string) $p['nom_origine'],
        ($_GET['apercu'] ?? '') !== '');
}
