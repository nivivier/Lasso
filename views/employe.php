<?php /** @var array $emp */ /** @var array $fiches */
// Le corps de la fiche vit dans _employe_fiche.php, partagé avec la vue deux
// colonnes (?p=employes&vue=duo). ?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>

<div class="module-content"><div class="module-content-inner">
<?php require __DIR__ . '/_employe_fiche.php'; ?>
</div></div>
