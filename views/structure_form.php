<?php /** @var ?array $structure */ /** @var ?string $err */
/** @var array $categoriesPourSelect */ /** @var int $categorieIdSelectionnee */
/** @var array $contacts */ /** @var array $notes */ /** @var array $tags */ /** @var array $tagsDispo */
/** @var array $lieuxLies */ /** @var array $lieuxDispo */ /** @var array $organisateurDispo */ /** @var array $categoriesLieu */
$v = fn(string $k, $d = '') => e((string) ($structure[$k] ?? $d));
$isEdit = !empty($structure['id']);
$sid = (int) ($structure['id'] ?? 0);
$peutEcrireStruct = peut_ecrire('facturation') || peut_ecrire('booking');
$peutEcrireBooking = peut_ecrire('booking');
?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>

<div class="module-content"><div class="module-content-inner">
<?= lien_retour_contextuel('?p=structures', 'Structures') ?>
<div class="page-head">
    <?php if ($isEdit && $peutEcrireStruct): ?>
    <div class="titre-row">
        <div class="titre-read">
            <h1><?= $v('nom') ?></h1>
            <button type="button" class="btn ghost btn-sm icon-only titre-edit-btn" title="Modifier le nom" aria-label="Modifier le nom"><?= icon('pencil') ?></button>
        </div>
        <form method="post" action="?p=structure_renommer" class="titre-edit-form" hidden>
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= $sid ?>">
            <input type="text" name="nom" class="input-titre" value="<?= $v('nom') ?>" required>
            <button type="submit" class="btn btn-sm icon-only" title="Enregistrer" aria-label="Enregistrer"><?= icon('save') ?></button>
            <button type="button" class="btn ghost btn-sm icon-only titre-cancel-btn" title="Annuler" aria-label="Annuler"><?= icon('x') ?></button>
        </form>
    </div>
    <script nonce="<?= e(csp_nonce()) ?>">
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
    <?php elseif ($isEdit): ?>
    <h1><?= $v('nom') ?></h1>
    <?php else: ?>
    <h1>Nouvelle structure</h1>
    <?php endif; ?>
    <?php if ($isEdit && $peutEcrireStruct && (int) ($structure['nb_factures'] ?? 0) === 0): ?>
    <div class="head-actions">
        <form method="post" action="?p=structure_delete" data-confirm="Supprimer définitivement cette structure ?" class="d-inline">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= $sid ?>">
            <button type="submit" class="btn danger icon-only" title="Supprimer" aria-label="Supprimer la structure"><?= icon('trash') ?></button>
        </form>
    </div>
    <?php endif; ?>
</div>

<?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>
<?php if (($_GET['err'] ?? null) === 'used'): ?><p class="err flash">Suppression impossible : des factures sont rattachées à cette structure.</p><?php endif; ?>
<?php if (($_GET['ok'] ?? null) === 'fusion'): ?><p class="ok flash">Structures fusionnées : contacts, notes, factures, étiquettes et lieux liés ont été repris ici.</p><?php endif; ?><?php $avecAside = $isEdit && module_actif('booking') && peut_lire('booking'); ?>
<?php if (!empty($structure['mise_a_jour_le']) && !$avecAside): ?>
    <p class="muted small">Dernière mise à jour connue (import) : <?= e(date('d.m.Y', strtotime($structure['mise_a_jour_le']))) ?></p>
<?php endif; ?>

<?php if (!$isEdit && !$peutEcrireStruct): ?>
<p class="err">Vous n'avez pas les droits d'écriture nécessaires pour cette action.</p>
<?php elseif (!$isEdit): ?>
<?php
// Période/statut/étiquettes : mêmes conditions d'accès que leurs équivalents
// en édition (carte « Informations générales »/« Statut », plus haut dans ce
// fichier) — période lisible dès que le module booking est lu, statut/
// étiquettes seulement s'il est modifiable.
$bookingOkCreation = module_actif('booking') && peut_lire('booking');
?>
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

			<label><span>Connu via <?= info_tip("D'où vient ce contact — un intermédiaire, une recommandation, une source…") ?></span> <input name="via" value="<?= $v('via') ?>" placeholder="ex. Recommandé par…"></label>

                <?php if ($bookingOkCreation && peut_ecrire('booking')): ?>
                <label>Statut
                    <select name="statut">
                        <?php foreach (STRUCTURE_STATUTS as $st): ?>
                            <option value="<?= e($st) ?>" <?= $st === 'actif' ? 'selected' : '' ?>><?= e(structure_statut_libelle($st)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php endif; ?>

            </div>

            <?php if ($bookingOkCreation && peut_ecrire('booking')): ?>
            <div class="field-group mt-16">
                <span>Étiquettes</span>
                <div class="tags-liste" id="tags-nouvelles-liste"></div>
                <div class="linked-add mt-10">
                    <div class="cat-search tag-search">
                        <input type="text" id="tag-nouveau-input" class="cat-search-input" placeholder="Ajouter une étiquette…" autocomplete="off">
                        <ul class="cat-search-list" hidden role="listbox">
                            <?php foreach ($tagsDispo as $t): ?><li><?= e($t['nom']) ?></li><?php endforeach; ?>
                        </ul>
                    </div>
                    <button type="button" id="tag-nouveau-btn" class="btn ghost btn-sm icon-only" title="Ajouter" aria-label="Ajouter l'étiquette"><?= icon('plus') ?></button>
                </div>
            </div>
            <?php endif; ?>
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

            <?php if ($bookingOkCreation): ?>
            <fieldset class="fieldset-groupe">
                <legend>Période</legend>
                <label class="check"><input type="checkbox" id="periode-toute-annee-creation" checked> Toute l'année</label>
                <div id="periode-mois-champs-creation" class="grid3" hidden>
                    <label>Début de réalisation
                        <select name="mois_evenement_debut"><?= mois_options(0) ?></select>
                    </label>
                    <label>Fin de réalisation
                        <select name="mois_evenement_fin"><?= mois_options(0) ?></select>
                    </label>
                    <label>Début de préparation
                        <select name="mois_debut"><?= mois_options(0) ?></select>
                    </label>
                    <label>Fin de préparation
                        <select name="mois_fin"><?= mois_options(0) ?></select>
                    </label>
                </div>
            </fieldset>
            <?php endif; ?>

            <label>Remarques
                <textarea name="notes" rows="2"><?= $v('notes') ?></textarea>
            </label>
    </div>

    <div class="form-actions">
        <button type="submit"><?= icon('save') ?> Enregistrer</button>
        <a class="btn ghost" href="?p=structures">Annuler</a>
    </div>
</form>
<?php if ($bookingOkCreation): ?>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    var chk = document.getElementById('periode-toute-annee-creation');
    var champs = document.getElementById('periode-mois-champs-creation');
    if (chk && champs) {
        chk.addEventListener('change', function () {
            champs.hidden = chk.checked;
            if (chk.checked) {
                champs.querySelectorAll('select').forEach(function (s) { s.value = ''; });
            }
        });
    }
    // Étiquettes à ajouter à la création : pas encore d'id de structure pour
    // les lier tout de suite (structure_attacher_tag() attache à un id
    // existant) — accumulées en puces ici, transmises via tags[] (un champ
    // cadré par puce) au même POST que le reste du formulaire ; route_structure()
    // les attache une à une juste après l'insertion (lib/routes_facturation.php).
    var liste = document.getElementById('tags-nouvelles-liste');
    var input = document.getElementById('tag-nouveau-input');
    var btn = document.getElementById('tag-nouveau-btn');
    if (liste && input && btn) {
        function ajouterPuce() {
            var nom = input.value.trim();
            if (!nom) return;
            var deja = Array.from(liste.querySelectorAll('input[name="tags[]"]'))
                .some(function (h) { return h.value.toLowerCase() === nom.toLowerCase(); });
            if (deja) { input.value = ''; return; }
            var puce = document.createElement('span');
            puce.className = 'badge';
            puce.textContent = nom + ' ';
            var retirer = document.createElement('button');
            retirer.type = 'button';
            retirer.className = 'btn-tag-x';
            retirer.setAttribute('aria-label', 'Retirer');
            retirer.textContent = '×';
            retirer.addEventListener('click', function () { puce.remove(); });
            var hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'tags[]';
            hidden.value = nom;
            puce.appendChild(retirer);
            puce.appendChild(hidden);
            liste.appendChild(puce);
            input.value = '';
            input.focus();
        }
        btn.addEventListener('click', ajouterPuce);
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); ajouterPuce(); }
        });
        // Cliquer une suggestion l'ajoute directement (voir lassoInitTagSuggest(),
        // assets/app.js) — pas besoin d'un clic supplémentaire sur le bouton +.
        input.addEventListener('tagselected', ajouterPuce);
    }
})();
lassoInitTagSuggest();
</script>
<?php endif; ?>

<?php elseif (!$avecAside): ?>
<!-- Sans le module booking (ou sans lecture dessus) : pas de carte
     « Localisation » séparée, les coordonnées restent ici — voir
     route_structure() (lib/routes_facturation.php). -->
<form method="post" action="?p=structure&id=<?= (int) $structure['id'] ?>" class="card card-editable form" id="structure-details-form">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="nom" value="<?= $v('nom') ?>">

    <?php if ($peutEcrireStruct): ?>
    <div class="head-actions card-actions-overlay">
        <button type="button" class="btn ghost icon-only card-edit-btn" title="Modifier" aria-label="Modifier"><?= icon('pencil') ?></button>
        <button type="submit" class="btn icon-only card-save-btn" hidden title="Enregistrer" aria-label="Enregistrer"><?= icon('save') ?></button>
        <a href="?p=structure&id=<?= (int) $structure['id'] ?>" class="btn ghost icon-only card-cancel-btn" hidden title="Annuler" aria-label="Annuler"><?= icon('x') ?></a>
    </div>
    <?php endif; ?>

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
     hauteur indépendante — colonne 1 : Statut + Informations générales (la
     carte « Période » a été fusionnée dedans, avant Remarques) ; colonne 2 :
     Structures liées + Événements + Contacts ; colonne 3 : Localisation +
     Historique (voir le fil de discussion pour la répartition exacte). -->
<div class="card-columns">

<div class="card-col">

<div class="card">
    <div class="card-head-row">
        <h2 class="mt-0">Statut</h2>
        <?php if ($peutEcrireBooking): ?><?= structure_statut_toggle_html($sid, (string) $structure['statut']) ?><?php else: ?><span class="badge"><?= e(structure_statut_libelle((string) $structure['statut'])) ?></span><?php endif; ?>
    </div>

    <div class="tags-liste mt-16">
        <?php foreach ($tags as $t): ?>
            <span class="badge"<?= badge_style_html((string) ($t['couleur'] ?? '')) ?>><?= e($t['nom']) ?>
                <?php if ($peutEcrireBooking): ?>
                <form method="post" action="?p=structure_tag_retirer" class="d-inline" data-confirm="Retirer cette étiquette ?">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="structure_id" value="<?= $sid ?>">
                    <input type="hidden" name="tag_id" value="<?= (int) $t['id'] ?>">
                    <button type="submit" class="btn-tag-x" aria-label="Retirer">×</button>
                </form>
                <?php endif; ?>
            </span>
        <?php endforeach; ?>
        <?php if (!$tags): ?><span class="muted small">Aucune étiquette.</span><?php endif; ?>
        <?php if ($peutEcrireBooking): ?>
        <button type="button" class="badge tag-ajouter-btn" data-show="tag-ajouter-form" data-focus="input[name=nom]" title="Ajouter une étiquette" aria-label="Ajouter une étiquette">+</button>
        <?php endif; ?>
    </div>
    <?php if ($peutEcrireBooking): ?>
    <form method="post" action="?p=structure_tag_ajouter" class="linked-add mt-10" id="tag-ajouter-form" hidden>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="structure_id" value="<?= $sid ?>">
        <div class="cat-search tag-search">
            <input type="text" name="nom" class="cat-search-input" placeholder="Ajouter une étiquette…" autocomplete="off">
            <ul class="cat-search-list" hidden role="listbox">
                <?php foreach ($tagsDispo as $t): ?><li><?= e($t['nom']) ?></li><?php endforeach; ?>
            </ul>
        </div>
        <button type="submit" class="btn ghost btn-sm icon-only" title="Ajouter" aria-label="Ajouter l'étiquette"><?= icon('plus') ?></button>
        <button type="button" class="btn ghost btn-sm icon-only" data-hide="tag-ajouter-form" title="Annuler" aria-label="Annuler"><?= icon('x') ?></button>
    </form>
    <?php endif; ?>
</div>
<?php if ($peutEcrireBooking): ?><script nonce="<?= e(csp_nonce()) ?>">lassoInitStatutToggle(); lassoInitTagSuggest();</script><?php endif; ?>

<div class="card card-editable">
    <?php
    $categorieAffichee = trim((string) ($structure['categorie'] ?? ''));
    if ($categorieAffichee !== '' && trim((string) ($structure['sous_categorie'] ?? '')) !== '') {
        $categorieAffichee .= ' › ' . $structure['sous_categorie'];
    }
    $periodeVide = empty($structure['mois_evenement_debut']) && empty($structure['mois_evenement_fin'])
        && empty($structure['mois_debut']) && empty($structure['mois_fin']);
    ?>
    <form method="post" action="?p=structure&id=<?= $sid ?>" class="form" id="structure-details-form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="nom" value="<?= $v('nom') ?>">

        <div class="card-head-row">
            <h2 class="mt-0">Informations générales</h2>
            <?php if ($peutEcrireStruct): ?>
            <div class="head-actions">
                <button type="button" class="btn ghost icon-only card-edit-btn" title="Modifier" aria-label="Modifier"><?= icon('pencil') ?></button>
                <button type="submit" class="btn icon-only card-save-btn" hidden title="Enregistrer" aria-label="Enregistrer"><?= icon('save') ?></button>
                <a href="?p=structure&id=<?= $sid ?>" class="btn ghost icon-only card-cancel-btn" hidden title="Annuler" aria-label="Annuler"><?= icon('x') ?></a>
            </div>
            <?php endif; ?>
        </div>

        <div class="card-disp">
            <table class="kv-table">
                <tr>
                    <th>Catégorie</th>
                    <td><?= $categorieAffichee !== '' ? e($categorieAffichee) : '—' ?></td>
                </tr>
                <tr>
                    <th>Site web</th>
                    <td><?php if (trim((string) ($structure['site_web'] ?? '')) !== ''): ?><a href="<?= $v('site_web') ?>" target="_blank" rel="noopener"><?= $v('site_web') ?></a><?php else: ?>—<?php endif; ?></td>
                </tr>
                <tr>
                    <th>Jauge</th>
                    <td>
                        <?php
                        $jaugeMinAff = $structure['jauge_min'] ?? null;
                        $jaugeMaxAff = $structure['jauge_max'] ?? null;
                        if ($jaugeMinAff !== null && $jaugeMinAff !== '' && $jaugeMaxAff !== null && $jaugeMaxAff !== ''):
                            echo (int) $jaugeMinAff . ' – ' . (int) $jaugeMaxAff;
                        elseif ($jaugeMinAff !== null && $jaugeMinAff !== ''):
                            echo '≥ ' . (int) $jaugeMinAff;
                        elseif ($jaugeMaxAff !== null && $jaugeMaxAff !== ''):
                            echo '≤ ' . (int) $jaugeMaxAff;
                        else:
                            echo '—';
                        endif;
                        ?>
                    </td>
                </tr>
                <tr>
                    <th>Réalisation</th>
                    <td>
                        <?php if (empty($structure['mois_evenement_debut']) && empty($structure['mois_evenement_fin'])): ?>Toute l'année
                        <?php else: ?><?= !empty($structure['mois_evenement_debut']) ? e(mois_nom((int) $structure['mois_evenement_debut'])) : '—' ?> – <?= !empty($structure['mois_evenement_fin']) ? e(mois_nom((int) $structure['mois_evenement_fin'])) : '—' ?><?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Préparation</th>
                    <td>
                        <?php if (empty($structure['mois_debut']) && empty($structure['mois_fin'])): ?>Toute l'année
                        <?php else: ?><?= !empty($structure['mois_debut']) ? e(mois_nom((int) $structure['mois_debut'])) : '—' ?> – <?= !empty($structure['mois_fin']) ? e(mois_nom((int) $structure['mois_fin'])) : '—' ?><?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Remarques</th>
                    <td>
                        <?php $notesTxt = trim((string) ($structure['notes'] ?? '')); ?>
                        <?php if ($notesTxt === ''): ?>—
                        <?php elseif (mb_strlen($notesTxt) > 200): ?>
                            <span class="notes-tronquees"><?= nl2br(e(mb_substr($notesTxt, 0, 200)) . '…') ?></span>
                            <span class="notes-completes" hidden><?= nl2br(e($notesTxt)) ?></span>
                            <button type="button" class="voir-tout-btn">voir tout</button>
                        <?php else: ?><?= nl2br(e($notesTxt)) ?><?php endif; ?>
                    </td>
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
            <input type="hidden" name="via" value="<?= $v('via') ?>">
            <label>Site web <input name="site_web" type="url" value="<?= $v('site_web') ?>" placeholder="https://…"></label>
            <div class="grid2">
                <label>Jauge min <input name="jauge_min" type="number" min="0" value="<?= ($structure['jauge_min'] ?? '') !== '' ? (int) $structure['jauge_min'] : '' ?>" placeholder="ex. 200"></label>
                <label>Jauge max <input name="jauge_max" type="number" min="0" value="<?= ($structure['jauge_max'] ?? '') !== '' ? (int) $structure['jauge_max'] : '' ?>" placeholder="ex. 800"></label>
            </div>
            <label class="check"><input type="checkbox" id="periode-toute-annee" <?= $periodeVide ? 'checked' : '' ?>> Toute l'année</label>
            <div id="periode-mois-champs" class="grid3" <?= $periodeVide ? 'hidden' : '' ?>>
                <label>Début de réalisation
                    <select name="mois_evenement_debut"><?= mois_options((int) ($structure['mois_evenement_debut'] ?? 0)) ?></select>
                </label>
                <label>Fin de réalisation
                    <select name="mois_evenement_fin"><?= mois_options((int) ($structure['mois_evenement_fin'] ?? 0)) ?></select>
                </label>
                <label>Début de préparation
                    <select name="mois_debut"><?= mois_options((int) ($structure['mois_debut'] ?? 0)) ?></select>
                </label>
                <label>Fin de préparation
                    <select name="mois_fin"><?= mois_options((int) ($structure['mois_fin'] ?? 0)) ?></select>
                </label>
            </div>
            <label>Remarques
                <textarea name="notes" rows="2"><?= $v('notes') ?></textarea>
            </label>
        </div>
    </form>
</div>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    var chk = document.getElementById('periode-toute-annee');
    var champs = document.getElementById('periode-mois-champs');
    if (!chk || !champs) return;
    chk.addEventListener('change', function () {
        champs.hidden = chk.checked;
        if (chk.checked) {
            champs.querySelectorAll('select').forEach(function (s) { s.value = ''; });
        }
    });
})();
</script>

</div>

<div class="card-col">

<div class="card card-lieux-liees">
    <div class="card-block section-editable">
        <div class="card-head-row">
            <h2 class="mt-0">Structures liées</h2>
            <?php if ($peutEcrireBooking): ?>
            <button type="button" class="btn ghost btn-sm icon-only edit-toggle-btn" title="Modifier" aria-label="Modifier les structures liées"><?= icon('pencil') ?></button>
            <?php endif; ?>
        </div>
        <?php
        $lieuxOrganises = array_values(array_filter($lieuxLies, fn ($l) => $l['sens'] === 'organise'));
        $lieuxOrganisePar = array_values(array_filter($lieuxLies, fn ($l) => $l['sens'] === 'organise_par'));
        $ligneLien = function (array $l) use ($sid): void { ?>
            <div class="linked-add">
                <span>
                    <strong><?= icon($l['sens'] === 'organise' ? 'blocks' : 'building') ?> <a href="<?= url_avec_retour('?p=structure&id=' . (int) $l['id'], 'structure', $sid) ?>"><?= e($l['nom']) ?></a></strong>
                    <div class="muted small"><?= e((string) $l['type']) ?>
                    <?php if ($l['ville']): ?> · <?= e($l['ville']) ?><?php endif; ?></div>
                </span>
                <form method="post" action="?p=structure_lieu_delier" class="edit-only" data-confirm="Délier ?">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="structure_id" value="<?= $sid ?>">
                    <input type="hidden" name="lieu_id" value="<?= (int) $l['id'] ?>">
                    <input type="hidden" name="sens" value="<?= e((string) $l['sens']) ?>">
                    <button type="submit" class="btn ghost btn-sm icon-only" title="Délier" aria-label="Délier"><?= icon('unlink') ?></button>
                </form>
            </div>
        <?php };
        ?>

        <?php if (!$lieuxLies): ?><p class="muted small read-only">Aucune structure liée.</p><?php endif; ?>

        <p class="muted small mb-8 edit-only">Organise</p>
        <?php foreach ($lieuxOrganises as $l) { $ligneLien($l); } ?>
        <?php if (!$lieuxOrganises): ?><p class="muted small edit-only">Aucune salle ni festival lié.</p><?php endif; ?>

        <form method="post" action="?p=structure_lieu_lier" class="linked-add edit-only" id="lieu-form">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="structure_id" value="<?= $sid ?>">
            <input type="hidden" name="sens" value="organise">
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

        <p class="muted small mb-8 mt-16 edit-only">Organisée par</p>
        <?php foreach ($lieuxOrganisePar as $l) { $ligneLien($l); } ?>
        <?php if (!$lieuxOrganisePar): ?><p class="muted small edit-only">Aucun organisateur lié.</p><?php endif; ?>

        <form method="post" action="?p=structure_lieu_lier" class="linked-add edit-only" id="organisateur-form">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="structure_id" value="<?= $sid ?>">
            <input type="hidden" name="sens" value="organise_par">
            <div class="cat-search organisateur-search">
                <input type="text" class="cat-search-input" placeholder="Rechercher une structure organisatrice…" autocomplete="off">
                <input type="hidden" name="lieu_id" class="cat-search-val" value="">
                <ul class="cat-search-list" hidden role="listbox">
                    <li data-val="__new__">+ Nouvelle structure</li>
                    <?php foreach ($organisateurDispo as $o): ?>
                        <li data-val="<?= (int) $o['id'] ?>"><?= e($o['nom']) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <button type="submit" class="btn ghost btn-sm"><?= icon('link') ?> Lier</button>
            <div id="organisateur-nouveau" hidden class="grid3 mt-10">
                <label>Nom <input name="nl_nom"></label>
                <label>Ville <input name="nl_ville"></label>
                <label>Pays <input name="nl_pays" placeholder="Suisse"></label>
            </div>
        </form>
        <script nonce="<?= e(csp_nonce()) ?>">
        (function () {
            var wrapLieu = document.querySelector('.lieu-search');
            var nouveauLieu = document.getElementById('lieu-nouveau');
            if (wrapLieu && window.lassoInitCatSearch) {
                lassoInitCatSearch(wrapLieu, {
                    showPlaceholderText: true,
                    clearHiddenOnInput: true,
                    onSelect: function (li) { nouveauLieu.hidden = li.dataset.val !== '__new__'; },
                });
            }
            var wrapOrga = document.querySelector('.organisateur-search');
            var nouveauOrga = document.getElementById('organisateur-nouveau');
            if (wrapOrga && window.lassoInitCatSearch) {
                lassoInitCatSearch(wrapOrga, {
                    showPlaceholderText: true,
                    clearHiddenOnInput: true,
                    onSelect: function (li) { nouveauOrga.hidden = li.dataset.val !== '__new__'; },
                });
            }
        })();
        </script>
    </div>
</div>

<?php if (module_actif('evenements') && $evenementsLies): ?>
<div class="card">
    <div class="card-block">
        <h2 class="mt-0">Événements (<?= count($evenementsLies) ?>)</h2>
        <ul class="clean-list">
            <?php foreach (array_slice($evenementsLies, 0, 30) as $ev):
                $ts = $ev['date'] ? strtotime((string) $ev['date']) : false;
            ?>
            <li class="evt-row">
                <a href="?p=evenement&id=<?= (int) $ev['id'] ?>" class="evt-date">
                    <?php if ($ts): ?>
                        <span class="evt-date-jm"><?= date('d', $ts) ?> <?= mois_abrege((int) date('n', $ts)) ?></span>
                        <span class="evt-date-an"><?= date('Y', $ts) ?></span>
                    <?php else: ?>
                        <span class="evt-date-jm">—</span>
                    <?php endif; ?>
                </a>
                <div class="evt-info">
                    <?php $nomPrincipal = (string) ($ev['spectacle_groupe'] ?? '') !== '' ? (string) $ev['spectacle_groupe'] : ((string) ($ev['spectacle'] ?? '') ?: 'Événement'); ?>
                    <div class="evt-spectacle"><a href="?p=evenement&id=<?= (int) $ev['id'] ?>"><?= e($nomPrincipal) ?></a></div>
                    <?php if ((string) ($ev['spectacle_groupe'] ?? '') !== '' && (string) ($ev['spectacle'] ?? '') !== ''): ?>
                        <div class="muted small"><?= e((string) $ev['spectacle']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($ev['structure_id'])): ?>
                        <div class="muted small"><span class="ico-tiny"><?= icon($ev['sens'] === 'organise' ? 'blocks' : 'building') ?></span> <?= e((string) $ev['structure_nom']) ?></div>
                    <?php endif; ?>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php if (count($evenementsLies) > 30): ?><p class="muted small">… et <?= count($evenementsLies) - 30 ?> autre(s).</p><?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php // Pas de bascule « mode édition » sur cette carte : la commande d'ajout est
      // toujours là, et chaque ligne porte son propre crayon (révélé au survol,
      // permanent au tactile — voir .contact-read .contact-edit-btn, app.css).
      // Un mode global obligeait à deux clics avant toute modification. ?>
<div class="card">
    <div class="card-head-row">
        <h2 class="mt-0">Contacts</h2>
        <?php if ($peutEcrireBooking): ?>
        <button type="button" class="btn ghost btn-sm icon-only" data-show="nouveau-contact-form" data-focus="input[name=prenom]" title="Nouveau contact" aria-label="Nouveau contact"><?= icon('user-plus') ?></button>
        <?php endif; ?>
    </div>
    <?php foreach ($contacts as $c): ?>
        <div class="contact-row">
            <div class="linked-add contact-read <?= $c['actif'] ? '' : 'inactif' ?>">
                <span>
                    <strong><?= e(trim($c['prenom'] . ' ' . $c['nom'])) ?></strong>
                    <?php if ($c['est_administration']): ?><span class="badge">facturation</span><?php endif; ?>
                    <?php if ($c['est_booking']): ?><span class="badge">booking</span><?php endif; ?>
                    <?php if ($c['desinscrit']): ?><span class="badge muted-badge">Désinscrit</span><?php endif; ?>
                    <?php if ($c['role']): ?><span class="muted small"> — <?= e($c['role']) ?></span><?php endif; ?>
                    <?php if ($c['email']): ?><div class="muted small"><?= e($c['email']) ?></div><?php endif; ?>
                    <?php if ($c['telephone']): ?><div class="muted small"><?= e($c['telephone']) ?></div><?php endif; ?>
                    <?php if ($c['formulaire_url']): ?><div class="muted small"> — <a href="<?= e($c['formulaire_url']) ?>" target="_blank" rel="noopener">Formulaire</a></div><?php endif; ?>
                    <?php if ($c['langue']): ?><span class="muted small"><?= e($c['langue']) ?></span><?php endif; ?>
                </span>
                <?php if ($peutEcrireBooking): ?>
                <button type="button" class="btn ghost btn-sm icon-only contact-edit-btn" title="Modifier" aria-label="Modifier"><?= icon('pencil') ?></button>
                <?php endif; ?>
            </div>
            <?php $cfContact = $c; $cfSid = $sid; require __DIR__ . '/_structure_contact_form.php'; ?>
            <?php // Suppression : le formulaire vit ICI, à côté du formulaire d'édition
                  // (jamais dedans, deux <form> ne s'imbriquent pas) ; son bouton est en
                  // bas du cadre d'édition et le vise par form="contact-del-N". ?>
            <form method="post" action="?p=structure_contact_delete" id="contact-del-<?= (int) $c['id'] ?>" data-confirm="Supprimer ce contact ?" hidden>
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="structure_id" value="<?= $sid ?>">
                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
            </form>
        </div>
    <?php endforeach; ?>
    <?php if (!$contacts && !$contactsLies): ?><p class="muted small">Aucun contact.</p><?php endif; ?>

    <?php
        $sensParStructure = array_column($lieuxLies, 'sens', 'id');
    ?>
    <?php if ($contactsLies): ?>
        <?php foreach ($contactsLies as $c): ?>
            <div class="linked-add contact-read <?= $c['actif'] ? '' : 'inactif' ?>">
                <span>
                    <strong><?= e(trim($c['prenom'] . ' ' . $c['nom'])) ?></strong>
                    <?php if ($c['role']): ?><span class="muted small"> — <?= e($c['role']) ?></span><?php endif; ?>
                    <div class="muted small"><span class="ico-tiny"><?= icon(($sensParStructure[$c['structure_id']] ?? '') === 'organise' ? 'blocks' : 'building') ?></span> <a href="<?= url_avec_retour('?p=structure&id=' . (int) $c['structure_id'], 'structure', $sid) ?>"><?= e((string) $c['structure_nom']) ?></a></div>
                    <?php if ($c['email']): ?><div class="muted small"><?= e($c['email']) ?></div><?php endif; ?>
                    <?php if ($c['telephone']): ?><div class="muted small"><?= e($c['telephone']) ?></div><?php endif; ?>
                </span>
                <?php // Ces contacts appartiennent à une AUTRE structure : on ne les
                      // modifie pas d'ici (ce serait éditer une fiche qu'on n'a pas
                      // sous les yeux). Le bouton y mène plutôt, pour qu'aucune ligne
                      // de la carte ne reste sans commande — une ligne sur deux sans
                      // bouton laissait croire à un bug d'affichage. Icône « bâtiment »
                      // et non crayon : il emmène ailleurs, il ne modifie pas ici. ?>
                <a class="btn ghost btn-sm icon-only contact-lien-btn" href="<?= url_avec_retour('?p=structure&id=' . (int) $c['structure_id'], 'structure', $sid) ?>"
                   title="Modifier chez « <?= e((string) $c['structure_nom']) ?> »" aria-label="Modifier chez <?= e((string) $c['structure_nom']) ?>"><?= icon('building') ?></a>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php $cfContact = null; $cfSid = $sid; require __DIR__ . '/_structure_contact_form.php'; ?>

    <script nonce="<?= e(csp_nonce()) ?>">
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

        // Crayon d'en-tête des AUTRES sections de la fiche (structures liées) :
        // bascule la section en mode édition — seules alors apparaissent ses
        // commandes lier/délier (.edit-only, masquées par défaut, voir app.css).
        // Le crayon devient une croix (annuler) tant que l'édition est ouverte.
        // La carte Contacts, elle, n'a plus de mode global : chaque ligne porte
        // son crayon et le formulaire d'ajout est toujours accessible.
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
            <?php if (trim((string) ($structure['grande_region'] ?? '')) !== ''): ?><div class="muted small"><?= e($structure['grande_region']) ?></div><?php endif; ?>
        </div>
        <?php if ($peutEcrireBooking): ?>
        <div class="head-actions">
            <button type="button" class="btn ghost icon-only card-edit-btn" title="Modifier" aria-label="Modifier"><?= icon('pencil') ?></button>
            <button type="submit" form="structure-localisation-form" class="btn icon-only card-save-btn" hidden title="Enregistrer" aria-label="Enregistrer"><?= icon('save') ?></button>
            <a href="?p=structure&id=<?= $sid ?>" class="btn ghost icon-only card-cancel-btn" hidden title="Annuler" aria-label="Annuler"><?= icon('x') ?></a>
        </div>
        <?php endif; ?>
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

<div class="card card-editable">
    <div class="card-head-row">
        <h2 class="mt-0">Historique <?= info_tip("Les dates de synthèse de la fiche. Le flux des notes et des contacts est dans « Historique détaillé », plus bas.") ?></h2>
        <?php if ($peutEcrireBooking): ?>
        <div class="head-actions">
            <button type="button" class="btn ghost icon-only card-edit-btn" title="Modifier" aria-label="Modifier"><?= icon('pencil') ?></button>
            <button type="submit" form="structure-via-form" class="btn icon-only card-save-btn" hidden title="Enregistrer" aria-label="Enregistrer"><?= icon('save') ?></button>
            <a href="?p=structure&id=<?= $sid ?>" class="btn ghost icon-only card-cancel-btn" hidden title="Annuler" aria-label="Annuler"><?= icon('x') ?></a>
        </div>
        <?php endif; ?>
    </div>
    <?php if (($_GET['ok'] ?? null) === 'via'): ?><p class="ok flash">Enregistré.</p><?php endif; ?>
    <div class="card-disp">
        <table class="kv-table">
            <tr>
                <th>Connu via</th>
                <td><?= trim((string) ($structure['via'] ?? '')) !== '' ? $v('via') : '—' ?></td>
            </tr>
            <tr>
                <th>Dernier contact</th>
                <td><?= !empty($structure['dernier_contact_le']) ? e(date('d.m.Y', strtotime($structure['dernier_contact_le']))) : '—' ?></td>
            </tr>
            <tr>
                <th>Dernière modification</th>
                <td><?= !empty($notes[0]['cree_le']) ? e(date('d.m.Y H:i', strtotime($notes[0]['cree_le']))) : '—' ?></td>
            </tr>
            <tr>
                <?php // structures.cree_le vaut datetime('now') à l'insertion : pour une
                      // fiche importée, c'est donc la date de l'import, pas celle de la
                      // création chez la source — d'où le libellé qui couvre les deux. ?>
                <th>Créée / importée</th>
                <td><?= !empty($structure['cree_le']) ? e(date('d.m.Y H:i', strtotime((string) $structure['cree_le']))) : '—' ?></td>
            </tr>
        </table>
    </div>
    <form method="post" id="structure-via-form" action="?p=structure_via" class="card-edit form" hidden>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= $sid ?>">
        <label><span>Connu via <?= info_tip("D'où vient ce contact — un intermédiaire, une recommandation, une source…") ?></span> <input name="via" value="<?= $v('via') ?>" placeholder="ex. Recommandé par…"></label>
        <label><span>Dernier contact <?= info_tip("Rattrapage manuel — sera écrasé par la prochaine prise de contact enregistrée (note ou mailing).") ?></span> <input type="date" name="dernier_contact_le" value="<?= !empty($structure['dernier_contact_le']) ? e(date('Y-m-d', strtotime($structure['dernier_contact_le']))) : '' ?>"></label>
    </form>
    <?php if (!empty($structure['mise_a_jour_le'])): ?>
        <p class="muted small">Dernière mise à jour connue (import) : <?= e(date('d.m.Y', strtotime($structure['mise_a_jour_le']))) ?></p>
    <?php endif; ?>
    <?php // Le flux d'entrées vit dans sa propre carte, « Historique détaillé »,
          // sous la grille de colonnes : à 400px de large, le formulaire de note
          // et les entrées se comprimaient au point de casser les libellés en
          // quatre lignes. Cette carte-ci ne garde que les dates de synthèse. ?>
</div>

</div>

</div>

<?php // Pleine largeur, hors de .card-columns : le flux a besoin de place — une
      // note fait plusieurs lignes, et le formulaire d'édition d'une entrée
      // aligne date, case à cocher et boutons sur une seule rangée. ?>
<div class="card mt-22" id="historique-detaille">
    <div class="card-head-row">
        <h2 class="mt-0">Historique détaillé <?= info_tip("Notes libres en flux chronologique. Cocher « prise de contact » alimente la date de dernier contact affichée dans la liste des structures.") ?></h2>
    </div>
    <?php if ($peutEcrireBooking): ?>
    <?php // Tout sur une rangée tant que la largeur le permet — date, texte,
          // prise de contact, bouton — et l'enroulement s'en charge en dessous.
          // La date d'abord : c'est elle qu'on corrige quand on consigne après
          // coup, et la lire avant d'écrire évite de s'en apercevoir trop tard.
          //
          // Aucun libellé au-dessus des champs : la bande se lit comme une barre
          // de saisie, l'invite du texte et le format de la date se suffisent, et
          // tous les contrôles se retrouvent alors à la même hauteur. Chacun
          // porte un aria-label, pour que son nom existe quand même. ?>
    <form method="post" action="?p=structure_note_ajouter" class="form hist-note-form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="structure_id" value="<?= $sid ?>">
        <div class="add-row hist-note-row">
            <?php // Pré-remplie à aujourd'hui : le cas courant reste « je note ce
                  // qui vient de se passer ». Le champ est là pour l'autre cas —
                  // consigner après coup un appel de la semaine dernière — sans
                  // quoi la date du jour serait figée dans l'historique à tort. ?>
            <input type="date" name="date" class="hist-note-date" value="<?= e(date('Y-m-d')) ?>" aria-label="Date de la note">
            <textarea name="contenu" rows="1" class="hist-note-texte" placeholder="Ce qui s'est passé, ce qu'il reste à faire…" aria-label="Note" required></textarea>
            <label class="check hist-note-contact"><input type="checkbox" name="est_contact" value="1"> Prise de contact</label>
            <button type="submit"><?= icon('message-square') ?> Ajouter</button>
        </div>
    </form>
    <?php endif; ?>
    <div class="mt-16">
        <?php if ($notes): ?>
            <?php // Cinq entrées d'emblée : la carte est pleine largeur et chacune
                  // tient sur une ligne, cinq se lisent donc d'un coup d'œil sans
                  // allonger la page. Le reste attend derrière « Voir les … ». ?>
            <?php $notesRecentes = array_slice($notes, 0, 5); $notesReste = array_slice($notes, 5); ?>
            <?php $histoModifiable = $peutEcrireBooking; $histoStructureId = $sid; ?>
            <?php $histoEntrees = $notesRecentes; require __DIR__ . '/_historique.php'; ?>
            <?php if ($notesReste): ?>
                <div class="hist-reste" hidden>
                    <?php $histoEntrees = $notesReste; require __DIR__ . '/_historique.php'; ?>
                </div>
                <div class="hist-voir-plus"><button type="button" class="btn ghost btn-sm hist-voir-plus-btn"><?= icon('chevron-down') ?> Voir les <?= count($notesReste) ?> précédente<?= count($notesReste) > 1 ? 's' : '' ?></button></div>
            <?php endif; ?>
        <?php else: ?>
            <p class="muted small">Aucune entrée pour l'instant.</p>
        <?php endif; ?>
    </div>
    <script nonce="<?= e(csp_nonce()) ?>">
    (function () {
        var btn = document.querySelector('.hist-voir-plus-btn');
        var reste = document.querySelector('.hist-reste');
        if (!btn || !reste) return;
        btn.addEventListener('click', function () {
            reste.hidden = false;
            btn.hidden = true;
        });
    })();
    </script>
</div>

<?php endif; ?>
</div></div>
<?php require __DIR__ . '/_region_select_js.php'; ?>
