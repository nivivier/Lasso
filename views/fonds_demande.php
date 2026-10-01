<?php
/** @var array $demande */ /** @var array $catalogue */ /** @var array $pieces */
/** @var array $historique */ /** @var ?array $versement */
/** @var array $ecritures */ /** @var ?array $facture */ /** @var ?string $ok */
// La fiche d'un dossier : ce que ce bailleur-là demande, ce qu'on lui a
// déposé, ce qu'il a répondu. Trois cartes qui se lisent et s'ouvrent au
// crayon, comme partout (docs/UI.md § 2a).
$d = $demande;
$id = (int) $d['id'];
$sid = (int) $d['structure_id'];
$statut = fonds_demande_statut($d);
$peutEcrire = peut_ecrire('fonds');
$jour = fn ($v) => trim((string) $v) !== '' ? date('d.m.Y', strtotime((string) $v)) : '';
$montant = fn ($v) => (float) $v > 0 ? chf((float) $v) : '<span class="muted">—</span>';
$champ = fn (string $c) => e((string) ($d[$c] ?? ''));
?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>

<div class="module-content"><div class="module-content-inner">
<a class="back-link" href="?p=fonds_campagne&id=<?= (int) $d['campagne_id'] ?>">
    <?= icon('arrow-left') ?> <?= e((string) $d['campagne_nom']) ?></a>

<?php if ($ok === 'pieces'): ?><p class="ok flash">Pièces exigées enregistrées.</p>
<?php elseif ($ok === 'versement'): ?><p class="ok flash">Versement enregistré.</p>
<?php elseif ($ok !== null): ?><p class="ok flash">Dossier enregistré.</p><?php endif; ?>

<div class="page-head">
    <div class="page-head-title">
        <h1><?= e((string) $d['structure_nom']) ?></h1>
        <?= badge(FONDS_STATUTS[$statut] ?? $statut, FONDS_STATUTS_CLASSES[$statut] ?? 'muted') ?>
    </div>
    <div class="head-actions">
        <?php // Ce qui mène ailleurs d'abord, ce qui agit à droite (docs/UI.md § 1). ?>
        <?= bouton_lien_externe_html((string) $d['drive_url'], 'Dossier partagé') ?>
        <?= bouton_formulaire_contact_html((string) $d['formulaire_affiche'], ['nom' => (string) $d['structure_nom']]) ?>
        <a class="btn ghost" href="?p=structure&id=<?= $sid ?>&depuis=fonds"><?= icon('house') ?> <span class="lbl">Fiche du bailleur</span></a>
    </div>
</div>

<div class="card card-editable">
    <div class="card-head-row">
        <h2 class="mt-0">Le dossier</h2>
        <?php if ($peutEcrire): ?>
        <?= carte_actions_html(['form' => 'dossier-form', 'quoi' => 'ce dossier']) ?>
        <?php endif; ?>
    </div>

    <div class="card-disp">
        <table class="kv-table">
            <tr><th>Montant demandé</th><td><?= $montant($d['montant_demande']) ?></td></tr>
            <tr><th>Montant accordé</th><td><?= $montant($d['montant_accorde']) ?></td></tr>
            <tr><th>Délai de dépôt</th><td><?= $jour($d['date_limite']) !== '' ? e($jour($d['date_limite'])) : '<span class="muted">—</span>' ?></td></tr>
            <tr><th>Déposée le</th><td><?= $jour($d['date_depot']) !== '' ? e($jour($d['date_depot'])) : '<span class="muted">—</span>' ?></td></tr>
            <tr><th>Réponse le</th><td><?= $jour($d['date_reponse']) !== '' ? e($jour($d['date_reponse'])) : '<span class="muted">—</span>' ?></td></tr>
            <tr><th>Bilan dû le</th><td><?= $jour($d['date_limite_bilan']) !== '' ? e($jour($d['date_limite_bilan'])) : '<span class="muted">—</span>' ?></td></tr>
            <tr><th>Bilan transmis le</th><td><?= $jour($d['date_bilan']) !== '' ? e($jour($d['date_bilan'])) : '<span class="muted">—</span>' ?></td></tr>
            <tr><th>N° de dossier</th><td><?= trim((string) $d['reference']) !== '' ? e((string) $d['reference']) : '<span class="muted">—</span>' ?></td></tr>
            <?php if (trim((string) $d['pieces_autres']) !== ''): ?>
            <tr><th>Pièces demandées cette fois</th><td><?= e((string) $d['pieces_autres']) ?></td></tr>
            <?php endif; ?>
            <?php if (trim((string) $d['notes']) !== ''): ?>
            <tr><th>Remarques</th><td><?= nl2br(e((string) $d['notes'])) ?></td></tr>
            <?php endif; ?>
        </table>
    </div>

    <?php if ($peutEcrire): ?>
    <form method="post" action="?p=fonds_demande_enregistrer" id="dossier-form" class="card-edit form" hidden>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= $id ?>">
        <?php // Le retour ramène ici plutôt qu'au suivi de la campagne : on
              // vient d'ouvrir CE dossier, on n'en sort pas en l'enregistrant. ?>
        <input type="hidden" name="retour" value="demande">
        <div class="grid2">
            <label><span>Montant demandé</span>
                <span class="pct-input">
                    <input name="montant_demande" type="text" inputmode="decimal"
                           value="<?= (float) $d['montant_demande'] > 0 ? e(number_format((float) $d['montant_demande'], 2, '.', '')) : '' ?>">
                    <span class="pct-suffix">CHF</span>
                </span>
            </label>
            <label><span>Montant accordé</span>
                <span class="pct-input">
                    <input name="montant_accorde" type="text" inputmode="decimal"
                           value="<?= (float) $d['montant_accorde'] > 0 ? e(number_format((float) $d['montant_accorde'], 2, '.', '')) : '' ?>">
                    <span class="pct-suffix">CHF</span>
                </span>
            </label>
        </div>
        <div class="grid3 mt-16">
            <label><span>Délai de dépôt <?= info_tip(
                "La date que CE bailleur impose pour CETTE campagne : elle change d'une année à l'autre."
            ) ?></span><input type="date" name="date_limite" value="<?= $champ('date_limite') ?>"></label>
            <label>Déposée le <input type="date" name="date_depot" value="<?= $champ('date_depot') ?>"></label>
            <label>Réponse le <input type="date" name="date_reponse" value="<?= $champ('date_reponse') ?>"></label>
        </div>
        <div class="grid3 mt-16">
            <label><span>Bilan dû le <?= info_tip(
                "La seconde échéance, celle qu'on oublie une fois l'argent reçu. Renseignée, elle fait apparaître le dossier dans les bilans dus."
            ) ?></span><input type="date" name="date_limite_bilan" value="<?= $champ('date_limite_bilan') ?>"></label>
            <label>Bilan transmis le <input type="date" name="date_bilan" value="<?= $champ('date_bilan') ?>"></label>
            <label>N° de dossier <input name="reference" value="<?= $champ('reference') ?>"></label>
        </div>
        <?php // La décision seule se pose à la main : tout le reste de l'état se
              // lit dans les dates et les montants (fonds_demande_statut()). ?>
        <label class="mt-16"><span>Décision du bailleur <?= info_tip(
            "Laissez « d'après les dates » dans le cours normal : l'état se déduit du dépôt, de la réponse et des montants. "
            . "Un refus et un abandon, eux, ne se devinent d'aucune date."
        ) ?></span>
            <select name="statut">
                <option value="">— D'après les dates —</option>
                <option value="refusee"<?= $d['statut'] === 'refusee' ? ' selected' : '' ?>>Refusée</option>
                <option value="abandonnee"<?= $d['statut'] === 'abandonnee' ? ' selected' : '' ?>>Abandonnée</option>
            </select>
        </label>
        <label class="mt-16"><span>Pièces demandées cette fois <?= info_tip(
            "En plus de ce que ce bailleur exige toujours (carte ci-dessous)."
        ) ?></span><input name="pieces_autres" value="<?= $champ('pieces_autres') ?>"></label>
        <label class="mt-16">Remarques <textarea name="notes" rows="2"><?= $champ('notes') ?></textarea></label>
    </form>
    <?php endif; ?>
</div>

<?php // Le versement n'apparaît qu'une fois l'argent accordé : avant, il n'y a
      // rien à verser, et la carte ne ferait qu'encombrer.
      //
      // UNE ligne, alors que la table en accepte plusieurs : l'échelonnement
      // est hors périmètre (SPEC_SUBVENTIONS.md § 7). Le jour où il arrive,
      // c'est cette carte qui change, pas le schéma. ?>
<?php if ((float) $d['montant_accorde'] > 0): ?>
<div class="card card-editable mt-22">
    <div class="card-head-row">
        <h2 class="mt-0">Versement</h2>
        <?php if ($peutEcrire): ?>
        <?= carte_actions_html(['form' => 'versement-form', 'quoi' => 'le versement']) ?>
        <?php endif; ?>
    </div>

    <div class="card-disp">
        <?php if (!$versement): ?>
            <p class="muted">Rien de noté. L'argent est accordé, reste à dire quand il est arrivé.</p>
        <?php else: ?>
        <?php
            $ecr = null;
            foreach ($ecritures as $e) {
                if ((int) $e['id'] === (int) ($versement['ecriture_id'] ?? 0)) { $ecr = $e; break; }
            }
        ?>
        <table class="kv-table">
            <tr><th>Montant</th><td><?= $montant($versement['montant']) ?></td></tr>
            <tr><th>Attendu le</th><td><?= $jour($versement['date_prevue']) !== '' ? e($jour($versement['date_prevue'])) : '<span class="muted">—</span>' ?></td></tr>
            <tr><th>Reçu le</th><td><?= $jour($versement['date_recue']) !== '' ? e($jour($versement['date_recue'])) : '<span class="muted">—</span>' ?></td></tr>
            <tr><th>Écriture bancaire</th><td><?= $ecr
                ? e(date('d.m.Y', strtotime((string) $ecr['date_op'])) . ' — ' . chf((float) $ecr['montant']) . ' — ' . mb_substr((string) $ecr['texte'], 0, 60))
                : '<span class="muted">Pas encore rapprochée</span>' ?></td></tr>
            <?php if (trim((string) $versement['notes']) !== ''): ?>
            <tr><th>Remarques</th><td><?= e((string) $versement['notes']) ?></td></tr>
            <?php endif; ?>
        </table>
        <?php endif; ?>
    </div>

    <?php if ($peutEcrire): ?>
    <form method="post" action="?p=fonds_versement" id="versement-form" class="card-edit form" hidden>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="grid3">
            <label><span>Montant <?= info_tip(
                "Tout laisser vide efface le versement : c'est ainsi qu'on revient en arrière."
            ) ?></span>
                <span class="pct-input">
                    <input name="montant" type="text" inputmode="decimal"
                           value="<?= (float) ($versement['montant'] ?? 0) > 0 ? e(number_format((float) $versement['montant'], 2, '.', '')) : '' ?>">
                    <span class="pct-suffix">CHF</span>
                </span>
            </label>
            <label>Attendu le <input type="date" name="date_prevue" value="<?= e((string) ($versement['date_prevue'] ?? '')) ?>"></label>
            <label>Reçu le <input type="date" name="date_recue" value="<?= e((string) ($versement['date_recue'] ?? '')) ?>"></label>
        </div>
        <?php if ($ecritures): ?>
        <label class="mt-16"><span>Écriture bancaire <?= info_tip(
            "L'entrée d'argent qui correspond, sur un relevé importé. Les écritures déjà prises par une facture "
            . "ou par un autre versement ne sont pas proposées."
        ) ?></span>
            <select name="ecriture_id">
                <option value="">— Pas encore rapprochée —</option>
                <?php foreach ($ecritures as $e): ?>
                <option value="<?= (int) $e['id'] ?>"<?= (int) $e['id'] === (int) ($versement['ecriture_id'] ?? 0) ? ' selected' : '' ?>>
                    <?= e(date('d.m.Y', strtotime((string) $e['date_op'])) . ' — ' . chf((float) $e['montant']) . ' — ' . mb_substr((string) $e['texte'], 0, 60)) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>
        <label class="mt-16">Remarques <input name="notes" value="<?= e((string) ($versement['notes'] ?? '')) ?>"></label>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php // Certains bailleurs ne versent rien sans facture ; d'autres n'en veulent
      // pas. Elle n'a donc rien d'obligatoire (SPEC_SUBVENTIONS.md § 9.11), et
      // la carte ne paraît qu'une fois l'argent accordé — avant, il n'y a rien
      // à facturer. Le geste est celui des « Factures liées » d'une date :
      // « Créer » ouvre le formulaire de facture, déjà rempli de ce que le
      // dossier sait (docs/UI.md § 1 — un geste qui existe se refait pareil). ?>
<?php if (module_accessible('facturation') && (float) $d['montant_accorde'] > 0): ?>
<div class="card mt-22">
    <div class="card-head-row">
        <h2 class="mt-0">Facture au bailleur <?= info_tip(
            "Une facture ordinaire, tenue par le module Facturation : le bailleur en destinataire, le montant accordé, "
            . "et l'axe analytique du projet financé. Tout reste modifiable avant de l'émettre."
        ) ?></h2>
        <?php if (!$facture && $peutEcrire && peut_ecrire('facturation')): ?>
        <div class="head-actions">
            <a class="btn ghost" href="?p=facturation_form&fonds_demande_id=<?= $id ?>"><?= icon('file-plus') ?> <span class="lbl">Créer</span></a>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!$facture): ?>
        <p class="muted mb-0">Aucune facture pour ce dossier.</p>
    <?php else: ?>
    <table class="list mb-0">
        <thead><tr><th>Numéro</th><th class="num">Montant</th><th>Statut</th></tr></thead>
        <tbody>
            <tr>
                <td><a href="<?= e(url_avec_retour('?p=facture&id=' . (int) $facture['id'], 'fonds_demande', $id)) ?>">
                    <?= trim((string) $facture['numero']) !== '' ? e((string) $facture['numero']) : '<span class="muted">(brouillon)</span>' ?></a></td>
                <td class="num strong"><?= chf((float) $facture['montant_total']) ?></td>
                <td><?= facturation_badge($facture) ?></td>
            </tr>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php // Les pièces appartiennent au BAILLEUR, pas à la campagne : la même
      // fondation demande les mêmes documents d'une année sur l'autre. La carte
      // le dit, sans quoi on croirait régler le seul dossier ouvert. ?>
<div class="card card-editable mt-22">
    <div class="card-head-row">
        <h2 class="mt-0">Ce que <?= e((string) $d['structure_nom']) ?> exige <?= info_tip(
            "Ces pièces valent pour TOUS les dossiers de ce bailleur, aujourd'hui et demain — on ne les ressaisit pas à chaque campagne."
        ) ?></h2>
        <?php if ($peutEcrire): ?>
        <?= carte_actions_html(['form' => 'pieces-form', 'quoi' => 'les pièces exigées']) ?>
        <?php endif; ?>
    </div>

    <div class="card-disp">
        <?php $rienDeCoche = !$pieces['demande'] && !$pieces['bilan'] && trim((string) $d['fonds_pieces_autres']) === ''; ?>
        <?php if ($rienDeCoche): ?>
            <p class="muted">Rien de noté pour ce bailleur.</p>
        <?php else: ?>
        <table class="kv-table">
            <?php foreach (FONDS_MOMENTS as $moment => $libelle): ?>
            <tr>
                <th><?= e($libelle) ?></th>
                <td><?php
                    $noms = [];
                    foreach ($catalogue as $p) {
                        if (in_array((int) $p['id'], $pieces[$moment], true)) { $noms[] = (string) $p['nom']; }
                    }
                    echo $noms ? e(implode(', ', $noms)) : '<span class="muted">—</span>';
                ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (trim((string) $d['fonds_pieces_autres']) !== ''): ?>
            <tr><th>Autres</th><td><?= e((string) $d['fonds_pieces_autres']) ?></td></tr>
            <?php endif; ?>
        </table>
        <?php endif; ?>
    </div>

    <?php if ($peutEcrire): ?>
    <form method="post" action="?p=fonds_pieces" id="pieces-form" class="card-edit form" hidden>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="grid2">
            <?php foreach (FONDS_MOMENTS as $moment => $libelle): ?>
            <?php // <div> et non <label> : un groupe de cases dans un label
                  // activerait la première au moindre clic (docs/UI.md § 10). ?>
            <div class="field-group">
                <span><?= e($libelle) ?></span>
                <?php foreach ($catalogue as $p): ?>
                <label class="check">
                    <input type="checkbox" name="pieces_<?= e($moment) ?>[]" value="<?= (int) $p['id'] ?>"
                           <?= in_array((int) $p['id'], $pieces[$moment], true) ? 'checked' : '' ?>>
                    <?= e((string) $p['nom']) ?>
                </label>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <label class="mt-16"><span>Autres pièces <?= info_tip(
            "Ce que ce bailleur réclame en plus du catalogue, et qu'il réclamera encore la prochaine fois."
        ) ?></span>
            <input name="fonds_pieces_autres" value="<?= e((string) $d['fonds_pieces_autres']) ?>"
                   placeholder="ex. statuts de l'association, lettre de soutien">
        </label>
    </form>
    <?php endif; ?>
</div>

<?php // L'historique de la structure, celui-là même que montre sa fiche : les
      // échanges d'un bailleur ne se rangent pas dans un second fil (§ 9.15). ?>
<div class="card mt-22">
    <h2 class="mt-0">Historique</h2>
    <?php if (!$historique): ?>
        <p class="muted">Aucun échange noté avec ce bailleur.</p>
    <?php else: ?>
        <?php $histoEntrees = array_slice($historique, 0, 10); require __DIR__ . '/_historique.php'; ?>
        <?php if (count($historique) > 10): ?>
        <p class="muted small mt-16">… et <?= count($historique) - 10 ?> entrée(s) plus anciennes, sur
            <a href="?p=structure&id=<?= $sid ?>&depuis=fonds">la fiche du bailleur</a>.</p>
        <?php endif; ?>
    <?php endif; ?>
</div>

</div></div>
