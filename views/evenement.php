<?php
/** @var ?array $evenement */ /** @var int $id */ /** @var ?string $err */ /** @var array $post */
// La préparation et le corps de la fiche vivent dans _evenement_fiche.php,
// partagés avec la vue deux colonnes (?p=evenements&vue=duo).
$isEdit = ((int) $id) > 0;
?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>

<div class="module-content"><div class="module-content-inner">
<?php require __DIR__ . '/_evenement_fiche.php'; ?>
</div></div>
