<?php /** @var array $fiches */ /** @var int $annee */ /** @var array $annees */ /** @var array $statut */
/** @var array $employes */ /** @var int $employeId */ /** @var array $axesParFiche */ /** @var array $totaux */
/** @var string $pgRoute */ /** @var array $pgParams */ /** @var int $pgPage */ /** @var int $pgTaille */ /** @var int $pgTotal */ ?>
<?php
// Filtre "Statut" (EXPÉRIMENTAL) : déplacé de la barre d'outils vers un bouton
// à côté du titre de la colonne "Paiement" qu'il filtre réellement — voir
// .col-filter/.col-filter-menu (assets/app.css). Cases à cocher (0 à 3 valeurs
// simultanées) plutôt qu'un choix unique — $statut est désormais un tableau
// (voir route_fiches(), lib/routes.php). $lienStatutSans() reconstruit l'URL
// avec une seule valeur retirée (utilisé par la croix de chaque filtre actif) ;
// 'statut_set' TOUJOURS posé (même à 0 case cochée) pour que route_fiches()
// sache que la case a bien été soumise plutôt que de retomber sur la session.
$statutLabels = ['avenir' => 'À venir', 'apayer' => 'À payer', 'payees' => 'Payées'];
$qsSansStatut = $_GET;
unset($qsSansStatut['statut'], $qsSansStatut['statut_set'], $qsSansStatut['page']);
$lienStatutSans = fn (string $retire): string => '?' . http_build_query(
    $qsSansStatut + ['statut_set' => 1, 'statut' => array_values(array_diff($statut, [$retire]))]
);
?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<div class="page-head-band">
<div class="page-head">
    <div class="page-head-title">
        <h1><?= e($ntLabel) ?></h1>
    </div>
    <?php require __DIR__ . '/_module_tabs_render.php'; ?>
</div>
</div>

<div class="module-content"><div class="module-content-inner">
    <div class="toolbar">
        <form method="get" class="filters">
            <input type="hidden" name="p" value="fiches">
            <input type="hidden" name="statut_set" value="1">
            <?php foreach ($statut as $s): ?><input type="hidden" name="statut[]" value="<?= e($s) ?>"><?php endforeach; ?>
            <label>Année
                <select name="annee" onchange="this.form.submit()">
                    <option value="0" <?= $annee === 0 ? 'selected' : '' ?>>Toutes</option>
                    <?php
                    $opts = array_filter(array_unique(array_merge([(int) date('Y')], array_map('intval', $annees))), fn($y) => $y > 0);
                    rsort($opts);
                    foreach ($opts as $a): ?>
                        <option value="<?= $a ?>" <?= $a === $annee ? 'selected' : '' ?>><?= $a ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Employé
                <select name="employe_id" onchange="this.form.submit()">
                    <option value="0">Tous</option>
                    <?php foreach ($employes as $emp): ?>
                        <option value="<?= (int) $emp['id'] ?>" <?= $employeId === (int) $emp['id'] ? 'selected' : '' ?>>
                            <?= e($emp['prenom'] . ' ' . $emp['nom']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>
        <div class="head-actions">
            <a class="btn" href="?p=fiche_new" title="Nouvelle fiche"><?= icon('file-plus') ?> <span class="lbl">Nouvelle fiche</span></a>
        </div>
    </div>

<?php if (!$fiches): ?>
    <p class="muted">Aucune fiche pour cette sélection.</p>
<?php else: ?>
<div class="table-scroll">
<table class="list list-wide">
    <thead>
        <tr>
            <th class="col-employe">Employé</th><?php if ($axesParFiche): ?><th class="col-petit">Axes</th><?php endif; ?>
            <th class="num">Brut</th><th class="num col-petit">Charges sociales</th><th class="num col-petit">Impôt à la source</th>
            <th class="num">Net</th>
            <th>
                <span class="col-th">
                    Paiement
                    <details class="col-filter">
                        <summary class="col-filter-btn" title="Filtrer"><?= icon('funnel') ?></summary>
                        <form method="get" class="col-filter-menu" onchange="this.submit()">
                            <input type="hidden" name="p" value="fiches">
                            <input type="hidden" name="annee" value="<?= (int) $annee ?>">
                            <input type="hidden" name="employe_id" value="<?= (int) $employeId ?>">
                            <input type="hidden" name="statut_set" value="1">
                            <?php foreach ($statutLabels as $val => $lib): ?>
                                <label><input type="checkbox" name="statut[]" value="<?= e($val) ?>" <?= in_array($val, $statut, true) ? 'checked' : '' ?>> <?= e($lib) ?></label>
                            <?php endforeach; ?>
                        </form>
                    </details>
                </span>
                <?php if ($statut): ?>
                <span class="col-th-actif-list">
                    <?php foreach ($statut as $s): ?>
                        <span class="col-th-actif"><?= e($statutLabels[$s]) ?><a href="<?= e($lienStatutSans($s)) ?>" title="Retirer « <?= e($statutLabels[$s]) ?> »"><?= icon('x') ?></a></span>
                    <?php endforeach; ?>
                </span>
                <?php endif; ?>
            </th>
            <th class="num col-petit">Charges patronales</th><th class="num">Coût employeur</th>
            <th class="center col-petit">Envoyée</th>
        </tr>
    </thead>
    <tbody>
    <?php $nbCols = 9 + ($axesParFiche ? 1 : 0); $moisPrec = null;
    foreach ($fiches as $f):
        $apayer = trim((string) $f['date_paiement']) === '' && !fiche_a_venir($f);
        $moisCle = (int) $f['annee'] . '-' . (int) $f['mois'];
        if ($moisCle !== $moisPrec): $moisPrec = $moisCle; ?>
        <tr class="fiche-mois-sep"><td colspan="<?= $nbCols ?>"><?= e(mois_nom((int) $f['mois'])) ?> <?= (int) $f['annee'] ?></td></tr>
    <?php endif; ?>
        <tr class="row-link" tabindex="0" role="link" data-href="?p=fiche&id=<?= (int) $f['id'] ?>">
            <td><?= e($f['employe_nom']) ?></td>
            <?php if ($axesParFiche): ?><td class="muted small"><?= e($axesParFiche[(int) $f['id']] ?? '') ?></td><?php endif; ?>
            <td class="num col-brut"><?= chf((float) $f['salaire_brut']) ?></td>
            <td class="num col-petit"><?= chf((float) $f['total_deductions']) ?></td>
            <td class="num col-petit"><?= chf((float) $f['ded_impot_source']) ?></td>
            <td class="num strong <?= $apayer ? 'net-apayer' : (fiche_a_venir($f) ? 'net-avenir' : '') ?>"><?= chf((float) $f['salaire_net']) ?></td>
            <td><?= badge_paiement($f) ?></td>
            <td class="num col-petit"><?= chf((float) $f['total_charges_emp']) ?></td>
            <td class="num col-cout"><?= cout_emp_affiche($f) ?></td>
            <td class="center"><?php if (trim((string) ($f['email_envoye_le'] ?? '')) !== ''): ?><span class="mail-sent" title="Envoyée le <?= e(date('d.m.Y', strtotime((string) $f['email_envoye_le']))) ?>"><?= icon('check') ?></span><?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <?php
            $totBrut       = (float) $totaux['brut'];
            $totDed        = (float) $totaux['ded'];
            $totImpot      = (float) $totaux['impot'];
            $totNet        = (float) $totaux['net'];
            $totChargesEmp = (float) $totaux['charges_emp'];
            $totCoutEmp    = (float) $totaux['cout_emp'];
        ?>
        <tr>
            <td>Total</td>
            <?php if ($axesParFiche): ?><td></td><?php endif; ?>
            <td class="num"><?= chf($totBrut) ?></td>
            <td class="num col-petit"><?= chf($totDed) ?></td>
            <td class="num col-petit"><?= chf($totImpot) ?></td>
            <td class="num"><?= chf($totNet) ?></td>
            <td></td>
            <td class="num col-petit"><?= chf($totChargesEmp) ?></td>
            <td class="num"><?= $totChargesEmp > 0 ? chf($totCoutEmp) : '—' ?></td>
            <td></td>
        </tr>
    </tfoot>
</table>
</div>
<?php require __DIR__ . '/_pagination.php'; ?>
<?php endif; ?>
</div></div>
