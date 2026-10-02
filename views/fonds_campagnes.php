<?php
/** @var array $groupes */ /** @var bool $vide */
/** @var int $nbTotal */ /** @var string $recherche */
// La liste des campagnes de recherche de fonds. Même charpente que
// ?p=booking_campagnes, dont c'est le pendant : la zone du module, une barre d'outils,
// puis le tableau d'un bord à l'autre de cette zone. L'onglet actif nomme la
// page, elle n'a donc pas de titre à elle.
$jour = fn ($d) => trim((string) $d) !== '' ? date('d.m.Y', strtotime((string) $d)) : '';
?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>

<div class="module-content"><div class="module-content-inner">
    <div class="toolbar">
        <?php // Le même champ que la liste des campagnes de démarchage : on y
              // cherche la même chose, le nom de la campagne ou celui du projet. ?>
        <form method="get" class="filters">
            <input type="hidden" name="p" value="fonds_campagnes">
            <?= champ_recherche(['id' => 'fonds-search', 'name' => 'q', 'valeur' => $recherche, 'submit' => true, 'placeholder' => 'Nom de campagne, projet…']) ?>
        </form>
        <div class="head-actions">
            <?= info_tip(
                "Une campagne de recherche de fonds est une sélection de bailleurs à solliciter pour un projet,
                entre deux dates. Chaque bailleur y a son dossier : ce qu'on lui demande, ce qu'il exige, ce
                qu'il a répondu. La jauge se compte en francs — obtenu, en attente, reste à trouver —, avec un
                repère à l'objectif minimal, celui sans lequel le projet ne se fait pas."
            ) ?>
            <?php if (peut_ecrire('fonds')): ?>
            <a class="btn" href="?p=fonds_campagne_form"><?= icon('plus') ?> Nouvelle campagne</a>
            <?php endif; ?>
        </div>
    </div>

<?php if (($_GET['ok'] ?? '') === 'suppr'): ?><p class="ok flash">Campagne supprimée.</p>
<?php elseif (isset($_GET['ok'])): ?><p class="ok flash">Campagne enregistrée.</p><?php endif; ?>

<?php if ($vide): ?>
    <p class="muted">
        <?php if ($nbTotal === 0): ?>Aucune campagne de recherche de fonds pour l'instant.
        <?php elseif ($recherche !== ''): ?>Aucune campagne ne correspond à « <?= e($recherche) ?> ».
        <?php else: ?>Aucune campagne pour cette sélection.<?php endif; ?>
    </p>
<?php else: ?>
<div class="table-scroll">
<table class="list list-wide campagnes-table">
    <thead>
        <tr>
            <?php // Le projet en tête, comme dans la liste des campagnes de
                  // démarchage : c'est son icône qui donne à la ligne son point
                  // d'accroche, et une image se repère avant un nom. ?>
            <th>Projet</th>
            <th>Campagne</th>
            <th class="nowrap col-periode">Période</th>
            <th>Avancement</th>
            <th class="num nowrap col-dossiers">Dossiers</th>
        </tr>
    </thead>
    <tbody>
    <?php // Trois tranches, séparées comme les mois d'une liste de dates : ce
          // qui court, ce qui vient, ce qui est derrière. ?>
    <?php foreach ($groupes as $groupe): ?>
        <tr class="mois-sep"><td colspan="5"><?= e($groupe['titre']) ?></td></tr>
        <?php foreach ($groupe['campagnes'] as $c): $cid = (int) $c['id']; ?>
        <?php
            $enCours = periode_courante($c);
            // La même jauge que la fiche d'une campagne, mais nourrie des sommes
            // déjà faites par la requête : parcourir les dossiers de chaque
            // campagne pour dessiner une barre de 120 px n'aurait pas de sens.
            $parts = fonds_jauge(
                (float) $c['montant_obtenu'], (float) $c['montant_en_attente'],
                (float) $c['montant_minimal'], (float) $c['montant_ideal']
            );
        ?>
        <tr class="row-link" tabindex="0" role="link" data-href="?p=fonds_campagne&id=<?= $cid ?>">
            <td>
                <div class="projet-pastilles"><?= projets_pastilles_html($c['projets'], $c['projets_pastilles']) ?></div>
            </td>
            <?php // La couleur d'accent est réservée à ce qui demande du travail :
                  // une campagne dont la saison court. Passée ou à venir, son nom
                  // s'écrit à l'encre — il reste un lien, il n'appelle plus. ?>
            <td><a class="strong<?= $enCours ? '' : ' lien-encre' ?>" href="?p=fonds_campagne&id=<?= $cid ?>"><?= e((string) $c['nom']) ?></a></td>
            <td class="muted small nowrap col-periode">
                <?php $d = $jour($c['date_debut']); $f = $jour($c['date_fin']); ?>
                <?= $d !== '' ? e($d) : '—' ?><?= $f !== '' ? ' → ' . e($f) : '' ?>
            </td>
            <?php // Sur écran étroit, « Dossiers » disparaît et son compte se
                  // replie ici, comme l'état d'une campagne de démarchage : une
                  // seule des deux questions se pose à la fois, et c'est
                  // l'argent qui mène celle-ci. Les deux sont rendus, le CSS
                  // choisit (assets/app.css). ?>
            <td class="camp-avancement">
                <?= fonds_barre_html($parts, 'camp-barre-liste') ?>
                <span class="camp-avancement-txt"><b><?= chf($parts['obtenu']) ?></b><?= $parts['chiffree'] ? ' / ' . chf($parts['base']) : '' ?></span>
                <span class="camp-dossiers-repli"><?= (int) $c['nb_deposees'] ?> / <?= (int) $c['nb_demandes'] ?> déposé<?= (int) $c['nb_deposees'] > 1 ? 's' : '' ?></span>
            </td>
            <td class="num small nowrap col-dossiers"><?= (int) $c['nb_deposees'] ?> / <?= (int) $c['nb_demandes'] ?></td>
        </tr>
        <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

</div></div>
