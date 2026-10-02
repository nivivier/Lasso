<?php /** @var ?array $spectacle */ /** @var array $axes */ /** @var ?string $err */ /** @var array $map */
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
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>
<?php // Contextuel : un spectacle est une catégorie de la recherche unifiée, donc
      // on doit pouvoir y revenir. Sans ?depuis, retombe sur la liste comme avant. ?>
<?= lien_retour_contextuel('?p=spectacles', evenements_terme_spectacle()) ?>
<div class="page-head">
    <h1><?= $isEdit ? 'Modifier le ' . e($termeSingulier) : 'Nouveau ' . e($termeSingulier) ?></h1>
    <?php // Cette page EST un formulaire : « Enregistrer » et « Annuler » vivent
          // dans l'en-tête, là où les cartes mettent leur crayon. Sans droit
          // d'écriture le formulaire n'est pas rendu, donc rien ici non plus. ?>
    <?php if (peut_ecrire('evenements')): ?>
    <?= entete_form_actions_html('spectacle-form', '?p=spectacles') ?>
    <?php endif; ?>
</div>

<?php if (!peut_ecrire('evenements')): ?>
<p class="err">Vous n'avez pas les droits d'écriture nécessaires pour cette action.</p>
<?php else: ?>
<?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>

<form method="post" action="?p=spectacle<?= $isEdit ? '&id=' . (int) $spectacle['id'] : '' ?>" class="card form" id="spectacle-form" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <label>Nom <input name="nom" value="<?= $v('nom') ?>" required></label>
    <label><span>Spectacle parent (artiste) <?= info_tip(
        "Optionnel : rattacher ce spectacle sous un autre (par ex. un artiste) pour l'imbriquer dans l'arbre. "
        . "Un spectacle qui a des enfants n'est plus assignable directement à un événement."
    ) ?></span>
        <select name="parent_id"><?= $parentOptions ?></select>
    </label>
    <?php // L'axe analytique du PROJET : il descend sur toutes ses dates — les
          // prestations qu'on y ajoute, les lignes d'une facture créée depuis
          // l'une d'elles — et sur la recherche de fonds qui le finance. Il se
          // réglait date par date jusqu'à la migration 91, alors que c'est le
          // projet qui le sait. ?>
    <?php if ($axes): ?>
    <label><span>Axe analytique <?= info_tip(
        "Présélectionné pour les prestations ajoutées à une date de ce " . mb_strtolower(evenements_terme_spectacle(false))
        . ", et pour les lignes d'une facture créée depuis l'une d'elles. Modifiable au cas par cas ensuite, "
        . "sans effet rétroactif sur ce qui est déjà enregistré."
    ) ?></span>
        <select name="axe_analytique_id">
            <option value="">— Aucun —</option>
            <?php $axeChoisi = (int) ($spectacle['axe_analytique_id'] ?? 0); ?>
            <?php foreach ($axes as $ax): ?>
            <option value="<?= (int) $ax['id'] ?>"<?= (int) $ax['id'] === $axeChoisi ? ' selected' : '' ?>>
                <?= e(trim((string) ($ax['code'] ?? '')) !== '' ? $ax['code'] . ' — ' . $ax['libelle'] : (string) $ax['libelle']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </label>
    <?php endif; ?>
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

</form>
<?php endif; ?>
