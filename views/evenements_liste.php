<?php
/** @var array $evenements */ /** @var int $annee */ /** @var array $annees */
/** @var string $statutSuisa */ /** @var int $spectacleId */ /** @var string $statut */
/** @var string $visibilite */ /** @var array $spectacles */
/** @var array $paysDisponibles */ /** @var string $pays */ /** @var string $salaries */
$statutsSuisa = [
    'tous' => 'Tous', 'a_faire' => 'À faire', 'envoye' => 'Envoyé', 'manquant' => 'Manquant',
    'decompte_recu' => 'Décompte reçu', 'ne_sapplique_pas' => "Ne s'applique pas",
];
$termePluriel = evenements_terme_spectacle();
$termeSingulier = evenements_terme_spectacle(false);
?>
<div class="page-head-band">
<div class="page-head">
    <div class="page-head-title">
        <h1>Événements</h1>
        <form method="get">
            <input type="hidden" name="p" value="evenements_liste">
            <input type="hidden" name="statut_suisa" value="<?= e($statutSuisa) ?>">
            <input type="hidden" name="spectacle_id" value="<?= (int) $spectacleId ?>">
            <input type="hidden" name="statut" value="<?= e($statut) ?>">
            <input type="hidden" name="visibilite" value="<?= e($visibilite) ?>">
            <input type="hidden" name="pays" value="<?= e($pays) ?>">
            <input type="hidden" name="salaries" value="<?= e($salaries) ?>">
            <select name="annee" class="inline-year-select" onchange="this.form.submit()">
                <option value="0" <?= $annee === 0 ? 'selected' : '' ?>>Toutes</option>
                <?php $opts = array_unique(array_merge([$annee, (int) date('Y')], $annees)); $opts = array_diff($opts, [0]); rsort($opts);
                foreach ($opts as $a): ?>
                    <option value="<?= $a ?>" <?= $a === $annee ? 'selected' : '' ?>><?= $a ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <div class="head-actions">
        <a class="btn ghost btn-sm" href="?p=spectacles"><?= icon('music') ?> <span class="lbl"><?= e($termePluriel) ?></span></a>
        <a class="btn" href="?p=evenement"><?= icon('file-plus') ?> Nouvel événement</a>
    </div>

    <form method="get" class="filters">
        <input type="hidden" name="p" value="evenements_liste">
        <input type="hidden" name="annee" value="<?= (int) $annee ?>">
        <label>Statut SUISA
            <select name="statut_suisa" onchange="this.form.submit()">
                <?php foreach ($statutsSuisa as $val => $lib): ?>
                    <option value="<?= $val ?>" <?= $statutSuisa === $val ? 'selected' : '' ?>><?= $lib ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Statut
            <select name="statut" onchange="this.form.submit()">
                <option value="tous" <?= $statut === 'tous' ? 'selected' : '' ?>>Tous</option>
                <?php foreach (EVENEMENTS_STATUTS as $s): ?>
                    <option value="<?= $s ?>" <?= $statut === $s ? 'selected' : '' ?>><?= e(evenement_statut_libelle($s)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Type d'audience
            <select name="visibilite" onchange="this.form.submit()">
                <option value="tous" <?= $visibilite === 'tous' ? 'selected' : '' ?>>Toutes</option>
                <?php foreach (EVENEMENTS_VISIBILITES as $vi): ?>
                    <option value="<?= $vi ?>" <?= $visibilite === $vi ? 'selected' : '' ?>><?= e(evenement_visibilite_libelle($vi)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><?= e($termeSingulier) ?>
            <select name="spectacle_id" onchange="this.form.submit()">
                <option value="0">Tous</option>
                <option value="-1" <?= $spectacleId === -1 ? 'selected' : '' ?>>Sans <?= mb_strtolower(e($termeSingulier)) ?></option>
                <?php foreach ($spectacles as $s): ?>
                    <option value="<?= (int) $s['id'] ?>" <?= $spectacleId === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['nom']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Pays
            <select name="pays" onchange="this.form.submit()">
                <option value="tous" <?= $pays === 'tous' ? 'selected' : '' ?>>Tous</option>
                <?php foreach ($paysDisponibles as $p): ?>
                    <option value="<?= e($p) ?>" <?= $pays === $p ? 'selected' : '' ?>><?= e($p) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Salariés
            <select name="salaries" onchange="this.form.submit()">
                <option value="tous" <?= $salaries === 'tous' ? 'selected' : '' ?>>Tous</option>
                <option value="oui" <?= $salaries === 'oui' ? 'selected' : '' ?>>Oui</option>
                <option value="non" <?= $salaries === 'non' ? 'selected' : '' ?>>Non</option>
            </select>
        </label>
        <label class="search-label"><span>Rechercher <span id="evenements-search-count" class="muted small"></span></span>
            <input type="search" id="evenements-search" placeholder="Ville, salle, festival, <?= mb_strtolower(e($termeSingulier)) ?>…" autocomplete="off" aria-label="Rechercher">
        </label>
    </form>
</div>
</div>

<?php if (!$evenements): ?>
    <p class="muted">Aucun événement pour cette sélection.</p>
<?php else: ?>
<div class="bulk-bar" id="bulk-bar" hidden>
    <form method="post" id="bulkform" action="?p=evenements_liste">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <select name="section" id="bulk-action" class="inline-year-select">
            <option value="">— Choisir une action —</option>
            <option value="delete">Supprimer</option>
            <option value="statut">Modifier le statut</option>
            <option value="visibilite">Modifier le type d'audience</option>
            <option value="spectacle">Modifier <?= mb_strtolower(e($termeSingulier)) ?></option>
            <option value="region">Modifier la région</option>
            <option value="pays">Modifier le pays</option>
            <option value="suisa_applicable">Modifier si la SUISA s'applique</option>
            <option value="suisa_envoi">Modifier l'envoi SUISA</option>
            <option value="suisa_decompte">Modifier la date du décompte SUISA</option>
        </select>

        <span class="bulk-field" data-for="statut" hidden>
            <select name="bulk_statut" class="inline-year-select">
                <?php foreach (EVENEMENTS_STATUTS as $s): ?>
                    <option value="<?= $s ?>"><?= e(evenement_statut_libelle($s)) ?></option>
                <?php endforeach; ?>
            </select>
        </span>
        <span class="bulk-field" data-for="visibilite" hidden>
            <select name="bulk_visibilite" class="inline-year-select">
                <?php foreach (EVENEMENTS_VISIBILITES as $vi): ?>
                    <option value="<?= $vi ?>"><?= e(evenement_visibilite_libelle($vi)) ?></option>
                <?php endforeach; ?>
            </select>
        </span>
        <span class="bulk-field" data-for="spectacle" hidden>
            <select name="bulk_spectacle_id" class="inline-year-select">
                <option value="">— Aucun —</option>
                <?php foreach ($spectacles as $s): ?>
                    <option value="<?= (int) $s['id'] ?>"><?= e($s['nom']) ?></option>
                <?php endforeach; ?>
            </select>
        </span>
        <span class="bulk-field" data-for="region" hidden>
            <input type="text" name="bulk_region" class="inline-year-select" placeholder="canton ou département">
        </span>
        <span class="bulk-field" data-for="pays" hidden>
            <select name="bulk_pays" class="inline-year-select">
                <option value="">—</option>
                <?php foreach ($paysDisponibles as $p): ?>
                    <option value="<?= e($p) ?>"><?= e($p) ?></option>
                <?php endforeach; ?>
            </select>
        </span>
        <span class="bulk-field" data-for="suisa_applicable" hidden>
            <select name="bulk_suisa_applicable" class="inline-year-select">
                <option value="1">S'applique</option>
                <option value="0">Ne s'applique pas</option>
            </select>
        </span>
        <span class="bulk-field" data-for="suisa_envoi" hidden>
            <select name="bulk_suisa_envoye_a" class="inline-year-select">
                <option value="">—</option>
                <option value="suisa">Directement à la SUISA</option>
                <option value="organisateur">À l'organisateur</option>
            </select>
            <input type="date" name="bulk_suisa_envoye_le" class="inline-year-select">
        </span>
        <span class="bulk-field" data-for="suisa_decompte" hidden>
            <input type="date" name="bulk_suisa_decompte_le" class="inline-year-select">
        </span>

        <button type="submit" class="btn" id="bulk-submit" disabled>Modifier la sélection</button>
    </form>
</div>
<div class="table-scroll">
<table class="list list-wide evenements-liste">
    <thead>
        <tr>
            <th class="col-check"><input type="checkbox" id="check-all" aria-label="Tout cocher"></th>
            <th>Date</th><th><?= e($termeSingulier) ?></th><th>Ville / salle</th><th>Audience</th><th>Statut</th><th>SUISA</th><th class="num">Salariés</th>
        </tr>
    </thead>
    <tbody>
    <?php $moisPrecedent = null; foreach ($evenements as $ev):
        $moisCle = substr((string) $ev['date'], 0, 7); // "AAAA-MM"
        if ($moisCle !== $moisPrecedent):
            $moisPrecedent = $moisCle;
    ?>
        <tr class="mois-sep"><td colspan="8"><?= e(mois_nom((int) substr($moisCle, 5, 2)) . ' ' . substr($moisCle, 0, 4)) ?></td></tr>
    <?php endif; ?>
        <?php
            $estAnnule = $ev['statut'] === 'annule';
            $drapeau = pays_drapeau((string) $ev['pays']);
            $festivalSalle = implode(', ', array_filter([$ev['festival'], $ev['salle']], fn ($v) => $v !== ''));
        ?>
        <tr class="row-link" tabindex="0" role="link" data-href="?p=evenement&id=<?= (int) $ev['id'] ?>">
            <td class="col-check"><input type="checkbox" name="ids[]" value="<?= (int) $ev['id'] ?>" form="bulkform" class="row-check"></td>
            <td<?= $estAnnule ? ' class="text-strike"' : '' ?>><?= e(date('d.m.Y', strtotime($ev['date']))) ?></td>
            <td class="small<?= $estAnnule ? ' text-strike' : '' ?>"><?= $ev['spectacle_nom'] ? e($ev['spectacle_nom']) : '—' ?></td>
            <td class="<?= $estAnnule ? 'text-strike' : '' ?>">
                <?php if ($ev['ville'] !== ''): ?><strong><?= e($ev['ville']) ?></strong><?php endif; ?>
                <?php if ($drapeau !== ''): ?> <span class="tiny"><?= $drapeau ?></span><?php endif; ?>
                <?php if ($ev['region'] !== ''): ?> <span class="tiny muted">(<?= e($ev['region']) ?>)</span><?php endif; ?>
                <?php if ($festivalSalle !== ''): ?> <span class="muted small"><?= e($festivalSalle) ?></span><?php endif; ?>
                <?php if ($ev['ville'] === '' && $festivalSalle === ''): ?>—<?php endif; ?>
            </td>
            <td><?= evenement_badge_visibilite($ev) ?></td>
            <td><?= evenement_badge_statut($ev) ?></td>
            <td><?= evenement_suisa_badge($ev) ?></td>
            <td class="num"><?= (int) $ev['nb_salaries'] ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<script>
(function () {
    const bulkBar = document.getElementById('bulk-bar');
    function updateBulkBar() {
        bulkBar.hidden = document.querySelectorAll('.row-check:checked').length === 0;
    }
    const all = document.getElementById('check-all');
    all.addEventListener('change', () => {
        document.querySelectorAll('.row-check').forEach(c => { c.checked = all.checked; });
        updateBulkBar();
    });
    document.querySelectorAll('.row-check').forEach(c => c.addEventListener('change', updateBulkBar));

    // Action choisie → affiche le champ correspondant (s'il y en a un) et adapte
    // le libellé du bouton (suppression = destructif, le reste = modification).
    const action = document.getElementById('bulk-action');
    const submit = document.getElementById('bulk-submit');
    const fields = document.querySelectorAll('.bulk-field');
    function syncAction() {
        fields.forEach(f => { f.hidden = f.dataset.for !== action.value; });
        submit.disabled = action.value === '';
        if (action.value === 'delete') {
            submit.textContent = 'Supprimer la sélection';
            submit.classList.add('danger');
        } else {
            submit.textContent = 'Modifier la sélection';
            submit.classList.remove('danger');
        }
    }
    action.addEventListener('change', syncAction);
    syncAction();

    document.getElementById('bulkform').addEventListener('submit', e => {
        const n = document.querySelectorAll('.row-check:checked').length;
        if (action.value === 'delete' && !confirm('Supprimer ' + n + ' événement(s) ? Cette action est irréversible.')) {
            e.preventDefault();
        }
    });

    // Recherche instantanée (insensible à la casse et aux accents) sur
    // spectacle, ville, salle et festival — colonnes déjà à l'écran.
    const search  = document.getElementById('evenements-search');
    const count   = document.getElementById('evenements-search-count');
    const allRows = Array.from(document.querySelectorAll('.evenements-liste tbody tr'));
    const rows    = allRows.filter(r => !r.classList.contains('mois-sep'));
    const norm = s => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    const texteLigne = r => norm((r.children[2]?.textContent || '') + ' ' + (r.children[3]?.textContent || ''));
    if (search) {
        const apply = () => {
            const q = norm(search.value.trim());
            let visibles = 0;
            rows.forEach(r => {
                const ok = q === '' || texteLigne(r).includes(q);
                r.style.display = ok ? '' : 'none';
                if (ok) visibles++;
            });
            // Un séparateur de mois ne s'affiche que s'il précède au moins une ligne visible.
            let sep = null, sepVisible = false;
            allRows.forEach(r => {
                if (r.classList.contains('mois-sep')) {
                    if (sep) sep.style.display = sepVisible ? '' : 'none';
                    sep = r; sepVisible = false;
                } else if (r.style.display !== 'none') {
                    sepVisible = true;
                }
            });
            if (sep) sep.style.display = sepVisible ? '' : 'none';
            count.textContent = q === '' ? '' : visibles + ' / ' + rows.length + ' affiché(e)s';
        };
        search.addEventListener('input', apply);
    }
})();
</script>
<?php endif; ?>
