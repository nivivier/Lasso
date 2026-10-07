<?php
/** @var array $aPayer */
/** @var array $facturesEmises */ /** @var array $comptaSeries */
/** @var array $prochainsEvenements */
/** @var int $suisaAFaire */ /** @var int $suisaEnvoye */ /** @var int $suisaManquant */
/** @var array $campagnesDash */ /** @var int $campagnesAVenir */ /** @var array $fondsDash */

// Une carte du tableau de bord met en valeur ce qui ATTEND UN GESTE : la ligne
// se détache sur un fond ambre très clair (.ligne-action). C'est la seule
// couleur de fond d'une carte — une ligne de total n'en porte aucune, elle ne
// demande rien. Cette mise en valeur remplace les médaillons d'alerte posés sur
// les titres : ils répétaient en chiffre ce que les lignes disaient déjà.
$dash_action = fn (bool $attend): string => $attend ? ' ligne-action' : '';

// Dernière ligne d'une carte tronquée : ce qui n'est pas montré, et le lien
// vers la liste complète. Posée DANS le tableau plutôt qu'à côté, parce que
// c'est la suite des lignes au-dessus — et la ligne de total, juste en dessous,
// n'a plus besoin de préciser un nombre que celle-ci annonce.
//
// $suffixe dit ce que sont ces autres quand ils ne sont pas de la même espèce
// que les lignes montrées : « et 3 autres à venir » sous les campagnes en
// cours. Vide, la ligne dit simplement « et 3 autres ».
$dash_reste = function (int $reste, int $colonnes, string $href, string $suffixe = ''): string {
    if ($reste <= 0) {
        return '';
    }
    return '<tr class="dash-reste"><td colspan="' . $colonnes . '">'
        . '<a href="' . e($href) . '">et ' . $reste . ' autre' . ($reste > 1 ? 's' : '')
        . ($suffixe !== '' ? ' ' . e($suffixe) : '') . '</a>'
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
<?php
// Chaque carte est rendue dans un tampon plutôt que directement : c'est cette
// liste — son ordre est l'ordre par défaut — qui sert ensuite à les écrire dans
// l'ordre choisi par le compte (dashboard_ordre()), et à peupler le panneau
// « Organiser les cartes ». Les conditions d'accès restent EXACTEMENT où elles
// étaient, autour de la carte qu'elles gouvernent : une carte figure dans
// $cartes si et seulement si elle a quelque chose à montrer, et c'est aussi ce
// qui dit au tableau de bord s'il est vide.
$dashComptaActif = module_accessible('compta') && count($comptaSeries) >= 1;
$cartes = [];
?>

    <?php if (module_accessible('evenements')): ?>
        <?php ob_start(); ?>
        <div class="card dash-card">
            <h2 class="mt-0">Prochains événements</h2>
            <?php if (!$prochainsEvenements): ?>
                <p class="muted">Aucun événement à venir.</p>
            <?php else: ?>
            <?php // Même mini-ligne que la carte « Événements » d'une structure et
                  // que la liste sur téléphone (evenement_mini_html()). ?>
            <ul class="clean-list">
                <?php foreach ($prochainsEvenements as $ev): ?>
                <?= evenement_mini_html($ev, ['href' => '?p=evenement&id=' . (int) $ev['id'] . '&depuis=dashboard']) ?>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
        <?php $cartes['evenements'] = ['titre' => 'Prochains événements', 'html' => ob_get_clean()]; ?>
        <?php ob_start(); ?>
        <div class="card dash-card">
            <h2 class="mt-0">Suisa</h2>
            <table class="list">
                <thead>
                    <tr><th>Statut</th><th class="num">Nombre</th></tr>
                </thead>
                <tbody>
                    <?php
                    // lien_liste_filtree() et pas une URL écrite à la main : les
                    // filtres de ?p=evenements sont des filtre_coche(), qui
                    // ignorent silencieusement un paramètre sans son marqueur
                    // « _set » (voir le helper). L'année est vidée au passage,
                    // sinon le compte annoncé ici et la liste ouverte là-bas ne
                    // porteraient pas sur les mêmes dates.
                    $suisaLien = fn (string $statut): string => lien_liste_filtree(
                        'evenements',
                        ['statut_suisa' => [$statut], 'annee' => []],
                        ['vue' => 'liste']
                    );
                    ?>
                    <?php // La ligne entière mène à la liste filtrée, comme les
                          // autres lignes du tableau de bord (.row-link) : deux
                          // boutons par ligne pour deux colonnes de chiffres,
                          // c'était le geste écrit deux fois. L'export reste
                          // accessible depuis la liste où il s'applique. ?>
                    <?php // « À faire » en gras : des trois lignes, c'est la seule
                          // qui appelle un geste. À l'encre, comme le nom d'une
                          // campagne en cours — la couleur d'accent reste à ce
                          // qui se clique. ?>
                    <?php // Seul « À faire » se détache : c'est ce qui dépend de nous,
                          // une déclaration à envoyer. « Envoyés » et « Manquants »
                          // attendent la SUISA — le rouge du nombre dit déjà qu'un
                          // décompte tarde, sans en faire une tâche du jour. ?>
                    <tr class="row-link<?= $dash_action($suisaAFaire > 0) ?>" tabindex="0" role="link" data-href="<?= e($suisaLien('a_faire')) ?>">
                        <td class="strong-encre">À faire</td>
                        <?php // Le nombre porte la gravité : ambre pour ce qui
                              // attend, rouge pour ce qui manque. Un zéro reste
                              // neutre — il n'y a rien à signaler. ?>
                        <td class="num strong<?= $suisaAFaire > 0 ? ' num-attente' : '' ?>"><?= $suisaAFaire ?></td>
                    </tr>
                    <?php // Envoyées, décompte pas encore revenu : rien à faire,
                          // donc un nombre neutre — la gravité est réservée à ce
                          // qui attend (ambre) et à ce qui manque (rouge). ?>
                    <tr class="row-link" tabindex="0" role="link" data-href="<?= e($suisaLien('envoye')) ?>">
                        <td>Envoyés</td>
                        <td class="num strong"><?= $suisaEnvoye ?></td>
                    </tr>
                    <tr class="row-link" tabindex="0" role="link" data-href="<?= e($suisaLien('manquant')) ?>">
                        <td>Manquants</td>
                        <td class="num strong<?= $suisaManquant > 0 ? ' num-retard' : '' ?>"><?= $suisaManquant ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php $cartes['suisa'] = ['titre' => 'Suisa', 'html' => ob_get_clean()]; ?>
        <?php endif; ?>

        <?php if ($dashComptaActif): ?>
        <?php ob_start(); ?>
        <div class="card dash-card">
            <h2 class="mt-0">Évolution financière</h2>
            <?= $dash_svg($comptaSeries) ?>
        </div>
        <?php $cartes['compta'] = ['titre' => 'Évolution financière', 'html' => ob_get_clean()]; ?>
        <?php endif; ?>

        <?php if (module_accessible('salaires')): ?>
        <?php
        // Carte plafonnée. La route renvoie TOUTES les fiches impayées — le
        // total doit rester exact — mais la carte les affichait toutes et
        // s'étirait sans limite : 11 fiches faisaient 792 px contre 390 px pour
        // le graphique voisin, ce qui déséquilibrait les colonnes de .dash-cols
        // (832 / 824 / 526 px mesurés à 1800 px de large). Les plus anciennes
        // sont en tête (ORDER BY annee, mois dans route_tableau_bord()), donc la
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
        $aPayerLien = lien_liste_filtree('fiches', ['statut' => ['apayer']]);
        ?>
        <?php ob_start(); ?>
        <div class="card dash-card">
            <h2 class="mt-0">Salaires à verser</h2>
            <?php if (!$aPayer): ?>
                <p class="muted">Vous êtes à jour.</p>
            <?php else: ?>
            <table class="list">
                <thead>
                    <tr><th>Mois</th><th>Employé</th><th class="num">Net à payer</th></tr>
                </thead>
                <tbody>
                <?php // Une fiche du mois courant reste à verser mais n'appelle rien
                      // aujourd'hui : elle ne se détache pas. Celles du mois
                      // précédent et d'avant, si (echeance_etat, route_tableau_bord()). ?>
                <?php foreach ($aPayerVisibles as $f): ?>
                    <tr class="row-link<?= $dash_action(($f['echeance_etat'] ?? '') !== '') ?>" tabindex="0" role="link" data-href="?p=fiche&id=<?= (int) $f['id'] ?>&depuis=dashboard">
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
                    <tr class="total-row">
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
        <?php $cartes['salaires'] = ['titre' => 'Salaires à verser', 'html' => ob_get_clean()]; ?>
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
        $facturesLien = lien_liste_filtree('factures', ['statut' => ['emise']]);
        $totEmises = array_sum(array_map(fn ($f) => (float) $f['montant_total'], $facturesEmises));
        ?>
        <?php ob_start(); ?>
        <div class="card dash-card">
            <h2 class="mt-0">Factures émises</h2>
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
                <?php // Une facture pas encore échue ne se détache pas : elle suit son
                      // cours. Une facture échue d'hier non plus — le paiement est
                      // peut-être en route. Le fond ambre, qui dit « ceci attend un
                      // geste », est réservé au retard LONG : passé un mois, c'est
                      // une relance qu'il faut, et c'est le même seuil qui fait
                      // passer la ligne au rouge. Entre les deux, la couleur du
                      // texte suffit à signaler l'échéance dépassée. ?>
                <?php foreach ($facturesVisibles as $fac): $cl = $factEtat($fac); ?>
                    <tr class="row-link<?= $dash_action($cl === ' facture-retard-long') ?>" tabindex="0" role="link" data-href="?p=facture&id=<?= (int) $fac['id'] ?>&depuis=dashboard"
                        title="<?= e(facturation_statut_effectif($fac) === 'en_retard' ? 'Échéance dépassée' : 'Émise, pas encore échue') ?>">
                        <td class="small<?= $cl ?>"><?= $fac['date_echeance'] !== '' ? e(date('d.m.Y', strtotime($fac['date_echeance']))) : '—' ?></td>
                        <td class="dash-nom"><?= e($fac['structure_nom']) ?></td>
                        <td class="num strong<?= $cl ?>"><?= chf((float) $fac['montant_total']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?= $dash_reste(count($facturesEmises) - count($facturesVisibles), 3, $facturesLien) ?>
                </tbody>
                <tfoot>
                    <tr class="total-row"><td><strong>Total</strong></td><td></td><td class="num strong"><?= chf($totEmises) ?></td></tr>
                </tfoot>
            </table>
            <?php endif; ?>
        </div>
        <?php $cartes['factures'] = ['titre' => 'Factures émises', 'html' => ob_get_clean()]; ?>
        <?php endif; ?>

        <?php if (module_accessible('booking')): ?>
        <?php
        // Campagnes : où en est le démarchage, campagne par campagne. Dès qu'une
        // campagne est ouverte, la carte ne montre qu'elles (en retard d'abord) et
        // résume le reste en une ligne ; sinon, les prochaines puis les terminées
        // — et seulement ce qui tient ici (campagnes_dashboard()).
        // La barre est celle de ?p=booking_campagnes et de la carte d'une campagne :
        // même segments, mêmes couleurs, une seule définition (campagne_barre_html()).
        // Le médaillon compte ce qui RESTE À FAIRE, pas les campagnes ouvertes :
        // savoir qu'il y a deux campagnes en cours n'apprend rien tant qu'on
        // ignore s'il y reste trois structures ou trois cents. C'est le même
        // « à contacter » que le dernier segment des barres ci-dessous, sommé
        // sur toutes les campagnes ouvertes (campagnes_a_contacter()).
        $statutClasseDash = ['a_venir' => 'muted-badge', 'en_cours' => 'ok-badge', 'en_retard' => 'err-badge', 'terminee' => 'muted-badge'];
        ?>
        <?php ob_start(); ?>
        <?php // « Booking » et non « Campagnes » : le tableau de bord croise deux
              // sortes de campagnes — le démarchage et la recherche de fonds — et,
              // sans le module autour pour le dire, le titre doit nommer celle
              // dont il s'agit. Ici c'est le nom du module lui-même. ?>
        <div class="card dash-card">
            <h2 class="mt-0">Booking</h2>
            <?php if (!$campagnesDash): ?>
                <p class="muted">Aucune campagne. <a href="?p=booking_campagne_form">Créez-en une</a> pour suivre un démarchage.</p>
            <?php else: ?>
            <table class="list">
                <thead>
                    <tr><th>Campagne</th><th class="nowrap">Avancement</th></tr>
                </thead>
                <tbody>
                <?php // Une campagne ouverte est un démarchage en cours : c'est là
                      // qu'il reste des structures à contacter. ?>
                <?php foreach ($campagnesDash as $c): $cid = (int) $c['id']; ?>
                    <tr class="row-link<?= $dash_action(in_array($c['statut'], CAMPAGNE_STATUTS_OUVERTS, true)) ?>" tabindex="0" role="link" data-href="?p=booking_campagne&id=<?= $cid ?>">
                        <td>
                            <span class="dash-campagne">
                                <?= $c['projets_pastilles'][0] ?? '' ?>
                                <?php // Gras pour ce qui demande du travail, normal pour
                                      // le reste — mais à l'encre dans les deux cas : sur
                                      // une carte où toute la ligne est cliquable, colorer
                                      // le nom n'apprend rien de plus que le gras. ?>
                                <span class="dash-campagne-nom<?= $c['statut'] === 'en_cours' ? ' strong-encre' : '' ?>"><?= e($c['nom']) ?></span>
                            </span>
                        </td>
                        <?php // Une seule colonne pour les deux questions, parce
                              // qu'une seule des deux se pose à la fois : sur une
                              // campagne dont la saison court, ce qui compte est où
                              // elle en est ; sur les autres, c'est leur état — une
                              // barre n'apprend rien d'une campagne pas commencée, et
                              // sur une campagne dont la date de fin est passée,
                              // « En retard » est l'information, pas le décompte.
                              //
                              // Tout le monde contacté ne clôt pas la question : tant
                              // que la saison court, les réponses continuent d'arriver
                              // et la barre de répartition de les montrer. ?>
                        <td class="camp-avancement">
                            <?php $avecBarre = $c['statut'] === 'en_cours'
                                || ($c['statut'] === 'terminee' && periode_courante($c)); ?>
                            <?php if ($avecBarre): ?>
                                <?= campagne_barre_html($c['repartition'], (int) $c['nb_total'], 'camp-barre-liste') ?>
                                <span class="camp-avancement-txt"><b><?= (int) $c['nb_faits'] ?></b> / <?= (int) $c['nb_total'] ?></span>
                            <?php else: ?>
                                <span class="badge <?= $statutClasseDash[$c['statut']] ?? 'muted-badge' ?>"><?= e(CAMPAGNE_STATUTS[$c['statut']] ?? $c['statut']) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php // Dès qu'un démarchage est ouvert, la carte s'y tient (voir
                      // campagnes_dashboard()) : les campagnes pas encore
                      // commencées tiennent en une ligne, qui mène à la liste. ?>
                <?= $dash_reste($campagnesAVenir, 2, '?p=booking_campagnes', 'à venir') ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php // La clé reste « campagnes » : c'est elle qui est rangée dans l'ordre
              // des cartes choisi par l'utilisateur (paramètre dash_cartes). Seul
              // le libellé change. ?>
        <?php $cartes['campagnes'] = ['titre' => 'Booking', 'html' => ob_get_clean()]; ?>
        <?php endif; ?>

        <?php if (module_accessible('fonds')): ?>
        <?php ob_start(); ?>
        <div class="card dash-card">
            <h2 class="mt-0">Recherche de fonds</h2>
            <?php if (!$fondsDash['campagnes'] && !$fondsDash['bilans']): ?>
                <p class="muted">Aucune campagne en cours.</p>
            <?php else: ?>
            <?php // Deux questions, deux tableaux : où en sont les campagnes de
                  // la saison, et quels bilans sont dus. La seconde est celle
                  // qu'on oublie — l'argent est encaissé, le dossier semble
                  // clos, et il reste à rendre des comptes. ?>
            <?php if ($fondsDash['campagnes']): ?>
            <table class="list">
                <thead>
                    <tr><th>Campagne</th><th class="nowrap">Avancement</th></tr>
                </thead>
                <tbody>
                <?php foreach ($fondsDash['campagnes'] as $fc): ?>
                    <?php // Une campagne attend un geste tant qu'il lui reste des
                          // dossiers à déposer. Tout déposé, elle suit son cours. ?>
                    <tr class="row-link<?= $dash_action((int) $fc['nb_deposees'] < (int) $fc['nb_total']) ?>"
                        tabindex="0" role="link" data-href="?p=fonds_campagne&id=<?= (int) $fc['id'] ?>">
                        <?php // L'icône du projet financé devant son nom, comme dans
                              // la carte Booking : c'est par elle qu'on reconnaît
                              // une campagne avant de la lire. ?>
                        <td>
                            <span class="dash-campagne">
                                <?= $fc['projets_pastilles'][0] ?? '' ?>
                                <span class="dash-campagne-nom strong-encre"><?= e((string) $fc['nom']) ?></span>
                            </span>
                        </td>
                        <td class="camp-avancement">
                            <?= fonds_barre_html($fc['repartition'], 'camp-barre-liste') ?>
                            <span class="camp-avancement-txt"><b><?= chf($fc['repartition']['obtenu']) ?></b>
                                <?= $fc['repartition']['chiffree'] ? ' / ' . chf((float) $fc['repartition']['base']) : '' ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?= $dash_reste($fondsDash['nbCampagnes'] - count($fondsDash['campagnes']), 2, '?p=fonds_campagnes') ?>
                </tbody>
            </table>
            <?php endif; ?>

            <?php if ($fondsDash['bilans']): ?>
            <table class="list">
                <thead>
                    <tr><th>Bilan dû</th><th class="num nowrap">Échéance</th></tr>
                </thead>
                <tbody>
                <?php foreach ($fondsDash['bilans'] as $b): ?>
                    <?php $enRetard = (string) $b['date_limite_bilan'] < date('Y-m-d'); ?>
                    <tr class="row-link<?= $dash_action(true) ?>" tabindex="0" role="link"
                        data-href="?p=fonds_demande&id=<?= (int) $b['id'] ?>">
                        <td class="dash-nom"><?= e((string) $b['structure_nom']) ?>
                            <div class="muted small"><?= e((string) $b['campagne_nom']) ?></div></td>
                        <td class="num small<?= $enRetard ? ' num-retard' : '' ?>">
                            <?= e(date('d.m.Y', strtotime((string) $b['date_limite_bilan']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?= $dash_reste($fondsDash['nbBilans'] - count($fondsDash['bilans']), 2, '?p=fonds_campagnes') ?>
                </tbody>
            </table>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php $cartes['fonds'] = ['titre' => 'Recherche de fonds', 'html' => ob_get_clean()]; ?>
        <?php endif; ?>

<?php // L'ordre retenu, les cartes masquées en moins. ?>
<?php $cartesVisibles = dashboard_ordre(array_keys($cartes)); ?>
<?php if (($_GET['refuse'] ?? null) === '1'): ?><p class="err flash">Accès refusé : vous n'avez pas les droits nécessaires pour cette page.</p><?php endif; ?>
<?php // Pas de titre ici : le rail dit déjà où l'on est, et la barre de
      // recherche est ce qu'on vient chercher en arrivant. Elle prend donc la
      // ligne de l'en-tête, le bouton d'organisation des cartes à sa droite.
      //
      // Recherche unifiée : les sources interrogées dépendent des droits du
      // compte (voir lib/recherche.php) — le champ s'affiche pour tout le
      // monde, les résultats sont filtrés. Raccourci « / » dans assets/app.js. ?>
<div class="page-head page-head-recherche">
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
    // Le « + » : ce qu'on vient créer depuis l'accueil. Chaque entrée n'y figure
    // que si son module est accessible ET qu'on a le droit d'y écrire — proposer
    // de créer ce qu'on ne pourra pas enregistrer serait une promesse en l'air.
    // Les adresses sont celles des boutons « Nouveau… » de chaque liste, pas des
    // routes inventées pour ce menu.
    $dashCreer = [];
    if (module_accessible('salaires') && peut_ecrire('salaires')) {
        $dashCreer[] = ['libelle' => 'Fiche de salaire', 'icone' => 'file-plus', 'href' => '?p=fiche_form'];
    }
    if (module_accessible('facturation') && peut_ecrire('facturation')) {
        $dashCreer[] = ['libelle' => 'Facture', 'icone' => 'file-plus', 'href' => '?p=facture_form'];
    }
    if (module_accessible('evenements') && peut_ecrire('evenements')) {
        $dashCreer[] = ['libelle' => 'Événement', 'icone' => 'calendar-plus', 'href' => '?p=evenement'];
    }
    if (module_accessible('booking') && peut_ecrire('booking')) {
        $dashCreer[] = ['libelle' => 'Structure', 'icone' => 'house-plus', 'href' => '?p=structure&depuis=booking'];
        $dashCreer[] = ['libelle' => 'Campagne de booking', 'icone' => 'message-circle-plus', 'href' => '?p=booking_campagne_form'];
    }
    if (module_accessible('fonds') && peut_ecrire('fonds')) {
        $dashCreer[] = ['libelle' => 'Recherche de fonds', 'icone' => 'landmark', 'href' => '?p=fonds_campagne_form'];
    }
    ?>
    <?php if ($cartes): ?>
    <?php
    // Organiser les cartes. Panneau ouvert/fermé par <details>, donc sans une
    // ligne de JavaScript ; il se rouvre après chaque déplacement grâce au
    // ?reglages=1 que pose la redirection.
    [$dashOrdre, $dashCachees] = dashboard_disposition(array_keys($cartes));
    $dashDernier = count($dashOrdre) - 1;
    ?>
    <details class="head-actions dash-reglages"<?= isset($_GET['reglages']) ? ' open' : '' ?>>
        <summary class="btn ghost icon-only" title="Organiser les cartes" aria-label="Organiser les cartes"><?= icon('columns-3-cog') ?></summary>
        <div class="dash-reglages-panneau">
            <p class="muted small mb-8">L'ordre des cartes et celles que vous voulez voir. Ce réglage
            n'est qu'à vous : il ne change rien pour les autres comptes.</p>
            <?php foreach ($dashOrdre as $dashRang => $dashId): ?>
            <?php $dashCachee = in_array($dashId, $dashCachees, true); ?>
            <div class="dash-reglage-ligne plan-row<?= $dashCachee ? ' est-cachee' : '' ?>" data-id="<?= e($dashId) ?>">
                <span class="plan-grip" draggable="true" title="Glisser pour ranger ailleurs" aria-hidden="true"><?= icon('grip') ?></span>
                <span class="dash-reglage-nom"><?= e($cartes[$dashId]['titre']) ?></span>
                <?php // Repli sans JavaScript : les flèches, masquées dès que le
                      // glisser-déposer est actif (.dnd-on .plan-fallback). ?>
                <form method="post" action="?p=tableau_bord" class="d-inline plan-fallback">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="section" value="deplacer">
                    <input type="hidden" name="carte" value="<?= e($dashId) ?>">
                    <?= hidden_inputs_html(['dispo' => array_keys($cartes)]) ?>
                    <button type="submit" name="dir" value="up" class="btn ghost btn-sm icon-only" title="Monter" aria-label="Monter <?= e($cartes[$dashId]['titre']) ?>" <?= $dashRang === 0 ? 'disabled' : '' ?>><?= icon('chevron-up') ?></button>
                    <button type="submit" name="dir" value="down" class="btn ghost btn-sm icon-only" title="Descendre" aria-label="Descendre <?= e($cartes[$dashId]['titre']) ?>" <?= $dashRang === $dashDernier ? 'disabled' : '' ?>><?= icon('chevron-down') ?></button>
                </form>
                <?php // L'œil ferme la ligne, tout à droite : c'est l'action de
                      // cette ligne-là (docs/UI.md § 1), et les yeux alignés se
                      // lisent comme une colonne. ?>
                <form method="post" action="?p=tableau_bord" class="d-inline">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="section" value="visible">
                    <input type="hidden" name="carte" value="<?= e($dashId) ?>">
                    <?= hidden_inputs_html(['dispo' => array_keys($cartes)]) ?>
                    <button type="submit" class="btn ghost btn-sm icon-only"
                            title="<?= $dashCachee ? 'Afficher' : 'Masquer' ?>"
                            aria-label="<?= $dashCachee ? 'Afficher' : 'Masquer' ?> la carte <?= e($cartes[$dashId]['titre']) ?>"><?= icon($dashCachee ? 'eye-off' : 'eye') ?></button>
                </form>
            </div>
            <?php endforeach; ?>
            <?php // Exemplaire unique du formulaire de repositionnement : le script
                  // y écrit l'ordre complet au dépôt et l'envoie. Le serveur
                  // renumérote, la page n'invente aucun rang (docs/UI.md § 4). ?>
            <form method="post" action="?p=tableau_bord" id="reorder-form" hidden>
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="section" value="ordre">
                <input type="hidden" name="id" value="">
                <input type="hidden" name="order" value="">
                <?= hidden_inputs_html(['dispo' => array_keys($cartes)]) ?>
            </form>
            <form method="post" action="?p=tableau_bord" class="mt-10">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="section" value="reinit">
                <button type="submit" class="btn ghost btn-sm">Rétablir l'ordre par défaut</button>
            </form>
            <script nonce="<?= e(csp_nonce()) ?>">
            lassoOrdreListe({
                containerSelector: '.dash-reglages-panneau',
                rowsSelector: '.dash-reglage-ligne',
                scrollKey: 'dashReglagesScroll',
                formAction: '?p=tableau_bord',
            });
            </script>
        </div>
    </details>
    <?php endif; ?>
    <?php // Le « + » à droite de tout : c'est le geste qui AJOUTE, pas celui qui
          // range l'écran, et c'est lui qu'on vient chercher le plus souvent. ?>
    <?= menu_deroulant_html(
        ['icone' => 'plus', 'plein' => true, 'titre' => 'Créer…'],
        $dashCreer,
        ['classe' => 'dash-creer']
    ) ?>
</div>


<?php if (!$cartes): ?>
    <p class="muted">Aucun module actif n'alimente le tableau de bord pour l'instant. Active
    des modules dans <a href="?p=modules">Paramètres → Modules</a>.</p>
<?php elseif (!$cartesVisibles): ?>
    <p class="muted">Toutes les cartes sont masquées. Le bouton ci-dessus permet d'en rétablir.</p>
<?php else: ?>
<div class="dash-cols">
<?php foreach ($cartesVisibles as $dashId) { echo $cartes[$dashId]['html']; } ?>
</div>
<?php endif; ?>
