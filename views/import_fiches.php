<?php
/** @var ?string $errFiches */ /** @var ?array $resultatsFiches */ /** @var ?array $resumeFiches */ /** @var bool $simuleFiches */
/** @var ?string $errFactures */ /** @var ?array $resultatsFactures */ /** @var ?array $resumeFactures */ /** @var bool $simuleFactures */
/** @var ?array $msgEcritures */
?>
<?php require __DIR__ . '/_param_tabs.php'; ?>
<?php if (module_actif('salaires')): ?>
    <?php require __DIR__ . '/_import_fiches_section.php'; ?>
<?php endif; ?>
<?php if (module_actif('facturation')): ?>
    <?php require __DIR__ . '/_import_factures_section.php'; ?>
<?php endif; ?>
<?php if (module_actif('compta')): ?>
    <?php require __DIR__ . '/_import_ecritures_section.php'; ?>
<?php endif; ?>
