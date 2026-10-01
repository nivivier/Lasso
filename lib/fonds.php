<?php
// Module « Recherche de fonds » — les règles, sans les écrans.
// Le besoin et les décisions : SPEC_SUBVENTIONS.md. Les routes vivent dans
// lib/routes_fonds.php ; ce fichier-ci ne contient que ce qui se décide, de
// préférence sans toucher la base — c'est ce qui le rend testable
// (tests/fonds_test.php), comme calc.php l'est pour la paie.

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

// Le cycle d'un dossier, dans l'ordre où il se vit. Deux temps, pas un : on
// dépose, on obtient — puis on rend un bilan, à une date que le bailleur fixe.
// C'est la seconde échéance qu'on oublie, une fois l'argent reçu.
const FONDS_STATUTS = [
    'a_preparer'    => 'À préparer',
    'en_retard'     => 'Dépôt en retard',
    'deposee'       => 'Déposée',
    'accordee'      => 'Accordée',
    'partielle'     => 'Accordée en partie',
    'refusee'       => 'Refusée',
    'abandonnee'    => 'Abandonnée',
    'bilan_a_rendre' => 'Bilan à rendre',
    'bilan_retard'  => 'Bilan en retard',
    'soldee'        => 'Soldée',
];

// La couleur de chaque état, prise au vocabulaire de l'application : teal pour
// ce qui est acquis, ambre pour ce qui attend un geste, rouge pour ce qui a
// dépassé sa date ou s'est refermé, gris pour ce qui ne demande rien.
const FONDS_STATUTS_CLASSES = [
    'a_preparer'     => 'warn',
    'en_retard'      => 'err',
    'deposee'        => 'muted',
    'accordee'       => 'ok',
    'partielle'      => 'ok',
    'refusee'        => 'err',
    'abandonnee'     => 'muted',
    'bilan_a_rendre' => 'warn',
    'bilan_retard'   => 'err',
    'soldee'         => 'ok',
];

// L'état d'un dossier, DÉRIVÉ de ses dates et de ses montants — jamais stocké,
// comme le statut d'une campagne ou le « en retard » d'une facture. Une seule
// règle, donc un seul endroit où la corriger.
//
// L'ordre des tests EST la règle de priorité :
//   abandonné          décidé à la main, il prime sur tout le reste ;
//   refusé             la réponse est tombée, il n'y a plus de bilan à rendre ;
//   accordé            puis, DANS cet état, la question du bilan ;
//   déposé             en attente d'une réponse ;
//   à préparer         rien n'est parti — et si la date limite est passée,
//                      c'est le seul moment où « en retard » veut dire
//                      quelque chose : après le dépôt, le retard n'est plus
//                      le nôtre.
//
// $aujourdhui : injectée pour que la fonction reste pure et testable.
function fonds_demande_statut(array $d, string $aujourdhui = ''): string
{
    $aujourdhui = $aujourdhui !== '' ? $aujourdhui : date('Y-m-d');
    $decision = (string) ($d['statut'] ?? '');
    if ($decision === 'abandonnee') {
        return 'abandonnee';
    }
    if ($decision === 'refusee') {
        return 'refusee';
    }

    $accorde = (float) ($d['montant_accorde'] ?? 0);
    $demande = (float) ($d['montant_demande'] ?? 0);
    if ($accorde > 0) {
        $limiteBilan = trim((string) ($d['date_limite_bilan'] ?? ''));
        $bilanRendu  = trim((string) ($d['date_bilan'] ?? '')) !== '';
        if (!$bilanRendu && $limiteBilan !== '') {
            return $limiteBilan < $aujourdhui ? 'bilan_retard' : 'bilan_a_rendre';
        }
        if ($bilanRendu) {
            return 'soldee';
        }
        // Accordée pour moins que demandé : l'écart se lit tout seul, mais il
        // mérite son mot — c'est lui qui dit qu'il reste à trouver ailleurs.
        return ($demande > 0 && $accorde < $demande) ? 'partielle' : 'accordee';
    }

    if (trim((string) ($d['date_depot'] ?? '')) !== '') {
        return 'deposee';
    }
    $limite = trim((string) ($d['date_limite'] ?? ''));
    return ($limite !== '' && $limite < $aujourdhui) ? 'en_retard' : 'a_preparer';
}

// Les trois parts de la jauge d'une recherche, EN FRANCS — c'est là que ce
// module cesse d'être une campagne de démarchage : ce qu'on suit n'est pas un
// nombre d'interlocuteurs, c'est un budget qui se remplit.
//
//   obtenu     ce qui est accordé, quel que soit l'état du bilan ;
//   en attente ce qui est demandé et pas encore tranché ;
//   à trouver  ce qui manque pour atteindre la cible — zéro si on y est.
//
// Sans cible renseignée, la jauge se cale sur ce qui est en jeu (obtenu +
// attente) : une barre pleine dit alors « tout est joué », pas « c'est gagné ».
function fonds_repartition(array $demandes, float $cible, string $aujourdhui = ''): array
{
    $obtenu = 0.0;
    $attente = 0.0;
    foreach ($demandes as $d) {
        $statut = fonds_demande_statut($d, $aujourdhui);
        if (in_array($statut, ['refusee', 'abandonnee'], true)) {
            continue;
        }
        $accorde = (float) ($d['montant_accorde'] ?? 0);
        if ($accorde > 0) {
            $obtenu += $accorde;
            continue;
        }
        if (trim((string) ($d['date_depot'] ?? '')) !== '') {
            $attente += (float) ($d['montant_demande'] ?? 0);
        }
    }
    $base = $cible > 0 ? $cible : $obtenu + $attente;
    return [
        'obtenu'   => r2($obtenu),
        'attente'  => r2($attente),
        'aTrouver' => r2(max(0, $base - $obtenu - $attente)),
        'base'     => r2($base),
    ];
}

// La barre de la jauge : mêmes segments et mêmes couleurs que partout ailleurs
// (barre_segments_html(), lib/booking.php) — teal pour l'acquis, ambre pour ce
// qui attend, et ce qui reste à trouver EST la piste.
function fonds_barre_html(array $parts, string $classe = ''): string
{
    $titre = chf($parts['obtenu']) . ' obtenu, ' . chf($parts['attente']) . ' en attente, '
           . chf($parts['aTrouver']) . ' à trouver';
    return barre_segments_html([
        'obtenu'  => ['camp-oui', $parts['obtenu']],
        'attente' => ['camp-attente', $parts['attente']],
    ], (float) $parts['base'], $titre, $classe);
}

// Les dossiers d'une recherche, avec le nom du bailleur et de quoi le joindre.
// Une requête, pas une par ligne : le tableau en montre vingt.
function fonds_campagne_demandes(int $campagneId): array
{
    $stmt = db()->prepare(
        'SELECT d.*, s.nom AS structure_nom, s.adresse_localite, s.adresse_pays,
                ' . structure_email_sql('s.id') . ' AS email_affiche,
                ' . structure_formulaire_sql('s.id') . ' AS formulaire_affiche
           FROM fonds_demandes d
           JOIN structures s ON s.id = d.structure_id
          WHERE d.campagne_id = ?
       ORDER BY d.date_limite = \'\', d.date_limite, s.nom'
    );
    $stmt->execute([$campagneId]);
    return $stmt->fetchAll();
}

// --- Les pièces qu'un bailleur exige ----------------------------------------
//
// Elles appartiennent au BAILLEUR, pas à la campagne : la même fondation
// demande les mêmes documents d'une année sur l'autre, et les redemander à
// chaque campagne serait de la ressaisie. On les règle donc depuis n'importe
// lequel de ses dossiers, et elles valent pour tous.
//
// Deux moments, parce que ce n'est pas la même liste à préparer : ce qu'il faut
// pour DÉPOSER, et ce qu'il faut pour rendre le BILAN. « Comptes vérifiés » se
// demande souvent aux deux.
const FONDS_MOMENTS = [
    'demande' => 'Pour déposer',
    'bilan'   => 'Pour le bilan',
];

// Le catalogue, dans son ordre d'affichage. Semé par la migration 92 avec les
// trois pièces du quotidien ; une quatrième s'ajoute en base, sans migration.
function fonds_pieces_catalogue(): array
{
    return db()->query('SELECT * FROM fonds_pieces ORDER BY ordre, id')->fetchAll();
}

// Ce que CE bailleur exige : [moment => [piece_id, …]].
function fonds_bailleur_pieces(int $structureId): array
{
    $out = array_fill_keys(array_keys(FONDS_MOMENTS), []);
    $stmt = db()->prepare('SELECT piece_id, moment FROM fonds_bailleur_pieces WHERE structure_id = ?');
    $stmt->execute([$structureId]);
    foreach ($stmt->fetchAll() as $l) {
        $moment = (string) $l['moment'];
        if (isset($out[$moment])) {
            $out[$moment][] = (int) $l['piece_id'];
        }
    }
    return $out;
}

// Enregistre ce qu'un bailleur exige. Table rasée puis réécrite : la ligne ne
// porte rien d'autre que le fait d'être cochée — contrairement au lien
// campagne↔bailleur, qui porte tout un dossier et se met donc à niveau.
//
// $coches : [moment => [piece_id, …]], filtré contre le catalogue réel — un
// identifiant forgé violerait la clé étrangère au lieu d'être ignoré.
function fonds_bailleur_pieces_enregistrer(int $structureId, array $coches): void
{
    $connues = array_map(fn ($p) => (int) $p['id'], fonds_pieces_catalogue());
    db()->beginTransaction();
    db()->prepare('DELETE FROM fonds_bailleur_pieces WHERE structure_id = ?')->execute([$structureId]);
    $ins = db()->prepare('INSERT OR IGNORE INTO fonds_bailleur_pieces (structure_id, piece_id, moment) VALUES (?, ?, ?)');
    foreach (FONDS_MOMENTS as $moment => $_) {
        foreach (array_map('intval', (array) ($coches[$moment] ?? [])) as $pieceId) {
            if (in_array($pieceId, $connues, true)) {
                $ins->execute([$structureId, $pieceId, $moment]);
            }
        }
    }
    db()->commit();
}

// Un dossier avec tout ce qu'il faut pour l'afficher : le bailleur, la campagne
// à laquelle il appartient, et de quoi joindre l'un comme l'autre.
function fonds_demande_charger(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT d.*, s.nom AS structure_nom, s.fonds_pieces_autres,
                c.nom AS campagne_nom, c.drive_url, c.montant_cible,
                ' . structure_formulaire_sql('s.id') . ' AS formulaire_affiche
           FROM fonds_demandes d
           JOIN structures s ON s.id = d.structure_id
           JOIN fonds_campagnes c ON c.id = d.campagne_id
          WHERE d.id = ?'
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

// --- Ce que le tableau de bord montre ---------------------------------------

// Deux choses, parce qu'il y a deux échéances dans la vie d'une subvention :
//
//   campagnes  celles dont la saison court, avec leur jauge — de quoi voir
//              d'un coup d'œil ce qu'il reste à déposer ;
//   bilans     les dossiers dont le bilan est dû, le plus pressé d'abord.
//              C'est la seconde échéance, celle qu'on oublie une fois l'argent
//              reçu — la première, personne ne l'oublie, elle apporte l'argent.
//
// $max borne chaque liste : une carte de tableau de bord ne s'étire pas.
function fonds_dashboard(int $max = 5, string $aujourdhui = ''): array
{
    $aujourdhui = $aujourdhui !== '' ? $aujourdhui : date('Y-m-d');
    $campagnes = [];
    foreach (db()->query('SELECT * FROM fonds_campagnes ORDER BY date_debut, id') as $c) {
        if (!periode_courante($c, $aujourdhui)) {
            continue;
        }
        $demandes = fonds_campagne_demandes((int) $c['id']);
        $deposees = 0;
        foreach ($demandes as $d) {
            if (trim((string) $d['date_depot']) !== '') {
                $deposees++;
            }
        }
        $campagnes[] = $c + [
            'repartition' => fonds_repartition($demandes, (float) $c['montant_cible'], $aujourdhui),
            'nb_total'    => count($demandes),
            'nb_deposees' => $deposees,
        ];
    }

    // Les bilans dus : accordés, pas encore rendus, avec une date connue. Le
    // tri met en tête ce qui est déjà en retard, puis ce qui vient.
    $stmt = db()->prepare(
        "SELECT d.*, s.nom AS structure_nom, c.nom AS campagne_nom
           FROM fonds_demandes d
           JOIN structures s ON s.id = d.structure_id
           JOIN fonds_campagnes c ON c.id = d.campagne_id
          WHERE d.montant_accorde > 0 AND d.date_bilan = '' AND d.date_limite_bilan <> ''
            AND d.statut NOT IN ('refusee', 'abandonnee')
       ORDER BY d.date_limite_bilan"
    );
    $stmt->execute();
    $bilans = $stmt->fetchAll();

    return [
        'campagnes'      => array_slice($campagnes, 0, $max),
        'nbCampagnes'    => count($campagnes),
        'bilans'         => array_slice($bilans, 0, $max),
        'nbBilans'       => count($bilans),
    ];
}
