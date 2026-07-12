<?php
/** @var array $employes */ /** @var array $tauxHoraires */ /** @var array $unites */ /** @var array $axes */
/** @var array $evenements */ /** @var ?string $err */ /** @var ?array $post */
/** @var bool $edit_mode */ /** @var int $fiche_id */ /** @var ?bool $saved */ /** @var array $tauxData */
$pv = fn(string $k, $d = '') => e((string) ($post[$k] ?? $d));
$edit = !empty($edit_mode);
$axes = $axes ?? [];
$evenements = $evenements ?? [];

// Options d'unités, encodées "heures|libellé"
$opts = options_unites($unites);

// Options de taux horaire (standard) + « Autre »
$rateOpts = options_taux_horaires($tauxHoraires);

// Options de l'axe analytique (select par ligne de prestation)
$axeOpts = options_axes($axes);

// Options d'événement (select par ligne, affiché seulement pour les lignes déjà liées)
$evLabel = function (array $ev): string {
    $d    = $ev['date'] ? date('d.m.Y', strtotime((string) $ev['date'])) : '';
    $lieu = $ev['spectacle'] ?: ($ev['festival'] ?: ($ev['salle'] ?: $ev['ville']));
    $s    = trim($d . ($lieu !== '' ? ' — ' . $lieu : ''));
    return $s !== '' ? $s : ('Événement #' . (int) $ev['id']);
};
$evenOpts = '<option value="">— aucun —</option>';
foreach ($evenements as $ev) {
    $evenOpts .= '<option value="' . (int) $ev['id'] . '">' . e($evLabel($ev)) . '</option>';
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
            'evenement' => (string) ($post['l_evenement'][$i] ?? ''),
        ];
    }
}
if (!$lignesInit) {
    $lignesInit[] = ['enc' => '', 'qte' => '', 'choix' => '', 'manuel' => '', 'axe' => '', 'evenement' => ''];
}

$renderRow = function (array $l) use ($opts, $rateOpts, $axes, $axeOpts, $evenements, $evenOpts) {
    $axeSel = $axes
        ? '<select name="l_axe[]" class="l-axe" title="Axe analytique">' . preselectionner_option($axeOpts, (string) ($l['axe'] ?? '')) . '</select>'
        : '';
    // Événement : toujours un select (vide par défaut) pour permettre de lier
    // une ligne non encore rattachée, pas seulement d'éditer un lien existant.
    $evSel = $evenements
        ? '<select name="l_evenement[]" class="l-evenement" title="Événement associé">' . preselectionner_option($evenOpts, (string) ($l['evenement'] ?? '')) . '</select>'
        : '';
    return '<div class="ligne-row ligne-row-presta">'
        . '<select name="l_unite[]" class="l-unite">' . preselectionner_option($opts, $l['enc']) . '</select>'
        . '<input name="l_quantite[]" class="l-qte" type="text" inputmode="decimal" placeholder="quantité" value="' . e($l['qte']) . '">'
        . '<select name="l_taux_choix[]" class="l-taux-choix">' . preselectionner_option($rateOpts, $l['choix']) . '</select>'
        . '<input name="l_taux_manuel[]" class="l-taux-manuel" type="text" inputmode="decimal" placeholder="CHF/h" value="' . e($l['manuel']) . '">'
        . $axeSel
        . $evSel
        . '<span class="l-sub muted"></span>'
        . '<button type="button" class="btn ghost btn-sm l-del" aria-label="Supprimer la ligne">✕</button>'
        . '</div>';
};
?>
<?= lien_retour('?p=fiches', 'Fiches de salaire') ?>
<div class="page-head">
    <h1><?= $edit ? 'Modifier la fiche de salaire' : 'Nouvelle fiche de salaire' ?></h1>
</div>

<?php if (!empty($saved)): ?><p class="ok flash">✓ Fiche enregistrée avec succès.</p><?php endif; ?>

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

    <h3 class="sub">Prestations <?= info_tip(
        'Une ou plusieurs lignes : unité (heures, jours, services…), quantité et taux horaire '
        . '(un taux standard ou « Autre » pour saisir manuellement).'
    ) ?></h3>
    <div id="lignes">
        <?php foreach ($lignesInit as $l) echo $renderRow($l); ?>
    </div>
    <div class="lignes-foot">
        <button type="button" class="btn ghost btn-sm" id="add-ligne">+ Ajouter une ligne</button>
        <span class="total-h">Total : <strong id="total-h">0</strong> h · <strong id="total-chf">0.00</strong> CHF</span>
    </div>

    <div class="grid2 mt-18">
        <label id="supp-field"><span>Supplément vacances (%) <?= info_tip("Laissez vide pour reprendre la valeur par défaut de l'employé.") ?></span>
            <input name="supplement_vacances" type="text" inputmode="decimal" value="<?= $pv('supplement_vacances') ?>" placeholder="défaut employé">
        </label>
        <label id="impot-field"><span>Taux impôt à la source (%) <?= info_tip(
            "Laissez vide pour reprendre la valeur par défaut de l'employé. N'est prélevé que si la procédure de "
            . "l'employé est « Ordinaire avec impôt à la source »."
        ) ?></span>
            <input name="impot_source_taux" type="text" inputmode="decimal" value="<?= $pv('impot_source_taux') ?>" placeholder="défaut employé">
        </label>
    </div>

    <h3 class="sub">Coûts estimés <?= info_tip(
        "Estimation en direct à partir des lignes de prestation ci-dessus. Les montants définitifs "
        . "(et les taux effectivement appliqués) sont figés à l'enregistrement."
    ) ?></h3>
    <div class="couts-estimes" id="couts-estimes">
        <div class="ce-item"><span class="ce-label">Salaire net</span><strong class="ce-val ce-net" id="est-net">0.00 CHF</strong></div>
        <div class="ce-item"><span class="ce-label">Salaire brut</span><strong class="ce-val ce-brut" id="est-brut">0.00 CHF</strong></div>
        <div class="ce-item"><span class="ce-label">Coût employeur</span><strong class="ce-val ce-cout" id="est-cout">0.00 CHF</strong></div>
    </div>

    <div class="form-actions">
        <button type="submit"><?= $edit ? icon('save') . ' Enregistrer les modifications' : 'Calculer et créer la fiche' ?></button>
        <a class="btn ghost" href="?p=fiches">Annuler</a>
    </div>
</form>

<template id="ligne-tpl"><?= $renderRow(['enc' => '', 'qte' => '', 'choix' => '', 'manuel' => '', 'axe' => '', 'evenement' => '']) ?></template>

<script>
const LASSO_TAUX_DATA = <?= json_encode($tauxData, JSON_UNESCAPED_UNICODE) ?>;
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
    // Coûts estimés (port JS de calculer_fiche(), voir lib/calc.php) : aperçu en
    // direct, non contractuel — les montants réels sont figés à l'enregistrement.
    const moisSelect = document.querySelector('select[name="mois"]');
    const anneeInput = document.querySelector('input[name="annee"]');
    const suppInput  = document.querySelector('input[name="supplement_vacances"]');
    const impotInput = document.querySelector('input[name="impot_source_taux"]');
    const estNet  = document.getElementById('est-net');
    const estBrut = document.getElementById('est-brut');
    const estCout = document.getElementById('est-cout');
    function r2(v) { return Math.round(v * 100) / 100; }
    function seuilHeures(annee, mois) {
        return new Date(annee, mois, 0).getDate() / 7 * 8; // jours du mois ÷ 7 × 8
    }
    function tauxPourAnnee(annee) {
        const years = Object.keys(LASSO_TAUX_DATA.parAnnee).map(Number).sort((a, b) => a - b);
        let choisie = null;
        years.forEach(y => { if (y <= annee) choisie = y; });
        return choisie !== null ? LASSO_TAUX_DATA.parAnnee[choisie] : LASSO_TAUX_DATA.defaut;
    }
    function calculerFiche(salaireTravail, heures, annee, mois, estSource, tauxImpot, tauxSupp) {
        const taux = Object.assign({}, tauxPourAnnee(annee));
        const plein = heures > seuilHeures(annee, mois);
        taux.laa = plein ? (taux.laa_plein || 0) : (taux.laa_reduit || 0);
        taux.emp_laa = plein ? (taux.emp_laa_plein || 0) : (taux.emp_laa_reduit || 0);

        salaireTravail = r2(salaireTravail);
        const suppMontant = r2(salaireTravail * tauxSupp);
        const brut = r2(salaireTravail + suppMontant);

        const dedAvs = r2(brut * taux.avs), dedAc = r2(brut * taux.ac), dedAmat = r2(brut * taux.amat);
        const dedLaa = r2(brut * taux.laa), dedLpp = r2(brut * taux.lpp);
        const dedImpot = estSource ? r2(brut * tauxImpot) : 0;
        const net = r2(brut - r2(dedAvs + dedAc + dedAmat + dedLaa + dedLpp + dedImpot));

        const empCles = ['emp_avs', 'emp_ac', 'emp_amat', 'emp_af', 'emp_laa', 'emp_frais', 'emp_cpe', 'emp_lfp', 'emp_lpp'];
        const empTotal = r2(empCles.reduce((s, k) => s + r2(brut * (taux[k] || 0)), 0));
        return { net, brut, cout: r2(brut + empTotal) };
    }
    function recalcCouts(heures, salaireTravail) {
        const opt = sel.options[sel.selectedIndex];
        const estSource = !!opt && opt.dataset.source === '1';
        const annee = num(anneeInput.value) || new Date().getFullYear();
        const mois  = num(moisSelect.value) || (new Date().getMonth() + 1);
        const tauxSupp  = suppInput.value.trim() !== '' ? num(suppInput.value) / 100 : (opt ? num(opt.dataset.supp) / 100 : 0);
        const tauxImpot = impotInput.value.trim() !== '' ? num(impotInput.value) / 100 : (opt ? num(opt.dataset.impot) / 100 : 0);
        const c = calculerFiche(salaireTravail, heures, annee, mois, estSource, tauxImpot, tauxSupp);
        estNet.textContent  = c.net.toFixed(2) + ' CHF';
        estBrut.textContent = c.brut.toFixed(2) + ' CHF';
        estCout.textContent = c.cout.toFixed(2) + ' CHF';
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
        recalcCouts(h, chf);
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
    sel.addEventListener('change', recalc);
    moisSelect.addEventListener('change', recalc);
    [anneeInput, suppInput, impotInput].forEach(i => i.addEventListener('input', recalc));
    recalc();
})();
</script>
<?php endif; ?>
