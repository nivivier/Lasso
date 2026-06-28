<?php
/** @var array $ecritures */ /** @var int $annee */ /** @var array $anneesEcr */
/** @var array $anneesFich */ /** @var int $ecrId */ /** @var array|null $ecrSel */
/** @var string $type */ /** @var array $periodeDefaut */
$typeLabels = ['ocas' => 'OCAS', 'laa' => 'LAA / Artes', 'lpp' => 'LPP / Comoedia'];
$moisNoms   = ['', 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin',
               'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
?>
<?= lien_retour('?p=compta_analyse&annee=' . (int) $annee, 'Comptabilité analytique') ?>
<div class="page-head">
    <div class="page-head-title">
        <h1>Suggérer une ventilation de charges</h1>
        <form method="get">
            <input type="hidden" name="p" value="compta_suggestion_ventilation">
            <select name="annee" class="inline-year-select" onchange="this.form.submit()">
                <?php foreach ($anneesEcr as $a): ?>
                    <option value="<?= (int) $a ?>" <?= (int) $a === $annee ? 'selected' : '' ?>><?= (int) $a ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</div>

<!-- Étape 1 : liste des écritures de charges sociales non ventilées -->
<div class="section-head"><h2>Écriture à ventiler</h2></div>
<?php if (!$ecritures): ?>
<p class="muted small">Aucune écriture de charges sociales non ventilée pour <?= (int) $annee ?>.</p>
<?php else: ?>
<div class="table-scroll">
<table class="list">
    <thead>
        <tr>
            <th>Date</th><th>Compte</th><th>Texte</th>
            <th class="num">Montant</th><th>Type détecté</th><th></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($ecritures as $ecr):
        $t   = detecter_type_charge((string) $ecr['pc_libelle'], (string) $ecr['pc_groupe']);
        $sel = (int) $ecr['id'] === $ecrId;
    ?>
        <tr class="<?= $sel ? 'row-selected' : '' ?>">
            <td><?= e(date('d.m.Y', strtotime((string) $ecr['date_op']))) ?></td>
            <td class="muted small"><?= e((string) $ecr['compte_libelle']) ?></td>
            <td><?= e((string) $ecr['texte']) ?></td>
            <td class="num montant-neg"><?= chf((float) $ecr['montant']) ?></td>
            <td><?= $t ? '<span class="badge">' . e($typeLabels[$t]) . '</span>' : '<span class="muted small">—</span>' ?></td>
            <td>
                <?php if ($sel): ?>
                    <span class="muted small">sélectionnée</span>
                <?php else: ?>
                    <a class="btn ghost btn-sm" href="?p=compta_suggestion_ventilation&annee=<?= (int) $annee ?>&ecriture_id=<?= (int) $ecr['id'] ?>">Sélectionner</a>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

<?php if ($ecrSel): ?>
<!-- Étapes 2 & 3 : période + suggestion -->
<div class="section-head" style="margin-top:2rem"><h2>Période de référence des salaires</h2></div>
<div class="card suggestion-config">
    <div class="suggestion-ecriture">
        <span class="muted small"><?= e(date('d.m.Y', strtotime((string) $ecrSel['date_op']))) ?></span>
        <strong><?= e((string) $ecrSel['texte']) ?></strong>
        <strong class="montant-neg"><?= chf((float) $ecrSel['montant']) ?> CHF</strong>
        <?php if ($type): ?>
            <span class="badge"><?= e($typeLabels[$type]) ?></span>
            <input type="hidden" id="type-val" value="<?= e($type) ?>">
        <?php endif; ?>
    </div>

    <?php if (!$type): ?>
    <div class="suggestion-row">
        <label for="type-sel">Type de charge :</label>
        <select id="type-val">
            <option value="">— Choisir —</option>
            <option value="ocas">OCAS (AVS / AI / APG / AC / AF)</option>
            <option value="laa">LAA / Artes</option>
            <option value="lpp">LPP / Comoedia</option>
        </select>
    </div>
    <?php endif; ?>

    <div class="suggestion-row">
        <label>Période :</label>
        <select id="mois-debut">
            <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= $m === ($periodeDefaut[1] ?? 0) ? 'selected' : '' ?>><?= e($moisNoms[$m]) ?></option>
            <?php endfor; ?>
        </select>
        <select id="annee-debut">
            <?php foreach ($anneesFich as $a): ?>
                <option value="<?= (int) $a ?>" <?= (int) $a === ($periodeDefaut[0] ?? 0) ? 'selected' : '' ?>><?= (int) $a ?></option>
            <?php endforeach; ?>
        </select>
        <span class="muted">→</span>
        <select id="mois-fin">
            <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= $m === ($periodeDefaut[3] ?? 0) ? 'selected' : '' ?>><?= e($moisNoms[$m]) ?></option>
            <?php endfor; ?>
        </select>
        <select id="annee-fin">
            <?php foreach ($anneesFich as $a): ?>
                <option value="<?= (int) $a ?>" <?= (int) $a === ($periodeDefaut[2] ?? 0) ? 'selected' : '' ?>><?= (int) $a ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <!-- Raccourcis temporels -->
    <?php
    $dateOp   = (string) $ecrSel['date_op'];
    $moisVers = (int) date('n', strtotime($dateOp));
    $anneeVers = (int) date('Y', strtotime($dateOp));
    $shortcuts = [
        ['Année préc.',  $anneeVers - 1, 1, $anneeVers - 1, 12],
        ['S1',           $anneeVers, 1, $anneeVers, 6],
        ['S2',           $anneeVers, 7, $anneeVers, 12],
        ['T1',           $anneeVers, 1, $anneeVers, 3],
    ];
    ?>
    <div class="suggestion-row">
        <label class="muted small">Raccourcis :</label>
        <?php foreach ($shortcuts as [$label, $ad, $md, $af, $mf]): ?>
            <button type="button" class="btn ghost btn-sm shortcut-btn"
                    data-ad="<?= $ad ?>" data-md="<?= $md ?>"
                    data-af="<?= $af ?>" data-mf="<?= $mf ?>">
                <?= e($label) ?>
            </button>
        <?php endforeach; ?>
    </div>

    <div class="suggestion-row">
        <button type="button" id="btn-calculer" class="btn"><?= icon('bar-chart') ?> Calculer</button>
    </div>
</div>

<!-- Résultat de la suggestion -->
<div id="suggestion-result" hidden>
    <div class="section-head" style="margin-top:2rem"><h2>Ventilation suggérée</h2></div>
    <div class="card">
        <table class="list suggestion-table">
            <thead>
                <tr>
                    <th>Axe</th>
                    <th class="num">Prévu (fiches)</th>
                    <th class="num">Montant à ventiler</th>
                </tr>
            </thead>
            <tbody id="suggestion-tbody"></tbody>
            <tfoot>
                <tr class="cr-total">
                    <td>Total des fiches</td>
                    <td class="num" id="total-fiches">—</td>
                    <td class="num" id="total-saisi">—</td>
                </tr>
                <tr>
                    <td>Versement réel</td>
                    <td></td>
                    <td class="num strong"><?= chf(abs((float) $ecrSel['montant'])) ?> CHF</td>
                </tr>
                <tr>
                    <td>Écart</td>
                    <td></td>
                    <td class="num" id="ecart-val">—</td>
                </tr>
            </tfoot>
        </table>
        <p class="muted small" id="suggestion-note" hidden></p>

        <form method="post" action="?p=compta_ventilation_save" id="form-vent-save">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="ecriture_id" value="<?= (int) $ecrId ?>">
            <div id="vent-hidden-inputs"></div>
            <div class="form-actions" style="margin-top:1rem">
                <button type="submit" class="btn"><?= icon('save') ?> Enregistrer la ventilation</button>
                <a href="?p=compta_ecritures" class="btn ghost">Annuler</a>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const MONTANT_ECR = <?= abs((float) $ecrSel['montant']) ?>;

    function getType() {
        return document.getElementById('type-val')?.value || '';
    }

    // Raccourcis période
    document.querySelectorAll('.shortcut-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.getElementById('annee-debut').value = btn.dataset.ad;
            document.getElementById('mois-debut').value  = btn.dataset.md;
            document.getElementById('annee-fin').value   = btn.dataset.af;
            document.getElementById('mois-fin').value    = btn.dataset.mf;
        });
    });

    document.getElementById('btn-calculer').addEventListener('click', async () => {
        const aD = document.getElementById('annee-debut').value;
        const mD = document.getElementById('mois-debut').value;
        const aF = document.getElementById('annee-fin').value;
        const mF = document.getElementById('mois-fin').value;
        const t  = getType();
        if (!t) { alert('Veuillez choisir le type de charge (OCAS, LAA ou LPP).'); return; }

        const btn = document.getElementById('btn-calculer');
        btn.disabled = true;
        try {
            const url = `?p=compta_suggestion_preview&annee_debut=${aD}&mois_debut=${mD}&annee_fin=${aF}&mois_fin=${mF}&type=${t}`;
            const data = await fetch(url).then(r => r.json()).catch(() => null);
            if (!data?.ok) { alert('Erreur lors du calcul. Vérifiez la période.'); return; }
            renderSuggestion(data.suggestions);
        } finally {
            btn.disabled = false;
        }
    });

    function renderSuggestion(suggestions) {
        const tbody  = document.getElementById('suggestion-tbody');
        const hidden = document.getElementById('vent-hidden-inputs');
        tbody.innerHTML = ''; hidden.innerHTML = '';

        let totalFiches = 0;
        suggestions.forEach(s => { totalFiches += s.montant; });
        lastTotalFiches = totalFiches;

        if (suggestions.length === 0) {
            tbody.innerHTML = '<tr><td colspan="3" class="muted">Aucune ligne de fiche ventilée sur cette période.</td></tr>';
            document.getElementById('suggestion-result').hidden = false;
            return;
        }

        suggestions.forEach(s => {
            const tr = document.createElement('tr');
            const label = s.code ? e(s.code) : e(s.libelle);
            tr.innerHTML =
                `<td>${label}<span class="muted small" style="margin-left:4px">${s.code ? e(s.libelle) : ''}</span></td>` +
                `<td class="num muted">${fmtChf(s.montant)}</td>` +
                `<td class="num"><input type="number" class="vent-montant-inp" step="0.05" min="0"` +
                ` value="${s.montant.toFixed(2)}" data-axe-id="${s.axe_id}" style="width:100px;text-align:right;font:inherit;font-size:13px;padding:2px 5px;border:1px solid var(--line);border-radius:var(--radius-sm)"> CHF</td>`;
            tbody.appendChild(tr);

            const iA = document.createElement('input'); iA.type='hidden'; iA.name='axe_id[]'; iA.value=s.axe_id;
            const iM = document.createElement('input'); iM.type='hidden'; iM.name='montant[]'; iM.className='vent-m'; iM.dataset.axeId=s.axe_id; iM.value=s.montant.toFixed(2);
            hidden.append(iA, iM);
        });

        updateTotals(totalFiches, totalFiches);
        document.getElementById('suggestion-result').hidden = false;

        // Note si écart naturel
        const ecart = Math.abs(MONTANT_ECR - totalFiches);
        const note  = document.getElementById('suggestion-note');
        if (ecart > 0.005) {
            note.textContent = `Écart de ${fmtChf(ecart)} CHF entre le total des fiches et le versement réel (arrondis, acomptes, cotisations planchers). Ajustez manuellement les montants.`;
            note.hidden = false;
        } else {
            note.hidden = true;
        }
    }

    // Listener unique sur tbody (survit aux re-renders car tbody.innerHTML est remplacé,
    // pas le tbody lui-même — le listener reste attaché au même nœud DOM).
    let lastTotalFiches = 0;
    document.getElementById('suggestion-tbody').addEventListener('input', ev => {
        const inp = ev.target.closest('.vent-montant-inp');
        if (!inp) return;
        const hidden = document.getElementById('vent-hidden-inputs');
        const hid = hidden.querySelector(`.vent-m[data-axe-id="${inp.dataset.axeId}"]`);
        if (hid) hid.value = inp.value;
        const totalSaisi = Array.from(document.querySelectorAll('.vent-montant-inp'))
            .reduce((s, i) => s + (parseFloat(i.value) || 0), 0);
        updateTotals(lastTotalFiches, totalSaisi);
    });

    function updateTotals(fromFiches, saisi) {
        document.getElementById('total-fiches').textContent = fmtChf(fromFiches) + ' CHF';
        document.getElementById('total-saisi').textContent  = fmtChf(saisi) + ' CHF';
        const ecart = MONTANT_ECR - saisi;
        const el    = document.getElementById('ecart-val');
        el.textContent  = (ecart >= 0 ? '+' : '') + fmtChf(ecart) + ' CHF';
        el.style.color  = Math.abs(ecart) < 0.005 ? 'var(--primary)' : Math.abs(ecart) < 5 ? '' : 'var(--danger)';
        el.style.fontWeight = Math.abs(ecart) < 0.005 ? '' : 'bold';
    }

    function fmtChf(n) {
        return n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, "’");
    }
    function e(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }
})();
</script>
<?php endif; ?>
