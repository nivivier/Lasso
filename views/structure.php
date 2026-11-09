<?php /** @var ?array $structure */ /** @var ?string $err */
/** @var array $categoriesPourSelect */ /** @var int $categorieIdSelectionnee */
/** @var array $contacts */ /** @var array $notes */ /** @var array $tags */ /** @var array $tagsDispo */
/** @var array $lieuxLies */ /** @var array $lieuxDispo */ /** @var array $organisateurDispo */ /** @var array $categoriesLieu */
/** @var array $campagnesStructure */ /** @var array $campagnesLiees */ /** @var array $campagnesDispo */ /** @var array $contactsJoignables */ /** @var array $expediteurs */ /** @var array $modelesMessage */ /** @var ?array $brouillon */
/** @var array $projetsDispo */ /** @var array $campagneProjets */ /** @var array $dossiersFonds */
// La préparation et le corps de la fiche vivent dans _structure_fiche.php,
// partagés avec la vue deux colonnes (?p=structures&vue=duo).
?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>

<div class="module-content"><div class="module-content-inner">
<?= lien_retour_contextuel('?p=structures', 'Structures') ?>
<?php require __DIR__ . '/_structure_fiche.php'; ?>
</div></div>
<?php require __DIR__ . '/_region_select_js.php'; ?>
