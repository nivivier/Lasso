<?php
/** @var array $aPayer */ /** @var array $facturesEmises */ /** @var array $comptaSeries */
/** @var array $prochainsEvenements */

// Génère le SVG du graphique comptable (inline, sans bibliothèque).
$dash_svg = function (array $series): string {
    if (count($series) < 1) return '';

    $annees = array_keys($series); // ordre chrono
    $n      = count($annees);

    // Dimensions SVG
    $W = 600; $H = 390;
    $ml = 62; $mr = 16; $mt = 16; $mb = 42;
    $pw = $W - $ml - $mr;
    $ph = $H - $mt - $mb;

    // Plage de valeurs
    $allVals = [];
    foreach ($series as $s) {
        $allVals[] = $s['produits']; $allVals[] = $s['charges'];
        $allVals[] = $s['resultat']; $allVals[] = $s['patrimoine'];
    }
    $vmin = min(0.0, min($allVals));
    $vmax = max(0.0, max($allVals));
    if ($vmax <= $vmin) $vmax = $vmin + 1.0;

    // Pas « joli » pour la grille Y (cible ~5 lignes)
    $range  = $vmax - $vmin;
    $rough  = $range / 5;
    $pow10  = pow(10, floor(log10(max(1.0, abs($rough)))));
    $nice   = $rough / $pow10;
    $step   = $nice <= 1 ? 1 : ($nice <= 2 ? 2 : ($nice <= 5 ? 5 : 10));
    $step  *= $pow10;
    $gmin   = floor($vmin / $step) * $step;
    $gmax   = ceil($vmax  / $step) * $step;
    if ($gmax <= $gmin) $gmax = $gmin + $step;

    // Coordonnées
    $xOf = fn(int $i): float => $ml + ($n > 1 ? $pw / ($n - 1) * $i : $pw / 2);
    $yOf = fn(float $v): float => $mt + $ph - ($v - $gmin) / ($gmax - $gmin) * $ph;

    $pts = function (string $key) use ($series, $annees, $n, $xOf, $yOf): string {
        $out = [];
        foreach ($annees as $i => $a) {
            $out[] = round($xOf($i), 1) . ',' . round($yOf($series[$a][$key]), 1);
        }
        return implode(' ', $out);
    };

    $fmtY = function (float $v): string {
        $abs = abs($v);
        if ($abs >= 1000) return ($v < 0 ? '−' : '') . number_format($abs / 1000, $abs < 10000 ? 1 : 0, '.', '') . 'k';
        return ($v < 0 ? '−' : '') . number_format($abs, 0, '.', '');
    };

    $o = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" xmlns="http://www.w3.org/2000/svg"'
       . ' class="dash-chart" aria-label="Évolution comptable" role="img">';

    // Grille horizontale
    for ($v = $gmin; $v <= $gmax + $step * 0.01; $v += $step) {
        $y   = round($yOf((float) $v), 1);
        $col = abs($v) < 0.01 ? '#a0a0c0' : '#e8e9f1';
        $w   = abs($v) < 0.01 ? '1.5'     : '1';
        $o  .= '<line x1="' . $ml . '" y1="' . $y . '" x2="' . ($W - $mr) . '" y2="' . $y
             . '" stroke="' . $col . '" stroke-width="' . $w . '"/>';
        $o  .= '<text x="' . ($ml - 6) . '" y="' . ($y + 4) . '" text-anchor="end"'
             . ' font-size="12" fill="#6b7280" font-family="inherit">' . $fmtY((float) $v) . '</text>';
    }

    // Étiquettes X (années)
    foreach ($annees as $i => $a) {
        $x  = round($xOf($i), 1);
        $o .= '<text x="' . $x . '" y="' . ($H - $mb + 16) . '" text-anchor="middle"'
            . ' font-size="12" fill="#6b7280" font-family="inherit">' . (int) $a . '</text>';
        // Tick vertical
        $o .= '<line x1="' . $x . '" y1="' . ($mt + $ph) . '" x2="' . $x . '" y2="' . ($mt + $ph + 4)
            . '" stroke="#e8e9f1" stroke-width="1"/>';
    }

    // Séries — ordre : patrimoine (dessous), produits, charges, résultat (dessus).
    // Couleur/libellé définis une seule fois ici ; la légende plus bas les
    // réutilise (pas de deuxième copie à tenir à jour). Patrimoine/Résultat
    // suivent la couleur principale/de marque choisie par l'employeur
    // (couleurs_derivees()) ; Recettes/Dépenses restent sur la palette fixe
    // teal/danger, non personnalisable.
    $couleurs = couleurs_derivees((string) param('employeur_couleur_principale', '#6d4ade'));
    $series_def = [
        'patrimoine' => ['label' => 'Patrimoine', 'color' => $couleurs['primary'], 'dash' => '',    'width' => '2'],
        'produits'   => ['label' => 'Recettes',   'color' => '#0c9486',            'dash' => '',    'width' => '2'],
        'charges'    => ['label' => 'Dépenses',   'color' => '#e0473c',            'dash' => '',    'width' => '2'],
        'resultat'   => ['label' => 'Résultat',   'color' => $couleurs['brand'],   'dash' => '6,3', 'width' => '2'],
    ];
    if ($n > 1) {
        foreach ($series_def as $key => $s) {
            $dash = $s['dash'] !== '' ? ' stroke-dasharray="' . $s['dash'] . '"' : '';
            $o   .= '<polyline points="' . $pts($key) . '" fill="none"'
                  . ' stroke="' . $s['color'] . '" stroke-width="' . $s['width'] . '"'
                  . ' stroke-linejoin="round" stroke-linecap="round"' . $dash . '/>';
        }
    }

    // Points sur chaque série
    foreach ($series_def as $key => $s) {
        foreach ($annees as $i => $a) {
            $cx = round($xOf($i), 1);
            $cy = round($yOf($series[$a][$key]), 1);
            $o .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="3" fill="' . $s['color'] . '"/>';
        }
    }

    // Légende (bas, centrée) — ordre d'affichage propre à la légende, mêmes
    // couleurs/libellés que $series_def.
    $items = array_map(fn ($key) => [$series_def[$key]['label'], $series_def[$key]['color'], $series_def[$key]['dash']],
        ['produits', 'charges', 'resultat', 'patrimoine']);
    $lx = $ml; $ly = $H - 10;
    $gap = ($W - $ml - $mr) / count($items);
    foreach ($items as $idx => [$label, $col, $dash]) {
        $x = $ml + $gap * $idx + $gap / 2;
        $da = $dash !== '' ? ' stroke-dasharray="' . $dash . '"' : '';
        $o .= '<line x1="' . ($x - 14) . '" y1="' . $ly . '" x2="' . ($x - 2) . '" y2="' . $ly
            . '" stroke="' . $col . '" stroke-width="2"' . $da . '/>';
        $o .= '<circle cx="' . ($x - 8) . '" cy="' . $ly . '" r="2.5" fill="' . $col . '"/>';
        $o .= '<text x="' . $x . '" y="' . ($ly + 4) . '" font-size="12" fill="#6b7280"'
            . ' font-family="inherit">' . $label . '</text>';
    }

    $o .= '</svg>';
    return $o;
};
?>
<div class="page-head"><h1>Tableau de bord</h1></div>

<?php
// Dérivé des mêmes conditions que chaque widget ci-dessous (pas une liste à
// part) : un widget ajouté/retiré ne peut pas désynchroniser ce garde-fou.
$dashComptaActif = module_actif('compta') && count($comptaSeries) >= 1;
$dashModuleActif = $dashComptaActif || module_actif('salaires') || module_actif('facturation') || module_actif('evenements');
?>
<?php if (!$dashModuleActif): ?>
    <p class="muted">Aucun module actif n'alimente le tableau de bord pour l'instant. Active
    des modules dans <a href="?p=parametres_modules">Paramètres → Modules</a>.</p>
<?php else: ?>
<div class="dash-cols">
    <div class="dash-col">
    
    <?php if (module_actif('evenements')): ?>
        <div>
            <h2 class="mt-0">Prochains événements</h2>
            <?php if (!$prochainsEvenements): ?>
                <p class="muted">Aucun événement à venir.</p>
            <?php else: ?>
            <table class="list">
                <thead>
                    <tr><th>Date</th><th><?= e(evenements_terme_spectacle(false)) ?></th><th>Lieu</th><th>Salle</th></tr>
                </thead>
                <tbody>
                <?php foreach ($prochainsEvenements as $ev):
                    $drapeau = pays_drapeau((string) $ev['pays']);
                    $lieu = trim($ev['ville'] . ($ev['region'] !== '' ? ' (' . $ev['region'] . ')' : ''));
                    $salleFestival = implode(', ', array_filter([$ev['salle'], $ev['festival']], fn ($v) => $v !== ''));
                    $estAnnule = $ev['statut'] === 'annule';
                    $dateClasse = 'statut-date-' . evenement_statut_couleur($ev) . ($estAnnule ? ' text-strike' : '');
                ?>
                    <tr class="row-link" tabindex="0" role="link" data-href="?p=evenement&id=<?= (int) $ev['id'] ?>" title="<?= e(evenement_statut_libelle((string) $ev['statut'])) ?>">
                        <td class="<?= $dateClasse ?>"><?= e(date('d.m.Y', strtotime($ev['date']))) ?></td>
                        <td class="small<?= $estAnnule ? ' text-strike' : '' ?>"><?= $ev['spectacle_nom'] ? e($ev['spectacle_nom']) : '—' ?></td>
                        <td class="muted small<?= $estAnnule ? ' text-strike' : '' ?>"><?= $lieu !== '' ? e($lieu) : '—' ?><?= $drapeau !== '' ? ' ' . $drapeau : '' ?></td>
                        <td class="muted small<?= $estAnnule ? ' text-strike' : '' ?>"><?= $salleFestival !== '' ? e($salleFestival) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    
        <?php if ($dashComptaActif): ?>
        <div>
            <h2 class="mt-0">Évolution financière</h2>
            <div class="card dash-chart-card"><?= $dash_svg($comptaSeries) ?></div>
        </div>
        <?php endif; ?>
        
                

    </div>
    <div class="dash-col">
        <?php if (module_actif('salaires')): ?>
        <div>
            <h2 class="mt-0">Salaires à verser</h2>
            <?php if (!$aPayer): ?>
                <p class="muted">Vous êtes à jour.</p>
            <?php else: ?>
            <table class="list">
                <thead>
                    <tr><th>Mois</th><th>Employé</th><th class="num">Net à payer</th></tr>
                </thead>
                <tbody>
                <?php $totAPayer = 0; foreach ($aPayer as $f): $totAPayer += (float) $f['salaire_net']; ?>
                    <tr class="row-link" tabindex="0" role="link" data-href="?p=fiche&id=<?= (int) $f['id'] ?>">
                        <td><?= e(mois_nom((int) $f['mois'])) ?> <?= (int) $f['annee'] ?></td>
                        <td><?= e($f['employe_nom']) ?></td>
                        <td class="num strong net-apayer"><?= chf((float) $f['salaire_net']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="total-row apayer-row"><td colspan="2"><strong>Total à verser</strong></td><td class="num strong net-apayer"><?= chf($totAPayer) ?></td></tr>
                </tfoot>
            </table>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <?php if (module_actif('facturation')): ?>
        <div>
            <h2 class="mt-0">Factures émises</h2>
            <?php if (!$facturesEmises): ?>
                <p class="muted">Aucune facture émise en attente de paiement.</p>
            <?php else: ?>
            <table class="list">
                <thead>
                    <tr><th>Échéance</th><th>Débiteur</th><th></th><th class="num">Montant</th></tr>
                </thead>
                <tbody>
                <?php $totEmises = 0; foreach ($facturesEmises as $fac): $totEmises += (float) $fac['montant_total']; ?>
                    <tr class="row-link" tabindex="0" role="link" data-href="?p=facture&id=<?= (int) $fac['id'] ?>">
                        <td><?= $fac['date_echeance'] !== '' ? e(date('d.m.Y', strtotime($fac['date_echeance']))) : '—' ?></td>
                        <td><?= e($fac['debiteur_nom']) ?></td>
                        <td><?= facturation_badge($fac) ?></td>
                        <td class="num strong net-apayer"><?= chf((float) $fac['montant_total']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="total-row apayer-row"><td><strong>Total émis</strong></td><td></td><td></td><td class="num strong net-apayer"><?= chf($totEmises) ?></td></tr>
                </tfoot>
            </table>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
    </div>
</div>
<?php endif; ?>
