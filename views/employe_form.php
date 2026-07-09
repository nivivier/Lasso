<?php /** @var ?array $emp */ /** @var ?string $err */
$v = fn(string $k, $d = '') => e((string) ($emp[$k] ?? $d));
$isEdit = !empty($emp['id']);
?>
<?= lien_retour($isEdit ? '?p=employe_voir&id=' . (int) $emp['id'] : '?p=employes', $isEdit ? 'Fiche employé' : 'Employés') ?>
<div class="page-head">
    <h1><?= $isEdit ? 'Modifier l\'employé' : 'Nouvel employé' ?></h1>
</div>

<?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>

<form method="post" action="?p=employe<?= $isEdit ? '&id=' . (int) $emp['id'] : '' ?>" class="card form">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <div class="grid2">
        <label>Prénom <input name="prenom" value="<?= $v('prenom') ?>" required></label>
        <label>Nom <input name="nom" value="<?= $v('nom') ?>" required></label>
    </div>
    <div class="grid3">
  	 	<label>E-mail (optionnel) <input name="email" type="email" value="<?= $v('email') ?>" placeholder="prenom@exemple.ch"></label>
		<label>Rue <input name="rue" value="<?= $v('rue') ?>"></label>
        <label>NPA, localité <input name="npa_localite" value="<?= $v('npa_localite') ?>"></label>
    </div>
    <div class="grid3">
        <label>Numéro AVS <input name="numero_avs" value="<?= $v('numero_avs') ?>" placeholder="756.XXXX.XXXX.XX"></label>
		<label>Date de naissance <input name="date_naissance" type="date" value="<?= $v('date_naissance') ?>"></label>
        <label>Canton
            <select name="canton">
                <?php foreach (CANTONS as $c): ?>
                    <option value="<?= e($c) ?>" <?= ($emp['canton'] ?? 'Genève') === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>
    <div class="grid3">
        <label>Procédure de décompte
            <select name="procedure">
                <?php foreach (PROCEDURES as $p): ?>
                    <option value="<?= e($p) ?>" <?= ($emp['procedure'] ?? 'Ordinaire') === $p ? 'selected' : '' ?>><?= e($p) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><span>Supplément vacances <?= info_tip(
            "Le supplément vacances et l'impôt à la source sont des valeurs par défaut : elles peuvent être ajustées "
            . "sur chaque fiche de salaire. Le salaire horaire, lui, se définit ligne par ligne sur la fiche."
        ) ?></span>
            <select name="supplement_vacances">
                <?php foreach (SUPPLEMENTS_VACANCES as $val => $lib):
                    $sel = (string) ($emp['supplement_vacances'] ?? '0.0833') === $val
                        || (abs((float) ($emp['supplement_vacances'] ?? 0) - (float) $val) < 1e-9); ?>
                    <option value="<?= e($val) ?>" <?= $sel ? 'selected' : '' ?>><?= e($lib) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><span>Impôt à la source (%) <?= info_tip(
            "Le supplément vacances et l'impôt à la source sont des valeurs par défaut : elles peuvent être ajustées "
            . "sur chaque fiche de salaire. Le salaire horaire, lui, se définit ligne par ligne sur la fiche."
        ) ?></span>
            <input name="impot_source_taux" type="text" inputmode="decimal"
                   value="<?= e(number_format((float) ($emp['impot_source_taux'] ?? 0) * 100, 2, '.', '')) ?>">
        </label>
    </div>

    <label class="check">
        <input type="checkbox" name="actif" value="1" <?= (!$isEdit || $emp['actif']) ? 'checked' : '' ?>>
        Employé actif (apparaît dans la création de fiches)
    </label>

    <div class="form-actions">
        <button type="submit"><?= icon('save') ?> Enregistrer</button>
        <a class="btn ghost" href="?p=employes">Annuler</a>
    </div>
</form>
