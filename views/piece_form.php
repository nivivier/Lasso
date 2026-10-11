<?php /** @var ?array $p */ /** @var ?array $champs */ /** @var string $err */ /** @var array $categories */
/** @var ?array $ecrSource */

// Trois sources, dans cet ordre : la saisie qu'on vient de refuser (il ne faut
// jamais la perdre), la pièce qu'on modifie, l'écriture d'où l'on vient. Cette
// dernière ne remplit que ce qu'elle sait — date, montant, contre-partie,
// catégorie —, et c'est déjà tout sauf l'intitulé et le fichier.
$depuisEcr = $ecrSource !== null;
$v = function (string $cle, $defaut = '') use ($p, $champs, $ecrSource) {
    if ($champs !== null) { return (string) ($champs[$cle] ?? ''); }
    if ($p !== null)      { return (string) ($p[$cle] ?? $defaut); }
    if ($ecrSource !== null) {
        $depuis = [
            'date_piece'     => (string) $ecrSource['date_op'],
            'date_payee'     => (string) $ecrSource['date_op'],
            'tiers'          => (string) ($ecrSource['tiers'] ?? ''),
            'plan_compte_id' => (string) ($ecrSource['plan_compte_id'] ?? ''),
            'mode_paiement'  => 'compte',
        ];
        if (isset($depuis[$cle])) { return $depuis[$cle]; }
    }
    return (string) $defaut;
};
$id      = (int) ($p['id'] ?? 0);
$montant = (float) ($champs['montant'] ?? $p['montant'] ?? $ecrSource['montant'] ?? 0);
$sens    = $montant > 0 ? 'recette' : 'depense';
$type    = $v('type', 'facture_recue');
$modePaie = $v('mode_paiement');
?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>

<div class="module-content"><div class="module-content-inner">
<?= lien_retour_contextuel($id ? '?p=piece&id=' . $id : '?p=pieces', $id ? (string) $p['libelle'] : 'Pièces') ?>

<?php if ($err !== ''): ?><p class="err flash"><?= e($err) ?></p><?php endif; ?>

<div class="page-head">
    <div class="page-head-title"><h1><?= $id ? 'Modifier la pièce' : 'Nouvelle pièce' ?></h1></div>
    <?= entete_form_actions_html('piece-form', $id ? '?p=piece&id=' . $id : '?p=pieces') ?>
</div>

<?php // enctype obligatoire : sans lui, $_FILES reste vide et le fichier part
      // en silence — l'erreur ne se voit qu'à l'enregistrement, quand rien
      // n'est joint. ?>
<form method="post" action="?p=piece_form" id="piece-form" class="card form" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <?php if ($id): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
    <?php if ($depuisEcr): ?>
    <input type="hidden" name="ecriture_id" value="<?= (int) $ecrSource['id'] ?>">
    <p class="muted small mb-16">
        <?= icon('banknote') ?> Sera rattachée à l'écriture du
        <?= e(date('d.m.Y', strtotime((string) $ecrSource['date_op']))) ?>,
        <?= e(chf((float) $ecrSource['montant'])) ?> — <?= e(mb_substr((string) $ecrSource['texte'], 0, 60, 'UTF-8')) ?>
    </p>
    <?php endif; ?>

    <h3 class="sub no-mt">Ce que c'est</h3>
    <?= icon_picker('type', array_map(fn ($t) => ['icone' => $t['icone'], 'label' => $t['libelle']], PIECE_TYPES), $type, 'Type de pièce') ?>

    <div class="grid2 mt-16">
        <label>Intitulé <input name="libelle" value="<?= e($v('libelle')) ?>" placeholder="ex. Facture Swisscom janvier" autofocus></label>
        <label>Tiers <input name="tiers" value="<?= e($v('tiers')) ?>" placeholder="ex. Swisscom"></label>
    </div>

    <div class="grid3 mt-16">
        <label><span>Montant <?= info_tip(
            "Toujours en positif : c'est le SENS, juste à côté, qui dit s'il s'agit d'une dépense ou d'une recette. "
            . 'Le signe est une propriété du mouvement, pas une subtilité de frappe.'
        ) ?></span>
            <span class="pct-input">
                <input name="montant" type="text" inputmode="decimal" required
                       value="<?= $montant != 0.0 ? e(number_format(abs($montant), 2, '.', '')) : '' ?>">
                <span class="pct-suffix">CHF</span>
            </span>
        </label>
        <label><span>Sens</span>
            <select name="sens">
                <option value="depense"<?= $sens === 'depense' ? ' selected' : '' ?>>Dépense (argent qui sort)</option>
                <option value="recette"<?= $sens === 'recette' ? ' selected' : '' ?>>Recette (argent qui entre)</option>
            </select>
        </label>
        <label>Date de la pièce <input type="date" name="date_piece" value="<?= e($v('date_piece') ?: date('Y-m-d')) ?>"></label>
    </div>

    <div class="grid2 mt-16">
        <label><span>Échéance <?= info_tip(
            "Quand elle doit être payée. C'est elle qui fait apparaître la pièce dans « à payer » et qui la signale "
            . 'une fois le délai passé. Un ticket de caisse, déjà réglé, n\'en a pas.'
        ) ?></span><input type="date" name="date_echeance" value="<?= e($v('date_echeance')) ?>"></label>
        <label><span>Catégorie présumée <?= info_tip(
            'Pour ranger les pièces entre elles. Rattacher la pièce à une écriture ne classera PAS cette écriture : '
            . 'le lettrage reste un geste à part.'
        ) ?></span>
            <select name="plan_compte_id">
                <option value="">— Aucune —</option>
                <?php foreach ($categories as $c): ?>
                <option value="<?= (int) $c['id'] ?>"<?= (int) $v('plan_compte_id') === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['chemin']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>

    <h3 class="sub">Le paiement <?= info_tip(
        'Une pièce peut être marquée payée AVANT que son écriture existe : les relevés bancaires ne '
        . "s'importent qu'une fois par mois. Laisser vide tant qu'elle ne l'est pas."
    ) ?></h3>
    <div class="grid2">
        <label>Payée le <input type="date" name="date_payee" value="<?= e($v('date_payee')) ?>"></label>
        <label><span>Moyen</span>
            <select name="mode_paiement">
                <?php foreach (PIECE_MODES_PAIEMENT as $k => $lib): ?>
                <option value="<?= e($k) ?>"<?= $modePaie === $k ? ' selected' : '' ?>><?= e($lib) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>

    <h3 class="sub">Le justificatif</h3>
    <?php if ($id && trim((string) $p['fichier']) !== ''): ?>
    <p class="muted small">
        <?= icon('paperclip') ?> <?= e((string) $p['nom_origine']) ?>
        (<?= e(fichier_taille_lisible((int) $p['taille'])) ?>) — en envoyer un nouveau le remplacera.
    </p>
    <?php endif; ?>
    <div class="piece-depot">
        <label class="piece-depot-champ"><span>Fichier (PDF, image, document — 8 Mo max)</span>
            <input type="file" name="fichier" accept=".pdf,image/*,.txt,.csv,.docx,.xlsx,.odt,.ods">
        </label>
        <?php // capture="environment" ouvre l'appareil photo sur un téléphone.
              // Le navigateur réduit l'image à 1600px avant l'envoi
              // (lassoPhotoPiece), sinon dix ans de tickets à 4 Mo pièce
              // finiraient dans chaque sauvegarde. Le champ caché porte le
              // résultat ; le serveur le revalide comme un fichier reçu. ?>
        <label class="piece-depot-champ piece-depot-photo"><span>…ou photographier le ticket</span>
            <input type="file" accept="image/*" capture="environment" id="piece-photo" data-photo-piece="#piece-photo-data">
        </label>
        <input type="hidden" name="photo" id="piece-photo-data">
        <div class="piece-photo-apercu" id="piece-photo-apercu" hidden>
            <img alt="Aperçu de la photo" id="piece-photo-img">
            <button type="button" class="btn ghost btn-sm" id="piece-photo-retirer"><?= icon('x') ?> Retirer</button>
        </div>
    </div>

    <label class="mt-16">Remarques <textarea name="notes" rows="2"><?= e($v('notes')) ?></textarea></label>
</form>
</div></div>
