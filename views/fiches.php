<?php /** @var array $fiches */ /** @var array $annee */ /** @var array $annees */ /** @var array $statut */
/** @var array $employes */ /** @var array $employeId */ /** @var string $recherche */
/** @var array $axesParFiche */ /** @var array $totaux */
/** @var string $pgRoute */ /** @var array $pgParams */ /** @var int $pgPage */ /** @var int $pgTaille */ /** @var int $pgTotal */ ?>
<?php
// Filtres de colonne (EXPÉRIMENTAL) : Statut/Date/Employé vivent chacun à
// côté du titre de la colonne qu'ils filtrent réellement plutôt que dans la
// barre d'outils — voir filtre_colonne_html()/filtre_colonne_actifs_html()
// (lib/helpers.php) et .col-filter* (assets/app.css). $autresXxx : les
// AUTRES filtres actifs de la page, reportés en hidden inputs par chaque
// panneau pour ne pas se perdre entre eux quand on n'en modifie qu'un.
$statutLabels = ['avenir' => 'À venir', 'apayer' => 'À payer', 'payees' => 'Payées'];
$anneeLabels = [];
foreach ($annees as $a) { $anneeLabels[(int) $a] = (string) (int) $a; }
$employeLabels = [];
foreach ($employes as $emp) { $employeLabels[(int) $emp['id']] = trim($emp['prenom'] . ' ' . $emp['nom']); }
$autresStatut  = array_filter(['annee' => $annee, 'employe_id' => $employeId, 'q' => $recherche]);
$autresAnnee   = array_filter(['statut' => $statut, 'employe_id' => $employeId, 'q' => $recherche]);
$autresEmploye = array_filter(['statut' => $statut, 'annee' => $annee, 'q' => $recherche]);
?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>

<div class="module-content"><div class="module-content-inner">
    <div class="toolbar">
        <form method="get" class="filters">
            <input type="hidden" name="p" value="fiches">
            <?= champ_recherche(['name' => 'q', 'valeur' => $recherche, 'submit' => true]) ?>
        </form>
        <?php if (peut_ecrire('salaires')): ?>
        <div class="head-actions">
            <a class="btn" href="?p=fiche_new" title="Nouvelle fiche"><?= icon('file-plus') ?> <span class="lbl">Nouvelle fiche</span></a>
        </div>
        <?php endif; ?>
    </div>

<?php $nbCols = 10 + ($axesParFiche ? 1 : 0); ?>
<div class="table-scroll">
<table class="list list-wide">
    <thead>
        <tr>
            <th class="col-date">
                <span class="col-th">
                    Date
                    <?= filtre_colonne_html('fiches', 'annee', $anneeLabels, $annee, $autresAnnee) ?>
                </span>
                <?= filtre_colonne_actifs_html('fiches', 'annee', $anneeLabels, $annee, $autresAnnee) ?>
            </th>
            <th class="col-employe">
                <span class="col-th">
                    Employé
                    <?= filtre_colonne_html('fiches', 'employe_id', $employeLabels, $employeId, $autresEmploye) ?>
                </span>
                <?= filtre_colonne_actifs_html('fiches', 'employe_id', $employeLabels, $employeId, $autresEmploye) ?>
            </th>
            <?php if ($axesParFiche): ?><th class="col-petit">Axes</th><?php endif; ?>
            <th class="num">Brut</th><th class="num col-petit">Charges sociales</th><th class="num col-petit">Impôt à la source</th>
            <th class="num">Net</th>
            <th class="col-paiement">
                <span class="col-th">
                    Paiement
                    <?= filtre_colonne_html('fiches', 'statut', $statutLabels, $statut, $autresStatut) ?>
                </span>
                <?= filtre_colonne_actifs_html('fiches', 'statut', $statutLabels, $statut, $autresStatut) ?>
            </th>
            <th class="num col-petit">Charges patronales</th><th class="num">Coût employeur</th>
            <th class="center col-petit">Envoyée</th>
        </tr>
    </thead>
    <tbody>
    <?php if (!$fiches): ?>
        <tr><td colspan="<?= $nbCols ?>" class="muted">Aucune fiche pour cette sélection.</td></tr>
    <?php else: ?>
    <?php $anneePrec = null;
    foreach ($fiches as $f):
        $apayer = trim((string) $f['date_paiement']) === '' && !fiche_a_venir($f);
        $anneeCourante = (int) $f['annee'];
        if ($anneeCourante !== $anneePrec): $anneePrec = $anneeCourante; ?>
        <tr class="fiche-mois-sep"><td colspan="<?= $nbCols ?>"><?= $anneeCourante ?></td></tr>
    <?php endif; ?>
        <?php $hrefLigne = '?p=fiche&id=' . (int) $f['id']; ?>
        <tr class="row-link" tabindex="0" role="link" data-href="<?= e($hrefLigne) ?>">
            <td class="col-date"><a href="<?= e($hrefLigne) ?>" class="titre-lien"><?= e(mois_nom((int) $f['mois'])) ?> <?= (int) $f['annee'] ?></a></td>
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
    <?php endif; ?>
    </tbody>
    <?php if ($fiches): ?>
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
            <td></td>
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
    <?php endif; ?>
</table>
</div>
<?php if ($fiches): ?><?php require __DIR__ . '/_pagination.php'; ?><?php endif; ?>
</div></div>
