<?php /** @var array $fiches */ /** @var array $annee */ /** @var array $annees */ /** @var array $statut */
/** @var array $employes */ /** @var array $employeId */ /** @var string $recherche */
/** @var array $axesParFiche */ /** @var array $totaux */ /** @var array $tri */
/** @var string $pgRoute */ /** @var array $pgParams */ /** @var int $pgPage */ /** @var int $pgTaille */ /** @var int $pgTotal */ ?>
<?php
// Filtres de colonne (EXPÉRIMENTAL) : Statut/Date/Employé vivent chacun à
// côté du titre de la colonne qu'ils filtrent réellement plutôt que dans la
// barre d'outils — voir filtre_colonne_html()/filtre_colonne_actifs_html()
// (lib/helpers.php) et .col-filter* (assets/css/). $autresXxx : les
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
// Les liens de tri reportent tous les filtres actifs : l'URL d'une liste triée
// dit alors ce qu'elle montre. ⚠️ Ce report n'est qu'un reflet, il ne PILOTE
// rien — sans son marqueur « <clé>_set », filtre_coche() ignore `$_GET` et
// retombe sur la session (voir lien_liste_filtree() pour un lien qui, lui, doit
// vraiment imposer un filtre). C'est la session qui fait survivre les
// entonnoirs au clic sur un en-tête.
$tousFiltres = array_filter(['statut' => $statut, 'annee' => $annee, 'employe_id' => $employeId, 'q' => $recherche]);

// Liens du sélecteur d'affichage (liste / deux colonnes) : mêmes filtres
// actifs, seule la vue change — comme ?p=structures.
$qsSansVue = $_GET;
unset($qsSansVue['p'], $qsSansVue['vue'], $qsSansVue['sel']);
$lienDuo = fn (int $idF): string => '?p=fiches&' . http_build_query($qsSansVue + ['vue' => 'duo', 'sel' => $idF]);
$vue = $vue ?? 'liste';
$duoSel = (int) ($duoSel ?? 0);
// ⚠️ La boucle de la liste utilise $f, comme la fiche : elle l'écraserait
// avant que le volet droit soit rendu. On met la fiche choisie de côté.
$fChoisie = $f ?? null;
?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>

<div class="module-content"><div class="module-content-inner">
    <div class="toolbar<?= $vue === 'duo' ? ' toolbar-carte toolbar-carte-panneau' : '' ?>">
        <form method="get" class="filters">
            <input type="hidden" name="p" value="fiches">
            <input type="hidden" name="vue" value="<?= e($vue) ?>">
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
        // En deux colonnes la liste est en cartes, son <thead> est masqué : le
        // panneau « Filtres » reste visible à toute largeur (voir ?p=structures).
        $fmClasse = $vue === 'duo' ? 'filtres-carte' : '';
        require __DIR__ . '/_filtres_mobile.php';
        ?>
        <div class="head-actions">
            <?= bascule_vue_html('fiches', $vue, $qsSansVue) ?>
            <?php if (peut_lire('coeur')):
                // Rangé derrière « … » par le bandeau. L'année suit le filtre
                // quand il n'en retient qu'une : c'est celle qu'on exporte.
                $lienCertificats = ['p' => 'export', 'type' => 'certificats'] + (count($annee) === 1 ? ['annee' => (int) reset($annee)] : []); ?>
            <a class="btn ghost" href="?<?= e(http_build_query($lienCertificats)) ?>"><?= icon('download') ?> <span class="lbl">Exporter pour certificats de salaire</span></a>
            <?php endif; ?>
            <?php if (peut_ecrire('salaires')): ?>
            <a class="btn" href="?p=fiche_form" title="Nouvelle fiche"><?= icon('file-plus') ?> <span class="lbl">Nouvelle fiche</span></a>
            <?php endif; ?>
        </div>
    </div>

<?php // 7 = Date, Employé, Brut, Net, Paiement, Coût employeur, Envoyée. ?>
<?php $nbCols = 7 + ($axesParFiche ? 1 : 0); ?>
<?php
// En deux colonnes, la liste n'est pas réécrite : elle est rendue telle quelle
// et CAPTURÉE pour aller dans le volet de gauche (voir views/_duo.php).
if ($vue === 'duo') { ob_start(); }
?>
<div class="table-scroll">
<table class="list list-wide liste-cartes cartes-fiches">
    <thead>
        <tr>
            <?php // Les en-têtes triables portent un lien (tri_entete_html()). « Axes »
                  // et « Envoyée » n'en sont pas : le premier est un agrégat calculé
                  // après coup, le second une icône d'état à deux valeurs que
                  // l'entonnoir « Paiement » dit déjà mieux. ?>
            <?php $triCol = fn (string $cle, string $lib): string => tri_entete_html('fiches', $cle, $lib, $tri, $tousFiltres); ?>
            <th class="col-reinit-hote col-date"><?= bouton_reinit_filtres('fiches', ['statut', 'annee', 'employe_id'], (bool) ($statut || $annee || $employeId)) ?>
                <span class="col-th">
                    <?= $triCol('date', 'Date') ?>
                    <?= filtre_colonne_html('fiches', 'annee', $anneeLabels, $annee, $autresAnnee) ?>
                </span>
            </th>
            <th class="col-employe">
                <span class="col-th">
                    <?= $triCol('employe', 'Employé') ?>
                    <?= filtre_colonne_html('fiches', 'employe_id', $employeLabels, $employeId, $autresEmploye) ?>
                </span>
            </th>
            <?php if ($axesParFiche): ?><th class="col-petit">Axes</th><?php endif; ?>
            <?php // Charges sociales, impôt à la source et charges patronales ne
                  // figurent plus ici : onze colonnes de même poids ne se lisaient
                  // plus. Ce détail reste sur la fiche elle-même, où on le consulte
                  // vraiment, et dans les totaux de « Cotisations ». ?>
            <th class="num"><?= $triCol('brut', 'Brut') ?></th>
            <th class="num"><?= $triCol('net', 'Net') ?></th>
            <th class="col-paiement">
                <span class="col-th">
                    <?= $triCol('paiement', 'Paiement') ?>
                    <?= filtre_colonne_html('fiches', 'statut', $statutLabels, $statut, $autresStatut) ?>
                </span>
            </th>
            <th class="num"><?= $triCol('cout', 'Coût employeur') ?></th>
            <th class="center col-petit">Envoyée
            </th>
        </tr>
    </thead>
    <tbody>
    <?php if (!$fiches): ?>
        <tr><td colspan="<?= $nbCols ?>" class="muted">Aucune fiche pour cette sélection.</td></tr>
    <?php else: ?>
    <?php // Les intertitres d'année n'ont de sens que sur une liste rangée par
          // date : triée par montant ou par employé, l'année se remet à changer à
          // chaque ligne et le séparateur ne sépare plus rien.
          $anneesSeparees = $tri['cle'] === '' || $tri['cle'] === 'date'; ?>
    <?php $anneePrec = null;
    foreach ($fiches as $f):
        $apayer = trim((string) $f['date_paiement']) === '' && !fiche_a_venir($f);
        $anneeCourante = (int) $f['annee'];
        if ($anneesSeparees && $anneeCourante !== $anneePrec): $anneePrec = $anneeCourante; ?>
        <tr class="fiche-mois-sep"><td colspan="<?= $nbCols ?>"><?= $anneeCourante ?></td></tr>
    <?php endif; ?>
        <?php $hrefLigne = $vue === 'duo' ? $lienDuo((int) $f['id']) : '?p=fiche&id=' . (int) $f['id']; ?>
        <tr class="row-link <?= $vue === 'duo' && (int) $f['id'] === $duoSel ? 'ligne-active' : '' ?>" tabindex="0" role="link" data-href="<?= e($hrefLigne) ?>">
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
            <td class="center col-envoyee"><?php if (trim((string) ($f['email_envoye_le'] ?? '')) !== ''): ?><span class="mail-sent" title="Envoyée le <?= e(horodatage($f['email_envoye_le'], 'd.m.Y')) ?>"><?= icon('check') ?></span><?php endif; ?></td>
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
<?php if ($vue === 'duo'): ?>
<?php
$duoListe = ob_get_clean();
// La fiche de droite est celle de la page pleine : le même partiel, les mêmes
// données (fiche_donnees_vue()). $f a servi de variable de boucle juste
// au-dessus — on remet la fiche choisie avant de la rendre.
$f = $fChoisie;
$duoFiche = $f !== null ? '_fiche_fiche.php' : '';
$duoVide  = 'Choisissez une fiche dans la liste.';
require __DIR__ . '/_duo.php';
?>
<?php endif; ?>
</div></div>
