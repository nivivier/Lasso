<?php /** @var array $spectacles */ ?>
<?php if (($_GET['err'] ?? null) === 'used'): ?><p class="err flash">Suppression impossible : des événements sont rattachés à ce spectacle.</p><?php endif; ?>
<?= lien_retour('?p=evenements_liste', 'Événements') ?>
<div class="page-head">
    <h1>Spectacles</h1>
    <a class="btn" href="?p=spectacle"><?= icon('file-plus') ?> Nouveau spectacle</a>
</div>

<?php if (!$spectacles): ?>
    <p class="muted">Aucun spectacle pour l'instant. Commencez par en ajouter un.</p>
<?php else: ?>
<div class="table-scroll">
<table class="list list-wide">
    <thead><tr><th>Nom</th><th>Feuille SUISA</th><th>Événements</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($spectacles as $s): ?>
        <tr class="row-link" tabindex="0" role="link" data-href="?p=spectacle&id=<?= (int) $s['id'] ?>">
            <td><strong><?= e($s['nom']) ?></strong></td>
            <td class="muted small">
                <?php if ($s['suisa_feuille_fichier']): ?>
                    <a href="<?= e($s['suisa_feuille_fichier']) ?>" target="_blank" rel="noopener" onclick="event.stopPropagation()">PDF</a>
                <?php else: ?>—<?php endif; ?>
            </td>
            <td><?= (int) $s['nb_evenements'] ?></td>
            <td>
                <?php if ((int) $s['nb_evenements'] === 0): ?>
                    <form method="post" action="?p=spectacle_delete" onclick="event.stopPropagation()"
                          onsubmit="return confirm('Supprimer ce spectacle ?');" class="d-inline">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                        <button type="submit" class="btn ghost btn-sm icon-only" title="Supprimer" aria-label="Supprimer"><?= icon('trash') ?></button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
