<?php /** @var ?string $errEvenements */ /** @var ?array $resultatsEvenements */ /** @var ?array $resumeEvenements */ /** @var bool $simuleEvenements */
// Résultats de l'import d'événements — le formulaire d'upload est désormais
// unique (voir import_fiches.php, « Importer des données »).
$termeSingulier = mb_strtolower(evenements_terme_spectacle(false));
?>
<?php if ($errEvenements): ?><p class="err"><?= e($errEvenements) ?></p><?php endif; ?>

<?php if ($resumeEvenements !== null): ?>
    <?php if ($simuleEvenements): ?>
        <div class="card mt-22 import-confirm">
            <p class="mb-0"><strong>Simulation</strong> — rien n'a été enregistré.
                <?php if ((int) $resumeEvenements['nouveaux'] > 0): ?>
                    <?= (int) $resumeEvenements['nouveaux'] ?> événement(s) seraient ajouté(s).
                <?php endif; ?>
                <?php if ((int) $resumeEvenements['spectacles_crees'] > 0): ?>
                    <?= (int) $resumeEvenements['spectacles_crees'] ?> nouveau(x) <?= e($termeSingulier) ?>(s) seraient créé(s).
                <?php endif; ?>
            </p>
            <?php if ((int) $resumeEvenements['nouveaux'] > 0): ?>
                <form method="post" action="?p=import_evenements" class="mt-0">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="depuis_session" value="1">
                    <button type="submit" name="appliquer" value="1" onclick="return confirm('Importer réellement les événements nouveaux ?');"><?= icon('import') ?> Importer réellement</button>
                </form>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <p class="ok flash">Import effectué : <?= (int) $resumeEvenements['nouveaux'] ?> événement(s) ajouté(s), <?= (int) $resumeEvenements['spectacles_crees'] ?> <?= e($termeSingulier) ?>(s) créé(s).</p>
    <?php endif; ?>

    <div class="card mt-22">
        <h2 class="mt-0"><?= $simuleEvenements ? 'Aperçu de l\'import' : 'Résultat de l\'import' ?></h2>
        <p class="muted small">
            <?= (int) $resumeEvenements['total'] ?> ligne(s) :
            <strong><?= (int) $resumeEvenements['nouveaux'] ?></strong> <?= $simuleEvenements ? 'à ajouter' : 'ajouté(s)' ?>,
            <?= (int) $resumeEvenements['existants'] ?> déjà présent(s),
            <?= (int) $resumeEvenements['erreurs'] ?> en erreur.
        </p>
        <div class="table-scroll">
        <table class="list">
            <thead>
                <tr><th>Date</th><th>Ville</th><th>Lieu</th><th>Import</th></tr>
            </thead>
            <tbody>
            <?php foreach ($resultatsEvenements as $r): ?>
                <?php
                $cls = $r['statut'] === 'erreur' ? 'warn-badge' : ($r['statut'] === 'existant' ? 'badge' : 'ok-badge');
                $lib = ['nouveau' => $simuleEvenements ? 'À ajouter' : 'Ajouté', 'existant' => 'Déjà présent', 'erreur' => 'Erreur'][$r['statut']];
                ?>
                <tr class="<?= $r['statut'] === 'erreur' ? 'inactif' : '' ?>">
                    <td><?= e($r['date']) ?: '—' ?></td>
                    <td><?= e($r['ville']) ?: '—' ?></td>
                    <td class="muted small"><?= e($r['lieu']) ?: '—' ?></td>
                    <td>
                        <span class="badge <?= $cls ?>"><?= e($lib) ?></span>
                        <?php if (!empty($r['detail']) && $r['statut'] !== 'nouveau'): ?>
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
