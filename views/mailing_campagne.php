<?php /** @var array $tags */ /** @var array $regions */ /** @var array $grandesRegions */ /** @var array $villes */
/** @var array $typesLieu */ /** @var array $categoriesPourSelect */ /** @var array $ciblages */ /** @var array $modeles */
/** @var array $criteres */ /** @var ?array $apercu */ /** @var array $structuresApercu */ /** @var int $totalStructures */
/** @var string $testEmailDefaut */ /** @var ?string $msg */

// Résumé lisible du ciblage actif (badges de l'aperçu + rappel dans la campagne).
$resumeCiblage = [];
if ($criteres['categorie_id']) {
    $noms = [];
    foreach ($categoriesPourSelect as $c) {
        if (in_array((int) $c['id'], $criteres['categorie_id'], true)) { $noms[] = $c['nom']; }
    }
    if ($noms) { $resumeCiblage[] = implode(', ', $noms); }
}
if ($criteres['tag_id']) {
    $noms = [];
    foreach ($tags as $t) {
        if (in_array((int) $t['id'], $criteres['tag_id'], true)) { $noms[] = $t['nom']; }
    }
    if ($noms) { $resumeCiblage[] = 'Étiquette : ' . implode(', ', $noms); }
}
foreach (['pays' => 'Pays', 'grande_region' => 'Région', 'departement_canton' => 'Dépt/canton', 'ville' => 'Ville', 'type_lieu' => 'Type de lieu'] as $k => $lib) {
    if ($criteres[$k]) { $resumeCiblage[] = $lib . ' : ' . implode(', ', $criteres[$k]); }
}
if ($criteres['mois_evenement_debut'] !== '' && $criteres['mois_evenement_fin'] !== '') {
    $resumeCiblage[] = 'Événements ' . mois_nom((int) $criteres['mois_evenement_debut']) . '–' . mois_nom((int) $criteres['mois_evenement_fin']);
}
if ($criteres['mois_debut'] !== '' && $criteres['mois_fin'] !== '') {
    $resumeCiblage[] = 'Préparé ' . mois_nom((int) $criteres['mois_debut']) . '–' . mois_nom((int) $criteres['mois_fin']);
}
if ($criteres['contact_jamais']) { $resumeCiblage[] = 'Jamais contactées'; }
elseif ($criteres['contact_avant'] !== '') { $resumeCiblage[] = 'Pas contactées depuis le ' . date('d.m.Y', strtotime($criteres['contact_avant'])); }

// Filtres de ciblage à cases à cocher (mêmes composants que ?p=structures —
// filtre_colonne_html()/lib/helpers.php), à la place des anciens <select> à
// valeur unique. $autresFiltres reporte, pour chaque panneau, TOUS les autres
// critères actuellement actifs (y compris les champs scalaires du crit-form
// ci-dessous : sans session ici — contrairement à filtre_coche()/
// filtre_persistant(), voir mailing_criteres_depuis() — un critère absent de
// l'URL soumise par un panneau serait perdu). 'previsualiser' => '1' force
// l'aperçu à se rafraîchir dès qu'on coche une case, sans clic supplémentaire.
$categorieLabels = [];
foreach ($categoriesPourSelect as $cat) { $categorieLabels[(int) $cat['id']] = str_repeat("\u{00A0}\u{00A0}", $cat['profondeur']) . $cat['nom']; }
$tagLabels = [];
foreach ($tags as $t) { $tagLabels[(int) $t['id']] = $t['nom']; }
$typeLieuLabels = array_combine($typesLieu, $typesLieu);
$paysLabels = [];
foreach (array_unique(array_merge($criteres['pays'], array_column(pays_liste(), 'nom'))) as $nom) { $paysLabels[$nom] = $nom; }
$grandeRegionLabels = [];
foreach ($grandesRegions as $regionsDuPays) {
    foreach ($regionsDuPays as $r) { $grandeRegionLabels[$r] = $r; }
}
$departementCantonLabels = [];
foreach (array_unique(array_merge($criteres['departement_canton'], $regions)) as $r) { $departementCantonLabels[$r] = $r; }
$villeLabels = [];
foreach (array_unique(array_merge($criteres['ville'], $villes)) as $v) { $villeLabels[$v] = $v; }

$tousFiltresCiblage = [
    'categorie_id' => $criteres['categorie_id'], 'tag_id' => $criteres['tag_id'], 'pays' => $criteres['pays'],
    'grande_region' => $criteres['grande_region'], 'departement_canton' => $criteres['departement_canton'],
    'ville' => $criteres['ville'], 'type_lieu' => $criteres['type_lieu'],
    'mois_debut' => $criteres['mois_debut'], 'mois_fin' => $criteres['mois_fin'],
    'mois_evenement_debut' => $criteres['mois_evenement_debut'], 'mois_evenement_fin' => $criteres['mois_evenement_fin'],
    'contact_avant' => $criteres['contact_avant'], 'contact_jamais' => $criteres['contact_jamais'] ? '1' : '',
    'previsualiser' => '1',
];
$autresFiltresCiblage = autres_filtres_fn($tousFiltresCiblage);
// Comme $autresFiltresCiblage, mais pour un panneau qui pilote PLUSIEURS
// champs à la fois (Réalisation/Préparation/Contacté ci-dessous) : exclut
// tous les champs de $cles d'un coup (autres_filtres_fn() n'en exclut qu'un).
$sansCles = fn (array $cles): array => array_filter(array_diff_key($tousFiltresCiblage, array_fill_keys($cles, true)));

// Réalisation/Préparation/Contacté : même enveloppe .col-filter que les
// filtres à cases à cocher ci-dessus (filtre_colonne_form_html()), mais au
// contenu libre — deux <select> « De »/« à » pour une plage de mois, ou un
// <input type="date"> + une case à cocher pour le contact.
$moisSelect = function (string $nom, $valeur): string {
    $h = '<select name="' . e($nom) . '"><option value="">—</option>';
    for ($m = 1; $m <= 12; $m++) {
        $h .= '<option value="' . $m . '"' . ((string) $valeur === (string) $m ? ' selected' : '') . '>' . e(mois_nom($m)) . '</option>';
    }
    return $h . '</select>';
};
$realisationContenu = '<label>De ' . $moisSelect('mois_evenement_debut', $criteres['mois_evenement_debut']) . '</label>'
    . '<label>à ' . $moisSelect('mois_evenement_fin', $criteres['mois_evenement_fin']) . '</label>';
$preparationContenu = '<label>De ' . $moisSelect('mois_debut', $criteres['mois_debut']) . '</label>'
    . '<label>à ' . $moisSelect('mois_fin', $criteres['mois_fin']) . '</label>';
$contacteContenu = '<label class="col-filter-champ">Pas contactées depuis le <input type="date" name="contact_avant" value="' . e($criteres['contact_avant']) . '"></label>'
    . '<label class="check"><input type="checkbox" name="contact_jamais" value="1"' . ($criteres['contact_jamais'] ? ' checked' : '') . '> Jamais contactées</label>';

// Valeurs actives des 7 filtres à cases à cocher, une pastille par valeur
// avec sa propre croix de retrait (filtre_colonne_actifs_html(), même
// composant que les en-têtes de colonne de ?p=structures/?p=evenements_liste)
// — affichée sous la rangée de filtres plutôt que dans l'en-tête d'une
// colonne, cette page n'ayant pas de tableau à qui l'accrocher.
$actifsCiblageHtml = filtre_colonne_actifs_html('mailing_campagne', 'categorie_id', $categorieLabels, $criteres['categorie_id'], $autresFiltresCiblage('categorie_id'))
    . filtre_colonne_actifs_html('mailing_campagne', 'tag_id', $tagLabels, $criteres['tag_id'], $autresFiltresCiblage('tag_id'))
    . filtre_colonne_actifs_html('mailing_campagne', 'type_lieu', $typeLieuLabels, $criteres['type_lieu'], $autresFiltresCiblage('type_lieu'))
    . filtre_colonne_actifs_html('mailing_campagne', 'pays', $paysLabels, $criteres['pays'], $autresFiltresCiblage('pays'))
    . filtre_colonne_actifs_html('mailing_campagne', 'grande_region', $grandeRegionLabels, $criteres['grande_region'], $autresFiltresCiblage('grande_region'))
    . filtre_colonne_actifs_html('mailing_campagne', 'departement_canton', $departementCantonLabels, $criteres['departement_canton'], $autresFiltresCiblage('departement_canton'))
    . filtre_colonne_actifs_html('mailing_campagne', 'ville', $villeLabels, $criteres['ville'], $autresFiltresCiblage('ville'));

// Même pastille (.col-th-actif) pour Réalisation/Préparation/Contacté, qui ne
// sont pas des filtres à cases à cocher (pas de $options/$actives à donner à
// filtre_colonne_actifs_html()) — une seule pastille par groupe (pas par
// champ élémentaire : « De »/« à » forment une seule plage, jamais retirés
// séparément), $sansCles() efface les deux/trois champs du groupe d'un coup.
$pilleGroupe = function (string $label, array $cles) use ($sansCles): string {
    $qs = ['p' => 'mailing_campagne'] + $sansCles($cles);
    return '<span class="col-th-actif-list"><span class="col-th-actif">' . e($label)
        . '<a href="?' . e(http_build_query($qs)) . '" title="Retirer « ' . e($label) . ' »">' . icon('x') . '</a></span></span>';
};
if ($criteres['mois_evenement_debut'] !== '' && $criteres['mois_evenement_fin'] !== '') {
    $actifsCiblageHtml .= $pilleGroupe('Réalisation : ' . mois_nom((int) $criteres['mois_evenement_debut']) . '–' . mois_nom((int) $criteres['mois_evenement_fin']), ['mois_evenement_debut', 'mois_evenement_fin']);
}
if ($criteres['mois_debut'] !== '' && $criteres['mois_fin'] !== '') {
    $actifsCiblageHtml .= $pilleGroupe('Préparation : ' . mois_nom((int) $criteres['mois_debut']) . '–' . mois_nom((int) $criteres['mois_fin']), ['mois_debut', 'mois_fin']);
}
if ($criteres['contact_jamais']) {
    $actifsCiblageHtml .= $pilleGroupe('Jamais contactées', ['contact_avant', 'contact_jamais']);
} elseif ($criteres['contact_avant'] !== '') {
    $actifsCiblageHtml .= $pilleGroupe('Pas contactées depuis le ' . date('d.m.Y', strtotime($criteres['contact_avant'])), ['contact_avant', 'contact_jamais']);
}

// Champs cachés « critères » d'un formulaire (ciblage_save / campagne-form) —
// mailing_criteres_vers_url() mélange scalaires et tableaux, e() seul ne
// suffit pas (voir hidden_inputs_html(), même principe).
$critHiddenInputs = function (array $criteres): string {
    $h = '';
    foreach (mailing_criteres_vers_url($criteres) as $k => $v) {
        foreach ((array) $v as $vv) {
            $h .= '<input type="hidden" name="' . e(is_array($v) ? $k . '[]' : $k) . '" value="' . e((string) $vv) . '" class="crit-hidden">';
        }
    }
    return $h;
};
?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>

<?php if ($msg === 'test_ok'): ?><p class="ok flash">E-mail de test envoyé.</p>
<?php elseif ($msg === 'test_ko'): ?><p class="err flash">Échec de l'envoi de test (vérifiez la configuration SMTP dans Paramètres → E-mails).</p>
<?php elseif ($msg === 'test_vide'): ?><p class="err flash">Renseignez l'adresse de test, le sujet et le corps avant d'envoyer un test.</p>
<?php elseif ($msg === 'vide'): ?><p class="err flash">Sujet et corps du message sont obligatoires.</p><?php endif; ?>

<div class="card">
    <div class="section-head mt-0">
        <h2 class="mt-0">Destinataires</h2>
        <form method="post" action="?p=mailing_campagne" class="linked-add ml-auto" data-sync-criteres>
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="section" value="ciblage_save">
            <?= $critHiddenInputs($criteres) ?>
            <input type="text" name="ciblage_nom" placeholder="Enregistrer ce ciblage sous… (ex. Festivals romands été)" required>
            <button type="submit" class="btn"><?= icon('save') ?></button>
        </form>
    </div>

    <?php if ($ciblages): ?>
    <div class="tags-liste">
        <span class="muted small">Ciblages enregistrés :</span>
        <?php foreach ($ciblages as $cb): ?>
            <span class="badge">
                <a href="?p=mailing_campagne&ciblage=<?= (int) $cb['id'] ?>"><?= e($cb['nom']) ?></a>
                <form method="post" action="?p=mailing_campagne" class="d-inline" onsubmit="return confirm('Supprimer le ciblage « <?= e($cb['nom']) ?> » ?');">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="section" value="ciblage_delete">
                    <input type="hidden" name="id" value="<?= (int) $cb['id'] ?>">
                    <button type="submit" class="btn-tag-x" title="Supprimer ce ciblage" aria-label="Supprimer ce ciblage">×</button>
                </form>
            </span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="toolbar">
        <div class="filters carte-filters">
            <span class="col-th">Catégorie <?= filtre_colonne_html('mailing_campagne', 'categorie_id', $categorieLabels, $criteres['categorie_id'], $autresFiltresCiblage('categorie_id')) ?></span>
            <?php if ($tags): ?><span class="col-th">Étiquette <?= filtre_colonne_html('mailing_campagne', 'tag_id', $tagLabels, $criteres['tag_id'], $autresFiltresCiblage('tag_id')) ?></span><?php endif; ?>
            <?php if ($typesLieu): ?>
            <span class="col-th">Type de lieu <?= filtre_colonne_html('mailing_campagne', 'type_lieu', $typeLieuLabels, $criteres['type_lieu'], $autresFiltresCiblage('type_lieu')) ?></span>
            <?php endif; ?>
            <span class="col-th">Pays <?= filtre_colonne_html('mailing_campagne', 'pays', $paysLabels, $criteres['pays'], $autresFiltresCiblage('pays')) ?></span>
            <span class="col-th">Région <?= filtre_colonne_html('mailing_campagne', 'grande_region', $grandeRegionLabels, $criteres['grande_region'], $autresFiltresCiblage('grande_region')) ?></span>
            <span class="col-th">Département / canton <?= filtre_colonne_html('mailing_campagne', 'departement_canton', $departementCantonLabels, $criteres['departement_canton'], $autresFiltresCiblage('departement_canton')) ?></span>
            <span class="col-th">Ville <?= filtre_colonne_html('mailing_campagne', 'ville', $villeLabels, $criteres['ville'], $autresFiltresCiblage('ville')) ?></span>
            <span class="col-th">Réalisation <?= filtre_colonne_form_html('mailing_campagne', $sansCles(['mois_evenement_debut', 'mois_evenement_fin']), $realisationContenu) ?></span>
            <span class="col-th">Préparation <?= filtre_colonne_form_html('mailing_campagne', $sansCles(['mois_debut', 'mois_fin']), $preparationContenu) ?></span>
            <span class="col-th">Contacté <?= filtre_colonne_form_html('mailing_campagne', $sansCles(['contact_avant', 'contact_jamais']), $contacteContenu) ?></span>
        </div>
    </div>
    <?php if ($actifsCiblageHtml !== ''): ?>
    <div class="filtres-ciblage-actifs"><?= $actifsCiblageHtml ?></div>
    <?php endif; ?>

    <?php
    // Ancre invisible pour syncCriteres() (voir le <script> en bas de page) :
    // au moment de soumettre le formulaire d'enregistrement du ciblage ou de
    // création de la campagne, on relit l'état COURANT des filtres depuis ce
    // <form> (hidden inputs, jamais affichés — sans bouton visible depuis que
    // chaque filtre s'applique lui-même) plutôt que celui rendu au dernier
    // aperçu.
    ?>
    <form method="get" id="crit-form" hidden>
        <input type="hidden" name="p" value="mailing_campagne">
        <?= hidden_inputs_html([
            'categorie_id' => $criteres['categorie_id'], 'tag_id' => $criteres['tag_id'], 'pays' => $criteres['pays'],
            'grande_region' => $criteres['grande_region'], 'departement_canton' => $criteres['departement_canton'],
            'ville' => $criteres['ville'], 'type_lieu' => $criteres['type_lieu'],
            'mois_evenement_debut' => $criteres['mois_evenement_debut'], 'mois_evenement_fin' => $criteres['mois_evenement_fin'],
            'mois_debut' => $criteres['mois_debut'], 'mois_fin' => $criteres['mois_fin'],
            'contact_avant' => $criteres['contact_avant'], 'contact_jamais' => $criteres['contact_jamais'] ? '1' : '',
        ]) ?>
    </form>

    <?php if ($apercu !== null): ?>
        <p class="mt-16"><strong><?= count($apercu) ?></strong> destinataire(s) trouvé(s)<?= $totalStructures ? ' dans ' . $totalStructures . ' structure(s)' : '' ?>.</p>
        <?php if ($structuresApercu): ?>
        <div class="table-scroll">
        <table class="list list-wide">
            <thead><tr>
                <th>Nom</th><th>Ville</th><th>Catégorie</th><th>Prénom</th><th>E-mail</th><th>Dernier contact</th><th>Salles / festivals</th>
            </tr></thead>
            <tbody>
            <?php foreach ($structuresApercu as $d): ?>
                <tr>
                    <td>
                        <strong><a href="?p=structure&id=<?= (int) $d['id'] ?>"><?= e($d['nom']) ?></a></strong>
                        <?php if ($d['statut'] === 'contact_privilegie'): ?><span class="ico-tiny ico-pink" title="Contact privilégié"><?= icon('heart') ?></span><?php endif; ?>
                    </td>
                    <td class="small">
                        <?php $villeHtml = ville_departement_canton_html((string) $d['adresse_localite'], pays_drapeau_nom((string) $d['adresse_pays']), (string) $d['adresse_pays'], (string) $d['departement_canton']); ?>
                        <?= $villeHtml !== '' ? $villeHtml : '—' ?>
                    </td>
                    <td><?= categorie_sous_categorie_html((string) $d['categorie'], (string) $d['sous_categorie']) ?></td>
                    <td class="muted small"><?= e(implode(', ', array_keys($d['prenoms']))) ?: '—' ?></td>
                    <td class="muted small col-petit"><?= e(implode(', ', array_keys($d['emails']))) ?: '—' ?></td>
                    <td class="muted small"><?= $d['dernier_contact_le'] ? e(date('d.m.Y', strtotime($d['dernier_contact_le']))) : '—' ?></td>
                    <td class="muted small"><?= $d['lieux_noms'] ? e($d['lieux_noms']) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php if ($totalStructures > count($structuresApercu)): ?><p class="muted small">… et <?= $totalStructures - count($structuresApercu) ?> structure(s) de plus.</p><?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="card mt-22">
    <div class="section-head mt-0">
        <h2 class="mt-0">Message</h2>
        <?php if ($modeles): ?>
        <label class="inline ml-auto">Charger un modèle
            <select id="modele-select">
                <option value="">—</option>
                <?php foreach ($modeles as $m): ?>
                    <option value="<?= (int) $m['id'] ?>"><?= e($m['nom']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>
    </div>

    <?php if ($resumeCiblage): ?>
        <p class="muted small mb-8">Ciblage : <?php foreach ($resumeCiblage as $b): ?><span class="badge"><?= e($b) ?></span> <?php endforeach; ?></p>
    <?php endif; ?>

    <form method="post" action="?p=mailing_envoyer" class="form" id="campagne-form" data-sync-criteres>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <?= $critHiddenInputs($criteres) ?>
        <label>Sujet <input name="sujet" id="camp-sujet" required></label>
        <label><span>Corps <?= info_tip('Variables disponibles : {{prenom}} (contact), {{nom_structure}}.') ?></span>
            <textarea name="corps" id="camp-corps" rows="8" required placeholder="Bonjour {{prenom}},&#10;&#10;…"></textarea>
        </label>

        <div class="linked-add">
            <input type="email" name="test_email" id="camp-test-email" value="<?= e($testEmailDefaut) ?>" placeholder="Adresse pour l'e-mail de test">
            <button type="submit" name="section" value="test" formaction="?p=mailing_campagne" formnovalidate class="btn ghost"><?= icon('send') ?> Envoyer un test</button>
        </div>

        <div class="form-actions">
            <button type="submit" onclick="return confirm('Ajouter ces destinataires à la file d\'attente d\'envoi ?');"><?= icon('send') ?> Créer la campagne<?= $apercu !== null ? ' (' . count($apercu) . ' destinataires)' : '' ?></button>
            <button type="submit" name="section" value="modele_save" formaction="?p=mailing_modeles" formnovalidate class="btn ghost" id="camp-save-modele"><?= icon('save') ?> Enregistrer comme modèle</button>
        </div>
    </form>
</div>

<script>
(function () {
    // Enregistrer un ciblage / créer une campagne SANS avoir cliqué « Prévisualiser » :
    // au moment de soumettre, on recopie l'état COURANT des filtres dans les
    // champs cachés du formulaire (au lieu des critères rendus au dernier aperçu).
    var critForm = document.getElementById('crit-form');
    function syncCriteres(cible) {
        cible.querySelectorAll('.crit-hidden').forEach(function (el) { el.remove(); });
        new FormData(critForm).forEach(function (val, key) {
            if (key === 'p' || key === 'previsualiser' || val === '' || val === '0') return;
            var h = document.createElement('input');
            h.type = 'hidden'; h.name = key; h.value = val; h.className = 'crit-hidden';
            cible.appendChild(h);
        });
    }
    document.querySelectorAll('form[data-sync-criteres]').forEach(function (f) {
        f.addEventListener('submit', function () { syncCriteres(f); });
    });

    // Charger un modèle : remplit sujet + corps depuis la liste (JSON en ligne).
    var modeles = <?= json_encode(array_column($modeles, null, 'id'), JSON_UNESCAPED_UNICODE) ?>;
    var sel = document.getElementById('modele-select');
    if (sel) sel.addEventListener('change', function () {
        var m = modeles[this.value];
        if (!m) return;
        document.getElementById('camp-sujet').value = m.sujet || '';
        document.getElementById('camp-corps').value = m.corps || '';
    });
    // Enregistrer comme modèle : demande un nom, l'ajoute au POST vers ?p=mailing_modeles.
    var btn = document.getElementById('camp-save-modele');
    if (btn) btn.addEventListener('click', function (e) {
        var nom = prompt('Nom du modèle :');
        if (!nom) { e.preventDefault(); return; }
        var f = document.getElementById('campagne-form');
        var h = document.createElement('input');
        h.type = 'hidden'; h.name = 'nom'; h.value = nom;
        f.appendChild(h);
        // section=modele_save est attendu par route_mailing_modeles
        this.value = 'modele_save';
    });
})();
</script>
