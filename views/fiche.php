<?php /** @var array $f */ /** @var ?string $saved */ /** @var ?string $mail */
/** @var string $emailEmploye */ /** @var string $emailExp */ /** @var array $axes */ /** @var array $ecrituresLibres */
// La préparation et le corps de la fiche vivent dans _fiche_fiche.php,
// partagés avec la vue deux colonnes (?p=fiches&vue=duo).
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
<?php if (($_GET['err'] ?? '') === 'payee'): ?><p class="err flash">Une fiche payée ne peut pas être supprimée.</p><?php endif; ?>
<?php if (isset($_GET['success'])): ?><p class="ok flash">✓ Fiche enregistrée avec succès.</p><?php endif; ?>
<?php require __DIR__ . '/_fiche_fiche.php'; ?>
</div></div>
