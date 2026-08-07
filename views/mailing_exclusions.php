<?php /** @var array $emails */ /** @var array $contacts */ /** @var array $structures */ /** @var bool $saved */ ?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<div class="page-head-band">
<div class="page-head">
    <div class="page-head-title">
        <h1><?= e($ntLabel) ?></h1>
    </div>
    <?php require __DIR__ . '/_module_tabs_render.php'; ?>
</div>
</div>

<?php if ($saved): ?><p class="ok flash">Liste mise à jour.</p><?php endif; ?>

<p class="muted small">
    Ces destinataires sont <strong>définitivement écartés</strong> de tout mailing (désinscription par
    lien, liste d'exclusion importée, ou structure désinscrite). Un import ultérieur ne peut pas les
    réintroduire. Pour réinscrire quelqu'un, passez par la fiche de sa structure.
</p>

<h2>Adresses exclues (<?= count($emails) ?>)</h2>
<p class="muted small">La liste « ne pas contacter » elle-même (importée depuis Paramètres → Importer) —
    aucune fiche n'est créée pour ces adresses. Retirer une adresse ne réinscrit pas les contacts déjà
    désinscrits qui la portent.</p>
<?php if (!$emails): ?>
    <p class="muted">Aucune adresse dans la liste.</p>
<?php else: ?>
<div class="table-scroll">
<table class="list">
    <thead><tr><th>E-mail</th><th>Ajoutée le</th><th class="actions"></th></tr></thead>
    <tbody>
    <?php foreach ($emails as $x): ?>
        <tr>
            <td><?= e($x['email']) ?></td>
            <td class="muted small"><?= e(date('d.m.Y', strtotime($x['cree_le']))) ?></td>
            <td class="actions">
                <form method="post" action="?p=mailing_exclusions" class="d-inline" onsubmit="return confirm('Retirer cette adresse de la liste d\'exclusion ?');">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="section" value="retirer">
                    <input type="hidden" name="id" value="<?= (int) $x['id'] ?>">
                    <button type="submit" class="btn ghost btn-sm icon-only" title="Retirer de la liste" aria-label="Retirer de la liste"><?= icon('trash') ?></button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

<h2>Contacts désinscrits (<?= count($contacts) ?>)</h2>
<?php if (!$contacts): ?>
    <p class="muted">Aucun contact désinscrit.</p>
<?php else: ?>
<div class="table-scroll">
<table class="list">
    <thead><tr><th>E-mail</th><th>Contact</th><th>Structure</th></tr></thead>
    <tbody>
    <?php foreach ($contacts as $c): ?>
        <tr class="row-link" tabindex="0" role="link" data-href="?p=structure&id=<?= (int) $c['structure_id'] ?>">
            <td><?= e($c['email']) ?></td>
            <td class="muted small"><?= e(trim($c['prenom'] . ' ' . $c['nom'])) ?: '—' ?></td>
            <td><a href="?p=structure&id=<?= (int) $c['structure_id'] ?>" onclick="event.stopPropagation()"><?= e($c['structure_nom']) ?></a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

<h2>Structures désinscrites (<?= count($structures) ?>)</h2>
<p class="muted small">Aucun contact de ces structures ne reçoit de mailing, quel que soit son propre statut.</p>
<?php if (!$structures): ?>
    <p class="muted">Aucune structure désinscrite.</p>
<?php else: ?>
<div class="table-scroll">
<table class="list">
    <thead><tr><th>Structure</th><th>E-mail</th></tr></thead>
    <tbody>
    <?php foreach ($structures as $s): ?>
        <tr class="row-link" tabindex="0" role="link" data-href="?p=structure&id=<?= (int) $s['id'] ?>">
            <td><?= e($s['nom']) ?></td>
            <td class="muted small"><?= e($s['email']) ?: '—' ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
