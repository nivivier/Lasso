<?php /** @var array $annees */ ?>
<?php require __DIR__ . '/_param_tabs.php'; ?>

<div class="card form mb-22">
    <h2 class="mt-0">Sauvegarde complète</h2>
    <p class="muted small mb-0">Télécharge une copie complète de la base (toutes les données : employés, fiches, taux…) dans un seul fichier <code>.sqlite</code>. À conserver régulièrement en lieu sûr — c'est ta sauvegarde.</p>
    <div class="form-actions">
        <a class="btn" href="?p=backup"><?= icon('download') ?> Télécharger la sauvegarde</a>
    </div>
</div>

<div class="card form">
    <h2 class="mt-0">Certificats de salaire — XML (eCS CSI)</h2>
    <p class="muted small mb-0">
        Exporte les certificats de salaire de tous les employés d'une année au format XML
        « eCertificat de salaire CSI ». Importe ensuite ce fichier dans l'application officielle
        <strong>eCertificat de salaire CSI</strong> pour produire les PDF certifiés (avec code-barres).
    </p>
    <?php if (!$annees): ?>
        <div class="form-actions"><p class="muted mb-0">Aucune fiche de salaire à exporter.</p></div>
    <?php else: ?>
    <form method="get" action="index.php" class="form-actions">
        <input type="hidden" name="p" value="certificat_xml">
        <label class="inline">Année
            <select name="annee">
                <?php foreach ($annees as $a): ?>
                    <option value="<?= $a ?>"><?= $a ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit"><?= icon('download') ?> Télécharger le XML</button>
    </form>
    <?php endif; ?>
</div>
