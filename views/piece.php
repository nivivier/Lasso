<?php /** @var array $p */ /** @var ?array $ecriture */ /** @var array $candidates */
/** @var ?string $ok */ /** @var string $err */

$peutEcrire = peut_ecrire('pieces');
$id    = (int) $p['id'];
$etat  = piece_etat($p);
$retard = piece_en_retard($p);
$typeInfo = PIECE_TYPES[$p['type']] ?? PIECE_TYPES['autre'];
$jour = fn (?string $d): string => $d ? date('d.m.Y', strtotime($d)) : '';
$vide = '<span class="muted">—</span>';
?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>

<div class="module-content"><div class="module-content-inner">
<?= lien_retour_contextuel('?p=pieces', 'Pièces') ?>

<?php if ($ok === 'rapprochee'): ?><p class="ok flash">Pièce rattachée à l'écriture.</p>
<?php elseif ($ok === 'detachee'): ?><p class="ok flash">Pièce détachée de son écriture.</p>
<?php elseif ($ok === 'paiement'): ?><p class="ok flash">Paiement enregistré.</p>
<?php elseif ($ok !== null): ?><p class="ok flash">Pièce enregistrée.</p><?php endif; ?>
<?php if ($err !== ''): ?><p class="err flash"><?= e($err) ?></p><?php endif; ?>

<div class="page-head">
    <div class="page-head-title">
        <h1><?= e((string) $p['libelle']) ?></h1>
        <?= piece_badge_html($p, $etat, $retard) ?>
    </div>
    <?php if ($peutEcrire): ?>
    <div class="head-actions">
        <?php // « Supprimer » n'est pas dans cette barre (docs/UI.md § 1) : il
              // vit au pied de la carte, en mode édition. Ici, ce qui mène
              // ailleurs, puis « Modifier » en dernier. ?>
        <?php if ((string) $p['type'] === 'quittance'): ?>
            <?php // Ce qui mène ailleurs avant ce qui agit (docs/UI.md § 1) :
                  // l'impression ouvre un document, « Modifier » change la
                  // fiche. ?>
            <a class="btn ghost" href="?p=quittance_imprimer&id=<?= $id ?>" target="_blank" rel="noopener"><?= icon('printer') ?> <span class="lbl">Imprimer</span></a>
        <?php endif; ?>
        <?php if (trim((string) $p['fichier']) !== ''): ?>
            <a class="btn ghost" href="?p=piece_fichier&id=<?= $id ?>"><?= icon('download') ?> <span class="lbl">Télécharger</span></a>
        <?php endif; ?>
        <a class="btn" href="?p=piece_form&id=<?= $id ?>"><?= icon('pencil') ?> <span class="lbl">Modifier</span></a>
    </div>
    <?php endif; ?>
</div>

<div class="grid2">
    <div class="card">
        <h2 class="mt-0">La pièce</h2>
        <table class="kv-table">
            <tr><th>Type</th><td><?= icon($typeInfo['icone']) ?> <?= e($typeInfo['libelle']) ?></td></tr>
            <tr><th>Tiers</th><td><?= $p['tiers'] !== '' ? e((string) $p['tiers']) : $vide ?></td></tr>
            <tr><th>Montant</th><td class="<?= (float) $p['montant'] < 0 ? 'montant-neg' : 'montant-pos' ?>"><?= chf((float) $p['montant']) ?></td></tr>
            <tr><th>Date</th><td><?= $jour($p['date_piece']) !== '' ? e($jour($p['date_piece'])) : $vide ?></td></tr>
            <?php if ($p['date_echeance'] !== ''): ?>
            <tr><th>Échéance</th><td><?= $retard ? '<strong>' . e($jour($p['date_echeance'])) . '</strong>' : e($jour($p['date_echeance'])) ?></td></tr>
            <?php endif; ?>
            <tr><th>Catégorie présumée</th><td>
                <?php if (!empty($p['plan_compte_id'])): ?>
                    <?php $cat = compta_plan_map()[(int) $p['plan_compte_id']] ?? null; ?>
                    <?= $cat ? e((string) $cat['libelle']) : $vide ?>
                <?php else: ?><?= $vide ?><?php endif; ?>
            </td></tr>
            <?php if ($p['numero'] !== ''): ?>
            <tr><th>Numéro</th><td><code><?= e((string) $p['numero']) ?></code></td></tr>
            <?php endif; ?>
            <?php if ((string) $p['type'] === 'quittance'): $b = quittance_beneficiaire($p); ?>
            <tr><th>Bénéficiaire</th><td>
                <?php if (!empty($p['structure_id'])): ?>
                    <a href="?p=structure&id=<?= (int) $p['structure_id'] ?>"><?= e($b['nom']) ?></a>
                <?php elseif (!empty($p['personne_id'])): ?>
                    <a href="?p=personne&id=<?= (int) $p['personne_id'] ?>"><?= e($b['nom']) ?></a>
                <?php else: ?>
                    <?= $b['nom'] !== '' ? e($b['nom']) : $vide ?>
                <?php endif; ?>
            </td></tr>
            <?php endif; ?>
            <?php if (trim((string) $p['notes']) !== ''): ?>
            <tr><th>Remarques</th><td><?= nl2br(e((string) $p['notes'])) ?></td></tr>
            <?php endif; ?>
        </table>

        <?php if (trim((string) $p['fichier']) !== ''): ?>
        <div class="piece-fichier mt-16">
            <?= icon('paperclip') ?>
            <a href="?p=piece_fichier&id=<?= $id ?>"><?= e((string) $p['nom_origine']) ?></a>
            <span class="muted small"><?= e(fichier_taille_lisible((int) $p['taille'])) ?></span>
        </div>
        <?php elseif ((string) $p['origine'] === 'televersee'): ?>
        <p class="muted small mt-16">Aucun fichier joint.</p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2 class="mt-0">Le paiement</h2>
        <?php if ($etat === 'a_payer'): ?>
            <?php // LE geste courant : une facture se paie le 30, et son écriture
                  // n'arrive qu'à l'import du mois suivant. Sans ce bouton il
                  // faudrait rouvrir le formulaire entier pour poser une date. ?>
            <p class="muted">Cette pièce n'est pas encore payée<?= $p['date_echeance'] !== '' ? ', échéance au ' . e($jour($p['date_echeance'])) : '' ?>.</p>
            <?php if ($peutEcrire): ?>
            <form method="post" action="?p=piece_payee" class="form mt-16">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= $id ?>">
                <div class="grid2">
                    <label>Payée le <input type="date" name="date_payee" value="<?= e(date('Y-m-d')) ?>"></label>
                    <label><span>Moyen</span>
                        <select name="mode_paiement">
                            <?php foreach (PIECE_MODES_PAIEMENT as $k => $lib): ?>
                            <option value="<?= e($k) ?>"><?= e($lib) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <button type="submit" class="btn mt-16"><?= icon('check') ?> Marquer payée</button>
            </form>
            <?php endif; ?>
        <?php else: ?>
            <table class="kv-table">
                <tr><th>Payée le</th><td><?= e($jour($p['date_payee'])) ?></td></tr>
                <tr><th>Moyen</th><td><?= e(PIECE_MODES_PAIEMENT[$p['mode_paiement']] ?? '—') ?></td></tr>
            </table>
            <?php if ((string) $p['mode_paiement'] === 'hors_compte'): ?>
                <p class="muted small mt-16">Réglée hors du compte suivi : aucune écriture ne viendra, la pièce est classée.</p>
            <?php endif; ?>
            <?php if ($peutEcrire): ?>
            <form method="post" action="?p=piece_payee" class="d-inline mt-16">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="section" value="annuler">
                <button type="submit" class="btn ghost btn-sm"
                        data-confirm="Marquer cette pièce comme non payée ? Son rattachement à une écriture sera défait."><?= icon('x') ?> Pas encore payée</button>
            </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if (module_accessible('compta')): ?>
<div class="card mt-22">
    <h2 class="mt-0">L'écriture</h2>
    <?php if ($ecriture): ?>
        <table class="kv-table">
            <tr><th>Date</th><td><?= e($jour($ecriture['date_op'])) ?></td></tr>
            <tr><th>Compte</th><td><?= e((string) $ecriture['compte_libelle']) ?></td></tr>
            <tr><th>Libellé</th><td><?= e((string) $ecriture['texte']) ?></td></tr>
            <tr><th>Montant</th><td class="<?= (float) $ecriture['montant'] < 0 ? 'montant-neg' : 'montant-pos' ?>"><?= chf((float) $ecriture['montant']) ?></td></tr>
        </table>
        <?php if ($peutEcrire): ?>
        <form method="post" action="?p=piece_rapprocher" class="d-inline mt-16">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="piece_id" value="<?= $id ?>">
            <input type="hidden" name="ecriture_id" value="0">
            <button type="submit" class="btn ghost btn-sm"><?= icon('x') ?> Détacher</button>
        </form>
        <?php endif; ?>
    <?php elseif ((string) $p['mode_paiement'] === 'hors_compte'): ?>
        <p class="muted">Réglée hors du compte suivi : il n'y a pas d'écriture à chercher.</p>
    <?php elseif (!$peutEcrire): ?>
        <p class="muted">Pas encore rattachée à une écriture.</p>
    <?php elseif (!$candidates): ?>
        <p class="muted">Aucune écriture libre ne correspond. Les relevés s'importent une fois par mois : l'écriture viendra.</p>
    <?php else: ?>
        <?php // Triées par proximité — écart de montant d'abord, puis distance
              // de date : c'est ce qui met la bonne en tête neuf fois sur dix.
              // Rattacher NE LETTRE PAS l'écriture (SPEC_PIECES.md § 8). ?>
        <p class="muted small">Les écritures les plus proches en montant et en date. Rattacher ne classe pas l'écriture : le lettrage reste un geste à part.</p>
        <div class="table-scroll mt-16">
        <table class="list">
            <thead><tr><th>Date</th><th>Compte</th><th>Libellé</th><th class="num">Montant</th><th></th></tr></thead>
            <tbody>
            <?php foreach (array_slice($candidates, 0, 12) as $c): ?>
                <tr>
                    <td class="nowrap"><?= e($jour($c['date_op'])) ?></td>
                    <td class="muted small"><?= e((string) $c['compte_libelle']) ?></td>
                    <td><?= e((string) ($c['tiers'] !== '' ? $c['tiers'] . ' — ' : '')) ?><span class="muted"><?= e(mb_substr((string) $c['texte'], 0, 70, 'UTF-8')) ?></span></td>
                    <td class="num <?= (float) $c['montant'] < 0 ? 'montant-neg' : 'montant-pos' ?>"><?= chf((float) $c['montant']) ?></td>
                    <td class="actions nowrap">
                        <form method="post" action="?p=piece_rapprocher" class="d-inline">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="piece_id" value="<?= $id ?>">
                            <input type="hidden" name="ecriture_id" value="<?= (int) $c['id'] ?>">
                            <button type="submit" class="btn btn-sm"><?= icon('check') ?> Rattacher</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($peutEcrire): ?>
<form method="post" action="?p=piece_supprimer" class="mt-22">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= $id ?>">
    <button type="submit" class="btn danger ghost btn-sm"
            data-confirm="Supprimer cette pièce ? Le fichier joint sera effacé du serveur."><?= icon('trash') ?> Supprimer cette pièce</button>
</form>
<?php endif; ?>
</div></div>
