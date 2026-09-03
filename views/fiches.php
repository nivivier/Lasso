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
        <?php
        // Les mêmes filtres qu'en en-tête de colonne, repris derrière le bouton
        // « Filtres » : sur téléphone la mise en cartes masque le <thead> et les
        // entonnoirs avec lui. Ils portent ici leur libellé, faute d'en-tête
        // pour les nommer.
        ob_start(); ?>
            <?= filtre_colonne_html('fiches', 'annee', $anneeLabels, $annee, $autresAnnee, 'Année') ?>
            <?= filtre_colonne_html('fiches', 'employe_id', $employeLabels, $employeId, $autresEmploye, 'Employé') ?>
            <?= filtre_colonne_html('fiches', 'statut', $statutLabels, $statut, $autresStatut, 'Paiement') ?>
        <?php
        $fmColonnes = ob_get_clean();
        $fmActifs = filtre_colonne_actifs_html('fiches', 'annee', $anneeLabels, $annee, $autresAnnee)
            . filtre_colonne_actifs_html('fiches', 'employe_id', $employeLabels, $employeId, $autresEmploye)
            . filtre_colonne_actifs_html('fiches', 'statut', $statutLabels, $statut, $autresStatut);
        require __DIR__ . '/_filtres_mobile.php';
        ?>
        <?php if (peut_ecrire('salaires')): ?>
        <div class="head-actions">
            <a class="btn" href="?p=fiche_new" title="Nouvelle fiche"><?= icon('file-plus') ?> <span class="lbl">Nouvelle fiche</span></a>
        </div>
        <?php endif; ?>
    </div>

<?php // 7 = Date, Employé, Brut, Net, Paiement, Coût employeur, Envoyée. ?>
<?php $nbCols = 7 + ($axesParFiche ? 1 : 0); ?>
<div class="table-scroll">
<table class="list list-wide liste-cartes cartes-fiches">
    <thead>
        <tr>
            <th class="col-reinit-hote col-date"><?= bouton_reinit_filtres('fiches', ['statut', 'annee', 'employe_id'], (bool) ($statut || $annee || $employeId)) ?>
                <span class="col-th">
                    Date
                    <?= filtre_colonne_html('fiches', 'annee', $anneeLabels, $annee, $autresAnnee) ?>
                </span>
            </th>
            <th class="col-employe">
                <span class="col-th">
                    Employé
                    <?= filtre_colonne_html('fiches', 'employe_id', $employeLabels, $employeId, $autresEmploye) ?>
                </span>
            </th>
            <?php if ($axesParFiche): ?><th class="col-petit">Axes</th><?php endif; ?>
            <?php // Charges sociales, impôt à la source et charges patronales ne
                  // figurent plus ici : onze colonnes de même poids ne se lisaient
                  // plus. Ce détail reste sur la fiche elle-même, où on le consulte
                  // vraiment, et dans les totaux de « Cotisations ». ?>
            <th class="num">Brut</th>
            <th class="num">Net</th>
            <th class="col-paiement">
                <span class="col-th">
                    Paiement
                    <?= filtre_colonne_html('fiches', 'statut', $statutLabels, $statut, $autresStatut) ?>
                </span>
            </th>
            <th class="num">Coût employeur</th>
            <th class="center col-petit">Envoyée
            </th>
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
            <td class="col-employe"><?= avatar_initiales((string) $f['employe_nom'], (string) ($f['avatar_couleur'] ?? ''), (string) ($f['avatar_photo'] ?? '')) ?><?= e($f['employe_nom']) ?></td>
            <?php if ($axesParFiche): ?>
            <?php // Étiquettes plutôt qu'une liste séparée par des virgules : un axe
                  // est une catégorie, pas une phrase — même composant que les
                  // étiquettes de structures. ?>
            <td class="col-axes"><?php foreach (array_filter(array_map('trim', explode(',', (string) ($axesParFiche[(int) $f['id']] ?? '')))) as $axe): ?><span class="badge muted-badge"><?= e($axe) ?></span><?php endforeach; ?></td>
            <?php endif; ?>
            <td class="num col-brut"><?= chf_ou_zero((float) $f['salaire_brut']) ?></td>
            <td class="num strong col-net <?= $apayer ? 'net-apayer' : (fiche_a_venir($f) ? 'net-avenir' : '') ?>"><?= chf_ou_zero((float) $f['salaire_net']) ?></td>
            <td class="col-paiement"><?= badge_paiement($f) ?></td>
            <td class="num col-cout"><?= cout_emp_affiche($f) ?></td>
            <td class="center col-envoyee"><?php if (trim((string) ($f['email_envoye_le'] ?? '')) !== ''): ?><span class="mail-sent" title="Envoyée le <?= e(date('d.m.Y', strtotime((string) $f['email_envoye_le']))) ?>"><?= icon('check') ?></span><?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
    <?php if ($fiches): ?>
    <tfoot>
        <?php
            $totBrut       = (float) $totaux['brut'];
            $totNet        = (float) $totaux['net'];
            // Toujours lu, bien que sa colonne ait disparu : il décide si le coût
            // employeur a un sens à afficher (voir cout_emp_affiche()).
            $totChargesEmp = (float) $totaux['charges_emp'];
            $totCoutEmp    = (float) $totaux['cout_emp'];
        ?>
        <tr>
            <td></td>
            <td>Total</td>
            <?php if ($axesParFiche): ?><td></td><?php endif; ?>
            <td class="num"><?= chf($totBrut) ?></td>
            <td class="num"><?= chf($totNet) ?></td>
            <td></td>
            <td class="num"><?= $totChargesEmp > 0 ? chf($totCoutEmp) : '—' ?></td>
            <td></td>
        </tr>
    </tfoot>
    <?php endif; ?>
</table>
</div>
<?php if ($fiches): ?><?php require __DIR__ . '/_pagination.php'; ?><?php endif; ?>
</div></div>
