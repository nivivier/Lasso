<?php /** @var ?string $errEvenements */ /** @var ?array $resultatsEvenements */ /** @var ?array $resumeEvenements */ /** @var bool $simuleEvenements */
$termeSingulier = mb_strtolower(evenements_terme_spectacle(false));
?>
<?php if ($errEvenements): ?><p class="err"><?= e($errEvenements) ?></p><?php endif; ?>

<div class="card form mt-22">
<h2 class="mt-0">Importer des événements</h2>
    <p class="muted small">Importez un agenda de tournée depuis un fichier <strong>CSV</strong> (colonnes :
        <code>date, ville, region, pays, lieu, details, type, statut, lien, lien_texte</code> — dans n'importe quel
        ordre, seules <code>date</code> et <code>ville</code> sont obligatoires ; date au format JJ/MM/AAAA).
        La colonne <code>type</code> est rapprochée d'un <?= e($termeSingulier) ?> existant du même nom (créé automatiquement sinon).
        Un événement à la même date/ville/salle qu'un événement déjà présent est <strong>ignoré</strong> — jamais
        écrasé. Les événements importés sont créés en visibilité <strong>non répertoriée</strong>, à relire avant
        de les publier. <a href="assets/exemples/evenements.csv" target="_blank">Voir un exemple de fichier</a>.</p>
    <form method="post" action="?p=import_evenements" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <label>Fichier à importer
            <input type="file" name="fichier" accept="text/csv,.csv" required>
        </label>
        <div class="form-actions">
            <button type="submit" name="simuler" value="1" class="btn ghost"><?= icon('bar-chart') ?> Simuler</button>
            <button type="submit" name="appliquer" value="1" onclick="return confirm('Importer réellement les événements nouveaux ?');"><?= icon('import') ?> Importer</button>
        </div>
        <p class="muted small">« Simuler » montre ce qui serait importé sans rien enregistrer. « Importer » insère les événements nouveaux.</p>
    </form>
</div>

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
