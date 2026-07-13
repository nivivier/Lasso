<?php /** @var array $f */ /** @var ?string $saved */ /** @var ?string $mail */ /** @var string $emailEmploye */ /** @var string $emailExp */ /** @var array $axes */ $paye = trim((string) $f['date_paiement']) !== ''; ?>
<?php if (($saved ?? null) === 'date'): ?><p class="ok flash">Date de paiement enregistrée.</p><?php endif; ?>
<?php if (($saved ?? null) === 'cout'): ?><p class="ok flash">Affichage du coût employeur mis à jour.</p><?php endif; ?>
<?php switch ($mail ?? null) {
    case 'ok':      echo '<p class="ok flash">Fiche envoyée par e-mail à ' . e($emailEmploye) . '.</p>'; break;
    case 'err':     echo '<p class="err flash">L\'envoi de l\'e-mail a échoué. Réessayez plus tard.</p>'; break;
    case 'no_dest': echo '<p class="err flash">Cet employé n\'a pas d\'adresse e-mail valide. Complétez sa fiche.</p>'; break;
    case 'no_exp':  echo '<p class="err flash">Aucun e-mail d\'expéditeur valide n\'est configuré (Paramètres → Employeur).</p>'; break;
} ?>
<?php if (isset($_GET['success'])): ?><p class="ok flash">✓ Fiche enregistrée avec succès.</p><?php endif; ?>
<?= lien_retour_contextuel('?p=fiches', 'Fiches de salaire') ?>
<?php
// Reporté sur les formulaires de cette page qui redirigent vers elle-même,
// pour que le lien de retour contextuel survive à un enregistrement.
$depuisQs = isset($_GET['depuis']) ? '&depuis=' . rawurlencode($_GET['depuis']) : '';
?>
<div class="page-head">
    <h1>Fiche · <?= e(mois_nom((int) $f['mois'])) ?> <?= (int) $f['annee'] ?></h1>
    <div class="head-actions">

        <?php if (!empty($modifiable)): ?>
            <a class="btn ghost btn-sm" href="?p=fiche_edit&id=<?= (int) $f['id'] ?><?= $depuisQs ?>"><?= icon('pencil') ?> <span class="lbl">Modifier</span></a>
        <?php else: ?>
            <button class="btn ghost btn-sm" disabled title="Fiche déjà payée : non modifiable"><?= icon('pencil') ?> <span class="lbl">Modifier</span></button>
        <?php endif; ?>
        <a class="btn ghost btn-sm" href="?p=fiche_print&id=<?= (int) $f['id'] ?>" data-preview target="_blank" title="Aperçu"><?= icon('eye') ?> <span class="lbl">Aperçu</span></a>
        <?php
        $envoyee = trim((string) ($f['email_envoye_le'] ?? '')) !== '';
        $peutEnvoyer = filter_var($emailEmploye, FILTER_VALIDATE_EMAIL) && filter_var($emailExp, FILTER_VALIDATE_EMAIL);
        ?>
        <?php if ($peutEnvoyer): ?>
            <form method="post" action="?p=fiche_email<?= $depuisQs ?>" class="d-inline"
                  onsubmit="return confirm('Envoyer cette fiche par e-mail à <?= e($emailEmploye) ?> ?');">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                <button type="submit" class="btn" title="Envoyer par e-mail"><?= icon('mail') ?> <span class="lbl">Envoyer</span></button>
            </form>
        <?php else: ?>
            <button class="btn" disabled
                    title="<?= !filter_var($emailEmploye, FILTER_VALIDATE_EMAIL) ? 'Aucune adresse e-mail pour cet employé' : 'Aucun e-mail d\'expéditeur configuré (Paramètres → Employeur)' ?>">
                <?= icon('mail') ?> <span class="lbl">Envoyer</span>
            </button>
        <?php endif; ?>
        <?php if ($envoyee): ?>
            <span class="mail-sent" title="Envoyée le <?= e(date('d.m.Y à H:i', strtotime((string) $f['email_envoye_le']))) ?>"><?= icon('check') ?> <span class="lbl">Envoyée</span></span>
        <?php endif; ?>
        <form method="post" action="?p=fiche_delete" onsubmit="return confirm('Supprimer définitivement cette fiche ?');" class="d-inline">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
            <button type="submit" class="btn danger icon-only" title="Supprimer" aria-label="Supprimer la fiche"><?= icon('trash') ?></button>
        </form>
    </div>
</div>

<div class="fiche-wrapper">
    <div class="fiche-main">
        <div class="card">
            <?php require __DIR__ . '/_fiche_body.php'; ?>
        </div>
    </div>
    <aside class="fiche-aside">
        <form method="post" action="?p=fiche_date<?= $depuisQs ?>" class="paiement-form">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
            <h2>Date de paiement <?= info_tip('Laissez la date vide pour marquer la fiche « à payer ».') ?></h2>
            <div class="paiement-date-row">
                <input type="date" name="date_paiement" value="<?= e($f['date_paiement']) ?>" class="paiement-date">
                <?php if (!empty($f['date_paiement'])): ?>
                    <span class="paid-check">✓</span>
                <?php endif; ?>
                <button type="submit" class="btn paiement-save" title="Enregistrer"><?= icon('save') ?><span class="lbl">Enregistrer</span></button>
            </div>

        </form>
        
                    <h2>Affichage avancé</h2>
                <form method="post" action="?p=fiche_cout<?= $depuisQs ?>" id="cout-form" class="cout-toggle">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
            <label class="check">
                <input type="checkbox" name="afficher_cout_emp" value="1"
                       onchange="document.getElementById('cout-form').submit()"
                       <?= (int) $f['afficher_cout_emp'] ? 'checked' : '' ?>>
                Coût employeur
            </label>
        </form>
    </aside>
</div>
<?php if (!empty($axes)): ?>
<script>
(function () {
    const CSRF = <?= json_encode(csrf_token()) ?>;

    // Horodatage du dernier change — sert à ignorer les ghost-clicks mobiles qui
    // surviennent juste après (le picker se ferme, DOM se met à jour, le touch
    // synthétise un click aux coordonnées initiales qui atterrit sur le crayon).
    let lastChangedAt = 0;

    document.addEventListener('focus', ev => {
        const sel = ev.target.closest('.ligne-axe-sel');
        if (sel && !sel.hidden) sel.dataset.prev = sel.value;
    }, true);

    document.addEventListener('click', ev => {
        const btn = ev.target.closest('.axe-edit-btn');
        if (!btn) return;
        if (Date.now() - lastChangedAt < 400) return; // ghost-click après change
        const cell = btn.closest('.ligne-axe-cell');
        const sel  = cell.querySelector('.ligne-axe-sel');
        const disp = cell.querySelector('.axe-disp');
        sel.dataset.prev = sel.value;
        disp.hidden = true;
        sel.hidden  = false;
        sel.focus();
    });

    document.addEventListener('change', async ev => {
        const sel = ev.target.closest('.ligne-axe-sel');
        if (!sel) return;
        lastChangedAt = Date.now();
        const cell = sel.closest('.ligne-axe-cell');
        const fd = new FormData();
        fd.append('csrf', CSRF);
        fd.append('ligne_id', cell.dataset.ligneId);
        fd.append('axe_id', sel.value || '0');
        const data = await fetch('?p=fiche_ligne_axe_save', { method: 'POST', body: fd })
            .then(r => r.json()).catch(() => ({ ok: false }));
        if (data.ok) applyState(cell, sel.value);
        else rollback(cell);
    });

    function applyState(cell, newVal) {
        const sel  = cell.querySelector('.ligne-axe-sel');
        const disp = cell.querySelector('.axe-disp');
        if (newVal) {
            const label = sel.querySelector('option[value="' + newVal + '"]')?.textContent.trim() || '';
            disp.querySelector('.axe-disp-txt').textContent = label;
            disp.hidden = false;
            sel.hidden  = true;
        } else {
            disp.hidden = true;
            sel.hidden  = false;
        }
    }

    function rollback(cell) {
        const sel  = cell.querySelector('.ligne-axe-sel');
        const disp = cell.querySelector('.axe-disp');
        sel.value = sel.dataset.prev ?? '';
        if (sel.dataset.prev) {
            disp.hidden = false;
            sel.hidden  = true;
        }
    }
})();
</script>
<?php endif; ?>
