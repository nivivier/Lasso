<?php
// Retour d'une pose ou d'un retrait de liaison en masse — tag, campagne — depuis
// la barre d'action groupée (views/_structures_bulk_bar.php). Ces deux actions
// n'ont pas d'annulation (ce ne sont pas des colonnes de `structures`, cf.
// structures_bulk_appliquer()) : le bandeau dit donc seulement ce qui a été
// fait, et sur combien de fiches.
//
// Partagé par ?p=structures et par le suivi d'une campagne, qui montrent la
// même barre.
//
// Attendu de l'appelant : $tagBulk / $tagBulkAction / $tagBulkNom et
// $campBulk / $campBulkAction / $campBulkNom — le compte (null = rien à dire),
// le sens ('ajout' | 'retrait') et le nom concerné.
/** @var ?int $tagBulk */ /** @var string $tagBulkAction */ /** @var string $tagBulkNom */
/** @var ?int $campBulk */ /** @var string $campBulkAction */ /** @var string $campBulkNom */
$campBulk = $campBulk ?? null;
$campBulkAction = $campBulkAction ?? '';
$campBulkNom = $campBulkNom ?? '';
?>
<?php if ($tagBulk !== null): ?>
<p class="ok flash">
    <?php if ($tagBulk > 0): ?>
        Tag « <?= e($tagBulkNom) ?> » <?= $tagBulkAction === 'retrait' ? 'retiré de' : 'ajouté à' ?> <strong><?= (int) $tagBulk ?></strong> structure(s).
    <?php else: ?>
        Aucune structure modifiée (tag <?= $tagBulkAction === 'retrait' ? 'déjà absent' : 'déjà présent' ?>).
    <?php endif; ?>
</p>
<?php endif; ?>
<?php if ($campBulk !== null): ?>
<p class="ok flash">
    <?php if ($campBulk > 0): ?>
        <strong><?= (int) $campBulk ?></strong> structure(s) <?= $campBulkAction === 'retrait' ? 'retirée(s) de' : 'ajoutée(s) à' ?> la campagne « <?= e($campBulkNom) ?> ».
    <?php else: ?>
        Aucune structure modifiée (<?= $campBulkAction === 'retrait' ? 'aucune n\'était dans cette campagne' : 'toutes y figuraient déjà' ?>).
    <?php endif; ?>
</p>
<?php endif; ?>
