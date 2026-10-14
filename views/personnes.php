<?php /** @var array $personnes */ /** @var array $doublons */ /** @var string $vue */
/** @var string $recherche */ /** @var int $total */ /** @var int $nbDoublons */ /** @var ?string $ok */
/** @var string $pgRoute */ /** @var array $pgParams */ /** @var int $pgPage */ /** @var int $pgTaille */ /** @var int $pgTotal */
?>
<?php // L'écran appartient au Carnet d'adresses depuis qu'il existe : bandeau
      // du module et onglets, comme ses voisins Structures et Employés. Il
      // vivait dans les Paramètres faute d'un module qui pût le porter. ?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>

<div class="module-content"><div class="module-content-inner">

<?php if ($ok !== null): ?><p class="ok flash"><?= (int) $ok ?> fiche(s) fusionnée(s).</p><?php endif; ?>

<div class="page-head">
    <div class="page-head-title"><h1>Personnes <?= info_tip(
        "Un contact de structure, un employé et un compte peuvent être la MÊME personne, et le sont "
        . 'souvent. Modifier un nom ou une adresse ici le change partout où cette personne apparaît.'
    ) ?></h1></div>
    <div class="head-actions">
        <?php // Deux vues du même annuaire : la liste, et ce qui s'y est
              // dédoublé. Le second bouton ne paraît que s'il y a quelque
              // chose à y voir. ?>
        <?php if ($vue === 'doublons'): ?>
            <a class="btn ghost" href="?p=personnes"><?= icon('arrow-left') ?> <span class="lbl">Tout l'annuaire</span></a>
        <?php elseif ($nbDoublons > 0): ?>
            <a class="btn ghost" href="?p=personnes&amp;vue=doublons"><?= icon('users') ?>
                <span class="lbl">Doublons probables</span> <span class="nav-badge"><?= $nbDoublons ?></span></a>
        <?php endif; ?>
    </div>
</div>

<?php if ($vue === 'doublons'): ?>

    <?php if (!$doublons): ?>
        <p class="muted">Aucun doublon probable : ni deux fiches avec la même adresse électronique, ni deux fois le même nom.</p>
    <?php else: ?>
    <?php // Rien n'est fusionné d'office. Une boîte partagée (info@salle.ch)
          // et deux homonymes se ressemblent exactement autant qu'un vrai
          // doublon, et une fusion fausse ne se défait pas (SPEC_PERSONNES.md § 5). ?>
    <p class="muted">Ces fiches se ressemblent. Rien n'est fusionné tant que vous ne l'avez pas demandé —
       une boîte partagée et deux homonymes se ressemblent autant qu'un vrai doublon, et une fusion ne se défait pas.</p>

    <?php foreach ($doublons as $g): ?>
    <div class="card mt-22">
        <div class="card-head-row">
            <h3 class="mt-0"><?= e($g['cle']) ?> <span class="muted small">— <?= count($g['personnes']) ?> fiches, même <?= $g['motif'] === 'email' ? 'adresse électronique' : 'nom' ?></span></h3>
        </div>
        <form method="post" action="?p=personnes_fusionner">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <div class="table-scroll">
            <table class="list">
                <thead><tr>
                    <th class="col-check" title="La fiche à garder">Garder</th>
                    <th>Nom</th><th>Adresse électronique</th><th>Téléphone</th><th>Rattachements</th>
                </tr></thead>
                <tbody>
                <?php foreach ($g['personnes'] as $i => $pp): $pid = (int) $pp['id']; ?>
                    <tr>
                        <td class="col-check">
                            <?php // La plus ANCIENNE proposée d'office : c'est
                                  // elle qui porte l'historique le plus long. ?>
                            <input type="radio" name="cible" value="<?= $pid ?>" <?= $i === 0 ? 'checked' : '' ?>>
                            <input type="hidden" name="sources[]" value="<?= $pid ?>">
                        </td>
                        <td><a href="?p=personne&id=<?= $pid ?>" class="titre-lien"><?= e(personne_nom($pp)) ?></a></td>
                        <td class="muted small"><?= e((string) $pp['email']) ?></td>
                        <td class="muted small"><?= e((string) $pp['telephone']) ?></td>
                        <td class="muted small"><?= (int) db()->query('SELECT COUNT(*) FROM personne_structures WHERE personne_id = ' . $pid)->fetchColumn() ?> structure(s)</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <button type="submit" class="btn mt-16"
                    data-confirm="Fusionner ces fiches ? Les autres disparaîtront, et tout ce qui les désignait pointera celle que vous gardez. Cela ne se défait pas."><?= icon('users') ?> Fusionner dans la fiche cochée</button>
        </form>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

<?php else: ?>

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

<?php endif; ?>
</div></div>
