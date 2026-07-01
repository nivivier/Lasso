<?php
/** @var array $employes */ /** @var array $tauxHoraires */ /** @var array $unites */ /** @var array $axes */
/** @var ?string $err */ /** @var ?array $post */
/** @var bool $edit_mode */ /** @var int $fiche_id */
$pv = fn(string $k, $d = '') => e((string) ($post[$k] ?? $d));
$edit = !empty($edit_mode);
$axes = $axes ?? [];

// Options d'unités, encodées "heures|libellé"
$opts = '';
foreach ($unites as $u) {
    $val = $u['heures'] . '|' . $u['libelle'];
    $opts .= '<option value="' . e($val) . '" data-h="' . e((string) $u['heures']) . '">'
        . e($u['libelle']) . ' (' . nombre_court($u['heures']) . ' h)</option>';
}

// Options de taux horaire (standard) + « Autre »
$rateOpts = '';
foreach ($tauxHoraires as $th) {
    $rateOpts .= '<option value="' . e((string) $th['montant']) . '" data-rate="' . e((string) $th['montant']) . '">'
        . e($th['libelle'] . ' — ' . chf((float) $th['montant']) . ' CHF/h') . '</option>';
}
$rateOpts .= '<option value="autre">Autre…</option>';

// Options de l'axe analytique (select par ligne de prestation)
$axeOpts = '<option value="">—</option>';
foreach ($axes as $ax) {
    $axeLabel = ($ax['code'] !== '' && $ax['code'] !== null) ? $ax['code'] : $ax['libelle'];
    $axeOpts .= '<option value="' . (int) $ax['id'] . '">' . e($axeLabel) . '</option>';
}

// Lignes initiales (repli sur une ligne vide, ou repopulation après erreur / édition)
$lignesInit = [];
if (!empty($post['l_unite'])) {
    foreach ($post['l_unite'] as $i => $enc) {
        $lignesInit[] = [
            'enc'    => (string) $enc,
            'qte'    => (string) ($post['l_quantite'][$i] ?? ''),
            'choix'  => (string) ($post['l_taux_choix'][$i] ?? ''),
            'manuel' => (string) ($post['l_taux_manuel'][$i] ?? ''),
            'axe'    => (string) ($post['l_axe'][$i] ?? ''),
        ];
    }
}
if (!$lignesInit) {
    $lignesInit[] = ['enc' => '', 'qte' => '', 'choix' => '', 'manuel' => '', 'axe' => ''];
}

$preselect = function (string $optionsHtml, string $value): string {
    if ($value === '') {
        return $optionsHtml;
    }
    return preg_replace_callback('/<option value="([^"]*)"/', function ($m) use ($value) {
        return $m[0] . (html_entity_decode($m[1], ENT_QUOTES) === $value ? ' selected' : '');
    }, $optionsHtml);
};

$renderRow = function (array $l) use ($opts, $rateOpts, $preselect, $axes, $axeOpts) {
    $axeSel = $axes
        ? '<select name="l_axe[]" class="l-axe" title="Axe analytique">' . $preselect($axeOpts, (string) ($l['axe'] ?? '')) . '</select>'
        : '';
    return '<div class="ligne-row">'
        . '<select name="l_unite[]" class="l-unite">' . $preselect($opts, $l['enc']) . '</select>'
        . '<input name="l_quantite[]" class="l-qte" type="text" inputmode="decimal" placeholder="quantité" value="' . e($l['qte']) . '">'
        . '<select name="l_taux_choix[]" class="l-taux-choix">' . $preselect($rateOpts, $l['choix']) . '</select>'
        . '<input name="l_taux_manuel[]" class="l-taux-manuel" type="text" inputmode="decimal" placeholder="CHF/h" value="' . e($l['manuel']) . '">'
        . $axeSel
        . '<span class="l-sub muted"></span>'
        . '<button type="button" class="btn ghost btn-sm l-del" aria-label="Supprimer la ligne">✕</button>'
        . '</div>';
};
?>
<?= lien_retour('?p=fiches', 'Fiches de salaire') ?>
<div class="page-head">
    <h1><?= $edit ? 'Modifier la fiche de salaire' : 'Nouvelle fiche de salaire' ?></h1>
</div>

<?php if (!$employes): ?>
    <p class="muted">Aucun employé actif. <a href="?p=employe">Ajoutez un employé</a> d'abord.</p>
<?php elseif (!$unites): ?>
    <p class="muted">Aucune unité de temps définie. <a href="?p=employeur">Ajoutez au moins « Heure » (1 h)</a> d'abord.</p>
<?php else: ?>
<?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>

<form method="post" action="?p=fiche_new" class="card form">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <?php if ($edit && isset($fiche_id)): ?>
        <input type="hidden" name="fiche_id" value="<?= (int) $fiche_id ?>">
    <?php endif; ?>

    <label>Employé
        <select name="employe_id" id="employe-select" required>
            <option value="">— choisir —</option>
            <?php foreach ($employes as $emp): ?>
                <option value="<?= (int) $emp['id'] ?>"
                        data-impot="<?= e(number_format((float) $emp['impot_source_taux'] * 100, 2, '.', '')) ?>"
                        data-source="<?= $emp['procedure'] === 'Ordinaire avec impôt à la source' ? '1' : '0' ?>"
                        data-supp="<?= e(number_format((float) $emp['supplement_vacances'] * 100, 4, '.', '')) ?>"
                        <?= (string) ($post['employe_id'] ?? '') === (string) $emp['id'] ? 'selected' : '' ?>>
                    <?= e($emp['prenom'] . ' ' . $emp['nom']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>

    <div class="grid2">
        <label>Mois
            <select name="mois">
                <?php $m = (int) ($post['mois'] ?? date('n'));
                foreach (MOIS_FR as $num => $nom): ?>
                    <option value="<?= $num ?>" <?= $num === $m ? 'selected' : '' ?>><?= e($nom) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Année
            <input name="annee" type="number" value="<?= $pv('annee', (string) date('Y')) ?>" min="2000" max="2100">
        </label>
    </div>

    <h3 class="sub">Prestations</h3>
    <p class="muted small">Une ou plusieurs lignes : unité (heures, jours, services…), quantité et taux horaire (un taux standard ou « Autre » pour saisir manuellement).</p>
    <div id="lignes">
        <?php foreach ($lignesInit as $l) echo $renderRow($l); ?>
    </div>
    <div class="lignes-foot">
        <button type="button" class="btn ghost btn-sm" id="add-ligne">+ Ajouter une ligne</button>
        <span class="total-h">Total : <strong id="total-h">0</strong> h · <strong id="total-chf">0.00</strong> CHF</span>
    </div>

    <div class="grid2 mt-18">
        <label id="supp-field">Supplément vacances (%)
            <input name="supplement_vacances" type="text" inputmode="decimal" value="<?= $pv('supplement_vacances') ?>" placeholder="défaut employé">
        </label>
        <label id="impot-field">Taux impôt à la source (%)
            <input name="impot_source_taux" type="text" inputmode="decimal" value="<?= $pv('impot_source_taux') ?>" placeholder="défaut employé">
        </label>
    </div>
    <p class="muted small">
        Laissez vide pour reprendre les valeurs par défaut de l'employé. L'impôt à la source n'est prélevé que
        si la procédure de l'employé est « Ordinaire avec impôt à la source ».
    </p>

    <div class="grid2">
        <label>Date de paiement (optionnel)
            <input name="date_paiement" type="date" value="<?= $pv('date_paiement') ?>">
        </label>
    </div>
    <div class="form-actions">
        <button type="submit"><?= $edit ? 'Enregistrer les modifications' : 'Calculer et créer la fiche' ?></button>
        <a class="btn ghost" href="?p=fiches">Annuler</a>
    </div>
</form>

<template id="ligne-tpl"><?= $renderRow(['enc' => '', 'qte' => '', 'choix' => '', 'manuel' => '', 'axe' => '']) ?></template>

<script>
(function () {
    // Valeurs par défaut selon l'employé (impôt + supplément vacances)
    const sel = document.getElementById('employe-select');
    const impot = document.getElementById('impot-field');
    const supp  = document.getElementById('supp-field');
    function syncEmp() {
        const opt = sel.options[sel.selectedIndex];
        const isSource = opt && opt.dataset.source === '1';
        impot.style.opacity = isSource ? '1' : '.5';
        impot.querySelector('input').placeholder = opt && opt.dataset.impot ? ('défaut ' + opt.dataset.impot + ' %') : 'défaut employé';
        supp.querySelector('input').placeholder = opt && opt.dataset.supp ? ('défaut ' + opt.dataset.supp + ' %') : 'défaut employé';
    }
    sel.addEventListener('change', syncEmp); syncEmp();

    // Lignes de prestation
    const lignes = document.getElementById('lignes');
    const tpl = document.getElementById('ligne-tpl');
    const totH = document.getElementById('total-h');
    const totC = document.getElementById('total-chf');
    function num(v) { return parseFloat((v || '').toString().replace(',', '.')) || 0; }
    function rowRate(row) {
        const choix = row.querySelector('.l-taux-choix');
        const manuel = row.querySelector('.l-taux-manuel');
        const isAutre = choix.value === 'autre';
        manuel.style.display = isAutre ? '' : 'none';
        manuel.required = isAutre;
        return isAutre ? num(manuel.value) : num(choix.value);
    }
    function recalc() {
        let h = 0, chf = 0;
        lignes.querySelectorAll('.ligne-row').forEach(row => {
            const opt = row.querySelector('.l-unite').selectedOptions[0];
            const hu = opt ? num(opt.dataset.h) : 0;
            const q  = num(row.querySelector('.l-qte').value);
            const t  = rowRate(row);
            const sousH = hu * q, montant = sousH * t;
            row.querySelector('.l-sub').textContent = q > 0 ? ('= ' + (Math.round(sousH * 100) / 100) + ' h · ' + (Math.round(montant * 100) / 100) + ' CHF') : '';
            h += sousH; chf += montant;
        });
        totH.textContent = Math.round(h * 100) / 100;
        totC.textContent = (Math.round(chf * 100) / 100).toFixed(2);
    }
    lignes.addEventListener('input', recalc);
    lignes.addEventListener('change', recalc);
    lignes.addEventListener('click', e => {
        if (e.target.closest('.l-del')) {
            if (lignes.querySelectorAll('.ligne-row').length > 1) e.target.closest('.ligne-row').remove();
            else { e.target.closest('.ligne-row').querySelectorAll('input').forEach(i => i.value = ''); }
            recalc();
        }
    });
    document.getElementById('add-ligne').addEventListener('click', () => {
        lignes.appendChild(tpl.content.cloneNode(true));
        recalc();
    });
    recalc();
})();
</script>
<?php endif; ?>
