<?php
// UNE ligne de prestation (carte « Employés » d'une date) : l'employé, sa fiche
// de salaire, son axe, sa durée et son total — en lecture, puis en édition sous
// le crayon.
//
// Sorti de la boucle pour être rendu AUSSI tout seul : quand on lie un employé,
// la route renvoie cette ligne-là et le script l'insère, sans recharger la page
// (docs/UI.md § 5).
//
// Attendu de l'appelant : $emp, $prestations, $fichesParEmploye, $axes, $unites,
// $tauxHoraires, $uniteOpts, $tauxOpts, $axeSelect, $vRaw, $evenement, $id,
// $peutEcrireEv, $colspanMsg, $depuisQs.
$depuisQs = $depuisQs ?? '';
// Le partiel se suffit : rendu seul (ajout en arrière-plan), il n'a pas les
// fermetures que la vue prépare pour sa boucle. Il les recrée à l'identique
// plutôt que d'obliger la route à refaire le travail d'une vue.
$uniteOpts  = $uniteOpts  ?? options_unites($unites);
$tauxOpts   = $tauxOpts   ?? options_taux_horaires($tauxHoraires);
$colspanMsg = $colspanMsg ?? 4 + ($axes ? 1 : 0);
$vRaw       = $vRaw       ?? fn (string $c, $d = '') => (string) ($evenement[$c] ?? $d);
$axeSelect  = $axeSelect  ?? function (string $name, string $class, int $selected, bool $hidden = false) use ($axes): string {
    return '<select name="' . e($name) . '" class="' . e($class) . '"' . ($hidden ? ' hidden' : '') . '>'
        . preselectionner_option(options_axes($axes), $selected ? (string) $selected : '') . '</select>';
};
                $eid = (int) $emp['id'];
                $ligne = $prestations[$eid] ?? null;
                $fichesEmp = $fichesParEmploye[$eid] ?? [];
                $moisEvenement = $vRaw('date') !== '' ? $vRaw('date') : ($evenement['date'] ?? date('Y-m-d'));
                $formId = 'pf-' . $eid;
                $axeLabel = '';
                if ($ligne) {
                    foreach ($axes as $ax) {
                        if ((int) $ax['id'] === (int) ($ligne['axe_analytique_id'] ?? 0)) { $axeLabel = $ax['code'] ?: $ax['libelle']; break; }
                    }
                }
                $totalBrut = $ligne ? (float) $ligne['heures_unite'] * (float) $ligne['quantite'] * (float) $ligne['taux_horaire'] : 0;
            ?>
                <tr>
                    <td class="epf-col-serre"><?= e($emp['prenom'] . ' ' . $emp['nom']) ?></td>
                    <?php if (!$unites || !$tauxHoraires): ?>
                        <td colspan="<?= $colspanMsg ?>" class="muted small">
                            Configurez au moins une unité de temps et un taux horaire (Paramètres &gt; Employeur) pour ajouter une prestation.
                        </td>
                    <?php else:
                        $huSel = $ligne ? $ligne['heures_unite'] . '|' . $ligne['libelle'] : '';
                        $tauxSel = '';
                        if ($ligne) {
                            $match = null;
                            foreach ($tauxHoraires as $th) {
                                if ((float) $th['montant'] === (float) $ligne['taux_horaire']) { $match = (string) $th['montant']; break; }
                            }
                            $tauxSel = $match ?? 'autre';
                        }
                    ?>
                        <td class="epf-col-sm epf-col-serre">
                            <?php if ($ligne): ?>
                                <span class="epf-disp"><a href="<?= e(url_avec_retour('?p=fiche&id=' . (int) $ligne['fiche_id'], 'evenement', $id)) ?>"><?= e(mois_nom((int) $ligne['mois']) . ' ' . $ligne['annee']) ?></a></span>
                            <?php endif; ?>
                            <select form="<?= e($formId) ?>" name="fiche_id" class="fiche-select-sm epf-editable"<?= $ligne ? ' hidden' : '' ?>>
                                <option value="">— Créer une fiche (<?= e(mois_nom((int) substr($moisEvenement, 5, 2)) . ' ' . substr($moisEvenement, 0, 4)) ?>) —</option>
                                <?php foreach ($fichesEmp as $f): ?>
                                    <option value="<?= (int) $f['id'] ?>" <?= $ligne && (int) $ligne['fiche_id'] === (int) $f['id'] ? 'selected' : '' ?>><?= e(mois_nom((int) $f['mois']) . ' ' . $f['annee']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <?php if ($axes): ?>
                        <td class="epf-col-sm epf-col-serre">
                            <?php if ($ligne): ?>
                                <span class="epf-disp"><?= e($axeLabel !== '' ? $axeLabel : '—') ?></span>
                            <?php endif; ?>
                            <?= str_replace('name="l_axe"', 'form="' . e($formId) . '" name="l_axe"', $axeSelect('l_axe', 'l-axe epf-editable', (int) ($ligne['axe_analytique_id'] ?? ($evenement['axe_analytique_id_defaut'] ?? 0)), (bool) $ligne)) ?>
                        </td>
                        <?php endif; ?>
                        <td class="epf-col-sm">
                            <div class="epf-duree">
                                <?php if ($ligne): ?>
                                    <span class="epf-disp"><?= e($ligne['libelle'] . ' × ' . nombre_court((float) $ligne['quantite']) . ' — ' . chf((float) $ligne['taux_horaire']) . ' CHF/h') ?></span>
                                <?php endif; ?>
                                <select form="<?= e($formId) ?>" name="l_unite" class="l-unite epf-editable"<?= $ligne ? ' hidden' : '' ?>><?= preselectionner_option($uniteOpts, $huSel) ?></select>
                                <input form="<?= e($formId) ?>" name="l_quantite" class="l-qte epf-editable" type="text" inputmode="decimal" placeholder="qté" value="<?= $ligne ? e(nombre_court((float) $ligne['quantite'])) : '' ?>"<?= $ligne ? ' hidden' : '' ?>>
                                <select form="<?= e($formId) ?>" name="l_taux_choix" class="l-taux-choix epf-editable"<?= $ligne ? ' hidden' : '' ?>><?= preselectionner_option($tauxOpts, $tauxSel) ?></select>
                                <input form="<?= e($formId) ?>" name="l_taux_manuel" class="l-taux-manuel epf-editable" type="text" inputmode="decimal" placeholder="CHF/h" value="<?= ($ligne && $tauxSel === 'autre') ? e(nombre_court((float) $ligne['taux_horaire'])) : '' ?>"<?= $ligne ? ' hidden' : '' ?>>
                            </div>
                        </td>
                        <td class="num epf-col-serre"><span class="epf-total-live"><?= $totalBrut > 0 ? chf($totalBrut) . ' CHF' : '—' ?></span></td>
                        <td class="epf-actions-cell epf-col-serre">
                            <?php if ($peutEcrireEv): ?>
                            <form id="<?= e($formId) ?>" method="post" action="?p=evenement_ligne_ajouter<?= $depuisQs ?>">
                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="id" value="<?= (int) $id ?>">
                                <input type="hidden" name="employe_id" value="<?= $eid ?>">
                            </form>
                            <div class="epf-actions">
                                <button type="submit" form="<?= e($formId) ?>" class="btn btn-sm icon-only epf-editable" title="Enregistrer la prestation" aria-label="Enregistrer la prestation"<?= $ligne ? ' hidden' : '' ?>><?= icon('save') ?></button>
                                <button type="submit" form="<?= e($formId) ?>" formaction="?p=evenement_employe_delier<?= $depuisQs ?>" class="btn danger btn-sm icon-only epf-editable" title="Retirer l'employé" aria-label="Retirer l'employé"<?= $ligne ? ' hidden' : '' ?>><?= icon('trash') ?></button>
                                <button type="button" form="<?= e($formId) ?>" class="btn ghost btn-sm icon-only epf-edit-btn" title="Modifier" aria-label="Modifier"<?= $ligne ? '' : ' hidden' ?>><?= icon('pencil') ?></button>
                                <?php // La croix se pose exactement là où était le crayon,
                                      // tout à droite : un seul emplacement pour ouvrir
                                      // l'édition et pour la refermer. Elle n'a de sens
                                      // que sur une ligne qui a quelque chose à quitter :
                                      // une prestation déjà enregistrée. ?>
                                <?php if ($ligne): ?>
                                <button type="button" class="btn ghost btn-sm icon-only epf-editable epf-annuler-btn" title="Annuler" aria-label="Annuler" hidden><?= icon('x') ?></button>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                </tr>
