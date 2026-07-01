<?php /** @var array $debiteurs */ ?>
<?php if (($_GET['err'] ?? null) === 'used'): ?><p class="err flash">Suppression impossible : des factures sont rattachées à ce débiteur.</p><?php endif; ?>
<div class="page-head">
    <h1>Débiteurs</h1>
    <a class="btn" href="?p=debiteur"><?= icon('user-plus') ?> Nouveau débiteur</a>
</div>

<?php if (!$debiteurs): ?>
    <p class="muted">Aucun débiteur pour l'instant. Commencez par en ajouter un.</p>
<?php else: ?>
<div class="table-scroll">
<table class="list list-wide">
    <thead><tr><th>Nom</th><th>Type</th><th>Adresse</th><th>E-mail</th><th>Factures</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($debiteurs as $d): ?>
        <tr class="row-link <?= $d['actif'] ? '' : 'inactif' ?>" tabindex="0" role="link" data-href="?p=debiteur&id=<?= (int) $d['id'] ?>">
            <td>
                <strong><?= e($d['nom']) ?></strong>
                <?php if (!$d['actif']): ?><span class="badge">inactif</span><?php endif; ?>
            </td>
            <td class="muted small"><?= $d['type'] === 'particulier' ? 'Particulier' : 'Organisation' ?></td>
            <td class="muted small">
                <?= e($d['adresse_rue']) ?><?= $d['adresse_rue'] && $d['adresse_npa'] ? '<br>' : '' ?><?= e(trim($d['adresse_npa'] . ' ' . $d['adresse_localite'])) ?>
                <?= !$d['adresse_rue'] && !$d['adresse_npa'] ? '—' : '' ?>
            </td>
            <td class="muted small"><?= $d['email'] ? e($d['email']) : '—' ?></td>
            <td><?= (int) $d['nb_factures'] ?></td>
            <td>
                <?php if ((int) $d['nb_factures'] === 0): ?>
                    <form method="post" action="?p=debiteur_delete" onclick="event.stopPropagation()"
                          onsubmit="return confirm('Supprimer ce débiteur ?');" class="d-inline">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
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
