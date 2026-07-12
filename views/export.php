<?php /** @var array $annees */ /** @var array $anneesCompta */ /** @var array $comptesCamt */ /** @var bool $errCamt */ ?>
<?php require __DIR__ . '/_param_tabs.php'; ?>
<?php if ($errCamt): ?><p class="err flash">Choisissez un compte bancaire avec IBAN renseignée.</p><?php endif; ?>

<div class="card form mb-22">
    <h2 class="mt-0">Sauvegarde complète</h2>
    <p class="muted small mb-0">Télécharge une copie complète de la base (toutes les données : employés, fiches, taux, <strong>comptabilité</strong> — comptes, écritures, plan, règles…) dans un seul fichier <code>.sqlite</code>. À conserver régulièrement en lieu sûr — c'est ta sauvegarde.</p>
    <div class="form-actions">
        <a class="btn" href="?p=backup"><?= icon('download') ?> Télécharger la sauvegarde</a>
    </div>
</div>

<?php if (module_actif('compta')): ?>
<div class="card form mb-22">
    <h2 class="mt-0">Écritures comptables — CSV</h2>
    <p class="muted small mb-0">Exporte toutes les écritures d'une année au format CSV (séparateur « ; », encodage UTF-8). Chaque ligne contient la date, le texte, le tiers, le montant, le compte bancaire et la catégorie de lettrage.</p>
    <?php if (!$anneesCompta): ?>
        <div class="form-actions"><p class="muted mb-0">Aucune écriture à exporter.</p></div>
    <?php else: ?>
    <form method="get" action="index.php" class="form-actions">
        <input type="hidden" name="p" value="compta_ecritures_csv">
        <label class="inline">Année
            <select name="annee">
                <option value="0">Toutes les années</option>
                <?php foreach ($anneesCompta as $a): ?>
                    <option value="<?= $a ?>"><?= $a ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit"><?= icon('download') ?> Télécharger le CSV</button>
    </form>
    <?php endif; ?>
</div>

<div class="card form mb-22">
    <h2 class="mt-0">Écritures comptables — CAMT.053</h2>
    <p class="muted small mb-0">Exporte le relevé d'un compte bancaire au format bancaire normalisé <strong>ISO 20022 camt.053</strong> (XML), pour le réimporter dans un autre logiciel comptable (ou dans Lasso lui-même, voir Importer). Une seule IBAN par relevé : choisissez le compte.</p>
    <?php if (!$comptesCamt): ?>
        <div class="form-actions"><p class="muted mb-0">Aucun compte bancaire avec IBAN renseignée.</p></div>
    <?php else: ?>
    <form method="get" action="index.php" class="form-actions">
        <input type="hidden" name="p" value="compta_ecritures_camt053">
        <label class="inline">Compte
            <select name="compte">
                <?php foreach ($comptesCamt as $c): ?>
                    <option value="<?= (int) $c['id'] ?>"><?= e($c['libelle']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="inline">Année
            <select name="annee">
                <option value="0">Toutes les années</option>
                <?php foreach ($anneesCompta as $a): ?>
                    <option value="<?= $a ?>"><?= $a ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit"><?= icon('download') ?> Télécharger le XML</button>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (module_actif('salaires')): ?>
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
<?php endif; ?>
