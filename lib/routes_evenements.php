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

// Ligne de prestation (fiche_lignes) déjà ajoutée pour cet événement + cet
// employé, avec les infos de la fiche porteuse — une seule ligne par événement
// (voir migration_27). Null si aucune prestation n'a encore été ajoutée : la
// carte « Employés » propose alors de choisir/créer une fiche.
function evenement_ligne_pour(int $evenementId, int $employeId): ?array
{
    $stmt = db()->prepare(
        'SELECT fl.*, f.annee, f.mois, f.date_paiement, f.supplement_taux, f.afficher_cout_emp, f.taux_json
         FROM fiche_lignes fl JOIN fiches f ON f.id = fl.fiche_id
         WHERE fl.evenement_id = ? AND f.employe_id = ?'
    );
    $stmt->execute([$evenementId, $employeId]);
    return $stmt->fetch() ?: null;
}

// Fiches non payées d'un employé, pour proposer où rattacher une prestation
// depuis un événement (les plus récentes d'abord) — une fiche payée est figée,
// jamais proposée ici.
function fiches_modifiables_pour_employe(int $employeId): array
{
    $stmt = db()->prepare(
        "SELECT id, annee, mois FROM fiches WHERE employe_id = ? AND date_paiement = ''
         ORDER BY annee DESC, mois DESC"
    );
    $stmt->execute([$employeId]);
    return $stmt->fetchAll();
}

// Retire la prestation (et donc le lien de fiche) associée à cet événement pour
// cet employé — invariant : jamais de fiche liée à un événement sans que son
// employé le soit aussi. No-op si la fiche est déjà payée (historique figé :
// on ne touche plus à ses lignes ni à ses montants une fois payée).
function evenement_detacher_prestation(int $evenementId, int $employeId): void
{
    $ligne = evenement_ligne_pour($evenementId, $employeId);
    if (!$ligne || trim((string) $ligne['date_paiement']) !== '') {
        return;
    }
    $ficheId = (int) $ligne['fiche_id'];
    db()->prepare('DELETE FROM fiche_lignes WHERE id = ?')->execute([(int) $ligne['id']]);
    // sauvegarder_fiche() ci-dessous synchronise evenement_fiches à partir des
    // lignes restantes — cet événement n'y figurera plus.

    $stmt = db()->prepare('SELECT * FROM fiche_lignes WHERE fiche_id = ? ORDER BY ordre');
    $stmt->execute([$ficheId]);
    $lignesRestantes = $stmt->fetchAll();

    $stmt = db()->prepare('SELECT * FROM employes WHERE id = ?');
    $stmt->execute([$employeId]);
    $emp = $stmt->fetch();
    if (!$emp) {
        return;
    }
    $tj = json_decode($ligne['taux_json'] ?: '{}', true) ?: [];
    if (isset($tj['impot_source'])) {
        $emp['impot_source_taux'] = (float) $tj['impot_source'];
    }
    $emp['supplement_vacances'] = (float) $ligne['supplement_taux'];
    // Fiche vidée de sa dernière prestation : reste (montants à 0) plutôt que
    // supprimée automatiquement — suppression manuelle si vraiment inutile.
    sauvegarder_fiche($emp, (int) $ligne['annee'], (int) $ligne['mois'], '', $lignesRestantes, $ficheId, (int) $ligne['afficher_cout_emp']);
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
    $annee        = (int) filtre_persistant('annee', 'evenements_annee', 0); // 0 = « Toutes les années » par défaut
    $statutSuisa  = filtre_persistant('statut_suisa', 'evenements_statut_suisa', 'tous');
    // spectacle_id : 0 = tous, -1 = sans spectacle (spectacle_id NULL), > 0 = un spectacle précis.
    $spectacleId  = (int) filtre_persistant('spectacle_id', 'evenements_spectacle_id', 0);
    $statut       = filtre_persistant('statut', 'evenements_statut', 'tous');
    $visibilite   = filtre_persistant('visibilite', 'evenements_visibilite', 'tous');
    $pays         = filtre_persistant('pays', 'evenements_pays_filtre', 'tous');
    $salaries     = filtre_persistant('salaries', 'evenements_salaries', 'tous'); // tous | oui | non
    $recherche    = trim((string) ($_GET['q'] ?? '')); // jamais mémorisée en session, comme pagination_page()
    $retourFiltres = [
        'annee' => $annee, 'statut_suisa' => $statutSuisa, 'spectacle_id' => $spectacleId,
        'statut' => $statut, 'visibilite' => $visibilite, 'pays' => $pays, 'salaries' => $salaries,
        'q' => $recherche,
    ];

    // Modification groupée (sélection de lignes + barre flottante, même esprit que
    // le lettrage/l'axe analytique en masse sur les écritures comptables).
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $section = $_POST['section'] ?? '';
        if ($section === 'bulk_undo') {
            $r = bulk_undo_appliquer();
            redirect($r['route'] ?? 'evenements_liste', ($r['retour'] ?? $retourFiltres) + ($r ? ['ok' => 'annule'] : []));
        }
        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            // Modifications simples (UPDATE) : état « avant » mémorisé pour permettre
            // l'annulation en un clic (voir bulk_undo_memoriser()). La suppression n'est
            // pas couverte (état bien plus lourd à restaurer fidèlement).
            unset($_SESSION['bulk_undo']); // évite de reprendre par erreur un état d'une requête précédente
            if ($section === 'delete') {
                // FK ON DELETE CASCADE/SET NULL (evenement_employes, evenement_fiches,
                // factures, fiche_lignes) : pas de nettoyage manuel nécessaire.
                db()->prepare("DELETE FROM evenements WHERE id IN ($in)")->execute($ids);
            } elseif ($section === 'spectacle') {
                $spId = ($_POST['bulk_spectacle_id'] ?? '') !== '' ? (int) $_POST['bulk_spectacle_id'] : null;
                if ($spId === null || spectacle_assignable($spId)) {
                    bulk_undo_memoriser('evenements', $ids, ['spectacle_id'], 'evenements_liste', $retourFiltres);
                    db()->prepare("UPDATE evenements SET spectacle_id = ? WHERE id IN ($in)")
                        ->execute(array_merge([$spId], $ids));
                }
            } elseif ($section === 'visibilite' && in_array($_POST['bulk_visibilite'] ?? '', EVENEMENTS_VISIBILITES, true)) {
                bulk_undo_memoriser('evenements', $ids, ['visibilite'], 'evenements_liste', $retourFiltres);
                db()->prepare("UPDATE evenements SET visibilite = ? WHERE id IN ($in)")
                    ->execute(array_merge([$_POST['bulk_visibilite']], $ids));
            } elseif ($section === 'statut' && in_array($_POST['bulk_statut'] ?? '', EVENEMENTS_STATUTS, true)) {
                bulk_undo_memoriser('evenements', $ids, ['statut'], 'evenements_liste', $retourFiltres);
                db()->prepare("UPDATE evenements SET statut = ? WHERE id IN ($in)")
                    ->execute(array_merge([$_POST['bulk_statut']], $ids));
            } elseif ($section === 'region') {
                $region = trim((string) ($_POST['bulk_region'] ?? ''));
                bulk_undo_memoriser('evenements', $ids, ['region'], 'evenements_liste', $retourFiltres);
                db()->prepare("UPDATE evenements SET region = ? WHERE id IN ($in)")
                    ->execute(array_merge([$region], $ids));
            } elseif ($section === 'pays') {
                $pays = valeur_autorisee($_POST['bulk_pays'] ?? '', evenements_pays_disponibles());
                bulk_undo_memoriser('evenements', $ids, ['pays'], 'evenements_liste', $retourFiltres);
                db()->prepare("UPDATE evenements SET pays = ? WHERE id IN ($in)")
                    ->execute(array_merge([$pays], $ids));
            } elseif ($section === 'suisa_applicable') {
                $applicable = ($_POST['bulk_suisa_applicable'] ?? '') === '1' ? 1 : 0;
                bulk_undo_memoriser('evenements', $ids, ['suisa_applicable'], 'evenements_liste', $retourFiltres);
                db()->prepare("UPDATE evenements SET suisa_applicable = ? WHERE id IN ($in)")
                    ->execute(array_merge([$applicable], $ids));
            } elseif ($section === 'suisa_envoi') {
                $envoyeA = valeur_autorisee($_POST['bulk_suisa_envoye_a'] ?? '', EVENEMENTS_SUISA_ENVOYE_A);
                $envoyeLe = trim((string) ($_POST['bulk_suisa_envoye_le'] ?? ''));
                bulk_undo_memoriser('evenements', $ids, ['suisa_envoye_a', 'suisa_envoye_le'], 'evenements_liste', $retourFiltres);
                db()->prepare("UPDATE evenements SET suisa_envoye_a = ?, suisa_envoye_le = ? WHERE id IN ($in)")
                    ->execute(array_merge([$envoyeA, $envoyeLe], $ids));
            } elseif ($section === 'suisa_decompte') {
                $decompteLe = trim((string) ($_POST['bulk_suisa_decompte_le'] ?? ''));
                bulk_undo_memoriser('evenements', $ids, ['suisa_decompte_le'], 'evenements_liste', $retourFiltres);
                db()->prepare("UPDATE evenements SET suisa_decompte_le = ? WHERE id IN ($in)")
                    ->execute(array_merge([$decompteLe], $ids));
            }
            if ($section !== '' && $section !== 'delete' && isset($_SESSION['bulk_undo'])) {
                $retourFiltres['bulk'] = count($ids);
            }
        }
        redirect('evenements_liste', $retourFiltres);
    }

    $spectacleMap = spectacle_map();

    $where = ' WHERE 1=1';
    $params = [];
    if ($annee) {
        $where .= " AND strftime('%Y', e.date) = ?";
        $params[] = (string) $annee;
    }
    if ($spectacleId === -1) {
        $where .= ' AND e.spectacle_id IS NULL';
    } elseif ($spectacleId) {
        // Un spectacle-groupe (artiste) filtre sur lui-même + toutes ses feuilles
        // (même principe que l'export public, voir evenements_a_exporter()).
        $ids = array_merge([$spectacleId], spectacle_descendants($spectacleId, $spectacleMap));
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $where .= " AND e.spectacle_id IN ($in)";
        $params = array_merge($params, $ids);
    }
    if (in_array($statut, EVENEMENTS_STATUTS, true)) {
        $where .= ' AND e.statut = ?';
        $params[] = $statut;
    }
    if (in_array($visibilite, EVENEMENTS_VISIBILITES, true)) {
        $where .= ' AND e.visibilite = ?';
        $params[] = $visibilite;
    }
    if (in_array($statutSuisa, EVENEMENTS_STATUTS_SUISA_FILTRE, true)) {
        $where .= ' AND (' . evenement_sql_statut_suisa($statutSuisa, 'e.') . ')';
        // Ordre des paramètres = ordre des '?' dans evenement_sql_statut_suisa().
        if ($statutSuisa === 'manquant') {
            $params[] = evenements_delai_decompte_mois();
            $params[] = evenements_delai_abandon_mois();
        } elseif (in_array($statutSuisa, ['a_faire', 'envoye', 'abandonne'], true)) {
            $params[] = evenements_delai_abandon_mois();
        }
    }
    if ($pays !== 'tous' && in_array($pays, evenements_pays_disponibles(), true)) {
        $where .= ' AND e.pays = ?';
        $params[] = $pays;
    }
    if ($salaries === 'oui') {
        $where .= ' AND EXISTS (SELECT 1 FROM evenement_employes ee WHERE ee.evenement_id = e.id)';
    } elseif ($salaries === 'non') {
        $where .= ' AND NOT EXISTS (SELECT 1 FROM evenement_employes ee WHERE ee.evenement_id = e.id)';
    }
    [$rechSql, $rechParams] = recherche_sql(['e.ville', 'e.salle', 'e.festival', 's.nom']);
    $where .= $rechSql;
    $params = array_merge($params, $rechParams);

    $from = ' FROM evenements e LEFT JOIN spectacles s ON s.id = e.spectacle_id';

    $stmtTot = db()->prepare('SELECT COUNT(*)' . $from . $where);
    $stmtTot->execute($params);
    $pgTotal = (int) $stmtTot->fetchColumn();

    $pgPage   = pagination_page();
    $pgTaille = pagination_taille('evenements_taille');
    [$limitSql, $limitParams] = pagination_sql($pgPage, $pgTaille);

    $sql = "SELECT e.*, s.nom AS spectacle_nom,
                   (SELECT COUNT(*) FROM evenement_employes ee WHERE ee.evenement_id = e.id) AS nb_salaries"
            . $from . $where . ' ORDER BY e.date DESC, e.id DESC' . $limitSql;
    $stmt = db()->prepare($sql);
    $stmt->execute(array_merge($params, $limitParams));
    $evenements = $stmt->fetchAll();

    // $spectacles : feuilles assignables uniquement (select « Modifier spectacle »
    // de la barre de modification groupée — un groupe n'y est jamais valide, voir
    // spectacle_assignable()). $spectaclesFiltre : groupes + feuilles (filtre en
    // haut de page, où un groupe est un filtre valide — voir spectacles_pour_filtre()).
    $spectacles = spectacles_pour_selection($spectacleMap);
    $spectaclesFiltre = spectacles_pour_filtre($spectacleMap);

    render('evenements_liste', [
        'evenements'      => $evenements,
        'annee'           => $annee,
        'annees'          => $annees ?: [(int) date('Y')],
        'statutSuisa'     => $statutSuisa,
        'spectacleId'     => $spectacleId,
        'statut'          => $statut,
        'visibilite'      => $visibilite,
        'spectacles'      => $spectacles,
        'spectaclesFiltre' => $spectaclesFiltre,
        'paysDisponibles' => evenements_pays_disponibles(),
        'pays'            => $pays,
        'salaries'        => $salaries,
        'recherche'       => $recherche,
        'bulkCount'       => isset($_GET['bulk']) ? (int) $_GET['bulk'] : null,
        'okAnnule'        => ($_GET['ok'] ?? '') === 'annule',
        'pgRoute'         => 'evenements_liste',
        'pgParams'        => $retourFiltres,
        'pgPage'          => $pgPage,
        'pgTaille'        => $pgTaille,
        'pgTotal'         => $pgTotal,
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

    $spectacleMap = spectacle_map();
    $spectacles = spectacles_pour_selection($spectacleMap);
    $employesTous = db()->query('SELECT id, prenom, nom FROM employes ORDER BY nom, prenom')->fetchAll();

    // Sépare « déjà liés » / « disponibles » pour le picker (select + bouton
    // Ajouter, cf. views/evenement_form.php) — évite d'afficher une case à
    // cocher par employé, peu lisible dès que la liste grossit.
    $employeIds = $id ? evenement_employe_ids($id) : [];
    $employesLies  = array_values(array_filter($employesTous, fn ($e) => in_array((int) $e['id'], $employeIds, true)));
    $employesDispo = array_values(array_filter($employesTous, fn ($e) => !in_array((int) $e['id'], $employeIds, true)));

    // Pour chaque employé lié : la prestation déjà ajoutée (le cas échéant) et
    // ses fiches non payées, pour la carte « Employés » (tableau fusionné
    // employé/fiche — impossible d'avoir une fiche liée sans employé lié).
    // Deux requêtes groupées (pas une par employé) : evenement_ligne_pour() et
    // fiches_modifiables_pour_employe() restent utiles ailleurs pour un seul
    // employé (ex. evenement_detacher_prestation()), mais ici on veut tous les
    // employés liés en une fois.
    $prestations = array_fill_keys($employeIds, null);
    $fichesParEmploye = array_fill_keys($employeIds, []);
    if ($id && $employeIds) {
        foreach (db()->query(
            'SELECT fl.*, f.annee, f.mois, f.date_paiement, f.supplement_taux, f.afficher_cout_emp, f.taux_json, f.employe_id
             FROM fiche_lignes fl JOIN fiches f ON f.id = fl.fiche_id
             WHERE fl.evenement_id = ' . (int) $id
        ) as $ligne) {
            $prestations[(int) $ligne['employe_id']] = $ligne;
        }
        $in = implode(',', $employeIds);
        foreach (db()->query(
            "SELECT id, annee, mois, employe_id FROM fiches WHERE employe_id IN ($in) AND date_paiement = ''
             ORDER BY annee DESC, mois DESC"
        ) as $fiche) {
            $fichesParEmploye[(int) $fiche['employe_id']][] = $fiche;
        }
    }

    $axes = module_actif('analytique')
        ? db()->query('SELECT * FROM axes_analytiques WHERE actif = 1 ORDER BY ordre, id')->fetchAll()
        : [];

    $renderForm = function (?string $err) use (
        $evenement, $id, $spectacles, $spectacleMap, $employesLies, $employesDispo, $prestations, $fichesParEmploye, $axes
    ) {
        render('evenement_form', [
            'evenement'      => $evenement,
            'id'             => $id,
            'spectacles'     => $spectacles,
            'spectacleMap'   => $spectacleMap,
            'employesLies'   => $employesLies,
            'employesDispo'  => $employesDispo,
            'prestations'    => $prestations,
            'fichesParEmploye' => $fichesParEmploye,
            'unites'         => db()->query('SELECT * FROM unites ORDER BY heures')->fetchAll(),
            'tauxHoraires'   => db()->query('SELECT * FROM taux_horaires ORDER BY montant')->fetchAll(),
            'factures'       => $id ? evenement_factures_liees($id) : [],
            'facturesDispo'  => ($id && module_actif('facturation')) ? factures_sans_evenement() : [],
            'paysDisponibles' => evenements_pays_disponibles(),
            'axes'           => $axes,
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
    $statut = valeur_autorisee($_POST['statut'] ?? '', EVENEMENTS_STATUTS, 'option');
    $visibilite = valeur_autorisee($_POST['visibilite'] ?? '', EVENEMENTS_VISIBILITES, 'non_repertorie');
    $spectacleId = ($_POST['spectacle_id'] ?? '') !== '' ? (int) $_POST['spectacle_id'] : null;
    $ville = trim($_POST['ville'] ?? '');
    $region = trim($_POST['region'] ?? '');
    $pays = valeur_autorisee($_POST['pays'] ?? '', evenements_pays_disponibles());
    $salle = trim($_POST['salle'] ?? '');
    $festival = trim($_POST['festival'] ?? '');
    $lienInfos = trim($_POST['lien_infos'] ?? '');
    $lienTexte = trim($_POST['lien_texte'] ?? '');
    $remarques = trim($_POST['remarques'] ?? '');

    // Un spectacle-parent (groupe/artiste) n'est jamais assignable — sauf s'il
    // s'agit du spectacle déjà en place (édition d'un autre champ sans y toucher :
    // le <select> le réaffiche tel quel, marqué « non réassignable »).
    $spectacleInchange = $evenement && $spectacleId === (int) $evenement['spectacle_id'];
    $err = null;
    if (!date_valide($date)) {
        $err = 'La date est invalide.';
    } elseif ($spectacleId !== null && !$spectacleInchange && !spectacle_assignable($spectacleId)) {
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
    $suisaEnvoyeA = valeur_autorisee($_POST['suisa_envoye_a'] ?? '', EVENEMENTS_SUISA_ENVOYE_A);
    $suisaEnvoyeLe = trim($_POST['suisa_envoye_le'] ?? '');
    $suisaDecompteLe = trim($_POST['suisa_decompte_le'] ?? '');
    db()->prepare('UPDATE evenements SET suisa_applicable=?, suisa_envoye_a=?, suisa_envoye_le=?, suisa_decompte_le=? WHERE id=?')
        ->execute([$suisaApplicable, $suisaEnvoyeA, $suisaEnvoyeLe, $suisaDecompteLe, $id]);
    redirect('evenement', ['id' => $id, 'ok' => 'suisa']);
}

// Carte « Comptabilité analytique » — axe par défaut de l'événement, présélectionné
// pour les nouvelles prestations (route_evenement_ligne_ajouter) et pour les lignes
// d'une facture créée depuis cet événement (route_facturation_form), modifiable
// au cas par cas ensuite sans jamais toucher les lignes déjà enregistrées.
function route_evenement_axe_defaut(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !module_actif('analytique')) {
        redirect('evenements_liste');
    }
    check_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    if (!evenement_charger($id)) {
        redirect('evenements_liste');
    }
    $axeId = (int) ($_POST['axe_analytique_id_defaut'] ?? 0) ?: null;
    if ($axeId !== null) {
        $stmt = db()->prepare('SELECT 1 FROM axes_analytiques WHERE id = ? AND actif = 1');
        $stmt->execute([$axeId]);
        if (!$stmt->fetchColumn()) {
            $axeId = null;
        }
    }
    db()->prepare('UPDATE evenements SET axe_analytique_id_defaut = ? WHERE id = ?')->execute([$axeId, $id]);
    redirect('evenement', ['id' => $id, 'ok' => 'axe']);
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

// Retire l'employé — et, avec lui, toute prestation/lien de fiche associé à cet
// événement pour cet employé (invariant : pas de fiche liée sans employé lié).
// Refuse (no-op) si cette prestation a déjà été payée : on ne délie jamais un
// employé « en douce » d'une fiche figée, il faut d'abord la corriger à la main.
function route_evenement_employe_delier(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('evenements_liste');
    }
    check_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $employeId = (int) ($_POST['employe_id'] ?? 0);
    $ligne = evenement_ligne_pour($id, $employeId);
    if ($ligne && trim((string) $ligne['date_paiement']) !== '') {
        redirect('evenement', ['id' => $id, 'errEmploye' => 'paye']);
    }
    evenement_detacher_prestation($id, $employeId);
    db()->prepare('DELETE FROM evenement_employes WHERE evenement_id = ? AND employe_id = ?')->execute([$id, $employeId]);
    redirect('evenement', ['id' => $id]);
}

// Carte « Employés » — ajoute (ou met à jour) la ligne de prestation d'un
// employé déjà lié à l'événement, sur une fiche existante non payée ou une
// fiche à créer pour le mois de l'événement. Une seule ligne par événement
// (voir evenement_ligne_pour()) ; établit aussi le lien evenement_fiches. Si la
// fiche choisie diffère de celle déjà utilisée pour cet événement/employé, on
// déplace la prestation (détache d'abord l'ancienne, recalculée sans elle).
function route_evenement_ligne_ajouter(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('evenements_liste');
    }
    check_csrf();
    $evenementId = (int) ($_POST['id'] ?? 0);
    $employeId   = (int) ($_POST['employe_id'] ?? 0);
    $ev = evenement_charger($evenementId);
    if (!$ev || !$employeId || !in_array($employeId, evenement_employe_ids($evenementId), true)) {
        redirect('evenement', ['id' => $evenementId]);
    }

    $enc    = (string) ($_POST['l_unite'] ?? '');
    $qte    = (float) str_replace(',', '.', $_POST['l_quantite'] ?? '0');
    $choix  = (string) ($_POST['l_taux_choix'] ?? '');
    $manuel = (string) ($_POST['l_taux_manuel'] ?? '');
    $tauxH  = ($choix === 'autre' || $choix === '')
        ? (float) str_replace(',', '.', $manuel)
        : (float) str_replace(',', '.', $choix);
    if ($qte <= 0 || $tauxH <= 0 || !str_contains($enc, '|')) {
        redirect('evenement', ['id' => $evenementId, 'errLigne' => '1']);
    }
    [$hu, $lib] = explode('|', $enc, 2);
    $hu = (float) str_replace(',', '.', $hu);
    if ($hu <= 0 || trim($lib) === '') {
        redirect('evenement', ['id' => $evenementId, 'errLigne' => '1']);
    }
    $axeId = (int) ($_POST['l_axe'] ?? 0) ?: null;
    if ($axeId !== null) {
        $stmt = db()->prepare('SELECT 1 FROM axes_analytiques WHERE id = ? AND actif = 1');
        $stmt->execute([$axeId]);
        if (!$stmt->fetchColumn()) {
            $axeId = null;
        }
    }
    $nouvelleLigne = [
        'libelle' => trim($lib), 'heures_unite' => $hu, 'quantite' => $qte, 'taux_horaire' => $tauxH,
        'axe_analytique_id' => $axeId, 'evenement_id' => $evenementId,
    ];

    $stmt = db()->prepare('SELECT * FROM employes WHERE id = ?');
    $stmt->execute([$employeId]);
    $emp = $stmt->fetch();
    if (!$emp) {
        redirect('evenement', ['id' => $evenementId]);
    }

    // Prestation déjà présente pour cet événement/employé : si la fiche choisie
    // change, on détache d'abord l'ancienne (recalcule l'ancienne fiche sans
    // elle) et on repart comme un ajout normal sur la fiche cible.
    $ligneExistante = evenement_ligne_pour($evenementId, $employeId);
    if ($ligneExistante && trim((string) $ligneExistante['date_paiement']) !== '') {
        redirect('evenement', ['id' => $evenementId]); // fiche déjà payée : figée
    }
    $ficheIdPoste = (int) ($_POST['fiche_id'] ?? 0);
    if ($ligneExistante && (int) $ligneExistante['fiche_id'] !== $ficheIdPoste) {
        evenement_detacher_prestation($evenementId, $employeId);
        $ligneExistante = null;
    }

    $ficheId = $ligneExistante ? (int) $ligneExistante['fiche_id'] : $ficheIdPoste;
    $extra = [];
    if ($ficheId) {
        $stmt = db()->prepare('SELECT * FROM fiches WHERE id = ? AND employe_id = ?');
        $stmt->execute([$ficheId, $employeId]);
        $fiche = $stmt->fetch();
        if (!$fiche || trim((string) $fiche['date_paiement']) !== '') {
            redirect('evenement', ['id' => $evenementId]);
        }
        $annee = (int) $fiche['annee'];
        $mois  = (int) $fiche['mois'];
    } else {
        $annee = (int) substr((string) $ev['date'], 0, 4);
        $mois  = (int) substr((string) $ev['date'], 5, 2);
        $stmt = db()->prepare('SELECT * FROM fiches WHERE employe_id = ? AND annee = ? AND mois = ?');
        $stmt->execute([$employeId, $annee, $mois]);
        $fiche = $stmt->fetch();
        if ($fiche && trim((string) $fiche['date_paiement']) !== '') {
            redirect('evenement', ['id' => $evenementId, 'errLigne' => 'payee']);
        }
        $ficheId = $fiche ? (int) $fiche['id'] : null;
    }

    $lignesExistantes = [];
    if ($ficheId) {
        $stmt = db()->prepare('SELECT * FROM fiche_lignes WHERE fiche_id = ? ORDER BY ordre');
        $stmt->execute([$ficheId]);
        $lignesExistantes = $stmt->fetchAll();
        // La fiche existe déjà : on préserve ses éventuelles surcharges figées
        // (supplément vacances / impôt à la source) plutôt que de revenir aux
        // valeurs courantes de l'employé.
        $tj = json_decode($fiche['taux_json'] ?: '{}', true) ?: [];
        if (isset($tj['impot_source'])) {
            $emp['impot_source_taux'] = (float) $tj['impot_source'];
        }
        $emp['supplement_vacances'] = (float) $fiche['supplement_taux'];
        $extra['afficher_cout_emp'] = (int) $fiche['afficher_cout_emp'];
    }

    // Remplace l'éventuelle ligne déjà tagguée pour cet événement (mise à jour)
    // plutôt que d'en ajouter une deuxième.
    $lignesExistantes = array_values(array_filter(
        $lignesExistantes,
        fn ($l) => (int) ($l['evenement_id'] ?? 0) !== $evenementId
    ));
    $lignesExistantes[] = $nouvelleLigne;

    // sauvegarder_fiche() synchronise déjà evenement_fiches à partir des
    // evenement_id présents dans $lignesExistantes (dont la nouvelle ligne).
    sauvegarder_fiche($emp, $annee, $mois, '', $lignesExistantes, $ficheId ?: null, $extra['afficher_cout_emp'] ?? 0);
    redirect('evenement', ['id' => $evenementId]);
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
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !module_actif('facturation')) {
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
// Modification groupée en arbre (renommage/ajout/déplacement/glisser-déposer),
// même esprit que le plan comptable (route_compta_plan()) — voir spectacle_map()/
// spectacle_descendants() dans lib/evenements.php. Un spectacle-parent (nœud
// non-feuille) représente un artiste ; « trier par artiste » = l'ordre de l'arbre.
function route_spectacles(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $section = $_POST['section'] ?? '';
        $map = spectacle_map();
        if ($section === 'add') {
            $nom = trim($_POST['nom'] ?? '');
            $parent = ($_POST['parent_id'] ?? '') === '' ? null : (int) $_POST['parent_id'];
            if ($nom !== '' && ($parent === null || isset($map[$parent]))) {
                $ordre = (int) db()->query('SELECT COALESCE(MAX(ordre),0)+1 FROM spectacles')->fetchColumn();
                db()->prepare('INSERT INTO spectacles (nom, parent_id, ordre) VALUES (?, ?, ?)')
                    ->execute([$nom, $parent, $ordre]);
            }
        } elseif ($section === 'rename') {
            // Renommage seul (crayon inline) : le formulaire ne porte pas de
            // parent_id — le rattachement se change via le glisser-déposer
            // ('reorder') ou le formulaire complet, jamais ici.
            $id  = (int) ($_POST['id'] ?? 0);
            $nom = trim($_POST['nom'] ?? '');
            if ($nom !== '' && isset($map[$id])) {
                db()->prepare('UPDATE spectacles SET nom = ? WHERE id = ?')->execute([$nom, $id]);
            }
        } elseif ($section === 'move') {
            $id  = (int) ($_POST['id'] ?? 0);
            $dir = ($_POST['dir'] ?? '') === 'up' ? 'up' : 'down';
            if (isset($map[$id])) {
                $pidParent = plan_pid($map[$id]['parent_id'] ?? null);
                $freres = plan_enfants($map)[$pidParent] ?? [];
                $ids = array_map(fn($r) => (int) $r['id'], $freres);
                $pos  = array_search($id, $ids, true);
                $swap = $dir === 'up' ? $pos - 1 : $pos + 1;
                if ($pos !== false && $swap >= 0 && $swap < count($ids)) {
                    [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
                    $upd = db()->prepare('UPDATE spectacles SET ordre = ? WHERE id = ?');
                    db()->beginTransaction();
                    foreach ($ids as $i => $sid) {
                        $upd->execute([$i, $sid]);
                    }
                    db()->commit();
                }
            }
        } elseif ($section === 'reorder') {
            // Glisser-déposer : rattache un spectacle à $parent (vide = racine) et
            // renumérote les frères selon l'ordre fourni (déplacé inclus).
            $id     = (int) ($_POST['id'] ?? 0);
            $parent = ($_POST['parent_id'] ?? '') === '' ? null : (int) $_POST['parent_id'];
            $order  = array_values(array_filter(array_map('intval', explode(',', $_POST['order'] ?? ''))));
            if (isset($map[$id]) && $order) {
                $interdits = array_merge([$id], spectacle_descendants($id, $map));
                $okParent = $parent === null || (isset($map[$parent]) && !in_array($parent, $interdits, true));
                if ($okParent) {
                    db()->beginTransaction();
                    db()->prepare('UPDATE spectacles SET parent_id = ? WHERE id = ?')->execute([$parent, $id]);
                    $upd = db()->prepare('UPDATE spectacles SET ordre = ? WHERE id = ?');
                    $i = 0;
                    foreach ($order as $sid) {
                        if ($sid === $id || (isset($map[$sid]) && plan_pid($map[$sid]['parent_id'] ?? null) === plan_pid($parent))) {
                            $upd->execute([$i++, $sid]);
                        }
                    }
                    db()->commit();
                }
            }
        }
        redirect('spectacles');
    }

    $map = [];
    foreach (db()->query('SELECT * FROM spectacles ORDER BY ordre, id') as $r) {
        $map[(int) $r['id']] = $r;
    }

    // Compte par statut (confirmé/option/annulé), propre à chaque spectacle —
    // un spectacle-groupe (artiste) n'a jamais d'événement lié directement,
    // son total est la somme de ses feuilles descendantes.
    $comptesPropres = [];
    foreach (db()->query(
        'SELECT spectacle_id, statut, COUNT(*) AS n FROM evenements
         WHERE spectacle_id IS NOT NULL GROUP BY spectacle_id, statut'
    ) as $r) {
        $comptesPropres[(int) $r['spectacle_id']][(string) $r['statut']] = (int) $r['n'];
    }
    $comptes = [];
    foreach (array_keys($map) as $id) {
        $sousArbre = array_merge([$id], spectacle_descendants($id, $map));
        $c = ['confirme' => 0, 'option' => 0, 'annule' => 0];
        foreach ($sousArbre as $sid) {
            foreach ($comptesPropres[$sid] ?? [] as $statut => $n) {
                if (isset($c[$statut])) {
                    $c[$statut] += $n;
                }
            }
        }
        $comptes[$id] = $c;
    }

    render('spectacles', [
        'lignes' => plan_liste_ordonnee($map),
        'map'    => $map,
        'comptes' => $comptes,
        'token'  => evenements_export_token(),
        'flagErr' => $_GET['err'] ?? null,
    ], evenements_terme_spectacle());
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
    $map = spectacle_map();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $nom = trim($_POST['nom'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $parent = ($_POST['parent_id'] ?? '') === '' ? null : (int) $_POST['parent_id'];
        $err = null;
        if ($nom === '') {
            $err = 'Le nom est obligatoire.';
        }
        // Rattachement invalide (cycle, parent inexistant) → racine.
        $interdits = $id ? array_merge([$id], spectacle_descendants($id, $map)) : [];
        if ($parent !== null && (in_array($parent, $interdits, true) || !isset($map[$parent]))) {
            $parent = null;
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
            $spectacleErr = array_merge((array) $spectacle, ['id' => $id, 'nom' => $nom, 'notes' => $notes, 'parent_id' => $parent]);
            render('spectacle_form', ['spectacle' => $spectacleErr, 'err' => $err, 'map' => $map], evenements_terme_spectacle(false));
            return;
        }
        if ($id) {
            db()->prepare('UPDATE spectacles SET nom=?, notes=?, suisa_feuille_fichier=?, parent_id=? WHERE id=?')
                ->execute([$nom, $notes, $fichier, $parent, $id]);
        } else {
            $ordre = (int) db()->query('SELECT COALESCE(MAX(ordre),0)+1 FROM spectacles')->fetchColumn();
            db()->prepare('INSERT INTO spectacles (nom, notes, suisa_feuille_fichier, parent_id, ordre) VALUES (?, ?, ?, ?, ?)')
                ->execute([$nom, $notes, $fichier, $parent, $ordre]);
        }
        redirect('spectacles');
    }
    render('spectacle_form', ['spectacle' => $spectacle, 'err' => null, 'map' => $map], ($id ? 'Modifier le ' : 'Nouveau ') . mb_strtolower(evenements_terme_spectacle(false)));
}

function route_spectacle_delete(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $id = (int) ($_POST['id'] ?? 0);
        $map = spectacle_map();
        if (isset($map[$id]) && !plan_est_feuille($id, $map)) {
            redirect('spectacles', ['err' => 'children']); // groupe (artiste) → on refuse
        }
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
            $delaiAbandon = max(1, (int) ($_POST['suisa_delai_abandon_mois'] ?? 60));
            $lienTexteDefaut = trim($_POST['evenements_lien_texte_defaut'] ?? '');
            $termeSpectacle = trim($_POST['evenements_terme_spectacle'] ?? '');
            $paysListe = array_values(array_filter(array_map(
                fn ($p) => mb_strtoupper(trim($p), 'UTF-8'),
                explode(',', (string) ($_POST['evenements_pays_disponibles'] ?? ''))
            ), fn ($p) => $p !== ''));
            $ins = db()->prepare('INSERT OR REPLACE INTO parametres (cle, valeur) VALUES (?, ?)');
            $ins->execute(['suisa_delai_decompte_mois', (string) $delai]);
            $ins->execute(['suisa_delai_abandon_mois', (string) $delaiAbandon]);
            $ins->execute(['evenements_lien_texte_defaut', $lienTexteDefaut]);
            $ins->execute(['evenements_terme_spectacle', $termeSpectacle]);
            $ins->execute(['evenements_pays_disponibles', implode(',', $paysListe)]);
        }
        redirect('parametres_evenements', ['ok' => 1]);
    }

    render('parametres_evenements', [
        'delai' => evenements_delai_decompte_mois(),
        'delaiAbandon' => evenements_delai_abandon_mois(),
        'lienTexteDefaut' => evenements_lien_texte_defaut(),
        'termeSpectacle' => evenements_terme_spectacle(),
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
        $r = lire_fichier_importe(2 * 1024 * 1024, 'Fichier trop volumineux (2 Mo maximum).', 'import_evenements_csv', 'Veuillez choisir un fichier CSV à importer.');
        $err = $r['err'];
        $csv = $r['contenu'];
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
