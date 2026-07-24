<?php /** @var array $structures */ /** @var string $recherche */ /** @var bool $modeClient */ /** @var int $categorieId */
/** @var string $pays */ /** @var string $region */ /** @var int $tagId */
/** @var array $categoriesPourSelect */ /** @var array $regionsDispo */ /** @var array $tagsDispo */
/** @var string $pgRoute */ /** @var array $pgParams */ /** @var int $pgPage */ /** @var int $pgTaille */ /** @var int $pgTotal */
/** @var ?int $bulkCount */ /** @var bool $okAnnule */ /** @var int $structBloquees */ ?>
<?php $actionUrl = '?p=structures'; require __DIR__ . '/_bulk_undo_flash.php'; ?>
<?php if ($structBloquees): ?><p class="err flash"><?= (int) $structBloquees ?> structure(s) non supprimée(s) : des factures y sont rattachées.</p><?php endif; ?>
<div class="page-head-band">
<div class="page-head">
    <div class="page-head-title">
        <h1>Structures</h1>
    </div>
    <div class="head-actions">
        <a class="btn" href="?p=structure"><?= icon('user-plus') ?><span class="lbl"> Nouvelle structure</span></a>
    </div>

    <form method="get" class="filters">
        <input type="hidden" name="p" value="structures">
        <label>Catégorie
            <select name="categorie_id" onchange="this.form.submit()">
                <option value="0">Toutes</option>
                <?php foreach ($categoriesPourSelect as $cat): ?>
                    <option value="<?= (int) $cat['id'] ?>" <?= $categorieId === (int) $cat['id'] ? 'selected' : '' ?>><?= str_repeat("\u{00A0}\u{00A0}", $cat['profondeur']) ?><?= e($cat['nom']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="search-label">
            <input type="search" name="q" id="structures-search" placeholder="Rechercher..." autocomplete="off" aria-label="Rechercher" value="<?= e($recherche) ?>">
        </label>
        <details class="filters-more" <?= ($pays !== '' || $region !== '' || $tagId) ? 'open' : '' ?>>
            <summary>Plus de filtres</summary>
            <div class="filters-more-body">
                <label>Pays
                    <select name="pays" onchange="this.form.submit()">
                        <option value="">Tous</option>
                        <?= pays_options_nom($pays) ?>
                    </select>
                </label>
                <label>Département / canton
                    <select name="region" onchange="this.form.submit()">
                        <option value="">Tous</option>
                        <?php foreach ($regionsDispo as $r): ?>
                            <option value="<?= e($r) ?>" <?= $region === $r ? 'selected' : '' ?>><?= e($r) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php if ($tagsDispo): ?>
                <label>Étiquette
                    <select name="tag_id" onchange="this.form.submit()">
                        <option value="0">Toutes</option>
                        <?php foreach ($tagsDispo as $t): ?>
                            <option value="<?= (int) $t['id'] ?>" <?= $tagId === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php endif; ?>
            </div>
        </details>
    </form>
</div>
</div>

<?php if (!$structures): ?>
    <?php if ($recherche !== '' || $categorieId !== 0 || $pays !== '' || $region !== '' || $tagId): ?>
        <p class="muted">Aucune structure ne correspond à cette recherche.</p>
    <?php else: ?>
        <p class="muted">Aucune structure pour l'instant. Commencez par en ajouter une.</p>
    <?php endif; ?>
<?php else: ?>
<div class="bulk-bar" id="bulk-bar" hidden>
    <form method="post" id="bulkform" action="?p=structures">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <select name="section" id="bulk-action" class="inline-year-select">
            <option value="">— Choisir une action —</option>
            <option value="categorie">Modifier la catégorie</option>
            <option value="type">Modifier le type</option>
            <option value="ville">Modifier la ville</option>
            <option value="region">Modifier la région</option>
            <option value="pays">Modifier le pays</option>
            <option value="via">Modifier le « via »</option>
            <option value="actif">Modifier « active »</option>
            <option value="desinscrit">Modifier « désinscrite »</option>
            <option value="fusionner">Fusionner (2 sélections ou plus)</option>
            <option value="transformer_lieu">Transformer en salle/festival d'un organisateur (2 sélections ou plus)</option>
            <option value="delete">Supprimer</option>
        </select>

        <span class="bulk-field" data-for="categorie" hidden>
            <select name="bulk_categorie_id" class="inline-year-select">
                <?php foreach ($categoriesPourSelect as $cat): ?>
                    <option value="<?= (int) $cat['id'] ?>"><?= str_repeat("\u{00A0}\u{00A0}", $cat['profondeur']) ?><?= e($cat['nom']) ?></option>
                <?php endforeach; ?>
            </select>
        </span>
        <span class="bulk-field" data-for="type" hidden>
            <select name="bulk_type" class="inline-year-select">
                <option value="organisation">Organisation</option>
                <option value="particulier">Particulier</option>
            </select>
        </span>
        <span class="bulk-field" data-for="ville" hidden>
            <input type="text" name="bulk_ville" class="inline-year-select" placeholder="Nouvelle ville">
        </span>
        <span class="bulk-field" data-for="region" hidden>
            <input type="text" name="bulk_region" class="inline-year-select" placeholder="Nouvelle région">
        </span>
        <span class="bulk-field" data-for="pays" hidden>
            <select name="bulk_pays" class="inline-year-select"><?= pays_options_nom('') ?></select>
        </span>
        <span class="bulk-field" data-for="via" hidden>
            <input type="text" name="bulk_via" class="inline-year-select" placeholder="Nouveau « via »">
        </span>
        <span class="bulk-field" data-for="actif" hidden>
            <select name="bulk_actif" class="inline-year-select">
                <option value="1">Active</option>
                <option value="0">Inactive</option>
            </select>
        </span>
        <span class="bulk-field" data-for="desinscrit" hidden>
            <select name="bulk_desinscrit" class="inline-year-select">
                <option value="1">Désinscrite</option>
                <option value="0">Pas désinscrite</option>
            </select>
        </span>

        <button type="submit" class="btn" id="bulk-submit" disabled>Modifier la sélection</button>
    </form>
</div>
<div class="table-scroll">
<table class="list list-wide">
    <thead><tr>
        <th class="col-check"><input type="checkbox" id="check-all" aria-label="Tout cocher"></th>
        <th>Nom</th><th>Ville</th><th>Catégorie</th><th>E-mail</th><th>Dernier contact</th><th>Lieux</th><th>Factures</th><?php if (module_actif('evenements')): ?><th><?= icon('calendar') ?></th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($structures as $d): ?>
        <tr class="row-link <?= $d['actif'] ? '' : 'inactif' ?>" tabindex="0" role="link" data-href="?p=structure&id=<?= (int) $d['id'] ?>">
            <td class="col-check"><input type="checkbox" name="ids[]" value="<?= (int) $d['id'] ?>" form="bulkform" class="row-check" onclick="event.stopPropagation()"></td>
            <td>
                <strong><?= e($d['nom']) ?></strong>
                <?php if (!$d['actif']): ?><span class="badge muted-badge">inactif</span><?php endif; ?>
                <?php if ($d['desinscrit']): ?><span class="badge muted-badge">désinscrit</span><?php endif; ?>
            </td>
            <td class="small">
                <?php $villeHtml = ville_region_html((string) $d['adresse_localite'], pays_drapeau_nom((string) $d['adresse_pays']), (string) $d['adresse_pays'], (string) $d['region']); ?>
                <?= $villeHtml !== '' ? $villeHtml : '—' ?>
            </td>
            <td><?= categorie_sous_categorie_html((string) $d['categorie'], (string) $d['sous_categorie']) ?></td>
            <td class="muted small col-petit"><?= $d['email_affiche'] ? e($d['email_affiche']) : '—' ?></td>
            <td class="muted small"><?= $d['dernier_contact_le'] ? e(date('d.m.Y', strtotime($d['dernier_contact_le']))) : '—' ?></td>
            <td class="muted small"><?= $d['lieux_noms'] ? e($d['lieux_noms']) : '—' ?></td>
            <td>
                <?php if ((int) $d['nb_factures'] > 0): ?>
                    <a href="?p=facturation_liste&annee=0&statut=tous&q=<?= urlencode($d['nom']) ?>" onclick="event.stopPropagation()"><?= (int) $d['nb_factures'] ?></a>
                <?php else: ?>
                    0
                <?php endif; ?>
            </td>
            <?php if (module_actif('evenements')): ?>
            <td class="muted small"><?php $ne = (int) ($nbEvenements[(int) $d['id']] ?? 0); echo $ne > 0 ? $ne : '—'; ?></td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php require __DIR__ . '/' . ($modeClient ? '_pagination_client.php' : '_pagination.php'); ?>
<?php endif; ?>
<script>
<?php if ($modeClient): ?>
lassoListeClient({
    tableSelector: '.list-wide',
    searchInputSelector: '#structures-search',
});
<?php else: ?>
lassoRechercheServeur(document.getElementById('structures-search'));
<?php endif; ?>

(function () {
    const bulkBar = document.getElementById('bulk-bar');
    if (!bulkBar) return;
    function updateBulkBar() {
        bulkBar.hidden = document.querySelectorAll('.row-check:checked').length === 0;
    }
    const all = document.getElementById('check-all');
    all.addEventListener('change', () => {
        document.querySelectorAll('.row-check').forEach(c => {
            if (c.closest('tr').style.display !== 'none') c.checked = all.checked;
        });
        updateBulkBar();
    });
    document.querySelectorAll('.row-check').forEach(c => c.addEventListener('change', updateBulkBar));

    const action = document.getElementById('bulk-action');
    const submit = document.getElementById('bulk-submit');
    const fields = document.querySelectorAll('.bulk-field');
    function syncAction() {
        fields.forEach(f => { f.hidden = f.dataset.for !== action.value; });
        submit.disabled = action.value === '';
        if (action.value === 'delete') {
            submit.textContent = 'Supprimer la sélection';
            submit.classList.add('danger');
        } else if (action.value === 'fusionner') {
            submit.textContent = 'Fusionner la sélection';
            submit.classList.remove('danger');
        } else if (action.value === 'transformer_lieu') {
            submit.textContent = 'Transformer la sélection';
            submit.classList.remove('danger');
        } else {
            submit.textContent = 'Modifier la sélection';
            submit.classList.remove('danger');
        }
    }
    action.addEventListener('change', syncAction);
    syncAction();

    document.getElementById('bulkform').addEventListener('submit', e => {
        const n = document.querySelectorAll('.row-check:checked').length;
        if (action.value === 'delete' && !confirm('Supprimer ' + n + ' structure(s) ? Cette action est irréversible.')) {
            e.preventDefault();
        } else if ((action.value === 'fusionner' || action.value === 'transformer_lieu') && n < 2) {
            alert('Sélectionnez au moins deux structures (l\'organisateur et les salles/festivals à rattacher).');
            e.preventDefault();
        }
    });
})();
</script>
