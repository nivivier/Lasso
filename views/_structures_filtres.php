<?php
// Les entonnoirs du tableau des structures — ceux de ?p=structures, et les
// seuls. Le suivi d'une campagne montre le même tableau
// (views/_structures_table.php) et donc les mêmes filtres : ils sont écrits ici
// une fois, et chaque page les branche sur SA route et SA mémoire de session
// (voir structures_filtres(), lib/routes_facturation.php).
//
// Attendu de l'appelant (préfixe « sf ») :
//   $sfPage        (string) la route qui reçoit les panneaux ('structures', 'campagne').
//   $sfVals        (array)  valeurs actives, telles que rendues par structures_filtres() :
//                  categorieId, pays, departementCanton, grandeRegion, tagId,
//                  statut,
//                  avecEvenements, contactPeriode, majPeriode.
//   $sfSources     (array)  categoriesPourSelect, tagsDispo, regionsDispo
//                  (les départements/cantons, malgré son nom),
//                  grandesRegionsDispo, et
//                  campagnesDispo pour la colonne « Campagnes » — vide ailleurs,
//                  l'entonnoir disparaît alors avec elle.
//   $sfAutresParams(array)  paramètres reportés dans CHAQUE panneau (q, depuis,
//                  l'id de la campagne…) : un panneau est un <form method="get">
//                  qui ne connaît que ses propres champs, tout le reste doit y
//                  être reposé en champs cachés sous peine d'être perdu.
//   $sfExtra       (array)  paramètres ajoutés en plus à chaque panneau (vue=carte).
//   $sfTagActions  (array)  actions par étiquette (renommer/supprimer) ; [] ailleurs.
//   $sfReinitVides (array)  champs à vider dans le lien « retirer les filtres ».
//   $sfActifSupp   (bool)   un filtre hors panneau est actif (non_localises, région).
//
// Produit : $sfLabels (les listes d'options), $sfAutres (closure), $sfFiltres
// (par colonne du <thead>), $sfColonnes (les mêmes, nommés, pour le panneau
// mobile et la vue carte), $sfActifs (bande des filtres actifs), $sfReinit
// (bouton), $sfActif (bool).
$sfSources = $sfSources ?? [];
$sfAutresParams = $sfAutresParams ?? [];
$sfExtra = $sfExtra ?? [];
$sfTagActions = $sfTagActions ?? [];
$sfReinitVides = $sfReinitVides ?? [];
$sfActifSupp = $sfActifSupp ?? false;

$sfStatut = $sfVals['statut'] ?? [];
$sfCategorieId = $sfVals['categorieId'] ?? [];
$sfPays = $sfVals['pays'] ?? [];
$sfDepartementCanton = $sfVals['departementCanton'] ?? [];
$sfGrandeRegion = $sfVals['grandeRegion'] ?? [];
$sfTagId = $sfVals['tagId'] ?? [];
$sfCampagneId = $sfVals['campagneId'] ?? [];
$sfAvecEvenements = $sfVals['avecEvenements'] ?? [];
$sfContactPeriode = $sfVals['contactPeriode'] ?? [];
$sfMajPeriode = $sfVals['majPeriode'] ?? [];

$statutLabels = [];
foreach (STRUCTURE_STATUTS as $st) { $statutLabels[$st] = structure_statut_libelle($st); }
// « Aucun » en tête de ces deux listes : la question « lesquelles n'en ont pas
// encore ? » est celle qu'on pose en composant une campagne, et elle n'avait pas
// de réponse. Sentinelle textuelle, d'où le texteLibre de structures_filtres().
$tagLabels = [];
foreach ($sfSources['tagsDispo'] ?? [] as $t) { $tagLabels[(int) $t['id']] = $t['nom']; }
if ($tagLabels) { $tagLabels = ['aucun' => 'Aucun tag'] + $tagLabels; }
$campagneLabels = [];
foreach ($sfSources['campagnesDispo'] ?? [] as $c) { $campagneLabels[(int) $c['id']] = $c['nom']; }
if ($campagneLabels) { $campagneLabels = ['aucun' => 'Aucune campagne'] + $campagneLabels; }
$categorieLabels = [];
foreach ($sfSources['categoriesPourSelect'] ?? [] as $cat) {
    $categorieLabels[(int) $cat['id']] = str_repeat("\u{00A0}\u{00A0}", $cat['profondeur']) . $cat['nom'];
}
$paysLabels = [];
foreach (array_unique(array_merge($sfPays, array_column(pays_liste(), 'nom'))) as $nom) { $paysLabels[$nom] = $nom; }
$departementCantonLabels = [];
foreach (array_unique(array_merge($sfDepartementCanton, $sfSources['regionsDispo'] ?? [])) as $r) { $departementCantonLabels[$r] = $r; }
// Région = grande_region (Romandie, Normandie…), à ne pas confondre avec le
// département/canton juste au-dessus. Les valeurs actives sont réunies aux
// valeurs disponibles : une région qui n'est plus portée par aucune structure
// doit rester décochable.
$grandeRegionLabels = [];
foreach (array_unique(array_merge($sfGrandeRegion, $sfSources['grandesRegionsDispo'] ?? [])) as $r) { $grandeRegionLabels[$r] = $r; }
$avecEvenementsLabels = ['avec' => 'Avec événements liés', 'sans' => 'Sans événement lié'];
// Mêmes tranches pour les deux colonnes de date (voir PERIODES_ANCIENNETE).
$periodeLabels = PERIODES_ANCIENNETE;
$sfLabels = [
    'statut' => $statutLabels, 'tag' => $tagLabels, 'campagne' => $campagneLabels, 'categorie' => $categorieLabels,
    'pays' => $paysLabels, 'departementCanton' => $departementCantonLabels, 'grandeRegion' => $grandeRegionLabels,
    'avecEvenements' => $avecEvenementsLabels, 'periode' => $periodeLabels,
];

// $sfAutres : les AUTRES filtres actifs (jamais celui-ci), à reporter en champs
// cachés par chaque panneau — construits une fois plutôt qu'un littéral quasi
// identique par filtre.
$sfTousFiltres = [
    'categorie_id' => $sfCategorieId, 'pays' => $sfPays, 'departement_canton' => $sfDepartementCanton,
    'grande_region' => $sfGrandeRegion,
    'tag_id' => $sfTagId, 'campagne_id' => $sfCampagneId, 'statut' => $sfStatut, 'avec_evenements' => $sfAvecEvenements,
    'maj_periode' => $sfMajPeriode, 'contact_periode' => $sfContactPeriode,
] + $sfAutresParams;
$sfAutres = autres_filtres_fn($sfTousFiltres);

// Le filtre de statut peut démarrer sur « actif + contact privilégié » plutôt
// que vide (voir structures_filtres()) : c'est bien un filtre, il masque des
// structures. Le bouton de retrait doit donc être là dès l'ouverture.
$sfActif = $sfCategorieId || $sfStatut || $sfPays || $sfDepartementCanton || $sfGrandeRegion || $sfTagId
    || $sfCampagneId || $sfAvecEvenements || $sfContactPeriode || $sfMajPeriode || $sfActifSupp;

$sfMontreEvenements = module_actif('evenements');
$sfTags = ($sfSources['tagsDispo'] ?? []) !== [];
$sfCampagnes = $campagneLabels !== [];
// Un filtre, deux rendus : nu pour le <thead> (la colonne le nomme déjà), nommé
// pour le panneau hors tableau (rien ne le nommerait). Même appel, un argument
// de plus — et $sfExtra, que la vue carte ajoute pour se reconduire elle-même.
$sfCol = fn (string $champ, array $options, array $actives, string $libelle = '', array $actions = []): string
    => filtre_colonne_html($sfPage, $champ, $options, $actives, $sfAutres($champ) + $sfExtra, $libelle, $actions);

$sfFiltres = [
    'statut'     => $sfCol('statut', $statutLabels, $sfStatut),
    // Trois entonnoirs sur la colonne « Ville » : du plus large au plus fin.
    'ville'      => $sfCol('pays', $paysLabels, $sfPays)
                  . ($grandeRegionLabels ? $sfCol('grande_region', $grandeRegionLabels, $sfGrandeRegion) : '')
                  . $sfCol('departement_canton', $departementCantonLabels, $sfDepartementCanton),
    'categorie'  => $sfCol('categorie_id', $categorieLabels, $sfCategorieId),
    'tags'       => $sfTags ? $sfCol('tag_id', $tagLabels, $sfTagId, '', $sfTagActions) : '',
    'campagnes'  => $sfCampagnes ? $sfCol('campagne_id', $campagneLabels, $sfCampagneId) : '',
    'contacte'   => $sfCol('contact_periode', $periodeLabels, $sfContactPeriode),
    'evenements' => $sfMontreEvenements ? $sfCol('avec_evenements', $avecEvenementsLabels, $sfAvecEvenements) : '',
    'maj'        => $sfCol('maj_periode', $periodeLabels, $sfMajPeriode),
];

// Filtres de colonne hors tableau : la vue carte n'a pas de <thead> où les
// accrocher, et la liste en mini-cartes le masque (@media 700px). Mêmes filtres,
// nommés cette fois.
$sfColonnes = $sfCol('statut', $statutLabels, $sfStatut, 'Statut')
    . $sfCol('categorie_id', $categorieLabels, $sfCategorieId, 'Catégorie')
    . $sfCol('pays', $paysLabels, $sfPays, 'Pays')
    . ($grandeRegionLabels ? $sfCol('grande_region', $grandeRegionLabels, $sfGrandeRegion, 'Région') : '')
    . $sfCol('departement_canton', $departementCantonLabels, $sfDepartementCanton, 'Département / canton')
    . ($sfTags ? $sfCol('tag_id', $tagLabels, $sfTagId, 'Tags', $sfTagActions) : '')
    . ($sfCampagnes ? $sfCol('campagne_id', $campagneLabels, $sfCampagneId, 'Campagnes') : '')
    . ($sfMontreEvenements ? $sfCol('avec_evenements', $avecEvenementsLabels, $sfAvecEvenements, 'Événements') : '')
    . $sfCol('contact_periode', $periodeLabels, $sfContactPeriode, 'Contacté')
    . $sfCol('maj_periode', $periodeLabels, $sfMajPeriode, 'Modifié');

// Bande des filtres actifs : en vue bureau ces pastilles vivent déjà sous leur
// en-tête de colonne, la bande ne sert donc que là où le <thead> est masqué.
$sfAct = fn (string $champ, array $options, array $actives): string
    => filtre_colonne_actifs_html($sfPage, $champ, $options, $actives, $sfAutres($champ) + $sfExtra);
$sfActifs = $sfAct('statut', $statutLabels, $sfStatut)
    . $sfAct('categorie_id', $categorieLabels, $sfCategorieId)
    . $sfAct('pays', $paysLabels, $sfPays)
    . $sfAct('grande_region', $grandeRegionLabels, $sfGrandeRegion)
    . $sfAct('departement_canton', $departementCantonLabels, $sfDepartementCanton)
    . $sfAct('tag_id', $tagLabels, $sfTagId)
    . $sfAct('campagne_id', $campagneLabels, $sfCampagneId)
    . $sfAct('avec_evenements', $avecEvenementsLabels, $sfAvecEvenements)
    . $sfAct('contact_periode', $periodeLabels, $sfContactPeriode)
    . $sfAct('maj_periode', $periodeLabels, $sfMajPeriode);

$sfReinit = bouton_reinit_filtres(
    $sfPage,
    ['categorie_id', 'statut', 'pays', 'grande_region', 'departement_canton', 'tag_id', 'campagne_id', 'avec_evenements', 'contact_periode', 'maj_periode'],
    (bool) $sfActif,
    $sfReinitVides,
    // Les vides ne voyagent pas : un « q= » ou un « depuis= » sans valeur dans
    // l'URL de retrait n'y ajouterait que du bruit.
    array_filter($sfAutresParams, fn ($v) => $v !== '' && $v !== [])
);
