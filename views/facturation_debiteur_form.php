<?php /** @var ?array $debiteur */ /** @var ?string $err */
$v = fn(string $k, $d = '') => e((string) ($debiteur[$k] ?? $d));
$isEdit = !empty($debiteur['id']);
?>
<?= lien_retour_contextuel('?p=facturation_debiteurs', 'Débiteurs') ?>
<div class="page-head">
    <h1><?= $isEdit ? 'Modifier le débiteur' : 'Nouveau débiteur' ?></h1>
</div>

<?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>

<form method="post" action="?p=debiteur<?= $isEdit ? '&id=' . (int) $debiteur['id'] : '' ?>" class="card form">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <div class="grid2">
        <label>Nom / raison sociale <input name="nom" value="<?= $v('nom') ?>" required></label>
        <label>Type
            <select name="type">
                <option value="organisation" <?= ($debiteur['type'] ?? 'organisation') === 'organisation' ? 'selected' : '' ?>>Organisation</option>
                <option value="particulier" <?= ($debiteur['type'] ?? '') === 'particulier' ? 'selected' : '' ?>>Particulier</option>
            </select>
        </label>
    </div>
    <div class="grid3">
        <label>Rue et numéro <input name="adresse_rue" value="<?= $v('adresse_rue') ?>"></label>
        <label>NPA <input name="adresse_npa" value="<?= $v('adresse_npa') ?>"></label>
        <label>Localité <input name="adresse_localite" value="<?= $v('adresse_localite') ?>"></label>
    </div>
    <div class="grid2">
        <label>Pays <input name="adresse_pays" value="<?= $v('adresse_pays', 'Suisse') ?>"></label>
        <label>E-mail (optionnel) <input name="email" type="email" value="<?= $v('email') ?>"></label>
    </div>
    <label>Notes (optionnel)
        <textarea name="notes" rows="2"><?= $v('notes') ?></textarea>
    </label>

    <label class="check">
        <input type="checkbox" name="actif" value="1" <?= (!$isEdit || $debiteur['actif']) ? 'checked' : '' ?>>
        Débiteur actif (proposé à la création d'une facture)
    </label>

    <div class="form-actions">
        <button type="submit"><?= icon('save') ?> Enregistrer</button>
        <a class="btn ghost" href="?p=facturation_debiteurs">Annuler</a>
    </div>
</form>
