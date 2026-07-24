<?php /** @var ?string $errFiches */ /** @var ?array $resultatsFiches */ /** @var ?array $resumeFiches */ /** @var bool $simuleFiches */
// Résultats de l'import de fiches de salaire — le formulaire d'upload est
// désormais unique (voir import_fiches.php, « Importer des données »). ?>
<?php if ($errFiches): ?><p class="err"><?= e($errFiches) ?></p><?php endif; ?>

<?php if ($resumeFiches !== null): ?>
    <?php if ($simuleFiches): ?>
        <div class="card mt-22 import-confirm">
            <p class="mb-0"><strong>Simulation</strong> — rien n'a été enregistré.
                <?php if ((int) $resumeFiches['nouvelles'] > 0): ?>
                    <?= (int) $resumeFiches['nouvelles'] ?> fiche(s) seraient ajoutée(s).
                <?php endif; ?>
            </p>
            <?php if ((int) $resumeFiches['nouvelles'] > 0): ?>
                <form method="post" action="?p=import_fiches" class="mt-0">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="depuis_session" value="1">
                    <button type="submit" name="appliquer" value="1" onclick="return confirm('Importer réellement les fiches nouvelles ?');"><?= icon('import') ?> Importer réellement</button>
                </form>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <p class="ok flash">Import effectué : <?= (int) $resumeFiches['nouvelles'] ?> fiche(s) ajoutée(s).</p>
    <?php endif; ?>

    <div class="card mt-22">
        <h2 class="mt-0"><?= $simuleFiches ? 'Aperçu de l\'import' : 'Résultat de l\'import' ?></h2>
        <p class="muted small">
            <?= (int) $resumeFiches['total'] ?> ligne(s) :
            <strong><?= (int) $resumeFiches['nouvelles'] ?></strong> <?= $simuleFiches ? 'à ajouter' : 'ajoutée(s)' ?>,
            <?= (int) $resumeFiches['existantes'] ?> déjà présente(s),
            <?= (int) $resumeFiches['erreurs'] ?> en erreur.
        </p>
        <div class="table-scroll">
        <table class="list">
            <thead>
                <tr><th>Employé</th><th>Période</th><th class="num">Brut</th><th class="num">Net</th><th>Statut</th></tr>
            </thead>
            <tbody>
            <?php foreach ($resultatsFiches as $r): ?>
                <?php
                $cls = $r['statut'] === 'erreur' ? 'warn-badge' : ($r['statut'] === 'existante' ? 'badge' : 'ok-badge');
                $lib = ['nouvelle' => $simuleFiches ? 'À ajouter' : 'Ajoutée', 'existante' => 'Déjà présente', 'erreur' => 'Erreur'][$r['statut']];
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
