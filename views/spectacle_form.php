<?php /** @var ?array $spectacle */ /** @var ?string $err */
$v = fn (string $k, $d = '') => e((string) ($spectacle[$k] ?? $d));
$isEdit = !empty($spectacle['id']);
?>
<?= lien_retour('?p=spectacles', 'Spectacles') ?>
<div class="page-head">
    <h1><?= $isEdit ? 'Modifier le spectacle' : 'Nouveau spectacle' ?></h1>
</div>

<?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>

<form method="post" action="?p=spectacle<?= $isEdit ? '&id=' . (int) $spectacle['id'] : '' ?>" class="card form" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <label>Nom <input name="nom" value="<?= $v('nom') ?>" required></label>
    <label>Notes (optionnel)
        <textarea name="notes" rows="2"><?= $v('notes') ?></textarea>
    </label>

    <label>Feuille SUISA pré-remplie (PDF, optionnel)
        <input type="file" name="suisa_feuille" accept="application/pdf">
    </label>
    <p class="muted small">2 Mo maximum.</p>
    <?php if (!empty($spectacle['suisa_feuille_fichier'])): ?>
        <p class="muted small">
            Fichier actuel : <a href="<?= e($spectacle['suisa_feuille_fichier']) ?>" target="_blank" rel="noopener">le PDF déjà déposé</a>.
        </p>
        <label class="check">
            <input type="checkbox" name="suisa_feuille_supprimer" value="1">
            Supprimer le fichier actuel
        </label>
    <?php endif; ?>

    <div class="form-actions">
        <button type="submit">Enregistrer</button>
        <a class="btn ghost" href="?p=spectacles">Annuler</a>
    </div>
</form>
