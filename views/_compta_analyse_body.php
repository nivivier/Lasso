<?php
/** @var array $ventilation */ /** @var int $annee */
// Tableau de la ventilation par axe, réutilisé à l'écran et à l'impression —
// même mécanique que _fiche_body.php et les deux autres corps de la
// comptabilité. L'en-tête, la boucle et la ligne de total étaient recopiés.
//
// $impression ne sépare que ce qui doit l'être : une feuille ne se clique pas
// (donc ni .row-link ni lien vers le détail d'un axe), n'a pas besoin des
// couleurs de montant, ne défile pas, et ne porte pas la rangée « écritures
// non ventilées » — celle-ci est un chemin vers l'écran des écritures.
/** @var bool $impression */
$impression = $impression ?? false;
$nonVentile = $nonVentile ?? null;

// Le tiret d'un montant nul : grisé à l'écran, nu sur le papier.
$vide = $impression ? '—' : '<span class="muted">—</span>';
// Classe de couleur d'un montant — l'impression est en noir.
$cls = fn (float $v, string $classe): string => (!$impression && $v != 0 && $classe !== '') ? ' ' . $classe : '';
$totProd = 0.0;
$totChg  = 0.0;
?>
<?php if (!$impression): ?><div class="table-scroll"><?php endif; ?>
<table class="list compta-cr cr-flat<?= $impression ? '' : ' cartes-chiffres cartes-analyse' ?>">
    <thead>
        <tr>
            <th>Axe</th>
            <th>Code</th>
            <th class="num">Recettes</th>
            <th class="num">Dépenses</th>
            <th class="num">Résultat</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($ventilation as $v):
        $res      = $v['resultat'];
        $totProd += $v['produits'];
        $totChg  += $v['charges'];
    ?>
        <tr class="cr-groupe<?= $impression ? '' : ' cr-clic row-link' ?>"<?= $impression ? '' : ' data-href="?p=compta_analyse_axe&axe=' . (int) $v['id'] . '&annee=' . (int) $annee . '"' ?>>
            <td class="col-axe">
                <?= e($v['libelle']) ?>
                <?php if (!$impression && !$v['actif']): ?><span class="badge muted-badge">inactif</span><?php endif; ?>
            </td>
            <td class="col-code muted<?= $impression ? '' : ' small' ?>"><?= $v['code'] ? e($v['code']) : '' ?></td>
            <td data-libelle="Recettes" class="col-recettes num<?= $cls((float) $v['produits'], $v['produits'] > 0 ? 'montant-pos' : '') ?>"><?= $v['produits'] != 0 ? chf($v['produits']) : $vide ?></td>
            <td data-libelle="Dépenses" class="col-depenses num<?= $cls((float) $v['charges'], $v['charges'] < 0 ? 'montant-neg' : '') ?>"><?= $v['charges'] != 0 ? chf($v['charges']) : $vide ?></td>
            <td data-libelle="Résultat" class="col-resultat num<?= $impression ? '' : ' strong' ?><?= $cls((float) $res, $res > 0 ? 'montant-pos' : ($res < 0 ? 'montant-neg' : '')) ?>"><?= $res != 0 ? chf($res) : $vide ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr class="cr-total">
            <td class="col-axe">Total ventilé</td>
            <td class="col-code"></td>
            <td data-libelle="Recettes" class="col-recettes num"><?= chf($totProd) ?></td>
            <td data-libelle="Dépenses" class="col-depenses num"><?= chf($totChg) ?></td>
            <td data-libelle="Résultat" class="col-resultat num"><?= chf($totProd + $totChg) ?></td>
        </tr>
        <?php // Ce qui n'est ventilé nulle part : une rangée qui MÈNE aux
              // écritures concernées, donc sans objet sur une feuille. ?>
        <?php if (!$impression && $nonVentile !== null): $nvRes = $nonVentile['resultat']; ?>
        <tr class="cr-note row-link" data-href="?p=compta_ecritures&annee=<?= (int) $annee ?>&axe=sans_axe">
            <td class="col-axe">Écritures non ventilées</td>
            <td class="col-code"></td>
            <td data-libelle="Recettes" class="col-recettes num <?= $nonVentile['produits'] > 0 ? 'montant-pos' : '' ?>"><?= $nonVentile['produits'] != 0 ? chf($nonVentile['produits']) : $vide ?></td>
            <td data-libelle="Dépenses" class="col-depenses num <?= $nonVentile['charges'] < 0 ? 'montant-neg' : '' ?>"><?= $nonVentile['charges'] != 0 ? chf($nonVentile['charges']) : $vide ?></td>
            <td data-libelle="Résultat" class="col-resultat num <?= $nvRes > 0 ? 'montant-pos' : ($nvRes < 0 ? 'montant-neg' : '') ?>"><?= $nvRes != 0 ? chf($nvRes) : $vide ?></td>
        </tr>
        <?php endif; ?>
    </tfoot>
</table>
<?php if (!$impression): ?></div><?php endif; ?>
