<?php /** @var ?string $errFactures */ /** @var ?array $resultatsFactures */ /** @var ?array $resumeFactures */ /** @var bool $simuleFactures */
// Résultats de l'import de factures — le formulaire d'upload est désormais
// unique (voir import_fiches.php, « Importer des données »). ?>
<?php if ($errFactures): ?><p class="err"><?= e($errFactures) ?></p><?php endif; ?>

<?php if ($resumeFactures !== null): ?>
    <?php if ($simuleFactures): ?>
        <div class="card mt-22 import-confirm">
            <p class="mb-0"><strong>Simulation</strong> — rien n'a été enregistré.
                <?php if ((int) $resumeFactures['nouvelles'] > 0): ?>
                    <?= (int) $resumeFactures['nouvelles'] ?> facture(s) seraient ajoutée(s).
                <?php endif; ?>
            </p>
            <?php if ((int) $resumeFactures['nouvelles'] > 0): ?>
                <form method="post" action="?p=import_factures" class="mt-0">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="depuis_session" value="1">
                    <button type="submit" name="appliquer" value="1" data-confirm="Importer réellement les factures nouvelles ?"><?= icon('import') ?> Importer réellement</button>
                </form>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <p class="ok flash">Import effectué : <?= (int) $resumeFactures['nouvelles'] ?> facture(s) ajoutée(s).</p>
    <?php endif; ?>

    <div class="card mt-22">
        <h2 class="mt-0"><?= $simuleFactures ? 'Aperçu de l\'import' : 'Résultat de l\'import' ?></h2>
        <p class="muted small">
            <?= (int) $resumeFactures['total'] ?> ligne(s) :
            <strong><?= (int) $resumeFactures['nouvelles'] ?></strong> <?= $simuleFactures ? 'à ajouter' : 'ajoutée(s)' ?>,
            <?= (int) $resumeFactures['existantes'] ?> déjà présente(s),
            <?= (int) $resumeFactures['erreurs'] ?> en erreur.
        </p>
        <div class="table-scroll">
        <table class="list">
            <thead>
                <tr><th>Numéro</th><th>Structure</th><th>Émission</th><th class="num">Montant</th><th>Statut</th><th>Import</th></tr>
            </thead>
            <tbody>
            <?php foreach ($resultatsFactures as $r): ?>
                <?php
                $cls = $r['statut'] === 'erreur' ? 'warn-badge' : ($r['statut'] === 'existante' ? 'badge' : 'ok-badge');
                $lib = ['nouvelle' => $simuleFactures ? 'À ajouter' : 'Ajoutée', 'existante' => 'Déjà présente', 'erreur' => 'Erreur'][$r['statut']];
                ?>
                <tr class="<?= $r['statut'] === 'erreur' ? 'inactif' : '' ?>">
                    <td><?= e($r['numero']) ?: '—' ?></td>
                    <td><?= e($r['structure']) ?: '—' ?></td>
                    <td><?= $r['date_emission'] !== '' ? e(date('d.m.Y', strtotime($r['date_emission']))) : '—' ?></td>
                    <td class="num"><?= chf((float) $r['montant']) ?></td>
                    <td class="muted small"><?= e($r['statut_facture']) ?></td>
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
