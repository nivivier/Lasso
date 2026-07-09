<?php /** @var ?array $spectacle */ /** @var ?string $err */
$v = fn (string $k, $d = '') => e((string) ($spectacle[$k] ?? $d));
$isEdit = !empty($spectacle['id']);
$termeSingulier = mb_strtolower(evenements_terme_spectacle(false));
?>
<?= lien_retour('?p=spectacles', evenements_terme_spectacle()) ?>
<div class="page-head">
    <h1><?= $isEdit ? 'Modifier le ' . e($termeSingulier) : 'Nouveau ' . e($termeSingulier) ?></h1>
</div>

<?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>

<form method="post" action="?p=spectacle<?= $isEdit ? '&id=' . (int) $spectacle['id'] : '' ?>" class="card form" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <label>Nom <input name="nom" value="<?= $v('nom') ?>" required></label>
    <label>Notes (optionnel)
        <textarea name="notes" rows="2"><?= $v('notes') ?></textarea>
    </label>

    <label><span>Feuille SUISA pré-remplie (PDF, optionnel) <?= info_tip('2 Mo maximum.') ?></span>
        <input type="file" name="suisa_feuille" accept="application/pdf">
    </label>
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
        <button type="submit"><?= icon('save') ?> Enregistrer</button>
        <a class="btn ghost" href="?p=spectacles">Annuler</a>
    </div>
</form>
