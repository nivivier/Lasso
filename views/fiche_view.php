<?php /** @var array $f */ /** @var ?string $saved */ /** @var ?string $mail */ /** @var string $emailEmploye */ /** @var string $emailExp */ /** @var array $axes */ /** @var array $ecrituresLibres */ $paye = trim((string) $f['date_paiement']) !== ''; ?>
<?php
// Rapprochement bancaire : l'écriture qui a payé cette fiche. Même dispositif
// que sur une facture (facturation_voir.php) — au signe près, un salaire sort
// du compte. Le libellé reprend la contre-partie quand le relevé la donne :
// sur un virement de salaire, c'est le nom de l'employé, donc le plus parlant.
$libelleEcr = function (array $e): string {
    $tiers = trim((string) ($e['tiers'] ?? ''));
    return date('d.m.Y', strtotime((string) $e['date_op'])) . ' — ' . chf((float) $e['montant']) . ' CHF'
        . ($tiers !== '' ? ' — ' . $tiers : ' — ' . mb_substr((string) $e['texte'], 0, 50));
};
$ecrActuelleId = (int) ($f['ecriture_id'] ?? 0);
$ecrActuelle   = array_values(array_filter($ecrituresLibres, fn ($e) => (int) $e['id'] === $ecrActuelleId));
$ecrActuelleLabel = $ecrActuelle ? $libelleEcr($ecrActuelle[0]) : '';
?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>

<div class="module-content"><div class="module-content-inner">
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

        <?php if (peut_ecrire('salaires')): ?>
        <?php if (!empty($modifiable)): ?>
            <a class="btn ghost" href="?p=fiche_edit&id=<?= (int) $f['id'] ?><?= $depuisQs ?>"><?= icon('pencil') ?> <span class="lbl">Modifier</span></a>
        <?php else: ?>
            <button class="btn ghost" disabled title="Fiche déjà payée : non modifiable"><?= icon('pencil') ?> <span class="lbl">Modifier</span></button>
        <?php endif; ?>
        <?php endif; ?>
        <a class="btn ghost" href="?p=fiche_print&id=<?= (int) $f['id'] ?>" data-preview target="_blank" title="Aperçu"><?= icon('eye') ?> <span class="lbl">Aperçu</span></a>
        <?php
        $envoyee = trim((string) ($f['email_envoye_le'] ?? '')) !== '';
        $peutEnvoyer = filter_var($emailEmploye, FILTER_VALIDATE_EMAIL) && filter_var($emailExp, FILTER_VALIDATE_EMAIL);
        ?>
        <?php if (peut_ecrire('salaires')): ?>
        <?php if ($peutEnvoyer): ?>
            <form method="post" action="?p=fiche_email<?= $depuisQs ?>" class="d-inline"
                  data-confirm="Envoyer cette fiche par e-mail à <?= e($emailEmploye) ?> ?">
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
        <?php endif; ?>
        <?php if ($envoyee): ?>
            <span class="mail-sent" title="Envoyée le <?= e(date('d.m.Y à H:i', strtotime((string) $f['email_envoye_le']))) ?>"><?= icon('check') ?> <span class="lbl">Envoyée</span></span>
        <?php endif; ?>
        <?php if (peut_ecrire('salaires')): ?>
        <form method="post" action="?p=fiche_delete" data-confirm="Supprimer définitivement cette fiche ?" class="d-inline">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
            <button type="submit" class="btn danger icon-only" title="Supprimer" aria-label="Supprimer la fiche"><?= icon('trash') ?></button>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="fiche-wrapper">
    <div class="fiche-main">
        <div class="card">
            <?php require __DIR__ . '/_fiche_body.php'; ?>
        </div>
    </div>
    <aside class="fiche-aside">
        <?php if (peut_ecrire('salaires')): ?>
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

            <?php if ($ecrituresLibres): ?>
            <div class="ecr-liee-box">
                <h3 class="sub no-mt">Écriture liée</h3>
                <div class="cat-search ecr-search">
                    <input type="text" class="cat-search-input" id="ecriture-recherche" autocomplete="off"
                           placeholder="— aucune —" value="<?= e($ecrActuelleLabel) ?>">
                    <input type="hidden" name="ecriture_id" id="ecriture-select" value="<?= $ecrActuelleId ?: '' ?>">
                    <ul class="cat-search-list" hidden role="listbox">
                        <li data-val="">— aucune —</li>
                        <?php foreach ($ecrituresLibres as $e):
                            $libelle = $libelleEcr($e);
                        ?>
                            <li data-val="<?= (int) $e['id'] ?>"
                                data-montant="<?= (float) $e['montant'] ?>"
                                data-date="<?= e($e['date_op']) ?>"
                                data-label="<?= e($libelle) ?>"
                                data-recherche="<?= e(mb_strtolower($libelle . ' ' . (string) $e['texte'], 'UTF-8')) ?>">
                                <span class="ecr-opt-top"><?= e($libelle) ?></span>
                                <span class="ecr-opt-texte"><?= e(mb_substr((string) $e['texte'], 0, 90)) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <label class="check small">
                    <input type="checkbox" id="ecriture-meme-montant" checked>
                    Montant exact (<?= chf((float) $f['salaire_net']) ?> CHF)
                </label>
                <p class="muted small" id="ecriture-compte"></p>
            </div>
            <?php endif; ?>
        </form>

                    <h2>Affichage avancé</h2>
                <form method="post" action="?p=fiche_cout<?= $depuisQs ?>" id="cout-form" class="cout-toggle">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
            <label class="check">
                <input type="checkbox" name="afficher_cout_emp" value="1"
                       data-submit-form="cout-form"
                       <?= (int) $f['afficher_cout_emp'] ? 'checked' : '' ?>>
                Coût employeur
            </label>
        </form>
        <?php else: ?>
        <h2>Date de paiement</h2>
        <p><?= !empty($f['date_paiement']) ? e(date('d.m.Y', strtotime((string) $f['date_paiement']))) : '<span class="muted">À payer</span>' ?></p>
        <?php if ($ecrActuelleLabel !== ''): ?>
            <h3 class="sub">Écriture liée</h3>
            <p class="muted small"><?= e($ecrActuelleLabel) ?></p>
        <?php endif; ?>
        <?php endif; ?>
    </aside>
</div>
<?php if (!empty($axes)): ?>
<script nonce="<?= e(csp_nonce()) ?>">
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
<?php if ($ecrituresLibres && peut_ecrire('salaires')): ?>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    // Composant partagé avec la facture (lassoInitRechercheEcriture, app.js) :
    // c'est le même rapprochement bancaire des deux côtés.
    lassoInitRechercheEcriture(document.querySelector('.ecr-search'), {
        hidden:      document.getElementById('ecriture-select'),
        memeMontant: document.getElementById('ecriture-meme-montant'),
        compteur:    document.getElementById('ecriture-compte'),
        dateCible:   document.querySelector('.paiement-date'),
        montant:     <?= json_encode(round((float) $f['salaire_net'], 2)) ?>,
    });
})();
</script>
<?php endif; ?>
</div></div>
