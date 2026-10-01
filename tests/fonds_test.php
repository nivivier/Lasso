<?php
// Tests du module « Recherche de fonds ». Lancement : php tests/fonds_test.php
// N'utilise pas la base de l'application : seulement les fonctions pures de
// lib/fonds.php — le statut d'un dossier, qui se DÉRIVE de ses dates et de ses
// montants, et la jauge en francs. Les deux tiennent à des comparaisons de
// dates et à des règles de priorité, c'est-à-dire exactement ce qui se casse
// sans bruit. La date du jour est injectée, jamais lue : un jeu de dates figé
// rendrait ce fichier faux l'année prochaine.

require_once __DIR__ . '/../lib/fonds.php';

$tests = 0;
$fails = 0;
function check(string $label, $attendu, $obtenu): void
{
    global $tests, $fails;
    $tests++;
    $ok = $attendu === $obtenu;
    if (!$ok) {
        $fails++;
        printf("  FAIL  %-56s attendu %s, obtenu %s\n", $label, var_export($attendu, true), var_export($obtenu, true));
    } else {
        printf("  ok    %s\n", $label);
    }
}

$auj = '2026-10-01';
$st = fn (array $d): string => fonds_demande_statut($d, $auj);

echo "1) Statut d'un dossier — avant le dépôt\n";
check('rien de posé', 'a_preparer', $st([]));
check('date limite à venir', 'a_preparer', $st(['date_limite' => '2026-12-01']));
check('date limite passée, rien déposé', 'en_retard', $st(['date_limite' => '2026-09-01']));
check('le jour même n\'est pas en retard', 'a_preparer', $st(['date_limite' => $auj]));

echo "\n2) Statut d'un dossier — après le dépôt\n";
// Passé le dépôt, le retard n'est plus le nôtre : c'est le bailleur qui tarde.
check('déposée, date limite passée', 'deposee', $st(['date_limite' => '2026-09-01', 'date_depot' => '2026-08-20']));
check('déposée, sans réponse', 'deposee', $st(['date_depot' => '2026-08-20', 'montant_demande' => 20000]));
check('accordée en entier', 'accordee', $st(['date_depot' => '2026-08-20', 'montant_demande' => 20000, 'montant_accorde' => 20000]));
check('accordée pour plus que demandé', 'accordee', $st(['montant_demande' => 10000, 'montant_accorde' => 12000]));
check('accordée en partie', 'partielle', $st(['date_depot' => '2026-08-20', 'montant_demande' => 20000, 'montant_accorde' => 12000]));
check('sans montant demandé, pas de « partielle »', 'accordee', $st(['montant_accorde' => 12000]));

echo "\n3) Le second cycle : le bilan\n";
check('bilan dû plus tard', 'bilan_a_rendre', $st(['montant_accorde' => 12000, 'date_limite_bilan' => '2026-12-31']));
check('bilan dû hier', 'bilan_retard', $st(['montant_accorde' => 12000, 'date_limite_bilan' => '2026-09-15']));
check('bilan dû aujourd\'hui : pas encore en retard', 'bilan_a_rendre', $st(['montant_accorde' => 12000, 'date_limite_bilan' => $auj]));
check('bilan transmis', 'soldee', $st(['montant_accorde' => 12000, 'date_limite_bilan' => '2026-09-15', 'date_bilan' => '2026-09-10']));
check('bilan transmis sans date limite connue', 'soldee', $st(['montant_accorde' => 12000, 'date_bilan' => '2026-09-10']));
// Sans date limite ET sans bilan rendu, rien n'est dû : le bailleur n'en
// demande pas. L'état reste celui de l'octroi.
check('aucun bilan attendu', 'accordee', $st(['montant_accorde' => 12000, 'montant_demande' => 12000]));

echo "\n4) Les deux décisions qui priment sur tout\n";
check('refusée, même déposée', 'refusee', $st(['statut' => 'refusee', 'date_depot' => '2026-08-20']));
check('refusée l\'emporte sur un montant accordé resté là', 'refusee', $st(['statut' => 'refusee', 'montant_accorde' => 5000]));
check('abandonnée', 'abandonnee', $st(['statut' => 'abandonnee']));
check('abandonnée l\'emporte sur une date limite passée', 'abandonnee', $st(['statut' => 'abandonnee', 'date_limite' => '2026-01-01']));

echo "\n5) La jauge se compte en francs\n";
$dossiers = [
    ['montant_accorde' => 12000, 'montant_demande' => 15000, 'date_depot' => '2026-08-01'],
    ['montant_accorde' => 0,     'montant_demande' => 20000, 'date_depot' => '2026-09-01'],
    ['montant_accorde' => 0,     'montant_demande' => 10000, 'date_depot' => ''],
    ['statut' => 'refusee',      'montant_demande' => 8000,  'date_depot' => '2026-07-01'],
];
$parts = fonds_repartition($dossiers, 45000.0, $auj);
check('obtenu : ce qui est accordé', 12000.0, $parts['obtenu']);
check('en attente : le demandé des dossiers déposés sans réponse', 20000.0, $parts['attente']);
check('un dossier pas encore déposé ne compte pas', 13000.0, $parts['aTrouver']);
check('la base est la cible', 45000.0, $parts['base']);

$refus = fonds_repartition([['statut' => 'refusee', 'montant_demande' => 8000, 'date_depot' => '2026-07-01']], 10000.0, $auj);
check('un refus ne reste pas « en attente »', 0.0, $refus['attente']);
check('…et tout reste à trouver', 10000.0, $refus['aTrouver']);

// Sans cible, la jauge se cale sur ce qui est en jeu : pleine, elle dit « tout
// est joué », pas « c'est gagné ».
$sansCible = fonds_repartition($dossiers, 0.0, $auj);
check('sans cible, la base est obtenu + attente', 32000.0, $sansCible['base']);
check('sans cible, rien « à trouver »', 0.0, $sansCible['aTrouver']);

$vide = fonds_repartition([], 0.0, $auj);
check('aucun dossier : base nulle, pas de division par zéro', 0.0, $vide['base']);
// Les deux segments retombent à zéro plutôt que de diviser par zéro.
check('barre sans base : deux segments à zéro pour cent', 2, substr_count(fonds_barre_html($vide), 'width:0%'));

echo "\n6) La barre reprend les segments de l'application\n";
$barre = fonds_barre_html($parts);
check('segment « obtenu » à 26.67 %', 1, substr_count($barre, 'data-part="obtenu" style="width:26.67%"'));
check('segment « en attente » à 44.44 %', 1, substr_count($barre, 'data-part="attente" style="width:44.44%"'));
check('ce qui reste à trouver EST la piste, pas un segment', 2, substr_count($barre, 'camp-seg'));
// chf() sépare les milliers par une espace insécable fine : on compare donc à
// ce que chf() rend, jamais à une chaîne écrite à la main.
check('la légende dit les trois montants', 1, substr_count($barre,
    'title="' . chf(12000.0) . ' obtenu, ' . chf(20000.0) . ' en attente, ' . chf(13000.0) . ' à trouver"'));

echo "\n$tests tests, $fails échec(s)\n";
exit($fails > 0 ? 1 : 0);
