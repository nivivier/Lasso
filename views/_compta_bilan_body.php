<?php
/** @var int $annee */ /** @var array $cols */ /** @var array $sommesParAnnee */
/** @var array $totauxParAnnee */ /** @var array $plan */ /** @var array $patrimoine */
// Corps du bilan et du compte de résultat, réutilisé à l'écran et à
// l'impression — même mécanique que _fiche_body.php et _certificat_body.php.
//
// Les deux vues portaient chacune leur copie de l'arbre du plan comptable :
// 67 % des lignes utiles de la version imprimée se retrouvaient à l'identique
// dans celle de l'écran. Deux copies d'un calcul de sous-totaux, c'est deux
// chances de ne plus dire la même chose — et l'écart ne se verrait qu'en
// comparant une feuille imprimée à l'écran dont elle sort.
//
// $impression (posé par l'appelant, faux par défaut) sépare ce qui DOIT
// différer, et rien d'autre :
//   — une feuille ne se déplie pas : pas de ligne cliquable, pas de détail ;
//   — une feuille ne défile pas : pas de .table-scroll ;
//   — une feuille n'a pas d'années « précédentes » grisées : pas de .col-prec,
//     puisque le papier ne distingue pas par la couleur ;
//   — l'indentation est plus serrée (18 px par niveau contre 22).
/** @var bool $impression */
$impression = $impression ?? false;

$byParent = plan_enfants($plan);
$nbCols   = count($cols);
$lignesParCat = $lignesParCat ?? [];

// Classe d'une cellule de montant : l'écran grise les années de comparaison.
$colCls = fn (int $a): string => (!$impression && $a !== $annee) ? ' col-prec' : '';

$blocSens = function (string $sens, string $titre) use (
    $byParent, $sommesParAnnee, $lignesParCat, $cols, $nbCols, $annee, $impression, $colCls
): string {
    $classePad = $impression ? 'cr-noeud-pad' : 'bilan-noeud-pad';
    $pad = fn (int $p) => 'class="' . $classePad . '" style="--depth:' . $p . '"';
    $cellules = function (callable $val) use ($cols, $colCls): string {
        $h = '';
        foreach ($cols as $a) {
            $h .= '<td class="num' . $colCls((int) $a) . '">' . chf($val((int) $a)) . '</td>';
        }
        return $h;
    };
    $rendre = function (array $row, int $prof) use (
        &$rendre, $byParent, $sommesParAnnee, $lignesParCat, $pad, $cellules, $nbCols, $annee, $impression
    ): string {
        $id = (int) $row['id'];
        $enfants = $byParent[$id] ?? [];
        if ($enfants) {
            // Rendre les enfants d'abord ; si tous masqués, masquer le groupe.
            $childHtml = '';
            foreach ($enfants as $child) {
                $childHtml .= $rendre($child, $prof + 1);
            }
            if ($childHtml === '') {
                return '';
            }
            return '<tr class="cr-groupe"><td ' . $pad($prof) . '>' . e($row['libelle']) . '</td>'
                 . $cellules(fn (int $a) => plan_sous_total($id, $byParent, $sommesParAnnee[$a] ?? [])) . '</tr>'
                 . $childHtml;
        }
        // Feuille inactive sans montant dans l'année sélectionnée → masquée.
        if (!(int) ($row['actif'] ?? 1) && empty($sommesParAnnee[$annee][$id])) {
            return '';
        }
        $montants = $cellules(fn (int $a) => (float) ($sommesParAnnee[$a][$id] ?? 0));
        if ($impression) {
            return '<tr class="cr-compte"><td ' . $pad($prof) . '>' . e($row['libelle']) . '</td>' . $montants . '</tr>';
        }
        // À l'écran, la feuille s'ouvre sur ses écritures de l'année affichée.
        $lignes = $lignesParCat[$id] ?? [];
        $h = '<tr class="cr-compte cr-clic" data-cat="' . $id . '" tabindex="0" role="button" aria-expanded="false">'
           . '<td ' . $pad($prof) . '><span class="cr-toggle" aria-hidden="true">' . icon('chevron') . '</span>'
           . e($row['libelle']) . ' <span class="cr-count">' . count($lignes) . '</span></td>'
           . $montants . '</tr>';
        $det = '';
        foreach ($lignes as $l) {
            $neg = (float) $l['montant'] < 0;
            $det .= '<div class="cr-det-row">'
                 . '<span class="dt">' . e(date('d.m.Y', strtotime((string) $l['date_op']))) . '</span>'
                 . '<span class="cpt">' . e($l['compte']) . '</span>'
                 . '<span class="tx" title="' . e($l['texte']) . '">' . e($l['texte']) . '</span>'
                 . '<span class="mt ' . ($neg ? 'montant-neg' : 'montant-pos') . '">' . chf((float) $l['montant']) . '</span>'
                 . '</div>';
        }
        $libDet = $nbCols > 1 ? '<div class="muted small mb-0">Détail ' . (int) $annee . ' :</div>' : '';
        return $h . '<tr class="cr-detail" data-cat="' . $id . '" hidden><td colspan="' . ($nbCols + 1) . '">'
             . $libDet . $det . '</td></tr>';
    };
    $racines = array_filter($byParent[0] ?? [], fn ($r) => $r['sens'] === $sens);
    $h = '<tr class="cr-section"><th colspan="' . ($nbCols + 1) . '">' . e($titre) . '</th></tr>';
    if (!$racines && !$impression) {
        $h .= '<tr><td colspan="' . ($nbCols + 1) . '" class="muted small">Aucune catégorie.</td></tr>';
    }
    foreach ($racines as $r) {
        $h .= $rendre($r, 0);
    }
    return $h;
};

// Une feuille ne défile pas : à l'impression, le conteneur disparaît.
$ouvrirTable = fn (): string => $impression ? '' : '<div class="table-scroll">';
$fermerTable = fn (): string => $impression ? '' : '</div>';
// Le tiret d'une valeur absente : grisé à l'écran, nu sur le papier.
$vide = $impression ? '—' : '<span class="muted">—</span>';
// L'écran aère ses sections d'une rangée vide ; la feuille les enchaîne.
$espaceur = $impression ? '' : '<tr class="cr-spacer"><td colspan="' . ($nbCols + 1) . '"></td></tr>';
?>
<h2>État du patrimoine</h2>
<?= $ouvrirTable() ?>
<table class="list">
    <thead>
        <tr><th>Poste</th><?php foreach ($cols as $a): ?><th class="num<?= $colCls((int) $a) ?>">au 31.12.<?= (int) $a ?></th><?php endforeach; ?></tr>
    </thead>
    <tbody>
        <?php foreach ($patrimoine as $p): ?>
        <tr>
            <td><?= e($p['libelle']) ?></td>
            <?php foreach ($cols as $a): $v = $p['valeurs'][$a] ?? null; ?>
                <td class="num<?= $colCls((int) $a) ?>"><?= $v === null ? $vide : chf((float) $v) ?></td>
            <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
        <tr class="cr-total">
            <td>Total du patrimoine</td>
            <?php foreach ($cols as $a): ?><td class="num<?= $colCls((int) $a) ?>"><?= chf(compta_total_patrimoine((int) $a, $patrimoine)) ?></td><?php endforeach; ?>
        </tr>
    </tbody>
</table>
<?= $fermerTable() ?>

<h2>Compte de résultat<?= $nbCols > 1 ? '' : ' ' . (int) $annee ?></h2>
<?php // L'avertissement des écritures non lettrées n'a de sens qu'à l'écran :
      // il porte un lien pour aller les lettrer. ?>
<?php if (!$impression && ($resultat['non_lettrees']['nb'] ?? 0) > 0): ?>
<p class="err"><?= (int) $resultat['non_lettrees']['nb'] ?> écriture(s) de <?= (int) $annee ?> non lettrée(s)
(<?= chf($resultat['non_lettrees']['montant']) ?> CHF) ne sont pas prises en compte.
<a href="?p=compta_ecritures&amp;annee=<?= (int) $annee ?>&amp;categorie=a_lettrer">Les lettrer</a>.</p>
<?php endif; ?>
<?= $ouvrirTable() ?>
<table class="list compta-cr">
    <?php if ($nbCols > 1): ?>
    <thead><tr><th>Catégorie</th><?php foreach ($cols as $a): ?><th class="num<?= $colCls((int) $a) ?>"><?= (int) $a ?></th><?php endforeach; ?></tr></thead>
    <?php endif; ?>
    <tbody>
        <?= $blocSens('produit', 'Recettes') ?>
        <?= compta_ligne_total('Total des recettes', 'produits', 'cr-total', $cols, $totauxParAnnee) ?>
        <?= $espaceur ?>
        <?= $blocSens('charge', 'Dépenses') ?>
        <?= compta_ligne_total('Total des dépenses', 'charges', 'cr-total', $cols, $totauxParAnnee) ?>
        <?= $espaceur ?>
        <?= compta_ligne_total('Résultat de l\'année', 'resultat', 'cr-resultat', $cols, $totauxParAnnee) ?>
    </tbody>
</table>
<?= $fermerTable() ?>
