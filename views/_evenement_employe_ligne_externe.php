<?php
// UNE ligne d'employé lié, en PRODUCTION EXTERNE : pas de prestation ni de fiche
// de salaire à gérer ici, le cachet est l'affaire de l'organisateur. Juste le
// nom et de quoi le retirer.
//
// Rendu aussi tout seul, quand on lie un employé sans recharger la page
// (docs/UI.md § 5). Attendu : $emp, $id, $peutEcrireEv, $depuisQs.
$depuisQs = $depuisQs ?? '';
?>
<tr>
                    <td><?= e($emp['prenom'] . ' ' . $emp['nom']) ?></td>
                    <td class="epf-actions-cell">
                        <?php if ($peutEcrireEv): ?>
                        <form method="post" action="?p=evenement_employe_delier<?= $depuisQs ?>" data-confirm="Retirer cet employé de l'événement ?">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="id" value="<?= (int) $id ?>">
                            <input type="hidden" name="employe_id" value="<?= (int) $emp['id'] ?>">
                            <button type="submit" class="btn danger btn-sm icon-only" title="Retirer l'employé" aria-label="Retirer l'employé"><?= icon('trash') ?></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
