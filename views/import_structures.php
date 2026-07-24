<?php /** @var string $etape */ /** @var ?string $err */
/** @var array $entete */ /** @var array $conflits */ /** @var int $nNouvelles */
/** @var array $resume */ /** @var int $nExclusion */
/** @var array $groupes */ /** @var array $groupesConfirmes */ /** @var array $mappingSuggere */
?>
<?php require __DIR__ . '/_param_tabs.php'; ?>
<h2 class="mt-0">Importer un carnet d'adresses</h2>

<?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>

<?php if ($etape === 'upload'): ?>
<div class="card form">
    <p class="muted small">
        Fichier CSV (export Excel/LibreOffice/Google Sheets). Les colonnes peuvent porter
        n'importe quel nom : l'écran suivant permet de les faire correspondre aux champs connus.
    </p>
    <form method="post" action="?p=import_structures" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="etape" value="mapper">
        <label>Fichier CSV <input type="file" name="fichier" accept=".csv,text/csv" required></label>
        <div class="form-actions">
            <button type="submit"><?= icon('save') ?> Continuer</button>
        </div>
    </form>
</div>

<?php elseif ($etape === 'mapping'): ?>
<div class="card form">
    <p class="muted small">Associez chaque champ connu à la colonne correspondante du fichier (laissez « — » si absente). Seul « Nom de la structure » est obligatoire.</p>
    <?php if ($mappingSuggere): ?><p class="muted small"><?= icon('wand') ?> <?= count($mappingSuggere) ?> champ(s) pré-remplis d'après le dernier import (colonnes de même nom). Vérifiez et ajustez si besoin.</p><?php endif; ?>
    <form method="post" action="?p=import_structures">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="etape" value="analyser">
        <input type="hidden" name="depuis_session" value="1">
        <div class="grid3">
            <?php foreach (STRUCTURE_IMPORT_CHAMPS as $champ => $label): ?>
                <?php $selIdx = $mappingSuggere[$champ] ?? null; ?>
                <label<?= $selIdx !== null ? ' class="mapping-memo"' : '' ?>><?= e($label) ?>
                    <select name="mapping[<?= $champ ?>]">
                        <option value="">—</option>
                        <?php foreach ($entete as $i => $col): ?>
                            <?php $estNomAuto = $selIdx === null && $champ === 'nom' && mb_strtolower(trim((string) $col), 'UTF-8') === 'nom'; ?>
                            <option value="<?= $i ?>" <?= ($selIdx === $i || $estNomAuto) ? 'selected' : '' ?>><?= e((string) $col) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endforeach; ?>
        </div>
        <div class="form-actions">
            <button type="submit"><?= icon('save') ?> Analyser</button>
        </div>
    </form>
</div>

<?php elseif ($etape === 'grouper'): ?>
<div class="card form">
    <p class="muted small">Certaines lignes semblent décrire des <strong>salles ou festivals rattachés à un même organisateur</strong>
       (colonne « Organisateur », ou nom entre parenthèses). Cochez les regroupements à appliquer : l'organisateur
       devient — ou reste — une structure, et ses salles/festivals lui sont rattachés (leurs contacts et étiquettes
       vont à l'organisateur). Décochez pour importer ces lignes comme des structures séparées.</p>
    <form method="post" action="?p=import_structures">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="etape" value="grouper">
        <div class="form-actions mb-16">
            <button type="button" class="btn ghost btn-sm" id="btn-tout-cocher">Tout cocher</button>
            <button type="button" class="btn ghost btn-sm" id="btn-tout-decocher">Tout décocher</button>
        </div>
        <?php foreach ($groupes as $g): ?>
            <label class="card mt-16 groupe-item">
                <div class="linked-add">
                    <input type="checkbox" name="groupes[]" value="<?= e($g['cle']) ?>" checked>
                    <span>
                        <strong><?= e($g['organisateur']) ?></strong>
                        <span class="badge muted-badge"><?= $g['existe'] ? 'structure existante' : 'nouvelle structure' ?></span>
                        <?php if ($g['self_index'] === null): ?><span class="muted small"> — organisateur non listé, créé automatiquement</span><?php endif; ?>
                    </span>
                </div>
                <div class="muted small mt-10">
                    <?= count($g['lieux']) ?> salle(s)/festival(s) rattachée(s) :
                    <?= e(implode(', ', array_map(fn ($l) => $l['nom'], $g['lieux']))) ?>
                </div>
            </label>
        <?php endforeach; ?>
        <div class="form-actions mt-16">
            <button type="submit"><?= icon('save') ?> Continuer</button>
        </div>
    </form>
    <script>
    (function () {
        var boxes = document.querySelectorAll('input[name="groupes[]"]');
        var c = document.getElementById('btn-tout-cocher');
        var d = document.getElementById('btn-tout-decocher');
        if (c) c.addEventListener('click', function () { boxes.forEach(function (b) { b.checked = true; }); });
        if (d) d.addEventListener('click', function () { boxes.forEach(function (b) { b.checked = false; }); });
    })();
    </script>
</div>

<?php elseif ($etape === 'resoudre'): ?>
<div class="card form">
    <p><strong><?= $nNouvelles ?></strong> nouvelle(s) structure(s) seront ajoutée(s) directement.
       <strong><?= count($conflits) ?></strong> correspondance(s) trouvée(s) avec l'existant.</p>
    <?php if ($conflits): ?>
    <p class="muted small">Choisissez pour toutes les correspondances à la fois, ou décidez une par une ci-dessous.</p>
    <div class="form-actions mb-16">
        <button type="button" class="btn ghost btn-sm" id="btn-tout-ignorer">Tout ignorer</button>
        <button type="button" class="btn ghost btn-sm" id="btn-tout-maj">Tout mettre à jour</button>
        <span class="muted small">Par défaut : <strong id="decision-defaut-label">Ignorer</strong></span>
    </div>
    <?php endif; ?>
    <form method="post" action="?p=import_structures" id="form-resoudre">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="etape" value="appliquer">
        <input type="hidden" name="depuis_session" value="1">
        <input type="hidden" name="decision_globale" id="decision-globale" value="ignorer">
        <?php foreach ($groupesConfirmes as $gc): ?><input type="hidden" name="groupes[]" value="<?= e($gc) ?>"><?php endforeach; ?>
        <?php foreach ($conflits as $c): $d = $c['donnees']; $ex = $c['structure_existante']; ?>
            <div class="card mt-16 conflit-row">
                <h3 class="sub no-mt"><?= e($d['nom']) ?></h3>
                <div class="table-scroll">
                <table class="list">
                    <thead><tr><th></th><th>Actuel</th><th>Importé</th></tr></thead>
                    <tbody>
                        <tr><td class="muted small">Catégorie</td><td><?= e($ex['categorie']) ?></td><td><?= e($d['categorie']) ?></td></tr>
                        <tr><td class="muted small">Adresse</td><td><?= e(trim($ex['adresse_rue'] . ' ' . $ex['adresse_npa'] . ' ' . $ex['adresse_localite'])) ?: '—' ?></td><td><?= e(trim($d['adresse_rue'] . ' ' . $d['adresse_npa'] . ' ' . $d['adresse_localite'])) ?: '—' ?></td></tr>
                        <tr><td class="muted small">Région</td><td><?= e($ex['region']) ?: '—' ?></td><td><?= e($d['region']) ?: '—' ?></td></tr>
                        <tr><td class="muted small">Site web</td><td><?= e($ex['site_web']) ?: '—' ?></td><td><?= e($d['site_web']) ?: '—' ?></td></tr>
                    </tbody>
                </table>
                </div>
                <label class="check"><input type="radio" name="decision[<?= $c['index'] ?>]" value="ignorer" checked> Ignorer (garder l'existant)</label>
                <label class="check"><input type="radio" name="decision[<?= $c['index'] ?>]" value="maj"> Mettre à jour avec les valeurs importées</label>
            </div>
        <?php endforeach; ?>
        <div class="form-actions mt-16">
            <button type="submit"><?= icon('save') ?> Importer</button>
        </div>
    </form>
    <script>
    (function () {
        var form = document.getElementById('form-resoudre');
        var globale = document.getElementById('decision-globale');
        var lbl = document.getElementById('decision-defaut-label');
        function refreshLabel() {
            if (lbl) lbl.textContent = globale.value === 'maj' ? 'Mettre à jour' : 'Ignorer';
        }
        function toutRegler(valeur) {
            globale.value = valeur;
            form.querySelectorAll('.conflit-row input[type=radio][value="' + valeur + '"]').forEach(function (r) { r.checked = true; });
            refreshLabel();
        }
        var btnIgnorer = document.getElementById('btn-tout-ignorer');
        var btnMaj = document.getElementById('btn-tout-maj');
        if (btnIgnorer) btnIgnorer.addEventListener('click', function () { toutRegler('ignorer'); });
        if (btnMaj) btnMaj.addEventListener('click', function () { toutRegler('maj'); });
        refreshLabel();
        // Au submit, on ne transmet QUE les lignes qui diffèrent du défaut global
        // (les radios conformes au défaut sont désactivées → non postées). Sans
        // ça, un import de plusieurs milliers de conflits dépasserait PHP
        // max_input_vars (~1000) et la plupart des décisions seraient perdues.
        form.addEventListener('submit', function () {
            var def = globale.value;
            form.querySelectorAll('.conflit-row').forEach(function (row) {
                var checked = row.querySelector('input[type=radio]:checked');
                if (checked && checked.value === def) {
                    row.querySelectorAll('input[type=radio]').forEach(function (r) { r.disabled = true; });
                }
            });
        });
    })();
    </script>
</div>

<?php elseif ($etape === 'resume'): ?>
<div class="card">
    <p class="ok flash"><strong><?= (int) $resume['nouvelles'] ?></strong> nouvelle(s) structure(s) ajoutée(s),
       <strong><?= (int) $resume['mises_a_jour'] ?></strong> mise(s) à jour, <strong><?= (int) $resume['ignorees'] ?></strong> ignorée(s)<?php if ((int) ($resume['lieux'] ?? 0) > 0): ?>,
       <strong><?= (int) $resume['lieux'] ?></strong> salle(s)/festival(s) rattachée(s) à leur organisateur<?php endif; ?><?php if ((int) ($resume['sous_categories'] ?? 0) > 0): ?>,
       <strong><?= (int) $resume['sous_categories'] ?></strong> sous-catégorie(s) ajoutée(s) à la taxonomie<?php endif; ?>.</p>
    <a class="btn ghost" href="?p=structures">Voir les structures</a>
    <a class="btn ghost" href="?p=import_structures">Importer un autre fichier</a>
</div>

<?php elseif ($etape === 'exclusion_ok'): ?>
<div class="card">
    <p class="ok flash"><strong><?= (int) $nExclusion ?></strong> adresse(s) ajoutée(s) à la liste d'exclusion.</p>
    <a class="btn ghost" href="?p=import_structures">Retour à l'import</a>
</div>
<?php endif; ?>
