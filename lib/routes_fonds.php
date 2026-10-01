<?php
// Module « Recherche de fonds » — suivi des demandes de subvention.
// Le besoin et les décisions de conception : SPEC_SUBVENTIONS.md.
//
// Ce que ce module NE fait pas, parce que d'autres le font déjà :
//   — tenir un carnet d'adresses : un bailleur est une `structure`, avec ses
//     contacts, son historique et son bouton « Contacter » (lib/booking.php) ;
//   — stocker des fichiers : les pièces d'un dossier vivent sur un drive
//     externe, la campagne n'en garde que le lien (§ 3 quater) ;
//   — inventer une ventilation : l'axe analytique est celui du projet financé
//     (spectacles.axe_analytique_id, migration_91).

declare(strict_types=1);

require_once __DIR__ . '/booking.php'; // ciblage_structures_preparer(), campagne_date()
require_once __DIR__ . '/fonds.php';   // les règles : statut dérivé, jauge
require_once __DIR__ . '/compta.php';  // montant_float()

// Les recherches de fonds, de la plus récemment commencée à la plus ancienne.
// Même forme que campagnes_liste() : la liste porte déjà ce qu'il faut pour
// l'afficher, pour que la vue n'ait aucune requête à faire.
function fonds_campagnes_liste(): array
{
    $sql = "SELECT c.*,
                   (SELECT COUNT(*) FROM fonds_demandes d WHERE d.campagne_id = c.id) AS nb_demandes,
                   (SELECT COUNT(*) FROM fonds_demandes d WHERE d.campagne_id = c.id AND d.date_depot <> '') AS nb_deposees,
                   (SELECT COALESCE(SUM(d.montant_demande), 0) FROM fonds_demandes d WHERE d.campagne_id = c.id AND d.date_depot <> '' AND d.montant_accorde = 0) AS montant_en_attente,
                   (SELECT COALESCE(SUM(d.montant_accorde), 0) FROM fonds_demandes d WHERE d.campagne_id = c.id) AS montant_obtenu
              FROM fonds_campagnes c
          ORDER BY c.date_debut DESC, c.id DESC";
    return db()->query($sql)->fetchAll();
}

// La liste des recherches de fonds.
function route_fonds(): void
{
    require_login();
    render('fonds', ['campagnes' => fonds_campagnes_liste()], 'Recherche de fonds');
}

function fonds_campagne_charger(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM fonds_campagnes WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

// Composer une recherche de fonds : ses champs, ses projets, et la sélection
// des bailleurs — qui est le ciblage de structures du booking, au mot près
// (ciblage_structures_preparer(), lib/booking.php). Un bailleur EST une
// structure : il n'y a pas d'autre carnet d'adresses à tenir.
function route_fonds_campagne_form(): void
{
    require_login();
    $id = (int) ($_GET['id'] ?? 0);
    $campagne = $id ? fonds_campagne_charger($id) : null;
    if ($id && !$campagne) {
        redirect('fonds');
    }
    $retenues = [];
    if ($id) {
        $stmt = db()->prepare('SELECT structure_id FROM fonds_demandes WHERE campagne_id = ? ORDER BY id');
        $stmt->execute([$id]);
        $retenues = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    $ciblage = ciblage_structures_preparer($retenues, $_GET);

    // Un entonnoir recharge la page : ce qui vient d'être saisi revient par
    // l'URL, et c'est cette version-là qu'on réaffiche — pas celle de la base.
    $projets = $id ? spectacles_lies('fonds_campagne_spectacles', $id) : [];
    if ($ciblage['previsualise']) {
        $campagne = (array) $campagne + ['id' => $id];
        foreach (['nom', 'date_debut', 'date_fin', 'montant_cible', 'drive_url', 'notes'] as $champ) {
            $campagne[$champ] = trim((string) ($_GET[$champ] ?? ''));
        }
        $campagne['axe_analytique_id'] = ((int) ($_GET['axe_analytique_id'] ?? 0)) ?: null;
        $projets = array_values(array_filter(array_map('intval', (array) ($_GET['spectacle_ids'] ?? []))));
    }

    render('fonds_campagne_form', $ciblage + [
        'campagne'   => $campagne ?: null,
        'projets'    => $projets,
        'spectacles' => module_actif('evenements') ? spectacles_pour_selection() : [],
        'axes'       => module_actif('analytique')
            ? db()->query('SELECT * FROM axes_analytiques WHERE actif = 1 ORDER BY ordre, id')->fetchAll()
            : [],
        'err'        => $_GET['err'] ?? null,
    ], $id ? 'Recherche — ' . $campagne['nom'] : 'Nouvelle recherche de fonds');
}

function route_fonds_campagne_enregistrer(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('fonds');
    }
    check_csrf();
    require_ecriture('fonds');
    $id    = (int) ($_POST['id'] ?? 0);
    $nom   = trim((string) ($_POST['nom'] ?? ''));
    $debut = campagne_date((string) ($_POST['date_debut'] ?? ''));
    $fin   = campagne_date((string) ($_POST['date_fin'] ?? ''));
    if ($nom === '') {
        redirect('fonds_campagne_form', ($id ? ['id' => $id] : []) + ['err' => 'nom']);
    }
    // montant_float() : la saisie tolère « 45'000 » et la virgule décimale,
    // comme partout où l'application lit un montant (lib/compta.php).
    $cible   = montant_float((string) ($_POST['montant_cible'] ?? ''));
    $drive   = trim((string) ($_POST['drive_url'] ?? ''));
    $notes   = trim((string) ($_POST['notes'] ?? ''));
    $projets = (array) ($_POST['spectacle_ids'] ?? []);

    // L'axe est celui du projet financé : on ne le redemande pas si l'écran
    // l'a laissé vide et qu'un projet le porte (migration_91). Choisi
    // explicitement, le choix l'emporte — une recherche peut couvrir deux
    // projets qui ne se ventilent pas au même endroit.
    $axe = ((int) ($_POST['axe_analytique_id'] ?? 0)) ?: null;
    if ($axe === null && $projets) {
        $stmt = db()->prepare('SELECT axe_analytique_id FROM spectacles WHERE id = ? AND axe_analytique_id IS NOT NULL');
        $stmt->execute([(int) reset($projets)]);
        $axe = ((int) $stmt->fetchColumn()) ?: null;
    }

    // Les bailleurs retenus : ce que l'écran a coché, et rien d'autre — on
    // n'infère pas depuis les critères, sinon une décoche manuelle serait
    // perdue au premier enregistrement.
    $structures = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['structure_ids'] ?? [])))));

    db()->beginTransaction();
    $criteres = json_encode(mailing_criteres_vers_url(mailing_criteres_depuis($_POST)), JSON_UNESCAPED_UNICODE);
    if ($id && fonds_campagne_charger($id)) {
        db()->prepare('UPDATE fonds_campagnes SET nom = ?, date_debut = ?, date_fin = ?, montant_cible = ?, axe_analytique_id = ?, drive_url = ?, notes = ?, criteres = ? WHERE id = ?')
            ->execute([$nom, $debut, $fin, $cible, $axe, $drive, $notes, $criteres, $id]);
    } else {
        db()->prepare('INSERT INTO fonds_campagnes (nom, date_debut, date_fin, montant_cible, axe_analytique_id, drive_url, notes, criteres) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$nom, $debut, $fin, $cible, $axe, $drive, $notes, $criteres]);
        $id = (int) db()->lastInsertId();
    }
    spectacles_lier('fonds_campagne_spectacles', $id, $projets);

    // Mise à niveau, et non table rasée : la ligne PORTE le dossier — montants,
    // dates, référence. La supprimer pour la réinsérer effacerait tout le suivi
    // au premier enregistrement ; renommer une recherche suffirait à le perdre.
    $valides = [];
    foreach (lots_ids($structures) as $lot) {
        $stmt = db()->prepare('SELECT id FROM structures WHERE id IN (' . sql_in($lot) . ')');
        $stmt->execute($lot);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $sid) {
            $valides[] = (int) $sid;
        }
    }
    $stmt = db()->prepare('SELECT structure_id FROM fonds_demandes WHERE campagne_id = ?');
    $stmt->execute([$id]);
    $avant = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    // Une demande retirée emporte son dossier : c'est voulu, et c'est pourquoi
    // l'écran demande confirmation avant d'enregistrer une sélection réduite.
    $supp = db()->prepare('DELETE FROM fonds_demandes WHERE campagne_id = ? AND structure_id = ?');
    foreach (array_diff($avant, $valides) as $sid) {
        $supp->execute([$id, $sid]);
    }
    $ins = db()->prepare('INSERT OR IGNORE INTO fonds_demandes (campagne_id, structure_id) VALUES (?, ?)');
    foreach (array_diff($valides, $avant) as $sid) {
        $ins->execute([$id, $sid]);
    }
    db()->commit();
    redirect('fonds', ['ok' => 1]);
}

// Le suivi d'une recherche : où en est chaque dossier, et combien manque-t-il.
function route_fonds_campagne(): void
{
    require_login();
    $id = (int) ($_GET['id'] ?? 0);
    $campagne = fonds_campagne_charger($id);
    if (!$campagne) {
        redirect('fonds');
    }
    $demandes = fonds_campagne_demandes($id);
    render('fonds_campagne', [
        'campagne'    => $campagne,
        'demandes'    => $demandes,
        'repartition' => fonds_repartition($demandes, (float) $campagne['montant_cible']),
        'projets'     => array_map(
            fn (int $sid) => spectacle_chemin($sid, spectacle_map()),
            spectacles_lies('fonds_campagne_spectacles', $id)
        ),
        'ok'          => $_GET['ok'] ?? null,
    ], 'Recherche — ' . $campagne['nom']);
}

// Enregistre UNE ligne du suivi : les montants, les dates, la décision. Le
// reste de la page ne bouge pas — c'est la ligne qu'on vient de remplir.
function route_fonds_demande(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('fonds');
    }
    check_csrf();
    require_ecriture('fonds');
    $id = (int) ($_POST['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM fonds_demandes WHERE id = ?');
    $stmt->execute([$id]);
    $demande = $stmt->fetch();
    if (!$demande) {
        redirect('fonds');
    }
    // La décision ne se devine pas : « refusée » et « abandonnée » se posent à
    // la main, tout le reste se dérive des dates et des montants
    // (fonds_demande_statut()). D'où une liste fermée, et le vide comme défaut.
    $decision = valeur_autorisee((string) ($_POST['statut'] ?? ''), ['refusee', 'abandonnee']);

    db()->prepare(
        'UPDATE fonds_demandes SET statut = ?, montant_demande = ?, montant_accorde = ?,
                date_limite = ?, date_depot = ?, date_reponse = ?,
                date_limite_bilan = ?, date_bilan = ?, reference = ?, pieces_autres = ?, notes = ?
          WHERE id = ?'
    )->execute([
        $decision,
        montant_float((string) ($_POST['montant_demande'] ?? '')),
        montant_float((string) ($_POST['montant_accorde'] ?? '')),
        campagne_date((string) ($_POST['date_limite'] ?? '')),
        campagne_date((string) ($_POST['date_depot'] ?? '')),
        campagne_date((string) ($_POST['date_reponse'] ?? '')),
        campagne_date((string) ($_POST['date_limite_bilan'] ?? '')),
        campagne_date((string) ($_POST['date_bilan'] ?? '')),
        trim((string) ($_POST['reference'] ?? '')),
        trim((string) ($_POST['pieces_autres'] ?? '')),
        trim((string) ($_POST['notes'] ?? '')),
        $id,
    ]);
    redirect('fonds_campagne', ['id' => (int) $demande['campagne_id'], 'ok' => 'demande']);
}
