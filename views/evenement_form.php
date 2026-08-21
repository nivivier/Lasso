<?php
/** @var ?array $evenement */ /** @var int $id */ /** @var array $spectacles */ /** @var array $spectacleMap */
/** @var array $employesLies */ /** @var array $employesDispo */ /** @var array $prestations */
/** @var array $fichesParEmploye */ /** @var array $unites */ /** @var array $tauxHoraires */
/** @var array $factures */ /** @var array $facturesDispo */
/** @var array $structuresLiees */
/** @var array $paysDisponibles */ /** @var array $axes */ /** @var ?string $err */ /** @var array $post */
$isEdit = $id > 0;
$peutEcrireEv = peut_ecrire('evenements');
$v = fn (string $k, $d = '') => e((string) ($post[$k] ?? $evenement[$k] ?? $d));
$vRaw = fn (string $k, $d = '') => (string) ($post[$k] ?? $evenement[$k] ?? $d);
$retour = $isEdit ? '?p=evenement&id=' . (int) $id : '?p=evenements_liste';
// Reporté sur les formulaires de cette page qui redirigent vers elle-même,
// pour que le lien de retour contextuel (lien_retour_contextuel()) survive à
// un enregistrement (voir redirect() dans lib/helpers.php).
$depuisQs = isset($_GET['depuis']) ? '&depuis=' . rawurlencode($_GET['depuis']) : '';
$ok = $_GET['ok'] ?? null;
$errLigne = $_GET['errLigne'] ?? null;
$errEmploye = $_GET['errEmploye'] ?? null;
$errOrganisation = ($_GET['errOrganisation'] ?? null) === '1';
$errProdExterne = $_GET['errProdExterne'] ?? null;
$errInformationsMsg = [
    'date'       => 'La date est invalide.',
    'spectacle'  => 'Spectacle invalide.',
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
// Le spectacle déjà lié peut avoir gagné des enfants depuis (devenu un groupe
// « artiste », non assignable) : on le garde visible dans le select pour ne pas
// changer silencieusement l'événement au prochain enregistrement.
$spectacleActuelId = (int) $vRaw('spectacle_id', '0');
if ($spectacleActuelId && !array_filter($spectacles, fn($s) => (int) $s['id'] === $spectacleActuelId)) {
    if (isset($spectacleMap[$spectacleActuelId])) {
        $spectacles[] = ['id' => $spectacleActuelId, 'nom' => spectacle_chemin($spectacleActuelId, $spectacleMap) . ' (groupe, non réassignable)'];
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
<div class="page-head">
    <?= lien_retour_contextuel('?p=evenements_liste', 'Événements') ?>
    <?php if ($isEdit && $peutEcrireEv): ?>
    <div class="head-actions">
        <form method="post" action="?p=evenement_delete" class="d-inline" data-confirm="<?= e($confirmSuppr) ?>">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int) $id ?>">
            <button type="submit" class="btn danger icon-only" title="Supprimer" aria-label="Supprimer"><?= icon('trash') ?></button>
        </form>
    </div>
    <?php endif; ?>
</div>
<?php if (!$isEdit): ?><h1><?= e('Nouvel événement') ?></h1><?php endif; ?>

<?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>

<?php if (!$isEdit && !$peutEcrireEv): ?>
<p class="err">Vous n'avez pas les droits d'écriture nécessaires pour cette action.</p>
<?php elseif (!$isEdit): ?>
<!-- Création : formulaire unique, inchangé — le multi-cadre lecture/édition
     ci-dessous n'a de sens qu'une fois l'événement déjà renseigné. -->
<div class="card">
    <h2 class="mt-0">Informations</h2>
    <form method="post" action="?p=evenement<?= $depuisQs ?>" class="form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <div class="grid4">
            <label>Date <input type="date" name="date" value="<?= $v('date') ?>" required></label>
            <label><?= e(evenements_terme_spectacle(false)) ?>
                <select name="spectacle_id">
                    <option value="">—</option>
                    <?php foreach ($spectacles as $s): ?>
                        <option value="<?= (int) $s['id'] ?>" <?= $vRaw('spectacle_id') === (string) $s['id'] ? 'selected' : '' ?>><?= e($s['nom']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Statut
                <select name="statut">
                    <?php foreach (EVENEMENTS_STATUTS as $s): ?>
                        <option value="<?= $s ?>" <?= $vRaw('statut', 'option') === $s ? 'selected' : '' ?>><?= e(evenement_statut_libelle($s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="field-group">
                <span>Type d'audience <?= info_tip(
                    "Public : affiché sur le site avec ville, salle, festival, lien, " . mb_strtolower(evenements_terme_spectacle(false)) . " et remarques. "
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

        <div class="form-actions">
            <button type="submit"><?= icon('save') ?> Enregistrer</button>
            <a class="btn ghost" href="<?= e($retour) ?>">Annuler</a>
        </div>
    </form>
</div>
<?php else: ?>

<div class="grid3">
<?php $statutCardClass = match ((string) $evenement['statut']) { 'confirme' => 'card-statut-confirme', 'annule' => 'card-statut-annule', default => 'card-statut-option' }; ?>
<div class="card card-editable <?= $statutCardClass ?>" id="carte-informations">
    <?php if ($peutEcrireEv): ?>
    <div class="head-actions card-actions-overlay">
        <button type="button" class="btn ghost icon-only card-edit-btn" title="Modifier" aria-label="Modifier"><?= icon('pencil') ?></button>
        <button type="submit" form="informations-form" class="btn icon-only card-save-btn" hidden title="Enregistrer" aria-label="Enregistrer"><?= icon('save') ?></button>
        <a href="<?= e($retour) ?>" class="btn ghost icon-only card-cancel-btn" hidden title="Annuler" aria-label="Annuler"><?= icon('x') ?></a>
    </div>
    <?php endif; ?>
    <?php if ($ok === 'informations'): ?><p class="ok flash">Informations enregistrées.</p><?php endif; ?>
    <?php if ($errInformationsMsg): ?><p class="err"><?= e($errInformationsMsg) ?></p><?php endif; ?>

    <div class="card-disp">
        <div class="info-date"><?= e(date('d.m.Y', strtotime((string) $evenement['date']))) ?></div>
        <div class="info-spectacle"><?= $evenement['spectacle_nom'] ? e($evenement['spectacle_nom']) : '—' ?></div>
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

    <form method="post" id="informations-form" action="?p=evenement_informations<?= $depuisQs ?>" class="card-edit form" hidden>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= (int) $id ?>">
        <label>Date <input type="date" name="date" value="<?= $v('date') ?>" required></label>
        <label><?= e(evenements_terme_spectacle(false)) ?>
            <select name="spectacle_id">
                <option value="">—</option>
                <?php foreach ($spectacles as $s): ?>
                    <option value="<?= (int) $s['id'] ?>" <?= $vRaw('spectacle_id') === (string) $s['id'] ? 'selected' : '' ?>><?= e($s['nom']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Statut
            <select name="statut">
                <?php foreach (EVENEMENTS_STATUTS as $s): ?>
                    <option value="<?= $s ?>" <?= $vRaw('statut', 'option') === $s ? 'selected' : '' ?>><?= e(evenement_statut_libelle($s)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="field-group">
            <span>Audience <?= info_tip(
                "Public : affiché sur le site avec ville, salle, festival, lien, " . mb_strtolower(evenements_terme_spectacle(false)) . " et remarques. "
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
            <?php if (trim((string) $evenement['grande_region']) !== ''): ?><div class="muted small"><?= e($evenement['grande_region']) ?></div><?php endif; ?>
        </div>
        <?php if ($peutEcrireEv): ?>
        <div class="head-actions">
            <button type="button" class="btn ghost icon-only card-edit-btn" title="Modifier" aria-label="Modifier"><?= icon('pencil') ?></button>
            <button type="submit" form="localisation-form" class="btn icon-only card-save-btn" hidden title="Enregistrer" aria-label="Enregistrer"><?= icon('save') ?></button>
            <a href="<?= e($retour) ?>" class="btn ghost icon-only card-cancel-btn" hidden title="Annuler" aria-label="Annuler"><?= icon('x') ?></a>
        </div>
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

    <form method="post" id="localisation-form" action="?p=evenement_localisation<?= $depuisQs ?>" class="card-edit form" hidden>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= (int) $id ?>">
        <div class="grid2">
            <label>Ville <input name="ville" value="<?= $v('ville') ?>"></label>
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
        <div class="head-actions">
            <button type="button" class="btn ghost icon-only card-edit-btn" title="Modifier" aria-label="Modifier"><?= icon('pencil') ?></button>
            <button type="submit" form="organisation-form" class="btn icon-only card-save-btn" hidden title="Enregistrer" aria-label="Enregistrer"><?= icon('save') ?></button>
            <a href="<?= e($retour) ?>" class="btn ghost icon-only card-cancel-btn" hidden title="Annuler" aria-label="Annuler"><?= icon('x') ?></a>
        </div>
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
                        <span class="ms-nom"><?php if ($s['est_facturation']): ?><span class="ico-tiny" title="Structure à facturer / SUISA"><?= icon('star') ?></span> <?php endif; ?><?= e($s['nom']) ?></span>
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

    <form method="post" action="?p=evenement_organisation<?= $depuisQs ?>" class="card-edit form" hidden id="organisation-form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= (int) $id ?>">

        <div class="tags-liste" id="organisation-structures-chips">
            <?php foreach ($structuresLiees as $s): ?>
                <span class="badge" data-id="<?= (int) $s['id'] ?>">
                    <button type="button" class="btn-tag-star<?= $s['est_facturation'] ? ' on' : '' ?>" title="Marquer comme structure à facturer / SUISA" aria-label="Marquer comme structure à facturer"><?= icon('star') ?></button>
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
            <label>E-mail (optionnel) <input name="org_email" type="email"></label>
            <label>Téléphone (optionnel) <input name="org_telephone" type="tel"></label>
            <label>Personne de contact (optionnel) <input name="org_personne_contact"></label>
        </div>
        <?php endif; ?>
    </form>
</div>
<?php endif; ?>

</div>
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
            <form method="post" action="?p=evenement_production_externe<?= $depuisQs ?>" id="prod-externe-form">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= (int) $id ?>">
                <label class="check">
                    <input type="checkbox" name="production_externe" id="prod-externe-check" value="1" <?= $prodExterne ? 'checked' : '' ?>>
                    Production externe
                </label>
            </form>
            <?php if ($employesDispo): ?>
                <form method="post" action="?p=evenement_employe_lier<?= $depuisQs ?>" class="linked-add">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= (int) $id ?>">
                    <select name="employe_id">
                        <?php foreach ($employesDispo as $emp): ?>
                            <option value="<?= (int) $emp['id'] ?>"><?= e($emp['prenom'] . ' ' . $emp['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn ghost"><?= icon('user-plus') ?> Ajouter</button>
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

    <?php if (!$employesLies): ?>
        <p class="muted small">Aucun employé lié.</p>
    <?php elseif ($prodExterne): ?>
        <!-- Production externe : pas de prestation/fiche de salaire à gérer ici,
             juste la liste des employés (cachet géré par l'organisateur externe). -->
        <div class="table-scroll">
        <table class="list evenement-employes">
            <thead><tr><th>Employé</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($employesLies as $emp): ?>
                <tr>
                    <td><?= e($emp['prenom'] . ' ' . $emp['nom']) ?></td>
                    <td class="epf-actions-cell">
                        <?php if ($peutEcrireEv): ?>
                        <form method="post" action="?p=evenement_employe_delier<?= $depuisQs ?>" data-confirm="Retirer cet employé de l'événement ?">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="id" value="<?= (int) $id ?>">
                            <input type="hidden" name="employe_id" value="<?= (int) $emp['id'] ?>">
                            <button type="submit" class="btn ghost btn-sm icon-only" title="Retirer l'employé" aria-label="Retirer l'employé"><?= icon('trash') ?></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php else: ?>
        <?php $colspanMsg = 4 + ($axes ? 1 : 0); ?>
        <div class="table-scroll">
        <table class="list evenement-employes">
            <thead><tr>
                <th>Employé</th><th>Fiche de salaire</th><?php if ($axes): ?><th>Axe</th><?php endif; ?>
                <th>Durée et taux horaire</th><th class="num">Total brut</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($employesLies as $emp):
                $eid = (int) $emp['id'];
                $ligne = $prestations[$eid] ?? null;
                $fichesEmp = $fichesParEmploye[$eid] ?? [];
                $moisEvenement = $vRaw('date') !== '' ? $vRaw('date') : ($evenement['date'] ?? date('Y-m-d'));
                $formId = 'pf-' . $eid;
                $axeLabel = '';
                if ($ligne) {
                    foreach ($axes as $ax) {
                        if ((int) $ax['id'] === (int) ($ligne['axe_analytique_id'] ?? 0)) { $axeLabel = $ax['code'] ?: $ax['libelle']; break; }
                    }
                }
                $totalBrut = $ligne ? (float) $ligne['heures_unite'] * (float) $ligne['quantite'] * (float) $ligne['taux_horaire'] : 0;
            ?>
                <tr>
                    <td><?= e($emp['prenom'] . ' ' . $emp['nom']) ?></td>
                    <?php if (!$unites || !$tauxHoraires): ?>
                        <td colspan="<?= $colspanMsg ?>" class="muted small">
                            Configurez au moins une unité de temps et un taux horaire (Paramètres &gt; Employeur) pour ajouter une prestation.
                        </td>
                    <?php else:
                        $huSel = $ligne ? $ligne['heures_unite'] . '|' . $ligne['libelle'] : '';
                        $tauxSel = '';
                        if ($ligne) {
                            $match = null;
                            foreach ($tauxHoraires as $th) {
                                if ((float) $th['montant'] === (float) $ligne['taux_horaire']) { $match = (string) $th['montant']; break; }
                            }
                            $tauxSel = $match ?? 'autre';
                        }
                    ?>
                        <td class="epf-col-sm">
                            <?php if ($ligne): ?>
                                <span class="epf-disp"><a href="<?= e(url_avec_retour('?p=fiche&id=' . (int) $ligne['fiche_id'], 'evenement', $id)) ?>"><?= e(mois_nom((int) $ligne['mois']) . ' ' . $ligne['annee']) ?></a></span>
                            <?php endif; ?>
                            <select form="<?= e($formId) ?>" name="fiche_id" class="fiche-select-sm epf-editable"<?= $ligne ? ' hidden' : '' ?>>
                                <option value="">— Créer une fiche (<?= e(mois_nom((int) substr($moisEvenement, 5, 2)) . ' ' . substr($moisEvenement, 0, 4)) ?>) —</option>
                                <?php foreach ($fichesEmp as $f): ?>
                                    <option value="<?= (int) $f['id'] ?>" <?= $ligne && (int) $ligne['fiche_id'] === (int) $f['id'] ? 'selected' : '' ?>><?= e(mois_nom((int) $f['mois']) . ' ' . $f['annee']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <?php if ($axes): ?>
                        <td class="epf-col-sm">
                            <?php if ($ligne): ?>
                                <span class="epf-disp"><?= e($axeLabel !== '' ? $axeLabel : '—') ?></span>
                            <?php endif; ?>
                            <?= str_replace('name="l_axe"', 'form="' . e($formId) . '" name="l_axe"', $axeSelect('l_axe', 'l-axe epf-editable', (int) ($ligne['axe_analytique_id'] ?? ($evenement['axe_analytique_id_defaut'] ?? 0)), (bool) $ligne)) ?>
                        </td>
                        <?php endif; ?>
                        <td class="epf-col-sm">
                            <div class="epf-duree">
                                <?php if ($ligne): ?>
                                    <span class="epf-disp"><?= e($ligne['libelle'] . ' × ' . nombre_court((float) $ligne['quantite']) . ' — ' . chf((float) $ligne['taux_horaire']) . ' CHF/h') ?></span>
                                <?php endif; ?>
                                <select form="<?= e($formId) ?>" name="l_unite" class="l-unite epf-editable"<?= $ligne ? ' hidden' : '' ?>><?= preselectionner_option($uniteOpts, $huSel) ?></select>
                                <input form="<?= e($formId) ?>" name="l_quantite" class="l-qte epf-editable" type="text" inputmode="decimal" placeholder="qté" value="<?= $ligne ? e(nombre_court((float) $ligne['quantite'])) : '' ?>"<?= $ligne ? ' hidden' : '' ?>>
                                <select form="<?= e($formId) ?>" name="l_taux_choix" class="l-taux-choix epf-editable"<?= $ligne ? ' hidden' : '' ?>><?= preselectionner_option($tauxOpts, $tauxSel) ?></select>
                                <input form="<?= e($formId) ?>" name="l_taux_manuel" class="l-taux-manuel epf-editable" type="text" inputmode="decimal" placeholder="CHF/h" value="<?= ($ligne && $tauxSel === 'autre') ? e(nombre_court((float) $ligne['taux_horaire'])) : '' ?>"<?= $ligne ? ' hidden' : '' ?>>
                            </div>
                        </td>
                        <td class="num"><span class="epf-total-live"><?= $totalBrut > 0 ? chf($totalBrut) . ' CHF' : '—' ?></span></td>
                        <td class="epf-actions-cell">
                            <?php if ($peutEcrireEv): ?>
                            <form id="<?= e($formId) ?>" method="post" action="?p=evenement_ligne_ajouter<?= $depuisQs ?>">
                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="id" value="<?= (int) $id ?>">
                                <input type="hidden" name="employe_id" value="<?= $eid ?>">
                            </form>
                            <div class="epf-actions">
                                <button type="button" form="<?= e($formId) ?>" class="btn ghost btn-sm icon-only epf-edit-btn" title="Modifier" aria-label="Modifier"<?= $ligne ? '' : ' hidden' ?>><?= icon('pencil') ?></button>
                                <button type="submit" form="<?= e($formId) ?>" class="btn btn-sm icon-only epf-editable" title="Enregistrer la prestation" aria-label="Enregistrer la prestation"<?= $ligne ? ' hidden' : '' ?>><?= icon('save') ?></button>
                                <button type="submit" form="<?= e($formId) ?>" formaction="?p=evenement_employe_delier<?= $depuisQs ?>" class="btn ghost btn-sm icon-only epf-editable" title="Retirer l'employé" aria-label="Retirer l'employé"<?= $ligne ? ' hidden' : '' ?>><?= icon('trash') ?></button>
                            </div>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<div class="grid3 mt-22">
<div class="card">
    <?php $suisaApplicable = (bool) $evenement['suisa_applicable']; ?>
    <div class="page-head">
        <h2 class="mt-0">SUISA <?= evenement_suisa_badge($evenement) ?></h2>
        <?php if ($peutEcrireEv): ?>
        <label class="check">
            <input type="checkbox" name="suisa_applicable" id="suisa-applicable" value="1" form="suisa-form" <?= $suisaApplicable ? 'checked' : '' ?>>
            s'applique
        </label>
        <?php endif; ?>
    </div>
    <?php if ($ok === 'suisa'): ?><p class="ok flash">SUISA enregistré.</p><?php endif; ?>
    <?php if ($peutEcrireEv): ?>
    <form method="post" id="suisa-form" action="?p=evenement_suisa<?= $depuisQs ?>" class="form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= (int) $id ?>">
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
        <div class="form-actions">
            <button type="submit"><?= icon('save') ?> Enregistrer</button>
            <a class="btn ghost" href="<?= e($retour) ?>">Annuler</a>
        </div>
    </form>
    <?php endif; ?>
</div>

<?php if ($axes): ?>
<div class="card">
    <h2 class="mt-0">Comptabilité analytique</h2>
    <?php if ($ok === 'axe'): ?><p class="ok flash">Axe par défaut enregistré.</p><?php endif; ?>
    <?php if ($peutEcrireEv): ?>
    <form method="post" action="?p=evenement_axe_defaut<?= $depuisQs ?>" class="form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= (int) $id ?>">
        <label><span>Axe par défaut <?= info_tip(
            "Présélectionné pour les nouvelles prestations ajoutées ci-dessous et pour les lignes "
            . "d'une facture créée depuis cet événement. Modifiable au cas par cas ensuite, sans "
            . "effet rétroactif sur les prestations ou factures déjà enregistrées."
        ) ?></span>
            <?= $axeSelect('axe_analytique_id_defaut', '', (int) ($evenement['axe_analytique_id_defaut'] ?? 0)) ?>
        </label>
        <div class="form-actions">
            <button type="submit"><?= icon('save') ?> Enregistrer</button>
        </div>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="page-head">
        <h2 class="mt-0">Factures liées</h2>
        <?php if (module_actif('facturation') && peut_ecrire('facturation')): ?>
            <a class="btn ghost" href="?p=facturation_form&evenement_id=<?= (int) $id ?>"><?= icon('file-plus') ?> Créer</a>
        <?php endif; ?>
    </div>
    <?php if (!$factures): ?>
        <p class="muted small">Aucune facture liée à cet événement.</p>
    <?php else: ?>
        <table class="list">
            <thead><tr><th>Numéro</th><th>Structure</th><th class="num">Montant</th><th>Statut</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($factures as $fa): ?>
                <tr>
                    <td><a href="<?= e(url_avec_retour('?p=facture&id=' . (int) $fa['id'], 'evenement', $id)) ?>"><?= $fa['numero'] !== '' ? e($fa['numero']) : '<span class="muted">(brouillon)</span>' ?></a></td>
                    <td><?= e($fa['structure_nom']) ?></td>
                    <td class="num strong"><?= chf((float) $fa['montant_total']) ?></td>
                    <td><?= facturation_badge($fa) ?></td>
                    <td>
                        <?php if ($peutEcrireEv): ?>
                        <form method="post" action="?p=evenement_facture_delier<?= $depuisQs ?>" data-confirm="Délier cette facture de l'événement ?">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="id" value="<?= (int) $id ?>">
                            <input type="hidden" name="facture_id" value="<?= (int) $fa['id'] ?>">
                            <button type="submit" class="btn ghost btn-sm" title="Délier" aria-label="Délier"><?= icon('x') ?></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <?php if (module_actif('facturation') && $facturesDispo && $peutEcrireEv): ?>
        <form method="post" action="?p=evenement_facture_lier<?= $depuisQs ?>" class="linked-add mt-18">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int) $id ?>">
            <div class="cat-search facture-search">
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
            <button type="submit" class="btn ghost btn-sm"><?= icon('link') ?> Lier</button>
        </form>
    <?php endif; ?>
</div>
</div>
<?php endif; ?>
</div></div>

<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    // Cadres lecture/édition (Informations, Organisation, Localisation) :
    // script générique partagé (assets/app.js, window DOMContentLoaded) —
    // rien à faire ici.
    const suisaCheck = document.getElementById('suisa-applicable');
    if (suisaCheck) {
        const suisaChamps = document.getElementById('suisa-champs');
        suisaCheck.addEventListener('change', () => {
            suisaChamps.hidden = !suisaCheck.checked;
            suisaChamps.querySelectorAll('select, input').forEach(el => { el.disabled = !suisaCheck.checked; });
        });
    }

    // Case « Production externe » : cocher détache les prestations déjà liées
    // (côté serveur, route_evenement_production_externe()) — confirmation avant
    // de soumettre si des prestations existent. Décocher ne supprime rien.
    const prodCheck = document.getElementById('prod-externe-check');
    if (prodCheck) {
        const aDesPrestations = <?= json_encode((bool) array_filter($prestations)) ?>;
        prodCheck.addEventListener('change', () => {
            if (prodCheck.checked && aDesPrestations
                && !confirm('Cocher « Production externe » va supprimer les prestations déjà liées sur les fiches de salaire des employés de cet événement. Continuer ?')) {
                prodCheck.checked = false;
                return;
            }
            document.getElementById('prod-externe-form').requestSubmit();
        });
    }

    // Ligne de prestation (carte Employés) : les champs vivent dans des <td>
    // séparés (colonnes) mais partagent un même <form> via l'attribut form="…" —
    // on les manipule donc via la <tr> commune plutôt que via le <form>.
    document.querySelectorAll('.evenement-employes tbody tr').forEach(tr => {
        const unite  = tr.querySelector('.l-unite');
        const qte    = tr.querySelector('.l-qte');
        const choix  = tr.querySelector('.l-taux-choix');
        const manuel = tr.querySelector('.l-taux-manuel');
        const total  = tr.querySelector('.epf-total-live');
        if (!unite || !qte || !choix || !manuel) return; // ligne "configurez une unité…"

        const num = v => parseFloat((v || '').toString().replace(',', '.')) || 0;
        const sync = () => {
            manuel.style.display = choix.value === 'autre' ? '' : 'none';
            if (total) {
                const opt = unite.selectedOptions[0];
                const hu = opt ? num(opt.dataset.h) : 0;
                const t  = choix.value === 'autre' ? num(manuel.value) : num(choix.value);
                const montant = hu * num(qte.value) * t;
                total.textContent = montant > 0 ? (Math.round(montant * 100) / 100).toFixed(2) + ' CHF' : '—';
            }
        };
        [unite, choix].forEach(el => el.addEventListener('change', sync));
        [qte, manuel].forEach(el => el.addEventListener('input', sync));
        sync();
    });

    // Ligne de prestation : mode lecture (texte + crayon) tant que rien n'est
    // modifié, mode édition (tous les champs + disquette/corbeille) après un
    // clic sur le crayon — soumis en un seul formulaire, pas d'action séparée.
    document.addEventListener('click', ev => {
        const btn = ev.target.closest('.epf-edit-btn');
        if (!btn) return;
        const tr = btn.closest('tr');
        tr.querySelectorAll('.epf-disp').forEach(el => { el.hidden = true; });
        tr.querySelectorAll('.epf-editable').forEach(el => { el.hidden = false; });
        btn.hidden = true;
        const choix = tr.querySelector('.l-taux-choix');
        if (choix) choix.dispatchEvent(new Event('change'));
        const sel = tr.querySelector('.fiche-select-sm');
        if (sel) sel.focus();
    });

    // Ne pas revenir en haut de la page après Ajouter un employé / Enregistrer /
    // Retirer (carte Employés) — on restaure la position de défilement au retour.
    const carteEmployes = document.getElementById('carte-employes');
    if (carteEmployes) {
        const scrollKey = 'evenement-scroll-<?= (int) $id ?>';
        carteEmployes.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', () => {
                sessionStorage.setItem(scrollKey, String(window.scrollY));
            });
        });
        const savedScroll = sessionStorage.getItem(scrollKey);
        if (savedScroll !== null) {
            sessionStorage.removeItem(scrollKey);
            window.addEventListener('load', () => window.scrollTo(0, parseInt(savedScroll, 10)));
        }
    }

    // Recherche de facture à lier (même widget que le rapprochement d'écriture/catégorie).
    document.querySelectorAll('.facture-search').forEach(wrap => {
        const input = wrap.querySelector('.cat-search-input');
        lassoInitCatSearch(wrap, {
            showPlaceholderText: true,
            clearHiddenOnInput: true,
            onSelect: () => input.setCustomValidity(''),
        });
    });
    document.querySelectorAll('.facture-search').forEach(wrap => {
        wrap.closest('form').addEventListener('submit', e => {
            const hidden = wrap.querySelector('.cat-search-val');
            const input = wrap.querySelector('.cat-search-input');
            if (!hidden.value) {
                input.setCustomValidity('Veuillez choisir une facture dans la liste');
                input.reportValidity();
                e.preventDefault();
            }
        });
    });

    // Lieu (base), recherche à la création (un seul lieu à ce stade) : widget
    // alimenté à la demande via ?p=lieux_options au premier focus (potentiellement
    // des milliers de lieux → pas d'injection dans la page). lassoInitCatSearch()
    // est appelée tout de suite (liste encore vide) — pas dans le .then() — pour
    // que le champ réagisse dès le premier focus/frappe/clic pendant que la
    // requête est en vol, au lieu de rester muet tant qu'elle n'a pas abouti
    // (c'était la cause du bug « il faut cliquer 2-3 fois »).
    const lieuWrapCreation = document.getElementById('evt-lieu-search');
    if (lieuWrapCreation && window.lassoInitCatSearch) {
        const lieuList = lieuWrapCreation.querySelector('.cat-search-list');
        const lieuInput = lieuWrapCreation.querySelector('.cat-search-input');
        lassoInitCatSearch(lieuWrapCreation, { clearHiddenOnInput: true });
        let lieuCharge = false;
        lieuInput.addEventListener('focus', function () {
            if (lieuCharge) { return; }
            lieuCharge = true;
            fetch('?p=lieux_options', { headers: { 'Accept': 'application/json' } })
                .then(r => r.json())
                .then(function (opts) {
                    const frag = document.createDocumentFragment();
                    opts.forEach(function (o) {
                        const li = document.createElement('li');
                        li.dataset.val = o.id;
                        li.textContent = o.nom;
                        frag.appendChild(li);
                    });
                    lieuList.appendChild(frag);
                    // Ré-applique le filtre courant : si l'utilisateur a déjà
                    // tapé pendant le chargement, les options qui viennent
                    // d'arriver doivent être filtrées tout de suite.
                    lieuInput.dispatchEvent(new Event('input'));
                })
                .catch(function () { lieuCharge = false; });
        });
    }

    // Carte « Organisation » (édition) : ajout/retrait de structures liées en
    // puces (« chips »), plus de distinction lieu/organisateur (voir
    // migration_66) — rien n'est envoyé au serveur avant « Enregistrer »,
    // contrairement aux anciennes routes lier/délier qui agissaient
    // immédiatement sur un seul lien à la fois. Une puce peut être marquée
    // « à facturer » (référence pour la facture et l'export SUISA) — une
    // seule à la fois, reflétée dans le champ caché facturation_id.
    const facturationHidden = document.getElementById('organisation-facturation-id');
    function definirFacturation(chips, id) {
        chips.querySelectorAll('.btn-tag-star').forEach(btn => {
            btn.classList.toggle('on', btn.closest('.badge').dataset.id === String(id));
        });
        if (facturationHidden) facturationHidden.value = id;
    }
    function ajouterChip(chips, id, label) {
        if (chips.querySelector('[data-id="' + id + '"]')) return;
        const chip = document.createElement('span');
        chip.className = 'badge';
        chip.dataset.id = id;
        const starBtn = document.createElement('button');
        starBtn.type = 'button'; starBtn.className = 'btn-tag-star';
        starBtn.title = 'Marquer comme structure à facturer / SUISA';
        starBtn.setAttribute('aria-label', 'Marquer comme structure à facturer');
        starBtn.innerHTML = <?= json_encode(icon('star')) ?>;
        chip.appendChild(starBtn);
        chip.appendChild(document.createTextNode(' ' + label + ' '));
        const hidden = document.createElement('input');
        hidden.type = 'hidden'; hidden.name = 'structure_ids[]'; hidden.value = id;
        chip.appendChild(hidden);
        const removeBtn = document.createElement('button');
        removeBtn.type = 'button'; removeBtn.className = 'btn-tag-x chip-remove'; removeBtn.setAttribute('aria-label', 'Retirer'); removeBtn.textContent = '×';
        chip.appendChild(removeBtn);
        chips.appendChild(chip);
        // Première structure ajoutée à une liste vide : présélectionnée comme
        // référence facture/SUISA (aucune ambiguïté possible) ; au-delà, on ne
        // devine rien de plus — l'utilisateur choisit via l'étoile.
        if (chips.querySelectorAll('.badge').length === 1) {
            definirFacturation(chips, id);
        }
    }
    document.addEventListener('click', ev => {
        const starBtn = ev.target.closest('.btn-tag-star');
        if (starBtn) {
            definirFacturation(starBtn.closest('.tags-liste'), starBtn.closest('.badge').dataset.id);
            return;
        }
        const removeBtn = ev.target.closest('.chip-remove');
        if (removeBtn) {
            const chip = removeBtn.closest('.badge');
            const chips = chip.closest('.tags-liste');
            const etaitFacturation = chip.querySelector('.btn-tag-star')?.classList.contains('on');
            chip.remove();
            // La structure retirée était la référence facture/SUISA : reporte
            // automatiquement sur la première restante, plutôt que de laisser
            // silencieusement plus aucune référence choisie.
            if (etaitFacturation && chips) {
                const suivante = chips.querySelector('.badge');
                if (suivante) definirFacturation(chips, suivante.dataset.id);
                else if (facturationHidden) facturationHidden.value = '';
            }
        }
    });

    const structureWrap = document.getElementById('evt-structure-add-search');
    if (structureWrap && window.lassoInitCatSearch) {
        const structureChips = document.getElementById('organisation-structures-chips');
        const structureList = structureWrap.querySelector('.cat-search-list');
        const structureInput = structureWrap.querySelector('.cat-search-input');
        const structureHidden = structureWrap.querySelector('.cat-search-val');
        const structureNouveau = document.getElementById('organisation-nouveau');
        // lassoInitCatSearch() appelée tout de suite (liste encore vide, hormis
        // « + Nouvelle structure » déjà en dur), pas dans le .then() du fetch :
        // sinon le champ ne réagit à rien tant que ?p=lieux_options n'a pas abouti.
        lassoInitCatSearch(structureWrap, {
            clearHiddenOnInput: true,
            onSelect: li => {
                if (li.dataset.val === '__new__') {
                    if (structureNouveau) structureNouveau.hidden = false;
                } else {
                    ajouterChip(structureChips, li.dataset.val, li.textContent);
                }
                // Réinitialiser tout de suite la valeur cachée du widget de
                // recherche lui-même (pas seulement le texte affiché) : sinon
                // le blur qui suit (~150 ms, cf. lassoInitCatSearch) retrouve
                // l'ancien id et réaffiche son libellé dans le champ, comme si
                // le dernier élément ajouté restait « sélectionné ».
                structureHidden.value = '';
                structureInput.value = '';
            },
        });
        let structureChargee = false;
        structureInput.addEventListener('focus', function () {
            if (structureChargee) return;
            structureChargee = true;
            fetch('?p=lieux_options', { headers: { 'Accept': 'application/json' } })
                .then(r => r.json())
                .then(function (opts) {
                    const dejaLies = new Set([...structureChips.querySelectorAll('.badge')].map(b => b.dataset.id));
                    const frag = document.createDocumentFragment();
                    opts.forEach(function (o) {
                        if (dejaLies.has(String(o.id))) return;
                        const li = document.createElement('li');
                        li.dataset.val = o.id;
                        li.textContent = o.nom;
                        frag.appendChild(li);
                    });
                    structureList.appendChild(frag);
                    structureInput.dispatchEvent(new Event('input'));
                })
                .catch(function () { structureChargee = false; });
        });
    }
})();
</script>
<?php require __DIR__ . '/_region_select_js.php'; ?>
