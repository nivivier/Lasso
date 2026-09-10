<?php
/** @var array $campagnes */ /** @var int $nbTotal */ /** @var bool $saved */ /** @var bool $supprimee */
/** @var array $projet */ /** @var array $annee */ /** @var array $statut */ /** @var string $recherche */
/** @var array $anneesDispo */ /** @var array $projetsDispo */
// Les campagnes de contact, en trois tranches séparées : en cours, à venir,
// passées — la plus récente en tête, sauf parmi celles à venir, où c'est la
// plus proche. La jauge dit l'essentiel — combien de structures restent à
// démarcher.
$statutClasse = [
    'a_venir'   => 'muted-badge',
    'en_cours'  => 'ok-badge',
    'en_retard' => 'err-badge',
    'terminee'  => 'muted-badge',
];
$jour = fn ($d) => trim((string) $d) !== '' ? date('d.m.Y', strtotime((string) $d)) : '';
// Filtres de colonne (mêmes composants que ?p=structures et ?p=facturation_liste) :
// chacun repart dans l'URL avec les autres, et avec la recherche en cours.
$statutLabels = CAMPAGNE_STATUTS;
$tousFiltres = array_filter(['projet_id' => $projet, 'annee' => $annee, 'statut' => $statut, 'q' => $recherche]);
$autres = autres_filtres_fn($tousFiltres);
$filtreActif = $projet !== [] || $annee !== [] || $statut !== [];
?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>

<?php // Même charpente que ?p=structures : la zone du module, puis une barre
      // d'outils, puis le tableau — qui va d'un bord à l'autre de cette zone
      // (.module-content-inner .table-scroll, assets/app.css). L'onglet actif
      // nomme la page, elle n'a donc pas de titre à elle. ?>
<div class="module-content"><div class="module-content-inner">
    <div class="toolbar">
        <?php // La recherche voyage avec les filtres en cours : les retrouver
              // décochés après avoir tapé trois lettres serait une surprise. ?>
        <form method="get" class="filters">
            <input type="hidden" name="p" value="campagnes">
            <?= hidden_inputs_html($tousFiltres) ?>
            <?= champ_recherche(['id' => 'campagnes-search', 'name' => 'q', 'valeur' => $recherche, 'submit' => true, 'placeholder' => 'Nom de campagne, projet…']) ?>
        </form>
        <?php // Sur téléphone, la mise en cartes masque le <thead> : ce panneau
              // reprend les entonnoirs qui y sont accrochés (voir ?p=fiches). ?>
        <?php ob_start(); ?>
            <?= $projetsDispo ? filtre_colonne_html('campagnes', 'projet_id', $projetsDispo, $projet, $autres('projet_id'), 'Projet') : '' ?>
            <?= $anneesDispo ? filtre_colonne_html('campagnes', 'annee', $anneesDispo, $annee, $autres('annee'), 'Période') : '' ?>
            <?= filtre_colonne_html('campagnes', 'statut', $statutLabels, $statut, $autres('statut'), 'État') ?>
        <?php
        $fmColonnes = ob_get_clean();
        $fmActifs = filtre_colonne_actifs_html('campagnes', 'projet_id', $projetsDispo, $projet, $autres('projet_id'))
            . filtre_colonne_actifs_html('campagnes', 'annee', $anneesDispo, $annee, $autres('annee'))
            . filtre_colonne_actifs_html('campagnes', 'statut', $statutLabels, $statut, $autres('statut'));
        require __DIR__ . '/_filtres_mobile.php';
        ?>
        <div class="head-actions">
            <?= info_tip(
                "Une campagne est une sélection de structures à contacter pour un ou plusieurs projets, entre deux dates.
                On y démarche structure par structure ; la jauge compte les prises de contact déjà consignées, e-mail
                comme appel noté à la main. Avant la date de début, aucun message ne part."
            ) ?>
            <?php if (peut_ecrire('booking')): ?>
            <a class="btn" href="?p=campagne_form"><?= icon('plus') ?> Nouvelle campagne</a>
            <?php endif; ?>
        </div>
    </div>

<?php if ($saved): ?><p class="ok flash">Enregistré.</p><?php endif; ?>
<?php if ($supprimee): ?><p class="ok flash">Campagne supprimée.</p><?php endif; ?>

<div class="table-scroll">
<table class="list list-wide campagnes-table">
    <thead>
        <tr>
            <?php // Le projet en tête : c'est son icône qui donne à la ligne son
                  // point d'accroche, et une image se repère avant un nom. Le
                  // bouton de retrait des filtres suit la première colonne. ?>
            <?php // Un entonnoir dont la liste d'options est vide n'aurait rien à
                  // filtrer : Projet et Période sont dérivés des campagnes
                  // elles-mêmes, et n'existent donc pas tant qu'il n'y en a aucune. ?>
            <th class="col-reinit-hote"><span class="col-th"><?= bouton_reinit_filtres('campagnes', ['projet_id', 'annee', 'statut'], $filtreActif) ?>Projet <?= $projetsDispo ? filtre_colonne_html('campagnes', 'projet_id', $projetsDispo, $projet, $autres('projet_id')) : '' ?></span></th>
            <th>Campagne</th>
            <th class="nowrap"><span class="col-th">Période <?= $anneesDispo ? filtre_colonne_html('campagnes', 'annee', $anneesDispo, $annee, $autres('annee')) : '' ?></span></th>
            <th>Avancement</th>
            <th class="nowrap"><span class="col-th">État <?= filtre_colonne_html('campagnes', 'statut', $statutLabels, $statut, $autres('statut')) ?></span></th>
        </tr>
    </thead>
    <tbody>
    <?php if (!$campagnes): ?>
        <tr><td colspan="5" class="muted">
            <?php if ($nbTotal === 0): ?>Aucune campagne pour l'instant.
            <?php elseif ($recherche !== ''): ?>Aucune campagne ne correspond à « <?= e($recherche) ?> ».
            <?php else: ?>Aucune campagne pour cette sélection.<?php endif; ?>
        </td></tr>
    <?php endif; ?>
    <?php // Trois tranches : en cours, à venir, passées (campagnes_groupees()).
          // La ligne de séparation est celle des autres listes de l'application
          // — .mois-sep, du nom de son premier usage, est le séparateur de
          // section des tableaux larges (?p=fiches, ?p=evenements,
          // ?p=compta_ecritures). Les tranches vides ne sont pas rendues du
          // tout, séparateur compris. ?>
    <?php foreach (campagnes_groupees($campagnes) as $groupe): ?>
        <tr class="mois-sep"><td colspan="5"><?= e($groupe['titre']) ?></td></tr>
    <?php foreach ($groupe['campagnes'] as $c): $cid = (int) $c['id']; ?>
        <tr class="row-link" tabindex="0" role="link" data-href="?p=campagne&id=<?= $cid ?>">
            <td>
                <?php if ($c['projets']): ?>
                    <?php // Chaque projet avec son icône : c'est elle qu'on
                          // reconnaît d'un coup d'œil dans une liste de campagnes. ?>
                    <?php foreach ($c['projets'] as $i => $nomProjet): ?>
                        <span class="projet-pastille"><?= $c['projets_pastilles'][$i] ?? '' ?><?= e($nomProjet) ?></span>
                    <?php endforeach; ?>
                <?php else: ?>
                    <span class="muted">Aucun projet</span>
                <?php endif; ?>
            </td>
            <?php // La couleur d'accent est réservée à ce qui demande du travail :
                  // une campagne en cours. À venir, en retard ou terminée, son nom
                  // s'écrit à l'encre — il reste un lien, il n'appelle plus. ?>
            <td><a class="strong<?= $c['statut'] === 'en_cours' ? '' : ' lien-encre' ?>" href="?p=campagne&id=<?= $cid ?>"><?= e($c['nom']) ?></a></td>
            <td class="muted small nowrap">
                <?php $d = $jour($c['date_debut']); $f = $jour($c['date_fin']); ?>
                <?= $d !== '' ? e($d) : '—' ?><?= $f !== '' ? ' → ' . e($f) : '' ?>
            </td>
            <td class="camp-avancement">
                <?php // La même barre que la carte d'une campagne, en plus étroit :
                      // ce qui reste à faire ET ce qu'on a obtenu, sans compter.
                      // La légende ne tient pas dans une ligne de tableau — le
                      // détail des quatre parts est au survol et pour les
                      // lecteurs d'écran (campagne_barre_html()). ?>
                <?= campagne_barre_html($c['repartition'], (int) $c['nb_total'], 'camp-barre-liste') ?>
                <span class="camp-avancement-txt"><b><?= (int) $c['nb_faits'] ?></b> / <?= (int) $c['nb_total'] ?></span>
            </td>
            <td class="nowrap">
                <span class="badge <?= $statutClasse[$c['statut']] ?? 'muted-badge' ?>"><?= e(CAMPAGNE_STATUTS[$c['statut']] ?? $c['statut']) ?></span>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
</div></div>
