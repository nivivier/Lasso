<?php
/** @var int $pgTaille */ /** @var int $pgTotal */
// Variante 100% JS de _pagination.php, pour les listes en mode "client" (voir
// pagination_mode_client() dans lib/helpers.php) : toutes les lignes sont déjà
// dans le DOM, lassoListeClient() (assets/app.js) prend le relais dès le
// chargement — les valeurs affichées ici (info/page) ne sont qu'un repli avant
// que le JS ne s'exécute, il les recalcule immédiatement à l'identique.
if ($pgTotal <= 0) {
    return;
}
?>
<div class="pagination" data-pg-client data-pg-taille-defaut="<?= (int) $pgTaille ?>">
    <div class="pagination-taille">
        <label>Par&nbsp;page
            <select data-pg-taille>
                <?php foreach (PAGINATION_TAILLES as $t): ?>
                    <option value="<?= $t ?>" <?= $pgTaille === $t ? 'selected' : '' ?>><?= $t ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>
    <span class="pagination-info" data-pg-info></span>
    <span class="pagination-nav" data-pg-nav>
        <button type="button" class="btn ghost btn-sm icon-only" data-pg-prev title="Page précédente" aria-label="Page précédente"><?= icon('chevron-left') ?></button>
        <span class="muted small nowrap" data-pg-page></span>
        <button type="button" class="btn ghost btn-sm icon-only" data-pg-next title="Page suivante" aria-label="Page suivante"><?= icon('chevron-right') ?></button>
    </span>
</div>
