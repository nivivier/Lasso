<?php
// Retours de la fenêtre « Contacter » (route_structure_message). Partagés par la
// fiche structure et la page d'une campagne : l'envoi ramène à celle des deux
// d'où il est parti, et le message doit y être le même.
$msg = (string) ($_GET['msg'] ?? '');
?>
<?php if ($msg === 'envoye'): ?><p class="ok flash">Message envoyé — une entrée a été ajoutée à l'historique.</p><?php endif; ?>
<?php if ($msg === 'note'): ?><p class="ok flash">Prise de contact consignée dans l'historique de la structure.</p><?php endif; ?>
<?php if ($msg === 'brouillon'): ?><p class="ok flash">Brouillon enregistré.</p><?php endif; ?>
<?php if ($msg === 'envoi_ko'): ?><p class="err flash">L'envoi a échoué. Vérifiez la boîte d'expédition dans Paramètres → E-mails → Envois pour le booking.</p><?php endif; ?>
<?php if ($msg === 'err'): ?><p class="err flash">Message incomplet ou destinataire indisponible : rien n'a été envoyé.</p><?php endif; ?>
