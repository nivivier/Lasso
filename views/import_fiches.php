<?php /** @var ?string $err */ /** @var ?array $resultats */ /** @var ?array $resume */ /** @var bool $simule */ ?>
<?php require __DIR__ . '/_param_tabs.php'; ?>
<?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>

<div class="card form">
    <p class="muted small">Importez des fiches de salaire depuis un fichier <strong>JSON</strong> (format d'export « fiches_salaire »). La correspondance des employés se fait par <strong>numéro AVS</strong>. Une fiche déjà présente (même employé, année et mois) est <strong>ignorée</strong> — jamais écrasée.</p>
    <form method="post" action="?p=import_fiches" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <label>Fichier à importer
            <input type="file" name="fichier" accept="application/json,.json" required>
        </label>
        <div class="form-actions">
            <button type="submit" name="simuler" value="1" class="btn ghost"><?= icon('bar-chart') ?> Simuler</button>
            <button type="submit" name="appliquer" value="1" onclick="return confirm('Importer réellement les fiches nouvelles ?');"><?= icon('check') ?> Importer</button>
        </div>
        <p class="muted small">« Simuler » montre ce qui serait importé sans rien enregistrer. « Importer » insère les fiches nouvelles.</p>
    </form>
</div>

<?php if ($resume !== null): ?>
    <?php if ($simule): ?>
        <p class="ok flash">Simulation — rien n'a été enregistré.</p>
    <?php else: ?>
        <p class="ok flash">Import effectué : <?= (int) $resume['nouvelles'] ?> fiche(s) ajoutée(s).</p>
    <?php endif; ?>

    <div class="card mt-22">
        <h2 class="mt-0"><?= $simule ? 'Aperçu de l\'import' : 'Résultat de l\'import' ?></h2>
        <p class="muted small">
            <?= (int) $resume['total'] ?> ligne(s) :
            <strong><?= (int) $resume['nouvelles'] ?></strong> <?= $simule ? 'à ajouter' : 'ajoutée(s)' ?>,
            <?= (int) $resume['existantes'] ?> déjà présente(s),
            <?= (int) $resume['erreurs'] ?> en erreur.
        </p>
        <div class="table-scroll">
        <table class="list">
            <thead>
                <tr><th>Employé</th><th>Période</th><th class="num">Brut</th><th class="num">Net</th><th>Statut</th></tr>
            </thead>
            <tbody>
            <?php foreach ($resultats as $r): ?>
                <?php
                $cls = $r['statut'] === 'erreur' ? 'warn-badge' : ($r['statut'] === 'existante' ? 'badge' : 'ok-badge');
                $lib = ['nouvelle' => $simule ? 'À ajouter' : 'Ajoutée', 'existante' => 'Déjà présente', 'erreur' => 'Erreur'][$r['statut']];
                ?>
                <tr class="<?= $r['statut'] === 'erreur' ? 'inactif' : '' ?>">
                    <td><?= e($r['nom']) ?></td>
                    <td><?= e(mois_nom((int) $r['mois'])) ?> <?= (int) $r['annee'] ?></td>
                    <td class="num"><?= chf((float) $r['brut']) ?></td>
                    <td class="num"><?= chf((float) $r['net']) ?></td>
                    <td>
                        <span class="badge <?= $cls ?>"><?= e($lib) ?></span>
                        <?php if (!empty($r['detail']) && $r['statut'] === 'erreur'): ?>
                            <span class="muted small"><?= e($r['detail']) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
<?php endif; ?>
