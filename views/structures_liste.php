<?php /** @var array $structures */ /** @var string $recherche */ /** @var bool $modeClient */ /** @var array $categorieId */
/** @var array $pays */ /** @var array $departementCanton */ /** @var array $tagId */ /** @var array $statut */
/** @var array $categoriesPourSelect */ /** @var array $regionsDispo */ /** @var array $tagsDispo */
/** @var array $campagnesParStructure */ /** @var array $campagnesDispo */ /** @var array $campagneId */
/** @var string $pgRoute */ /** @var array $pgParams */ /** @var int $pgPage */ /** @var int $pgTaille */ /** @var int $pgTotal */
/** @var ?int $bulkCount */ /** @var bool $okAnnule */ /** @var int $structBloquees */
/** @var ?int $tagBulk */ /** @var string $tagBulkAction */ /** @var string $tagBulkNom */
/** @var string $vue */ /** @var array $cartePoints */ /** @var int $carteVillesManquantes */
/** @var ?int $lieuJaugeMin */ /** @var ?int $lieuJaugeMax */
/** @var int $lieuMoisEvenement */ /** @var int $lieuMoisProg */
/** @var bool $nonLocalises */ /** @var array $avecEvenements */ /** @var string $region */
/** @var array $majPeriode */ /** @var array $contactPeriode */
// Liens des onglets Liste/Carte : mêmes filtres actifs, seule la vue change
// (voir views/lieux_liste.php pour le même principe).
$qsSansVue = $_GET;
unset($qsSansVue['p'], $qsSansVue['vue'], $qsSansVue['geocode']);
$lienVue = fn (string $v) => '?p=structures&' . http_build_query($qsSansVue + ['vue' => $v]);
// Lien pour quitter le filtre « non localisées » (venu de la vue carte) sans
// perdre les autres filtres actifs — voir views/lieux_liste.php.
$qsSansNonLocalises = $_GET;
unset($qsSansNonLocalises['non_localises']);
$lienQuitterNonLocalises = '?' . http_build_query($qsSansNonLocalises);
// Même chose pour le filtre « région », posé par les liens de
// ?p=parametres_pays : il n'a pas d'entonnoir dans l'en-tête, donc rien ne le
// signalerait sans ce bandeau ni ne permettrait de le quitter.
$qsSansRegion = $_GET;
unset($qsSansRegion['region']);
$lienQuitterRegion = '?' . http_build_query($qsSansRegion);

// Filtres de colonne (EXPÉRIMENTAL, même mécanique que ?p=fiches — voir
// filtre_colonne_html()/filtre_colonne_actifs_html() dans lib/helpers.php) :
// Statut/Tags/Catégorie/Flag/Avec-événements, à la place des anciens <select>
// de la toolbar. Colonne Ville : porte à la fois Pays et Département/canton
// (deux entonnoirs séparés, un composant par filtre). Jauge/mois restent des
// champs scalaires dans « Plus de filtres », non concernés par ce filtrage.
// Étiquettes : droit propre au module booking, évalué une fois plutôt qu'à
// chaque étiquette de chaque ligne (peut_ecrire() interroge les permissions).
// Déclaré ICI, avant $tagActions plus bas, qui en dépend.
$peutEcrireTags = peut_ecrire('booking');

// Renommage / suppression d'une étiquette depuis son propre filtre : le crayon
// change le libellé en champ de saisie, et se change lui-même en enregistrer /
// supprimer / annuler (voir lassoInitTagGerer(), assets/app.js). data-nb porte
// le nombre de structures concernées, pour l'annoncer avant de supprimer — il
// est déjà compté par la requête, inutile d'aller le rechercher au moment du
// clic. Le champ de saisie n'a pas de name : le panneau de filtre est un
// <form method="get">, un name y ajouterait un paramètre à l'URL de filtrage.
// Propre à cette page : ailleurs, le filtre d'étiquettes ne fait que filtrer.
$tagActions = [];
if ($peutEcrireTags) {
    foreach ($tagsDispo as $t) {
        $tid = (int) $t['id'];
        $tagActions[$tid] = '<span class="tag-gerer" data-tag="' . $tid . '" data-nb="' . (int) ($t['nb'] ?? 0) . '">'
            . '<input type="text" class="tag-gerer-nom" value="' . e((string) $t['nom']) . '" aria-label="Nom de l\'étiquette" hidden>'
            . '<button type="button" class="tag-gerer-btn tag-gerer-crayon" title="Renommer" aria-label="Renommer l\'étiquette">' . icon('pencil') . '</button>'
            . '<button type="button" class="tag-gerer-btn tag-gerer-ok" title="Enregistrer" aria-label="Enregistrer le nom" hidden>' . icon('save') . '</button>'
            . '<button type="button" class="tag-gerer-btn tag-gerer-suppr" title="Supprimer" aria-label="Supprimer l\'étiquette" hidden>' . icon('trash') . '</button>'
            . '<button type="button" class="tag-gerer-btn tag-gerer-annuler" title="Annuler" aria-label="Annuler" hidden>' . icon('x') . '</button>'
            . '</span>';
    }
}

// Les entonnoirs sont partagés avec le suivi d'une campagne : ils vivent dans
// views/_structures_filtres.php. Cette page y ajoute ce qui n'est qu'à elle —
// les actions de renommage sur le filtre d'étiquettes, les filtres d'appoint
// « non localisées » et « région », et les liens Liste/Carte.
$sfPage = 'structures';
$sfVals = ['statut' => $statut, 'categorieId' => $categorieId, 'pays' => $pays,
    'departementCanton' => $departementCanton, 'tagId' => $tagId, 'campagneId' => $campagneId,
    'avecEvenements' => $avecEvenements, 'contactPeriode' => $contactPeriode, 'majPeriode' => $majPeriode];
$sfSources = ['categoriesPourSelect' => $categoriesPourSelect, 'tagsDispo' => $tagsDispo,
    'regionsDispo' => $regionsDispo, 'campagnesDispo' => $campagnesDispo];
// 'depuis' est reporté par chaque panneau : Structures est partagée par 3
// groupes de nav (booking/facturation/evenements) et sans lui, soumettre un
// panneau — un simple <form method="get"> qui ne connaît que ses propres
// champs — perdait ?depuis=… en route, faisant retomber le rail/bandeau sur son
// groupe par défaut (voir nav_groupe_actif()).
$sfAutresParams = ['q' => $recherche, 'depuis' => (string) ($_GET['depuis'] ?? '')];
// La vue carte doit se reconduire elle-même dans chaque panneau, la liste non.
$sfExtra = $vue === 'carte' ? ['vue' => 'carte'] : [];
$sfTagActions = $tagActions;
$sfReinitVides = ['lieu_jauge_min', 'lieu_jauge_max', 'lieu_mois_evenement', 'lieu_mois_prog', 'non_localises', 'region'];
$sfActifSupp = $nonLocalises || $region !== '';
require __DIR__ . '/_structures_filtres.php';
$statutLabels = $sfLabels['statut'];
$tagLabels = $sfLabels['tag'];
$categorieLabels = $sfLabels['categorie'];
$paysLabels = $sfLabels['pays'];
$departementCantonLabels = $sfLabels['departementCanton'];
$avecEvenementsLabels = $sfLabels['avecEvenements'];
$periodeLabels = $sfLabels['periode'];
$autresFiltres = $sfAutres;
$structFiltreActif = $sfActif;
$peutEcrireStruct = peut_ecrire('facturation') || peut_ecrire('booking');
?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php
// Structures est partagée par 3 groupes de nav (booking/facturation/evenements,
// voir nav_groupe_actif()) — reporté sur les liens vers une structure pour que
// le rail/bandeau y reste dans le même groupe de provenance une fois dessus.
$suffixeDepuis = $ntCle !== null ? '&depuis=' . $ntCle : '';

// Deux colonnes ne concernent pas tous les visiteurs de cette liste, qui est
// partagée par trois modules : arrivé du booking, on ne vient pas compter des
// factures ; arrivé de la facturation, la date de dernier contact n'est pas le
// sujet. On les masque selon la PROVENANCE explicite (?depuis=…) et non selon
// le groupe de navigation résolu : sans provenance — lien direct, favori,
// retour d'une fiche — la liste doit tout montrer plutôt que deviner.
$depuisNav = (string) ($_GET['depuis'] ?? '');
$montreFactures = $depuisNav !== 'booking';
$montreContacte = $depuisNav !== 'facturation';
?>
<?php $actionUrl = '?p=structures'; require __DIR__ . '/_bulk_undo_flash.php'; ?>
<?= filtre_non_localises_flash_html($nonLocalises, 'structures', $lienQuitterNonLocalises) ?>
<?php if ($region !== ''): ?><p class="warn flash">Filtre : structures de la région « <?= e($region) ?> ». <a href="<?= e($lienQuitterRegion) ?>">Quitter ce filtre</a></p><?php endif; ?>
<?php if ($tagBulk !== null): ?>
<p class="ok flash">
    <?php if ($tagBulk > 0): ?>
        Étiquette « <?= e($tagBulkNom) ?> » <?= $tagBulkAction === 'retrait' ? 'retirée de' : 'ajoutée à' ?> <strong><?= (int) $tagBulk ?></strong> structure(s).
    <?php else: ?>
        Aucune structure modifiée (étiquette <?= $tagBulkAction === 'retrait' ? 'déjà absente' : 'déjà présente' ?>).
    <?php endif; ?>
</p>
<?php endif; ?>
<?php if ($structBloquees): ?><p class="err flash"><?= (int) $structBloquees ?> structure(s) non supprimée(s) : des factures y sont rattachées.</p><?php endif; ?>
<?php $ntBandClasse = $vue === 'carte' ? 'carte-header' : null; require __DIR__ . '/_page_head_band.php'; ?>

<div class="module-content"><div class="module-content-inner">
    <?php // toolbar-carte-panneau : la vue carte de ?p=structures range ses filtres
          // dans le panneau « Filtres » et n'a donc plus besoin de rétrécir la
          // recherche pour leur faire de la place — contrairement à
          // ?p=evenements_liste, qui les affiche toujours à plat. ?>
    <div class="toolbar toolbar-opaque<?= $vue === 'carte' ? ' toolbar-carte toolbar-carte-panneau' : '' ?>">
        <form method="get" class="filters">
            <input type="hidden" name="p" value="structures">
            <input type="hidden" name="vue" value="<?= e($vue) ?>">
            <?php if (($_GET['depuis'] ?? '') !== ''): ?><input type="hidden" name="depuis" value="<?= e((string) $_GET['depuis']) ?>"><?php endif; ?>
            <?= champ_recherche(['id' => 'structures-search', 'name' => 'q', 'valeur' => $recherche, 'submit' => true]) ?>
            <?php $lieuFiltresActifs = $lieuJaugeMin !== null || $lieuJaugeMax !== null || $lieuMoisEvenement || $lieuMoisProg; ?>
            <details class="filters-more" <?= $lieuFiltresActifs ? 'open' : '' ?>>
                <summary title="Plus de filtres" aria-label="Plus de filtres"><?= icon('funnel-plus') ?></summary>
                <div class="filters-more-body"><?php require __DIR__ . '/_structures_filtres_lieu.php'; ?></div>
            </details>
        </form>
        <?php
        // Bande des filtres actifs du panneau « Filtres » (mobile). Mêmes
        // pastilles que ?p=mailing_campagne — .filtres-ciblage-actifs /
        // .col-th-actif, chacune avec sa croix de retrait — pour que les deux
        // écrans qui filtrent des structures se lisent pareil. En vue bureau,
        // ces pastilles vivent déjà sous leur en-tête de colonne
        // (filtre_colonne_actifs_html() dans le <thead>) : la bande n'a de sens
        // que là où le <thead> est masqué.
        // Les huit pastilles des filtres de colonne sont déjà rendues par
        // views/_structures_filtres.php ($sfActifs) : cette page n'ajoute que
        // les siennes, jauge et mois, qui ne sont pas des cases à cocher.
        $actifsFiltres = $sfActifs;
        // Jauge et mois ne sont pas des cases à cocher : une pastille par
        // groupe, dont le lien de retrait remet le ou les champs à vide.
        // filtre_persistant() écrase la session dès que la clé est présente en
        // GET, même vide — c'est ce qui rend le retrait effectif.
        // $autresFiltres('') ne retire rien : il reporte tous les autres
        // filtres actifs sur le lien.
        $pilleGroupe = fn (string $label, array $vides): string
            => filtre_pille_groupe_html('structures', $label, $autresFiltres('') + $vides);
        if ($lieuJaugeMin !== null || $lieuJaugeMax !== null) {
            $actifsFiltres .= $pilleGroupe(
                'Jauge : ' . ($lieuJaugeMin !== null ? (int) $lieuJaugeMin : '…') . '–' . ($lieuJaugeMax !== null ? (int) $lieuJaugeMax : '…'),
                ['lieu_jauge_min' => '', 'lieu_jauge_max' => '']
            );
        }
        if ($lieuMoisEvenement) {
            $actifsFiltres .= $pilleGroupe("Mois d'événement : " . mois_nom($lieuMoisEvenement), ['lieu_mois_evenement' => '0']);
        }
        if ($lieuMoisProg) {
            $actifsFiltres .= $pilleGroupe('Mois de programmation : ' . mois_nom($lieuMoisProg), ['lieu_mois_prog' => '0']);
        }
        ?>
        <?php
        // Filtres de colonne hors tableau. Deux vues en ont besoin : la carte,
        // qui n'a pas de <thead> où les accrocher, et la liste en mode mobile,
        // dont le <thead> est masqué par la mise en cartes (@media 700px,
        // assets/app.css). Ce sont les MÊMES filtres que ceux du tableau, nommés
        // cette fois : views/_structures_filtres.php les a déjà rendus dans
        // $sfColonnes, avec le paramètre de vue qu'il faut ($sfExtra — la carte
        // doit se reconduire elle-même, la liste non). Les réécrire ici, c'était
        // huit appels en double sur la même page.
        $filtresColonnes = $sfColonnes;
        ?>
        <?php // Vue carte comme vue liste : un seul bouton « Filtres » qui ouvre
              // le panneau. La carte n'a aucun en-tête de colonne où poser les
              // entonnoirs, et la liste les masque en mode mini-cartes — le même
              // panneau sert donc les deux, à ceci près qu'en carte il s'affiche
              // à toute largeur (.filtres-carte).
              // Jauge et mois ne sont pas des cases à cocher : leur formulaire
              // entre dans le panneau en bloc supplémentaire. ?>
        <?php ob_start(); ?>
                <form method="get" class="filters filters-more-body">
                    <input type="hidden" name="p" value="structures">
                    <input type="hidden" name="vue" value="<?= e($vue) ?>">
                    <?php if (($_GET['depuis'] ?? '') !== ''): ?><input type="hidden" name="depuis" value="<?= e((string) $_GET['depuis']) ?>"><?php endif; ?>
                    <?php if ($recherche !== ''): ?><input type="hidden" name="q" value="<?= e($recherche) ?>"><?php endif; ?>
                    <?php require __DIR__ . '/_structures_filtres_lieu.php'; ?>
                </form>
        <?php
        $fmColonnes = $filtresColonnes;
        $fmActifs = $actifsFiltres;
        $fmExtra = ob_get_clean();
        $fmClasse = $vue === 'carte' ? 'filtres-carte' : '';
        require __DIR__ . '/_filtres_mobile.php';
        ?>
        <div class="head-actions">
            <div class="seg-picker" role="radiogroup" aria-label="Affichage">
                <a href="<?= e($lienVue('liste')) ?>" class="seg-btn <?= $vue === 'liste' ? 'on' : '' ?>" role="radio" aria-checked="<?= $vue === 'liste' ? 'true' : 'false' ?>" title="Liste"><?= icon('rows-3') ?></a>
                <a href="<?= e($lienVue('carte')) ?>" class="seg-btn <?= $vue === 'carte' ? 'on' : '' ?>" role="radio" aria-checked="<?= $vue === 'carte' ? 'true' : 'false' ?>" title="Carte"><?= icon('map') ?></a>
            </div>
            <?php if ($peutEcrireStruct): ?>
            <a class="btn" href="?p=structure"><?= icon('house-plus') ?><span class="lbl"> Nouvelle structure</span></a>
            <?php endif; ?>
        </div>
    </div>

<?php if (peut_ecrire('booking')): ?>
<?php // Exemplaire unique du formulaire d'ajout d'étiquette : déplacé dans la
      // cellule de la ligne cliquée à l'ouverture, son structure_id renseigné à
      // ce moment-là. Hors du tableau au repos, pour ne peser qu'une fois. ?>
<form method="post" action="?p=structure_tag_ajouter" class="linked-add tag-ajouter-ligne" id="tag-ajouter-form-liste" hidden>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="structure_id" value="">
    <input type="hidden" name="retour" value="structures">
    <div class="cat-search tag-search">
        <input type="text" name="nom" class="cat-search-input" placeholder="Étiquette…" autocomplete="off">
        <ul class="cat-search-list" hidden role="listbox">
            <?php foreach ($tagsDispo as $t): ?><li><?= e($t['nom']) ?></li><?php endforeach; ?>
        </ul>
    </div>
    <?php // Ce bouton ne sert qu'à CRÉER : choisir une étiquette existante dans
          // la liste l'enregistre au clic (voir lassoInitTagAjout()). D'où le
          // libellé, qui ne promet plus un simple « Ajouter ». ?>
    <button type="submit" class="btn ghost btn-sm icon-only" title="Créer cette étiquette" aria-label="Créer cette étiquette et l'ajouter"><?= icon('plus') ?></button>
    <button type="button" class="btn ghost btn-sm icon-only tag-ajouter-annuler" title="Annuler" aria-label="Annuler"><?= icon('x') ?></button>
</form>
<?php endif; ?>

<?php if ($peutEcrireTags && $campagnesDispo): ?>
<?php // Exemplaire unique, comme le formulaire d'étiquette juste au-dessus :
      // déplacé dans la cellule de la ligne dont on clique le « + ». Une liste
      // fermée — on rattache à une campagne existante, on n'en crée pas d'ici —
      // donc un menu déroulant plutôt qu'un champ à suggestions. ?>
<form method="post" action="?p=structure_campagne" class="linked-add campagne-ajouter-ligne" id="campagne-ajouter-form" hidden>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="structure_id" value="">
    <input type="hidden" name="retour" value="structures">
    <select name="campagne_id" aria-label="Campagne">
        <option value="">— Choisir une campagne —</option>
        <?php foreach ($campagnesDispo as $c): ?>
        <option value="<?= (int) $c['id'] ?>"><?= e($c['nom']) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn ghost btn-sm icon-only" title="Ajouter à cette campagne" aria-label="Ajouter à cette campagne"><?= icon('plus') ?></button>
    <button type="button" class="btn ghost btn-sm icon-only campagne-ajouter-annuler" title="Annuler" aria-label="Annuler"><?= icon('x') ?></button>
</form>
<?php endif; ?>

<?php if ($vue === 'carte'): ?>
    <?php require __DIR__ . '/_structures_carte.php'; ?>
<?php else: ?>
<?php $filtresActifs = $recherche !== '' || $categorieId || $pays || $departementCanton || $tagId || $lieuFiltresActifs || $avecEvenements; ?>
<?php if ($peutEcrireStruct): ?>
<div class="bulk-bar" id="bulk-bar" hidden>
    <form method="post" id="bulkform" action="?p=structures">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <?php // « section » est la valeur que le serveur commute (voir
              // route_structures()). Elle n'est plus portée par un <select> mais par
              // ce champ caché, alimenté par le JS depuis l'un OU l'autre des deux
              // menus : les sept modifications de champ tenaient auparavant sept
              // lignes dans une liste qui en comptait douze, ce qui la rendait longue
              // à parcourir pour trouver « Supprimer » ou « Fusionner ». ?>
        <input type="hidden" name="section" id="bulk-section" value="">

        <select id="bulk-action" class="inline-year-select" aria-label="Action groupée">
            <option value="">— Choisir une action —</option>
            <option value="modifier">Modifier…</option>
            <?php if ($tagsDispo || module_actif('booking')): ?>
            <option value="tag_ajouter">Ajouter une étiquette</option>
            <option value="tag_retirer">Retirer une étiquette</option>
            <?php endif; ?>
            <option value="fusionner">Fusionner</option>
            <option value="delete">Supprimer</option>
        </select>

        <?php // Second menu, révélé par « Modifier… » : le champ à changer. Les
              // valeurs sont exactement celles attendues par le serveur, inchangées. ?>
        <select id="bulk-champ" class="inline-year-select" aria-label="Champ à modifier" hidden>
            <option value="">— Choisir un champ —</option>
            <option value="statut">Statut</option>
            <option value="ville">Ville</option>
            <option value="departement_canton">Département / canton</option>
            <option value="pays">Pays</option>
            <option value="categorie">Catégorie</option>
            <option value="via">Connu via</option>
        </select>

        <span class="bulk-field" data-for="categorie" hidden>
            <select name="bulk_categorie_id" class="inline-year-select">
                <?php foreach ($categoriesPourSelect as $cat): ?>
                    <option value="<?= (int) $cat['id'] ?>"><?= str_repeat("\u{00A0}\u{00A0}", $cat['profondeur']) ?><?= e($cat['nom']) ?></option>
                <?php endforeach; ?>
            </select>
        </span>
        <span class="bulk-field" data-for="ville" hidden>
            <input type="text" name="bulk_ville" class="inline-year-select" placeholder="Nouvelle ville">
        </span>
        <span class="bulk-field" data-for="departement_canton" hidden>
            <input type="text" name="bulk_departement_canton" class="inline-year-select" placeholder="Nouveau département / canton">
        </span>
        <span class="bulk-field" data-for="pays" hidden>
            <select name="bulk_pays" class="inline-year-select"><?= pays_options_nom('') ?></select>
        </span>
        <span class="bulk-field" data-for="via" hidden>
            <input type="text" name="bulk_via" class="inline-year-select" placeholder="Nouveau « via »">
        </span>
        <?php if ($tagsDispo || module_actif('booking')): ?>
        <span class="bulk-field" data-for="tag_ajouter" hidden>
            <div class="cat-search tag-search">
                <input type="text" name="bulk_tag_ajouter" class="cat-search-input inline-year-select"
                       placeholder="Étiquette à ajouter" autocomplete="off">
                <ul class="cat-search-list" hidden role="listbox">
                    <?php foreach ($tagsDispo as $t): ?><li><?= e($t['nom']) ?></li><?php endforeach; ?>
                </ul>
            </div>
        </span>
        <span class="bulk-field" data-for="tag_retirer" hidden>
            <select name="bulk_tag_retirer" class="inline-year-select">
                <?php foreach ($tagsDispo as $t): ?>
                    <option value="<?= (int) $t['id'] ?>"><?= e($t['nom']) ?></option>
                <?php endforeach; ?>
            </select>
        </span>
        <?php endif; ?>
        <span class="bulk-field" data-for="statut" hidden>
            <select name="bulk_statut" class="inline-year-select">
                <?php foreach (STRUCTURE_STATUTS as $s): ?>
                    <option value="<?= e($s) ?>"><?= e(structure_statut_libelle($s)) ?></option>
                <?php endforeach; ?>
            </select>
        </span>

        <?php // Bouton en icône seule : le libellé « Modifier la sélection » prenait
              // toute la largeur d'un écran de téléphone, au point de réduire le champ
              // voisin à rien. Les TROIS états sont rendus ici plutôt qu'injectés en
              // JS : le sprite d'icônes ne contient que ce qui a été rendu côté
              // serveur, une icône seulement référencée depuis un script serait
              // introuvable. Le script se contente de basculer leur visibilité et le
              // libellé accessible. ?>
        <button type="submit" class="btn icon-only" id="bulk-submit" disabled
                title="Modifier la sélection" aria-label="Modifier la sélection">
            <span data-bulk-icone="modifier"><?= icon('save') ?></span>
            <span data-bulk-icone="supprimer" hidden><?= icon('trash') ?></span>
            <span data-bulk-icone="fusionner" hidden><?= icon('merge') ?></span>
        </button>
    </form>
</div>
<?php endif; ?>
<?php
// Le tableau lui-même est partagé avec la sélection d'une campagne : il vit dans
// views/_structures_table.php. Cette page lui passe ses entonnoirs, ses cases à
// cocher d'action groupée et ses liens ; le reste — colonnes, ordre, rendu des
// cellules — n'est écrit qu'une fois, là-bas.
$stStructures = $structures;
$stVide = $filtresActifs ? 'Aucune structure ne correspond à cette recherche.' : "Aucune structure pour l'instant. Commencez par en ajouter une.";
$stCheck = $peutEcrireStruct ? ['name' => 'ids[]', 'form' => 'bulkform', 'classe' => 'row-check', 'tout' => 'check-all'] : null;
// Les entonnoirs et le bouton de retrait sont déjà rendus par
// views/_structures_filtres.php, colonne par colonne : les réécrire ici, c'était
// la même liste une troisième fois sur la même page — et la colonne
// « Campagnes » y manquait, faute d'avoir été ajoutée aux trois endroits.
$stReinit = $sfReinit;
$stFiltres = $sfFiltres;
$stHref = fn (array $d): string => '?p=structure&id=' . (int) $d['id'] . $suffixeDepuis . suffixe_retour_liste($recherche, $pgPage);
$stSuffixeDepuis = $suffixeDepuis;
$stTagsActifs = $peutEcrireTags;
$stMontreContacte = $montreContacte;
$stMontreFactures = $montreFactures;
$stMontreEvenements = module_actif('evenements');
$stNbEvenements = $nbEvenements;
// Colonne « Campagnes » : ici seulement. Sur la sélection ou le suivi d'une
// campagne, on est déjà dans l'une d'elles — la colonne n'y apprendrait rien.
$stCampagnes = $campagnesDispo ? $campagnesParStructure : null;
require __DIR__ . '/_structures_table.php';
?>
<?php if ($structures): ?><?php require __DIR__ . '/' . ($modeClient ? '_pagination_client.php' : '_pagination.php'); ?><?php endif; ?>
<?php endif; ?>
</div></div>
<script nonce="<?= e(csp_nonce()) ?>">
<?php if ($modeClient && $vue !== 'carte'): ?>
lassoListeClient({
    tableSelector: '.list-wide',
    searchInputSelector: '#structures-search',
});
<?php else: ?>
lassoRechercheServeur(document.getElementById('structures-search'));
<?php endif; ?>
lassoInitTagSuggest();

(function () {
    const bulkBar = document.getElementById('bulk-bar');
    if (!bulkBar) return;
    function updateBulkBar() {
        bulkBar.hidden = document.querySelectorAll('.row-check:checked').length === 0;
    }
    const all = document.getElementById('check-all');
    all.addEventListener('change', () => {
        document.querySelectorAll('.row-check').forEach(c => {
            if (c.closest('tr').style.display !== 'none') c.checked = all.checked;
        });
        updateBulkBar();
    });
    document.querySelectorAll('.row-check').forEach(c => c.addEventListener('change', updateBulkBar));

    const action = document.getElementById('bulk-action');
    const submit = document.getElementById('bulk-submit');
    const fields = document.querySelectorAll('.bulk-field');
    const champ = document.getElementById('bulk-champ');
    const sectionInput = document.getElementById('bulk-section');
    function syncAction() {
        // « Modifier… » délègue le choix au second menu ; les autres actions sont
        // elles-mêmes la section. Une seule valeur part au serveur, dans le champ
        // caché — les deux <select> ne sont que des commandes d'interface.
        const enModification = action.value === 'modifier';
        champ.hidden = !enModification;
        if (!enModification) champ.value = '';
        const section = enModification ? champ.value : action.value;
        sectionInput.value = section;

        fields.forEach(f => { f.hidden = f.dataset.for !== section; });
        submit.disabled = section === '';
        // L'icône et le libellé accessible suivent l'action choisie. On bascule
        // des éléments déjà présents : rien n'est construit en JS, donc aucune
        // icône ne peut manquer au sprite.
        const etat = section === 'delete' ? 'supprimer'
                   : section === 'fusionner' ? 'fusionner' : 'modifier';
        const libelles = { supprimer: 'Supprimer la sélection',
                           fusionner: 'Fusionner la sélection',
                           modifier:  'Modifier la sélection' };
        submit.querySelectorAll('[data-bulk-icone]').forEach(el => {
            el.hidden = el.dataset.bulkIcone !== etat;
        });
        submit.title = libelles[etat];
        submit.setAttribute('aria-label', libelles[etat]);
        submit.classList.toggle('danger', etat === 'supprimer');
    }
    action.addEventListener('change', syncAction);
    champ.addEventListener('change', syncAction);
    syncAction();

    document.getElementById('bulkform').addEventListener('submit', e => {
        const n = document.querySelectorAll('.row-check:checked').length;
        if (sectionInput.value === 'delete' && !confirm('Supprimer ' + n + ' structure(s) ? Cette action est irréversible.')) {
            e.preventDefault();
        } else if (sectionInput.value === 'fusionner' && n < 2) {
            alert('Sélectionnez au moins deux structures à fusionner.');
            e.preventDefault();
        }
    });
})();
</script>
