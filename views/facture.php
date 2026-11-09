<?php
/** @var array $facture */ /** @var array $lignes */ /** @var string $statutEffectif */ /** @var ?string $saved */
/** @var array $ecrituresLibres */ /** @var array $evenementsListe */ /** @var array $axes */
// La préparation et le corps de la fiche vivent dans _facture_fiche.php,
// partagés avec la vue deux colonnes (?p=factures&vue=duo).
?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>

<div class="module-content"><div class="module-content-inner">
<?php if (($saved ?? null) === 'emise'): ?><p class="ok flash">Facture émise.</p><?php endif; ?>
<?php if (($saved ?? null) === 'payee'): ?><p class="ok flash">Facture marquée comme payée.</p><?php endif; ?>
<?php if (($saved ?? null) === 'axe'): ?><p class="ok flash">Axe analytique enregistré.</p><?php endif; ?>
<?php switch ($_GET['mail'] ?? null) {
    case 'ok':  echo '<p class="ok flash">Facture envoyée par e-mail.</p>'; break;
    case 'err': echo '<p class="err flash">L\'envoi de l\'e-mail a échoué. Réessayez plus tard.</p>'; break;
} ?>
<?php switch ($_GET['err'] ?? null) {
    case 'compte':   echo '<p class="err flash">Choisissez un compte bancaire créancier avant d\'émettre.</p>'; break;
    case 'lignes':   echo '<p class="err flash">La facture doit avoir au moins une ligne.</p>'; break;
    case 'emission': echo '<p class="err flash">L\'émission a échoué (numéro ou référence de paiement invalide). Réessayez.</p>'; break;
    case 'pdf':      echo '<p class="err flash">La génération du PDF a échoué (vérifiez l\'IBAN et l\'adresse de la structure).</p>'; break;
} ?>
<?= lien_retour_contextuel('?p=factures', 'Facturation') ?>
<?php require __DIR__ . '/_facture_fiche.php'; ?>
</div></div>
