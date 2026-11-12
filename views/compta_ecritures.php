<?php
/** @var array $tri */ /** @var array $comptes */ /** @var array $compteId */ /** @var array $annee */ /** @var array $annees */
/** @var array $categorieFilter */ /** @var array $axeFilter */ /** @var array $sensFiltre */ /** @var array $ecritures */
/** @var array $pieceFiltre */ /** @var bool $piecesOk */
/** @var array $ventilationsParEcr */ /** @var array $feuilles */ /** @var array $axes */
/** @var ?string $rules */ /** @var bool $openNew */
/** @var ?int $bulkCount */ /** @var bool $okAnnule */ /** @var string $recherche */ /** @var bool $modeClient */
/** @var string $pgRoute */ /** @var array $pgParams */ /** @var int $pgPage */ /** @var int $pgTaille */ /** @var int $pgTotal */
/** @var array $categoriesArbre */

// Map id → chemin et id → {prefix, leaf} pour les inputs individuels.
$cheminById    = [];
$catPrefixById = [];
$catLeafById   = [];
foreach ($feuilles as $f) {
    $ch = $f['chemin'];
    $cheminById[(int) $f['id']] = $ch;
    $sep = mb_strrpos($ch, ' › ');
    if ($sep !== false) {
        $catPrefixById[(int) $f['id']] = mb_substr($ch, 0, $sep);
        $catLeafById[(int) $f['id']]   = mb_substr($ch, $sep + 3);
    } else {
        $catPrefixById[(int) $f['id']] = '';
        $catLeafById[(int) $f['id']]   = $ch;
    }
}

// Pour les dropdowns : produits d'abord puis charges, ordre du plan respecté dans chaque sens.
$feuillesSorted = array_values(array_merge(
    array_filter($feuilles, fn($f) => $f['sens'] === 'produit'),
    array_filter($feuilles, fn($f) => $f['sens'] !== 'produit')
));

// Formulaire écriture manuelle (création ou modification).
// Ce formulaire ne sert plus qu'à CRÉER : une écriture existante se modifie
// dans sa ligne (le crayon, voir _compta_ecritures_script.php). Il partait
// autrefois en tête de page pour les deux, à cinquante lignes de l'écriture
// qu'il modifiait.
$showForm = $openNew;
// http_build_query gère aussi bien les valeurs scalaires que les tableaux
// (compte[0]=1&compte[1]=2 — reparsable tel quel par PHP), donc pas besoin
// de distinguer les filtres à valeur unique des filtres multi-valeurs ici.
$qs = '&' . http_build_query(['compte' => $compteId, 'annee' => $annee, 'categorie' => $categorieFilter, 'axe' => $axeFilter, 'sens' => $sensFiltre, 'piece' => $pieceFiltre]);
$axeLabel = fn(array $ax): string => ($ax['code'] !== '' && $ax['code'] !== null) ? $ax['code'] : $ax['libelle'];

// Filtres de colonne (EXPÉRIMENTAL, même mécanique que ?p=fiches — voir
// filtre_colonne_html()/filtre_colonne_actifs_html() dans lib/helpers.php) :
// Date/Compte/Catégorie/Axe, à la place des 4 anciens <select> de la toolbar.
$compteLabels = [];
foreach ($comptes as $c) { $compteLabels[(int) $c['id']] = $c['libelle']; }
$anneeLabels = [];
foreach ($annees as $a) { $anneeLabels[(int) $a] = (string) (int) $a; }
$categorieLabels = ['a_lettrer' => '— À lettrer —', 'ignore' => '— Ne pas lettrer —'];
foreach ($categoriesArbre as $c) {
    $categorieLabels[(int) $c['id']] = str_repeat("\u{00A0}\u{00A0}", (int) $c['profondeur']) . $c['libelle'] . (!empty($c['a_enfants']) ? ' (et sous-catégories)' : '');
}
// Même vocabulaire que les règles de lettrage (?p=compta_regles) : le signe
// vient du relevé bancaire, où une entrée d'argent CRÉDITE le compte.
$sensLabels = ['credit' => 'Crédit (+)', 'debit' => 'Débit (−)'];
$axeLabels = ['sans_axe' => '— Sans axe —'];
foreach ($axes as $ax) { $axeLabels[(int) $ax['id']] = $axeLabel($ax); }
// Le justificatif. Les libellés viennent de lib/pieces.php, la même table qui
// nomme les icônes de la colonne : un état renommé l'est partout d'un coup.
$pieceLabels = ECRITURE_PIECE_LIBELLES;
// $autresFiltres('champ') : les AUTRES filtres actifs de la page (jamais
// celui-ci), à reporter en hidden inputs par chaque panneau — voir
// autres_filtres_fn(), lib/helpers.php.
$tousFiltres = ['compte' => $compteId, 'annee' => $annee, 'categorie' => $categorieFilter, 'axe' => $axeFilter, 'sens' => $sensFiltre, 'piece' => $pieceFiltre, 'q' => $recherche];
$autresFiltres = autres_filtres_fn($tousFiltres);
// Colonne Compte masquée quand le filtre la rend redondante (un seul compte
// coché) — généralise l'ancien "$compteId === 0" (affiché) / valeur unique
// (masqué) au cas multi-sélection : masquée seulement si exactement 1 coché.
$compteColVisible = count($compteId) !== 1;
$compteDefaut     = count($compteId) === 1 ? $compteId[0] : 0;

// Fonction : composant cat-search réutilisable (partagé bulk + form manuel).
$catSearchField = function (string $name, ?int $selected, string $placeholder, bool $ignore = false) use ($feuillesSorted, $cheminById, $catPrefixById): string {
    $initChemin = $ignore ? 'Ne pas lettrer' : ($selected !== null ? ($cheminById[$selected] ?? '') : '');
    $hiddenVal  = $ignore ? 'ignore' : ($selected ?? '');
    $items = '';
    $sensCourant = null; $grpCourant = null;
    foreach ($feuillesSorted as $f) {
        $fid = (int) $f['id'];
        $grp = $catPrefixById[$fid] ?? '';
        if ($f['sens'] !== $sensCourant) { $sensCourant = $f['sens']; $grpCourant = null; $items .= '<li class="cat-search-sens">' . ($sensCourant === 'produit' ? 'Recettes' : 'Dépenses') . '</li>'; }
        if ($grp !== $grpCourant) { $grpCourant = $grp; if ($grp !== '') $items .= '<li class="cat-search-group">' . e($grp) . '</li>'; }
        $items .= '<li data-val="' . $fid . '">' . e($f['chemin']) . '</li>';
    }
    return '<div class="cat-search form-cat-search" data-cat-search data-filtre-groupes>'
         . '<input type="text" class="cat-search-input" aria-label="Catégorie" placeholder="' . e($placeholder) . '" autocomplete="off" value="' . e($initChemin) . '">'
         . '<input type="hidden" name="' . e($name) . '" class="cat-search-val" value="' . e($hiddenVal) . '">'
         . '<ul class="cat-search-list" hidden role="listbox"><li data-val="">— Sans catégorie —</li>'
         . '<li data-val="ignore">Ne pas lettrer</li>' . $items . '</ul>'
         . '</div>';
};
?>
<?php $actionUrl = '?p=compta_ecritures'; require __DIR__ . '/_bulk_undo_flash.php'; ?>

<?php // Le gabarit de la cellule de lecture d'une catégorie. Une écriture
      // jamais lettrée n'en a pas — elle n'affiche que son champ, pour qu'on
      // puisse la lettrer d'un clic sans passer par un crayon. Quand elle
      // reçoit sa catégorie sans recharger la page, c'est d'ici que sa cellule
      // de lecture est tirée : le balisage reste écrit une fois.
      // Hors de la boucle : un gabarit par page, pas un par ligne. ?>
<?php if ($piecesOk && peut_ecrire('pieces')): ?>
<?php // Le menu « Joindre une pièce », UNE fois pour toute la page : le script
      // le clone dans la cellule cliquée et y remplace __ECRITURE__ par
      // l'écriture visée (voir data-pieces-menu, assets/app.js). ?>
<template id="tpl-pieces-ecriture">
    <?= pieces_menu_nouvelle_html(
        ['icone' => 'paperclip', 'libelle' => '', 'nu' => true,
         'classe' => 'row-edit-btn piece-lier-btn',
         'titre' => 'Joindre une pièce à cette écriture'],
        ['depuis' => 'compta_ecritures', 'params' => ['ecriture_id' => '__ECRITURE__']]
    ) ?>
</template>
<?php endif; ?>
<?php if (peut_ecrire('compta')): ?>
<template id="tpl-cat-disp">
    <div class="row-field-disp">
        <span class="row-field-txt"><span class="row-field-prefix"></span><span class="row-field-leaf"></span></span>
        <button type="button" class="row-edit-btn" title="Modifier" aria-label="Modifier cette écriture"><?= icon('pencil') ?></button>
    </div>
</template>
<?php endif; ?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>

<div class="module-content"><div class="module-content-inner">
    <div class="toolbar">
        <form method="get" class="filters">
            <input type="hidden" name="p" value="compta_ecritures">
            <?= champ_recherche(['id' => 'compta-search', 'name' => 'q', 'valeur' => $recherche, 'submit' => true]) ?>
        </form>
        <?php
        // Les mêmes filtres qu'en en-tête de colonne, avec leur libellé : en
        // cartes, le <thead> est masqué et ses entonnoirs avec lui (?p=fiches).
        ob_start(); ?>
            <?= filtre_colonne_html('compta_ecritures', 'annee', $anneeLabels, $annee, $autresFiltres('annee'), 'Année') ?>
            <?php if ($compteColVisible): ?><?= filtre_colonne_html('compta_ecritures', 'compte', $compteLabels, $compteId, $autresFiltres('compte'), 'Compte') ?><?php endif; ?>
            <?= filtre_colonne_html('compta_ecritures', 'sens', $sensLabels, $sensFiltre, $autresFiltres('sens'), 'Sens') ?>
            <?= filtre_colonne_html('compta_ecritures', 'categorie', $categorieLabels, $categorieFilter, $autresFiltres('categorie'), 'Catégorie') ?>
            <?php if ($axes): ?><?= filtre_colonne_html('compta_ecritures', 'axe', $axeLabels, $axeFilter, $autresFiltres('axe'), 'Axe') ?><?php endif; ?>
            <?php if ($piecesOk): ?><?= filtre_colonne_html('compta_ecritures', 'piece', $pieceLabels, $pieceFiltre, $autresFiltres('piece'), 'Pièce') ?><?php endif; ?>
        <?php
        $fmColonnes = ob_get_clean();
        $fmActifs = filtre_colonne_actifs_html('compta_ecritures', 'annee', $anneeLabels, $annee, $autresFiltres('annee'))
            . ($compteColVisible ? filtre_colonne_actifs_html('compta_ecritures', 'compte', $compteLabels, $compteId, $autresFiltres('compte')) : '')
            . filtre_colonne_actifs_html('compta_ecritures', 'sens', $sensLabels, $sensFiltre, $autresFiltres('sens'))
            . filtre_colonne_actifs_html('compta_ecritures', 'categorie', $categorieLabels, $categorieFilter, $autresFiltres('categorie'))
            . ($axes ? filtre_colonne_actifs_html('compta_ecritures', 'axe', $axeLabels, $axeFilter, $autresFiltres('axe')) : '')
            . ($piecesOk ? filtre_colonne_actifs_html('compta_ecritures', 'piece', $pieceLabels, $pieceFiltre, $autresFiltres('piece')) : '');
        require __DIR__ . '/_filtres_mobile.php';
        ?>
        <?php if (peut_ecrire('compta')): ?>
        <div class="head-actions">
            <button type="button" id="btn-new-ecr" class="btn ghost btn-compact"><?= icon('plus') ?> Écriture<br class="btn-compact-break"> manuelle</button>
            <a href="?p=compta_ecritures_importer" class="btn"><?= icon('upload') ?><span class="lbl"> Importer</span></a>
        </div>
        <?php endif; ?>
    </div>

    <?php // Exercices clôturés (lib/compta.php § Clôture d'exercice) : dit
          // AVANT qu'on essaie, plutôt qu'après un refus. Seulement quand la
          // liste en montre — toutes années, ou l'une des années cochées. ?>
    <?php $clotureAnnee = $clotureAnnee ?? null;
    $anneesVues = array_map('intval', (array) ($annee ?? []));
    if ($clotureAnnee !== null && (!$anneesVues || min($anneesVues) <= $clotureAnnee)): ?>
    <p class="muted small mb-8"><?= icon('lock') ?> Exercices clôturés jusqu'à <?= (int) $clotureAnnee ?> : leurs écritures ne se modifient plus.</p>
    <?php endif; ?>
    <?php if ($rules !== null): ?><p class="ok flash"><?= (int) $rules ?> écriture(s) lettrée(s) par les règles.</p><?php endif; ?>

<?php if (peut_ecrire('compta')): ?>
<!-- Formulaire écriture manuelle -->
<div class="card form ecr-manuel-form" id="ecr-manuel-form" <?= $showForm ? '' : 'hidden' ?>>
    <h3 class="form-subtitle">Nouvelle écriture manuelle</h3>
    <form method="post" action="?p=compta_ecritures<?= $qs ?>">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="section" value="create">
        <div class="grid3">
            <label>Compte
                <select name="compte_bancaire_id" required>
                    <?php foreach ($comptes as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= $compteDefaut === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['libelle']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Date
                <input type="date" name="date_op" required value="<?= e(date('Y-m-d')) ?>">
            </label>
            <label>Montant <span class="muted label-hint">(+ crédit, − débit)</span>
                <input type="number" name="montant" step="0.01" required value="" placeholder="ex. -150.00">
            </label>
        </div>
        <label>Description
            <input type="text" name="texte" required value="" placeholder="ex. Remboursement frais divers">
        </label>
        <div class="grid2-optional">
        <label>Catégorie <span class="muted label-hint">(optionnel)</span>
            <?= $catSearchField('plan_compte_id', null, 'Chercher une catégorie…', false) ?>
        </label>
        <?php if ($axes): ?>
        <label>Axe analytique <span class="muted label-hint">(optionnel)</span>
            <select name="axe_analytique_id">
                <option value="">— Aucun —</option>
                <?php foreach ($axes as $ax): ?>
                    <option value="<?= (int) $ax['id'] ?>"><?= e($axeLabel($ax)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn"><?= icon('check') ?> Créer l'écriture</button>
            <a href="?p=compta_ecritures<?= $qs ?>" class="btn ghost">Annuler</a>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if (!$comptes): ?>
    <p class="muted">Aucun compte bancaire. Commencez par en <a href="?p=compta_comptes">créer un</a> puis <a href="?p=compta_ecritures_importer">importer</a> un export.</p>
<?php else: ?>

<?php if (peut_ecrire('compta')): ?>
<div class="bulk-bar" id="bulk-bar" hidden>
    <form method="post" id="bulkform" action="?p=compta_ecritures<?= $qs ?>">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <select name="section" id="bulk-action" class="inline-year-select" aria-label="Action sur la sélection">
            <option value="">— Choisir une action —</option>
            <option value="lettrer">Modifier la catégorie</option>
            <?php if ($axes): ?><option value="axer">Modifier l'axe</option><?php endif; ?>
            <?php if ($piecesOk && peut_ecrire('pieces')): ?><option value="dispenser">Dispenser de pièce</option><?php endif; ?>
        </select>

        <span class="bulk-field" data-for="lettrer" hidden>
            <div class="cat-search bulk-cat-search" data-cat-search data-filtre-groupes data-hydrater data-texte-vide>
                <input type="text" class="cat-search-input" aria-label="Nouvelle catégorie" placeholder="Catégorie ou retirer le lettrage…" autocomplete="off">
                <input type="hidden" name="plan_compte_id" class="cat-search-val" value="">
                <ul class="cat-search-list" hidden role="listbox">
                    <li data-val="">— Retirer le lettrage —</li>
                    <li data-val="ignore">Ne pas lettrer</li>
                    <?php $sensCourant = null; $grpCourant = null; foreach ($feuillesSorted as $f): $fid = (int) $f['id']; $grp = $catPrefixById[$fid] ?? ''; ?>
                    <?php if ($f['sens'] !== $sensCourant): $sensCourant = $f['sens']; $grpCourant = null; ?><li class="cat-search-sens"><?= $sensCourant === 'produit' ? 'Recettes' : 'Dépenses' ?></li><?php endif; ?>
                    <?php if ($grp !== $grpCourant): $grpCourant = $grp; if ($grp !== ''): ?><li class="cat-search-group"><?= e($grp) ?></li><?php endif; endif; ?>
                        <li data-val="<?= $fid ?>"><?= e($f['chemin']) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </span>
        <?php if ($axes): ?>
        <span class="bulk-field" data-for="axer" hidden>
            <select name="axe_analytique_id" class="inline-year-select" aria-label="Nouvel axe analytique">
                <option value="">— Retirer —</option>
                <?php foreach ($axes as $ax): ?>
                    <option value="<?= (int) $ax['id'] ?>"><?= e($axeLabel($ax)) ?></option>
                <?php endforeach; ?>
            </select>
        </span>
        <?php endif; ?>

        <?php if ($piecesOk && peut_ecrire('pieces')): ?>
        <?php // Le motif FAIT FOI : vide, la dispense se retire. D'où l'absence
              // de « required » — on efface en effaçant, sans second bouton. ?>
        <span class="bulk-field" data-for="dispenser" hidden>
            <input type="text" name="dispense_motif" class="bulk-motif" aria-label="Motif de la dispense de pièce"
                   placeholder="Pourquoi pas de pièce ? (vide = retirer la dispense)">
        </span>
        <?php endif; ?>
        <button type="submit" class="btn" id="bulk-submit" disabled>Modifier la sélection</button>
    </form>
</div>
<?php endif; ?>

<div class="table-scroll">
<?php // Sur téléphone (ou dans toute colonne de moins de 700 px), la liste se
      // relit en cartes : mêmes cellules, replacées (.cartes-ecritures,
      // assets/css/). Le <thead> disparaît alors, et ses entonnoirs avec
      // lui : ils sont repris par le panneau « Filtres » de la barre d'outils. ?>
<table class="list compta-lettrage liste-cartes cartes-ecritures<?= peut_ecrire('compta') ? ' avec-check' : '' ?>">
    <?php // 7 = case + Date + Contre-partie + Texte + Montant + Catégorie + actions ?>
    <?php $nbCols = 7 + ($compteColVisible ? 1 : 0) + ($axes ? 1 : 0) + ($piecesOk ? 1 : 0) - (peut_ecrire('compta') ? 0 : 1); ?>
    <thead>
        <tr>
            <?php if (peut_ecrire('compta')): ?><th class="col-reinit-hote col-check"><?= bouton_reinit_filtres('compta_ecritures', ['annee', 'compte', 'categorie', 'axe', 'sens'], (bool) ($annee || $compteId || $categorieFilter || $axeFilter || $sensFiltre)) ?><input type="checkbox" id="check-all" aria-label="Tout cocher"></th><?php endif; ?>
            <?php // La dernière colonne (les boutons de lettrage) et « Axe » ne se
                  // trient pas : l'une ne porte pas de donnée, l'autre affiche
                  // plusieurs ventilations par écriture. ?>
            <?php $triCol = fn (string $cle, string $lib): string => tri_entete_html('compta_ecritures', $cle, $lib, $tri, $autresFiltres('')); ?>
            <th class="col-date">
                <span class="col-th">
                    <?= $triCol('date', 'Date') ?>
                    <?= filtre_colonne_html('compta_ecritures', 'annee', $anneeLabels, $annee, $autresFiltres('annee')) ?>
                </span>
            </th>
            <?php if ($compteColVisible): ?>
            <th class="col-compte">
                <span class="col-th">
                    <?= $triCol('compte', 'Compte') ?>
                    <?= filtre_colonne_html('compta_ecritures', 'compte', $compteLabels, $compteId, $autresFiltres('compte')) ?>
                </span>
            </th>
            <?php endif; ?>
            <?php // Colonne à part plutôt que noyée dans le texte : c'est la
                  // réponse à « qui ? », que le relevé donne en clair. ?>
            <th class="col-tiers"><?= $triCol('tiers', 'Contre-partie') ?></th>
            <?php // Le bouton déplie ou replie TOUTE la colonne d'un coup — ce que
                  // chaque cellule fait déjà au clic. Les deux icônes voyagent sur
                  // le bouton (data-ico-*) plutôt que d'être redessinées en
                  // JavaScript : le SVG reste produit par icon(), une seule fois. ?>
            <th>
                <span class="col-th">
                    <?= $triCol('texte', 'Texte') ?>
                    <button type="button" class="col-th-btn texte-tout-btn"
                            title="Tout détailler" aria-label="Tout détailler"
                            data-ico-unfold="<?= e(icon('unfold-vertical')) ?>"
                            data-ico-fold="<?= e(icon('fold-vertical')) ?>"><?= icon('unfold-vertical') ?></button>
                </span>
            </th>
            <th class="num">
                <span class="col-th">
                    <?= $triCol('montant', 'Montant') ?>
                    <?= filtre_colonne_html('compta_ecritures', 'sens', $sensLabels, $sensFiltre, $autresFiltres('sens')) ?>
                </span>
            </th>
            <th class="col-categorie">
                <span class="col-th">
                    <?= $triCol('categorie', 'Catégorie') ?>
                    <?= filtre_colonne_html('compta_ecritures', 'categorie', $categorieLabels, $categorieFilter, $autresFiltres('categorie')) ?>
                </span>
            </th>
            <?php if ($axes): ?>
            <th class="col-axe">
                <span class="col-th">
                    Axe
                    <?= filtre_colonne_html('compta_ecritures', 'axe', $axeLabels, $axeFilter, $autresFiltres('axe')) ?>
                </span>
            </th>
            <?php endif; ?>
            <?php if ($piecesOk): ?>
            <?php // Le justificatif : une icône par nature de preuve, rien du
                  // tout quand elle manque. C'est l'ABSENCE qui doit sauter aux
                  // yeux dans une colonne d'icônes, pas la présence — l'inverse
                  // aurait fait clignoter les lignes en ordre. ?>
            <th class="col-piece">
                <span class="col-th">
                    Pièce
                    <?= filtre_colonne_html('compta_ecritures', 'piece', $pieceLabels, $pieceFiltre, $autresFiltres('piece')) ?>
                </span>
            </th>
            <?php endif; ?>
            <th>
            </th>
        </tr>
    </thead>
    <tbody>
    <?php // Le corps fait l'essentiel du poids de la page (toutes les lignes en
          // mode client) : tamponné et compacté avant l'envoi, comme la liste
          // des structures (compacter_cellules(), lib/helpers.php). Aucun
          // <textarea> ni texte préformaté ici — la condition de la fonction. ?>
    <?php ob_start(); ?>
    <?php if (!$ecritures): ?>
        <tr><td colspan="<?= $nbCols ?>" class="muted">Aucune écriture pour ce filtre.</td></tr>
    <?php else: ?>
    <?php
    $prevMois = null;
    // Les intertitres de mois n'ont de sens que sur une liste rangée par date :
    // triée par montant ou par contre-partie, le mois change à chaque ligne.
    $moisSepares = $tri['cle'] === '' || $tri['cle'] === 'date';
    foreach ($ecritures as $ecr): $neg = (float) $ecr['montant'] < 0;
        $moisCle = substr((string) $ecr['date_op'], 0, 7);
        if ($moisSepares && $moisCle !== $prevMois):
            $prevMois = $moisCle;
    ?>
        <tr class="ecr-mois-sep"><td colspan="<?= $nbCols ?>">
            <?= e(mois_nom((int) substr($moisCle, 5, 2))) . ' ' . substr($moisCle, 0, 4) ?>
        </td></tr>
    <?php endif; ?>
        <?php
        $isManuel = $ecr['import_id'] === null;
        $estIgnore = $ecr['plan_compte_id'] === null && ($ecr['origine_lettrage'] ?? '') === 'ignore';
        $estNonLettre = $ecr['plan_compte_id'] === null && !$estIgnore;
        $rowCatVal   = $estIgnore ? 'ignore' : ($ecr['plan_compte_id'] ?? '');
        $pid = (int) ($ecr['plan_compte_id'] ?? 0);
        $rowCatPrefix = $estIgnore ? '' : ($catPrefixById[$pid] ?? '');
        $rowCatLeaf   = $estIgnore ? 'Ne pas lettrer' : ($catLeafById[$pid] ?? '');
        ?>
        <?php // Une écriture SAISIE À LA MAIN se modifie sur place : la ligne
              // porte ses valeurs brutes en data-, et les quatre cellules
              // modifiables se nomment, pour que l'éditeur sache où poser ses
              // champs. Une écriture IMPORTÉE n'a rien de tout cela — on ne
              // retouche pas un relevé de banque. ?>
        <tr class="<?= $estNonLettre ? 'non-lettre' : '' ?><?= $estIgnore ? ' ecr-ignore' : '' ?><?= $isManuel ? ' ecr-manuelle' : '' ?>"
            <?php // ⚠️ Deux attributs seulement, et c'est délibéré : la date, le
                  // montant et le libellé sont DÉJÀ dans les cellules, l'éditeur
                  // les y relit. Les recopier ici coûtait une centaine
                  // d'octets par ligne — le libellé à lui seul en faisait
                  // soixante —, soit le quart de ce qu'on venait de rendre à
                  // cette page. Le compte, lui, s'affiche par son nom : seul
                  // son identifiant permet de présélectionner le bon. ?>
            <?php if ($isManuel && peut_ecrire('compta')): ?>data-ecr-id="<?= (int) $ecr['id'] ?>" data-compte="<?= (int) $ecr['compte_bancaire_id'] ?>"<?php endif; ?>>
            <?php if (peut_ecrire('compta')): ?><td class="col-check"><input type="checkbox" name="ids[]" value="<?= (int) $ecr['id'] ?>" form="bulkform" class="row-check" aria-label="Sélectionner l'écriture du <?= e(date('d.m.Y', strtotime((string) $ecr['date_op']))) ?>, <?= e(chf((float) $ecr['montant'])) ?>"></td><?php endif; ?>
            <td class="nowrap ecr-c-date"><?= e(date('d.m.Y', strtotime((string) $ecr['date_op']))) ?></td>
            <?php if ($compteColVisible): ?><td class="compte-cell small muted ecr-c-compte"><?= e($ecr['compte_libelle']) ?></td><?php endif; ?>
            <?php
            // Contre-partie et communication : un relevé camt.053 les porte en
            // champs structurés, et elles ne se retrouvent pas forcément dans
            // « Texte » — celui-ci peut se réduire à une référence (« 2026-10 »)
            // quand la banque remplit Ustrd. Sans cette ligne, l'écriture
            // n'affichait ni son destinataire ni sa communication, alors que
            // les deux étaient en base. Écartées si elles répètent déjà le
            // texte, ce qui est le cas des imports CSV où elles en sont
            // extraites.
            $ecrTiers = trim((string) ($ecr['tiers'] ?? ''));
            $ecrResume = resumer_texte_postfinance($ecr['texte']);
            // Sur un import CSV, resumer_texte_postfinance() met déjà le nom en
            // tête du résumé (« Jean Martin — DON ANNUEL ») : il ferait doublon
            // avec la colonne Contre-partie. On le retire du résumé, la colonne
            // le porte mieux.
            if ($ecrTiers !== '') {
                $ecrResume = preg_replace(
                    '/^' . preg_quote($ecrTiers, '/') . '\s*(?:—\s*)?/iu',
                    '',
                    $ecrResume
                ) ?: $ecrResume;
                $ecrResume = trim($ecrResume);
            }
            $ecrDetails = [];
            // Chaque morceau est jugé séparément : une communication peut
            // réunir plusieurs mentions (« 2026-10 — HoR Frais de booking »)
            // dont une seule figure déjà dans le texte. Filtrer la chaîne
            // entière les aurait toutes gardées, donc répété « 2026-10 ».
            $ecrAjoute = function (string $v) use (&$ecrDetails, $ecrResume, $ecr): void {
                $v = trim($v);
                if ($v === '' || in_array($v, $ecrDetails, true)) {
                    return;
                }
                if (stripos($ecrResume, $v) !== false || stripos((string) $ecr['texte'], $v) !== false) {
                    return;
                }
                $ecrDetails[] = $v;
            };
            foreach (explode(' — ', (string) ($ecr['communication'] ?? '')) as $ecrMorceau) {
                $ecrAjoute($ecrMorceau);
            }
            // La référence QR n'a pas sa place dans la colonne (27 chiffres),
            // mais elle mérite d'être retrouvable au survol.
            $ecrTitre = trim((string) $ecr['texte']);
            foreach ([$ecr['tiers'] ?? '', $ecr['communication'] ?? '', $ecr['reference'] ?? ''] as $v) {
                if (trim((string) $v) !== '') {
                    $ecrTitre .= "\n" . trim((string) $v);
                }
            }
            ?>
            <?php
            // Une contre-partie déduite du libellé n'a pas la fiabilité d'un
            // champ déclaré par la banque : l'infobulle le dit, plutôt qu'un
            // signe visuel qui alourdirait une colonne déjà discrète.
            $ecrTiersTitre = match ((string) ($ecr['tiers_source'] ?? '')) {
                'camt'  => $ecrTiers . ' — contre-partie déclarée par le relevé',
                'texte' => $ecrTiers . ' — déduite du libellé',
                'csv'   => $ecrTiers . ' — reconnue dans le libellé de l\'export',
                'compte' => $ecrTiers . ' — établissement du compte bancaire (frais)',
                default => $ecrTiers,
            };
            ?>
            <td class="tiers-cell" title="<?= e($ecrTiersTitre) ?>"><?= $ecrTiers !== '' ? e($ecrTiers) : '<span class="muted">—</span>' ?></td>
            <td class="texte-cell ecr-c-texte" title="<?= e($ecrTitre) ?>">
                <?php // Facture émise et fiche de salaire se montrent d'ordinaire
                      // dans la colonne « Pièce », qui dit la même chose et mène
                      // au même endroit. Ces deux icônes-ci ne servent donc que
                      // quand cette colonne n'est pas là — module « Pièces »
                      // éteint, ou compte sans accès —, sans quoi le lien vers
                      // la facture disparaîtrait pour qui ne fait que les comptes. ?>
                <?php if (!$piecesOk): ?>
                    <?php if (!empty($ecr['facture_id'])): ?>
                        <a class="ecr-facture-lien" href="<?= e(url_avec_retour('?p=facture&id=' . (int) $ecr['facture_id'], 'compta_ecritures')) ?>" title="Voir la facture liée"><?= icon('receipt-swiss-franc') ?></a>
                    <?php endif; ?>
                    <?php if (!empty($ecr['fiche_id'])): ?>
                        <a class="ecr-facture-lien" href="<?= e(url_avec_retour('?p=fiche&id=' . (int) $ecr['fiche_id'], 'compta_ecritures')) ?>" title="Voir la fiche de salaire liée"><?= icon('file-text') ?></a>
                    <?php endif; ?>
                <?php endif; ?>
                <span class="texte-cell-txt" data-summary="<?= e($ecrResume) ?>"><?= e($ecrResume) ?></span>
                <?php if ($ecrDetails): ?>
                    <span class="ecr-details"><?= e(implode(' · ', $ecrDetails)) ?></span>
                <?php endif; ?>
            </td>
            <td class="num ecr-c-montant <?= $neg ? 'montant-neg' : 'montant-pos' ?>"><?= chf((float) $ecr['montant']) ?></td>
            <?php // ⚠️ La cellule ne porte QUE ce qu'elle montre. Le formulaire, le
                  // jeton, l'identifiant et le champ de saisie vivent en un seul
                  // exemplaire au pied de la page (_compta_ecritures_panneaux.php) et
                  // viennent se poser ici au clic. L'identifiant de l'écriture et sa
                  // catégorie courante voyagent en data- : c'est tout ce dont
                  // l'éditeur partagé a besoin pour se brancher.
                  //
                  // Une ligne jamais lettrée n'affiche qu'un « + » : le même geste,
                  // et la même pastille à contour pointillé, que l'ajout d'une
                  // étiquette sur ?p=structures (.tag-ajouter-btn). Un champ de la
                  // largeur de la colonne, répété sur toutes les lignes non
                  // lettrées, pesait visuellement autant que les vraies valeurs à
                  // côté — alors qu'il n'est qu'une place vide qui en attend une. ?>
            <td class="cat-cell" data-ecr-id="<?= (int) $ecr['id'] ?>" data-cat-val="<?= e($rowCatVal) ?>" data-cat-prefix="<?= e($rowCatPrefix) ?>">
                <?php if (!peut_ecrire('compta')): ?>
                    <?php if ($rowCatPrefix !== ''): ?><span class="row-field-prefix"><?= e($rowCatPrefix) ?></span><?php endif; ?>
                    <span><?= $rowCatVal !== '' ? e($rowCatLeaf) : '<span class="muted">— à lettrer —</span>' ?></span>
                <?php elseif ($rowCatVal !== ''): ?>
                <div class="row-field-disp">
                    <span class="row-field-txt"><?php if ($rowCatPrefix !== ''): ?><span class="row-field-prefix"><?= e($rowCatPrefix) ?></span><?php endif; ?><span class="row-field-leaf"><?= e($rowCatLeaf) ?></span></span>
                    <button type="button" class="row-edit-btn" title="Modifier" aria-label="Modifier cette écriture"><?= icon('pencil') ?></button>
                </div>
                <?php else: ?>
                <button type="button" class="badge cat-ajouter-btn" title="Lettrer cette écriture" aria-label="Lettrer cette écriture">+</button>
                <?php endif; ?>
            </td>
            <?php if ($axes):
                $vents   = $ventilationsParEcr[(int) $ecr['id']] ?? [];
                $nVents  = count($vents);
                $dispTxt = implode(' / ', array_map(fn($v) => $v['code'] !== '' ? e($v['code']) : e($v['libelle']), $vents));
            ?>
            <td class="axe-cell"
                data-ecr-id="<?= (int) $ecr['id'] ?>"
                data-ecr-montant="<?= (float) $ecr['montant'] ?>"
                data-ventilations="<?= e(json_encode(array_values($vents), JSON_UNESCAPED_UNICODE)) ?>">
                <div class="axe-disp">
                    <?php if (!peut_ecrire('analytique')): ?>
                        <span class="axe-disp-txt muted small"><?= $dispTxt !== '' ? $dispTxt : '—' ?></span>
                    <?php elseif ($nVents === 0): ?>
                        <?php // Vide : le script le remplit au chargement depuis la liste
                              // des axes qu'il porte déjà (buildInlineSel). Il était rendu
                              // ici avec toutes ses options, répétées à chaque ligne non
                              // ventilée — et il n'a jamais fonctionné sans JavaScript,
                              // n'ayant ni name ni formulaire. ?>
                        <select class="axe-inline-sel" data-vide title="Axe analytique"></select>
                        <button type="button" class="row-edit-btn axe-add-btn" title="Ventilation multi-axe" aria-label="Ventiler sur plusieurs axes"><?= icon('plus') ?></button>
                    <?php elseif ($nVents === 1): ?>
                        <span class="axe-disp-txt muted small"><?= $dispTxt ?></span>
                        <button type="button" class="row-edit-btn axe-edit-btn" title="Modifier l'axe" aria-label="Modifier l'axe analytique"><?= icon('pencil') ?></button>
                        <button type="button" class="row-edit-btn axe-add-btn" title="Ventilation multi-axe" aria-label="Ventiler sur plusieurs axes"><?= icon('plus') ?></button>
                    <?php else: ?>
                        <span class="axe-disp-txt muted small"><?= $dispTxt ?></span>
                        <button type="button" class="row-edit-btn axe-edit-btn" title="Modifier la ventilation" aria-label="Modifier la ventilation analytique"><?= icon('pencil') ?></button>
                    <?php endif; ?>
                </div>
            </td>
            <?php endif; ?>
            <?php if ($piecesOk): $etatPiece = ecriture_piece_etat($ecr); ?>
            <td class="col-piece piece-cell">
                <?php if ($etatPiece === 'manquante'): ?>
                    <?php if (peut_ecrire('pieces')): ?>
                        <?php // Un LIEN par ligne, et non le menu des cinq types : ce
                              // menu pesait 1,5 Ko par écriture, soit 40 % de la
                              // page, pour un seul ouvert à la fois. Le script le
                              // substitue au lien au clic, depuis le gabarit unique
                              // posé sous le tableau (tpl-pieces-ecriture). Sans
                              // script, le lien ouvre le formulaire en type
                              // « Autre », le seul dont le sens se choisit : la
                              // pièce se dépose quand même. ?>
                        <a class="row-edit-btn piece-lier-btn" data-pieces-menu
                           href="?p=piece_form&amp;type=autre&amp;depuis=compta_ecritures&amp;ecriture_id=<?= (int) $ecr['id'] ?>"
                           title="Joindre une pièce à cette écriture" aria-label="Joindre une pièce à cette écriture"><?= icon('paperclip') ?></a>
                    <?php endif; ?>
                <?php else: ?>
                    <?php
                    // Une dispense motivée dit SA RAISON en infobulle : « sans
                    // pièce attendue » tout court laisserait chercher pourquoi.
                    $motifDispense = trim((string) ($ecr['piece_dispense_motif'] ?? ''));
                    $nbPieces = (int) ($ecr['nb_pieces'] ?? 0);
                    $titrePiece = ECRITURE_PIECE_LIBELLES[$etatPiece]
                        . ($etatPiece === 'dispensee' && $motifDispense !== '' ? ' — ' . $motifDispense : '');
                    // Plusieurs pièces pour une écriture : le lien ouvre la
                    // première, et l'infobulle le dit — « pièce jointe » au
                    // singulier laisserait croire que l'autre n'existe pas.
                    $plusieurs = $etatPiece === 'piece' && $nbPieces > 1;
                    if ($plusieurs) { $titrePiece = $nbPieces . ' pièces jointes'; }
                    $hrefPiece = ecriture_piece_href($ecr, $etatPiece);
                    $titreLien = $titrePiece . ($hrefPiece === '' ? ''
                        : ($plusieurs ? ' — cliquer pour ouvrir la première' : ' — cliquer pour l\'ouvrir'));
                    ?>
                    <?php if ($hrefPiece !== ''): ?>
                        <a class="piece-etat piece-etat-<?= e($etatPiece) ?>" title="<?= e($titreLien) ?>"
                           aria-label="<?= e($titreLien) ?>"
                           href="<?= e(url_avec_retour($hrefPiece, 'compta_ecritures')) ?>"><?= icon(ECRITURE_PIECE_ICONES[$etatPiece]) ?></a>
                    <?php else: ?>
                        <span class="piece-etat piece-etat-<?= e($etatPiece) ?>" title="<?= e($titrePiece) ?>"
                              aria-label="<?= e($titrePiece) ?>"><?= icon(ECRITURE_PIECE_ICONES[$etatPiece]) ?></span>
                    <?php endif; ?>
                <?php endif; ?>
            </td>
            <?php endif; ?>
            <td class="actions">
                <?php if (peut_ecrire('compta')): ?>
                <?php if ($isManuel): ?>
                    <?php // Un bouton et non un lien : l'édition s'ouvre DANS la
                          // ligne, sans recharger. Le formulaire partait en tête
                          // de page, loin de l'écriture qu'il modifiait, et il
                          // fallait redescendre la retrouver. ?>
                    <button type="button" class="btn ghost btn-sm icon-only ecr-edit-btn" title="Modifier cette écriture" aria-label="Modifier cette écriture"><?= icon('pencil') ?></button>
                <?php else: ?>
                    <a class="btn ghost btn-sm icon-only" title="Créer une règle depuis cette écriture" aria-label="Créer une règle depuis cette écriture"
                       href="?p=compta_regles&motif=<?= urlencode($ecr['texte']) ?>&compte=<?= (int) $ecr['compte_bancaire_id'] ?>"><?= icon('pencil-sparkles') ?></a>
                <?php endif; ?>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php endif; ?>
    <?= compacter_cellules((string) ob_get_clean()) ?>
    </tbody>
</table>
</div>
<?php if ($ecritures): ?><?php require __DIR__ . '/' . ($modeClient ? '_pagination_client.php' : '_pagination.php'); ?><?php endif; ?>
</div></div>

<?php require __DIR__ . '/_compta_ecritures_panneaux.php'; ?>

<?php require __DIR__ . '/_compta_ecritures_script.php'; ?>
<?php endif; ?>
