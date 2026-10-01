<?php
/** @var array $campagne */ /** @var array $demandes */ /** @var array $repartition */
/** @var array $projets */ /** @var ?string $ok */
// Le suivi d'une campagne de recherche de fonds : la jauge en francs, puis un dossier par
// ligne. Chaque ligne se lit, et s'ouvre au crayon pour y noter ce qu'on vient
// d'apprendre — un délai, un dépôt, une réponse (docs/UI.md § 2d).
$id = (int) $campagne['id'];
$jour = fn ($d) => trim((string) $d) !== '' ? date('d.m.Y', strtotime((string) $d)) : '';
$peutEcrire = peut_ecrire('fonds');
$aujourdhui = date('Y-m-d');
?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>

<div class="module-content"><div class="module-content-inner">
<a class="back-link" href="?p=fonds"><?= icon('arrow-left') ?> Campagnes</a>

<?php if ($ok === 'demande'): ?><p class="ok flash">Dossier enregistré.</p><?php endif; ?>

<div class="page-head">
    <div class="page-head-title">
        <h1><?= e((string) $campagne['nom']) ?></h1>
    </div>
    <div class="head-actions">
        <?php // Le dossier partagé d'abord : il mène ailleurs, il ne modifie
              // rien (docs/UI.md § 1). Même bouton que « Formulaire de contact ». ?>
        <?= bouton_lien_externe_html((string) $campagne['drive_url'], 'Dossier partagé') ?>
        <?php if ($peutEcrire): ?>
        <a class="btn ghost" href="?p=fonds_campagne_form&id=<?= $id ?>"><?= icon('pencil') ?> <span class="lbl">Modifier</span></a>
        <?php endif; ?>
    </div>
</div>

<?php // La carte de tête répond d'un coup d'œil à la seule question qui compte
      // au milieu d'une campagne : combien manque-t-il encore. ?>
<div class="card">
    <div class="camp-jauge">
        <?= fonds_barre_html($repartition) ?>
        <ul class="camp-legende">
            <li><span class="camp-pastille camp-oui"></span><b><?= chf($repartition['obtenu']) ?></b> obtenu</li>
            <li><span class="camp-pastille camp-attente"></span><b><?= chf($repartition['attente']) ?></b> en attente</li>
            <li><span class="camp-pastille"></span><b><?= chf($repartition['aTrouver']) ?></b> à trouver</li>
            <?php // Le verdict, à droite de la légende : le projet peut-il se
                  // faire ? C'est la seule question que pose un objectif
                  // minimal, et elle se tranche sur l'argent ACQUIS. ?>
            <?php if ($repartition['minimal'] > 0): ?>
            <li class="camp-legende-fin<?= $repartition['minimalAtteint'] ? '' : ' muted' ?>">
                <span class="ico-tiny"><?= icon($repartition['minimalAtteint'] ? 'circle-check' : 'target') ?></span>
                <?= $repartition['minimalAtteint']
                    ? 'Minimum atteint'
                    : 'Minimum ' . e(chf((float) $repartition['minimal'])) ?>
            </li>
            <?php endif; ?>
        </ul>
    </div>
    <table class="kv-table mt-16">
        <tr><th>Période</th><td><?php $d = $jour($campagne['date_debut']); $f = $jour($campagne['date_fin']); ?>
            <?= $d !== '' ? e($d) : '—' ?><?= $f !== '' ? ' → ' . e($f) : '' ?></td></tr>
        <tr><th>Projet</th><td><?= $projets ? e(implode(', ', $projets)) : '<span class="muted">Aucun</span>' ?></td></tr>
        <tr><th>Objectif minimal</th><td><?= (float) $campagne['montant_minimal'] > 0
            ? chf((float) $campagne['montant_minimal'])
            : '<span class="muted">Non chiffré</span>' ?></td></tr>
        <tr><th>Objectif idéal</th><td><?= (float) $campagne['montant_ideal'] > 0
            ? chf((float) $campagne['montant_ideal'])
            : '<span class="muted">Non chiffré</span>' ?></td></tr>
    </table>
    <?php if (trim((string) $campagne['notes']) !== ''): ?>
        <p class="muted small mt-16"><?= nl2br(e((string) $campagne['notes'])) ?></p>
    <?php endif; ?>
</div>

<h2 class="mt-22">Dossiers <?= info_tip(
    "Un bailleur par ligne, du délai le plus proche au plus lointain. Le crayon ouvre la ligne pour y noter "
    . "ce que le bailleur demande, ce qu'on a déposé et ce qu'il a répondu."
) ?></h2>

<?php if (!$demandes): ?>
    <p class="muted">Aucun bailleur dans cette campagne.
        <?php if ($peutEcrire): ?><a href="?p=fonds_campagne_form&id=<?= $id ?>">Choisissez-en</a>.<?php endif; ?></p>
<?php else: ?>
<div class="card table-scroll" id="fonds-dossiers">
    <table class="list fonds-dossiers">
        <thead>
            <tr>
                <th>Bailleur</th>
                <th class="nowrap">Délai</th>
                <th class="num">Demandé</th>
                <th class="num">Accordé</th>
                <th>État</th>
                <th class="nowrap"></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($demandes as $d):
            $did = (int) $d['id'];
            $statut = fonds_demande_statut($d, $aujourdhui);
            $formId = 'dossier-' . $did;
        ?>
            <tr>
                <td class="dash-nom">
                    <a class="titre-lien" href="?p=structure&id=<?= (int) $d['structure_id'] ?>&depuis=fonds"><?= e((string) $d['structure_nom']) ?></a>
                    <?php if (trim((string) $d['reference']) !== ''): ?>
                        <div class="muted small fonds-disp"><?= e((string) $d['reference']) ?></div>
                    <?php endif; ?>
                    <?php if ($peutEcrire): ?>
                    <input form="<?= e($formId) ?>" name="reference" class="fonds-editable" hidden
                           value="<?= e((string) $d['reference']) ?>" placeholder="N° de dossier" aria-label="Numéro de dossier">
                    <?php endif; ?>
                </td>
                <td class="small nowrap">
                    <span class="fonds-disp"><?= $jour($d['date_limite']) !== '' ? e($jour($d['date_limite'])) : '—' ?></span>
                    <?php if ($peutEcrire): ?>
                    <input form="<?= e($formId) ?>" type="date" name="date_limite" class="fonds-editable" hidden
                           value="<?= e((string) $d['date_limite']) ?>" aria-label="Délai de dépôt">
                    <?php endif; ?>
                </td>
                <td class="num">
                    <span class="fonds-disp"><?= (float) $d['montant_demande'] > 0 ? chf((float) $d['montant_demande']) : '—' ?></span>
                    <?php if ($peutEcrire): ?>
                    <input form="<?= e($formId) ?>" name="montant_demande" class="fonds-editable" hidden type="text" inputmode="decimal"
                           value="<?= (float) $d['montant_demande'] > 0 ? e(number_format((float) $d['montant_demande'], 2, '.', '')) : '' ?>" aria-label="Montant demandé">
                    <?php endif; ?>
                </td>
                <td class="num strong">
                    <span class="fonds-disp"><?= (float) $d['montant_accorde'] > 0 ? chf((float) $d['montant_accorde']) : '—' ?></span>
                    <?php if ($peutEcrire): ?>
                    <input form="<?= e($formId) ?>" name="montant_accorde" class="fonds-editable" hidden type="text" inputmode="decimal"
                           value="<?= (float) $d['montant_accorde'] > 0 ? e(number_format((float) $d['montant_accorde'], 2, '.', '')) : '' ?>" aria-label="Montant accordé">
                    <?php endif; ?>
                </td>
                <td>
                    <span class="fonds-disp"><?= badge(FONDS_STATUTS[$statut] ?? $statut, FONDS_STATUTS_CLASSES[$statut] ?? 'muted') ?></span>
                    <?php if ($peutEcrire): ?>
                    <?php // La décision seule se pose à la main : le reste de
                          // l'état se lit dans les dates (fonds_demande_statut()). ?>
                    <select form="<?= e($formId) ?>" name="statut" class="fonds-editable" hidden aria-label="Décision du bailleur">
                        <option value="">— D'après les dates —</option>
                        <option value="refusee"<?= $d['statut'] === 'refusee' ? ' selected' : '' ?>>Refusée</option>
                        <option value="abandonnee"<?= $d['statut'] === 'abandonnee' ? ' selected' : '' ?>>Abandonnée</option>
                    </select>
                    <?php endif; ?>
                </td>
                <td class="actions nowrap">
                    <?= bouton_lien_externe_html((string) $d['formulaire_affiche'], 'Formulaire de contact', [
                        'petit' => true, 'nom' => (string) $d['structure_nom'],
                    ]) ?>
                    <?php if ($peutEcrire): ?>
                    <form method="post" action="?p=fonds_demande_enregistrer" id="<?= e($formId) ?>" class="d-inline">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="id" value="<?= $did ?>">
                    </form>
                    <button type="submit" form="<?= e($formId) ?>" class="btn btn-sm icon-only fonds-editable" hidden
                            title="Enregistrer" aria-label="Enregistrer le dossier"><?= icon('save') ?></button>
                    <button type="button" class="btn ghost btn-sm icon-only fonds-edit-btn"
                            title="Modifier" aria-label="Modifier le dossier de <?= e((string) $d['structure_nom']) ?>"><?= icon('pencil') ?></button>
                    <button type="button" class="btn ghost btn-sm icon-only fonds-editable fonds-annuler-btn" hidden
                            title="Annuler" aria-label="Annuler"><?= icon('x') ?></button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php if ($peutEcrire): ?>
            <?php // Les champs du second temps — dépôt, réponse, bilan — sur une
                  // ligne à eux : au repos la table reste lisible, et à l'ouverture
                  // tout ce qui concerne ce dossier est sous les yeux. ?>
            <tr class="fonds-editable fonds-suite" hidden>
                <td colspan="6">
                    <div class="fonds-suite-champs">
                        <label class="small">Déposée le
                            <input form="<?= e($formId) ?>" type="date" name="date_depot" value="<?= e((string) $d['date_depot']) ?>">
                        </label>
                        <label class="small">Réponse le
                            <input form="<?= e($formId) ?>" type="date" name="date_reponse" value="<?= e((string) $d['date_reponse']) ?>">
                        </label>
                        <label class="small">Bilan dû le
                            <input form="<?= e($formId) ?>" type="date" name="date_limite_bilan" value="<?= e((string) $d['date_limite_bilan']) ?>">
                        </label>
                        <label class="small">Bilan transmis le
                            <input form="<?= e($formId) ?>" type="date" name="date_bilan" value="<?= e((string) $d['date_bilan']) ?>">
                        </label>
                        <label class="small grow">Pièces demandées cette fois
                            <input form="<?= e($formId) ?>" name="pieces_autres" value="<?= e((string) $d['pieces_autres']) ?>"
                                   placeholder="en plus de celles que ce bailleur exige toujours">
                        </label>
                        <label class="small grow">Remarques
                            <input form="<?= e($formId) ?>" name="notes" value="<?= e((string) $d['notes']) ?>">
                        </label>
                    </div>
                </td>
            </tr>
            <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

</div></div>

<?php if ($peutEcrire && $demandes): ?>
<script nonce="<?= e(csp_nonce()) ?>">
// Une ligne se lit, le crayon l'ouvre. La seconde ligne — dépôt, réponse,
// bilan — porte .fonds-editable comme les champs : elle suit donc la bascule
// sans rien de plus (lassoInitLigneEdition(), assets/app.js).
lassoInitLigneEdition('#fonds-dossiers', {
    prefixe: 'fonds',
    // La ligne de détail est la SUIVANTE : la bascule ne la voit pas, puisqu'elle
    // ne travaille que dans la ligne du crayon.
    apres: tr => {
        const suite = tr.nextElementSibling;
        if (suite && suite.classList.contains('fonds-suite')) suite.hidden = false;
    },
});
document.getElementById('fonds-dossiers').addEventListener('click', ev => {
    if (!ev.target.closest('.fonds-annuler-btn')) return;
    const suite = ev.target.closest('tr').nextElementSibling;
    if (suite && suite.classList.contains('fonds-suite')) suite.hidden = true;
});
</script>
<?php endif; ?>
