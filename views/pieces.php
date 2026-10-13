<?php /** @var array $pieces */ /** @var array $etat */ /** @var array $type */ /** @var array $annee */
/** @var array $annees */ /** @var string $recherche */ /** @var bool $modeClient */ /** @var array $tri */
/** @var string $pgRoute */ /** @var array $pgParams */ /** @var int $pgPage */ /** @var int $pgTaille */ /** @var int $pgTotal */

$peutEcrire = peut_ecrire('pieces');
$nbSuggestions = $peutEcrire ? nb_pieces_suggestions() : 0;
// L'année de l'archive : celle qu'on filtre si une seule est cochée, sinon
// l'année en cours. Deux années cochées ne donnent pas une archive à deux
// exercices — une archive comptable porte sur UN exercice.
$anneeArchive = count($annee) === 1 ? (int) $annee[0] : (int) date('Y');
$etatLabels = PIECE_ETATS;
$typeLabels = array_map(fn ($t) => $t['libelle'], PIECE_TYPES);
$anneeLabels = [];
foreach (array_unique(array_merge($annee, [(int) date('Y')], $annees)) as $a) { $anneeLabels[(int) $a] = (string) (int) $a; }

$autresEtat  = array_filter(['type' => $type, 'annee' => $annee, 'q' => $recherche]);
$autresType  = array_filter(['etat' => $etat, 'annee' => $annee, 'q' => $recherche]);
$autresAnnee = array_filter(['etat' => $etat, 'type' => $type, 'q' => $recherche]);
$tousFiltres = array_filter(['etat' => $etat, 'type' => $type, 'annee' => $annee, 'q' => $recherche]);
$triCol = fn (string $cle, string $lib): string => tri_entete_html('pieces', $cle, $lib, $tri, $tousFiltres);

$jour = fn (?string $d): string => $d ? date('d.m.Y', strtotime($d)) : '';
$nbCols = 6;
?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>

<div class="module-content"><div class="module-content-inner">
    <?php $errArchive = (string) ($_GET['err'] ?? ''); ?>
    <?php if ($errArchive === 'archive_vide'): ?>
        <p class="err flash">Aucune pièce pour cette année : rien à archiver.</p>
    <?php elseif ($errArchive === 'archive'): ?>
        <p class="err flash">L'archive n'a pas pu être créée sur le serveur.</p>
    <?php endif; ?>
    <div class="toolbar">
        <form method="get" class="filters">
            <input type="hidden" name="p" value="pieces">
            <?= champ_recherche(['id' => 'pieces-search', 'name' => 'q', 'valeur' => $recherche, 'submit' => true]) ?>
        </form>
        <?php
        // Sur téléphone la mise en cartes masque le <thead> : le bouton
        // « Filtres » reprend les entonnoirs qui y étaient accrochés, avec
        // leur libellé — hors tableau, plus rien ne les nomme (docs/UI.md § 9).
        ob_start(); ?>
            <?= filtre_colonne_html('pieces', 'etat', $etatLabels, $etat, $autresEtat, 'État') ?>
            <?= filtre_colonne_html('pieces', 'type', $typeLabels, $type, $autresType, 'Type') ?>
            <?= filtre_colonne_html('pieces', 'annee', $anneeLabels, $annee, $autresAnnee, 'Année') ?>
        <?php
        $fmColonnes = ob_get_clean();
        $fmActifs = filtre_colonne_actifs_html('pieces', 'etat', $etatLabels, $etat, $autresEtat)
            . filtre_colonne_actifs_html('pieces', 'type', $typeLabels, $type, $autresType)
            . filtre_colonne_actifs_html('pieces', 'annee', $anneeLabels, $annee, $autresAnnee);
        require __DIR__ . '/_filtres_mobile.php';
        ?>
        <div class="head-actions">
            <?php // Ce qui mène ailleurs d'abord, l'action mise en évidence
                  // ensuite (docs/UI.md § 1). Le bouton ne paraît que s'il y a
                  // quelque chose à rapprocher : un bouton qui mène toujours à
                  // une page vide finit par ne plus être lu. ?>
            <?php if ($nbSuggestions > 0): ?>
            <a class="btn ghost" href="?p=pieces_rapprochements"><?= icon('check') ?>
                <span class="lbl"> Rapprochements</span> <span class="nav-badge"><?= $nbSuggestions ?></span></a>
            <?php endif; ?>
            <?php // L'archive de l'exercice : ce qu'une fiduciaire ou un
                  // vérificateur demande au bouclement. L'année suit les
                  // filtres si l'on en a coché une, sinon c'est l'année en
                  // cours — le geste se fait sur ce qu'on regarde. ?>
            <?php if ($pieces): ?>
            <a class="btn ghost" href="?p=pieces_archive&amp;annee=<?= $anneeArchive ?>"
               title="Les justificatifs de <?= $anneeArchive ?> et leur index, en une archive"><?= icon('download') ?><span class="lbl"> Archive <?= $anneeArchive ?></span></a>
            <?php endif; ?>
            <?php // Le type se choisit AVANT d'ouvrir le formulaire : c'est
                  // lui qui décide des champs posés, et il valait mieux le
                  // demander dans un menu d'une ligne que dans une rangée
                  // d'icônes à mi-formulaire. Le même menu s'ouvre depuis le
                  // tableau de bord (pieces_menu_nouvelle_html()). ?>
            <?php if ($peutEcrire): ?>
            <?= pieces_menu_nouvelle_html() ?>
            <?php endif; ?>
        </div>
    </div>

<div class="table-scroll">
<table class="list list-wide liste-cartes cartes-pieces">
    <thead><tr>
        <th class="col-reinit-hote"><?= bouton_reinit_filtres('pieces', ['etat', 'type', 'annee'], (bool) ($etat || $type || $annee)) ?><?= $triCol('libelle', 'Pièce') ?></th>
        <th><?= $triCol('tiers', 'Tiers') ?></th>
        <th class="col-date">
            <span class="col-th">
                <?= $triCol('date', 'Date') ?>
                <?= filtre_colonne_html('pieces', 'annee', $anneeLabels, $annee, $autresAnnee) ?>
            </span>
        </th>
        <th class="col-echeance"><?= $triCol('echeance', 'Échéance') ?></th>
        <th class="num"><?= $triCol('montant', 'Montant') ?></th>
        <th class="col-etat">
            <span class="col-th">
                État
                <?= filtre_colonne_html('pieces', 'etat', $etatLabels, $etat, $autresEtat) ?>
            </span>
        </th>
    </tr></thead>
    <tbody>
    <?php if (!$pieces): ?>
        <tr><td colspan="<?= $nbCols ?>" class="muted"><?php if ($recherche !== ''): ?>Aucune pièce ne correspond à « <?= e($recherche) ?> ».<?php else: ?>Aucune pièce pour cette sélection.<?php endif; ?></td></tr>
    <?php else: ?>
    <?php
    $prevMois = null;
    foreach ($pieces as $p):
        $moisCle = substr((string) ($p['date_piece'] ?: $p['cree_le']), 0, 7);
        if ($moisCle !== $prevMois):
            $prevMois = $moisCle;
    ?>
        <tr class="mois-sep"><td colspan="<?= $nbCols ?>"><?= e(mois_nom((int) substr($moisCle, 5, 2)) . ' ' . substr($moisCle, 0, 4)) ?></td></tr>
    <?php endif; ?>
        <?php
        $etatP  = piece_etat($p);
        $retard = piece_en_retard($p);
        $href   = '?p=piece&id=' . (int) $p['id'] . suffixe_retour_liste($recherche, $pgPage);
        $typeInfo = PIECE_TYPES[$p['type']] ?? PIECE_TYPES['autre'];
        ?>
        <?php // L'ambre dit UN GESTE À FAIRE, et rien d'autre (docs/UI.md § 13) :
              // une échéance dépassée en est un. Une pièce simplement « à payer »
              // dont le délai court n'en est pas encore un. ?>
        <tr class="row-link<?= $retard ? ' ligne-action' : '' ?>" tabindex="0" role="link" data-href="<?= e($href) ?>">
            <td class="col-nom">
                <span class="piece-type" title="<?= e($typeInfo['libelle']) ?>"><?= icon($typeInfo['icone']) ?></span>
                <a href="<?= e($href) ?>" class="titre-lien"><?= e((string) $p['libelle']) ?></a>
                <?php if (trim((string) $p['fichier']) !== ''): ?>
                    <span class="piece-attache" title="Un fichier est joint" aria-label="Un fichier est joint"><?= icon('paperclip') ?></span>
                <?php endif; ?>
                <?php if (trim((string) $p['categorie_nom']) !== ''): ?>
                    <span class="row-field-txt"><?= e((string) $p['categorie_nom']) ?></span>
                <?php endif; ?>
            </td>
            <?php // .carte-vide : sur une mini-carte, une cellule qui ne porte
                  // qu'un tiret s'efface — il n'y a plus de colonne à tenir
                  // alignée, et l'absence n'a pas à prendre une place. ?>
            <td class="col-tiers<?= $p['tiers'] === '' ? ' carte-vide' : '' ?>"><?= $p['tiers'] !== '' ? e((string) $p['tiers']) : '<span class="muted">—</span>' ?></td>
            <td class="muted small col-date"><?= $jour($p['date_piece']) !== '' ? e($jour($p['date_piece'])) : '—' ?></td>
            <td class="muted small col-echeance<?= $p['date_echeance'] === '' ? ' carte-vide' : '' ?>">
                <?php if ($p['date_echeance'] !== ''): ?>
                    <?= $retard ? '<strong>' . e($jour($p['date_echeance'])) . '</strong>' : e($jour($p['date_echeance'])) ?>
                <?php else: ?>—<?php endif; ?>
            </td>
            <td class="num col-montant <?= (float) $p['montant'] < 0 ? 'montant-neg' : 'montant-pos' ?>"><?= chf((float) $p['montant']) ?></td>
            <td class="col-etat"><?= piece_badge_html($p, $etatP, $retard) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>
</div>
<?php if ($pieces): ?><?php require __DIR__ . '/' . ($modeClient ? '_pagination_client.php' : '_pagination.php'); ?><?php endif; ?>
</div></div>
<script nonce="<?= e(csp_nonce()) ?>">
<?php if ($modeClient): ?>
lassoListeClient({
    tableSelector: '.cartes-pieces',
    searchInputSelector: '#pieces-search',
    separatorSelector: '.mois-sep',
});
<?php else: ?>
lassoRechercheServeur(document.getElementById('pieces-search'));
<?php endif; ?>
</script>
