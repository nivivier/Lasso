<?php /** @var array $factures */ /** @var array $statut */ /** @var array $annee */ /** @var array $annees */
/** @var bool $avecEvenements */ /** @var string $recherche */ /** @var bool $modeClient */
/** @var string $pgRoute */ /** @var array $pgParams */ /** @var int $pgPage */ /** @var int $pgTaille */ /** @var int $pgTotal */

// Filtres de colonne (EXPÉRIMENTAL, même mécanique que ?p=fiches) : Émission
// (ex-Année) et Paiement (ex-Statut), à la place des 2 <select> de la toolbar.
$statutLabels = ['brouillon' => 'Brouillons', 'emise' => 'Émises', 'en_retard' => 'En retard', 'payee' => 'Payées', 'annulee' => 'Annulées'];
$anneeLabels = [];
foreach (array_unique(array_merge($annee, [(int) date('Y')], $annees)) as $a) { $anneeLabels[(int) $a] = (string) (int) $a; }
$autresStatut = array_filter(['annee' => $annee, 'q' => $recherche]);
$autresAnnee  = array_filter(['statut' => $statut, 'q' => $recherche]);
?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>

<div class="module-content"><div class="module-content-inner">
    <div class="toolbar">
        <form method="get" class="filters">
            <input type="hidden" name="p" value="facturation_liste">
            <?= champ_recherche(['id' => 'facturation-search', 'name' => 'q', 'valeur' => $recherche, 'submit' => true]) ?>
        </form>
        <?php
        // Voir ?p=fiches : sur téléphone la mise en cartes masque le <thead>,
        // le bouton « Filtres » reprend les entonnoirs qui y étaient accrochés.
        ob_start(); ?>
            <?= filtre_colonne_html('facturation_liste', 'annee', $anneeLabels, $annee, $autresAnnee, 'Émission') ?>
            <?= filtre_colonne_html('facturation_liste', 'statut', $statutLabels, $statut, $autresStatut, 'Paiement') ?>
        <?php
        $fmColonnes = ob_get_clean();
        $fmActifs = filtre_colonne_actifs_html('facturation_liste', 'annee', $anneeLabels, $annee, $autresAnnee)
            . filtre_colonne_actifs_html('facturation_liste', 'statut', $statutLabels, $statut, $autresStatut);
        require __DIR__ . '/_filtres_mobile.php';
        ?>
        <?php if (peut_ecrire('facturation')): ?>
        <div class="head-actions">
            <a class="btn" href="?p=facturation_form"><?= icon('file-plus') ?><span class="lbl"> Nouvelle facture</span></a>
        </div>
        <?php endif; ?>
    </div>

<?php $nbCols = 6 + ($avecEvenements ? 1 : 0); ?>
<div class="table-scroll">
<table class="list list-wide liste-cartes cartes-factures">
    <thead><tr>
        <th>Numéro</th><th>Structure</th>
        <th class="col-date">
            <span class="col-th">
                Émission
                <?= filtre_colonne_html('facturation_liste', 'annee', $anneeLabels, $annee, $autresAnnee) ?>
            </span>
        </th>
        <th>Échéance</th>
        <?php if ($avecEvenements): ?><th>Événement</th><?php endif; ?>
        <th class="num">Montant</th>
        <th class="col-paiement">
            <span class="col-th">
                Paiement
                <?= filtre_colonne_html('facturation_liste', 'statut', $statutLabels, $statut, $autresStatut) ?>
            </span>
        </th>
    </tr></thead>
    <tbody>
    <?php if (!$factures): ?>
        <tr><td colspan="<?= $nbCols ?>" class="muted"><?php if ($recherche !== ''): ?>Aucune facture ne correspond à « <?= e($recherche) ?> ».<?php else: ?>Aucune facture pour cette sélection.<?php endif; ?></td></tr>
    <?php else: ?>
    <?php
    $prevMois = null;
    foreach ($factures as $f):
        $moisCle = substr((string) ($f['date_emission'] ?: $f['cree_le']), 0, 7);
        if ($moisCle !== $prevMois):
            $prevMois = $moisCle;
    ?>
        <tr class="mois-sep"><td colspan="<?= $nbCols ?>"><?= e(mois_nom((int) substr($moisCle, 5, 2)) . ' ' . substr($moisCle, 0, 4)) ?></td></tr>
    <?php endif; ?>
        <?php $hrefLigne = '?p=facture&id=' . (int) $f['id'] . suffixe_retour_liste($recherche, $pgPage); ?>
        <tr class="row-link" tabindex="0" role="link" data-href="<?= e($hrefLigne) ?>">
            <td class="col-numero"><a href="<?= e($hrefLigne) ?>" class="titre-lien"><?= $f['numero'] !== '' ? e($f['numero']) : '<span class="muted">(brouillon)</span>' ?></a></td>
            <td class="col-structure"><strong><?= e($f['structure_nom']) ?></strong></td>
            <td class="muted small"><?= $f['date_emission'] !== '' ? e(date('d.m.Y', strtotime($f['date_emission']))) : '—' ?></td>
            <td class="muted small col-echeance"><?= $f['date_echeance'] !== '' ? e(date('d.m.Y', strtotime($f['date_echeance']))) : '—' ?></td>
            <?php if ($avecEvenements): ?>
                <td class="muted small">
                    <?php if (!empty($f['evenement_date'])): ?>
                        <?= e(date('d.m.Y', strtotime($f['evenement_date']))) ?><?= $f['spectacle_nom'] ? ' — ' . e($f['spectacle_nom']) : '' ?>
                    <?php else: ?>—<?php endif; ?>
                </td>
            <?php endif; ?>
            <td class="num strong col-montant"><?= chf((float) $f['montant_total']) ?></td>
            <td class="col-paiement"><?= facturation_badge($f) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>
</div>
<?php if ($factures): ?><?php require __DIR__ . '/' . ($modeClient ? '_pagination_client.php' : '_pagination.php'); ?><?php endif; ?>
</div></div>
<script nonce="<?= e(csp_nonce()) ?>">
<?php if ($modeClient): ?>
lassoListeClient({
    tableSelector: '.list-wide',
    searchInputSelector: '#facturation-search',
    separatorSelector: '.mois-sep',
});
<?php else: ?>
lassoRechercheServeur(document.getElementById('facturation-search'));
<?php endif; ?>
</script>
