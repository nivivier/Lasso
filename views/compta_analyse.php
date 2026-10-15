<?php
/** @var int $annee */ /** @var array $annees */ /** @var array $axes */
/** @var array $ventilation */ /** @var array $detailParAxe */ /** @var array $nonVentile */
?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>

<div class="module-content"><div class="module-content-inner">
    <div class="toolbar">
    <form method="get" class="filters">
        <input type="hidden" name="p" value="compta_analyse">
        <label>Année
            <select name="annee" data-submit-on-change>
                <option value="0" <?= $annee === 0 ? 'selected' : '' ?>>Toutes</option>
                <?php foreach ($annees as $a): ?>
                    <option value="<?= (int) $a ?>" <?= (int) $a === $annee ? 'selected' : '' ?>><?= (int) $a ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </form>
    <div class="head-actions">
        <?php if ($axes): ?>
        <a href="?p=compta_ventilation_suggestion&annee=<?= (int) $annee ?>" class="btn ghost"><?= icon('wand') ?> <span><span class="lbl">Ventilation charges</span><span class="lbl-m">Charges</span></span></a>
        <?php endif; ?>
        <?php if ($ventilation): ?>
        <a class="btn ghost" href="?p=compta_analyse_imprimer&annee=<?= (int) $annee ?>" data-preview target="_blank" rel="noopener"><?= icon('eye') ?><span class="lbl"> Aperçu</span></a>
        <?php endif; ?>
        <?php // « Modifier », tout à droite et en dernier : c'est la place de ce
              // geste dans toute l'application (docs/UI.md § 1). Ce qu'on modifie
              // ici, ce sont les axes — la matière même de l'écran —, et la page
              // où l'on atterrit le redit dans son titre. ?>
        <a href="?p=compta_axes" class="btn ghost"><?= icon('pencil') ?><span class="lbl"> Modifier</span></a>
    </div>
    </div>

<?php if (!$axes): ?>
<div class="card">
    <div class="section-head">
        <h2>Comptabilité analytique</h2>
        <a href="?p=compta_axes" class="btn ml-auto"><?= icon('plus') ?> Créer des axes</a>
    </div>
    <p class="muted small card-note">
        Aucun axe analytique défini. Créez des axes (ex. Label, Tour, Stages, Local)
        pour ventiler les écritures par activité et obtenir le résultat par axe.
    </p>
</div>
<?php elseif (!$ventilation): ?>
<div class="card">
    <div class="section-head"></div>
    <p class="muted small card-note">
        Aucune écriture lettrée et ventilée<?= $annee ? ' pour ' . (int) $annee : '' ?>.
        <a href="?p=compta_ecritures&annee=<?= (int) $annee ?>">Affecter un axe aux écritures.</a>
    </p>
</div>
<?php else: ?>

    <div class="section-head"></div>
    <?php require __DIR__ . '/_compta_analyse_body.php'; ?>
<?php endif; ?>
</div></div>
