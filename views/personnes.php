<?php /** @var array $personnes */ /** @var array $structuresParPersonne */
/** @var string $recherche */ /** @var array $rattachement */ /** @var int $total */ /** @var array $tri */
/** @var string $pgRoute */ /** @var array $pgParams */ /** @var int $pgPage */ /** @var int $pgTaille */ /** @var int $pgTotal */

// Chaque lien d'en-tête emporte ce que la page porte déjà — la recherche et le
// filtre —, sinon trier remettrait la liste à zéro.
$tousFiltres = array_filter(['q' => $recherche, 'rattachement' => $rattachement]);
$triCol = fn (string $cle, string $lib): string => tri_entete_html('personnes', $cle, $lib, $tri, $tousFiltres);

// Liens du sélecteur d'affichage (liste / deux colonnes) : mêmes filtres
// actifs, seule la vue change — comme ?p=structures.
$qsSansVue = $_GET;
unset($qsSansVue['p'], $qsSansVue['vue'], $qsSansVue['sel']);
$lienDuo = fn (int $idP): string => '?p=personnes&' . http_build_query($qsSansVue + ['vue' => 'duo', 'sel' => $idP]);
$vue = $vue ?? 'liste';
$duoSel = (int) ($duoSel ?? 0);
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

    <div class="toolbar<?= $vue === 'duo' ? ' toolbar-carte toolbar-carte-panneau' : '' ?>">
        <form method="get" class="filters">
            <input type="hidden" name="p" value="personnes">
            <input type="hidden" name="vue" value="<?= e($vue) ?>">
            <?= champ_recherche(['id' => 'personnes-search', 'name' => 'q', 'valeur' => $recherche, 'submit' => true]) ?>
        </form>
        <?php // Avec un filtre posé, le compte de la page ne dit plus « dans
              // l'annuaire » mais « sur tant » : sinon on croit l'annuaire
              // vidé de ce que le filtre écarte. ?>
        <span class="muted small toolbar-compte">
            <?php if ($rattachement || $recherche !== ''): ?>
                <?= (int) $pgTotal ?> sur <?= $total ?>
            <?php else: ?>
                <?= $total ?> personne<?= $total > 1 ? 's' : '' ?> dans l'annuaire
            <?php endif; ?>
        </span>
        <?php // En deux colonnes la liste est en cartes, son <thead> est masqué :
              // l'entonnoir « Rattachement » doit se retrouver dans le panneau
              // « Filtres », visible à toute largeur (voir ?p=structures). ?>
        <?php
        $fmColonnes = filtre_colonne_html('personnes', 'rattachement', PERSONNE_RATTACHEMENTS, $rattachement,
            array_filter(['q' => $recherche]), 'Rattachement');
        $fmActifs = filtre_colonne_actifs_html('personnes', 'rattachement', PERSONNE_RATTACHEMENTS, $rattachement,
            array_filter(['q' => $recherche]));
        $fmClasse = $vue === 'duo' ? 'filtres-carte' : '';
        require __DIR__ . '/_filtres_mobile.php';
        ?>
        <div class="head-actions">
            <?= bascule_vue_html('personnes', $vue, $qsSansVue) ?>
            <?php // Même geste que « Nouvelle structure » : la fiche, en création. ?>
            <?php if (peut_ecrire('carnet')): ?>
            <a class="btn" href="?p=personne"><?= icon('user-plus') ?><span class="lbl"> Nouvelle personne</span></a>
            <?php endif; ?>
        </div>
    </div>

    <?php // .table-scroll seul : porté par le même élément que .card, il
          // dessinait un filet et des coins arrondis de chaque côté d'un
          // tableau qui va déjà de bord à bord (docs/UI.md § 9). ?>
    <?php
    // En deux colonnes, la liste n'est pas réécrite : elle est rendue telle
    // quelle et CAPTURÉE pour aller dans le volet de gauche (views/_duo.php).
    if ($vue === 'duo') { ob_start(); }
    ?>
    <div class="table-scroll">
    <?php // liste-cartes : la même liste passe en cartes dès qu'elle est à
          // l'étroit — sur téléphone comme dans la colonne de gauche de la vue
          // deux colonnes (requête de conteneur, voir assets/css/). ?>
    <table class="list liste-cartes cartes-personnes">
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
        <?php foreach ($personnes as $pp): $pid = (int) $pp['id'];
            $href = $vue === 'duo' ? $lienDuo($pid) : '?p=personne&id=' . $pid; ?>
            <tr class="row-link <?= $vue === 'duo' && $pid === $duoSel ? 'ligne-active' : '' ?>" tabindex="0" role="link" data-href="<?= e($href) ?>">
                <td class="col-nom-personne">
                    <a href="<?= e($href) ?>" class="titre-lien"><?= e(personne_nom($pp)) ?></a>
                    <?php if ((int) $pp['desinscrit'] === 1): ?><span class="badge muted-badge">Désinscrite</span><?php endif; ?>
                </td>
                <td class="muted small col-email-personne"><?= $pp['email'] !== '' ? e((string) $pp['email']) : '—' ?></td>
                <td class="muted small col-tel-personne"><?= $pp['telephone'] !== '' ? e((string) $pp['telephone']) : '—' ?></td>
                <td class="muted small col-rattachements">
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
<?php if ($vue === 'duo'): ?>
<?php
$duoListe = ob_get_clean();
// La fiche de droite est celle de la page pleine : le même partiel, les mêmes
// données (personne_donnees_vue()).
$duoFiche = !empty($p) ? '_personne_fiche.php' : '';
$duoVide  = 'Choisissez une personne dans la liste.';
require __DIR__ . '/_duo.php';
?>
<?php endif; ?>
    <script nonce="<?= e(csp_nonce()) ?>">lassoRechercheServeur(document.getElementById('personnes-search'));</script>
</div></div>
