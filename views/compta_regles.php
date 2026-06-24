<?php
/** @var array $regles */ /** @var array $impacts */ /** @var array $feuilles */ /** @var array $comptes */
/** @var string $prefillMotif */ /** @var ?int $prefillCompte */ /** @var bool $saved */ /** @var ?int $test */

$compteOptions = function ($selected) use ($comptes): string {
    $sel  = $selected === null ? '' : (string) $selected;
    $html = '<option value=""' . ($sel === '' ? ' selected' : '') . '>Tous (global)</option>';
    foreach ($comptes as $c) {
        $html .= '<option value="' . (int) $c['id'] . '"' . ($sel === (string) $c['id'] ? ' selected' : '') . '>' . e($c['libelle']) . '</option>';
    }
    return $html;
};

// Composant catégorie cherchable : scrollable ET filtrable en tapant.
$catSearchable = function ($selected) use ($feuilles): string {
    $sel   = $selected === null ? '' : (string) $selected;
    $items = '';
    foreach ($feuilles as $f) {
        $items .= '<li data-val="' . (int) $f['id'] . '">' . e($f['chemin']) . '</li>';
    }
    return '<div class="cat-search">'
         . '<input type="text" class="cat-search-input" placeholder="Chercher une catégorie…" autocomplete="off">'
         . '<input type="hidden" name="plan_compte_id" class="cat-search-val" value="' . e($sel) . '">'
         . '<ul class="cat-search-list" hidden role="listbox">' . $items . '</ul>'
         . '</div>';
};

// Rendu d'une ligne de condition (PHP, pour les conditions déjà en base).
$condRow = function (array $cond) use ($feuilles): string {
    $type   = $cond['type']   ?? 'texte';
    $op     = $cond['op']     ?? 'contient';
    $valeur = (string) ($cond['valeur'] ?? '');

    // Normalise les anciens types plats vers le type unifié 'montant'.
    if (in_array($type, ['montant_min', 'montant_max', 'montant_exact'], true)) {
        $op   = match ($type) { 'montant_max' => '<=', 'montant_exact' => '=', default => '>=' };
        $type = 'montant';
    }

    $typeOpts = '';
    foreach (['texte' => 'Texte', 'sens' => 'Sens (crédit/débit)', 'montant' => 'Montant'] as $k => $v) {
        $typeOpts .= '<option value="' . $k . '"' . ($type === $k ? ' selected' : '') . '>' . e($v) . '</option>';
    }
    $opOpts = '';
    foreach (['contient' => 'contient', 'commence' => 'commence par', 'exact' => 'égal à'] as $k => $v) {
        $opOpts .= '<option value="' . $k . '"' . ($op === $k ? ' selected' : '') . '>' . e($v) . '</option>';
    }
    $opNumOpts = '';
    foreach (['>=' => '≥', '<=' => '≤', '=' => '='] as $k => $v) {
        $opNumOpts .= '<option value="' . $k . '"' . ($op === $k ? ' selected' : '') . '>' . e($v) . '</option>';
    }

    $isTexte   = $type === 'texte';
    $isSens    = $type === 'sens';
    $isMontant = $type === 'montant';

    $valSens = in_array($valeur, ['credit', 'debit'], true) ? $valeur : 'credit';
    $valNum  = $isMontant ? $valeur : '';

    $sensOpts = '<option value="credit"' . ($valSens === 'credit' ? ' selected' : '') . '>Crédit (+)</option>'
              . '<option value="debit"'  . ($valSens === 'debit'  ? ' selected' : '') . '>Débit (−)</option>';

    return '<div class="cond-row" data-type="' . e($type) . '">'
         . '<select name="cond_type[]" class="cond-type">' . $typeOpts . '</select>'
         . '<select name="cond_op[]" class="cond-op cond-vis-texte"' . ($isTexte ? '' : ' hidden') . '>' . $opOpts . '</select>'
         . '<input name="cond_valeur_text[]" type="text" class="cond-val grow cond-vis-texte" value="' . e($isTexte ? $valeur : '') . '" placeholder="ex. ROMAGNOLI"' . ($isTexte ? '' : ' hidden') . '>'
         . '<select name="cond_valeur_sens[]" class="cond-val cond-vis-sens"' . ($isSens ? '' : ' hidden') . '>' . $sensOpts . '</select>'
         . '<select name="cond_op_num[]" class="cond-op-num cond-vis-montant"' . ($isMontant ? '' : ' hidden') . '>' . $opNumOpts . '</select>'
         . '<input name="cond_valeur_num[]" type="number" inputmode="decimal" step="0.01" class="cond-val cond-vis-montant" value="' . e($valNum) . '" placeholder="0.00"' . ($isMontant ? '' : ' hidden') . '>'
         . '<button type="button" class="btn ghost btn-sm icon-only cond-rm" title="Supprimer cette condition" aria-label="Supprimer">' . icon('x') . '</button>'
         . '</div>';
};

$condVide = fn(string $motif = '') => $condRow(['type' => 'texte', 'op' => 'contient', 'valeur' => $motif]);

$ouvrirNew = $prefillMotif !== '' || $prefillCompte !== null || isset($_GET['new']);
?>
<div class="page-head page-head-sub">
    <?= lien_retour('?p=compta_ecritures', 'Écritures') ?>
    <h1>Lettrage automatique</h1>
</div>
<?php if ($saved): ?><p class="ok flash">Règles mises à jour.</p><?php endif; ?>
<?php if ($test !== null): ?>
    <p class="ok flash"><?= $test < 0
        ? 'Aucune condition à tester.'
        : 'Cette règle toucherait <strong>' . (int) $test . '</strong> écriture(s) non lettrée(s).' ?></p>
<?php endif; ?>

<!-- Template JS pour l'ajout dynamique d'une condition -->
<template id="cond-tpl">
    <div class="cond-row" data-type="texte">
        <select name="cond_type[]" class="cond-type">
            <option value="texte">Texte</option>
            <option value="sens">Sens (crédit/débit)</option>
            <option value="montant">Montant</option>
        </select>
        <select name="cond_op[]" class="cond-op cond-vis-texte">
            <option value="contient">contient</option>
            <option value="commence">commence par</option>
            <option value="exact">égal à</option>
        </select>
        <input name="cond_valeur_text[]" type="text" class="cond-val grow cond-vis-texte" placeholder="ex. ROMAGNOLI">
        <select name="cond_valeur_sens[]" class="cond-val cond-vis-sens" hidden>
            <option value="credit">Crédit (+)</option>
            <option value="debit">Débit (−)</option>
        </select>
        <select name="cond_op_num[]" class="cond-op-num cond-vis-montant" hidden>
            <option value=">=">≥</option>
            <option value="<=">≤</option>
            <option value="=">=</option>
        </select>
        <input name="cond_valeur_num[]" type="number" inputmode="decimal" step="0.01" class="cond-val cond-vis-montant" placeholder="0.00" hidden>
        <button type="button" class="btn ghost btn-sm icon-only cond-rm" title="Supprimer cette condition" aria-label="Supprimer"><?= icon('x') ?></button>
    </div>
</template>

<div class="card form">
    <div class="card-head">
        <p class="muted small mb-0">Chaque règle associe automatiquement une catégorie aux écritures qui satisfont toutes ses conditions (ET) ou l'une d'elles (OU). Évaluées par <strong>priorité croissante</strong> (première correspondance gagnante) ; à priorité égale, une règle ciblant un compte précis l'emporte sur une règle globale. Insensible à la casse et aux accents. <strong>Touche</strong> = écritures non lettrées concernées.</p>
        <div style="display:flex;gap:8px;flex-shrink:0">
            <form method="post" action="?p=compta_ecritures">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="section" value="apply_rules">
                <button type="submit" class="btn ghost"><?= icon('tag') ?> Appliquer les règles</button>
            </form>
            <button type="button" id="btn-new-rule" class="btn"><?= icon('plus') ?> Nouvelle règle</button>
        </div>
    </div>

    <!-- Nouvelle règle (masquée par défaut, ou ouverte si prefill depuis Lettrage) -->
    <div id="new-rule-card" class="regle-card regle-new <?= $ouvrirNew ? '' : 'hidden' ?>">
        <form method="post" action="?p=compta_regles">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <div class="regle-head">
                <label class="regle-label">Compte<select name="compte_bancaire_id"><?= $compteOptions($prefillCompte !== null ? (string) $prefillCompte : '') ?></select></label>
                <label class="regle-label regle-op-wrap" hidden>Conditions<select name="operateur"><option value="ET">ET (toutes)</option><option value="OU">OU (au moins une)</option></select></label>
                <span class="flex-spacer"></span>
                <span class="test-result muted small"></span>
                <button type="button" class="btn ghost btn-sm btn-tester"><?= icon('search') ?> Tester</button>
                <button type="submit" name="section" value="add" class="btn btn-sm"><?= icon('save') ?> Enregistrer</button>
                <button type="button" id="cancel-new-rule" class="btn ghost btn-sm"><?= icon('x') ?> Annuler</button>
            </div>
            <div class="regle-conds" id="conds-new">
                <?= $prefillMotif !== '' ? $condVide($prefillMotif) : $condVide() ?>
            </div>
            <div class="regle-add-cond">
                <button type="button" class="btn ghost btn-sm add-cond" data-target="conds-new"><?= icon('plus') ?> Condition</button>
            </div>
            <div class="regle-cat">
                <label class="regle-label grow">Catégorie cible<?= $catSearchable(null) ?></label>
            </div>
        </form>
    </div>

    <?php if (!$regles): ?>
    <p class="muted small" id="no-rule">Aucune règle définie. Cliquez sur « Nouvelle règle ».</p>
    <?php endif; ?>

    <?php foreach ($regles as $r):
        $rid   = (int) $r['id'];
        $actif = (int) $r['actif'] === 1;
        $imp   = (int) ($impacts[$rid] ?? 0);
        $nbConds = count($r['conditions']);
    ?>
    <div class="regle-card <?= $actif ? '' : 'regle-inactive' ?>">
        <form method="post" action="?p=compta_regles">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= $rid ?>">
            <div class="regle-head">
                <!-- Gauche : toggle + flèches + compte + opérateur -->
                <label class="regle-toggle" title="<?= $actif ? 'Désactiver' : 'Activer' ?>">
                    <input type="checkbox" name="actif" value="1" <?= $actif ? 'checked' : '' ?>
                           class="regle-actif-cb" onchange="this.closest('form').submit()">
                </label>
                <div class="regle-arrows">
                    <button type="submit" name="section" value="move_up"   class="btn ghost btn-xs icon-only" title="Monter"><?= icon('chevron-up') ?></button>
                    <button type="submit" name="section" value="move_down" class="btn ghost btn-xs icon-only" title="Descendre"><?= icon('chevron-down') ?></button>
                </div>
                <label class="regle-label">Compte<select name="compte_bancaire_id"><?= $compteOptions($r['compte_bancaire_id'] === null ? '' : (string) $r['compte_bancaire_id']) ?></select></label>
                <label class="regle-label regle-op-wrap" <?= $nbConds <= 1 ? 'hidden' : '' ?>>Conditions<select name="operateur">
                    <option value="ET" <?= ($r['operateur'] ?? 'ET') === 'ET' ? 'selected' : '' ?>>ET (toutes)</option>
                    <option value="OU" <?= ($r['operateur'] ?? 'ET') === 'OU' ? 'selected' : '' ?>>OU (au moins une)</option>
                </select></label>
                <!-- Droite : impact + boutons -->
                <span class="flex-spacer"></span>
                <?php if ($actif): ?>
                    <?php if ($imp > 0): ?>
                        <span class="badge" title="Écritures non lettrées que cette règle attraperait">Touche : <?= $imp ?></span>
                    <?php else: ?>
                        <span class="muted small">Touche : 0</span>
                    <?php endif; ?>
                <?php endif; ?>
                <span class="test-result muted small"></span>
                <button type="button" class="btn ghost btn-sm btn-tester"><?= icon('search') ?> Tester</button>
                <button type="submit" name="section" value="edit" class="btn btn-sm"><?= icon('save') ?> Enregistrer</button>
                <button type="submit" name="section" value="del" class="btn ghost btn-sm btn-danger"
                        onclick="return confirm('Supprimer cette règle ?')"><?= icon('trash') ?></button>
            </div>
            <div class="regle-conds" id="conds-<?= $rid ?>">
                <?php foreach ($r['conditions'] as $cond): ?>
                    <?= $condRow($cond) ?>
                <?php endforeach; ?>
                <?php if (empty($r['conditions'])): ?>
                    <p class="muted small regle-no-cond">Aucune condition — la règle ne s'applique pas.</p>
                <?php endif; ?>
            </div>
            <div class="regle-add-cond">
                <button type="button" class="btn ghost btn-sm add-cond" data-target="conds-<?= $rid ?>"><?= icon('plus') ?> Condition</button>
            </div>
            <div class="regle-cat">
                <label class="regle-label grow">Catégorie cible<?= $catSearchable((int) $r['plan_compte_id']) ?></label>
            </div>
        </form>
    </div>
    <?php endforeach; ?>
</div>

<script>
(function () {
    const tpl = document.getElementById('cond-tpl');

    // Mise à jour de data-type + affichage conditionnel des champs.
    function updateCondType(row) {
        const type = row.querySelector('.cond-type').value;
        row.dataset.type = type;
        row.querySelectorAll('.cond-vis-texte').forEach(el   => el.hidden = (type !== 'texte'));
        row.querySelectorAll('.cond-vis-sens').forEach(el    => el.hidden = (type !== 'sens'));
        row.querySelectorAll('.cond-vis-montant').forEach(el => el.hidden = (type !== 'montant'));
    }

    // Affiche/masque l'opérateur ET/OU selon le nombre de conditions dans le formulaire.
    function updateOperateurVisibility(form) {
        const n    = form.querySelectorAll('.regle-conds .cond-row').length;
        const wrap = form.querySelector('.regle-op-wrap');
        if (wrap) wrap.hidden = (n <= 1);
    }

    function initCondRow(row) {
        const sel = row.querySelector('.cond-type');
        if (sel) sel.addEventListener('change', () => updateCondType(row));
        const rm = row.querySelector('.cond-rm');
        if (rm) rm.addEventListener('click', () => {
            const box  = rm.closest('.regle-conds');
            const form = rm.closest('form');
            row.remove();
            if (box && !box.querySelector('.cond-row')) {
                let msg = box.querySelector('.regle-no-cond');
                if (!msg) {
                    msg = document.createElement('p');
                    msg.className = 'muted small regle-no-cond';
                    msg.textContent = 'Aucune condition — la règle ne s\'applique pas.';
                    box.appendChild(msg);
                }
            }
            if (form) updateOperateurVisibility(form);
        });
    }

    document.querySelectorAll('.cond-row').forEach(initCondRow);

    // Boutons « + Condition ».
    document.querySelectorAll('.add-cond').forEach(btn => {
        btn.addEventListener('click', () => {
            if (!tpl) return;
            const box  = document.getElementById(btn.dataset.target);
            const form = btn.closest('form');
            if (!box) return;
            const clone = tpl.content.firstElementChild.cloneNode(true);
            const msg = box.querySelector('.regle-no-cond');
            if (msg) msg.remove();
            box.appendChild(clone);
            initCondRow(clone);
            clone.querySelector('input, select')?.focus();
            if (form) updateOperateurVisibility(form);
        });
    });

    // Catégorie cherchable.
    function initCatSearch(wrap) {
        const input  = wrap.querySelector('.cat-search-input');
        const hidden = wrap.querySelector('.cat-search-val');
        const list   = wrap.querySelector('.cat-search-list');
        const items  = Array.from(list.querySelectorAll('li'));

        const norm = s => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');

        // Affichage initial.
        const initItem = items.find(li => li.dataset.val === hidden.value);
        if (initItem) input.value = initItem.textContent;

        function filter(q) {
            const nq = norm(q);
            items.forEach(li => { li.hidden = nq !== '' && !norm(li.textContent).includes(nq); });
        }

        input.addEventListener('focus', () => { filter(input.value); list.hidden = false; });
        input.addEventListener('input', () => { filter(input.value); list.hidden = false; });
        input.addEventListener('blur',  () => {
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
                input.value  = li.textContent;
                list.hidden  = true;
                input.setCustomValidity('');
            });
        });
    }
    document.querySelectorAll('.cat-search').forEach(initCatSearch);

    // Validation catégorie avant envoi.
    document.querySelectorAll('.regle-card form').forEach(form => {
        form.addEventListener('submit', function (e) {
            const section = e.submitter?.value;
            if (section === 'move_up' || section === 'move_down') return;
            const hidden = form.querySelector('.cat-search-val');
            const input  = form.querySelector('.cat-search-input');
            if (hidden && !hidden.value && input) {
                input.setCustomValidity('Veuillez choisir une catégorie');
                input.reportValidity();
                e.preventDefault();
            } else if (input) {
                input.setCustomValidity('');
            }
        });
    });

    // Boutons « Tester » : fetch sans rechargement.
    function bindTester(card) {
        const btn = card.querySelector('.btn-tester');
        if (!btn) return;
        btn.addEventListener('click', () => {
            const form = card.querySelector('form');
            if (!form) return;
            const data = new FormData(form);
            data.set('section', 'test');
            const result = card.querySelector('.test-result');
            btn.disabled = true;
            fetch('?p=compta_regles', { method: 'POST', body: data })
                .then(r => r.json())
                .then(j => { if (result) result.textContent = 'Touche : ' + j.n; })
                .catch(() => { if (result) result.textContent = 'Erreur'; })
                .finally(() => { btn.disabled = false; });
        });
    }
    document.querySelectorAll('.regle-card').forEach(bindTester);

    // Ouvrir / fermer la nouvelle règle.
    const card      = document.getElementById('new-rule-card');
    const btnNew    = document.getElementById('btn-new-rule');
    const cancelNew = document.getElementById('cancel-new-rule');
    if (btnNew)    btnNew.addEventListener('click', () => { card.classList.remove('hidden'); card.querySelector('input, select')?.focus(); });
    if (cancelNew) cancelNew.addEventListener('click', () => card.classList.add('hidden'));
})();
</script>
