<?php /** @var array $structures */ /** @var string $recherche */ /** @var bool $modeClient */ /** @var array $categorieId */
/** @var array $pays */ /** @var array $departementCanton */ /** @var array $tagId */ /** @var array $statut */
/** @var array $categoriesPourSelect */ /** @var array $regionsDispo */ /** @var array $tagsDispo */
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

$statutLabels = [];
foreach (STRUCTURE_STATUTS as $s) { $statutLabels[$s] = structure_statut_libelle($s); }
$tagLabels = [];
foreach ($tagsDispo as $t) { $tagLabels[(int) $t['id']] = $t['nom']; }
// Renommage / suppression d'une étiquette depuis son propre filtre : le crayon
// change le libellé en champ de saisie, et se change lui-même en enregistrer /
// supprimer / annuler (voir lassoInitTagGerer(), assets/app.js). data-nb porte
// le nombre de structures concernées, pour l'annoncer avant de supprimer — il
// est déjà compté par la requête, inutile d'aller le rechercher au moment du
// clic. Le champ de saisie n'a pas de name : le panneau de filtre est un
// <form method="get">, un name y ajouterait un paramètre à l'URL de filtrage.
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
$categorieLabels = [];
foreach ($categoriesPourSelect as $cat) { $categorieLabels[(int) $cat['id']] = str_repeat("\u{00A0}\u{00A0}", $cat['profondeur']) . $cat['nom']; }
$paysLabels = [];
foreach (array_unique(array_merge($pays, array_column(pays_liste(), 'nom'))) as $nom) { $paysLabels[$nom] = $nom; }
$departementCantonLabels = [];
foreach (array_unique(array_merge($departementCanton, $regionsDispo)) as $r) { $departementCantonLabels[$r] = $r; }
// Le filtre de statut démarre sur « actif + contact privilégié » plutôt que
// vide (voir structures_filtres()) : c'est bien un filtre, il masque les
// structures inactives ou à ne pas contacter. Le bouton doit donc être là dès
// l'ouverture, sinon on ne pourrait pas le retirer. Après un clic, le statut
// est vide comme le reste et le bouton disparaît.
$structFiltreActif = $categorieId || $statut || $pays || $departementCanton || $tagId
    || $avecEvenements || $contactPeriode || $majPeriode || $nonLocalises || $region !== '';

$avecEvenementsLabels = ['avec' => 'Avec événements liés', 'sans' => 'Sans événement lié'];
// Mêmes tranches pour les deux colonnes de date (voir PERIODES_ANCIENNETE).
$periodeLabels = PERIODES_ANCIENNETE;
// $autresXxx : les AUTRES filtres actifs de la page (jamais celui-ci), à
// reporter en hidden inputs par chaque panneau — construits une fois depuis
// $tousFiltres plutôt qu'un littéral quasi identique par filtre. 'depuis' y
// est inclus (jamais exclu par $autresFiltres, vu qu'aucun appel n'exclut
// cette clé) : Structures est partagée par 3 groupes de nav (booking/
// facturation/evenements) et sans ça, soumettre un panneau de filtre — un
// simple <form method="get"> qui ne connaît que ses propres champs — perdait
// ?depuis=… en route, faisant retomber le rail/bandeau sur son groupe par
// défaut (voir nav_groupe_actif()) au lieu de rester dans le groupe de
// provenance.
$tousFiltres = ['categorie_id' => $categorieId, 'pays' => $pays, 'departement_canton' => $departementCanton,
    'tag_id' => $tagId, 'statut' => $statut, 'avec_evenements' => $avecEvenements, 'q' => $recherche,
    'maj_periode' => $majPeriode, 'contact_periode' => $contactPeriode,
    'depuis' => (string) ($_GET['depuis'] ?? '')];
$autresFiltres = autres_filtres_fn($tousFiltres);
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
        $actifsFiltres = ''
            . filtre_colonne_actifs_html('structures', 'statut', $statutLabels, $statut, $autresFiltres('statut'))
            . filtre_colonne_actifs_html('structures', 'categorie_id', $categorieLabels, $categorieId, $autresFiltres('categorie_id'))
            . filtre_colonne_actifs_html('structures', 'pays', $paysLabels, $pays, $autresFiltres('pays'))
            . filtre_colonne_actifs_html('structures', 'departement_canton', $departementCantonLabels, $departementCanton, $autresFiltres('departement_canton'))
            . filtre_colonne_actifs_html('structures', 'tag_id', $tagLabels, $tagId, $autresFiltres('tag_id'))
            . filtre_colonne_actifs_html('structures', 'avec_evenements', $avecEvenementsLabels, $avecEvenements, $autresFiltres('avec_evenements'))
            . filtre_colonne_actifs_html('structures', 'contact_periode', $periodeLabels, $contactPeriode, $autresFiltres('contact_periode'))
            . filtre_colonne_actifs_html('structures', 'maj_periode', $periodeLabels, $majPeriode, $autresFiltres('maj_periode'));
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
        // assets/app.css). Même bloc dans les deux cas, à un paramètre près
        // ($vueExtra : la carte doit se reconduire elle-même, la liste non) ;
        // il est posé à plat dans la toolbar pour la carte, et replié derrière
        // un bouton « Filtres » pour la liste — bouton lui-même invisible
        // au-delà de 700px, où les entonnoirs du <thead> reprennent la main.
        $vueExtra = $vue === 'carte' ? ['vue' => 'carte'] : [];
        ob_start(); ?>
            <?= filtre_colonne_html('structures', 'statut', $statutLabels, $statut, $autresFiltres('statut') + $vueExtra, 'Statut') ?>
            <?= filtre_colonne_html('structures', 'categorie_id', $categorieLabels, $categorieId, $autresFiltres('categorie_id') + $vueExtra, 'Catégorie') ?>
            <?= filtre_colonne_html('structures', 'pays', $paysLabels, $pays, $autresFiltres('pays') + $vueExtra, 'Pays') ?>
            <?= filtre_colonne_html('structures', 'departement_canton', $departementCantonLabels, $departementCanton, $autresFiltres('departement_canton') + $vueExtra, 'Département / canton') ?>
            <?php if ($tagsDispo): ?>
            <?= filtre_colonne_html('structures', 'tag_id', $tagLabels, $tagId, $autresFiltres('tag_id') + $vueExtra, 'Étiquettes', $tagActions) ?>
            <?php endif; ?>
            <?php if (module_actif('evenements')): ?>
            <?= filtre_colonne_html('structures', 'avec_evenements', $avecEvenementsLabels, $avecEvenements, $autresFiltres('avec_evenements') + $vueExtra, 'Événements') ?>
            <?php endif; ?>
            <?= filtre_colonne_html('structures', 'contact_periode', $periodeLabels, $contactPeriode, $autresFiltres('contact_periode') + $vueExtra, 'Contacté') ?>
            <?= filtre_colonne_html('structures', 'maj_periode', $periodeLabels, $majPeriode, $autresFiltres('maj_periode') + $vueExtra, 'Modifié') ?>
        <?php $filtresColonnes = ob_get_clean(); ?>
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
<?php // Une colonne de moins depuis que « Structures liées » a rejoint la colonne « Nom ».
$nbCols = 9 + (module_actif('evenements') ? 1 : 0) - ($peutEcrireStruct ? 0 : 1)
    - ($montreFactures ? 0 : 1) - ($montreContacte ? 0 : 1); ?>
<div class="table-scroll">
<table class="list list-wide liste-cartes<?= $peutEcrireStruct ? ' avec-check' : '' ?>">
    <thead><tr>
        <?php if ($peutEcrireStruct): ?><th class="col-reinit-hote col-check"><?= bouton_reinit_filtres('structures', ['categorie_id', 'statut', 'pays', 'departement_canton', 'tag_id', 'avec_evenements', 'contact_periode', 'maj_periode'], $structFiltreActif, ['lieu_jauge_min', 'lieu_jauge_max', 'lieu_mois_evenement', 'lieu_mois_prog', 'non_localises', 'region']) ?><input type="checkbox" id="check-all" aria-label="Tout cocher"></th><?php endif; ?>
        <?php // En-tête en icône plutôt qu'en mot : la colonne ne contient que des
              // icônes, et « Statut » écrit en toutes lettres y occupait deux fois
              // la largeur de son contenu. Le nom reste porté par title et
              // aria-label — au survol pour la souris, à la lecture pour les
              // lecteurs d'écran — comme les colonnes Factures et Événements. ?>
        <th class="col-petit col-statut-th">
            <span class="col-th">
                <span title="Statut" aria-label="Statut"><?= icon('circle-dot') ?></span>
                <?= filtre_colonne_html('structures', 'statut', $statutLabels, $statut, $autresFiltres('statut')) ?>
            </span>
        </th>
        <th class="col-nom">
            <span class="col-th">
                Nom
            </span>
        </th>
        <th class="col-ville">
            <span class="col-th">
                Ville
                <?= filtre_colonne_html('structures', 'pays', $paysLabels, $pays, $autresFiltres('pays')) ?>
                <?= filtre_colonne_html('structures', 'departement_canton', $departementCantonLabels, $departementCanton, $autresFiltres('departement_canton')) ?>
            </span>
        </th>
        <th class="col-categorie">
            <span class="col-th">
                Catégorie
                <?= filtre_colonne_html('structures', 'categorie_id', $categorieLabels, $categorieId, $autresFiltres('categorie_id')) ?>
            </span>
        </th>
        <th class="col-tags">
            <span class="col-th">
                Tags
                <?php if ($tagsDispo): ?><?= filtre_colonne_html('structures', 'tag_id', $tagLabels, $tagId, $autresFiltres('tag_id'), '', $tagActions) ?><?php endif; ?>
            </span>
        </th>
        <?php // Colonne masquée, mais toujours rendue : la recherche de cette liste
              // se fait EN JAVASCRIPT sur le texte des lignes (lassoListeClient(),
              // mode client jusqu'à 4000 fiches), et textContent ignore le CSS —
              // les noms de contacts restent donc trouvables sans être affichés.
              // Retirer les cellules aurait supprimé cette recherche du même coup.
              // Au-delà du seuil, c'est la requête SQL qui cherche, et elle
              // interroge structure_contacts de son côté. ?>
        <th class="col-contact">Contact</th>
        <?php if ($montreContacte): ?>
        <th class="col-petit">
            <span class="col-th">Contacté
                <?= filtre_colonne_html('structures', 'contact_periode', $periodeLabels, $contactPeriode, $autresFiltres('contact_periode')) ?>
            </span>
        </th>
        <?php endif; ?>
        <?php if ($montreFactures): ?>
        <th title="Factures liées" aria-label="Factures liées"><?= icon('receipt-swiss-franc') ?></th>
        <?php endif; ?>
        <?php if (module_actif('evenements')): ?>
        <th class="col-evenements">
            <span class="col-th">
                <span title="Événements liés" aria-label="Événements liés"><?= icon('calendar') ?></span>
                <?= filtre_colonne_html('structures', 'avec_evenements', $avecEvenementsLabels, $avecEvenements, $autresFiltres('avec_evenements')) ?>
            </span>
        </th>
        <?php endif; ?>
        <?php // « Modifié » en dernière colonne : c'est une date de service,
              // qu'on consulte rarement et jamais en premier — la reléguer en fin
              // de ligne laisse la place aux colonnes qu'on parcourt. ?>
        <th class="col-petit">
            <span class="col-th">Modifié
                <?= filtre_colonne_html('structures', 'maj_periode', $periodeLabels, $majPeriode, $autresFiltres('maj_periode')) ?>
            </span>
            </th>
    </tr></thead>
    <tbody>
    <?php // Borne du « contact récent » (moins d'un an), calculée une fois : la
      // comparer par ligne referait 2959 fois le même calcul de date. Les dates
      // sont stockées en « AAAA-MM-JJ », la comparaison de chaînes suffit. ?>
<?php $limiteContactRecent = date('Y-m-d', strtotime('-1 year')); ?>
<?php // Le corps du tableau fait 98 % du poids de cette page — 2959 lignes en
          // mode client. Il est tamponné pour en retirer l'indentation entre
          // cellules avant l'envoi (compacter_cellules(), lib/helpers.php) : elle
          // ne rend rien à l'écran et coûtait 254 octets par ligne. Le gabarit
          // ci-dessous reste donc indenté normalement, c'est la sortie qui est
          // compactée. ?>
    <?php ob_start(); ?>
    <?php if (!$structures): ?>
        <tr><td colspan="<?= $nbCols ?>" class="muted"><?= $filtresActifs ? 'Aucune structure ne correspond à cette recherche.' : "Aucune structure pour l'instant. Commencez par en ajouter une." ?></td></tr>
    <?php else: ?>
    <?php foreach ($structures as $d): ?>
        <?php $hrefLigne = '?p=structure&id=' . (int) $d['id'] . $suffixeDepuis . suffixe_retour_liste($recherche, $pgPage); ?>
        <?php // data-statut : seul point d'accroche du statut hors de sa cellule.
              // En mini-cartes (mobile), l'icône de statut est masquée et c'est la
              // BORDURE de la case à cocher qui porte la couleur ; en vue tableau,
              // c'est lui qui choisit le tracé de l'icône de statut (masque CSS,
              // voir assets/app.css) — d'où l'absence de <svg> dans la cellule.
              //
              // Pas de data-href : il recopiait à l'octet près le href du lien du
              // nom, 56 octets par ligne (15,9 Ko compressés sur 2959 lignes) pour
              // une information déjà là. go() (views/layout.php) retombe sur
              // .titre-lien quand l'attribut manque. ?>
        <tr class="row-link <?= $d['statut'] === 'inactif' ? 'inactif' : '' ?>" data-statut="<?= e((string) $d['statut']) ?>" tabindex="0" role="link">
            <?php if ($peutEcrireStruct): ?><td class="col-check"><input type="checkbox" name="ids[]" value="<?= (int) $d['id'] ?>" form="bulkform" class="row-check"></td><?php endif; ?>
            <td class="col-statut"><span class="<?= e(structure_statut_icone_classe((string) $d['statut'])) ?>" title="<?= e(structure_statut_libelle((string) $d['statut'])) ?>"></span></td>
            <td class="col-nom">
                <strong><a href="<?= e($hrefLigne) ?>" class="titre-lien"><?= e($d['nom']) ?></a></strong>
                <?php
                    // Structures liées : sous le nom plutôt que dans leur propre
                    // colonne. Elles qualifient la structure — « organise X »,
                    // « accueilli par Y » — et se lisent donc avec elle ; isolées
                    // huit colonnes plus loin, il fallait faire l'aller-retour des
                    // yeux pour savoir de qui on parlait.
                    $lieesPaires = ($d['structures_liees'] ?? '') !== '' ? array_map(
                        fn ($p) => explode("\x1f", $p, 3) + ['', '', ''],
                        explode("\x1e", (string) $d['structures_liees'])
                    ) : [];
                ?>
                <?php if ($lieesPaires): ?>
                <div class="nom-liees"><?php foreach ($lieesPaires as $i => [$ln, $lid, $ls]): ?><?= $i > 0 ? ', ' : '' ?><span class="ico-tiny"><?= icon($ls === 'organise' ? 'blocks' : 'building') ?></span> <a href="?p=structure&id=<?= (int) $lid ?><?= $suffixeDepuis ?>"><?= e((string) $ln) ?></a><?php endforeach; ?></div>
                <?php endif; ?>
            </td>
            <td class="small col-ville">
                <?php $villeHtml = ville_departement_canton_html((string) $d['adresse_localite'], pays_drapeau_nom((string) $d['adresse_pays']), (string) $d['adresse_pays'], (string) $d['departement_canton']); ?>
                <?= $villeHtml !== '' ? $villeHtml : '—' ?>
            </td>
            <td class="col-categorie"><?= categorie_sous_categorie_html((string) $d['categorie'], (string) $d['sous_categorie']) ?></td>
            <td class="small col-tags" data-structure="<?= (int) $d['id'] ?>">
                <?php
                    // Trois champs par étiquette (id, nom, couleur), agrégés pour
                    // toutes les lignes en une requête (tags_noms). L'id sert à la
                    // croix de retrait et à la mise à jour AJAX de la cellule.
                    $tagsPaires = ($d['tags_noms'] ?? '') !== '' ? array_map(
                        fn ($p) => array_slice(explode("\x1f", $p, 3) + ['', '', ''], 0, 3),
                        explode("\x1e", (string) $d['tags_noms'])
                    ) : [];
                    // Rendu par structure_tags_cellule_html() (lib/booking.php), la
                    // même fonction que les routes d'ajout/retrait renvoient en JSON :
                    // la cellule mise à jour est identique à celle d'origine, par
                    // construction et non par recopie.
                ?>
                <?= structure_tags_cellule_html((int) $d['id'], $tagsPaires, $peutEcrireTags) ?>
            </td>
            <td class="tiny col-contact">
                <?php $contactsNoms = ($d['contacts_noms'] ?? '') !== '' ? explode("\x1e", (string) $d['contacts_noms']) : []; ?>
                <?= $contactsNoms ? e(implode(', ', $contactsNoms)) : '<span class="muted">—</span>' ?>
            </td>
            <?php
                // Une DURÉE et non une date : la question posée devant cette
                // colonne n'est pas « quand » mais « depuis combien de temps », et
                // « 21.11.2022 » demandait un calcul mental à chaque ligne. La date
                // exacte reste au survol.
                // Moins d'un an : vert et gras — au-delà, la structure est à
                // relancer, et c'est le cas de presque toutes (1 sur 655).
                // Jamais contactée : un trait seul, sans enveloppe. L'icône
                // annoncerait un échange qui n'a pas eu lieu, et 2307 enveloppes
                // alignées sur une colonne vide feraient du bruit pour rien.
                $contactLe = (string) ($d['dernier_contact_le'] ?? '');
                $contactRecent = $contactLe !== '' && $contactLe >= $limiteContactRecent;
                ?>
            <?php if ($montreContacte): ?>
            <?php // L'enveloppe est posée en masque CSS (::before sur .a-date) et non
                  // en <svg> : sur une colonne rendue 2959 fois, un dessin dans le
                  // balisage coûterait 74 octets par ligne pour une icône qui ne
                  // varie jamais. ?>
            <td class="tiny col-contact-le<?= $contactLe !== '' ? ' a-date' : '' ?><?= $contactRecent ? ' contact-recent' : ' muted' ?>"<?= $contactLe !== '' ? ' title="' . e(date('d.m.Y', strtotime($contactLe))) . '"' : '' ?>><?= $contactLe !== '' ? e(duree_depuis($contactLe)) : '—' ?></td>
            <?php endif; ?>
            <?php if ($montreFactures): ?>
            <td class="small col-factures">
                <?php if ((int) $d['nb_factures'] > 0): ?>
                    <a href="?p=facturation_liste&annee=0&statut=tous&q=<?= urlencode($d['nom']) ?>"><?= (int) $d['nb_factures'] ?></a>
                <?php else: ?>
                    0
                <?php endif; ?>
            </td>
            <?php endif; ?>
            <?php if (module_actif('evenements')): ?>
            <td class="muted small col-nb-evenements"><?php $ne = (int) ($nbEvenements[(int) $d['id']] ?? 0); echo $ne > 0 ? $ne : '—'; ?></td>
            <?php endif; ?>
            <?php
                // Repli sur la date de création quand aucune modification n'est
                // enregistrée : 2160 structures sur 2965 sont dans ce cas et la
                // colonne y restait vide. En italique (.est-creation) — une
                // création n'est pas une modification, et la colonne ne doit pas
                // laisser croire le contraire. Le libellé au survol qui le disait
                // a été retiré : 76 octets et un <span> par ligne concernée, soit
                // 203 Ko et 2159 nœuds, pour une infobulle que l'italique et
                // l'en-tête de colonne suffisent à expliquer. Le filtre
                // d'ancienneté de la colonne porte sur la date affichée, repli
                // compris (structures_filtres()) : ce qui se lit ici est ce qui
                // se filtre là. « Jamais » ne reste donc que pour les lignes
                // sans aucune date, ni modification ni création.
                // Même traitement que « Contacté » : une durée, la date au survol.
                // L'icône distingue les deux cas là où l'italique était seul à le
                // faire — crayon pour une vraie modification, cercle-plus pour une
                // fiche créée à l'import et jamais retouchée (2160 sur 2952). Le
                // libellé qui l'expliquait avait disparu en 2.3.8 pour alléger la
                // page ; l'icône le redit sans un octet de texte.
                $majLe = (string) ($d['mise_a_jour_le'] ?? '');
                $creeLe = (string) ($d['cree_le'] ?? '');
                $majAffichee = $majLe !== '' ? $majLe : $creeLe;
                ?>
            <td class="muted tiny col-maj-le<?= $majAffichee !== '' ? ' a-date' : '' ?><?= $majLe === '' && $creeLe !== '' ? ' est-creation' : '' ?>"<?= $majAffichee !== '' ? ' title="' . e(date('d.m.Y', strtotime($majAffichee))) . '"' : '' ?>><?= $majAffichee !== '' ? e(duree_depuis($majAffichee)) : '—' ?></td>
        </tr>
    <?php endforeach; ?>
    <?php endif; ?>
    <?= compacter_cellules((string) ob_get_clean()) ?>
    </tbody>
</table>
</div>
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
