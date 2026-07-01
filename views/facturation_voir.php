<?php
/** @var array $facture */ /** @var array $lignes */ /** @var string $statutEffectif */ /** @var ?string $saved */
$f = $facture;
$brouillon = $f['statut'] === 'brouillon';
$peutAnnuler = !in_array($f['statut'], ['payee', 'annulee'], true);
$peutEmail = !$brouillon && filter_var($f['debiteur_email'] ?? '', FILTER_VALIDATE_EMAIL);
$aDesAxes = (bool) array_filter($lignes, fn($l) => $l['axe_analytique_id']);
?>
<?php if (($saved ?? null) === 'emise'): ?><p class="ok flash">Facture émise.</p><?php endif; ?>
<?php switch ($_GET['mail'] ?? null) {
    case 'ok':  echo '<p class="ok flash">Facture envoyée par e-mail.</p>'; break;
    case 'err': echo '<p class="err flash">L\'envoi de l\'e-mail a échoué. Réessayez plus tard.</p>'; break;
} ?>
<?php switch ($_GET['err'] ?? null) {
    case 'compte':   echo '<p class="err flash">Choisissez un compte bancaire créancier avant d\'émettre.</p>'; break;
    case 'lignes':   echo '<p class="err flash">La facture doit avoir au moins une ligne.</p>'; break;
    case 'emission': echo '<p class="err flash">L\'émission a échoué (numéro ou référence de paiement invalide). Réessayez.</p>'; break;
    case 'pdf':      echo '<p class="err flash">La génération du PDF a échoué (vérifiez l\'IBAN et l\'adresse du débiteur).</p>'; break;
} ?>
<?= lien_retour('?p=facturation_liste', 'Facturation') ?>
<div class="page-head">
    <h1>Facture <?= $f['numero'] !== '' ? e($f['numero']) : '(brouillon)' ?></h1>
    <div class="head-actions">
        <?php if ($brouillon): ?>
            <a class="btn ghost" href="?p=facturation_form&id=<?= (int) $f['id'] ?>"><?= icon('pencil') ?> <span class="lbl">Modifier</span></a>
            <form method="post" action="?p=facture_emettre" class="d-inline" onsubmit="return confirm('Émettre cette facture ? Le numéro et la référence de paiement seront figés.');">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                <button type="submit" class="btn"><?= icon('check') ?> <span class="lbl">Émettre</span></button>
            </form>
            <form method="post" action="?p=facture_delete" class="d-inline" onsubmit="return confirm('Supprimer ce brouillon ?');">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                <button type="submit" class="btn danger icon-only" title="Supprimer" aria-label="Supprimer"><?= icon('trash') ?></button>
            </form>
        <?php else: ?>
            <a class="btn ghost" href="?p=facture_pdf&id=<?= (int) $f['id'] ?>" data-preview target="_blank" title="Aperçu / PDF"><?= icon('eye') ?> <span class="lbl">PDF</span></a>
            <?php if ($statutEffectif === 'en_retard'): ?>
                <a class="btn ghost" href="?p=facture_rappel&id=<?= (int) $f['id'] ?>" data-preview target="_blank"><?= icon('mail') ?> <span class="lbl">Lettre de rappel</span></a>
            <?php endif; ?>
            <?php if ($peutEmail): ?>
                <form method="post" action="?p=facture_email" class="d-inline" onsubmit="return confirm('Envoyer cette facture par e-mail à <?= e($f['debiteur_email']) ?> ?');">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                    <button type="submit" class="btn" title="Envoyer par e-mail"><?= icon('mail') ?> <span class="lbl">Envoyer</span></button>
                </form>
            <?php endif; ?>
            <?php if ($peutAnnuler): ?>
                <form method="post" action="?p=facture_annuler" class="d-inline" onsubmit="return confirm('Annuler cette facture ?');">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                    <button type="submit" class="btn ghost">Annuler</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <p><?= facturation_badge($f) ?>
        <?php if (trim((string) ($f['envoyee_le'] ?? '')) !== ''): ?>
            <span class="mail-sent" title="Envoyée le <?= e(date('d.m.Y à H:i', strtotime($f['envoyee_le']))) ?>"><?= icon('check') ?> Envoyée</span>
        <?php endif; ?>
    </p>
    <div class="grid2">
        <div>
            <h3 class="sub">Débiteur</h3>
            <p><?= e($f['debiteur_nom']) ?><br>
                <?= e($f['adresse_rue']) ?><?= $f['adresse_rue'] ? '<br>' : '' ?>
                <?= e(trim($f['adresse_npa'] . ' ' . $f['adresse_localite'])) ?></p>
        </div>
        <div>
            <h3 class="sub">Détails</h3>
            <p>
                Émission : <?= $f['date_emission'] !== '' ? e(date('d.m.Y', strtotime($f['date_emission']))) : '—' ?><br>
                Échéance : <?= $f['date_echeance'] !== '' ? e(date('d.m.Y', strtotime($f['date_echeance']))) : '—' ?><br>
                Compte : <?= e($f['compte_libelle'] ?? '—') ?><br>
                <?php if ($f['reference_paiement'] !== ''): ?>Référence : <?= e($f['reference_paiement']) ?><br><?php endif; ?>
            </p>
        </div>
    </div>

    <?php if (trim((string) $f['communication']) !== ''): ?>
        <p class="muted small"><?= nl2br(e($f['communication'])) ?></p>
    <?php endif; ?>

    <table class="list">
        <thead><tr><th>Description</th><th class="num">Qté</th><th class="num">Prix unit.</th><th class="num">Montant</th><?php if ($aDesAxes): ?><th>Axe</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($lignes as $l): ?>
            <tr>
                <td><?= e($l['description']) ?></td>
                <td class="num"><?= nombre_court((float) $l['quantite']) ?></td>
                <td class="num"><?= chf((float) $l['prix_unitaire']) ?></td>
                <td class="num"><?= chf((float) $l['montant']) ?></td>
                <?php if ($aDesAxes): ?>
                    <td class="muted small"><?= e($l['axe_code'] ?: $l['axe_libelle'] ?: '—') ?></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="3" class="num strong">Total</td><td class="num strong"><?= chf((float) $f['montant_total']) ?></td><?php if ($aDesAxes): ?><td></td><?php endif; ?></tr></tfoot>
    </table>
</div>
