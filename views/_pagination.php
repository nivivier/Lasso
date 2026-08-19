<?php
/** @var string $pgRoute */ /** @var array $pgParams */ /** @var int $pgPage */
/** @var int $pgTaille */ /** @var int $pgTotal */
// Partagé par toutes les listes paginées (fiches, employés, écritures,
// factures, débiteurs, événements) — voir pagination_taille()/pagination_page()
// dans lib/helpers.php. $pgParams : filtres GET actuels à reporter dans les
// liens de page et le sélecteur de taille (sans 'page' ni 'taille' eux-mêmes).
if ($pgTotal <= 0) {
    return;
}
$nbPages = (int) ceil($pgTotal / $pgTaille);
$debut   = ($pgPage - 1) * $pgTaille + 1;
$fin     = min($pgPage * $pgTaille, $pgTotal);
$lien    = fn(int $p): string => e('?p=' . $pgRoute . '&' . http_build_query($pgParams + ['page' => $p]));
// Fenêtre de numéros affichés autour de la page courante (+ 1re/dernière),
// « … » pour les trous — mêmes règles que le pendant JS de
// _pagination_client.php (assets/app.js, lassoListeClient()).
$pagesAffichees = pagination_pages_affichees($pgPage, $nbPages);
?>
<div class="pagination">
    <form method="get" class="pagination-taille">
        <input type="hidden" name="p" value="<?= e($pgRoute) ?>">
        <?= hidden_inputs_html($pgParams) ?>
        <label>Par&nbsp;page
            <select name="taille" data-submit-on-change>
                <?php foreach (PAGINATION_TAILLES as $t): ?>
                    <option value="<?= $t ?>" <?= $pgTaille === $t ? 'selected' : '' ?>><?= $t ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </form>
    <?php if ($pgTotal > $pgTaille): ?>
    <span class="pagination-info"><?= $debut ?>–<?= $fin ?> sur <?= $pgTotal ?></span>
    <span class="pagination-nav">
        <?php if ($pgPage > 1): ?>
            <a href="<?= $lien($pgPage - 1) ?>" class="btn ghost btn-sm icon-only" title="Page précédente" aria-label="Page précédente"><?= icon('chevron-left') ?></a>
        <?php else: ?>
            <span class="btn ghost btn-sm icon-only" aria-disabled="true"><?= icon('chevron-left') ?></span>
        <?php endif; ?>
        <span class="pagination-pages">
            <?php foreach ($pagesAffichees as $p): ?>
                <?php if ($p === '…'): ?>
                    <span class="pagination-ellipsis">…</span>
                <?php elseif ($p === $pgPage): ?>
                    <span class="pagination-page on" aria-current="page"><?= $p ?></span>
                <?php else: ?>
                    <a href="<?= $lien($p) ?>" class="pagination-page"><?= $p ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </span>
        <?php if ($pgPage < $nbPages): ?>
            <a href="<?= $lien($pgPage + 1) ?>" class="btn ghost btn-sm icon-only" title="Page suivante" aria-label="Page suivante"><?= icon('chevron-right') ?></a>
        <?php else: ?>
            <span class="btn ghost btn-sm icon-only" aria-disabled="true"><?= icon('chevron-right') ?></span>
        <?php endif; ?>
    </span>
    <?php else: ?>
    <span class="pagination-info muted small"><?= $pgTotal ?> résultat<?= $pgTotal > 1 ? 's' : '' ?></span>
    <?php endif; ?>
</div>
