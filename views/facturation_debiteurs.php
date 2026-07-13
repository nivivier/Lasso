<?php /** @var array $debiteurs */
/** @var string $pgRoute */ /** @var array $pgParams */ /** @var int $pgPage */ /** @var int $pgTaille */ /** @var int $pgTotal */ ?>
<?php if (($_GET['err'] ?? null) === 'used'): ?><p class="err flash">Suppression impossible : des factures sont rattachées à ce débiteur.</p><?php endif; ?>
<div class="page-head">
    <h1>Débiteurs</h1>
    <div class="head-actions">
	    <?php if ($debiteurs): ?>
	    <label class="search-label"><span>Rechercher <span id="debiteurs-search-count" class="muted small"></span></span>
	        <input type="search" id="debiteurs-search" placeholder="Nom, adresse, e-mail…" autocomplete="off" aria-label="Rechercher">
	    </label>
	    <?php endif; ?>
	    <a class="btn" href="?p=debiteur"><?= icon('user-plus') ?><span class="lbl"> Nouveau débiteur</span></a>
	</div>
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
                <?php if (!$d['actif']): ?><span class="badge muted-badge">inactif</span><?php endif; ?>
            </td>
            <td class="muted small"><?= $d['type'] === 'particulier' ? 'Particulier' : 'Organisation' ?></td>
            <td class="muted small">
                <?= e($d['adresse_rue']) ?><?= $d['adresse_rue'] && $d['adresse_npa'] ? '<br>' : '' ?><?= e(trim($d['adresse_npa'] . ' ' . $d['adresse_localite'])) ?>
                <?= !$d['adresse_rue'] && !$d['adresse_npa'] ? '—' : '' ?>
            </td>
            <td class="muted small"><?= $d['email'] ? e($d['email']) : '—' ?></td>
            <td>
                <?php if ((int) $d['nb_factures'] > 0): ?>
                    <a href="?p=facturation_liste&annee=0&statut=tous&q=<?= urlencode($d['nom']) ?>" onclick="event.stopPropagation()"><?= (int) $d['nb_factures'] ?></a>
                <?php else: ?>
                    0
                <?php endif; ?>
            </td>
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
<?php require __DIR__ . '/_pagination.php'; ?>
<script>
(function () {
    const search = document.getElementById('debiteurs-search');
    const count  = document.getElementById('debiteurs-search-count');
    const rows   = Array.from(document.querySelectorAll('.list-wide tbody tr'));
    if (search) {
        const apply = () => {
            const q = lassoNorm(search.value.trim());
            let visibles = 0;
            rows.forEach(r => {
                const ok = q === '' || lassoNorm(r.textContent).includes(q);
                r.style.display = ok ? '' : 'none';
                if (ok) visibles++;
            });
            count.textContent = q === '' ? '' : visibles + ' / ' + rows.length + ' affiché(e)s';
        };
        search.addEventListener('input', apply);
    }
})();
</script>
<?php endif; ?>
