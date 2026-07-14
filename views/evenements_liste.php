<?php
/** @var array $evenements */ /** @var int $annee */ /** @var array $annees */
/** @var string $statutSuisa */ /** @var int $spectacleId */ /** @var string $statut */
/** @var string $visibilite */ /** @var array $spectacles */ /** @var array $spectaclesFiltre */
/** @var array $paysDisponibles */ /** @var string $pays */ /** @var string $salaries */ /** @var string $recherche */
/** @var ?int $bulkCount */ /** @var bool $okAnnule */ /** @var bool $modeClient */
/** @var ?int $prodExterneOk */ /** @var ?int $prodExterneBloques */
/** @var string $pgRoute */ /** @var array $pgParams */ /** @var int $pgPage */ /** @var int $pgTaille */ /** @var int $pgTotal */
$statutsSuisa = [
    'tous' => 'Tous', 'a_venir' => 'À venir', 'a_faire' => 'À faire', 'envoye' => 'Envoyé', 'manquant' => 'Manquant',
    'abandonne' => 'Abandonné', 'decompte_recu' => 'Décompte reçu', 'ne_sapplique_pas' => "Ne s'applique pas",
];
$termePluriel = evenements_terme_spectacle();
$termeSingulier = evenements_terme_spectacle(false);
?>
<?php $actionUrl = '?p=evenements_liste'; require __DIR__ . '/_bulk_undo_flash.php'; ?>
<?php if ($prodExterneOk): ?><p class="flash"><?= (int) $prodExterneOk ?> événement(s) passé(s) en « Production externe ».</p><?php endif; ?>
<?php if ($prodExterneBloques): ?><p class="err flash"><?= (int) $prodExterneBloques ?> événement(s) non modifié(s) : une prestation liée est déjà sur une fiche payée (figée, jamais modifiée).</p><?php endif; ?>
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
            <input type="hidden" name="q" value="<?= e($recherche) ?>">
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
        <?php $exportQs = http_build_query([
            'annee' => $annee, 'statut_suisa' => $statutSuisa, 'spectacle_id' => $spectacleId,
            'statut' => $statut, 'visibilite' => $visibilite, 'pays' => $pays, 'salaries' => $salaries,
            'q' => $recherche,
        ]); ?>
        <a class="btn ghost btn-sm" href="?p=evenements_export_suisa&<?= $exportQs ?>" title="Exporter les événements filtrés actuellement (SUISA + organisateur)">
            <?= icon('download') ?> <span class="lbl">Export SUISA</span>
        </a>
        <a class="btn ghost btn-sm" href="?p=spectacles"><?= icon('music') ?> <span class="lbl"><?= e($termePluriel) ?></span></a>
        <a class="btn" href="?p=evenement"><?= icon('file-plus') ?><span class="lbl"> Nouvel événement</span></a>
    </div>

    <form method="get" class="filters">
        <input type="hidden" name="p" value="evenements_liste">
        <input type="hidden" name="annee" value="<?= (int) $annee ?>">
        <label>Statut
            <select name="statut" onchange="this.form.submit()">
                <option value="tous" <?= $statut === 'tous' ? 'selected' : '' ?>>Tous</option>
                <?php foreach (EVENEMENTS_STATUTS as $s): ?>
                    <option value="<?= $s ?>" <?= $statut === $s ? 'selected' : '' ?>><?= e(evenement_statut_libelle($s)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><?= e($termeSingulier) ?>
            <select name="spectacle_id" onchange="this.form.submit()">
                <option value="0">Tous</option>
                <option value="-1" <?= $spectacleId === -1 ? 'selected' : '' ?>>Sans <?= mb_strtolower(e($termeSingulier)) ?></option>
                <?php foreach ($spectaclesFiltre as $s): ?>
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
        <label class="search-label">
            <input type="search" name="q" id="evenements-search" placeholder="Rechercher..." autocomplete="off" aria-label="Rechercher" value="<?= e($recherche) ?>">
        </label>
        <details class="filters-more" <?= ($statutSuisa !== 'tous' || $visibilite !== 'tous' || $salaries !== 'tous') ? 'open' : '' ?>>
            <summary>Plus de filtres</summary>
            <div class="filters-more-body">
                <label>Statut SUISA
                    <select name="statut_suisa" onchange="this.form.submit()">
                        <?php foreach ($statutsSuisa as $val => $lib): ?>
                            <option value="<?= $val ?>" <?= $statutSuisa === $val ? 'selected' : '' ?>><?= $lib ?></option>
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
                <label>Salariés
                    <select name="salaries" onchange="this.form.submit()">
                        <option value="tous" <?= $salaries === 'tous' ? 'selected' : '' ?>>Tous</option>
                        <option value="oui" <?= $salaries === 'oui' ? 'selected' : '' ?>>Oui</option>
                        <option value="non" <?= $salaries === 'non' ? 'selected' : '' ?>>Non</option>
                    </select>
                </label>
            </div>
        </details>
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
            <option value="production_externe">Modifier « Production externe »</option>
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
        <span class="bulk-field" data-for="production_externe" hidden>
            <select name="bulk_production_externe" class="inline-year-select" id="bulk-production-externe">
                <option value="1">Activer</option>
                <option value="0">Désactiver</option>
            </select>
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
            <td><?= evenement_icone_visibilite($ev) ?></td>
            <td><?= evenement_badge_statut($ev) ?></td>
            <td><?= evenement_suisa_badge($ev, true) ?></td>
            <td class="num salaries-cell">
                <?php if ((int) $ev['production_externe']): ?><span title="Production externe" aria-label="Production externe"><?= icon('handshake') ?></span><?php endif; ?>
                <?= (int) $ev['nb_salaries'] ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php require __DIR__ . '/' . ($modeClient ? '_pagination_client.php' : '_pagination.php'); ?>
<script>
(function () {
    const bulkBar = document.getElementById('bulk-bar');
    function updateBulkBar() {
        bulkBar.hidden = document.querySelectorAll('.row-check:checked').length === 0;
    }
    const all = document.getElementById('check-all');
    all.addEventListener('change', () => {
        // Ne coche que les lignes visibles : en mode client (lassoListeClient()),
        // les lignes des autres pages restent dans le DOM mais display:none —
        // « Tout cocher » ne doit porter que sur la page actuellement affichée.
        document.querySelectorAll('.row-check').forEach(c => {
            if (c.closest('tr').style.display !== 'none') c.checked = all.checked;
        });
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
        } else if (action.value === 'production_externe' && document.getElementById('bulk-production-externe').value === '1'
            && !confirm('Activer « Production externe » va supprimer les prestations déjà liées (non payées) sur les fiches de salaire des employés des ' + n + ' événement(s) sélectionné(s). Continuer ?')) {
            e.preventDefault();
        }
    });

    // Recherche : voir lassoRechercheServeur() (assets/app.js) — paginée côté
    // serveur, sinon une recherche ne porterait que sur la page déjà chargée.
    // En dessous du seuil client (lib/helpers.php), lassoListeClient() prend
    // le relais entièrement en JS.
    <?php if ($modeClient): ?>
    lassoListeClient({
        tableSelector: '.evenements-liste',
        searchInputSelector: '#evenements-search',
        separatorSelector: '.mois-sep',
    });
    <?php else: ?>
    lassoRechercheServeur(document.getElementById('evenements-search'));
    <?php endif; ?>
})();
</script>
<?php endif; ?>
