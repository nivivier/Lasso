<?php /** @var array $tags */ /** @var array $regions */ /** @var array $grandesRegions */ /** @var array $villes */
/** @var array $typesLieu */ /** @var array $categoriesPourSelect */ /** @var array $ciblages */ /** @var array $modeles */
/** @var array $criteres */ /** @var ?array $apercu */ /** @var array $structuresApercu */ /** @var int $totalStructures */
/** @var string $testEmailDefaut */ /** @var ?string $msg */

// Résumé lisible du ciblage actif (badges de l'aperçu + rappel dans la campagne).
$resumeCiblage = [];
if ($criteres['categorie_id']) {
    foreach ($categoriesPourSelect as $c) {
        if ((int) $c['id'] === (int) $criteres['categorie_id']) { $resumeCiblage[] = $c['nom']; break; }
    }
}
if ($criteres['tag_id']) {
    foreach ($tags as $t) {
        if ((int) $t['id'] === (int) $criteres['tag_id']) { $resumeCiblage[] = 'Étiquette : ' . $t['nom']; break; }
    }
}
foreach (['pays' => 'Pays', 'grande_region' => 'Région', 'region' => 'Dépt/canton', 'ville' => 'Ville', 'type_lieu' => 'Type de lieu'] as $k => $lib) {
    if ($criteres[$k] !== '') { $resumeCiblage[] = $lib . ' : ' . $criteres[$k]; }
}
if ($criteres['mois_evenement_debut'] !== '' && $criteres['mois_evenement_fin'] !== '') {
    $resumeCiblage[] = 'Événements ' . mois_nom((int) $criteres['mois_evenement_debut']) . '–' . mois_nom((int) $criteres['mois_evenement_fin']);
}
if ($criteres['mois_debut'] !== '' && $criteres['mois_fin'] !== '') {
    $resumeCiblage[] = 'Préparé ' . mois_nom((int) $criteres['mois_debut']) . '–' . mois_nom((int) $criteres['mois_fin']);
}
if ($criteres['contact_jamais']) { $resumeCiblage[] = 'Jamais contactées'; }
elseif ($criteres['contact_avant'] !== '') { $resumeCiblage[] = 'Pas contactées depuis le ' . date('d.m.Y', strtotime($criteres['contact_avant'])); }
?>
<?php require __DIR__ . '/_mailing_tabs.php'; ?>

<?php if ($msg === 'test_ok'): ?><p class="ok flash">E-mail de test envoyé.</p>
<?php elseif ($msg === 'test_ko'): ?><p class="err flash">Échec de l'envoi de test (vérifiez la configuration SMTP dans Paramètres → E-mails).</p>
<?php elseif ($msg === 'test_vide'): ?><p class="err flash">Renseignez l'adresse de test, le sujet et le corps avant d'envoyer un test.</p>
<?php elseif ($msg === 'vide'): ?><p class="err flash">Sujet et corps du message sont obligatoires.</p><?php endif; ?>

<div class="card">
    <h2 class="mt-0">Destinataires <?= info_tip("Combinables : catégorie (une catégorie inclut ses sous-catégories), étiquette, pays, région, département/canton, ville, type de lieu, périodes des lieux liés (événements / programmation), dernier contact. Mêmes filtres que la liste des structures. Les structures désinscrites et la liste d'exclusion sont toujours écartées.") ?></h2>

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

    <form method="get" class="grid3" id="crit-form">
        <input type="hidden" name="p" value="mailing_campagne">
        <label>Catégorie
            <select name="categorie_id">
                <option value="0">Toutes</option>
                <?php foreach ($categoriesPourSelect as $cat): ?>
                    <option value="<?= (int) $cat['id'] ?>" <?= (int) $criteres['categorie_id'] === (int) $cat['id'] ? 'selected' : '' ?>><?= str_repeat("\u{00A0}\u{00A0}", $cat['profondeur']) ?><?= e($cat['nom']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Étiquette
            <select name="tag_id">
                <option value="0">Toutes</option>
                <?php foreach ($tags as $t): ?>
                    <option value="<?= (int) $t['id'] ?>" <?= (int) $criteres['tag_id'] === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['nom']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Type de lieu <?= info_tip("Structures ayant au moins un lieu lié de ce type (Festival, Salle de concert, Saison culturelle…).") ?>
            <select name="type_lieu">
                <option value="">Tous</option>
                <?php foreach ($typesLieu as $tl): ?>
                    <option value="<?= e($tl) ?>" <?= $criteres['type_lieu'] === $tl ? 'selected' : '' ?>><?= e($tl) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Pays
            <select name="pays">
                <option value="">Tous</option>
                <?= pays_options_nom($criteres['pays']) ?>
            </select>
        </label>
        <label>Région <?= info_tip("Grande région (Normandie, Romandie, Acadie… — se gère dans Paramètres → Pays)") ?>
            <select name="grande_region">
                <option value="">Toutes</option>
                <?php foreach ($grandesRegions as $paysNom => $regions): ?>
                    <optgroup label="<?= e($paysNom) ?>">
                        <?php foreach ($regions as $r): ?>
                            <option value="<?= e($r) ?>" <?= $criteres['grande_region'] === $r ? 'selected' : '' ?>><?= e($r) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Département / canton
            <select name="region">
                <option value="">Tous</option>
                <?php foreach ($regions as $r): ?>
                    <option value="<?= e($r) ?>" <?= $criteres['region'] === $r ? 'selected' : '' ?>><?= e($r) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Ville
            <select name="ville">
                <option value="">Toutes</option>
                <?php foreach ($villes as $v): ?>
                    <option value="<?= e($v) ?>" <?= $criteres['ville'] === $v ? 'selected' : '' ?>><?= e($v) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php $moisSelect = function (string $nom, $valeur): void { ?>
            <select name="<?= $nom ?>">
                <option value="">—</option>
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= (string) $valeur === (string) $m ? 'selected' : '' ?>><?= mois_nom($m) ?></option>
                <?php endfor; ?>
            </select>
        <?php }; ?>
        <label><span>Événements de… <?= info_tip("Quand le festival a lieu ou quand la saison se déroule (lieux liés à la structure).") ?></span>
            <?php $moisSelect('mois_evenement_debut', $criteres['mois_evenement_debut']); ?>
        </label>
        <label>… à
            <?php $moisSelect('mois_evenement_fin', $criteres['mois_evenement_fin']); ?>
        </label>
        <label><span>Préparé de… <?= info_tip("Quand le festival / la salle choisit sa programmation (lieux liés à la structure).") ?></span>
            <?php $moisSelect('mois_debut', $criteres['mois_debut']); ?>
        </label>
        <label>… à
            <?php $moisSelect('mois_fin', $criteres['mois_fin']); ?>
        </label>
        <label>Pas contactées depuis le <?= info_tip("Structures jamais contactées ou dont le dernier contact est antérieur à cette date. Ignoré si « Jamais contactées » est coché.") ?>
            <input type="date" name="contact_avant" value="<?= e($criteres['contact_avant']) ?>">
        </label>
        <label class="check"><input type="checkbox" name="contact_jamais" value="1" <?= $criteres['contact_jamais'] ? 'checked' : '' ?>> Jamais contactées</label>
        <div class="form-actions grid3-full">
            <button type="submit" name="previsualiser" value="1" class="btn ghost btn-sm">Prévisualiser</button>
        </div>
    </form>

    <form method="post" action="?p=mailing_campagne" class="linked-add mt-16" data-sync-criteres>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="section" value="ciblage_save">
        <?php foreach (mailing_criteres_vers_url($criteres) as $k => $v): ?>
            <input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>" class="crit-hidden">
        <?php endforeach; ?>
        <input type="text" name="ciblage_nom" placeholder="Enregistrer ce ciblage sous… (ex. Festivals romands été)" required>
        <button type="submit" class="btn ghost btn-sm"><?= icon('save') ?> Enregistrer le ciblage</button>
    </form>

    <?php if ($apercu !== null): ?>
        <p class="mt-16"><strong><?= count($apercu) ?></strong> destinataire(s) trouvé(s)<?= $totalStructures ? ' dans ' . $totalStructures . ' structure(s)' : '' ?>.
            <?php foreach ($resumeCiblage as $b): ?><span class="badge"><?= e($b) ?></span><?php endforeach; ?>
            <?php if (!$resumeCiblage): ?><span class="badge muted-badge">toutes les structures</span><?php endif; ?>
        </p>
        <?php if ($structuresApercu): ?>
        <div class="table-scroll">
        <table class="list list-wide">
            <thead><tr>
                <th>Nom</th><th>Ville</th><th>Catégorie</th><th>Prénom</th><th>E-mail</th><th>Dernier contact</th><th>Salles / festivals</th>
            </tr></thead>
            <tbody>
            <?php foreach ($structuresApercu as $d): ?>
                <tr class="<?= $d['actif'] ? '' : 'inactif' ?>">
                    <td>
                        <strong><a href="?p=structure&id=<?= (int) $d['id'] ?>"><?= e($d['nom']) ?></a></strong>
                        <?php if (!$d['actif']): ?><span class="badge muted-badge">inactif</span><?php endif; ?>
                    </td>
                    <td class="small">
                        <?php $villeHtml = ville_region_html((string) $d['adresse_localite'], pays_drapeau_nom((string) $d['adresse_pays']), (string) $d['adresse_pays'], (string) $d['region']); ?>
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
        <?php foreach (mailing_criteres_vers_url($criteres) as $k => $v): ?>
            <input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>" class="crit-hidden">
        <?php endforeach; ?>
        <label>Sujet <input name="sujet" id="camp-sujet" required></label>
        <label><span>Corps <?= info_tip('Variables disponibles : {{prenom}} (contact), {{nom_structure}}.') ?></span>
            <textarea name="corps" id="camp-corps" rows="8" required placeholder="Bonjour {{prenom}},&#10;&#10;…"></textarea>
        </label>

        <div class="linked-add">
            <input type="email" name="test_email" id="camp-test-email" value="<?= e($testEmailDefaut) ?>" placeholder="Adresse pour l'e-mail de test">
            <button type="submit" name="section" value="test" formaction="?p=mailing_campagne" formnovalidate class="btn ghost btn-sm"><?= icon('send') ?> Envoyer un test</button>
        </div>

        <div class="form-actions">
            <button type="submit" onclick="return confirm('Ajouter ces destinataires à la file d\'attente d\'envoi ?');"><?= icon('send') ?> Créer la campagne<?= $apercu !== null ? ' (' . count($apercu) . ' destinataires)' : '' ?></button>
            <button type="submit" name="section" value="modele_save" formaction="?p=mailing_modeles" formnovalidate class="btn ghost btn-sm" id="camp-save-modele"><?= icon('save') ?> Enregistrer comme modèle</button>
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
