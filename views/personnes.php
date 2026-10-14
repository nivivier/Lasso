<?php /** @var array $personnes */ /** @var string $recherche */ /** @var int $total */
/** @var string $pgRoute */ /** @var array $pgParams */ /** @var int $pgPage */ /** @var int $pgTaille */ /** @var int $pgTotal */
?>
<?php // L'écran appartient au Carnet d'adresses depuis qu'il existe : bandeau
      // du module et onglets, comme ses voisins Structures et Employés. Il
      // vivait dans les Paramètres faute d'un module qui pût le porter.
      //
      // Pas de titre de page : l'onglet « Personnes » du bandeau, à deux
      // centimètres au-dessus, dit déjà où l'on est (docs/UI.md § 9 ter).
      // Pas de bouton « Doublons probables » non plus — le dédoublonnage est
      // un geste de reprise de données, il vit dans ?p=dev avec ses pareils. ?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>

<div class="module-content"><div class="module-content-inner">

    <div class="toolbar">
        <form method="get" class="filters">
            <input type="hidden" name="p" value="personnes">
            <?= champ_recherche(['id' => 'personnes-search', 'name' => 'q', 'valeur' => $recherche, 'submit' => true]) ?>
        </form>
        <span class="muted small"><?= $total ?> personne<?= $total > 1 ? 's' : '' ?> dans l'annuaire</span>
    </div>

    <div class="card form table-scroll">
    <table class="list">
        <thead><tr>
            <th>Nom</th><th>Adresse électronique</th><th>Téléphone</th><th>Rattachements</th>
        </tr></thead>
        <tbody>
        <?php if (!$personnes): ?>
            <tr><td colspan="4" class="muted"><?php if ($recherche !== ''): ?>Personne ne correspond à « <?= e($recherche) ?> ».<?php else: ?>L'annuaire est vide.<?php endif; ?></td></tr>
        <?php endif; ?>
        <?php foreach ($personnes as $pp): $pid = (int) $pp['id']; $href = '?p=personne&id=' . $pid; ?>
            <tr class="row-link" tabindex="0" role="link" data-href="<?= e($href) ?>">
                <td>
                    <a href="<?= e($href) ?>" class="titre-lien"><?= e(personne_nom($pp)) ?></a>
                    <?php if ((int) $pp['desinscrit'] === 1): ?><span class="badge muted-badge">Désinscrite</span><?php endif; ?>
                </td>
                <td class="muted small"><?= $pp['email'] !== '' ? e((string) $pp['email']) : '—' ?></td>
                <td class="muted small"><?= $pp['telephone'] !== '' ? e((string) $pp['telephone']) : '—' ?></td>
                <td class="muted small">
                    <?php
                    // Ce qui la retient, dit en clair : c'est exactement ce que
                    // le modèle apporte, et qu'aucun écran ne montrait avant.
                    $att = [];
                    if ((int) $pp['nb_structures'] > 0) { $att[] = (int) $pp['nb_structures'] . ' structure' . ((int) $pp['nb_structures'] > 1 ? 's' : ''); }
                    if ((int) $pp['est_employe'] > 0)   { $att[] = 'employée'; }
                    if ((int) $pp['est_utilisateur'] > 0) { $att[] = 'compte'; }
                    ?>
                    <?= $att ? e(implode(' · ', $att)) : '<span class="muted">—</span>' ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php if ($personnes): ?><?php require __DIR__ . '/_pagination.php'; ?><?php endif; ?>
    <script nonce="<?= e(csp_nonce()) ?>">lassoRechercheServeur(document.getElementById('personnes-search'));</script>
</div></div>
