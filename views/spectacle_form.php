<?php /** @var ?array $spectacle */ /** @var ?string $err */ /** @var array $map */
$v = fn (string $k, $d = '') => e((string) ($spectacle[$k] ?? $d));
$isEdit = !empty($spectacle['id']);
$termeSingulier = mb_strtolower(evenements_terme_spectacle(false));

// Options de parent (artiste) : tous les spectacles sauf soi-même et ses descendants.
$exclus = $isEdit ? array_merge([(int) $spectacle['id']], spectacle_descendants((int) $spectacle['id'], $map)) : [];
$parentActuel = plan_pid($spectacle['parent_id'] ?? null) ?: null;
$parentOptions = '<option value="">— Aucun (spectacle racine) —</option>';
foreach (plan_liste_ordonnee($map) as $r) {
    $rid = (int) $r['id'];
    if (in_array($rid, $exclus, true)) {
        continue;
    }
    $parentOptions .= '<option value="' . $rid . '"' . ($parentActuel === $rid ? ' selected' : '') . '>'
        . e(spectacle_chemin($rid, $map)) . '</option>';
}
?>
<?= lien_retour('?p=spectacles', evenements_terme_spectacle()) ?>
<div class="page-head">
    <h1><?= $isEdit ? 'Modifier le ' . e($termeSingulier) : 'Nouveau ' . e($termeSingulier) ?></h1>
</div>

<?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>

<form method="post" action="?p=spectacle<?= $isEdit ? '&id=' . (int) $spectacle['id'] : '' ?>" class="card form" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <label>Nom <input name="nom" value="<?= $v('nom') ?>" required></label>
    <label><span>Spectacle parent (artiste) <?= info_tip(
        "Optionnel : rattacher ce spectacle sous un autre (par ex. un artiste) pour l'imbriquer dans l'arbre. "
        . "Un spectacle qui a des enfants n'est plus assignable directement à un événement."
    ) ?></span>
        <select name="parent_id"><?= $parentOptions ?></select>
    </label>
    <label>Notes (optionnel)
        <textarea name="notes" rows="2"><?= $v('notes') ?></textarea>
    </label>

    <label><span>Feuille SUISA pré-remplie (PDF, optionnel) <?= info_tip('2 Mo maximum.') ?></span>
        <input type="file" name="suisa_feuille" accept="application/pdf">
    </label>
    <?php if (!empty($spectacle['suisa_feuille_fichier'])): ?>
        <p class="muted small">
            Fichier actuel : <a href="<?= e($spectacle['suisa_feuille_fichier']) ?>" target="_blank" rel="noopener">le PDF déjà déposé</a>.
        </p>
        <label class="check">
            <input type="checkbox" name="suisa_feuille_supprimer" value="1">
            Supprimer le fichier actuel
        </label>
    <?php endif; ?>

    <div class="form-actions">
        <button type="submit"><?= icon('save') ?> Enregistrer</button>
        <a class="btn ghost" href="?p=spectacles">Annuler</a>
    </div>
</form>
