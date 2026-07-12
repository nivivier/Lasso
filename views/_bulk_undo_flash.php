<?php /** @var ?int $bulkCount */ /** @var bool $okAnnule */ /** @var string $actionUrl */ ?>
<?php if ($okAnnule): ?><p class="warn flash">Modification annulée.</p><?php endif; ?>
<?php if ($bulkCount): ?>
<div class="bulk-undo-flash" id="bulk-undo-flash">
    <span><?= icon('check') ?> <?= (int) $bulkCount ?> ligne(s) modifiée(s).</span>
    <form method="post" action="<?= e($actionUrl) ?>" class="d-inline">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="section" value="bulk_undo">
        <button type="submit" class="link-btn">Annuler <span class="muted small">(Ctrl+Z)</span></button>
    </form>
</div>
<?php endif; ?>
