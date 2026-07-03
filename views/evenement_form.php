<?php
/** @var ?array $evenement */ /** @var int $id */ /** @var array $spectacles */
/** @var array $employesLies */ /** @var array $employesDispo */ /** @var array $fichesLiees */
/** @var array $fichesDispo */ /** @var array $factures */ /** @var array $facturesDispo */
/** @var ?string $err */ /** @var array $post */
$isEdit = $id > 0;
$v = fn (string $k, $d = '') => e((string) ($post[$k] ?? $evenement[$k] ?? $d));
$vRaw = fn (string $k, $d = '') => (string) ($post[$k] ?? $evenement[$k] ?? $d);

// Bloc réutilisable « liste des liés + select/bouton Ajouter » (employés, fiches).
$picker = function (string $titre, string $listId, string $inputName, array $lies, array $dispo, callable $libelle) {
    echo '<h3 class="sub">' . e($titre) . '</h3>';
    echo '<div class="linked-list" id="' . e($listId) . '-linked">';
    foreach ($lies as $item) {
        $lib = $libelle($item);
        echo '<div class="linked-item" data-label="' . e($lib) . '">'
            . '<input type="hidden" name="' . e($inputName) . '" value="' . (int) $item['id'] . '">'
            . '<span>' . e($lib) . '</span>'
            . '<button type="button" class="btn ghost btn-sm linked-remove" aria-label="Retirer">✕</button>'
            . '</div>';
    }
    if (!$lies) {
        echo '<p class="muted small linked-empty">Aucun élément lié.</p>';
    }
    echo '</div>';
    echo '<div class="linked-add">';
    echo '<select id="' . e($listId) . '-select" data-input-name="' . e($inputName) . '">';
    echo '<option value="">— choisir —</option>';
    foreach ($dispo as $item) {
        echo '<option value="' . (int) $item['id'] . '">' . e($libelle($item)) . '</option>';
    }
    echo '</select>';
    echo '<button type="button" class="btn ghost btn-sm" id="' . e($listId) . '-add">+ Ajouter</button>';
    echo '</div>';
};
?>
<?= lien_retour('?p=evenements_liste', 'Événements') ?>
<div class="page-head">
    <h1><?= $isEdit ? "Modifier l'événement" : 'Nouvel événement' ?></h1>
</div>

<?php if (($_GET['ok'] ?? null) && $isEdit): ?><p class="ok flash">Événement enregistré.</p><?php endif; ?>
<?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>

<form method="post" action="?p=evenement<?= $isEdit ? '&id=' . (int) $id : '' ?>" class="card form">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int) $id ?>"><?php endif; ?>

    <div class="grid3">
        <label>Date <input type="date" name="date" value="<?= $v('date') ?>" required></label>
        <label>Spectacle
            <select name="spectacle_id">
                <option value="">—</option>
                <?php foreach ($spectacles as $s): ?>
                    <option value="<?= (int) $s['id'] ?>" <?= $vRaw('spectacle_id') === (string) $s['id'] ? 'selected' : '' ?>><?= e($s['nom']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Statut
            <select name="statut">
                <?php foreach (EVENEMENTS_STATUTS as $s): ?>
                    <option value="<?= $s ?>" <?= $vRaw('statut', 'option') === $s ? 'selected' : '' ?>><?= e(evenement_statut_libelle($s)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>

    <label>Visibilité
        <select name="visibilite">
            <?php foreach (EVENEMENTS_VISIBILITES as $vi): ?>
                <option value="<?= $vi ?>" <?= $vRaw('visibilite', 'non_repertorie') === $vi ? 'selected' : '' ?>><?= e(evenement_visibilite_libelle($vi)) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <p class="muted small">
        Public : affiché sur le site avec ville, salle, festival, lien, spectacle et remarques.
        Privé : seule la date apparaît, avec la mention « Événement privé ».
        Non répertorié : n'apparaît jamais sur le site (usage interne).
    </p>

    <div class="grid3">
        <label>Ville <input name="ville" value="<?= $v('ville') ?>"></label>
        <label>Salle (optionnel) <input name="salle" value="<?= $v('salle') ?>"></label>
        <label>Festival (optionnel) <input name="festival" value="<?= $v('festival') ?>"></label>
    </div>
    <label>Lien « plus d'infos » (optionnel) <input type="url" name="lien_infos" value="<?= $v('lien_infos') ?>" placeholder="https://…"></label>
    <label>Remarques
        <textarea name="remarques" rows="2"><?= $v('remarques') ?></textarea>
    </label>

    <h3 class="sub">Suivi SUISA</h3>
    <?php $suisaApplicable = !$isEdit || $evenement['suisa_applicable']; ?>
    <label class="check">
        <input type="checkbox" name="suisa_applicable" id="suisa-applicable" value="1" <?= $suisaApplicable ? 'checked' : '' ?>>
        La SUISA s'applique à cet événement
    </label>
    <div class="grid3" id="suisa-champs">
        <label>Envoyée à
            <select name="suisa_envoye_a" <?= $suisaApplicable ? '' : 'disabled' ?>>
                <option value="">—</option>
                <option value="suisa" <?= $vRaw('suisa_envoye_a') === 'suisa' ? 'selected' : '' ?>>Directement à la SUISA</option>
                <option value="organisateur" <?= $vRaw('suisa_envoye_a') === 'organisateur' ? 'selected' : '' ?>>À l'organisateur</option>
            </select>
        </label>
        <label>Date d'envoi <input type="date" name="suisa_envoye_le" value="<?= $v('suisa_envoye_le') ?>" <?= $suisaApplicable ? '' : 'disabled' ?>></label>
        <label>Date du décompte <input type="date" name="suisa_decompte_le" value="<?= $v('suisa_decompte_le') ?>" <?= $suisaApplicable ? '' : 'disabled' ?>></label>
    </div>
    <?php if ($isEdit): ?><p><?= evenement_suisa_badge($evenement) ?></p><?php endif; ?>

    <?php // Employés : dispo dès la création (pas besoin d'un id existant, contrairement
    // aux fiches/factures qui référencent l'événement une fois enregistré). ?>
    <?php $picker('Employés liés', 'employes', 'employe_ids[]', $employesLies, $employesDispo,
        fn ($e) => $e['prenom'] . ' ' . $e['nom']); ?>

    <?php if ($isEdit): ?>
    <h3 class="sub">Fiches de salaire liées</h3>
    <p class="muted small">Une fiche peut couvrir plusieurs événements (ex. cachet regroupant une tournée).</p>
    <?php $picker('', 'fiches', 'fiche_ids[]', $fichesLiees, $fichesDispo,
        fn ($f) => $f['prenom'] . ' ' . $f['nom'] . ' — ' . mois_nom((int) $f['mois']) . ' ' . $f['annee']); ?>
    <?php endif; ?>

    <div class="form-actions">
        <button type="submit">Enregistrer</button>
        <a class="btn ghost" href="?p=evenements_liste">Annuler</a>
    </div>
</form>

<?php if ($isEdit): ?>
<div class="card">
    <div class="page-head">
        <h2>Factures liées</h2>
        <?php if (module_actif('facturation')): ?>
            <a class="btn ghost" href="?p=facturation_form&evenement_id=<?= (int) $id ?>"><?= icon('file-plus') ?> Créer une facture liée</a>
        <?php endif; ?>
    </div>
    <?php if (!$factures): ?>
        <p class="muted small">Aucune facture liée à cet événement.</p>
    <?php else: ?>
        <table class="list">
            <thead><tr><th>Numéro</th><th>Débiteur</th><th class="num">Montant</th><th>Statut</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($factures as $fa): ?>
                <tr>
                    <td><a href="?p=facture&id=<?= (int) $fa['id'] ?>"><?= $fa['numero'] !== '' ? e($fa['numero']) : '<span class="muted">(brouillon)</span>' ?></a></td>
                    <td><?= e($fa['debiteur_nom']) ?></td>
                    <td class="num strong"><?= chf((float) $fa['montant_total']) ?></td>
                    <td><?= facturation_badge($fa) ?></td>
                    <td>
                        <form method="post" action="?p=evenement_facture_delier" onsubmit="return confirm('Délier cette facture de l\'événement ?');">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="id" value="<?= (int) $id ?>">
                            <input type="hidden" name="facture_id" value="<?= (int) $fa['id'] ?>">
                            <button type="submit" class="btn ghost btn-sm" title="Délier" aria-label="Délier"><?= icon('x') ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <?php if (module_actif('facturation') && $facturesDispo): ?>
        <form method="post" action="?p=evenement_facture_lier" class="linked-add mt-18">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int) $id ?>">
            <select name="facture_id">
                <?php foreach ($facturesDispo as $fa): ?>
                    <option value="<?= (int) $fa['id'] ?>">
                        <?= $fa['numero'] !== '' ? e($fa['numero']) : '(brouillon)' ?> — <?= e($fa['debiteur_nom']) ?> — <?= chf((float) $fa['montant_total']) ?> CHF
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn ghost btn-sm">Lier une facture existante</button>
        </form>
    <?php endif; ?>
</div>

<?php
$impacts = [];
if ($employesLies) $impacts[] = count($employesLies) . ' employé(s) lié(s)';
if ($fichesLiees) $impacts[] = count($fichesLiees) . ' fiche(s) de salaire liée(s)';
if ($factures) $impacts[] = count($factures) . ' facture(s) qui perdront ce lien';
$confirmSuppr = 'Supprimer cet événement ?' . ($impacts ? ' ' . implode(', ', $impacts) . '.' : '');
?>
<form method="post" action="?p=evenement_delete" onsubmit="return confirm(<?= e(json_encode($confirmSuppr, JSON_UNESCAPED_UNICODE)) ?>);">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= (int) $id ?>">
    <button type="submit" class="btn ghost"><?= icon('trash') ?> Supprimer l'événement</button>
</form>
<?php endif; ?>

<script>
(function () {
    function setupPicker(id) {
        const list = document.getElementById(id + '-linked');
        const select = document.getElementById(id + '-select');
        const btn = document.getElementById(id + '-add');
        if (!list || !select || !btn) return;

        function clearEmpty() {
            const empty = list.querySelector('.linked-empty');
            if (empty) empty.remove();
        }
        function showEmptyIfNone() {
            if (list.querySelector('.linked-item')) return;
            const p = document.createElement('p');
            p.className = 'muted small linked-empty';
            p.textContent = 'Aucun élément lié.';
            list.appendChild(p);
        }

        btn.addEventListener('click', () => {
            const opt = select.selectedOptions[0];
            if (!opt || !opt.value) return;
            clearEmpty();
            const item = document.createElement('div');
            item.className = 'linked-item';
            item.dataset.label = opt.textContent;
            item.innerHTML = '<input type="hidden" name="' + select.dataset.inputName + '" value="' + opt.value + '">'
                + '<span></span><button type="button" class="btn ghost btn-sm linked-remove" aria-label="Retirer">✕</button>';
            item.querySelector('span').textContent = opt.textContent;
            list.appendChild(item);
            opt.remove();
            select.value = '';
        });

        list.addEventListener('click', e => {
            const removeBtn = e.target.closest('.linked-remove');
            if (!removeBtn) return;
            const row = removeBtn.closest('.linked-item');
            const input = row.querySelector('input');
            const label = row.dataset.label;
            const option = document.createElement('option');
            option.value = input.value;
            option.textContent = label;
            const opts = Array.from(select.options).slice(1);
            const idx = opts.findIndex(o => o.textContent.localeCompare(label) > 0);
            if (idx === -1) select.appendChild(option); else select.insertBefore(option, opts[idx]);
            row.remove();
            showEmptyIfNone();
        });
    }
    setupPicker('employes');
    setupPicker('fiches');

    const suisaCheck = document.getElementById('suisa-applicable');
    const suisaChamps = document.getElementById('suisa-champs');
    suisaCheck.addEventListener('change', () => {
        suisaChamps.querySelectorAll('select, input').forEach(el => { el.disabled = !suisaCheck.checked; });
    });
})();
</script>
