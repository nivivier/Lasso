<?php /** @var ?array $structure */ /** @var ?string $err */
/** @var array $categoriesPourSelect */ /** @var int $categorieIdSelectionnee */
/** @var array $contacts */ /** @var array $notes */ /** @var array $tags */ /** @var array $tagsDispo */
/** @var array $lieuxLies */ /** @var array $lieuxDispo */ /** @var array $categoriesLieu */
$v = fn(string $k, $d = '') => e((string) ($structure[$k] ?? $d));
$isEdit = !empty($structure['id']);
$sid = (int) ($structure['id'] ?? 0);
?>
<?= lien_retour_contextuel('?p=structures', 'Structures') ?>
<div class="page-head">
    <?php if ($isEdit): ?>
    <div class="titre-row">
        <div class="titre-read">
            <?= flag_toggle_html('structure', $sid, (string) ($structure['flag'] ?? '')) ?>
            <h1><?= $v('nom') ?></h1>
            <button type="button" class="btn ghost btn-sm icon-only titre-edit-btn" title="Modifier le nom" aria-label="Modifier le nom"><?= icon('pencil') ?></button>
        </div>
        <form method="post" action="?p=structure_renommer" class="titre-edit-form" hidden>
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= $sid ?>">
            <input type="text" name="nom" class="input-titre" value="<?= $v('nom') ?>" required>
            <button type="submit" class="btn ghost btn-sm icon-only" title="Enregistrer" aria-label="Enregistrer"><?= icon('save') ?></button>
            <button type="button" class="btn ghost btn-sm icon-only titre-cancel-btn" title="Annuler" aria-label="Annuler"><?= icon('x') ?></button>
        </form>
    </div>
    <script>
    (function () {
        var row = document.querySelector('.titre-row');
        if (!row) return;
        row.querySelector('.titre-edit-btn').addEventListener('click', function () {
            row.querySelector('.titre-read').hidden = true;
            row.querySelector('.titre-edit-form').hidden = false;
            row.querySelector('.titre-edit-form input[name=nom]').focus();
        });
        row.querySelector('.titre-cancel-btn').addEventListener('click', function () {
            row.querySelector('.titre-edit-form').hidden = true;
            row.querySelector('.titre-read').hidden = false;
        });
    })();
    lassoInitFlagToggle();
    </script>
    <?php else: ?>
    <h1>Nouvelle structure</h1>
    <?php endif; ?>
    <?php if ($isEdit && (int) ($structure['nb_factures'] ?? 0) === 0): ?>
    <div class="head-actions">
        <form method="post" action="?p=structure_delete" onsubmit="return confirm('Supprimer définitivement cette structure ?');" class="d-inline">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= $sid ?>">
            <button type="submit" class="btn danger icon-only" title="Supprimer" aria-label="Supprimer la structure"><?= icon('trash') ?></button>
        </form>
    </div>
    <?php endif; ?>
</div>

<?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>
<?php if (($_GET['err'] ?? null) === 'used'): ?><p class="err flash">Suppression impossible : des factures sont rattachées à cette structure.</p><?php endif; ?>
<?php if (($_GET['ok'] ?? null) === 'fusion'): ?><p class="ok flash">Structures fusionnées : contacts, notes, factures, étiquettes et lieux liés ont été repris ici.</p><?php endif; ?>
<?php if (($_GET['ok'] ?? null) === 'transforme'): ?><p class="ok flash">Structures transformées en salles/festivals rattachés à cet organisateur.</p><?php endif; ?>
<?php $avecAside = $isEdit && module_actif('booking') && peut_lire('booking'); ?>
<?php if (!empty($structure['mise_a_jour_le']) && !$avecAside): ?>
    <p class="muted small">Dernière mise à jour connue (import) : <?= e(date('d.m.Y', strtotime($structure['mise_a_jour_le']))) ?></p>
<?php endif; ?>

<?php if (!$isEdit): ?>
<form method="post" action="?p=structure" class="card form">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div class="form-split">

        <div class="form-split-main">
            <div class="grid3">
                <label>Nom / raison sociale <input name="nom" class="input-titre" value="<?= $v('nom') ?>" required></label>

                <label>Catégorie
                    <select name="categorie_id">
                        <?php foreach ($categoriesPourSelect as $cat): ?>
                            <option value="<?= (int) $cat['id'] ?>" <?= $categorieIdSelectionnee === (int) $cat['id'] ? 'selected' : '' ?>><?= str_repeat("\u{00A0}\u{00A0}", $cat['profondeur']) ?><?= e($cat['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <div class="field-group">
                    <span>Type</span>
                    <?= icon_picker('type', [
                        'organisation' => ['icone' => 'building', 'label' => 'Organisation'],
                        'particulier'  => ['icone' => 'user', 'label' => 'Particulier'],
                    ], (string) ($structure['type'] ?? 'organisation'), 'Type (facturation)') ?>
                </div>

			<label><span>Connu via <?= info_tip("D'où vient ce contact — un intermédiaire, une recommandation, une source…") ?></span> <input name="via" value="<?= $v('via') ?>" placeholder="ex. Recommandé par…"></label>

            </div>
        </div>
        <fieldset class="fieldset-groupe">
            <legend>Coordonnées</legend>
            <input name="adresse_rue" value="<?= $v('adresse_rue') ?>" placeholder="Rue et numéro" aria-label="Rue et numéro" class="mb-16">
            <div class="grid2">
                <input name="adresse_npa" value="<?= $v('adresse_npa') ?>" placeholder="NPA" aria-label="NPA">
                <input name="adresse_localite" value="<?= $v('adresse_localite') ?>" placeholder="Localité" aria-label="Localité" class="input-titre">
            </div>
            <div class="grid3 mt-16">
                <input name="departement_canton" value="<?= $v('departement_canton') ?>" placeholder="Département / canton" aria-label="Département / canton">
                <select name="grande_region" class="region-select" aria-label="Région" title="Région (Normandie, Romandie… — se gère dans Paramètres → Pays)">
                    <option value="">— Région —</option>
                    <?= region_options_nom((string) ($structure['adresse_pays'] ?? 'Suisse'), (string) ($structure['grande_region'] ?? '')) ?>
                </select>
                <select name="adresse_pays" class="pays-select" aria-label="Pays"><?= pays_options_nom((string) ($structure['adresse_pays'] ?? 'Suisse')) ?></select>
            </div>
            <label class="mt-22">Site web <input name="site_web" type="url" value="<?= $v('site_web') ?>" placeholder="https://…"></label>
        </fieldset>

            <label>Remarques
                <textarea name="notes" rows="2"><?= $v('notes') ?></textarea>
            </label>
    </div>

    <div class="form-actions">
        <button type="submit"><?= icon('save') ?> Enregistrer</button>
        <a class="btn ghost" href="?p=structures">Annuler</a>
    </div>
</form>

<?php elseif (!$avecAside): ?>
<!-- Sans le module booking (ou sans lecture dessus) : pas de carte
     « Localisation » séparée, les coordonnées restent ici — voir
     route_structure() (lib/routes_facturation.php). -->
<form method="post" action="?p=structure&id=<?= (int) $structure['id'] ?>" class="card card-editable form" id="structure-details-form">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="nom" value="<?= $v('nom') ?>">

    <div class="head-actions card-actions-overlay">
        <button type="button" class="btn ghost icon-only card-edit-btn" title="Modifier" aria-label="Modifier"><?= icon('pencil') ?></button>
        <button type="submit" class="btn icon-only card-save-btn" hidden title="Enregistrer" aria-label="Enregistrer"><?= icon('save') ?></button>
        <a href="?p=structure&id=<?= (int) $structure['id'] ?>" class="btn ghost icon-only card-cancel-btn" hidden title="Annuler" aria-label="Annuler"><?= icon('x') ?></a>
    </div>

    <?php
    $categorieAffichee = trim((string) ($structure['categorie'] ?? ''));
    if ($categorieAffichee !== '' && trim((string) ($structure['sous_categorie'] ?? '')) !== '') {
        $categorieAffichee .= ' › ' . $structure['sous_categorie'];
    }
    $rueAffichee = trim((string) ($structure['adresse_rue'] ?? ''));
    $npaAffiche = trim((string) ($structure['adresse_npa'] ?? ''));
    $villeHtmlS = ville_departement_canton_html(
        (string) ($structure['adresse_localite'] ?? ''),
        pays_drapeau_nom((string) ($structure['adresse_pays'] ?? '')),
        (string) ($structure['adresse_pays'] ?? ''),
        (string) ($structure['departement_canton'] ?? '')
    );
    ?>
    <div class="card-disp">
        <table class="kv-table">
            <tr>
                <th>Catégorie</th>
                <td><?= $categorieAffichee !== '' ? e($categorieAffichee) : '—' ?></td>
            </tr>
            <tr>
                <th>Type</th>
                <td><span class="ico-label"><?= icon(($structure['type'] ?? 'organisation') === 'particulier' ? 'user' : 'building') ?> <?= ($structure['type'] ?? 'organisation') === 'particulier' ? 'Particulier' : 'Organisation' ?></span></td>
            </tr>
            <tr>
                <th>Connu via</th>
                <td><?= trim((string) ($structure['via'] ?? '')) !== '' ? $v('via') : '—' ?></td>
            </tr>
            <tr>
                <th>Coordonnées</th>
                <td>
                    <?php if ($rueAffichee === '' && $npaAffiche === '' && $villeHtmlS === ''): ?>—
                    <?php else: ?>
                        <?php if ($rueAffichee !== ''): ?><?= e($rueAffichee) ?><br><?php endif; ?>
                        <?php if ($npaAffiche !== '' || $villeHtmlS !== ''): ?><?= e($npaAffiche) ?> <?= $villeHtmlS ?><?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th>Site web</th>
                <td><?php if (trim((string) ($structure['site_web'] ?? '')) !== ''): ?><a href="<?= $v('site_web') ?>" target="_blank" rel="noopener"><?= $v('site_web') ?></a><?php else: ?>—<?php endif; ?></td>
            </tr>
            <tr>
                <th>Remarques</th>
                <td><?= trim((string) ($structure['notes'] ?? '')) !== '' ? nl2br($v('notes')) : '—' ?></td>
            </tr>
        </table>
    </div>

    <div class="card-edit" hidden>
        <div class="form-split">
            <div class="form-split-main">
                <div class="grid3">
                    <label>Catégorie
                        <select name="categorie_id">
                            <?php foreach ($categoriesPourSelect as $cat): ?>
                                <option value="<?= (int) $cat['id'] ?>" <?= $categorieIdSelectionnee === (int) $cat['id'] ? 'selected' : '' ?>><?= str_repeat("\u{00A0}\u{00A0}", $cat['profondeur']) ?><?= e($cat['nom']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <div class="field-group">
                        <span>Type</span>
                        <?= icon_picker('type', [
                            'organisation' => ['icone' => 'building', 'label' => 'Organisation'],
                            'particulier'  => ['icone' => 'user', 'label' => 'Particulier'],
                        ], (string) ($structure['type'] ?? 'organisation'), 'Type (facturation)') ?>
                    </div>

                    <label><span>Connu via <?= info_tip("D'où vient ce contact — un intermédiaire, une recommandation, une source…") ?></span> <input name="via" value="<?= $v('via') ?>" placeholder="ex. Recommandé par…"></label>
                </div>
            </div>
            <fieldset class="fieldset-groupe">
                <legend>Coordonnées</legend>
                <input name="adresse_rue" value="<?= $v('adresse_rue') ?>" placeholder="Rue et numéro" aria-label="Rue et numéro" class="mb-16">
                <div class="grid2">
                    <input name="adresse_npa" value="<?= $v('adresse_npa') ?>" placeholder="NPA" aria-label="NPA">
                    <input name="adresse_localite" value="<?= $v('adresse_localite') ?>" placeholder="Localité" aria-label="Localité" class="input-titre">
                </div>
                <div class="grid3 mt-16">
                    <input name="departement_canton" value="<?= $v('departement_canton') ?>" placeholder="Département / canton" aria-label="Département / canton">
                    <select name="grande_region" class="region-select" aria-label="Région" title="Région (Normandie, Romandie… — se gère dans Paramètres → Pays)">
                        <option value="">— Région —</option>
                        <?= region_options_nom((string) ($structure['adresse_pays'] ?? 'Suisse'), (string) ($structure['grande_region'] ?? '')) ?>
                    </select>
                    <select name="adresse_pays" class="pays-select" aria-label="Pays"><?= pays_options_nom((string) ($structure['adresse_pays'] ?? 'Suisse')) ?></select>
                </div>
                <label class="mt-22">Site web <input name="site_web" type="url" value="<?= $v('site_web') ?>" placeholder="https://…"></label>
            </fieldset>

            <label>Remarques
                <textarea name="notes" rows="2"><?= $v('notes') ?></textarea>
            </label>
        </div>
    </div>
</form>

<?php else: ?>
<!-- Avec le module booking : 3 colonnes de cartes empilées, chacune d'une
     hauteur indépendante — colonne 1 : Lieux liés + Informations générales ;
     colonne 2 : Statut + Contacts ; colonne 3 : Localisation + Historique
     (voir le fil de discussion pour la répartition exacte). -->
<div class="card-columns">

<div class="card-col">

<div class="card">
    <div class="card-block section-editable">
        <div class="card-head-row">
            <h2 class="mt-0">Lieux liés</h2>
            <button type="button" class="btn ghost btn-sm icon-only edit-toggle-btn" title="Modifier" aria-label="Modifier les salles &amp; festivals"><?= icon('pencil') ?></button>
        </div>
        <?php foreach ($lieuxLies as $l): ?>
            <div class="linked-add">
                <span>
                    <strong><a href="<?= url_avec_retour('?p=lieu&id=' . (int) $l['id'], 'structure', $sid) ?>"><?= e($l['nom']) ?></a></strong>
                    <span class="muted small"> — <?= e((string) $l['type']) ?></span>
                    <?php if ($l['ville']): ?><span class="muted small"> — <?= e($l['ville']) ?></span><?php endif; ?>
                </span>
                <form method="post" action="?p=structure_lieu_delier" class="edit-only" onsubmit="return confirm('Délier ce lieu ?');">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="structure_id" value="<?= $sid ?>">
                    <input type="hidden" name="lieu_id" value="<?= (int) $l['id'] ?>">
                    <button type="submit" class="btn ghost btn-sm icon-only" title="Délier" aria-label="Délier"><?= icon('unlink') ?></button>
                </form>
            </div>
        <?php endforeach; ?>
        <?php if (!$lieuxLies): ?><p class="muted small">Aucune salle ni festival lié.</p><?php endif; ?>

        <form method="post" action="?p=structure_lieu_lier" class="linked-add edit-only" id="lieu-form">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="structure_id" value="<?= $sid ?>">
            <div class="cat-search lieu-search">
                <input type="text" class="cat-search-input" placeholder="Rechercher une salle/un festival…" autocomplete="off">
                <input type="hidden" name="lieu_id" class="cat-search-val" value="">
                <ul class="cat-search-list" hidden role="listbox">
                    <li data-val="__new__">+ Nouveau lieu</li>
                    <?php foreach ($lieuxDispo as $l): ?>
                        <li data-val="<?= (int) $l['id'] ?>"><?= e($l['nom']) ?> (<?= e((string) $l['type']) ?>)</li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <button type="submit" class="btn ghost btn-sm"><?= icon('link') ?> Lier</button>
            <div id="lieu-nouveau" hidden class="grid3 mt-10">
                <label>Nom <input name="nl_nom"></label>
                <label>Type
                    <select name="nl_type">
                        <?php foreach (($categoriesLieu ?? []) as $c): ?><option value="<?= e($c) ?>"><?= e($c) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label>Ville <input name="nl_ville"></label>
            </div>
        </form>
        <script>
        (function () {
            var wrap = document.querySelector('.lieu-search');
            var nouveau = document.getElementById('lieu-nouveau');
            if (wrap && window.lassoInitCatSearch) {
                lassoInitCatSearch(wrap, {
                    showPlaceholderText: true,
                    clearHiddenOnInput: true,
                    onSelect: function (li) { nouveau.hidden = li.dataset.val !== '__new__'; },
                });
            }
        })();
        </script>
    </div>

    <?php if (module_actif('evenements') && $evenementsLies): ?>
    <div class="card-block">
        <h2 class="mt-0"><?= icon('calendar') ?> Événements (<?= count($evenementsLies) ?>)</h2>
        <ul class="clean-list small">
            <?php foreach (array_slice($evenementsLies, 0, 30) as $ev): ?>
            <li>
                <a href="?p=evenement&id=<?= (int) $ev['id'] ?>"><?= e($ev['date'] ? date('d.m.Y', strtotime((string) $ev['date'])) : '—') ?></a>
                — <?= e((string) ($ev['spectacle'] ?? '') ?: 'Événement') ?><?php if ($ev['ville']): ?> <span class="muted">· <?= e((string) $ev['ville']) ?></span><?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php if (count($evenementsLies) > 30): ?><p class="muted small">… et <?= count($evenementsLies) - 30 ?> autre(s).</p><?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<div class="card card-editable">
    <?php
    $categorieAffichee = trim((string) ($structure['categorie'] ?? ''));
    if ($categorieAffichee !== '' && trim((string) ($structure['sous_categorie'] ?? '')) !== '') {
        $categorieAffichee .= ' › ' . $structure['sous_categorie'];
    }
    ?>
    <form method="post" action="?p=structure&id=<?= $sid ?>" class="form" id="structure-details-form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="nom" value="<?= $v('nom') ?>">

        <div class="card-head-row">
            <h2 class="mt-0">Informations générales</h2>
            <div class="head-actions">
                <button type="button" class="btn ghost icon-only card-edit-btn" title="Modifier" aria-label="Modifier"><?= icon('pencil') ?></button>
                <button type="submit" class="btn icon-only card-save-btn" hidden title="Enregistrer" aria-label="Enregistrer"><?= icon('save') ?></button>
                <a href="?p=structure&id=<?= $sid ?>" class="btn ghost icon-only card-cancel-btn" hidden title="Annuler" aria-label="Annuler"><?= icon('x') ?></a>
            </div>
        </div>

        <div class="card-disp">
            <table class="kv-table">
                <tr>
                    <th>Catégorie</th>
                    <td><?= $categorieAffichee !== '' ? e($categorieAffichee) : '—' ?></td>
                </tr>
                <tr>
                    <th>Type</th>
                    <td><span class="ico-label"><?= icon(($structure['type'] ?? 'organisation') === 'particulier' ? 'user' : 'building') ?> <?= ($structure['type'] ?? 'organisation') === 'particulier' ? 'Particulier' : 'Organisation' ?></span></td>
                </tr>
                <tr>
                    <th>Connu via</th>
                    <td><?= trim((string) ($structure['via'] ?? '')) !== '' ? $v('via') : '—' ?></td>
                </tr>
                <tr>
                    <th>Site web</th>
                    <td><?php if (trim((string) ($structure['site_web'] ?? '')) !== ''): ?><a href="<?= $v('site_web') ?>" target="_blank" rel="noopener"><?= $v('site_web') ?></a><?php else: ?>—<?php endif; ?></td>
                </tr>
                <tr>
                    <th>Remarques</th>
                    <td><?= trim((string) ($structure['notes'] ?? '')) !== '' ? nl2br($v('notes')) : '—' ?></td>
                </tr>
            </table>
        </div>

        <div class="card-edit" hidden>
            <label>Catégorie
                <select name="categorie_id">
                    <?php foreach ($categoriesPourSelect as $cat): ?>
                        <option value="<?= (int) $cat['id'] ?>" <?= $categorieIdSelectionnee === (int) $cat['id'] ? 'selected' : '' ?>><?= str_repeat("\u{00A0}\u{00A0}", $cat['profondeur']) ?><?= e($cat['nom']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="field-group">
                <span>Type</span>
                <?= icon_picker('type', [
                    'organisation' => ['icone' => 'building', 'label' => 'Organisation'],
                    'particulier'  => ['icone' => 'user', 'label' => 'Particulier'],
                ], (string) ($structure['type'] ?? 'organisation'), 'Type (facturation)') ?>
            </div>
            <label><span>Connu via <?= info_tip("D'où vient ce contact — un intermédiaire, une recommandation, une source…") ?></span> <input name="via" value="<?= $v('via') ?>" placeholder="ex. Recommandé par…"></label>
            <label>Site web <input name="site_web" type="url" value="<?= $v('site_web') ?>" placeholder="https://…"></label>
            <label>Remarques
                <textarea name="notes" rows="2"><?= $v('notes') ?></textarea>
            </label>
        </div>
    </form>
</div>

</div>

<div class="card-col">

<div class="card">
    <h2 class="mt-0">Statut</h2>
    <form method="post" action="?p=structure_statut" id="statut-form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= $sid ?>">
        <label class="check mb-8">
            <input type="checkbox" name="actif" id="structure-actif" value="1" <?= $structure['actif'] ? 'checked' : '' ?>>
            Structure active
        </label>
        <label class="check">
            <input type="checkbox" name="desinscrit" id="structure-desinscrit" value="1" <?= $structure['desinscrit'] ? 'checked' : '' ?>>
            Désinscrite du mailing <?= info_tip("Automatique : une structure inactive est toujours désinscrite du mailing.") ?>
        </label>
    </form>

    <div class="tags-liste mt-16">
        <?php foreach ($tags as $t): ?>
            <span class="badge"><?= e($t['nom']) ?>
                <form method="post" action="?p=structure_tag_retirer" class="d-inline" onsubmit="return confirm('Retirer cette étiquette ?');">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="structure_id" value="<?= $sid ?>">
                    <input type="hidden" name="tag_id" value="<?= (int) $t['id'] ?>">
                    <button type="submit" class="btn-tag-x" aria-label="Retirer">×</button>
                </form>
            </span>
        <?php endforeach; ?>
        <?php if (!$tags): ?><span class="muted small">Aucune étiquette.</span><?php endif; ?>
    </div>
    <form method="post" action="?p=structure_tag_ajouter" class="linked-add">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="structure_id" value="<?= $sid ?>">
        <input type="text" name="nom" list="tags-dispo" placeholder="Ajouter une étiquette…" autocomplete="off">
        <datalist id="tags-dispo">
            <?php foreach ($tagsDispo as $t): ?><option value="<?= e($t['nom']) ?>"><?php endforeach; ?>
        </datalist>
        <button type="submit" class="btn ghost btn-sm icon-only" title="Ajouter" aria-label="Ajouter l'étiquette"><?= icon('plus') ?></button>
    </form>
    <script>
    (function () {
        var actif = document.getElementById('structure-actif');
        var desinscrit = document.getElementById('structure-desinscrit');
        var form = document.getElementById('statut-form');
        if (!actif || !desinscrit || !form) return;
        function syncDisable() {
            desinscrit.disabled = !actif.checked;
            if (!actif.checked) desinscrit.checked = true;
        }
        syncDisable();
        // Coche/décoche : enregistrement immédiat (route_structure_statut), sans
        // passer par le bouton Enregistrer de la fiche.
        actif.addEventListener('change', function () { syncDisable(); form.requestSubmit(); });
        desinscrit.addEventListener('change', function () { form.requestSubmit(); });
    })();
    </script>
</div>

<div class="card section-editable">
    <div class="card-head-row">
        <h2 class="mt-0">Contacts</h2>
        <button type="button" class="btn ghost btn-sm icon-only edit-only" data-show="nouveau-contact-form" data-focus="input[name=prenom]" title="Nouveau contact" aria-label="Nouveau contact"><?= icon('plus') ?></button>
        <button type="button" class="btn ghost btn-sm icon-only edit-toggle-btn" title="Modifier" aria-label="Modifier les contacts"><?= icon('pencil') ?></button>
    </div>
    <?php foreach ($contacts as $c): ?>
        <div class="contact-row">
            <div class="linked-add contact-read <?= $c['actif'] ? '' : 'inactif' ?>">
                <span>
                    <strong><?= e(trim($c['prenom'] . ' ' . $c['nom'])) ?></strong>
                    <?php if ($c['role']): ?><span class="muted small"> — <?= e($c['role']) ?></span><?php endif; ?>
                    <?php if ($c['email']): ?><span class="muted small"> — <?= e($c['email']) ?></span><?php endif; ?>
                    <?php if ($c['telephone']): ?><span class="muted small"> — <?= e($c['telephone']) ?></span><?php endif; ?>
                    <?php if ($c['formulaire_url']): ?><span class="muted small"> — <a href="<?= e($c['formulaire_url']) ?>" target="_blank" rel="noopener" onclick="event.stopPropagation()">Formulaire</a></span><?php endif; ?>
                    <?php if ($c['langue']): ?><span class="muted small"> — <?= e($c['langue']) ?></span><?php endif; ?>
                    <?php if ($c['est_administration']): ?><span class="badge">administration</span><?php endif; ?>
                    <?php if ($c['est_booking']): ?><span class="badge">booking</span><?php endif; ?>
                    <?php if ($c['desinscrit']): ?><span class="badge muted-badge">désinscrit</span><?php endif; ?>
                </span>
                <button type="button" class="btn ghost btn-sm icon-only contact-edit-btn edit-only" title="Modifier" aria-label="Modifier"><?= icon('pencil') ?></button>
                <form method="post" action="?p=structure_contact_delete" class="edit-only" onsubmit="return confirm('Supprimer ce contact ?');">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="structure_id" value="<?= $sid ?>">
                    <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                    <button type="submit" class="btn ghost btn-sm icon-only" title="Supprimer" aria-label="Supprimer"><?= icon('trash') ?></button>
                </form>
            </div>
            <form method="post" action="?p=structure_contact_ajouter" class="grid3 contact-edit-form" hidden>
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="structure_id" value="<?= $sid ?>">
                <input type="hidden" name="contact_id" value="<?= (int) $c['id'] ?>">
                <label>Prénom <input name="prenom" value="<?= e($c['prenom']) ?>"></label>
                <label>Nom <input name="nom" value="<?= e($c['nom']) ?>"></label>
                <label>Rôle <input name="role" value="<?= e($c['role']) ?>" placeholder="ex. Programmation"></label>
                <label>E-mail <input name="email" type="email" value="<?= e($c['email']) ?>"></label>
                <label>Téléphone <input name="telephone" type="tel" value="<?= e($c['telephone']) ?>"></label>
                <label>Langue <input name="langue" value="<?= e($c['langue']) ?>" placeholder="ex. FR"></label>
                <label class="grid3-full">Formulaire de contact (URL, optionnel) <input name="formulaire_url" type="url" value="<?= e($c['formulaire_url']) ?>" placeholder="https://…"></label>
                <label class="check"><input type="checkbox" name="est_administration" value="1" <?= $c['est_administration'] ? 'checked' : '' ?>> Administration <?= info_tip("Contact utilisé par défaut pour l'envoi des factures — un seul à la fois par structure.") ?></label>
                <label class="check"><input type="checkbox" name="est_booking" value="1" <?= $c['est_booking'] ? 'checked' : '' ?>> Booking <?= info_tip("S'il y a plusieurs contacts, le mailing n'est envoyé qu'à ceux marqués « booking ».") ?></label>
                <div class="form-actions grid3-full">
                    <button type="submit" class="btn ghost btn-sm"><?= icon('save') ?> Enregistrer</button>
                    <button type="button" class="btn ghost btn-sm contact-cancel-btn">Annuler</button>
                </div>
            </form>
        </div>
    <?php endforeach; ?>
    <?php if (!$contacts): ?><p class="muted small">Aucun contact.</p><?php endif; ?>

    <form method="post" action="?p=structure_contact_ajouter" class="grid3" id="nouveau-contact-form" hidden>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="structure_id" value="<?= $sid ?>">
        <div class="grid2">
            <label>Prénom <input name="prenom"></label>
            <label>Nom <input name="nom"></label>
        </div>
        <div class="grid2">
            <label>E-mail <input name="email" type="email"></label>
            <label>Téléphone <input name="telephone" type="tel"></label>
        </div>
        <label>Langue <input name="langue" placeholder="ex. FR"></label>
        <label class="grid3-full">Formulaire de contact (URL, optionnel) <input name="formulaire_url" type="url" placeholder="https://…"></label>
        <label class="check"><input type="checkbox" name="est_administration" value="1"> Administration <?= info_tip("Contact utilisé par défaut pour l'envoi des factures — un seul à la fois par structure.") ?></label>
        <label class="check"><input type="checkbox" name="est_booking" value="1"> Booking <?= info_tip("S'il y a plusieurs contacts, le mailing n'est envoyé qu'à ceux marqués « booking ».") ?></label>
        <label>Autre rôle<input name="role" placeholder="ex. Programmation"></label>
        <div class="form-actions grid3-full">
            <button type="submit" class="btn ghost btn-sm"><?= icon('plus') ?> Ajouter le contact</button>
            <button type="button" class="btn ghost btn-sm" data-hide="nouveau-contact-form">Annuler</button>
        </div>
    </form>
    <script>
    (function () {
        document.querySelectorAll('.contact-edit-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var row = btn.closest('.contact-row');
                row.querySelector('.contact-read').hidden = true;
                row.querySelector('.contact-edit-form').hidden = false;
            });
        });
        document.querySelectorAll('.contact-cancel-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var row = btn.closest('.contact-row');
                row.querySelector('.contact-edit-form').hidden = true;
                row.querySelector('.contact-read').hidden = false;
            });
        });

        // Crayon d'en-tête : bascule la section en mode édition — seules alors
        // apparaissent les commandes ajouter/lier/modifier/supprimer (.edit-only,
        // masquées par défaut, voir app.css). Le crayon devient une croix
        // (annuler) tant que l'édition est ouverte. En quittant l'édition, on
        // referme aussi les formulaires d'ajout/édition restés ouverts.
        var editToggleIconPencil = <?= json_encode(icon('pencil')) ?>;
        var editToggleIconX = <?= json_encode(icon('x')) ?>;
        document.querySelectorAll('.edit-toggle-btn').forEach(function (btn) {
            var titreDefaut = btn.title;
            btn.addEventListener('click', function () {
                var sec = btn.closest('.section-editable');
                var on = sec.classList.toggle('editing');
                btn.classList.toggle('on', on);
                btn.innerHTML = on ? editToggleIconX : editToggleIconPencil;
                btn.title = on ? 'Annuler' : titreDefaut;
                btn.setAttribute('aria-label', on ? 'Annuler' : titreDefaut);
                if (!on) {
                    sec.querySelectorAll('.contact-row').forEach(function (row) {
                        var ef = row.querySelector('.contact-edit-form');
                        var rv = row.querySelector('.contact-read');
                        if (ef) ef.hidden = true;
                        if (rv) rv.hidden = false;
                    });
                    var add = sec.querySelector('#nouveau-contact-form');
                    if (add) add.hidden = true;
                }
            });
        });
    })();
    </script>
</div>

</div>

<div class="card-col">

<?php
$rueAffichee = trim((string) ($structure['adresse_rue'] ?? ''));
$npaAffiche = trim((string) ($structure['adresse_npa'] ?? ''));
$villeHtmlS = ville_departement_canton_html(
    (string) ($structure['adresse_localite'] ?? ''),
    pays_drapeau_nom((string) ($structure['adresse_pays'] ?? '')),
    (string) ($structure['adresse_pays'] ?? ''),
    (string) ($structure['departement_canton'] ?? '')
);
?>
<div class="card card-flush card-editable" id="carte-localisation">
    <?php if (($_GET['ok'] ?? null) === 'localisation'): ?><p class="ok flash">Localisation enregistrée.</p><?php endif; ?>

    <div class="loc-header blur-glass">
        <div>
            <div><?= $villeHtmlS !== '' ? $villeHtmlS : '<span class="muted small">Ville non renseignée.</span>' ?></div>
            <?php if ($rueAffichee !== '' || $npaAffiche !== ''): ?>
            <div class="muted small"><?= e($rueAffichee) ?><?= $rueAffichee !== '' ? ' · ' : '' ?><?= e($npaAffiche) ?> <?= e((string) ($structure['adresse_localite'] ?? '')) ?></div>
            <?php endif; ?>
            <?php if (trim((string) ($structure['grande_region'] ?? '')) !== ''): ?><div class="muted small">· <?= e($structure['grande_region']) ?></div><?php endif; ?>
        </div>
        <div class="head-actions">
            <button type="button" class="btn ghost icon-only card-edit-btn" title="Modifier" aria-label="Modifier"><?= icon('pencil') ?></button>
            <button type="submit" form="structure-localisation-form" class="btn icon-only card-save-btn" hidden title="Enregistrer" aria-label="Enregistrer"><?= icon('save') ?></button>
            <a href="?p=structure&id=<?= $sid ?>" class="btn ghost icon-only card-cancel-btn" hidden title="Annuler" aria-label="Annuler"><?= icon('x') ?></a>
        </div>
    </div>

    <div class="card-disp">
        <div class="loc-map-bg">
            <?php if (trim((string) ($structure['adresse_localite'] ?? '')) !== ''): ?>
                <?php
                $miniCarteVille = (string) $structure['adresse_localite'];
                $miniCarteDepartementCanton = (string) ($structure['departement_canton'] ?? '');
                $miniCartePays = (string) ($structure['adresse_pays'] ?? 'Suisse');
                $miniCarteRetourRoute = 'structure';
                $miniCarteRetourId = $sid;
                require __DIR__ . '/_mini_carte.php';
                ?>
            <?php else: ?>
                <p class="muted small">Aucune ville renseignée.</p>
            <?php endif; ?>
        </div>
    </div>

    <form method="post" id="structure-localisation-form" action="?p=structure_localisation" class="card-edit form" hidden>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= $sid ?>">
        <input name="adresse_rue" value="<?= $v('adresse_rue') ?>" placeholder="Rue et numéro" aria-label="Rue et numéro" class="mb-16">
        <div class="grid2">
            <input name="adresse_npa" value="<?= $v('adresse_npa') ?>" placeholder="NPA" aria-label="NPA">
            <input name="adresse_localite" value="<?= $v('adresse_localite') ?>" placeholder="Localité" aria-label="Localité" class="input-titre">
        </div>
        <div class="grid3 mt-16">
            <input name="departement_canton" value="<?= $v('departement_canton') ?>" placeholder="Département / canton" aria-label="Département / canton">
            <select name="grande_region" class="region-select" aria-label="Région" title="Région (Normandie, Romandie… — se gère dans Paramètres → Pays)">
                <option value="">— Région —</option>
                <?= region_options_nom((string) ($structure['adresse_pays'] ?? 'Suisse'), (string) ($structure['grande_region'] ?? '')) ?>
            </select>
            <select name="adresse_pays" class="pays-select" aria-label="Pays"><?= pays_options_nom((string) ($structure['adresse_pays'] ?? 'Suisse')) ?></select>
        </div>
    </form>
</div>

<div class="card">
    <h2 class="mt-0">Historique <?= info_tip("Notes libres en flux chronologique. Cocher « prise de contact » alimente la date de dernier contact affichée dans la liste des structures.") ?></h2>
    <?php if (!empty($structure['mise_a_jour_le'])): ?>
        <p class="muted small">Dernière mise à jour connue (import) : <?= e(date('d.m.Y', strtotime($structure['mise_a_jour_le']))) ?></p>
    <?php endif; ?>
    <form method="post" action="?p=structure_note_ajouter" class="form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="structure_id" value="<?= $sid ?>">
        <textarea name="contenu" rows="2" placeholder="Ajouter une note…" required></textarea>
        <div class="form-actions">
            <label class="check"><input type="checkbox" name="est_contact" value="1"> Marquer comme prise de contact</label>
            <button type="submit" class="btn ghost btn-sm"><?= icon('message-square') ?> Ajouter</button>
        </div>
    </form>
    <div class="mt-16">
        <?php $histoEntrees = $notes; require __DIR__ . '/_historique.php'; ?>
        <?php if (!$notes): ?><p class="muted small">Aucune entrée pour l'instant.</p><?php endif; ?>
    </div>
</div>

</div>

</div>

<?php endif; ?>
<?php require __DIR__ . '/_region_select_js.php'; ?>
