<?php
/** @var array $campagne */ /** @var array $projets */ /** @var array $projetsPastilles */ /** @var array $structures */
/** @var int $nbTotal */ /** @var int $nbFaits */ /** @var string $statut */
/** @var bool $ouverte */ /** @var bool $saved */
/** @var array $contacterCibles */ /** @var array $expediteurs */ /** @var array $modelesMessage */
/** @var array $campagneProjets */ /** @var array $spectacles */ /** @var array $projetIds */
/** @var array $repartition */ /** @var array $filtres */ /** @var array $reponseFiltre */ /** @var int $nbAffichees */
/** @var array $categoriesPourSelect */ /** @var array $regionsDispo */ /** @var array $tagsDispo */
// Une campagne : ses structures, et pour chacune le bouton qui ouvre la fenêtre
// « Contacter » — ici même, sans quitter la liste. Rien ne part d'ici en masse :
// c'est le principe, on démarche une structure à la fois.
$spectacleLabels = [];
foreach ($spectacles as $sp) { $spectacleLabels[(int) $sp['id']] = $sp['nom']; }
$statutClasse = ['a_venir' => 'muted-badge', 'en_cours' => 'ok-badge', 'en_retard' => 'err-badge', 'terminee' => 'muted-badge'];
$jour = fn ($d) => trim((string) $d) !== '' ? date('d.m.Y', strtotime((string) $d)) : '';
$peutEcrire = peut_ecrire('booking');

// Les entonnoirs sont ceux de ?p=structures (views/_structures_filtres.php) :
// c'est le même tableau, ce sont les mêmes questions. Ils pointent sur cette
// route et gardent l'id de la campagne — sans lui, filtrer renverrait à la
// liste des campagnes. La réponse reçue s'y ajoute : elle n'existe que sur une
// campagne, et vit sur le lien campagne↔structure.
$sfPage = 'campagne';
$sfVals = $filtres;
$sfSources = ['categoriesPourSelect' => $categoriesPourSelect, 'tagsDispo' => $tagsDispo, 'regionsDispo' => $regionsDispo];
$sfAutresParams = ['id' => (int) $campagne['id'], 'reponse' => $reponseFiltre];
$sfActifSupp = $reponseFiltre !== [];
require __DIR__ . '/_structures_filtres.php';
// Le filtre propre à la campagne, bâti avec les mêmes composants. « Aucune
// réponse » est la chaîne vide : c'est une valeur comme une autre, on doit
// pouvoir demander à ne voir que les structures qui n'ont pas répondu.
$reponseAutres = $sfAutres('reponse');
$reponseFiltreHtml = filtre_colonne_html('campagne', 'reponse', CAMPAGNE_REPONSES, $reponseFiltre, $reponseAutres);
// Nu dans l'en-tête (la colonne le nomme), nommé dans le panneau hors tableau.
$sfColonnes .= filtre_colonne_html('campagne', 'reponse', CAMPAGNE_REPONSES, $reponseFiltre, $reponseAutres, 'Réponse');
$sfActifs .= filtre_colonne_actifs_html('campagne', 'reponse', CAMPAGNE_REPONSES, $reponseFiltre, $reponseAutres);
// Le bouton de retrait doit vider la réponse comme le reste.
$sfReinit = bouton_reinit_filtres(
    'campagne',
    ['categorie_id', 'statut', 'pays', 'departement_canton', 'tag_id', 'avec_evenements', 'contact_periode', 'maj_periode', 'reponse'],
    (bool) $sfActif,
    [],
    ['id' => (int) $campagne['id']]
);
?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>
<?php // Même charpente qu'une fiche (?p=structure) : la zone du module, un
      // en-tête de page, puis le tableau d'un bord à l'autre de cette zone. ?>
<div class="module-content"><div class="module-content-inner">
<a class="back-link" href="?p=campagnes"><?= icon('arrow-left') ?> Campagnes</a>
<?php if ($saved): ?><p class="ok flash">Enregistré.</p><?php endif; ?>
<?php require __DIR__ . '/_flash_contacter.php'; ?>

<div class="page-head">
    <?php // Le badge est À CÔTÉ du <h1>, pas dedans : le titre de page peint son
          // texte en dégradé (background-clip: text), et un enfant niché là-dedans
          // dépend du bon vouloir du moteur de rendu pour son propre fond — d'où
          // le liseré fantôme vu autour de la pastille. .page-head-title est la
          // rangée prévue pour ça (toutes les autres pages l'utilisent déjà), et
          // elle centre la pastille sur le titre au lieu de la poser sur sa
          // ligne de base. ?>
    <div class="page-head-title">
        <h1><?= e($campagne['nom']) ?></h1>
        <span class="badge <?= $statutClasse[$statut] ?? 'muted-badge' ?>"><?= e(CAMPAGNE_STATUTS[$statut] ?? $statut) ?></span>
    </div>
    <?php if ($peutEcrire): ?>
    <div class="head-actions">
    <a class="btn ghost" href="?p=campagne_form&id=<?= (int) $campagne['id'] ?>"><?= icon('pencil') ?> Modifier</a>
    <form method="post" action="?p=campagne_delete" class="d-inline" data-confirm="Supprimer la campagne « <?= e($campagne['nom']) ?> » ? Les structures et l'historique ne sont pas touchés.">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= (int) $campagne['id'] ?>">
        <button type="submit" class="btn danger btn-sm icon-only" title="Supprimer" aria-label="Supprimer la campagne"><?= icon('trash') ?></button>
    </form>
    </div>
    <?php endif; ?>
</div>

<?php
// La carte de tête. Elle répond d'un coup d'œil à deux questions : combien
// reste-t-il à démarcher, et qu'ont répondu ceux qu'on a déjà contactés.
//
// Une seule barre, quatre segments — intéressé, pas intéressé, contacté sans
// réponse, à contacter. Elle est rendue par campagne_barre_html() : la liste
// des campagnes montre exactement la même, il n'y en a qu'une définition.
// Les icônes des projets, en grand : c'est par elles qu'on reconnaît la
// campagne avant même de lire son nom. Plusieurs projets se superposent en
// pile, le premier devant. Au-delà de trois on s'arrête et on compte le reste :
// une pile de six ne montrerait plus rien de personne.
// Sans projet, pas de bloc du tout.
$iconesPile = array_slice($projetsPastilles, 0, 3);
$iconesReste = count($projetsPastilles) - count($iconesPile);
?>
<div class="card camp-carte mb-22">
    <?php if ($iconesPile): ?>
    <?php // aria-hidden : les projets sont nommés juste à côté, en toutes
          // lettres. Répéter la pile à la lecture n'ajouterait rien. ?>
    <div class="camp-icone" aria-hidden="true">
        <?php foreach ($iconesPile as $i => $pastille): ?>
        <span class="camp-icone-item" style="z-index:<?= count($iconesPile) - $i ?>"><?= $pastille ?></span>
        <?php endforeach; ?>
        <?php if ($iconesReste > 0): ?>
        <span class="camp-icone-item"><span class="avatar-ini camp-icone-plus">+<?= (int) $iconesReste ?></span></span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="camp-corps">
        <div class="camp-projets">
            <?php if (!$projets): ?><span class="muted">Aucun projet</span><?php endif; ?>
            <?php foreach ($projets as $i => $nomProjet): ?>
                <span class="projet-pastille"><?= $projetsPastilles[$i] ?? '' ?><?= e($nomProjet) ?></span>
            <?php endforeach; ?>
        </div>

        <?= campagne_barre_html($repartition, $nbTotal) ?>

        <?php // data-part : le script du bas met ces nombres à jour quand on note
              // une réponse dans la liste, sans recharger la page. ?>
        <ul class="camp-legende">
            <li><i class="camp-pip camp-oui"></i><b data-part="interesse"><?= (int) $repartition['interesse'] ?></b> intéressé</li>
            <li><i class="camp-pip camp-non"></i><b data-part="refus"><?= (int) $repartition['refus'] ?></b> pas intéressé</li>
            <li><i class="camp-pip camp-attente"></i><b data-part="sansReponse"><?= (int) $repartition['sansReponse'] ?></b> sans réponse</li>
            <li><i class="camp-pip camp-reste"></i><b data-part="aContacter"><?= (int) $repartition['aContacter'] ?></b> à contacter</li>
            <?php $d = $jour($campagne['date_debut']); $f = $jour($campagne['date_fin']); ?>
            <?php if ($d !== '' || $f !== ''): ?>
            <li class="camp-legende-fin muted"><?= icon('clock') ?> <?= $d !== '' ? e($d) : '—' ?><?= $f !== '' ? ' → ' . e($f) : '' ?></li>
            <?php endif; ?>
        </ul>
    </div>

    <div class="camp-chiffre">
        <b><?= (int) $nbFaits ?></b>
        <span class="muted">sur <?= (int) $nbTotal ?> contactée<?= $nbTotal > 1 ? 's' : '' ?></span>
    </div>
</div>
<?php if (!$ouverte): ?>
    <p class="muted small camp-note"><?= icon('clock') ?> La campagne commence le <?= e($jour($campagne['date_debut'])) ?> : aucun message ne peut partir avant.</p>
<?php elseif (!$projets): ?>
    <p class="muted small camp-note">Sans projet renseigné, aucune prise de contact ne peut être rattachée à cette campagne — l'avancement restera à zéro.</p>
<?php endif; ?>

<?php // Recherche instantanée sur les lignes déjà affichées : une campagne tient
      // dans une page, et filtrer côté serveur ferait perdre l'état des
      // sélecteurs de réponse à chaque frappe. Même composant qu'ailleurs. ?>
<div class="toolbar">
    <?= champ_recherche(['id' => 'campagne-search', 'placeholder' => 'Structure, ville, catégorie, tag…']) ?>
    <?php // Sur téléphone la mise en cartes masque le <thead> : ce panneau
          // reprend les entonnoirs qui y sont accrochés. ?>
    <?php $fmColonnes = $sfColonnes; $fmActifs = $sfActifs; require __DIR__ . '/_filtres_mobile.php'; ?>
    <?php // UN seul compte, quelle qu'en soit la cause. Les filtres (serveur) et
          // la recherche (immédiate, sur les lignes affichées) réduisent la même
          // liste : deux compteurs côte à côte donnaient deux chiffres à
          // rapprocher soi-même. Le serveur pose la valeur, la recherche la
          // réécrit à chaque frappe. ?>
    <span class="muted small ml-auto" id="campagne-compte"
          data-total="<?= (int) $nbTotal ?>"><?= $nbAffichees !== $nbTotal
        ? (int) $nbAffichees . ' structure(s) sur ' . (int) $nbTotal : '' ?></span>
</div>

<?php
// Le tableau est celui de ?p=structures (views/_structures_table.php) : mêmes
// colonnes, même code. La campagne y ajoute les deux siennes, tout à droite —
// ce qu'on fait à cette ligne, puis ce qu'elle a répondu.
$stStructures = $structures;
$stVide = $sfActif
    ? 'Aucune structure de cette campagne ne correspond à ces filtres.'
    : 'Aucune structure dans cette campagne.';
// depuis=campagne:<id> : le lien retour de la fiche ramène à CETTE campagne
// (lien_retour_contextuel()), et le rail reste sur Booking (nav_groupe_actif()).
$stHref = fn (array $d): string => '?p=structure&id=' . (int) $d['id'] . '&depuis=campagne:' . (int) $campagne['id'];
$stSuffixeDepuis = '&depuis=campagne:' . (int) $campagne['id'];
// Ligne non cliquable : elle porte des boutons, un clic à côté ne doit pas
// emporter sur la fiche au milieu d'un démarchage. Étiquettes en lecture seule
// pour la même raison — on les modifie sur la fiche.
$stLigneCliquable = false;
$stTagsActifs = false;
$stNbEvenements = $nbEvenements;
// Pas de colonne « Factures » : on est dans le booking, et ?p=structures la
// masque déjà quand on y arrive par ce module. Même tableau, même choix.
$stMontreFactures = false;
$stClasses = 'campagne-structures';
$stFiltres = $sfFiltres;
$stReinit = $sfReinit;
$stExtraTh = ($peutEcrire ? '<th class="nowrap col-actions">Actions</th>' : '')
    . '<th class="nowrap col-reponse"><span class="col-th">Réponse ' . $reponseFiltreHtml . '</span></th>';
$stExtraTd = function (array $d) use ($campagne, $peutEcrire, $ouverte): string {
    $sid = (int) $d['id'];
    // Les deux colonnes s'excluent, parce que les deux gestes se suivent : tant
    // que le contact reste à faire, on agit et il n'y a pas de réponse à noter ;
    // une fois qu'il a eu lieu, il n'y a plus rien à déclencher et c'est la
    // réponse qui compte. Une ligne ne propose donc jamais les deux à la fois.
    $contactee = (bool) $d['contactee'];

    // Sans droit d'écriture, pas de colonne d'actions du tout : il n'y a rien à
    // y faire, et « Réponse » dit déjà si le contact a eu lieu.
    $h = $peutEcrire ? '<td class="actions nowrap col-actions">' : '';
    if ($peutEcrire && $contactee) {
        // La coche remplace les boutons : elle dit pourquoi il n'y en a plus.
        // Bulle et non coche nue : c'est un échange qui a eu lieu, et « check »
        // reste l'action de valider — juste à côté, le bouton « Marquer comme
        // contacté » le porte déjà.
        $h .= '<span class="campagne-coche" title="Déjà contactée pour un projet de cette campagne">' . icon('message-circle-check') . '</span>';
    } elseif ($peutEcrire && $ouverte) {
        // Deux boutons en icône seule : le tableau porte déjà les dix colonnes de
        // ?p=structures, et deux libellés entiers repoussaient « Réponse » hors de
        // l'écran. Le nom reste porté par title et aria-label, comme les autres
        // colonnes d'actions de l'application.
        //
        // « Contacter » est mis en évidence (fond plein, le .btn par défaut) :
        // c'est le geste de la page, on démarche. Marquer après coup reste en
        // second plan. Le bouton empêché garde la même couleur pour que la
        // colonne ne change pas d'allure d'une ligne à l'autre — l'opacité des
        // boutons désactivés dit déjà qu'il ne se passera rien.
        //
        // Il ouvre la même fenêtre que sur la fiche structure, projets de la
        // campagne déjà cochés : rien de neuf à apprendre.
        $h .= $d['contact_impossible'] === ''
            ? '<button type="button" class="btn btn-sm icon-only" data-contacter="' . $sid . '"'
              . ' title="Contacter" aria-label="Contacter ' . e((string) $d['nom']) . '">' . icon('mail') . '</button>'
            : '<button type="button" class="btn btn-sm icon-only" disabled title="'
              . e((string) $d['contact_impossible']) . '" aria-label="Contacter">' . icon('mail') . '</button>';
        // Tout démarchage n'est pas parti d'ici : un appel, une rencontre, un
        // message envoyé de sa propre boîte se consignent à la main — et comptent pareil.
        $h .= ' <button type="button" class="btn ghost btn-sm icon-only" data-noter="' . $sid . '"'
            . ' data-noter-nom="' . e((string) $d['nom']) . '" title="Marquer comme contacté"'
            . ' aria-label="Marquer ' . e((string) $d['nom']) . ' comme contactée">' . icon('check') . '</button>';
    }
    if ($peutEcrire) {
        // Retirer la structure de la campagne : on délie, on ne supprime rien —
        // la structure et son historique restent. La réponse notée, elle, part
        // avec la ligne qui la porte, d'où la confirmation. Disponible même sur
        // une ligne déjà contactée et sur une campagne pas encore ouverte :
        // corriger une sélection n'a pas d'heure.
        $h .= ' <form method="post" action="?p=structure_campagne" class="d-inline"'
            . ' data-confirm="Retirer ' . e((string) $d['nom']) . ' de la campagne « ' . e((string) $campagne['nom'])
            . ' » ? La réponse qui y est notée sera perdue.">'
            . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
            . '<input type="hidden" name="structure_id" value="' . $sid . '">'
            . '<input type="hidden" name="campagne_id" value="' . (int) $campagne['id'] . '">'
            . '<input type="hidden" name="action" value="retirer">'
            . '<input type="hidden" name="retour" value="campagne">'
            . '<button type="submit" class="btn ghost btn-sm icon-only" title="Retirer de la campagne"'
            . ' aria-label="Retirer ' . e((string) $d['nom']) . ' de la campagne">' . icon('unlink') . '</button>'
            . '</form>';
    }

    $h .= ($peutEcrire ? '</td>' : '') . '<td class="nowrap col-reponse">';
    // Réponse reçue : trois icônes enregistrées au clic. Elle est propre à CETTE
    // campagne — la même salle peut décliner une tournée et prendre la suivante.
    // Rien tant que personne n'a été contacté : il n'y a pas de réponse à une
    // question qu'on n'a pas posée, et la cellule vide se lit d'elle-même.
    if ($contactee) {
        $reponse = (string) ($d['reponse'] ?? '');
        $h .= $peutEcrire
            ? campagne_reponse_toggle_html((int) $campagne['id'], $sid, $reponse)
            : '<span class="' . e(CAMPAGNE_REPONSES_CLASSES_ICONE[$reponse] ?? 'muted') . '" title="'
              . e(CAMPAGNE_REPONSES[$reponse] ?? '') . '">' . icon(CAMPAGNE_REPONSES_ICONES[$reponse] ?? 'circle-dashed') . '</span>';
    }
    return $h . '</td>';
};
require __DIR__ . '/_structures_table.php';
?>

<?php if ($peutEcrire): ?>
<script nonce="<?= e(csp_nonce()) ?>">
// Noter une réponse dans la liste met la carte à jour sur-le-champ : la barre
// et ses quatre nombres décrivent CETTE liste, ils ne peuvent pas la contredire
// jusqu'au prochain rechargement.
//
// Le compte est tenu ici, pas redemandé au serveur : la règle est la même que
// campagne_repartition() (lib/booking.php) — « sans réponse » est ce qui a été
// contacté moins ce qui a répondu, et le reste suit. Un aller-retour de plus
// n'apprendrait rien, mais chaque clic en paierait le prix.
(function () {
    var carte = document.querySelector('.camp-carte');
    if (!carte) { return; }
    var total = <?= (int) $nbTotal ?>;
    var faits = <?= (int) $nbFaits ?>;
    var n = { interesse: <?= (int) $repartition['interesse'] ?>, refus: <?= (int) $repartition['refus'] ?> };
    var barre = carte.querySelector('.camp-barre');

    function peindre() {
        var parts = {
            interesse: n.interesse,
            refus: n.refus,
            sansReponse: Math.max(0, faits - n.interesse - n.refus),
        };
        parts.aContacter = Math.max(0, total - parts.interesse - parts.refus - parts.sansReponse);
        Object.keys(parts).forEach(function (cle) {
            var seg = barre.querySelector('.camp-seg[data-part="' + cle + '"]');
            if (seg) { seg.style.width = (total > 0 ? (parts[cle] * 100 / total) : 0) + '%'; }
            var txt = carte.querySelector('.camp-legende b[data-part="' + cle + '"]');
            if (txt) { txt.textContent = parts[cle]; }
        });
        var titre = parts.interesse + ' intéressé, ' + parts.refus + ' pas intéressé, '
                  + parts.sansReponse + ' sans réponse, ' + parts.aContacter + ' à contacter';
        barre.setAttribute('title', titre);
        barre.setAttribute('aria-label', titre);
    }

    // segchange vient du sélecteur segmenté (lassoInitSegToggleAjax, app.js) :
    // il n'est émis qu'une fois le serveur d'accord, jamais au clic.
    document.addEventListener('segchange', function (e) {
        var picker = e.target.closest ? e.target.closest('.reponse-toggle') : null;
        if (!picker) { return; }
        if (e.detail.avant === 'interesse') { n.interesse--; }
        if (e.detail.avant === 'pas_interesse') { n.refus--; }
        if (e.detail.apres === 'interesse') { n.interesse++; }
        if (e.detail.apres === 'pas_interesse') { n.refus++; }
        peindre();
    });
})();
</script>
<?php endif; ?>

<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    var champ = document.getElementById('campagne-search');
    var compte = document.getElementById('campagne-compte');
    var lignes = Array.prototype.slice.call(document.querySelectorAll('.campagne-structures tbody tr'));
    if (!champ || !lignes.length) return;
    // Le compte au chargement est celui du serveur : on le garde tel quel tant
    // qu'on n'a rien tapé, pour ne pas effacer ce que disent les filtres.
    var duServeur = compte.textContent;
    var total = parseInt(compte.getAttribute('data-total'), 10) || lignes.length;
    var filtrer = function () {
        var q = lassoNorm(champ.value.trim());
        var vus = 0;
        lignes.forEach(function (tr) {
            var montrer = q === '' || lassoNorm(tr.textContent).indexOf(q) !== -1;
            tr.hidden = !montrer;
            if (montrer) vus++;
        });
        compte.textContent = q === '' ? duServeur : vus + ' structure(s) sur ' + total;
    };
    champ.addEventListener('input', filtrer);
})();
</script>

<?php if ($peutEcrire && $ouverte): ?>
<?php require __DIR__ . '/_campagne_noter.php'; ?>
<?php endif; ?>

<?php if ($peutEcrire && $ouverte && $contacterCibles): ?>
<?php // Une seule fenêtre pour toutes les lignes, et l'envoi ramène ici. ?>
<?php $contacterRetourCampagne = (int) $campagne['id']; ?>
<?php require __DIR__ . '/_structure_contacter.php'; ?>
<?php endif; ?>
