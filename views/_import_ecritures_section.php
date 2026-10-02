<?php /** @var ?array $msgEcritures */
// Résultats de l'import d'écritures — le formulaire d'upload est désormais
// unique (voir import.php, « Importer des données »). ?>
<?php $msg = $msgEcritures; $actionUrl = '?p=compta_ecritures_importer_valider'; require __DIR__ . '/_import_ecritures_preview.php'; ?>
