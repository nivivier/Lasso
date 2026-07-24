<?php /** @var string $etape */ /** @var ?string $err */
/** @var array $entete */ /** @var array $conflits */ /** @var int $nNouvelles */ /** @var int $nFusion */
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
    <p><strong><?= $nNouvelles ?></strong> nouvelle(s) structure(s) ajoutée(s)<?php if ($nFusion > 0): ?>,
       <strong><?= $nFusion ?></strong> fiche(s) existante(s) fusionnée(s) automatiquement (champs vides complétés, sans conflit)<?php endif; ?>.</p>
    <?php if ($conflits): ?>
    <p class="muted small"><strong><?= count($conflits) ?></strong> fiche(s) ont des champs remplis <strong>des deux côtés</strong> avec des valeurs différentes. Pour chaque champ, cochez « prendre l'importé », ou laissez décoché pour conserver la valeur actuelle.</p>
    <div class="form-actions mb-16">
        <button type="button" class="btn ghost btn-sm" id="btn-tout-actuel">Tout garder l'actuel</button>
        <button type="button" class="btn ghost btn-sm" id="btn-tout-importe">Tout prendre l'importé</button>
    </div>
    <?php else: ?>
    <p class="muted small">Aucun conflit à trancher : l'import peut être appliqué directement.</p>
    <?php endif; ?>
    <form method="post" action="?p=import_structures" id="form-resoudre">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="etape" value="appliquer">
        <input type="hidden" name="depuis_session" value="1">
        <?php foreach ($groupesConfirmes as $gc): ?><input type="hidden" name="groupes[]" value="<?= e($gc) ?>"><?php endforeach; ?>
        <?php foreach ($conflits as $c): $i = (int) $c['index']; $titre = (string) ($c['structure_existante']['nom'] ?? $c['donnees']['nom']); ?>
            <div class="card mt-16 conflit-row">
                <h3 class="sub no-mt"><?= e($titre) ?></h3>
                <div class="table-scroll">
                <table class="list">
                    <thead><tr><th>Champ</th><th>Actuel (conservé)</th><th>Importé</th><th class="nowrap">Prendre l'importé</th></tr></thead>
                    <tbody>
                        <?php foreach ($c['conflits'] as $col => $info): ?>
                        <tr>
                            <td class="muted small"><?= e($info['label']) ?></td>
                            <td><?= e($info['actuel']) ?></td>
                            <td><?= e($info['importe']) ?></td>
                            <td style="text-align:center"><input type="checkbox" class="prendre-box" name="prendre[<?= $i ?>][<?= e((string) $col) ?>]" value="1" aria-label="Prendre la valeur importée pour <?= e($info['label']) ?>"></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        <?php endforeach; ?>
        <div class="form-actions mt-16">
            <button type="submit"><?= icon('save') ?> Importer</button>
        </div>
    </form>
    <script>
    (function () {
        var form = document.getElementById('form-resoudre');
        if (!form) { return; }
        var boxes = form.querySelectorAll('.prendre-box');
        var a = document.getElementById('btn-tout-actuel');
        var b = document.getElementById('btn-tout-importe');
        if (a) a.addEventListener('click', function () { boxes.forEach(function (x) { x.checked = false; }); });
        if (b) b.addEventListener('click', function () { boxes.forEach(function (x) { x.checked = true; }); });
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
