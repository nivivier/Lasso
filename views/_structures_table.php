<?php
// Le tableau des structures — celui de ?p=structures, et le seul. La sélection
// d'une campagne (?p=campagne_form) montre les mêmes colonnes : elles sont donc
// écrites ici une fois, et leurs données viennent toutes de
// structures_colonnes_liste_sql() (lib/booking.php).
//
// Attendu de l'appelant (préfixe « st » pour ne pas heurter les variables de la
// vue hôte, qui inclut ce fichier dans sa propre portée) :
//   $stStructures       les lignes, avec les colonnes ci-dessus.
//   $stVide             la phrase affichée quand il n'y en a aucune.
//   $stTri              (?array) rend les en-têtes triables : le tri courant
//                       (tri_colonne()) plus 'page' (la route) et 'params' (les
//                       filtres à emporter dans les liens). null = en-têtes en
//                       texte, comme sur la sélection et le suivi d'une campagne.
//   $stCheck            (?array) colonne de cases à cocher :
//                       ['name', 'form', 'classe', 'tout' (id du « tout cocher »),
//                        'coche' => Closure(array): bool] ; null = pas de colonne.
//   $stFiltres          (array) entonnoirs déjà rendus, par colonne : statut,
//                       ville, categorie, tags, campagnes, contacte, evenements, maj.
//   $stReinit           (string) bouton « retirer les filtres », posé sur la
//                       première colonne.
//   $stHref             (?Closure) lien du nom ; null = nom en texte simple.
//   $stLigneCliquable   (bool) la ligne entière mène à la fiche. Vrai dès qu'il y
//                       a un lien, sauf refus explicite : sur une liste où l'on
//                       coche (la sélection d'une campagne), un clic ne doit pas
//                       emporter ailleurs.
//   $stSuffixeDepuis    (string) suffixe d'URL des liens vers une fiche.
//   $stTagsActifs       (bool) étiquettes modifiables sur place (croix et « + »).
//   $stMontreContacte / $stMontreFactures / $stMontreEvenements (bool)
//   $stNbEvenements     (array) id de structure => nombre d'événements.
//   $stClasses          (string) classes en plus sur le <table>.
//   $stCampagnes        (?array) colonne « Campagnes », à droite des étiquettes :
//                       [structure_id => [[id, nom], …]]. null = pas de colonne.
//                       Propre à ?p=structures : sur la sélection ou le suivi
//                       d'une campagne, on est déjà dans l'une d'elles.
//   $stExtraTh          (string) en-têtes de colonnes en plus, à droite.
//   $stExtraTd          (?Closure) les cellules correspondantes, pour une ligne.
//                       C'est par là que le suivi d'une campagne ajoute ses
//                       actions et la réponse reçue sans redéfinir le tableau.
// $stTri : ['cle','sens','sql'] + ['page','params'] quand la liste est triable.
// null ailleurs — la sélection et le suivi d'une campagne gardent leurs en-têtes
// en texte : leur ordre est celui du démarchage, pas une question qu'on pose.
$stTri = $stTri ?? null;
// L'en-tête d'une colonne : un lien de tri quand la liste est triable, le
// libellé nu sinon. Une seule fabrique, pour que les deux cas se lisent pareil
// plus bas dans le <thead>.
$stTh = function (string $cle, string $libelle) use ($stTri): string {
    return $stTri === null
        ? $libelle
        : tri_entete_html($stTri['page'], $cle, $libelle, $stTri, $stTri['params']);
};
$stCheck = $stCheck ?? null;
$stFiltres = $stFiltres ?? [];
$stReinit = $stReinit ?? '';
$stHref = $stHref ?? null;
$stLigneCliquable = $stLigneCliquable ?? ($stHref !== null);
$stSuffixeDepuis = $stSuffixeDepuis ?? '';
$stTagsActifs = $stTagsActifs ?? false;
$stMontreContacte = $stMontreContacte ?? true;
$stMontreFactures = $stMontreFactures ?? true;
$stMontreEvenements = $stMontreEvenements ?? module_actif('evenements');
$stNbEvenements = $stNbEvenements ?? [];
$stClasses = $stClasses ?? '';
$stVide = $stVide ?? 'Aucune structure.';
$stCampagnes = $stCampagnes ?? null;
$stExtraTh = $stExtraTh ?? '';
$stExtraTd = $stExtraTd ?? null;
?>
<?php // Une colonne de moins depuis que « Structures liées » a rejoint la colonne « Nom ».
$nbCols = 9 + ($stMontreEvenements ? 1 : 0) - ($stCheck ? 0 : 1)
    - ($stMontreFactures ? 0 : 1) - ($stMontreContacte ? 0 : 1)
    + ($stCampagnes === null ? 0 : 1)
    + preg_match_all('~<th\b~i', $stExtraTh); ?>
<div class="table-scroll">
<table class="list list-wide liste-cartes<?= $stCheck ? ' avec-check' : '' ?><?= $stClasses !== '' ? ' ' . e($stClasses) : '' ?>">
    <thead><tr>
        <?php if ($stCheck): ?><th class="col-reinit-hote col-check"><?= $stReinit ?><input type="checkbox" id="<?= e($stCheck['tout'] ?? 'check-all') ?>" aria-label="Tout cocher"></th><?php endif; ?>
        <?php // En-tête en icône plutôt qu'en mot : la colonne ne contient que des
              // icônes, et « Statut » écrit en toutes lettres y occupait deux fois
              // la largeur de son contenu. Le nom reste porté par title et
              // aria-label — au survol pour la souris, à la lecture pour les
              // lecteurs d'écran — comme les colonnes Factures et Événements. ?>
        <th class="col-petit col-statut-th<?= $stCheck ? '' : ' col-reinit-hote' ?>"><?php if (!$stCheck): ?><?= $stReinit ?><?php endif; ?>
            <span class="col-th">
                <?= $stTh('statut', '<span title="Statut" aria-label="Statut">' . icon('circle-dot') . '</span>') ?>
                <?= $stFiltres['statut'] ?? '' ?>
            </span>
        </th>
        <th class="col-nom">
            <span class="col-th">
                <?= $stTh('nom', 'Nom') ?>
            </span>
        </th>
        <th class="col-ville">
            <span class="col-th">
                <?= $stTh('ville', 'Ville') ?>
                <?= $stFiltres['ville'] ?? '' ?>
            </span>
        </th>
        <th class="col-categorie">
            <span class="col-th">
                <?= $stTh('categorie', 'Catégorie') ?>
                <?= $stFiltres['categorie'] ?? '' ?>
            </span>
        </th>
        <th class="col-tags">
            <span class="col-th">
                Tags
                <?= $stFiltres['tags'] ?? '' ?>
            </span>
        </th>
        <?php if ($stCampagnes !== null): ?>
        <th class="col-campagnes">
            <span class="col-th">
                Campagnes
                <?= $stFiltres['campagnes'] ?? '' ?>
            </span>
        </th>
        <?php endif; ?>
        <?php // Colonne masquée, mais toujours rendue : la recherche de cette liste
              // se fait EN JAVASCRIPT sur le texte des lignes (lassoListeClient(),
              // mode client jusqu'à 4000 fiches), et textContent ignore le CSS —
              // les noms de contacts restent donc trouvables sans être affichés.
              // Retirer les cellules aurait supprimé cette recherche du même coup.
              // Au-delà du seuil, c'est la requête SQL qui cherche, et elle
              // interroge structure_contacts de son côté. ?>
        <th class="col-contact">Contact</th>
        <?php if ($stMontreContacte): ?>
        <th class="col-petit">
            <span class="col-th"><?= $stTh('contacte', 'Contacté') ?>
                <?= $stFiltres['contacte'] ?? '' ?>
            </span>
        </th>
        <?php endif; ?>
        <?php if ($stMontreFactures): ?>
        <th><?= $stTh('factures', '<span title="Factures liées" aria-label="Factures liées">' . icon('receipt-swiss-franc') . '</span>') ?></th>
        <?php endif; ?>
        <?php if ($stMontreEvenements): ?>
        <th class="col-evenements">
            <span class="col-th">
                <?= $stTh('evenements', '<span title="Événements liés" aria-label="Événements liés">' . icon('calendar') . '</span>') ?>
                <?= $stFiltres['evenements'] ?? '' ?>
            </span>
        </th>
        <?php endif; ?>
        <?php // « Modifié » en dernière colonne : c'est une date de service,
              // qu'on consulte rarement et jamais en premier — la reléguer en fin
              // de ligne laisse la place aux colonnes qu'on parcourt. ?>
        <th class="col-petit">
            <span class="col-th"><?= $stTh('maj', 'Modifié') ?>
                <?= $stFiltres['maj'] ?? '' ?>
            </span>
            </th>
        <?= $stExtraTh ?>
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
    <?php if (!$stStructures): ?>
        <tr><td colspan="<?= $nbCols ?>" class="muted"><?= e($stVide) ?></td></tr>
    <?php else: ?>
    <?php foreach ($stStructures as $d): ?>
        <?php $hrefLigne = $stHref ? ($stHref)($d) : ''; ?>
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
        <tr class="<?= $stLigneCliquable ? 'row-link ' : '' ?><?= $d['statut'] === 'inactif' ? 'inactif' : '' ?>" data-statut="<?= e((string) $d['statut']) ?>"<?= $stLigneCliquable ? ' tabindex="0" role="link"' : '' ?>>
            <?php if ($stCheck): ?><td class="col-check"><input type="checkbox" name="<?= e($stCheck['name']) ?>" value="<?= (int) $d['id'] ?>" form="<?= e($stCheck['form']) ?>" class="<?= e($stCheck['classe']) ?>"<?= isset($stCheck['coche']) && ($stCheck['coche'])($d) ? ' checked' : '' ?>></td><?php endif; ?>
            <td class="col-statut"><span class="<?= e(structure_statut_icone_classe((string) $d['statut'])) ?>" title="<?= e(structure_statut_libelle((string) $d['statut'])) ?>"></span></td>
            <td class="col-nom">
                <strong><?php if ($hrefLigne !== ''): ?><a href="<?= e($hrefLigne) ?>" class="titre-lien"><?= e($d['nom']) ?></a><?php else: ?><?= e($d['nom']) ?><?php endif; ?></strong>
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
                <div class="nom-liees"><?php foreach ($lieesPaires as $i => [$ln, $lid, $ls]): ?><?= $i > 0 ? ', ' : '' ?><span class="ico-tiny"><?= icon($ls === 'organise' ? 'blocks' : 'building') ?></span> <a href="?p=structure&id=<?= (int) $lid ?><?= $stSuffixeDepuis ?>"><?= e((string) $ln) ?></a><?php endforeach; ?></div>
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
                <?= structure_tags_cellule_html((int) $d['id'], $tagsPaires, $stTagsActifs) ?>
            </td>
            <?php if ($stCampagnes !== null): ?>
            <?php // data-structure : la cellule est remplacée seule après un ajout
                  // ou un retrait, sans recharger une page de plusieurs mégaoctets
                  // (même mécanique que les étiquettes). ?>
            <td class="small col-campagnes" data-structure="<?= (int) $d['id'] ?>">
                <?= structure_campagnes_cellule_html((int) $d['id'], $stCampagnes[(int) $d['id']] ?? [], $stTagsActifs) ?>
            </td>
            <?php endif; ?>
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
            <?php if ($stMontreContacte): ?>
            <?php // L'enveloppe est posée en masque CSS (::before sur .a-date) et non
                  // en <svg> : sur une colonne rendue 2959 fois, un dessin dans le
                  // balisage coûterait 74 octets par ligne pour une icône qui ne
                  // varie jamais. ?>
            <td class="tiny col-contact-le<?= $contactLe !== '' ? ' a-date' : '' ?><?= $contactRecent ? ' contact-recent' : ' muted' ?>"<?= $contactLe !== '' ? ' title="' . e(date('d.m.Y', strtotime($contactLe))) . '"' : '' ?>><?= $contactLe !== '' ? e(duree_depuis($contactLe)) : '—' ?></td>
            <?php endif; ?>
            <?php if ($stMontreFactures): ?>
            <td class="small col-factures">
                <?php if ((int) $d['nb_factures'] > 0): ?>
                    <a href="?p=facturation_liste&annee=0&statut=tous&q=<?= urlencode($d['nom']) ?>"><?= (int) $d['nb_factures'] ?></a>
                <?php else: ?>
                    0
                <?php endif; ?>
            </td>
            <?php endif; ?>
            <?php if ($stMontreEvenements): ?>
            <td class="muted small col-nb-evenements"><?php $ne = (int) ($stNbEvenements[(int) $d['id']] ?? 0); echo $ne > 0 ? $ne : '—'; ?></td>
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
            <?= $stExtraTd ? ($stExtraTd)($d) : '' ?>
        </tr>
    <?php endforeach; ?>
    <?php endif; ?>
    <?= compacter_cellules((string) ob_get_clean()) ?>
    </tbody>
</table>
</div>
