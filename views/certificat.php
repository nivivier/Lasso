<?php /** @var array $emp */ /** @var int $annee */ /** @var array $annees */ /** @var array $fiches */ /** @var array $tot */ ?>
<?= lien_retour('?p=employe_voir&id=' . (int) $emp['id'], $emp['prenom'] . ' ' . $emp['nom']) ?>
<div class="page-head">
    <h1>Certificat de salaire · <?= (int) $annee ?></h1>
    <div class="head-actions">
        <?php if (count($annees) > 1): ?>
        <label class="inline">Année
            <select onchange="location.href='?p=certificat&employe_id=<?= (int) $emp['id'] ?>&annee='+this.value">
                <?php foreach ($annees as $a): ?>
                    <option value="<?= $a ?>" <?= $a === $annee ? 'selected' : '' ?>><?= $a ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>
        <a class="btn ghost" href="?p=certificat_xml&employe_id=<?= (int) $emp['id'] ?>&annee=<?= (int) $annee ?>"><?= icon('download') ?> XML (eCS CSI)</a>
        <a class="btn" href="?p=certificat_print&employe_id=<?= (int) $emp['id'] ?>&annee=<?= (int) $annee ?>" target="_blank"><?= icon('printer') ?> Imprimer / PDF</a>
    </div>
</div>

<div class="card">
    <?php require __DIR__ . '/_certificat_body.php'; ?>
</div>
