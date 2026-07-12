<?php /** @var ?array $msgEcritures */ ?>

<div class="card form mt-22">
<h2 class="mt-0">Importer des écritures bancaires</h2>
    <p class="muted small">Importez un export PostFinance (CSV) ou un relevé <strong>ISO 20022 camt.053</strong> (XML) des mouvements bancaires. Le compte est retrouvé par <strong>IBAN</strong> — s'il n'existe pas encore, vous pourrez lui donner un nom après avoir simulé l'import. Les écritures déjà importées sont <strong>ignorées</strong> (dédoublonnage automatique) ; les règles de lettrage sont appliquées après l'import.</p>
    <form method="post" action="?p=import_ecritures" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <label>Fichier (CSV ou XML)
            <input type="file" name="fichier" accept=".csv,text/csv,.xml,application/xml,text/xml" required>
        </label>
        <div class="form-actions">
            <button type="submit" name="simuler" value="1" class="btn ghost"><?= icon('bar-chart') ?> Simuler</button>
            <button type="submit" name="appliquer" value="1" onclick="return confirm('Importer réellement ces écritures ?');"><?= icon('import') ?> Importer directement</button>
        </div>
        <p class="muted small">« Simuler » montre ce qui serait importé (et permet de nommer un nouveau compte) sans rien enregistrer.</p>
    </form>
</div>

<?php $msg = $msgEcritures; $actionUrl = '?p=import_ecritures'; require __DIR__ . '/_import_ecritures_preview.php'; ?>
