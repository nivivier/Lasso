<?php
/** @var int $annee */ /** @var array $annees */ /** @var array $cols */ /** @var int $nbPrec */
/** @var array $resultat */ /** @var array $sommesParAnnee */ /** @var array $totauxParAnnee */
/** @var array $continuite */ /** @var array $plan */ /** @var array $lignesParCat */
/** @var array $patrimoine */

?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>

<div class="module-content">
    <div class="module-content-inner">
        <div class="toolbar">

            <form method="get" class="filters">
                <input type="hidden" name="p" value="compta_bilan">
                <label>Année
                    <select name="annee" data-submit-on-change>
                        <?php foreach ($annees as $a): ?>
                            <option value="<?= (int) $a ?>" <?= $annee === (int) $a ? 'selected' : '' ?>><?= (int) $a ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </form>

            <div class="filters">
                <?php
                $comparAnnees = array_values(array_filter(array_map('intval', $cols), fn($a) => $a !== $annee));
                $jusquaEff  = $comparAnnees ? min($comparAnnees) : 0;
                $anneesPrec = array_values(array_filter(array_map('intval', $annees), fn($a) => $a < $annee));
                ?>
                <label>
                    Comparer jusqu'à
                    <select class="inline-year-select" data-go-on-change="?p=compta_bilan&annee=<?= (int) $annee ?>&jusqua=">
                        <option value="aucune" <?= $jusquaEff === 0 ? 'selected' : '' ?>>-</option>
                        <?php foreach ($anneesPrec as $y): ?>
                            <option value="<?= $y ?>" <?= $jusquaEff === $y ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <a class="btn ghost" href="?p=compta_bilan_imprimer&annee=<?= (int) $annee ?>&prec=<?= $nbPrec ?>" data-preview target="_blank" rel="noopener"><?= icon('eye') ?><span class="lbl"> Aperçu</span></a>
            </div>

        </div>

        <?php if ($continuite): ?>
        <div class="err mb-16">
            <strong>Report à nouveau — contrôle de continuité :</strong>
            <ul class="mb-0">
                <?php foreach ($continuite as $a): ?>
                <li><?= e($a['compte']) ?> : solde d'ouverture <?= (int) $a['annee'] ?> (<?= chf($a['ouverture']) ?>) ≠ clôture <?= (int) $a['annee'] - 1 ?> (<?= chf($a['cloture_prec']) ?>) — un export est peut-être manquant entre les deux exercices.</li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>


        <?php require __DIR__ . '/_compta_bilan_body.php'; ?>
    </div>

</div>

<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    // Clic (ou Entrée/Espace) sur une catégorie → déplie ses écritures.
    function toggle(row) {
        const cat = row.getAttribute('data-cat');
        const det = document.querySelector('.cr-detail[data-cat="' + cat + '"]');
        if (!det) return;
        const open = det.hasAttribute('hidden');
        if (open) { det.removeAttribute('hidden'); } else { det.setAttribute('hidden', ''); }
        row.classList.toggle('open', open);
        row.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    document.querySelectorAll('.cr-clic').forEach(row => {
        row.addEventListener('click', () => toggle(row));
        row.addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(row); }
        });
    });

})();
</script>
