<?php /** @var bool $saved */ /** @var ?string $err */ /** @var array $lignes */ /** @var array $map */ /** @var array $usageRegion */

// Options de PAYS (racines) pour le <select> parent — repli (reparenter une
// région sans glisser-déposer) et formulaire d'ajout.
$paysOptions = function (?int $selected) use ($map): string {
    $h = '';
    foreach ($map as $r) {
        if (plan_pid($r['parent_id'] ?? null) !== 0) { continue; }
        $rid = (int) $r['id'];
        $h .= '<option value="' . $rid . '"' . ($selected === $rid ? ' selected' : '') . '>' . e($r['nom']) . '</option>';
    }
    return $h;
};
?>
<?php require __DIR__ . '/_param_tabs.php'; ?>
<?php if ($saved): ?><p class="ok flash">Enregistré.</p><?php endif; ?>
<?php if ($err === 'used'): ?><p class="err flash">Suppression impossible : ce pays a encore des régions, est utilisé par une structure / un lieu / l'employeur, ou c'est le dernier pays restant.</p><?php endif; ?>
<?php if ($err === 'region_used'): ?><p class="err flash">Suppression impossible : au moins une fiche utilise cette région — réaffectez-la d'abord.</p><?php endif; ?>

<?php $peutEcrirePays = peut_ecrire('coeur'); ?>
<?php if ($peutEcrirePays): ?>
<!-- Formulaire de repositionnement, déclenché par le glisser-déposer -->
<form method="post" action="?p=parametres_pays" id="reorder-form" hidden>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="section" value="reorder">
    <input type="hidden" name="id" value="">
    <input type="hidden" name="parent_id" value="">
    <input type="hidden" name="order" value="">
</form>
<?php endif; ?>

<div class="section-head mt-0">
    <h2 class="mt-0">Pays &amp; régions <?= info_tip("Liste de pays commune à toute l'application (structures, salles/festivals, employeur,
    événements, factures). Les régions (Normandie, Romandie, Acadie…) sont des sous-entrées d'un pays :
    glissez une ligne pour la réordonner ou la déplacer. Renommer un pays ou une région met aussi à jour
    les fiches qui l'utilisent déjà.") ?> </h2>
    <?php if ($peutEcrirePays): ?>
    <button type="button" class="btn btn-sm ml-auto" data-show="pays-add"><?= icon('plus') ?> Nouveau pays / région</button>
    <?php endif; ?>
</div>
<div class="card form table-scroll" id="pays-card">
<table class="list mb-16 plan-table">
    <tbody>
    <?php if (!$lignes): ?>
        <tr><td class="muted small">Aucun pays.</td></tr>
    <?php endif; ?>
    <?php foreach ($lignes as $i => $p): $pid = (int) $p['id']; $prof = (int) $p['profondeur']; $estPays = $prof === 0; ?>
        <tr class="plan-row <?= $p['a_enfants'] ? 'plan-groupe' : '' ?>"
            data-id="<?= $pid ?>" data-depth="<?= $prof ?>" data-parent="<?= (int) plan_pid($p['parent_id'] ?? null) ?>">
            <td>
                <div class="inline-edit" style="--depth:<?= $prof ?>">
                    <span class="plan-grip" draggable="true" title="Glisser pour ranger ailleurs" aria-hidden="true"><?= icon('grip') ?></span>
                    <span class="plan-puce" aria-hidden="true"><?= $p['a_enfants'] ? icon('chevron-down') : '•' ?></span>
                    <span class="plan-nom"><?= $estPays ? pays_drapeau((string) ($p['code_iso2'] ?? '')) . ' ' : '' ?><?= e($p['nom']) ?></span>
                    <?php if ($peutEcrirePays): ?>
                    <form method="post" action="?p=parametres_pays" class="inline-edit plan-edit">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="section" value="edit">
                        <input type="hidden" name="id" value="<?= $pid ?>">
                        <input name="nom" value="<?= e($p['nom']) ?>" class="grow plan-libelle" required aria-label="Nom">
                        <?php if ($estPays): ?>
                            <input name="code_iso2" value="<?= e((string) ($p['code_iso2'] ?? '')) ?>" maxlength="2" style="max-width:4.5rem" required aria-label="Code ISO2" title="Code ISO 3166-1 alpha-2 (ex. CH, FR)" class="plan-code-iso2">
                        <?php else: ?>
                            <label class="plan-parent plan-fallback">dans
                                <select name="parent_id"><?= $paysOptions(plan_pid($p['parent_id'] ?? null)) ?></select>
                            </label>
                        <?php endif; ?>
                        <button type="submit" class="btn ghost btn-sm plan-fallback" title="Enregistrer"><?= icon('save') ?></button>
                    </form>
                    <?php endif; ?>
                </div>
            </td>
            <td class="actions nowrap">
                <?php if ($peutEcrirePays): ?>
                <button type="button" class="btn ghost btn-sm icon-only plan-edit-btn" title="Renommer" aria-label="Renommer"><?= icon('pencil') ?></button>
                <form method="post" action="?p=parametres_pays" class="d-inline plan-fallback">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="section" value="move">
                    <input type="hidden" name="id" value="<?= $pid ?>">
                    <button type="submit" name="dir" value="up" class="btn ghost btn-sm icon-only" title="Monter" aria-label="Monter" <?= $p['est_premier'] ? 'disabled' : '' ?>><?= icon('chevron-up') ?></button>
                    <button type="submit" name="dir" value="down" class="btn ghost btn-sm icon-only" title="Descendre" aria-label="Descendre" <?= $p['est_dernier'] ? 'disabled' : '' ?>><?= icon('chevron-down') ?></button>
                </form>
                <?php $nbUsage = (int) ($usageRegion[$pid] ?? 0); if ($estPays || $nbUsage === 0): ?>
                <form method="post" action="?p=parametres_pays" data-confirm="Supprimer <?= $estPays ? 'ce pays' : 'cette région' ?> ?" class="d-inline">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="section" value="delete">
                    <input type="hidden" name="id" value="<?= $pid ?>">
                    <button type="submit" class="btn ghost btn-sm icon-only" title="Supprimer" aria-label="Supprimer"><?= icon('trash') ?></button>
                </form>
                <?php else: ?>
                <button type="button" class="btn ghost btn-sm icon-only region-del-btn" title="Supprimer" aria-label="Supprimer"
                        data-id="<?= $pid ?>" data-nom="<?= e($p['nom']) ?>" data-nb="<?= $nbUsage ?>"
                        data-parent="<?= (int) plan_pid($p['parent_id'] ?? null) ?>"><?= icon('trash') ?></button>
                <?php endif; ?>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot id="pays-add" hidden>
        <tr>
            <td colspan="2">
                <form method="post" action="?p=parametres_pays" class="inline-edit" id="pays-add-form">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="section" value="add">
                    <select name="parent_id" id="pays-add-parent" title="Vide = nouveau pays ; sinon = région du pays choisi">
                        <option value="">— Nouveau pays —</option>
                        <?= $paysOptions(null) ?>
                    </select>
                    <input name="nom" placeholder="ex. Espagne / Normandie" required class="grow" aria-label="Nom">
                    <input name="code_iso2" id="pays-add-code" placeholder="ES" maxlength="2" style="max-width:4.5rem" aria-label="Code ISO2" title="Code ISO 3166-1 alpha-2 (pays uniquement)">
                    <button type="submit" class="btn btn-sm"><?= icon('check') ?> Ajouter</button>
                    <button type="button" class="btn ghost btn-sm" data-hide="pays-add"><?= icon('x') ?> Annuler</button>
                </form>
            </td>
        </tr>
    </tfoot>
</table>
</div>

<!-- Suppression d'une région utilisée : réaffecter d'abord -->
<div id="region-del-modal" class="modal-overlay" hidden>
    <div class="modal-card">
        <h3 class="mt-0">Supprimer la région « <span id="region-del-nom"></span> »</h3>
        <p class="muted small"><strong id="region-del-nb"></strong> fiche(s) utilisent cette région. Réaffectez-les avant de supprimer.</p>
        <form method="post" action="?p=parametres_pays" id="region-del-form">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="section" value="delete">
            <input type="hidden" name="id" id="region-del-id" value="">
            <label>Réaffecter vers
                <select name="reaffecter_vers" id="region-del-cible">
                    <option value="" data-kind="aucune">(aucune région)</option>
                    <?php foreach ($lignes as $c): if ((int) $c['profondeur'] !== 1) { continue; } ?>
                        <option value="<?= e($c['nom']) ?>" data-id="<?= (int) $c['id'] ?>" data-parent="<?= (int) plan_pid($c['parent_id'] ?? null) ?>"><?= e($c['nom']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="modal-actions">
                <button type="button" id="region-del-cancel" class="btn ghost">Annuler</button>
                <button type="submit" class="btn danger"><?= icon('trash') ?> Réaffecter et supprimer</button>
            </div>
        </form>
    </div>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    lassoPlanArbre({
        containerSelector: '#pays-card',
        rowsSelector: '.plan-row',
        scrollKey: 'paysScroll',
        formAction: '?p=parametres_pays',
    });

    // Formulaire d'ajout : le code ISO2 n'a de sens que pour un pays — masqué et
    // non requis quand on ajoute une région (parent sélectionné).
    var addParent = document.getElementById('pays-add-parent');
    var addCode = document.getElementById('pays-add-code');
    if (addParent && addCode) {
        var toggleCode = function () {
            var estPays = addParent.value === '';
            addCode.hidden = !estPays;
            addCode.required = estPays;
            if (!estPays) { addCode.value = ''; }
        };
        addParent.addEventListener('change', toggleCode);
        toggleCode();
    }

    // Code ISO2 (édition d'un pays) : soumet dès qu'on le modifie, comme le
    // renommage (bouton de repli masqué une fois le glisser-déposer actif).
    document.querySelectorAll('.plan-code-iso2').forEach(function (inp) {
        inp.addEventListener('change', function () {
            var f = inp.closest('form');
            (f.requestSubmit ? f.requestSubmit() : f.submit());
        });
        inp.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); inp.blur(); }
        });
    });

    // Modale de suppression d'une région utilisée : cibles = autres régions du
    // même pays, ou « aucune » (vider le champ).
    var modal = document.getElementById('region-del-modal');
    if (modal) {
        var cible = document.getElementById('region-del-cible');
        var open = function (btn) {
            document.getElementById('region-del-id').value = btn.dataset.id;
            document.getElementById('region-del-nom').textContent = btn.dataset.nom;
            document.getElementById('region-del-nb').textContent = btn.dataset.nb;
            var premierVisible = -1;
            Array.from(cible.options).forEach(function (o, idx) {
                var ok = o.dataset.kind === 'aucune'
                    || (o.dataset.parent === btn.dataset.parent && o.dataset.id !== btn.dataset.id);
                o.hidden = !ok; o.disabled = !ok;
                if (ok && premierVisible < 0) premierVisible = idx;
            });
            if (premierVisible >= 0) cible.selectedIndex = premierVisible;
            modal.removeAttribute('hidden');
        };
        var close = function () { modal.setAttribute('hidden', ''); };
        document.querySelectorAll('.region-del-btn').forEach(function (b) { b.addEventListener('click', function () { open(b); }); });
        document.getElementById('region-del-cancel').addEventListener('click', close);
        modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) close(); });
    }
})();
</script>
