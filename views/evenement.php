<?php
/** @var ?array $evenement */ /** @var int $id */ /** @var array $projets */ /** @var array $projetMap */
/** @var array $employesLies */ /** @var array $employesDispo */ /** @var array $prestations */
/** @var array $fichesParEmploye */ /** @var array $unites */ /** @var array $tauxHoraires */
/** @var array $factures */ /** @var array $facturesDispo */
/** @var array $structuresLiees */
/** @var array $paysDisponibles */ /** @var array $axes */ /** @var ?string $err */ /** @var array $post */
$isEdit = $id > 0;
$peutEcrireEv = peut_ecrire('evenements');
$v = fn (string $k, $d = '') => e((string) ($post[$k] ?? $evenement[$k] ?? $d));
$vRaw = fn (string $k, $d = '') => (string) ($post[$k] ?? $evenement[$k] ?? $d);
$retour = $isEdit ? '?p=evenement&id=' . (int) $id : '?p=evenements';
// Reporté sur les formulaires de cette page qui redirigent vers elle-même,
// pour que le lien de retour contextuel (lien_retour_contextuel()) survive à
// un enregistrement (voir redirect() dans lib/helpers.php).
$depuisQs = isset($_GET['depuis']) ? '&depuis=' . rawurlencode($_GET['depuis']) : '';
$ok = $_GET['ok'] ?? null;
$errFeuille = trim((string) ($_GET['errFeuille'] ?? ''));
$errLigne = $_GET['errLigne'] ?? null;
$errEmploye = $_GET['errEmploye'] ?? null;
$errOrganisation = ($_GET['errOrganisation'] ?? null) === '1';
$errProdExterne = $_GET['errProdExterne'] ?? null;
$errInformationsMsg = [
    'date'       => 'La date est invalide.',
    'projet'  => evenements_terme_projet(false) . ' invalide.',
    'lien'       => "Le lien doit être une URL valide (commençant par http:// ou https://).",
][$_GET['errInformations'] ?? ''] ?? null;
$prodExterne = (bool) ($evenement['production_externe'] ?? false);

$confirmSuppr = null;
if ($isEdit) {
    $nbFiches = count(evenement_fiche_ids($id));
    $impacts = [];
    if ($employesLies) $impacts[] = count($employesLies) . ' employé(s) lié(s)';
    if ($nbFiches) $impacts[] = $nbFiches . ' fiche(s) de salaire liée(s)';
    if ($factures) $impacts[] = count($factures) . ' facture(s) qui perdront ce lien';
    $confirmSuppr = 'Supprimer cet événement ?' . ($impacts ? ' ' . implode(', ', $impacts) . '.' : '');
}
$uniteOpts = options_unites($unites);
$tauxOpts  = options_taux_horaires($tauxHoraires);

// Options de l'axe analytique par défaut (carte « Comptabilité analytique ») et
// pour la ligne d'ajout de prestation — même présentation que fiche_form.php.
$axeOpts = options_axes($axes);
$axeSelect = function (string $name, string $class, int $selected, bool $hidden = false) use ($axeOpts): string {
    $html = preselectionner_option($axeOpts, $selected ? (string) $selected : '');
    return '<select name="' . e($name) . '" class="' . e($class) . '"' . ($hidden ? ' hidden' : '') . '>' . $html . '</select>';
};
// Le projet déjà lié peut avoir gagné des enfants depuis (devenu un groupe
// « artiste », non assignable) : on le garde visible dans le select pour ne pas
// changer silencieusement l'événement au prochain enregistrement.
$projetActuelId = (int) $vRaw('projet_id', '0');
if ($projetActuelId && !array_filter($projets, fn($s) => (int) $s['id'] === $projetActuelId)) {
    if (isset($projetMap[$projetActuelId])) {
        $projets[] = ['id' => $projetActuelId, 'nom' => projet_chemin($projetActuelId, $projetMap) . ' (groupe, non réassignable)'];
    }
}

?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php
// Structures est partagée par 3 groupes de nav (booking/facturation/evenements,
// voir nav_groupe_actif()) — reporté sur les liens vers une structure pour que
// le rail/bandeau y reste dans le même groupe de provenance une fois dessus
// (même principe que structures_liste.php). Ici en forme « evenement:id »
// (convention de lien_retour_contextuel()) plutôt que la simple clé de groupe
// « evenements » : nav_groupe_actif() sait résoudre les deux, mais seule la
// forme objet permet en plus au lien « retour » de la fiche structure de
// pointer précisément vers CET événement plutôt que vers la liste générique.
$suffixeDepuis = $isEdit ? '&depuis=evenement:' . (int) $id : ($ntCle !== null ? '&depuis=' . $ntCle : '');
?>
<?php require __DIR__ . '/_page_head_band.php'; ?>

<div class="module-content"><div class="module-content-inner">
<div class="page-head entete-editable">
    <?= lien_retour_contextuel('?p=evenements', 'Événements') ?>
    <?php if ($isEdit): ?>
    <div class="head-actions">
        <?php // La feuille de route se consulte d'ici, sans descendre la page :
              // c'est ce qu'on ouvre avant de partir. Disponible aussi en
              // lecture seule — consulter n'est pas modifier.
              //
              // [data-preview] : la fenêtre d'aperçu partagée (views/layout.php),
              // celle d'un décompte de salaire, d'un certificat ou d'un bilan.
              // Elle affiche la page d'impression dans un cadre à la largeur
              // d'une feuille, avec sa croix de fermeture, Échap et le clic hors
              // cadre — et c'est la barre de cette page-là qui porte
              // « Imprimer / PDF ». Sans JavaScript, le lien ouvre simplement
              // la page. ?>
        <a class="btn ghost entete-lecture" href="?p=evenement_feuille_imprimer&id=<?= (int) $id ?>" data-preview target="_blank" rel="noopener"
           title="Voir la feuille de route"><?= icon('eye') ?><span class="lbl"> Feuille de route</span></a>
        <?php if ($peutEcrireEv): ?>
        <?php // Cette page n'a pas d'écran de modification : tout s'y édite en
              // place, carte par carte. La suppression de la DATE ELLE-MÊME ne
              // peut donc pas aller « sur l'écran de modification » comme
              // ailleurs (docs/UI.md § 1) : c'est le crayon de la barre qui la
              // découvre, et la croix qui la referme — sans recharger. Pendant
              // ce temps « Feuille de route » s'efface : on consulte ou on
              // décide du sort de la date, pas les deux. ?>
        <form method="post" action="?p=evenement_supprimer" class="d-inline entete-edition" hidden data-confirm="<?= e($confirmSuppr) ?>">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int) $id ?>">
            <button type="submit" class="btn danger icon-only" title="Supprimer" aria-label="Supprimer la date"><?= icon('trash') ?></button>
        </form>
        <button type="button" class="btn ghost icon-only entete-edit-btn" title="Modifier"
                aria-label="Modifier cette date"><?= icon('pencil') ?></button>
        <button type="button" class="btn ghost icon-only entete-annuler-btn entete-edition" hidden
                title="Annuler" aria-label="Annuler"><?= icon('x') ?></button>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php // À la création, le titre et les commandes du formulaire tiennent le
          // même en-tête : cet écran EST un formulaire, il n'y a rien à lire
          // qu'un crayon ouvrirait (entete_form_actions_html()). ?>
    <?php if (!$isEdit): ?>
    <h1><?= e('Nouvel événement') ?></h1>
    <?php if ($peutEcrireEv): ?>
    <?= entete_form_actions_html('evenement-creation-form', $retour) ?>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>

<?php if (!$isEdit && !$peutEcrireEv): ?>
<p class="err">Vous n'avez pas les droits d'écriture nécessaires pour cette action.</p>
<?php elseif (!$isEdit): ?>
<!-- Création : formulaire unique, inchangé — le multi-cadre lecture/édition
     ci-dessous n'a de sens qu'une fois l'événement déjà renseigné. -->
<div class="card">
    <h2 class="mt-0">Informations</h2>
    <form method="post" action="?p=evenement<?= $depuisQs ?>" class="form" id="evenement-creation-form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <div class="grid4">
            <label>Date <input type="date" name="date" value="<?= $v('date') ?>" required></label>
            <div>
                <?= projet_entete_actifs_html(evenements_terme_projet(false), 'ev-projet-new', '#ev-projet-new') ?>
                <select name="projet_id" id="ev-projet-new">
                    <option value="">—</option>
                    <?php foreach ($projets as $s): ?>
                        <option value="<?= (int) $s['id'] ?>" <?= $vRaw('projet_id') === (string) $s['id'] ? 'selected' : '' ?><?= ($s['actif'] ?? true) ? '' : ' data-projet-inactif' ?>><?= e($s['nom']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <label>Statut
                <select name="statut">
                    <?php foreach (EVENEMENTS_STATUTS as $s): ?>
                        <option value="<?= $s ?>" <?= $vRaw('statut', 'option') === $s ? 'selected' : '' ?>><?= e(evenement_statut_libelle($s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="field-group">
                <span>Type d'audience <?= info_tip(
                    "Public : affiché sur le site avec ville, salle, festival, lien, " . mb_strtolower(evenements_terme_projet(false)) . " et remarques. "
                    . "Privé : seule la date apparaît, avec la mention « Événement privé ». "
                    . "Non répertorié : n'apparaît jamais sur le site (usage interne)."
                ) ?></span>
                <?= icon_picker('visibilite', [
                    'public'         => ['icone' => 'earth', 'label' => evenement_visibilite_libelle('public')],
                    'prive'          => ['icone' => 'earth-lock', 'label' => evenement_visibilite_libelle('prive')],
                    'non_repertorie' => ['icone' => 'globe-off', 'label' => evenement_visibilite_libelle('non_repertorie')],
                ], $vRaw('visibilite', 'non_repertorie'), "Type d'audience") ?>
            </div>
        </div>

        <div class="grid4">
            <label>Ville <input name="ville" value="<?= $v('ville') ?>"></label>
            <label>Département/canton, région et pays
                <div class="field-pair">
                    <input name="departement_canton" value="<?= $v('departement_canton') ?>" placeholder="canton ou département">
                    <select name="grande_region" class="region-select" title="Région (Normandie, Romandie… — se gère dans Paramètres → Pays)">
                        <option value="">— Région —</option>
                        <?= region_options_nom(pays_nom_depuis_code($vRaw('pays')), $v('grande_region')) ?>
                    </select>
                    <select name="pays" class="pays-select">
                        <option value="">—</option>
                        <?= pays_options_code($vRaw('pays')) ?>
                    </select>
                </div>
            </label>
            <label>Salle <input name="salle" value="<?= $v('salle') ?>"></label>
            <label>Festival <input name="festival" value="<?= $v('festival') ?>"></label>
            <?php if ($peutLierLieu): ?>
            <label><span>Lieu (base) <?= info_tip("Rattacher l'événement à un lieu de la base (booking) : il apparaîtra dans l'historique du lieu et de sa structure. Laisser vide pour ne pas lier. D'autres lieux pourront être ajoutés une fois l'événement créé, depuis la carte « Organisation ».") ?></span>
                <div class="cat-search" id="evt-lieu-search">
                    <input type="text" class="cat-search-input" placeholder="Rechercher un lieu…" autocomplete="off" value="<?= $lieuActuel ? e((string) $lieuActuel['nom'] . ((string) $lieuActuel['ville'] !== '' ? ' — ' . (string) $lieuActuel['ville'] : '')) : '' ?>">
                    <input type="hidden" name="lieu_id" class="cat-search-val" value="<?= $lieuActuel ? (int) $lieuActuel['id'] : '' ?>">
                    <ul class="cat-search-list" hidden role="listbox"></ul>
                </div>
            </label>
            <?php endif; ?>
        </div>
        <div class="grid3">
            <label>Lien <input type="url" name="lien_infos" value="<?= $v('lien_infos') ?>" placeholder="https://…"></label>
            <label>Texte du bouton de lien <input name="lien_texte" value="<?= $v('lien_texte') ?>" placeholder="Plus d'informations"></label>
            <label>Remarques <input name="remarques" value="<?= $v('remarques') ?>"></label>
        </div>

    </form>
</div>
<?php else: ?>

<div class="grid3">
<?php $statutCardClass = match ((string) $evenement['statut']) { 'confirme' => 'card-statut-confirme', 'annule' => 'card-statut-annule', default => 'card-statut-option' }; ?>
<div class="card card-editable <?= $statutCardClass ?>" id="carte-informations">
    <?php if ($peutEcrireEv): ?>
    <?= carte_actions_html(['form' => 'informations-form', 'overlay' => true]) ?>
    <?php endif; ?>
    <?php if ($ok === 'informations'): ?><p class="ok flash">Informations enregistrées.</p><?php endif; ?>
    <?php if ($errInformationsMsg): ?><p class="err"><?= e($errInformationsMsg) ?></p><?php endif; ?>

    <div class="card-disp">
        <div class="info-date"><?= e(date('d.m.Y', strtotime((string) $evenement['date']))) ?></div>
        <?php // L'horaire sur sa propre ligne, sous la date : c'est la même
              // information — quand —, mais la date reste ce qu'on lit en
              // premier. Accolé, il passait à la ligne de lui-même, la date
              // occupant déjà la largeur laissée par les boutons flottants. ?>
        <?php $horaireEv = evenement_horaire_texte((array) $evenement); ?>
        <?php if ($horaireEv !== ''): ?><div class="info-heure"><?= e($horaireEv) ?></div><?php endif; ?>
        <div class="info-projet"><?= $evenement['projet_nom'] ? e($evenement['projet_nom']) : '—' ?></div>
        <table class="kv-table">
            <tr>
                <th>Statut</th>
                <td><span class="ico-label <?php
                    echo match ((string) $evenement['statut']) { 'confirme' => 'ico-ok', 'annule' => 'muted', default => 'ico-amber' };
                ?>"><?= icon(evenement_statut_icone((string) $evenement['statut'])) ?> <?= e(evenement_statut_libelle((string) $evenement['statut'])) ?></span></td>
            </tr>
            <tr>
                <th>Audience</th>
                <td><span class="ico-label"><?= icon(evenement_visibilite_icone((string) $evenement['visibilite'])) ?> <?= e(evenement_visibilite_libelle((string) $evenement['visibilite'])) ?></span></td>
            </tr>
            <tr>
                <th>Salle à afficher</th>
                <td><?= trim((string) $evenement['salle']) !== '' ? e($evenement['salle']) : '—' ?></td>
            </tr>
            <tr>
                <th>Festival à afficher</th>
                <td><?= trim((string) $evenement['festival']) !== '' ? e($evenement['festival']) : '—' ?></td>
            </tr>
            <tr>
                <th>Lien</th>
                <td><?php if (trim((string) $evenement['lien_infos']) !== ''): ?><a href="<?= e($evenement['lien_infos']) ?>" target="_blank" rel="noopener"><?= e($evenement['lien_texte'] ?: $evenement['lien_infos']) ?></a><?php else: ?>—<?php endif; ?></td>
            </tr>
            <tr>
                <th>Remarques</th>
                <td><?= trim((string) $evenement['remarques']) !== '' ? e($evenement['remarques']) : '—' ?></td>
            </tr>
        </table>
    </div>

    <form method="post" id="informations-form" action="?p=evenement_informations_enregistrer<?= $depuisQs ?>" class="card-edit form" hidden>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= (int) $id ?>">
        <?php // Champ court : la date n'a pas besoin de toute la largeur, et
              // surtout elle passait sous les boutons flottants de la carte
              // (.card-actions-overlay), qui se retrouvaient posés dessus. ?>
        <label class="champ-court">Date <input type="date" name="date" value="<?= $v('date') ?>" required></label>
        <?php // Heures facultatives : une date de tournée se pose des mois avant
              // que l'horaire soit connu. Publiques comme la date — elles partent
              // dans l'export et le flux iCal d'un événement public. ?>
        <div class="grid2">
            <label>Début <input type="time" name="heure_debut" value="<?= $v('heure_debut') ?>"></label>
            <label>Fin <input type="time" name="heure_fin" value="<?= $v('heure_fin') ?>"></label>
        </div>
        <div>
            <?= projet_entete_actifs_html(evenements_terme_projet(false), 'ev-projet', '#ev-projet') ?>
            <select name="projet_id" id="ev-projet">
                <option value="">—</option>
                <?php foreach ($projets as $s): ?>
                    <option value="<?= (int) $s['id'] ?>" <?= $vRaw('projet_id') === (string) $s['id'] ? 'selected' : '' ?><?= ($s['actif'] ?? true) ? '' : ' data-projet-inactif' ?>><?= e($s['nom']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <label>Statut
            <select name="statut">
                <?php foreach (EVENEMENTS_STATUTS as $s): ?>
                    <option value="<?= $s ?>" <?= $vRaw('statut', 'option') === $s ? 'selected' : '' ?>><?= e(evenement_statut_libelle($s)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="field-group">
            <span>Audience <?= info_tip(
                "Public : affiché sur le site avec ville, salle, festival, lien, " . mb_strtolower(evenements_terme_projet(false)) . " et remarques. "
                . "Privé : seule la date apparaît, avec la mention « Événement privé ». "
                . "Non répertorié : n'apparaît jamais sur le site (usage interne)."
            ) ?></span>
            <?= icon_picker('visibilite', [
                'public'         => ['icone' => 'earth', 'label' => evenement_visibilite_libelle('public')],
                'prive'          => ['icone' => 'earth-lock', 'label' => evenement_visibilite_libelle('prive')],
                'non_repertorie' => ['icone' => 'globe-off', 'label' => evenement_visibilite_libelle('non_repertorie')],
            ], $vRaw('visibilite', 'non_repertorie'), "Audience") ?>
        </div>
        <label>Salle <input name="salle" value="<?= $v('salle') ?>"></label>
        <label>Festival <input name="festival" value="<?= $v('festival') ?>"></label>
        <label>Lien <input type="url" name="lien_infos" value="<?= $v('lien_infos') ?>" placeholder="https://…"></label>
        <label>Texte du bouton de lien <input name="lien_texte" value="<?= $v('lien_texte') ?>" placeholder="Plus d'informations"></label>
        <label>Remarques <input name="remarques" value="<?= $v('remarques') ?>"></label>
    </form>
</div>
<div class="card card-flush card-editable" id="carte-localisation">
    <?php if ($ok === 'localisation'): ?><p class="ok flash">Localisation enregistrée.</p><?php endif; ?>

    <div class="loc-header blur-glass">
        <div>
            <?php $drapeauEv = pays_drapeau((string) $evenement['pays']); ?>
            <?php $villeHtmlEv = ville_departement_canton_html((string) $evenement['ville'], $drapeauEv, (string) $evenement['pays'], (string) $evenement['departement_canton']); ?>
            <div><?= $villeHtmlEv !== '' ? $villeHtmlEv : '<span class="muted small">Ville non renseignée.</span>' ?></div>
            <?php // Rue et NPA sous la ville, comme sur une fiche structure :
                  // c'est l'adresse à laquelle on se rend le jour même. ?>
            <?php $adresseEv = evenement_adresse_texte((array) $evenement); ?>
            <?php if ($adresseEv !== '' && $adresseEv !== trim((string) $evenement['ville'])): ?><div class="muted small"><?= e($adresseEv) ?></div><?php endif; ?>
            <?php if (trim((string) $evenement['grande_region']) !== ''): ?><div class="muted small"><?= e($evenement['grande_region']) ?></div><?php endif; ?>
        </div>
        <?php if ($peutEcrireEv): ?>
        <?= carte_actions_html(['form' => 'localisation-form']) ?>
        <?php endif; ?>
    </div>

    <div class="card-disp">
        <div class="loc-map-bg">
            <?php if (trim((string) ($evenement['ville'] ?? '')) !== ''): ?>
                <?php
                $miniCarteVille = (string) $evenement['ville'];
                $miniCarteDepartementCanton = (string) ($evenement['departement_canton'] ?? '');
                $miniCartePays = pays_nom_depuis_code((string) ($evenement['pays'] ?? ''));
                $miniCarteRetourRoute = 'evenement';
                $miniCarteRetourId = (int) $id;
                require __DIR__ . '/_mini_carte.php';
                ?>
            <?php else: ?>
                <p class="muted small">Aucune ville renseignée.</p>
            <?php endif; ?>
        </div>
    </div>

    <form method="post" id="localisation-form" action="?p=evenement_localisation_enregistrer<?= $depuisQs ?>" class="card-edit form" hidden>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= (int) $id ?>">
        <input name="adresse_rue" value="<?= $v('adresse_rue') ?>" placeholder="Rue et numéro" aria-label="Rue et numéro" class="mb-16">
        <div class="grid2">
            <label>NPA <input name="adresse_npa" value="<?= $v('adresse_npa') ?>" placeholder="code postal"></label>
            <label>Ville <input name="ville" value="<?= $v('ville') ?>"></label>
        </div>
        <div class="grid2">
            <label>Département/canton <input name="departement_canton" value="<?= $v('departement_canton') ?>" placeholder="canton ou département"></label>
        </div>
        <div class="grid2">
            <label>Région
                <select name="grande_region" class="region-select" title="Région (Normandie, Romandie… — se gère dans Paramètres → Pays)">
                    <option value="">— Région —</option>
                    <?= region_options_nom(pays_nom_depuis_code($vRaw('pays')), $v('grande_region')) ?>
                </select>
            </label>
            <label>Pays
                <select name="pays" class="pays-select">
                    <option value="">—</option>
                    <?= pays_options_code($vRaw('pays')) ?>
                </select>
            </label>
        </div>
    </form>
</div>

<?php if ($peutLierLieu || module_actif('facturation')): ?>
<div class="card card-editable" id="carte-organisation">
    <div class="page-head">
        <h2 class="mt-0">Organisation</h2>
        <?php if ($peutEcrireEv): ?>
        <?= carte_actions_html(['form' => 'organisation-form']) ?>
        <?php endif; ?>
    </div>
    <?php if ($ok === 'organisation'): ?><p class="ok flash">Organisation enregistrée.</p><?php endif; ?>
    <?php if ($errOrganisation): ?><p class="err">Le nom de la nouvelle structure est obligatoire.</p><?php endif; ?>

    <div class="card-disp">
        <?php if (!$structuresLiees): ?>
        <p class="muted small">Aucune structure liée.</p>
        <?php else: ?>
        <?php // Mêmes informations et même vocabulaire visuel que les fiches de
              // ?p=structures sur téléphone : statut porté par un repère coloré,
              // nom, ville avec drapeau et canton, catégorie et les deux dates à
              // droite. Les étiquettes en sont absentes — elles demanderaient une
              // jointure de plus pour un contexte où l'on cherche qui organise,
              // pas comment le relancer. ?>
        <ul class="mini-structures">
            <?php foreach ($structuresLiees as $s): ?>
            <?php
                $msVille = ville_departement_canton_html(
                    (string) $s['adresse_localite'], pays_drapeau_nom((string) $s['adresse_pays']),
                    (string) $s['adresse_pays'], (string) $s['departement_canton']
                );
                $msMaj = trim((string) ($s['mise_a_jour_le'] ?? ''));
                $msCree = trim((string) ($s['cree_le'] ?? ''));
                $msVu = trim((string) ($s['dernier_contact_le'] ?? ''));
            ?>
            <li class="mini-structure<?= $s['statut'] === 'inactif' ? ' inactif' : '' ?>" data-statut="<?= e((string) $s['statut']) ?>">
                <a href="?p=structure&id=<?= (int) $s['id'] ?><?= $suffixeDepuis ?>" class="ms-lien">
                    <?php // L'icône de statut elle-même, et non un carré : il n'y a pas de
                          // case à cocher ici, et un carré bordé en tenait faussement lieu. ?>
                    <span class="ms-puce <?= e(structure_statut_icone_classe((string) $s['statut'])) ?>" title="<?= e(structure_statut_libelle((string) $s['statut'])) ?>"><?= icon(structure_statut_icone((string) $s['statut'])) ?></span>
                    <span class="ms-corps">
                        <span class="ms-nom"><?php if ($s['est_facturation']): ?><span class="ico-tiny" title="Structure à facturer / SUISA"><?= icon('receipt-swiss-franc') ?></span> <?php endif; ?><?= e($s['nom']) ?></span>
                        <span class="ms-lieu"><?= $msVille !== '' ? $msVille : '<span class="muted">—</span>' ?></span>
                    </span>
                    <span class="ms-cote">
                        <span class="ms-cat"><?= e(trim((string) ($s['sous_categorie'] ?: $s['categorie']))) ?></span>
                        <span class="ms-dates">
                            <span class="ms-maj<?= $msMaj === '' && $msCree !== '' ? ' est-creation' : '' ?>"><?php
                                if ($msMaj !== '') { echo e(date('d.m.Y', strtotime($msMaj))); }
                                elseif ($msCree !== '') { echo e(date('d.m.Y', strtotime($msCree))); }
                                else { echo '—'; }
                            ?></span>
                            <span class="ms-vu"><?= $msVu !== '' ? e(date('d.m.Y', strtotime($msVu))) : '—' ?></span>
                        </span>
                    </span>
                    <?php
                        $msTags = ($s['tags_noms'] ?? '') !== '' ? array_map(
                            fn ($paire) => explode("\x1f", $paire, 2) + ['', ''],
                            explode("\x1e", (string) $s['tags_noms'])
                        ) : [];
                    ?>
                    <?php if ($msTags): ?>
                    <?php // Pas de bouton « + » ici : cette carte montre l'organisation
                          // de l'événement, on étiquette une structure depuis sa fiche. ?>
                    <span class="ms-tags">
                        <?php foreach ($msTags as [$tn, $tc]): ?><span class="badge"<?= badge_style_html((string) $tc) ?>><?= e((string) $tn) ?></span><?php endforeach; ?>
                    </span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>

    <form method="post" action="?p=evenement_organisation_enregistrer<?= $depuisQs ?>" class="card-edit form" hidden id="organisation-form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= (int) $id ?>">

        <div class="tags-liste" id="organisation-structures-chips">
            <?php foreach ($structuresLiees as $s): ?>
                <span class="badge" data-id="<?= (int) $s['id'] ?>">
                    <button type="button" class="btn-tag-facturation<?= $s['est_facturation'] ? ' on' : '' ?>" title="Marquer comme structure à facturer / SUISA" aria-label="Marquer comme structure à facturer"><?= icon('receipt-swiss-franc') ?></button>
                    <?= e($s['nom']) ?><?= trim((string) $s['ville']) !== '' ? ' — ' . e($s['ville']) : '' ?>
                    <input type="hidden" name="structure_ids[]" value="<?= (int) $s['id'] ?>">
                    <button type="button" class="btn-tag-x chip-remove" aria-label="Retirer">×</button>
                </span>
            <?php endforeach; ?>
        </div>
        <input type="hidden" name="facturation_id" id="organisation-facturation-id" value="<?php
            $facturationActuelle = array_values(array_filter($structuresLiees, fn ($s) => $s['est_facturation']));
            echo $facturationActuelle ? (int) $facturationActuelle[0]['id'] : '';
        ?>">
        <div class="cat-search" id="evt-structure-add-search">
            <input type="text" class="cat-search-input" placeholder="Ajouter une structure…" autocomplete="off">
            <input type="hidden" class="cat-search-val" value="">
            <ul class="cat-search-list" hidden role="listbox">
                <?php if (module_actif('facturation')): ?><li data-val="__new__">+ Nouvelle structure</li><?php endif; ?>
            </ul>
        </div>
        <?php if (module_actif('facturation')): ?>
        <div id="organisation-nouveau" class="grid2" hidden>
            <label>Nom / raison sociale <input name="org_nom"></label>
            <label>Rue et numéro <input name="org_adresse_rue"></label>
            <label>NPA <input name="org_adresse_npa"></label>
            <label>Localité <input name="org_adresse_localite"></label>
            <label>Pays <select name="org_adresse_pays"><?= pays_options_nom('Suisse') ?></select></label>
            <?php // Ces quatre champs créent un CONTACT de la structure, pas des
                  // champs de la structure : c'est dans sa carte « Contacts »
                  // qu'ils se relisent et se corrigent. ?>
            <label>Prénom du contact (optionnel) <input name="org_prenom"></label>
            <label>Nom du contact (optionnel) <input name="org_nom_contact"></label>
            <label>E-mail (optionnel) <input name="org_email" type="email"></label>
            <label>Téléphone (optionnel) <input name="org_telephone" type="tel"></label>
        </div>
        <?php endif; ?>
    </form>
</div>
<?php endif; ?>

</div>
<?php // Le déroulé de la journée, section de la feuille de route qu'on
      // compose ici. Placée après l'organisation et avant les employés — on lit
      // dans cet ordre : où, avec qui, puis comment la journée se déroule. ?>
<?php if ($id): ?><?php require __DIR__ . '/_evenement_feuille.php'; ?><?php endif; ?>
<div class="card mt-22" id="carte-employes">
    <div class="page-head">
        <h2 class="mt-0">Employés <?= info_tip(
            "Une fiche de salaire ne peut être liée que via un employé lié — une seule ligne de "
            . "prestation par événement. Pour un cachet couvrant plusieurs dates, ajoutez la "
            . "prestation depuis un seul des événements de la tournée et liez les autres depuis "
            . "la fiche elle-même."
        ) ?></h2>
        <?php if ($peutEcrireEv): ?>
        <div class="head-actions">
            <form method="post" action="?p=evenement_production_externe_enregistrer<?= $depuisQs ?>" id="prod-externe-form">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= (int) $id ?>">
                <label class="check">
                    <input type="checkbox" name="production_externe" id="prod-externe-check" value="1" <?= $prodExterne ? 'checked' : '' ?>>
                    Production externe
                </label>
            </form>
            <?php if ($employesDispo): ?>
                <form method="post" action="?p=evenement_employe_lier<?= $depuisQs ?>" class="linked-add"
                        data-ajout="#employes-lies" data-ajout-vide="#employes-lies-vide" data-ajout-message="Employé ajouté.">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= (int) $id ?>">
                    <select name="employe_id">
                        <?php foreach ($employesDispo as $emp): ?>
                            <option value="<?= (int) $emp['id'] ?>"><?= e($emp['prenom'] . ' ' . $emp['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php // user-plus et non plus : c'est quelqu'un qu'on ajoute,
                          // comme « Nouvel employé » (?p=employes) et « Nouveau
                          // contact » (carte Contacts d'une structure). ?>
                    <button type="submit" class="btn ghost" title="Ajouter" aria-label="Ajouter cet employé"><?= icon('user-plus') ?><span class="lbl"> Ajouter</span></button>
                </form>
            <?php endif; ?>
        </div>
        <?php elseif ($prodExterne): ?>
        <span class="badge">Production externe</span>
        <?php endif; ?>
    </div>
    <?php if ($errLigne === '1'): ?><p class="err">Prestation invalide : vérifiez l'unité, la quantité et le taux horaire.</p><?php endif; ?>
    <?php if ($errLigne === 'payee'): ?><p class="err">La fiche de ce mois a déjà été payée : créez plutôt une fiche complémentaire depuis « Fiches de salaire ».</p><?php endif; ?>
    <?php if ($errEmploye === 'paye'): ?><p class="err">Impossible de retirer cet employé : sa prestation pour cet événement a déjà été payée.</p><?php endif; ?>
    <?php if ($errProdExterne === 'paye'): ?><p class="err">Impossible d'activer « Production externe » : une prestation liée est déjà sur une fiche payée (figée, jamais modifiée). Retirez-la manuellement d'abord.</p><?php endif; ?>

    <?php if ($prodExterne): ?>
        <!-- Production externe : pas de prestation/fiche de salaire à gérer ici,
             juste la liste des employés (cachet géré par l'organisateur externe). -->
        <div class="table-scroll">
        <table class="list evenement-employes">
            <thead><tr><th>Employé</th><th></th></tr></thead>
            <tbody id="employes-lies">
            <?php if (!$employesLies): ?>
                <tr id="employes-lies-vide"><td colspan="2" class="muted small">Aucun employé lié.</td></tr>
            <?php endif; ?>
            <?php foreach ($employesLies as $emp): ?>
                <?php require __DIR__ . '/_evenement_employe_ligne_externe.php'; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php else: ?>
        <?php $colspanMsg = 4 + ($axes ? 1 : 0); ?>
        <div class="table-scroll">
        <table class="list evenement-employes">
            <?php // Les classes de colonne sont posées à la main, jamais par
                  // :nth-child : la colonne « Axe » n'existe que si le module
                  // analytique est actif, et tout décompte de position se
                  // décalerait d'une colonne sans lui. ?>
            <thead><tr>
                <th class="epf-col-serre">Employé</th>
                <th class="epf-col-serre">Fiche de salaire</th>
                <?php if ($axes): ?><th class="epf-col-serre">Axe</th><?php endif; ?>
                <th>Durée et taux horaire</th>
                <th class="num epf-col-serre">Total brut</th>
                <th class="epf-col-serre"></th>
            </tr></thead>
            <tbody id="employes-lies">
            <?php if (!$employesLies): ?>
                <tr id="employes-lies-vide"><td colspan="<?= $colspanMsg + 2 ?>" class="muted small">Aucun employé lié.</td></tr>
            <?php endif; ?>
            <?php foreach ($employesLies as $emp): ?>
                <?php require __DIR__ . '/_evenement_employe_ligne.php'; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<div class="grid3 mt-22">
<div class="card card-editable">
    <?php $suisaApplicable = (bool) $evenement['suisa_applicable']; ?>
    <div class="page-head">
        <h2 class="mt-0">SUISA <?= evenement_suisa_badge($evenement) ?></h2>
        <?php if ($peutEcrireEv): ?>
        <?= carte_actions_html(['form' => 'suisa-form']) ?>
        <?php endif; ?>
    </div>
    <?php if ($ok === 'suisa'): ?><p class="ok flash">SUISA enregistré.</p><?php endif; ?>

    <?php // Lecture par défaut, comme les autres cartes de la fiche : ce qui a
          // été déclaré, et quand. Les trois lignes ne s'affichent que si la
          // SUISA s'applique — sinon il n'y a rien à dire de plus que la phrase
          // qui le dit. ?>
    <div class="card-disp">
        <?php if (!$suisaApplicable): ?>
        <p class="muted mb-0">Ne s'applique pas à cette date.</p>
        <?php else: ?>
        <?php
        $suisaEnvoyeA  = trim((string) ($evenement['suisa_envoye_a'] ?? ''));
        $suisaEnvoyeLe = trim((string) ($evenement['suisa_envoye_le'] ?? ''));
        $suisaDecompte = trim((string) ($evenement['suisa_decompte_le'] ?? ''));
        $suisaJour = fn (string $d): string => $d !== '' ? date('d.m.Y', strtotime($d)) : '';
        $suisaVide = '<span class="muted">—</span>';
        ?>
        <table class="kv-table">
            <tr><th>Envoyée à</th><td><?= $suisaEnvoyeA !== '' ? e(evenement_suisa_envoye_a_libelle($suisaEnvoyeA)) : $suisaVide ?></td></tr>
            <tr><th>Date d'envoi</th><td><?= $suisaEnvoyeLe !== '' ? e($suisaJour($suisaEnvoyeLe)) : $suisaVide ?></td></tr>
            <tr><th>Date du décompte</th><td><?= $suisaDecompte !== '' ? e($suisaJour($suisaDecompte)) : $suisaVide ?></td></tr>
        </table>
        <?php endif; ?>
    </div>

    <?php if ($peutEcrireEv): ?>
    <?php // « S'applique » rejoint le formulaire : c'est un champ comme les
          // autres, qui ne se coche qu'en édition. Les trois champs qu'il
          // commande restent masqués tant qu'il n'est pas coché (script en bas
          // de page). ?>
    <form method="post" id="suisa-form" action="?p=evenement_suisa_enregistrer<?= $depuisQs ?>" class="card-edit form" hidden>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= (int) $id ?>">
        <label class="check mb-16">
            <input type="checkbox" name="suisa_applicable" id="suisa-applicable" value="1" <?= $suisaApplicable ? 'checked' : '' ?>>
            s'applique
        </label>
        <div id="suisa-champs" <?= $suisaApplicable ? '' : 'hidden' ?>>
            <div class="grid2">
                <label>Envoyée à
                    <select name="suisa_envoye_a" <?= $suisaApplicable ? '' : 'disabled' ?>>
                        <option value="">—</option>
                        <?php foreach (EVENEMENTS_SUISA_ENVOYE_A as $ea): ?>
                            <option value="<?= e($ea) ?>" <?= $vRaw('suisa_envoye_a') === $ea ? 'selected' : '' ?>><?= e(evenement_suisa_envoye_a_libelle($ea)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Date d'envoi <input type="date" name="suisa_envoye_le" value="<?= $v('suisa_envoye_le') ?>" <?= $suisaApplicable ? '' : 'disabled' ?>></label>
            </div>
            <label>Date du décompte <input type="date" name="suisa_decompte_le" value="<?= $v('suisa_decompte_le') ?>" <?= $suisaApplicable ? '' : 'disabled' ?>></label>
        </div>
    </form>
    <?php endif; ?>
</div>

<?php // Pas de carte « Comptabilité analytique » ici : l'axe appartient au
      // PROJET de la date (migration_91), il se règle sur sa fiche. Une date ne
      // choisit plus le sien — elle en hérite, et toutes les dates d'une même
      // création se ventilent donc au même endroit. ?>
<div class="card">
    <div class="page-head">
        <h2 class="mt-0">Factures liées</h2>
        <?php if (module_actif('facturation') && peut_ecrire('facturation')): ?>
            <a class="btn ghost" href="?p=facture_form&evenement_id=<?= (int) $id ?>"><?= icon('file-plus') ?> Créer</a>
        <?php endif; ?>
    </div>
    <?php // Le tableau est rendu même vide, et c'est une LIGNE qui porte le
          // « aucune facture » : une facture liée en arrière-plan s'insère alors
          // dans un <tbody> qui existe déjà (docs/UI.md § 5). ?>
    <table class="list">
        <thead><tr><th>Numéro</th><th>Structure</th><th class="num">Montant</th><th>Statut</th><th></th></tr></thead>
        <tbody id="factures-liees">
        <?php if (!$factures): ?>
            <tr id="factures-liees-vide"><td colspan="5" class="muted small">Aucune facture liée à cet événement.</td></tr>
        <?php endif; ?>
        <?php foreach ($factures as $fa): ?>
            <?php require __DIR__ . '/_evenement_facture_ligne.php'; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if (module_actif('facturation') && $facturesDispo && $peutEcrireEv): ?>
        <form method="post" action="?p=evenement_facture_lier<?= $depuisQs ?>" class="linked-add mt-18"
              data-ajout="#factures-liees" data-ajout-vide="#factures-liees-vide" data-ajout-message="Facture liée.">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int) $id ?>">
            <div class="cat-search facture-search" data-cat-search data-texte-vide data-vider-en-saisie>
                <input type="text" class="cat-search-input" placeholder="Rechercher une facture à lier…" autocomplete="off">
                <input type="hidden" name="facture_id" class="cat-search-val" value="">
                <ul class="cat-search-list" hidden role="listbox">
                    <?php foreach ($facturesDispo as $fa):
                        $label = ($fa['numero'] !== '' ? $fa['numero'] : '(brouillon)') . ' — ' . $fa['structure_nom'] . ' — ' . chf((float) $fa['montant_total']) . ' CHF';
                    ?>
                        <li data-val="<?= (int) $fa['id'] ?>"><?= e($label) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <button type="submit" class="btn ghost" title="Lier" aria-label="Lier cette facture"><?= icon('link') ?><span class="lbl"> Lier</span></button>
        </form>
    <?php endif; ?>
</div>
</div>
<?php endif; ?>
</div></div>

<?php require __DIR__ . '/_evenement_script.php'; ?>
<?php require __DIR__ . '/_region_select_js.php'; ?>
