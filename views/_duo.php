<?php
// Les deux volets de la vue « liste et fiche » (?vue=duo) — le gabarit commun
// à tous les écrans qui ont une LISTE et une FICHE : structures, factures,
// et les suivants (docs/UI.md § 9 quinquies).
//
// Il ne connaît ni les colonnes, ni les filtres, ni le contenu d'une fiche :
// l'appelant lui passe les deux moitiés déjà choisies, et ce fichier ne fait
// que les poser côte à côte. C'est ce qui permet d'ajouter un module sans
// réécrire une ligne de mise en page.
//
// Variables attendues, préfixées « duo » :
//   $duoListe (obligatoire) le HTML de la colonne de gauche — la liste de la
//             page, rendue telle quelle. Elle passe en cartes d'elle-même :
//             les règles des listes en cartes sont des requêtes de CONTENEUR
//             (@container liste), et la colonne fait 300px.
//   $duoFiche (facultatif)  le partiel de fiche à requérir à droite. Vide : la
//             liste n'a rien à montrer, on le dit plutôt que de laisser un
//             volet blanc.
//   $duoVide  (facultatif)  la phrase à afficher à droite dans ce cas.
//   $duoVolet  posé à vrai juste avant la fiche, pour qu'un partiel sache
//             qu'il est dans le volet de droite et non en pleine page. Il
//             n'en a presque jamais besoin — sauf pour le lien de retour vers
//             la liste, qui n'a plus de sens quand la liste est à côté.
$duoFiche = $duoFiche ?? '';
$duoVide  = $duoVide ?? 'Choisissez un élément dans la liste.';
$duoVolet = true;
?>
<div class="duo">
    <div class="duo-liste"><?= $duoListe ?></div>
    <div class="duo-fiche">
        <?php if ($duoFiche !== ''): ?>
            <?php require __DIR__ . '/' . $duoFiche; ?>
        <?php else: ?>
            <p class="muted"><?= e($duoVide) ?></p>
        <?php endif; ?>
    </div>
</div>
