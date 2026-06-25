<?php /** @var array $emp */ /** @var int $annee */ /** @var array $annees */ /** @var array $fiches */ /** @var array $tot */ ?>
<?= lien_retour('?p=employe_voir&id=' . (int) $emp['id'], $emp['prenom'] . ' ' . $emp['nom']) ?>
<div class="page-head">
    <div class="page-head-title">
        <h1>Certificat de salaire</h1>
        <?php if ($annees): ?>
        <select class="inline-year-select" onchange="location.href='?p=certificat&employe_id=<?= (int) $emp['id'] ?>&annee='+this.value">
            <?php foreach ($annees as $a): ?>
                <option value="<?= $a ?>" <?= $a === $annee ? 'selected' : '' ?>><?= $a ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
    </div>
    <div class="head-actions">
        <a class="btn ghost" href="?p=certificat_xml&employe_id=<?= (int) $emp['id'] ?>&annee=<?= (int) $annee ?>"><?= icon('download') ?> XML (eCS CSI)</a>
        <a class="btn" href="?p=certificat_print&employe_id=<?= (int) $emp['id'] ?>&annee=<?= (int) $annee ?>" data-preview target="_blank"><?= icon('eye') ?> Aperçu</a>
    </div>
</div>

<div class="card">
    <?php require __DIR__ . '/_certificat_body.php'; ?>
</div>
