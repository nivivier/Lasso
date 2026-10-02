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
//     (projets.axe_analytique_id, migration_91).

declare(strict_types=1);

require_once __DIR__ . '/booking.php'; // ciblage_structures_preparer(), campagne_date()
require_once __DIR__ . '/fonds.php';   // les règles : statut dérivé, jauge
require_once __DIR__ . '/compta.php';  // montant_float()

// Ajoute à des lignes de campagne les projets qu'elles financent : leurs
// identifiants, leurs noms, et leurs PASTILLES — l'icône du projet est ce par
// quoi on reconnaît une campagne avant d'en lire le nom, sur la liste comme sur
// le tableau de bord. Une requête pour toutes les campagnes, pas une par ligne.
//
// Isolé ici plutôt que dans fonds_dashboard() (lib/fonds.php) : les pastilles
// viennent du module Événements (projet_map(), lib/evenements.php), et ce
// fichier-là ne doit répondre que des règles de la recherche de fonds.
function fonds_campagnes_avec_projets(array $campagnes): array
{
    if (!$campagnes) {
        return [];
    }
    $map = projet_map();
    $projetsParCampagne = [];
    foreach (db()->query('SELECT campagne_id, projet_id FROM fonds_campagne_projets ORDER BY projet_id') as $l) {
        $projetsParCampagne[(int) $l['campagne_id']][] = (int) $l['projet_id'];
    }
    return array_map(function (array $c) use ($map, $projetsParCampagne): array {
        $projetIds = $projetsParCampagne[(int) $c['id']] ?? [];
        return $c + [
            'projet_ids'        => $projetIds,
            'projets'           => array_map(fn ($sid) => projet_chemin($sid, $map), $projetIds),
            'projets_pastilles' => array_map(fn ($sid) => projet_pastille_html($sid, $map), $projetIds),
        ];
    }, $campagnes);
}

// Les campagnes de recherche de fonds, de la plus récemment commencée à la plus ancienne.
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
    return fonds_campagnes_avec_projets(db()->query($sql)->fetchAll());
}

// La liste des campagnes de recherche de fonds.
function route_fonds_campagnes(): void
{
    require_login();
    // Recherche texte jamais mémorisée en session, comme les autres listes
    // (voir route_booking_campagnes()). Le même filtre que le démarchage, puisque
    // c'est la même question — le nom de la campagne ou celui du projet.
    $recherche = trim((string) ($_GET['q'] ?? ''));
    $toutes = fonds_campagnes_liste();
    $campagnes = array_values(array_filter($toutes, fn (array $c) => campagne_correspond($c, $recherche)));
    render('fonds_campagnes', [
        'groupes'   => fonds_campagnes_groupees($campagnes),
        'vide'      => !$campagnes,
        'nbTotal'   => count($toutes),
        'recherche' => $recherche,
    ], 'Recherche de fonds');
}

function fonds_campagne_charger(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM fonds_campagnes WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

// Composer une campagne de recherche de fonds : ses champs, ses projets, et la sélection
// des bailleurs — qui est le ciblage de structures du booking, au mot près
// (ciblage_structures_preparer(), lib/booking.php). Un bailleur EST une
// structure : il n'y a pas d'autre carnet d'adresses à tenir.
function route_fonds_campagne_form(): void
{
    require_login();
    $id = (int) ($_GET['id'] ?? 0);
    $campagne = $id ? fonds_campagne_charger($id) : null;
    if ($id && !$campagne) {
        redirect('fonds_campagnes');
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
    $projets = $id ? projets_lies('fonds_campagne_projets', $id) : [];
    if ($ciblage['previsualise']) {
        $campagne = (array) $campagne + ['id' => $id];
        foreach (['nom', 'date_debut', 'date_fin', 'montant_minimal', 'montant_ideal', 'drive_url', 'notes'] as $champ) {
            $campagne[$champ] = trim((string) ($_GET[$champ] ?? ''));
        }
        $campagne['axe_analytique_id'] = ((int) ($_GET['axe_analytique_id'] ?? 0)) ?: null;
        $projets = array_values(array_filter(array_map('intval', (array) ($_GET['projet_ids'] ?? []))));
    }

    render('fonds_campagne_form', $ciblage + [
        'campagne'   => $campagne ?: null,
        'projets'    => $projets,
        'projetsDispo' => module_actif('evenements') ? projets_pour_selection() : [],
        'axes'       => module_actif('analytique')
            ? db()->query('SELECT * FROM axes_analytiques WHERE actif = 1 ORDER BY ordre, id')->fetchAll()
            : [],
        'err'        => $_GET['err'] ?? null,
    ], $id ? 'Campagne — ' . $campagne['nom'] : 'Nouvelle campagne de recherche de fonds');
}

function route_fonds_campagne_enregistrer(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('fonds_campagnes');
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
    // montant_float() : la saisie tolère les séparateurs de milliers (« 45'000 »,
    // espaces fines comprises), comme partout où l'application lit un montant
    // (lib/compta.php). Le séparateur décimal reste le point.
    // Deux paliers : le minimum sans lequel le projet ne se fait pas, et ce
    // qu'il faudrait pour le faire comme on le voudrait. L'un et l'autre
    // facultatifs — une campagne peut n'avoir encore chiffré ni l'un ni l'autre.
    $minimal = montant_float((string) ($_POST['montant_minimal'] ?? ''));
    $ideal   = montant_float((string) ($_POST['montant_ideal'] ?? ''));
    $drive   = trim((string) ($_POST['drive_url'] ?? ''));
    $notes   = trim((string) ($_POST['notes'] ?? ''));
    $projets = (array) ($_POST['projet_ids'] ?? []);

    // L'axe est celui du projet financé : on ne le redemande pas si l'écran
    // l'a laissé vide et qu'un projet le porte (migration_91). Choisi
    // explicitement, le choix l'emporte — une campagne peut couvrir deux
    // projets qui ne se ventilent pas au même endroit.
    $axe = ((int) ($_POST['axe_analytique_id'] ?? 0)) ?: null;
    if ($axe === null && $projets) {
        $stmt = db()->prepare('SELECT axe_analytique_id FROM projets WHERE id = ? AND axe_analytique_id IS NOT NULL');
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
        db()->prepare('UPDATE fonds_campagnes SET nom = ?, date_debut = ?, date_fin = ?, montant_minimal = ?, montant_ideal = ?, axe_analytique_id = ?, drive_url = ?, notes = ?, criteres = ? WHERE id = ?')
            ->execute([$nom, $debut, $fin, $minimal, $ideal, $axe, $drive, $notes, $criteres, $id]);
    } else {
        db()->prepare('INSERT INTO fonds_campagnes (nom, date_debut, date_fin, montant_minimal, montant_ideal, axe_analytique_id, drive_url, notes, criteres) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$nom, $debut, $fin, $minimal, $ideal, $axe, $drive, $notes, $criteres]);
        $id = (int) db()->lastInsertId();
    }
    projets_lier('fonds_campagne_projets', $id, $projets);

    // Mise à niveau, et non table rasée : la ligne PORTE le dossier — montants,
    // dates, référence. La supprimer pour la réinsérer effacerait tout le suivi
    // au premier enregistrement ; renommer une campagne suffirait à le perdre.
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
    redirect('fonds_campagnes', ['ok' => 1]);
}

// Le suivi d'une campagne : où en est chaque dossier, et combien manque-t-il.
function route_fonds_campagne(): void
{
    require_login();
    $id = (int) ($_GET['id'] ?? 0);
    $campagne = fonds_campagne_charger($id);
    if (!$campagne) {
        redirect('fonds_campagnes');
    }
    $demandes = fonds_campagne_demandes($id);
    $projetIds = projets_lies('fonds_campagne_projets', $id);
    $mapProjets = projet_map();
    render('fonds_campagne', [
        'campagne'    => $campagne,
        'demandes'    => $demandes,
        'repartition' => fonds_repartition($demandes, (float) $campagne['montant_minimal'], (float) $campagne['montant_ideal']),
        // Les projets financés, nommés ET en pastilles : la carte de tête les
        // montre comme celle d'une campagne de démarchage — l'icône d'abord,
        // c'est par elle qu'on reconnaît la campagne.
        'projets'     => array_map(fn (int $sid) => projet_chemin($sid, $mapProjets), $projetIds),
        'projetsPastilles' => array_map(fn (int $sid) => projet_pastille_html($sid, $mapProjets), $projetIds),
        'ok'          => $_GET['ok'] ?? null,
    ], 'Campagne — ' . $campagne['nom']);
}

// Enregistre UN dossier : les montants, les dates, la décision. Appelée aussi
// bien depuis la ligne du suivi que depuis la fiche du dossier — même
// formulaire, mêmes champs, et le retour ramène d'où l'on vient.
function route_fonds_demande_enregistrer(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('fonds_campagnes');
    }
    check_csrf();
    require_ecriture('fonds');
    $id = (int) ($_POST['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM fonds_demandes WHERE id = ?');
    $stmt->execute([$id]);
    $demande = $stmt->fetch();
    if (!$demande) {
        redirect('fonds_campagnes');
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
    // Retour là où l'on était : la fiche du dossier si l'on y était, le suivi
    // de la campagne sinon.
    if (($_POST['retour'] ?? '') === 'demande') {
        redirect('fonds_demande', ['id' => $id, 'ok' => 1]);
    }
    redirect('fonds_campagne', ['id' => (int) $demande['campagne_id'], 'ok' => 'demande']);
}

// La fiche d'un dossier : tout ce qu'on sait de cette demande-là, et ce que ce
// bailleur exige — réglable ici, depuis n'importe lequel de ses dossiers.
function route_fonds_demande(): void
{
    require_login();
    $id = (int) ($_GET['id'] ?? 0);
    $demande = fonds_demande_charger($id);
    if (!$demande) {
        redirect('fonds_campagnes');
    }
    $sid = (int) $demande['structure_id'];
    render('fonds_demande', [
        'demande'    => $demande,
        'catalogue'  => fonds_pieces_catalogue(),
        'pieces'     => fonds_bailleur_pieces($sid),
        'versement'  => fonds_versement_de($id),
        'ecritures'  => fonds_ecritures_rapprochables($id),
        // La facture au bailleur, s'il y en a une — et seulement si la
        // facturation est là pour la montrer.
        'facture'    => module_accessible('facturation') ? fonds_facture_de($id) : null,
        'historique' => historique_fusionne('structure', $sid),
        'ok'         => $_GET['ok'] ?? null,
    ], 'Dossier — ' . $demande['structure_nom']);
}

// Le versement d'un dossier : ce qui est arrivé sur le compte, et l'écriture
// bancaire qui le prouve.
function route_fonds_versement_enregistrer(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('fonds_campagnes');
    }
    check_csrf();
    require_ecriture('fonds');
    $id = (int) ($_POST['id'] ?? 0);
    if (!fonds_demande_charger($id)) {
        redirect('fonds_campagnes');
    }
    fonds_versement_enregistrer($id, [
        'montant'     => montant_float((string) ($_POST['montant'] ?? '')),
        'date_prevue' => campagne_date((string) ($_POST['date_prevue'] ?? '')),
        'date_recue'  => campagne_date((string) ($_POST['date_recue'] ?? '')),
        'ecriture_id' => (int) ($_POST['ecriture_id'] ?? 0),
        'notes'       => (string) ($_POST['notes'] ?? ''),
    ]);
    redirect('fonds_demande', ['id' => $id, 'ok' => 'versement']);
}

// Ce qu'un bailleur exige, réglé depuis l'un de ses dossiers. Vaut pour TOUS
// ses dossiers, présents et à venir : c'est lui qui l'exige, pas la campagne.
function route_fonds_bailleur_pieces_enregistrer(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('fonds_campagnes');
    }
    check_csrf();
    require_ecriture('fonds');
    $id = (int) ($_POST['id'] ?? 0);
    $demande = fonds_demande_charger($id);
    if (!$demande) {
        redirect('fonds_campagnes');
    }
    $sid = (int) $demande['structure_id'];
    fonds_bailleur_pieces_enregistrer($sid, [
        'demande' => (array) ($_POST['pieces_demande'] ?? []),
        'bilan'   => (array) ($_POST['pieces_bilan'] ?? []),
    ]);
    db()->prepare('UPDATE structures SET fonds_pieces_autres = ? WHERE id = ?')
        ->execute([trim((string) ($_POST['fonds_pieces_autres'] ?? '')), $sid]);
    redirect('fonds_demande', ['id' => $id, 'ok' => 'pieces']);
}

// Ranger un bailleur dans une campagne de recherche de fonds, depuis la colonne
// du même nom sur la liste des structures. Le pendant de
// route_booking_campagne_structure() (lib/routes_booking.php), au retrait près : on
// n'en retire pas d'ici. Un dossier porte des montants, des dates et un
// versement — le défaire d'un clic dans une ligne de liste effacerait tout cela
// sans rien montrer. Il se retire depuis le suivi de la campagne, où l'on voit
// ce qu'on efface.
function route_fonds_campagne_structure_ajouter(): void
{
    require_login();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('structures', ['depuis' => 'fonds']);
    }
    check_csrf();
    require_ecriture('fonds');
    $structureId = (int) ($_POST['structure_id'] ?? 0);
    $campagneId  = (int) ($_POST['campagne_id'] ?? 0);
    $campagne = fonds_campagne_charger($campagneId);
    $stmt = db()->prepare('SELECT nom FROM structures WHERE id = ?');
    $stmt->execute([$structureId]);
    $nomStructure = (string) ($stmt->fetchColumn() ?: '');

    // INSERT OR IGNORE : l'index unique (campagne_id, structure_id) dit qu'un
    // bailleur n'a qu'un dossier par campagne (SPEC_SUBVENTIONS.md § 9.4). Un
    // second clic ne doit donc pas échouer, il ne doit rien faire.
    if ($campagne && $nomStructure !== '') {
        $ins = db()->prepare('INSERT OR IGNORE INTO fonds_demandes (campagne_id, structure_id) VALUES (?, ?)');
        $ins->execute([$campagneId, $structureId]);
        // Journalisé seulement si une ligne a VRAIMENT été créée : un second
        // clic ne doit pas poser une seconde entrée dans l'historique du
        // bailleur pour un dossier qui existait déjà.
        if ($ins->rowCount() > 0) {
            journaliser('structure', $structureId, 'edition', 'Sollicitée dans la campagne de recherche de fonds : ' . $campagne['nom']);
        }
    }
    // Même convention que les étiquettes et le démarchage : en JSON quand le
    // JavaScript est là, pour ne remplacer que la cellule d'une liste qui pèse
    // plusieurs mégaoctets.
    if (($_POST['retour'] ?? '') === 'json') {
        header('Content-Type: application/json');
        echo json_encode([
            'ok'   => true,
            'html' => fonds_campagnes_cellule_html(
                $structureId,
                fonds_structures_campagnes([$structureId])[$structureId] ?? [],
                peut_ecrire('fonds')
            ),
        ]);
        return;
    }
    // Sans JavaScript : on revient d'où l'on vient — la liste des structures,
    // vue par la recherche de fonds.
    redirect('structures', ['depuis' => 'fonds']);
}
