<?php /** @var ?array $lieu */ /** @var ?string $err */ /** @var array $categoriesLieu */
$v = fn(string $k, $d = '') => e((string) ($lieu[$k] ?? $d));
$isEdit = !empty($lieu['id']);
$categoriesLieu = $categoriesLieu ?? lieu_categories_liste();
// <option> des catégories de lieu (taxonomie configurable), sélection courante.
$typeOptions = function () use ($lieu, $categoriesLieu): string {
    $cur = (string) ($lieu['type'] ?? '');
    $h = '';
    foreach ($categoriesLieu as $c) {
        $h .= '<option value="' . e($c) . '"' . ($cur === $c ? ' selected' : '') . '>' . e($c) . '</option>';
    }
    return $h;
};
?>
<?= lien_retour_contextuel('?p=lieux', 'Salles & festivals') ?>
<div class="page-head">
    <?php if ($isEdit): ?>
    <div class="titre-row">
        <div class="titre-read">
            <h1><?= $v('nom') ?></h1>
            <button type="button" class="btn ghost btn-sm icon-only titre-edit-btn" title="Modifier le nom" aria-label="Modifier le nom"><?= icon('pencil') ?></button>
        </div>
        <form method="post" action="?p=lieu_renommer" class="titre-edit-form" hidden>
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int) $lieu['id'] ?>">
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
    </script>
    <?php else: ?>
    <h1>Nouveau lieu</h1>
    <?php endif; ?>
    <?php if ($isEdit): ?>
    <div class="head-actions">
        <form method="post" action="?p=lieu_delete" onsubmit="return confirm('Supprimer définitivement ce lieu ?');" class="d-inline">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int) $lieu['id'] ?>">
            <button type="submit" class="btn danger icon-only" title="Supprimer" aria-label="Supprimer le lieu"><?= icon('trash') ?></button>
        </form>
    </div>
    <?php endif; ?>
</div>

<?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>
<?php if (($_GET['err'] ?? null) === 'used'): ?><p class="err flash">Suppression impossible : une structure est liée à ce lieu.</p><?php endif; ?>

<?php $structuresLiees = $structuresLiees ?? []; $avecAside = $isEdit; ?>
<?php
// Formulaire réutilisable : crayon → recherche de structure + save, pour
// (re)définir l'organisateur d'un lieu. $ancien = id de la structure liée
// actuelle (0 si aucune). La liste des structures est chargée à la demande
// (?p=structures_options), pas injectée ici — voir le script en bas de page.
$orgaEditeur = function (int $lieuId, int $ancien) {
    ob_start(); ?>
    <form method="post" action="?p=lieu_organisateur" class="orga-edit-form linked-add" hidden>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="lieu_id" value="<?= $lieuId ?>">
        <input type="hidden" name="ancien_structure_id" value="<?= $ancien ?>">
        <div class="cat-search orga-search">
            <input type="text" class="cat-search-input" placeholder="Rechercher une structure…" autocomplete="off">
            <input type="hidden" name="structure_id" class="cat-search-val" value="">
            <ul class="cat-search-list" hidden role="listbox"></ul>
        </div>
        <button type="submit" class="btn ghost btn-sm icon-only" title="Enregistrer" aria-label="Enregistrer"><?= icon('save') ?></button>
        <button type="button" class="btn ghost btn-sm icon-only orga-cancel-btn" title="Annuler" aria-label="Annuler"><?= icon('x') ?></button>
    </form>
    <?php return ob_get_clean();
};
?>
<?php if ($avecAside): ?><div class="struct-wrapper"><div class="struct-main"><?php endif; ?>

<form method="post" action="?p=lieu<?= $isEdit ? '&id=' . (int) $lieu['id'] : '' ?>" class="card form">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <?php if ($isEdit): ?>
        <input type="hidden" name="nom" value="<?= $v('nom') ?>">
        <div class="grid4">
            <label>Type
                <select name="type"><?= $typeOptions() ?></select>
            </label>
            <label>Ville <input name="ville" value="<?= $v('ville') ?>"></label>
           <div class="grid3">
            <label>Département / canton <input name="region" value="<?= $v('region') ?>"></label>
            <label>Région <?= info_tip("Grande région (Normandie, Romandie, Acadie… — se gère dans Paramètres → Pays)") ?>
                <select name="grande_region" class="region-select">
                    <option value="">— Région —</option>
                    <?= region_options_nom((string) ($lieu['pays'] ?? ''), (string) ($lieu['grande_region'] ?? '')) ?>
                </select>
            </label>
            <label>Pays
                <select name="pays" class="pays-select">
                    <option value="">—</option>
                    <?= pays_options_nom((string) ($lieu['pays'] ?? '')) ?>
                </select>
            </label>
            </div>
        </div>
    <?php else: ?>
        <div class="grid2">
            <label>Nom <input name="nom" class="input-titre" value="<?= $v('nom') ?>" required></label>
            <label>Type
                <select name="type"><?= $typeOptions() ?></select>
            </label>
        </div>
        <div class="grid4">
            <label>Ville <input name="ville" value="<?= $v('ville') ?>"></label>
            <label>Département / canton <input name="region" value="<?= $v('region') ?>"></label>
            <label>Région <?= info_tip("Grande région (Normandie, Romandie, Acadie… — se gère dans Paramètres → Pays)") ?>
                <select name="grande_region" class="region-select">
                    <option value="">— Région —</option>
                    <?= region_options_nom((string) ($lieu['pays'] ?? ''), (string) ($lieu['grande_region'] ?? '')) ?>
                </select>
            </label>
            <label>Pays
                <select name="pays" class="pays-select">
                    <option value="">—</option>
                    <?= pays_options_nom((string) ($lieu['pays'] ?? '')) ?>
                </select>
            </label>
        </div>
    <?php endif; ?>
    <div class="grid2">
    <div class="grid2">
        <label><span>Événements de... <?= info_tip("Quand le festival a lieu ou quand la saison se déroule") ?></span>
            <select name="mois_evenement_debut">
                <option value="">—</option>
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= (int) ($lieu['mois_evenement_debut'] ?? 0) === $m ? 'selected' : '' ?>><?= mois_nom($m) ?></option>
                <?php endfor; ?>
            </select>
        </label>
        <label>... à
            <select name="mois_evenement_fin">
                <option value="">—</option>
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= (int) ($lieu['mois_evenement_fin'] ?? 0) === $m ? 'selected' : '' ?>><?= mois_nom($m) ?></option>
                <?php endfor; ?>
            </select>
        </label>
    </div>
    <div class="grid2">
        <label><span>Préparé de... <?= info_tip("Quand le festival/salle travaille choisit sa programmation.") ?></span>
            <select name="mois_debut">
                <option value="">—</option>
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= (int) ($lieu['mois_debut'] ?? 0) === $m ? 'selected' : '' ?>><?= mois_nom($m) ?></option>
                <?php endfor; ?>
            </select>
        </label>
        <label>... à
            <select name="mois_fin">
                <option value="">—</option>
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= (int) ($lieu['mois_fin'] ?? 0) === $m ? 'selected' : '' ?>><?= mois_nom($m) ?></option>
                <?php endfor; ?>
            </select>
        </label>
    </div> </div>
    <div class="grid3">
        <label>Jauge de... <input name="jauge_min" type="number" min="0" value="<?= $v('jauge_min') ?>"></label>
        <label>... à <input name="jauge_max" type="number" min="0" value="<?= $v('jauge_max') ?>"></label>
        <label>Dernier concert ou diffusion <input name="dernier_concert_le" type="date" value="<?= $v('dernier_concert_le') ?>"></label>
    </div>

    <label>Notes (optionnel)
        <textarea name="notes" rows="2"><?= $v('notes') ?></textarea>
    </label>

    <div class="form-actions">
        <button type="submit"><?= icon('save') ?> Enregistrer</button>
        <a class="btn ghost" href="?p=lieux">Annuler</a>
    </div>
</form>

<?php if ($avecAside): ?>
</div>
<aside class="struct-aside">
    <?php if (!$structuresLiees): ?>
    <section class="aside-block">
        <div class="card-head-row">
            <h3 class="sub mt-0">Organisateur</h3>
            <button type="button" class="btn ghost btn-sm icon-only orga-edit-btn" title="Définir l'organisateur" aria-label="Définir l'organisateur"><?= icon('pencil') ?></button>
        </div>
        <p class="muted small orga-read">Aucun organisateur.</p>
        <?= $orgaEditeur((int) $lieu['id'], 0) ?>
    </section>
    <?php endif; ?>
    <?php foreach ($structuresLiees as $sl): $s = $sl['structure']; $ssid = (int) $s['id']; ?>
    <section class="aside-block">
        <div class="card-head-row">
            <h3 class="sub mt-0">Organisateur</h3>
            <button type="button" class="btn ghost btn-sm icon-only orga-edit-btn" title="Changer l'organisateur" aria-label="Changer l'organisateur"><?= icon('pencil') ?></button>
        </div>
        <h2 class="orga-read"><a href="<?= url_avec_retour('?p=structure&id=' . $ssid, 'lieu', (int) $lieu['id']) ?>"><?= e($s['nom']) ?></a>
            <?php if (!$s['actif']): ?><span class="badge muted-badge">inactif</span><?php endif; ?>
        </h2>
        <?= $orgaEditeur((int) $lieu['id'], $ssid) ?>
        <?php $villeHtml = ville_region_html((string) $s['adresse_localite'], pays_drapeau_nom((string) $s['adresse_pays']), (string) $s['adresse_pays'], (string) $s['region']); ?>
        <?php if ($villeHtml !== ''): ?><div class="small mb-8"><?= $villeHtml ?></div><?php endif; ?>
        <div class="muted small mb-8"><?= categorie_sous_categorie_html((string) $s['categorie'], (string) $s['sous_categorie']) ?></div>
        <?php if ($s['notes'] !== ''): ?><div class="muted small mb-8"><?= e((string) $s['notes']) ?></div><?php endif; ?>
        
        <h3 class="sub">Contacts</h3>
        <?php if ($sl['contacts']): ?>
            <?php foreach ($sl['contacts'] as $c): ?>
                <div class="small <?= $c['actif'] ? '' : 'inactif' ?>">
                    <strong><?= e(trim($c['prenom'] . ' ' . $c['nom'])) ?: '—' ?></strong>
                    <?php if ($c['role']): ?><span class="muted"> — <?= e($c['role']) ?></span><?php endif; ?>
                    <?php if ($c['est_administration']): ?><span class="badge">administration</span><?php endif; ?>
                    <?php if ($c['est_booking']): ?><span class="badge">booking</span><?php endif; ?>
                    <?php if ($c['email']): ?><div class="muted"><a href="mailto:<?= e($c['email']) ?>"><?= e($c['email']) ?></a></div><?php endif; ?>
                    <?php if ($c['telephone']): ?><div class="muted"><?= e($c['telephone']) ?></div><?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p class="muted small">Aucun contact.</p>
        <?php endif; ?>

        <?php if ($sl['autres_lieux']): ?>
        <h3 class="sub">Autres salles &amp; festivals</h3>
        <?php foreach ($sl['autres_lieux'] as $al): ?>
            <div class="small">
                <a href="<?= url_avec_retour('?p=lieu&id=' . (int) $al['id'], 'structure', $ssid) ?>"><?= e($al['nom']) ?></a>
                <span class="muted"> — <?= e((string) $al['type']) ?><?php if ($al['ville']): ?>, <?= e($al['ville']) ?><?php endif; ?></span>
            </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <h3 class="sub">Historique</h3>
        <?php if ($sl['notes']): ?>
            <div class="notes-flux">
                <?php foreach ($sl['notes'] as $n): ?>
                    <div class="note-item">
                        <div class="muted small">
                            <?= e(date('d.m.Y', strtotime($n['cree_le']))) ?>
                            <?php if ($n['est_contact']): ?><span class="badge">contact</span><?php endif; ?>
                        </div>
                        <div class="small"><?= nl2br(e($n['contenu'])) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="muted small">Aucune note.</p>
        <?php endif; ?>
    </section>
    <?php endforeach; ?>
</aside>
</div>
<script>
(function () {
    // Chargement paresseux, partagé, des structures (une seule requête même
    // avec plusieurs organisateurs affichés).
    var optionsPromise = null;
    function chargerOptions() {
        if (!optionsPromise) {
            optionsPromise = fetch('?p=structures_options', { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .catch(function () { return []; });
        }
        return optionsPromise;
    }
    document.querySelectorAll('.orga-edit-btn').forEach(function (btn) {
        var section = btn.closest('.aside-block');
        var read = section.querySelector('.orga-read');
        var form = section.querySelector('.orga-edit-form');
        if (!form) return;
        var search = form.querySelector('.orga-search');
        var list = search ? search.querySelector('.cat-search-list') : null;
        var inited = false;
        function ensureInit() {
            if (inited || !list) return Promise.resolve();
            return chargerOptions().then(function (opts) {
                var frag = document.createDocumentFragment();
                opts.forEach(function (o) {
                    var li = document.createElement('li');
                    li.dataset.val = o.id;
                    li.textContent = o.nom;
                    frag.appendChild(li);
                });
                list.appendChild(frag);
                if (window.lassoInitCatSearch) lassoInitCatSearch(search, { clearHiddenOnInput: true });
                inited = true;
            });
        }
        btn.addEventListener('click', function () {
            if (form.hidden) {
                ensureInit().then(function () {
                    form.hidden = false;
                    if (read) read.hidden = true;
                    var i = form.querySelector('.cat-search-input'); if (i) i.focus();
                });
            } else {
                form.hidden = true;
                if (read) read.hidden = false;
            }
        });
        var cancel = form.querySelector('.orga-cancel-btn');
        if (cancel) cancel.addEventListener('click', function () {
            form.hidden = true;
            if (read) read.hidden = false;
        });
        form.addEventListener('submit', function (e) {
            if (!form.querySelector('.cat-search-val').value) {
                e.preventDefault();
                alert('Choisissez une structure dans la liste.');
            }
        });
    });
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/_region_select_js.php'; ?>
