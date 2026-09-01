<?php
/** @var array $aPayer */ /** @var int $aPayerAFaire */ /** @var int $aPayerRetard */
/** @var array $facturesEmises */ /** @var array $comptaSeries */
/** @var array $prochainsEvenements */
/** @var int $suisaAFaire */ /** @var int $suisaManquant */
/** @var array $suiviTags */ /** @var int $suiviTagId */ /** @var array $suiviRepartition */

// Médaillon d'état posé sur une carte : le chiffre de ce qu'il reste à faire,
// et le lien vers la liste correspondante. Rien à signaler = pas de médaillon
// (un « 0 » sur chaque carte ne dit rien et fait du bruit). Les médaillons
// s'écrivent du plus urgent au moins urgent : à trois colonnes, seul le
// premier tient (voir .dash-medaillon dans app.css).
$dash_medaillon = function (int $nb, string $libelle, string $ton, string $href): string {
    if ($nb <= 0) {
        return '';
    }
    // Enveloppe de hauteur nulle : c'est elle qui est neutralisée dans la
    // rangée de titre, pas le médaillon — celui-ci porte le fond coloré et
    // s'aplatirait si on lui mettait height: 0 (voir app.css).
    return '<span class="dash-medaillon-slot">'
        . '<a class="dash-medaillon dash-medaillon-' . e($ton) . '" href="' . e($href) . '">'
        . '<b>' . $nb . '</b> ' . e($libelle) . '</a></span>';
};

// Dernière ligne d'une carte tronquée : ce qui n'est pas montré, et le lien
// vers la liste complète. Posée DANS le tableau plutôt qu'à côté, parce que
// c'est la suite des lignes au-dessus — et la ligne de total, juste en dessous,
// n'a plus besoin de préciser un nombre que celle-ci annonce.
$dash_reste = function (int $reste, int $colonnes, string $href): string {
    if ($reste <= 0) {
        return '';
    }
    return '<tr class="dash-reste"><td colspan="' . $colonnes . '">'
        . '<a href="' . e($href) . '">et ' . $reste . ' autre' . ($reste > 1 ? 's' : '') . '</a>'
        . '</td></tr>';
};

// Génère le SVG du graphique comptable (inline, sans bibliothèque).
// Les couleurs de décor (grille, ligne du zéro, libellés d'axes et de légende)
// ne sont PAS écrites ici mais portées par des classes stylées dans app.css
// (.dash-chart .ch-grille/.ch-zero/.ch-label) : codées en dur, elles ne
// suivaient pas le thème — en sombre, les libellés tombaient à 3.54:1 (sous le
// seuil AA) et la grille, presque blanche, passait devant les courbes. Seules
// les couleurs des SÉRIES restent ici : ce sont des données, pas du décor.
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
        $zero = abs($v) < 0.01;
        $o  .= '<line x1="' . $ml . '" y1="' . $y . '" x2="' . ($W - $mr) . '" y2="' . $y
             . '" class="' . ($zero ? 'ch-zero' : 'ch-grille') . '" stroke-width="' . ($zero ? '1.5' : '1') . '"/>';
        $o  .= '<text x="' . ($ml - 6) . '" y="' . ($y + 4) . '" text-anchor="end"'
             . ' class="ch-label">' . $fmtY((float) $v) . '</text>';
    }

    // Étiquettes X (années)
    foreach ($annees as $i => $a) {
        $x  = round($xOf($i), 1);
        $o .= '<text x="' . $x . '" y="' . ($H - $mb + 16) . '" text-anchor="middle"'
            . ' class="ch-label">' . (int) $a . '</text>';
        // Tick vertical
        $o .= '<line x1="' . $x . '" y1="' . ($mt + $ph) . '" x2="' . $x . '" y2="' . ($mt + $ph + 4)
            . '" class="ch-grille" stroke-width="1"/>';
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
        $o .= '<text x="' . $x . '" y="' . ($ly + 4) . '" class="ch-label">' . $label . '</text>';
    }

    $o .= '</svg>';
    return $o;
};
?>
<?php if (($_GET['refuse'] ?? null) === '1'): ?><p class="err flash">Accès refusé : vous n'avez pas les droits nécessaires pour cette page.</p><?php endif; ?>
<div class="page-head"><h1>Tableau de bord</h1></div>

<?php // Recherche unifiée. Les sources interrogées dépendent des droits du
      // compte (voir lib/recherche.php) : le champ s'affiche pour tout le monde,
      // les résultats sont filtrés. Raccourci « / » posé dans assets/app.js. ?>
<form class="recherche-form recherche-dash" method="get" action="">
    <input type="hidden" name="p" value="recherche">
    <?= champ_recherche([
        'id'          => 'recherche-globale',
        'name'        => 'q',
        'classe'      => 'recherche-champ',
        'placeholder' => 'Rechercher partout',
        'aria'        => "Rechercher dans toute l'application",
        'submit'      => true,
    ]) ?>
</form>

<?php
// Dérivé des mêmes conditions que chaque widget ci-dessous (pas une liste à
// part) : un widget ajouté/retiré ne peut pas désynchroniser ce garde-fou.
$dashComptaActif = module_accessible('compta') && count($comptaSeries) >= 1;
$dashModuleActif = $dashComptaActif || module_accessible('salaires') || module_accessible('facturation') || module_accessible('evenements');
?>
<?php if (!$dashModuleActif): ?>
    <p class="muted">Aucun module actif n'alimente le tableau de bord pour l'instant. Active
    des modules dans <a href="?p=parametres_modules">Paramètres → Modules</a>.</p>
<?php else: ?>
<div class="dash-cols">
    <?php if (module_accessible('evenements')): ?>
        <div class="card dash-card">
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
                    $lieu = trim($ev['ville'] . ($ev['departement_canton'] !== '' ? ' (' . $ev['departement_canton'] . ')' : ''));
                    $salleFestival = implode(', ', array_filter([$ev['salle'], $ev['festival']], fn ($v) => $v !== ''));
                    $estAnnule = $ev['statut'] === 'annule';
                    $dateClasse = 'statut-date-' . evenement_statut_couleur($ev) . ($estAnnule ? ' text-strike' : '');
    
                ?>
                    <tr class="row-link" tabindex="0" role="link" data-href="?p=evenement&id=<?= (int) $ev['id'] ?>&depuis=dashboard" title="<?= e(evenement_statut_libelle((string) $ev['statut'])) ?>">
                        <td class="small <?= $dateClasse ?>"><?= e(date('d.m.Y', strtotime($ev['date']))) ?></td>
                        <td class="muted col-petit small<?= $estAnnule ? ' text-strike' : '' ?>"><?= $ev['spectacle_nom'] ? e($ev['spectacle_nom']) : '—' ?></td>
                        <td class="small<?= $estAnnule ? ' text-strike' : '' ?>"><?php $villeHtml = ville_departement_canton_html((string) $ev['ville'], pays_drapeau((string) $ev['pays']), (string) $ev['pays'], (string) $ev['departement_canton']); ?>
                	<?= $villeHtml !== '' ? $villeHtml : '—' ?></td>
                        <td class="muted col-petit small<?= $estAnnule ? ' text-strike' : '' ?>"><?= $salleFestival !== '' ? e($salleFestival) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <div class="card dash-card">
            <div class="card-head-row">
                <h2 class="mt-0">Suisa</h2>
                <?= $dash_medaillon($suisaAFaire, 'à faire', 'attente',
                    '?p=evenements_liste&vue=liste&statut_suisa[]=a_faire&statut_suisa_set=1') ?>
            </div>
            <table class="list">
                <thead>
                    <tr><th>Statut</th><th class="num">Nombre</th><th></th><th></th></tr>
                </thead>
                <tbody>
                    <?php
                    // statut_suisa[]=…&statut_suisa_set=1 (pas statut_suisa=… seul) : le
                    // filtre de la colonne SUISA sur ?p=evenements_liste est un filtre_coche()
                    // (0 à N valeurs cochées simultanément, voir evenements_lire_filtres()) —
                    // un lien qui n'utiliserait pas ce format serait silencieusement ignoré
                    // (filtre_coche() n'y verrait aucune sélection explicite, faute du
                    // marqueur "_set", et retomberait sur la session ou « tous »).
                    $suisaLien = fn (string $statut): string => '&statut_suisa[]=' . $statut . '&statut_suisa_set=1';
                    ?>
                    <tr>
                        <td>À faire</td>
                        <?php // Le nombre porte la gravité : ambre pour ce qui
                              // attend, rouge pour ce qui manque. Un zéro reste
                              // neutre — il n'y a rien à signaler. ?>
                        <td class="num strong<?= $suisaAFaire > 0 ? ' num-attente' : '' ?>"><?= $suisaAFaire ?></td>
                        <td><a class="btn ghost btn-sm" href="?p=evenements_liste&vue=liste<?= $suisaLien('a_faire') ?>"><?= icon('calendar') ?> Voir</a></td>
                        <td><a class="btn ghost btn-sm icon-only" href="?p=evenements_export_suisa<?= $suisaLien('a_faire') ?>" title="Exporter" aria-label="Exporter"><?= icon('download') ?></a></td>
                    </tr>
                    <tr>
                        <td>Manquants</td>
                        <td class="num strong<?= $suisaManquant > 0 ? ' num-retard' : '' ?>"><?= $suisaManquant ?></td>
                        <td><a class="btn ghost btn-sm" href="?p=evenements_liste&vue=liste<?= $suisaLien('manquant') ?>"><?= icon('calendar') ?> Voir</a></td>
                        <td><a class="btn ghost btn-sm icon-only" href="?p=evenements_export_suisa<?= $suisaLien('manquant') ?>" title="Exporter" aria-label="Exporter"><?= icon('download') ?></a></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if ($dashComptaActif): ?>
        <div class="card dash-card">
            <h2 class="mt-0">Évolution financière</h2>
            <?= $dash_svg($comptaSeries) ?>
        </div>
        <?php endif; ?>

        <?php if (module_accessible('salaires')): ?>
        <?php
        // Carte plafonnée. La route renvoie TOUTES les fiches impayées — le
        // total doit rester exact — mais la carte les affichait toutes et
        // s'étirait sans limite : 11 fiches faisaient 792 px contre 390 px pour
        // le graphique voisin, ce qui déséquilibrait les colonnes de .dash-cols
        // (832 / 824 / 526 px mesurés à 1800 px de large). Les plus anciennes
        // sont en tête (ORDER BY annee, mois dans route_resumes()), donc la
        // troncature garde les plus urgentes et renvoie le reste à la liste.
        $aPayerMax      = 5;
        $aPayerVisibles = array_slice($aPayer, 0, $aPayerMax);
        $aPayerTronque  = count($aPayer) > count($aPayerVisibles);
        $totAPayer      = array_sum(array_map(fn ($f) => (float) $f['salaire_net'], $aPayer));
        // Mêmes fiches que la carte : « à payer » = non payée ET pas à venir,
        // exactement le statut « apayer » de route_fiches(). Format de
        // filtre_coche() obligatoire (marqueur _set), sinon le filtre retombe
        // silencieusement sur la session — voir la note du lien Suisa ci-dessus.
        // « echeance » affine sur le retard (filtre d'appoint, route_fiches()) :
        // sans lui le médaillon annoncerait 8 et ouvrirait les 13.
        $aPayerLien = '?p=fiches&statut[]=apayer&statut_set=1';
        ?>
        <div class="card dash-card">
            <div class="card-head-row">
                <h2 class="mt-0">Salaires à verser</h2>
                <?= $dash_medaillon($aPayerRetard, 'en retard', 'retard', $aPayerLien . '&echeance=retard')
                  . $dash_medaillon($aPayerAFaire, 'à faire',   'attente', $aPayerLien . '&echeance=afaire') ?>
            </div>
            <?php if (!$aPayer): ?>
                <p class="muted">Vous êtes à jour.</p>
            <?php else: ?>
            <table class="list">
                <thead>
                    <tr><th>Mois</th><th>Employé</th><th class="num">Net à payer</th></tr>
                </thead>
                <tbody>
                <?php foreach ($aPayerVisibles as $f): ?>
                    <tr class="row-link" tabindex="0" role="link" data-href="?p=fiche&id=<?= (int) $f['id'] ?>&depuis=dashboard">
                        <td class="small"><?= e(mois_nom((int) $f['mois'])) ?> <?= (int) $f['annee'] ?></td>
                        <td class="dash-nom"><?= e($f['employe_nom']) ?></td>
                        <td class="num strong net-apayer"><?= chf((float) $f['salaire_net']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?= $dash_reste(count($aPayer) - count($aPayerVisibles), 3, $aPayerLien) ?>
                </tbody>
                <tfoot>
                    <?php // Le total porte sur TOUTES les fiches, pas sur les seules
                          // lignes visibles. Il n'a plus à le préciser : la ligne
                          // « et X autres » juste au-dessus rend l'écart lisible. ?>
                    <tr class="total-row apayer-row">
                        <td colspan="2"><strong>Total à verser</strong></td>
                        <?php // Total à l'encre, pas en ambre : l'ambre signale ce qui
                              // attend une action, or un total n'est pas une alerte —
                              // les lignes au-dessus, elles, la portent déjà. ?>
                        <td class="num strong"><?= chf($totAPayer) ?></td>
                    </tr>
                </tfoot>
            </table>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <?php if (module_accessible('facturation')): ?>
        <?php
        // Compté sur les factures déjà chargées plutôt que par une requête de
        // plus : facturation_statut_effectif() porte la règle « émise dont
        // l'échéance est passée », la même que le filtre en_retard de la liste.
        $facturesRetard = count(array_filter(
            $facturesEmises,
            fn ($f) => facturation_statut_effectif($f) === 'en_retard'
        ));
        // Même plafond que « Salaires à verser », pour la même raison : une
        // carte du tableau de bord ne doit pas s'étirer au rythme des données.
        // Les plus proches de l'échéance sont en tête (ORDER BY date_echeance).
        $facturesVisibles = array_slice($facturesEmises, 0, 5);
        // « emise » est le statut RÉELLEMENT stocké, y compris pour une facture
        // échue (« en retard » est dérivé de la date, voir
        // facturation_statut_effectif()) : le lien ouvre donc exactement les
        // mêmes factures que la carte.
        $facturesLien = '?p=facturation_liste&statut[]=emise&statut_set=1';
        $totEmises = array_sum(array_map(fn ($f) => (float) $f['montant_total'], $facturesEmises));
        ?>
        <div class="card dash-card">
            <div class="card-head-row">
                <h2 class="mt-0">Factures émises</h2>
                <?= $dash_medaillon($facturesRetard, 'en retard', 'retard',
                    '?p=facturation_liste&statut[]=en_retard&statut_set=1') ?>
            </div>
            <?php if (!$facturesEmises): ?>
                <p class="muted">Aucune facture émise en attente de paiement.</p>
            <?php else: ?>
            <?php
            // Pas d'étiquette de statut ici : elle répétait en mots ce que la
            // couleur dit déjà, dans une carte où la place est comptée. Trois
            // degrés, portés par l'échéance ET le montant pour que la ligne se
            // lise d'un bloc : à échoir (encre), échue depuis moins d'un mois
            // (ambre), au-delà (rouge). Un vrai mois calendaire, pas 30 jours.
            $ilYaUnMois = date('Y-m-d', strtotime('-1 month'));
            $factEtat = function (array $fac) use ($ilYaUnMois): string {
                $ech = trim((string) $fac['date_echeance']);
                if ($ech === '' || $ech >= date('Y-m-d')) {
                    return '';
                }
                return $ech < $ilYaUnMois ? ' facture-retard-long' : ' facture-retard';
            };
            ?>
            <table class="list">
                <thead>
                    <tr><th>Échéance</th><th>Structure</th><th class="num">Montant</th></tr>
                </thead>
                <tbody>
                <?php foreach ($facturesVisibles as $fac): $cl = $factEtat($fac); ?>
                    <tr class="row-link" tabindex="0" role="link" data-href="?p=facture&id=<?= (int) $fac['id'] ?>&depuis=dashboard"
                        title="<?= e(facturation_statut_effectif($fac) === 'en_retard' ? 'Échéance dépassée' : 'Émise, pas encore échue') ?>">
                        <td class="small<?= $cl ?>"><?= $fac['date_echeance'] !== '' ? e(date('d.m.Y', strtotime($fac['date_echeance']))) : '—' ?></td>
                        <td class="dash-nom"><?= e($fac['structure_nom']) ?></td>
                        <td class="num strong<?= $cl ?>"><?= chf((float) $fac['montant_total']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?= $dash_reste(count($facturesEmises) - count($facturesVisibles), 3, $facturesLien) ?>
                </tbody>
                <tfoot>
                    <tr class="total-row apayer-row"><td><strong>Total</strong></td><td></td><td class="num strong"><?= chf($totEmises) ?></td></tr>
                </tfoot>
            </table>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (module_accessible('booking')): ?>
        <?php
        // Suivi du booking : où en est le démarchage d'une étiquette. Une barre
        // par tranche d'ancienneté du dernier contact, dans les mêmes couleurs
        // que partout ailleurs (vert = frais, gris = à reprendre), et chaque
        // segment mène à la liste filtrée — la barre ne dit pas seulement
        // « combien », elle donne « lesquels ».
        $suiviTotal = array_sum($suiviRepartition);
        ?>
        <div class="card dash-card">
            <div class="card-head-row">
                <h2 class="mt-0">Suivi du booking</h2>
                <?= $suiviTags ? $dash_medaillon(
                    (int) ($suiviRepartition['vieux'] ?? 0), 'à contacter', 'attente',
                    lien_structures_filtre([
                        'tag_id' => [$suiviTagId],
                        'contact_periode' => SUIVI_BOOKING_BANDES['vieux'][1],
                    ]) . '&depuis=booking'
                ) : '' ?>
                <?php if ($suiviTags): ?>
                <form method="post" action="?p=resumes_suivi_tag" class="suivi-tag-form">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <select name="tag_id" data-submit-on-change aria-label="Étiquette suivie">
                        <?php foreach ($suiviTags as $t): ?>
                            <option value="<?= (int) $t['id'] ?>" <?= $suiviTagId === (int) $t['id'] ? 'selected' : '' ?>>
                                <?= e($t['nom']) ?> (<?= (int) $t['nb'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <?php endif; ?>
            </div>
            <?php if (!$suiviTags): ?>
                <p class="muted">Aucune étiquette. Posez-en une sur des structures pour suivre leur démarchage.</p>
            <?php elseif ($suiviTotal === 0): ?>
                <p class="muted">Aucune structure ne porte cette étiquette.</p>
            <?php else: ?>
                <div class="suivi-barre">
                    <?php foreach (SUIVI_BOOKING_BANDES as $cle => [$libelle, $tranches]): ?>
                        <?php $n = (int) ($suiviRepartition[$cle] ?? 0); if ($n === 0) { continue; } ?>
                        <a class="suivi-seg suivi-seg-<?= e($cle) ?>"
                           style="flex-grow: <?= $n ?>"
                           href="<?= e(lien_structures_filtre(['tag_id' => [$suiviTagId], 'contact_periode' => $tranches])) ?>&amp;depuis=booking"
                           title="<?= e($libelle) ?> : <?= $n ?> structure<?= $n > 1 ? 's' : '' ?>">
                            <span><?= $n ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
                <ul class="suivi-legende">
                    <?php foreach (SUIVI_BOOKING_BANDES as $cle => [$libelle, $tranches]): ?>
                        <?php $n = (int) ($suiviRepartition[$cle] ?? 0); ?>
                        <li>
                            <a href="<?= e(lien_structures_filtre(['tag_id' => [$suiviTagId], 'contact_periode' => $tranches])) ?>&amp;depuis=booking">
                                <span class="suivi-puce suivi-seg-<?= e($cle) ?>"></span>
                                <?= e($libelle) ?>
                                <strong><?= $n ?></strong>
                                <span class="muted"><?= $suiviTotal ? round($n * 100 / $suiviTotal) : 0 ?> %</span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php endif; ?>
</div>
<?php endif; ?>
