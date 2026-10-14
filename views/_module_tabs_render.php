<?php
// Rendu de la rangée d'onglets — suppose _module_tabs.php déjà requis dans
// le même scope (pour $ntCle/$ntOnglets). Séparé du calcul pour pouvoir
// placer le <h1> (dans .page-head-title) et cette rangée (enfant direct de
// .page-head, pleine largeur) à deux endroits différents du DOM à partir
// d'une seule résolution de groupe.
?>
<?php // Une rangée d'UN SEUL onglet ne mène nulle part : elle répète le nom du
      // module écrit à deux centimètres à gauche, et occupe la place d'une
      // navigation qui n'existe pas. On ne la rend qu'à partir de deux. La
      // pastille que portait cet onglet unique reste lisible dans le rail, qui
      // porte la même. ?>
<?php if (count($ntOnglets) > 1): ?>
<nav class="module-tabs">
    <?php foreach ($ntOnglets as $ntRoute => $ntOnglet): ?>
        <?php [$ntLib, $ntRoutesMatch, $ntBadge, $ntIcon] = $ntOnglet; ?>
        <?php $ntHref = '?p=' . $ntRoute . '&depuis=' . $ntCle; ?>
        <a href="<?= e($ntHref) ?>" class="module-tab <?= in_array((string) ($_GET['p'] ?? ''), $ntRoutesMatch, true) ? 'on' : '' ?>">
            <?= icon($ntIcon) ?> <?= e($ntLib) ?>
            <?php if ($ntBadge > 0): ?><span class="nav-badge"><?= $ntBadge ?></span><?php endif; ?>
        </a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>
