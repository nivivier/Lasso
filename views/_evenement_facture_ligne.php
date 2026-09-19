<?php
// UNE facture liée à une date, telle que la carte « Factures liées » l'affiche.
//
// Sorti de la boucle pour être rendu AUSSI tout seul : quand on lie une facture,
// la route renvoie cette ligne-là et le script l'insère, sans recharger la page
// (docs/UI.md § 5).
//
// Attendu de l'appelant : $fa (la facture), $id (l'événement), $peutEcrireEv,
// $depuisQs.
$depuisQs = $depuisQs ?? '';
?>
<tr>
                    <td><a href="<?= e(url_avec_retour('?p=facture&id=' . (int) $fa['id'], 'evenement', $id)) ?>"><?= $fa['numero'] !== '' ? e($fa['numero']) : '<span class="muted">(brouillon)</span>' ?></a></td>
                    <td><?= e($fa['structure_nom']) ?></td>
                    <td class="num strong"><?= chf((float) $fa['montant_total']) ?></td>
                    <td><?= facturation_badge($fa) ?></td>
                    <td>
                        <?php if ($peutEcrireEv): ?>
                        <form method="post" action="?p=evenement_facture_delier<?= $depuisQs ?>" data-confirm="Délier cette facture de l'événement ?">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="id" value="<?= (int) $id ?>">
                            <input type="hidden" name="facture_id" value="<?= (int) $fa['id'] ?>">
                            <button type="submit" class="btn ghost btn-sm" title="Délier" aria-label="Délier"><?= icon('x') ?></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
