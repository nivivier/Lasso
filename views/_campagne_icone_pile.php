<?php
/** @var array $projetsPastilles */
// La pile d'icônes de projet en tête d'une carte de campagne — booking et
// recherche de fonds affichent la même, et l'écrivaient chacune de leur côté.
//
// Trois au plus, la première devant : une pile de six ne montrerait plus rien
// de personne, et le reste se compte dans une pastille « +n ». aria-hidden :
// les projets sont nommés en toutes lettres juste à côté, les répéter à la
// lecture n'ajouterait rien.
$iconesPile  = array_slice($projetsPastilles, 0, 3);
$iconesReste = count($projetsPastilles) - count($iconesPile);
?>
<?php if ($iconesPile): ?>
<div class="camp-icone" aria-hidden="true">
    <?php foreach ($iconesPile as $i => $pastille): ?>
    <span class="camp-icone-item" style="z-index:<?= count($iconesPile) - $i ?>"><?= $pastille ?></span>
    <?php endforeach; ?>
    <?php if ($iconesReste > 0): ?>
    <span class="camp-icone-item"><span class="avatar-ini camp-icone-plus">+<?= (int) $iconesReste ?></span></span>
    <?php endif; ?>
</div>
<?php endif; ?>
