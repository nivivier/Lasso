<?php
/** @var ?array $evenement */ /** @var int $id */ /** @var array $spectacles */
/** @var array $employesLies */ /** @var array $employesDispo */ /** @var array $fichesLiees */
/** @var array $fichesDispo */ /** @var array $factures */ /** @var array $facturesDispo */
/** @var array $paysDisponibles */ /** @var ?string $err */ /** @var array $post */
$isEdit = $id > 0;
$v = fn (string $k, $d = '') => e((string) ($post[$k] ?? $evenement[$k] ?? $d));
$vRaw = fn (string $k, $d = '') => (string) ($post[$k] ?? $evenement[$k] ?? $d);
$retour = $isEdit ? '?p=evenement&id=' . (int) $id : '?p=evenements_liste';
$ok = $_GET['ok'] ?? null;

$confirmSuppr = null;
if ($isEdit) {
    $impacts = [];
    if ($employesLies) $impacts[] = count($employesLies) . ' employé(s) lié(s)';
    if ($fichesLiees) $impacts[] = count($fichesLiees) . ' fiche(s) de salaire liée(s)';
    if ($factures) $impacts[] = count($factures) . ' facture(s) qui perdront ce lien';
    $confirmSuppr = 'Supprimer cet événement ?' . ($impacts ? ' ' . implode(', ', $impacts) . '.' : '');
}
?>
<?= lien_retour('?p=evenements_liste', 'Événements') ?>
<div class="page-head">
    <h1><?= $isEdit ? "Modifier l'événement" : 'Nouvel événement' ?></h1>
    <?php if ($isEdit): ?>
    <div class="head-actions">
        <form method="post" action="?p=evenement_delete" class="d-inline" onsubmit="return confirm(<?= e(json_encode($confirmSuppr, JSON_UNESCAPED_UNICODE)) ?>);">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int) $id ?>">
            <button type="submit" class="btn danger icon-only" title="Supprimer" aria-label="Supprimer"><?= icon('trash') ?></button>
        </form>
    </div>
    <?php endif; ?>
</div>

<?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>

<div class="card">
    <h2 class="mt-0">Informations</h2>
    <?php if ($ok === 'infos'): ?><p class="ok flash">Informations enregistrées.</p><?php endif; ?>
    <form method="post" action="?p=evenement<?= $isEdit ? '&id=' . (int) $id : '' ?>" class="form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int) $id ?>"><?php endif; ?>

        <div class="grid4">
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
            <label>Type d'audience
                <select name="visibilite">
                    <?php foreach (EVENEMENTS_VISIBILITES as $vi): ?>
                        <option value="<?= $vi ?>" <?= $vRaw('visibilite', 'non_repertorie') === $vi ? 'selected' : '' ?>><?= e(evenement_visibilite_libelle($vi)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <p class="muted small">
            Public : affiché sur le site avec ville, salle, festival, lien, spectacle et remarques.
            Privé : seule la date apparaît, avec la mention « Événement privé ».
            Non répertorié : n'apparaît jamais sur le site (usage interne).
        </p>

        <div class="grid4">
            <label>Ville <input name="ville" value="<?= $v('ville') ?>"></label>
            <label>Région et pays
                <div class="field-pair">
                    <input name="region" value="<?= $v('region') ?>" placeholder="canton ou département">
                    <select name="pays">
                        <option value="">—</option>
                        <?php foreach ($paysDisponibles as $p): ?>
                            <option value="<?= e($p) ?>" <?= $vRaw('pays') === $p ? 'selected' : '' ?>><?= e($p) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </label>
            <label>Salle <input name="salle" value="<?= $v('salle') ?>"></label>
            <label>Festival <input name="festival" value="<?= $v('festival') ?>"></label>
        </div>
        <div class="grid3">
            <label>Lien <input type="url" name="lien_infos" value="<?= $v('lien_infos') ?>" placeholder="https://…"></label>
            <label>Texte du bouton de lien <input name="lien_texte" value="<?= $v('lien_texte') ?>" placeholder="Plus d'informations"></label>
            <label>Remarques <input name="remarques" value="<?= $v('remarques') ?>"></label>
        </div>

        <div class="form-actions">
            <button type="submit"><?= icon('save') ?> Enregistrer</button>
            <a class="btn ghost" href="<?= e($retour) ?>">Annuler</a>
        </div>
    </form>
</div>

<?php if ($isEdit): ?>
<div class="card mt-22">
    <h2 class="mt-0">Suivi SUISA</h2>
    <?php if ($ok === 'suisa'): ?><p class="ok flash">Suivi SUISA enregistré.</p><?php endif; ?>
    <form method="post" action="?p=evenement_suisa" class="form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= (int) $id ?>">
        <?php $suisaApplicable = (bool) $evenement['suisa_applicable']; ?>
        <label class="check">
            <input type="checkbox" name="suisa_applicable" id="suisa-applicable" value="1" <?= $suisaApplicable ? 'checked' : '' ?>>
            La SUISA s'applique à cet événement
        </label>
        <div class="grid3" id="suisa-champs" <?= $suisaApplicable ? '' : 'hidden' ?>>
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
        <p><?= evenement_suisa_badge($evenement) ?></p>
        <div class="form-actions">
            <button type="submit"><?= icon('save') ?> Enregistrer</button>
            <a class="btn ghost" href="<?= e($retour) ?>">Annuler</a>
        </div>
    </form>
</div>

<div class="card mt-22">
    <h2 class="mt-0">Employés</h2>
    <h3 class="sub no-mt">Employés liés</h3>
    <?php if (!$employesLies): ?>
        <p class="muted small">Aucun employé lié.</p>
    <?php else: ?>
        <div class="linked-list">
            <?php foreach ($employesLies as $emp): ?>
                <div class="linked-item">
                    <span><?= e($emp['prenom'] . ' ' . $emp['nom']) ?></span>
                    <form method="post" action="?p=evenement_employe_delier" class="d-inline">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="id" value="<?= (int) $id ?>">
                        <input type="hidden" name="employe_id" value="<?= (int) $emp['id'] ?>">
                        <button type="submit" class="btn ghost btn-sm linked-remove" aria-label="Retirer">✕</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($employesDispo): ?>
        <form method="post" action="?p=evenement_employe_lier" class="linked-add">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int) $id ?>">
            <select name="employe_id">
                <?php foreach ($employesDispo as $emp): ?>
                    <option value="<?= (int) $emp['id'] ?>"><?= e($emp['prenom'] . ' ' . $emp['nom']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn ghost btn-sm">+ Ajouter</button>
        </form>
    <?php endif; ?>

    <h3 class="sub">Fiches de salaire liées</h3>
    <p class="muted small">Une fiche peut couvrir plusieurs événements (ex. cachet regroupant une tournée).</p>
    <?php if (!$fichesLiees): ?>
        <p class="muted small">Aucune fiche liée.</p>
    <?php else: ?>
        <div class="linked-list">
            <?php foreach ($fichesLiees as $f): ?>
                <div class="linked-item">
                    <span><?= e($f['prenom'] . ' ' . $f['nom'] . ' — ' . mois_nom((int) $f['mois']) . ' ' . $f['annee']) ?></span>
                    <form method="post" action="?p=evenement_fiche_delier" class="d-inline">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="id" value="<?= (int) $id ?>">
                        <input type="hidden" name="fiche_id" value="<?= (int) $f['id'] ?>">
                        <button type="submit" class="btn ghost btn-sm linked-remove" aria-label="Retirer">✕</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($fichesDispo): ?>
        <form method="post" action="?p=evenement_fiche_lier" class="linked-add">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int) $id ?>">
            <select name="fiche_id">
                <?php foreach ($fichesDispo as $f): ?>
                    <option value="<?= (int) $f['id'] ?>"><?= e($f['prenom'] . ' ' . $f['nom'] . ' — ' . mois_nom((int) $f['mois']) . ' ' . $f['annee']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn ghost btn-sm">+ Ajouter</button>
        </form>
    <?php endif; ?>
</div>

<div class="card mt-22">
    <div class="page-head">
        <h2 class="mt-0">Factures liées</h2>
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
            <div class="cat-search facture-search">
                <input type="text" class="cat-search-input" placeholder="Rechercher une facture à lier…" autocomplete="off">
                <input type="hidden" name="facture_id" class="cat-search-val" value="">
                <ul class="cat-search-list" hidden role="listbox">
                    <?php foreach ($facturesDispo as $fa):
                        $label = ($fa['numero'] !== '' ? $fa['numero'] : '(brouillon)') . ' — ' . $fa['debiteur_nom'] . ' — ' . chf((float) $fa['montant_total']) . ' CHF';
                    ?>
                        <li data-val="<?= (int) $fa['id'] ?>"><?= e($label) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <button type="submit" class="btn ghost btn-sm">Lier une facture existante</button>
        </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
(function () {
    const suisaCheck = document.getElementById('suisa-applicable');
    if (suisaCheck) {
        const suisaChamps = document.getElementById('suisa-champs');
        suisaCheck.addEventListener('change', () => {
            suisaChamps.hidden = !suisaCheck.checked;
            suisaChamps.querySelectorAll('select, input').forEach(el => { el.disabled = !suisaCheck.checked; });
        });
    }

    // Recherche de facture à lier (même widget que le rapprochement d'écriture/catégorie).
    function initCatSearch(wrap) {
        const input  = wrap.querySelector('.cat-search-input');
        const hidden = wrap.querySelector('.cat-search-val');
        const list   = wrap.querySelector('.cat-search-list');
        const items  = Array.from(list.querySelectorAll('li'));
        const norm = s => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');

        function filter(q) {
            const nq = norm(q);
            items.forEach(li => { li.hidden = nq !== '' && !norm(li.textContent).includes(nq); });
        }

        input.addEventListener('focus', () => { filter(input.value); list.hidden = false; });
        input.addEventListener('input', () => { filter(input.value); list.hidden = false; hidden.value = ''; });
        input.addEventListener('blur', () => {
            setTimeout(() => {
                list.hidden = true;
                const cur = items.find(li => li.dataset.val === hidden.value);
                input.value = cur ? cur.textContent : '';
            }, 150);
        });
        items.forEach(li => {
            li.addEventListener('mousedown', e => {
                e.preventDefault();
                hidden.value = li.dataset.val;
                input.value = li.textContent;
                list.hidden = true;
                input.setCustomValidity('');
            });
        });
    }
    document.querySelectorAll('.facture-search').forEach(initCatSearch);
    document.querySelectorAll('.facture-search').forEach(wrap => {
        wrap.closest('form').addEventListener('submit', e => {
            const hidden = wrap.querySelector('.cat-search-val');
            const input = wrap.querySelector('.cat-search-input');
            if (!hidden.value) {
                input.setCustomValidity('Veuillez choisir une facture dans la liste');
                input.reportValidity();
                e.preventDefault();
            }
        });
    });
})();
</script>
