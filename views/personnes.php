<?php /** @var array $personnes */ /** @var array $structuresParPersonne */
/** @var string $recherche */ /** @var array $rattachement */ /** @var int $total */ /** @var array $tri */
/** @var string $pgRoute */ /** @var array $pgParams */ /** @var int $pgPage */ /** @var int $pgTaille */ /** @var int $pgTotal */

// Chaque lien d'en-tête emporte ce que la page porte déjà — la recherche et le
// filtre —, sinon trier remettrait la liste à zéro.
$tousFiltres = array_filter(['q' => $recherche, 'rattachement' => $rattachement]);
$triCol = fn (string $cle, string $lib): string => tri_entete_html('personnes', $cle, $lib, $tri, $tousFiltres);
?>
<?php // L'écran appartient au Carnet d'adresses depuis qu'il existe : bandeau
      // du module et onglets, comme ses voisins Structures et Employés. Il
      // vivait dans les Paramètres faute d'un module qui pût le porter.
      //
      // Pas de titre de page : l'onglet « Personnes » du bandeau, à deux
      // centimètres au-dessus, dit déjà où l'on est (docs/UI.md § 9 ter).
      // Pas de bouton « Doublons probables » non plus — le dédoublonnage est
      // un geste de reprise de données, il vit dans ?p=incoherences avec ses pareils. ?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>

<div class="module-content"><div class="module-content-inner">

    <div class="toolbar">
        <form method="get" class="filters">
            <input type="hidden" name="p" value="personnes">
            <?= champ_recherche(['id' => 'personnes-search', 'name' => 'q', 'valeur' => $recherche, 'submit' => true]) ?>
        </form>
        <?php // Avec un filtre posé, le compte de la page ne dit plus « dans
              // l'annuaire » mais « sur tant » : sinon on croit l'annuaire
              // vidé de ce que le filtre écarte. ?>
        <span class="muted small">
            <?php if ($rattachement || $recherche !== ''): ?>
                <?= (int) $pgTotal ?> sur <?= $total ?>
            <?php else: ?>
                <?= $total ?> personne<?= $total > 1 ? 's' : '' ?> dans l'annuaire
            <?php endif; ?>
        </span>
    </div>

    <?php // .table-scroll seul : porté par le même élément que .card, il
          // dessinait un filet et des coins arrondis de chaque côté d'un
          // tableau qui va déjà de bord à bord (docs/UI.md § 9). ?>
    <div class="table-scroll">
    <table class="list">
        <thead><tr>
            <?php // L'effacement des filtres se loge dans le PREMIER en-tête,
                  // comme sur les autres listes filtrables (?p=pieces,
                  // ?p=compta_ecritures) — pas dans la barre d'outils, où il
                  // se serait posé à gauche du champ de recherche. ?>
            <th class="col-reinit-hote"><?= bouton_reinit_filtres('personnes', ['rattachement'], (bool) $rattachement) ?><?= $triCol('nom', 'Nom') ?></th>
            <th><?= $triCol('email', 'Adresse électronique') ?></th>
            <th><?= $triCol('telephone', 'Téléphone') ?></th>
            <th>
                <span class="col-th">
                    <?= $triCol('rattachements', 'Rattachements') ?>
                    <?php // Sans libellé : l'en-tête de colonne le porte déjà, à
                          // trois millimètres à gauche. Le libellé du sixième
                          // argument sert au panneau « Filtres » du téléphone,
                          // où aucun en-tête ne nomme ce qu'on filtre. ?>
                    <?= filtre_colonne_html('personnes', 'rattachement', PERSONNE_RATTACHEMENTS, $rattachement,
                            array_filter(['q' => $recherche])) ?>
                </span>
            </th>
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
                    <?php // Ce qui la retient, NOMMÉ : « 1 structure » obligeait
                          // à ouvrir la fiche pour savoir laquelle. Les liens
                          // mènent droit aux structures — c'est le trajet qu'on
                          // fait depuis cet écran. ?>
                    <?php $pStructs = $structuresParPersonne[$pid] ?? []; ?>
                    <?php if (!$pStructs && !(int) $pp['est_employe'] && !(int) $pp['est_utilisateur']): ?>
                        <span class="muted">—</span>
                    <?php else: ?>
                        <?php foreach ($pStructs as $i => $st): ?><?= $i ? ' · ' : '' ?><a href="?p=structure&amp;id=<?= $st['id'] ?>&amp;depuis=personne:<?= $pid ?>"><?= e($st['nom']) ?></a><?php endforeach; ?>
                        <?php if ((int) $pp['est_employe'] > 0): ?><?= $pStructs ? ' · ' : '' ?><?= badge('Employée', 'ok') ?><?php endif; ?>
                        <?php if ((int) $pp['est_utilisateur'] > 0): ?> <?= badge('Compte', 'muted') ?><?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php if ($personnes): ?><?php require __DIR__ . '/_pagination.php'; ?><?php endif; ?>
    <script nonce="<?= e(csp_nonce()) ?>">lassoRechercheServeur(document.getElementById('personnes-search'));</script>
</div></div>
