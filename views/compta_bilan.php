<?php
/** @var int $annee */ /** @var array $annees */ /** @var array $cols */ /** @var int $nbPrec */
/** @var array $resultat */ /** @var array $sommesParAnnee */ /** @var array $totauxParAnnee */
/** @var array $continuite */ /** @var array $plan */ /** @var array $lignesParCat */
/** @var array $patrimoine */
/** @var ?int $clotureAnnee */ /** @var string $clotureLe */ /** @var string $cloturePar */ /** @var string $clotureOk */
$anneeClose = $clotureAnnee !== null && $annee <= $clotureAnnee;

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
            </div>

            <?php // L'aperçu agit, il ne filtre pas : il monte avec les actions de
                  // la page (docs/UI.md § 1), comme sur ?p=compta_analyse. ?>
            <div class="head-actions">
                <?php // Clôturer : l'exercice affiché, s'il est ouvert et terminé
                      // ou en cours. Rouvrir : seulement le DERNIER clos. Réservé
                      // aux administrateurs (route_compta_exercice_cloturer()). ?>
                <?php if (est_admin() && !$anneeClose): ?>
                <form method="post" action="?p=compta_exercice_cloturer" class="d-inline"
                      data-confirm="Clôturer l'exercice <?= (int) $annee ?><?= $clotureAnnee === null || $clotureAnnee < $annee - 1 ? ' et tous ceux qui le précèdent' : '' ?> ? Ses écritures ne pourront plus être ajoutées, modifiées ni supprimées. Une sauvegarde est faite avant.">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="annee" value="<?= (int) $annee ?>">
                    <button type="submit" class="btn ghost"><?= icon('lock') ?><span class="lbl"> Clôturer <?= (int) $annee ?></span></button>
                </form>
                <?php elseif (est_admin() && $clotureAnnee === $annee): ?>
                <form method="post" action="?p=compta_exercice_cloturer" class="d-inline"
                      data-confirm="Rouvrir l'exercice <?= (int) $annee ?> ? Ses écritures redeviennent modifiables. Une sauvegarde est faite avant.">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="annee" value="<?= (int) $annee ?>">
                    <input type="hidden" name="action" value="rouvrir">
                    <button type="submit" class="btn ghost"><?= icon('unlock') ?><span class="lbl"> Rouvrir <?= (int) $annee ?></span></button>
                </form>
                <?php endif; ?>
                <?php // data-plus : derrière « … » d'office — sans action pleine, le
                      // dernier du bloc serait pris pour l'action principale. ?>
                <a class="btn ghost" href="?p=compta_bilan_imprimer&annee=<?= (int) $annee ?>&prec=<?= $nbPrec ?>" data-preview data-plus target="_blank" rel="noopener"><?= icon('eye') ?><span class="lbl"> Aperçu</span></a>
            </div>

        </div>

        <?php if ($clotureOk === 'clos'): ?><p class="ok flash">Exercice <?= (int) $annee ?> clôturé.</p><?php endif; ?>
        <?php if ($clotureOk === 'rouvert'): ?><p class="ok flash">Exercice <?= (int) $annee ?> rouvert.</p><?php endif; ?>
        <?php if ($clotureOk === 'refus'): ?><p class="err flash">Seul un administrateur clôture un exercice, et seul le dernier clos se rouvre.</p><?php endif; ?>
        <?php if ($anneeClose): ?>
        <p class="muted small mb-16"><?= icon('lock') ?> Exercice <?= (int) $annee ?> clôturé<?php if ($clotureLe !== '' && $clotureAnnee === $annee): ?>
            le <?= e(horodatage($clotureLe, 'd.m.Y')) ?><?= $cloturePar !== '' ? ' par ' . e($cloturePar) : '' ?><?php endif; ?> :
            ses écritures ne se modifient plus.</p>
        <?php endif; ?>

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
