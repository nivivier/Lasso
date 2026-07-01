<?php /** @var ?array $msgEcritures */ ?>
<?php if ($msgEcritures): ?><p class="<?= $msgEcritures[0] === 'ok' ? 'ok' : 'err' ?> flash"><?= e($msgEcritures[1]) ?></p><?php endif; ?>

<div class="card form mt-22">
<h2 class="mt-0">Importer des écritures bancaires</h2>
    <p class="muted small">Importez un export PostFinance (CSV) des mouvements bancaires. Le compte est retrouvé (ou créé) par <strong>IBAN</strong>. Les écritures déjà importées sont <strong>ignorées</strong> (dédoublonnage automatique) ; les règles de lettrage sont appliquées après l'import.</p>
    <form method="post" action="?p=import_ecritures" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <label>Fichier CSV
            <input type="file" name="fichier" accept=".csv,text/csv" required>
        </label>
        <div class="form-actions">
            <button type="submit"><?= icon('import') ?> Importer</button>
        </div>
    </form>
</div>
