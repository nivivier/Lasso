<?php /** @var array $p */ /** @var array $structures */ /** @var array $employes */
/** @var array $utilisateurs */ /** @var array $pieces */ /** @var bool $ok */
// Le corps de la fiche vit dans _personne_fiche.php, partagé avec la vue deux
// colonnes (?p=personnes&vue=duo).
?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>

<div class="module-content"><div class="module-content-inner">
<?php require __DIR__ . '/_personne_fiche.php'; ?>
</div></div>
