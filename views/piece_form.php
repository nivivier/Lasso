<?php /** @var ?array $p */ /** @var ?array $champs */ /** @var string $err */ /** @var array $categories */
/** @var ?array $ecrSource */ /** @var array $beneficiaires */

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
// Le bénéficiaire, dans la forme que porte le sélecteur unique : « s:12 » ou
// « p:34 ». Un seul champ plutôt que deux menus, qui auraient laissé en
// remplir deux et posé la question de qui gagne.
$benefCourant = '';
if ($p !== null) {
    if (!empty($p['structure_id'])) { $benefCourant = 's:' . (int) $p['structure_id']; }
    elseif (!empty($p['personne_id'])) { $benefCourant = 'p:' . (int) $p['personne_id']; }
}
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

    <h3 class="sub no-mt">Le justificatif <?= info_tip(
        "La première chose qu'on a sous la main : la facture reçue, le ticket, le document. "
        . "Le reste se lit dessus."
    ) ?></h3>
    <?php if ($id && trim((string) $p['fichier']) !== ''): ?>
    <p class="muted small">
        <?= icon('paperclip') ?> <?= e((string) $p['nom_origine']) ?>
        (<?= e(fichier_taille_lisible((int) $p['taille'])) ?>) — en envoyer un nouveau le remplacera.
    </p>
    <?php endif; ?>
    <div class="piece-depot">
        <label class="piece-depot-champ"><span>Un fichier (PDF, image, document — 8 Mo max)</span>
            <input type="file" name="fichier" accept=".pdf,image/*,.txt,.csv,.docx,.xlsx,.odt,.ods">
        </label>
        <?php // Prendre une photo. UNE seule porte, et elle s'adapte :
              //   • si le navigateur sait ouvrir une caméra (getUserMedia),
              //     la prise de vue se fait DANS la page — ce qui règle le cas
              //     de l'ordinateur de bureau, où un <input capture> n'ouvrait
              //     qu'un explorateur de fichiers ;
              //   • sinon, le champ caché ci-dessous prend le relais et
              //     déclenche l'appareil photo du téléphone.
              // Le bouton naît caché et le script le montre : sans JavaScript,
              // il ne ferait rien, et un bouton mort vaut moins que pas de
              // bouton. Le champ de fichier, lui, marche toujours. ?>
        <div class="piece-depot-champ">
            <span class="lbl-champ">…ou photographier le justificatif</span>
            <button type="button" class="btn ghost" id="piece-photo-ouvrir" hidden><?= icon('camera') ?> Prendre une photo</button>
            <input type="file" accept="image/*" capture="environment" id="piece-photo" data-photo-piece="#piece-photo-data">
        </div>
        <input type="hidden" name="photo" id="piece-photo-data">

        <?php // La prise de vue : flux vidéo, déclencheur, et rien d'autre.
              // Elle n'existe dans le document que pendant la prise — le flux
              // est coupé dès qu'on a l'image ou qu'on renonce, sinon la
              // lampe de la caméra resterait allumée sur une page qu'on a
              // quittée des yeux. ?>
        <div class="piece-camera" id="piece-camera" hidden>
            <video id="piece-camera-flux" playsinline muted></video>
            <div class="piece-camera-actions">
                <button type="button" class="btn" id="piece-camera-prendre"><?= icon('camera') ?> Capturer</button>
                <button type="button" class="btn ghost" id="piece-camera-fermer"><?= icon('x') ?> Annuler</button>
            </div>
        </div>
        <?php // Le message HORS du panneau : un refus de caméra ne doit pas
              // ouvrir un cadre vidéo noir surmontant un « Capturer » qui ne
              // peut rien capturer. On dit ce qui s'est passé, et le champ de
              // fichier reparaît à côté. ?>
        <p class="err small piece-camera-err" id="piece-camera-err" hidden></p>

        <div class="piece-photo-apercu" id="piece-photo-apercu" hidden>
            <img alt="Aperçu de la photo" id="piece-photo-img">
            <button type="button" class="btn ghost btn-sm" id="piece-photo-retirer"><?= icon('x') ?> Retirer</button>
        </div>
    </div>

    <h3 class="sub">Ce que c'est</h3>
    <?php // Avec LIBELLÉ : quatre feuilles de papier se ressemblent trop pour
          // qu'une icône seule dise « facture reçue » plutôt que « quittance ».
          // Le dessin accompagne le mot, il ne le remplace pas. ?>
    <?= icon_picker('type', array_map(fn ($t) => ['icone' => $t['icone'], 'label' => $t['libelle']], PIECE_TYPES), $type, 'Type de pièce', ['avecLabel' => true]) ?>

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

    <?php // Le bénéficiaire ne concerne QUE la quittance : c'est le seul type
          // de pièce que Lasso produit, et le seul qui s'adresse à quelqu'un.
          // Affiché pour tous mais masqué par le script tant que le type n'est
          // pas « quittance » — un champ qui apparaît et disparaît au bon
          // moment vaut mieux qu'un champ toujours là qui ne sert jamais. ?>
    <div class="piece-champ-quittance mt-16" id="piece-beneficiaire" <?= $type === 'quittance' ? '' : 'hidden' ?>>
        <label><span>Bénéficiaire <?= info_tip(
            'À qui la quittance est remise : une structure du carnet, ou une personne. '
            . "À défaut, le « Tiers » saisi plus haut sert de nom sur le document."
        ) ?></span>
            <select name="beneficiaire">
                <option value="">— Le tiers saisi plus haut —</option>
                <?php $grpCourant = null; foreach ($beneficiaires as $b): ?>
                    <?php if ($b['groupe'] !== $grpCourant): $grpCourant = $b['groupe']; ?>
                        <?php if ($grpCourant !== null): ?></optgroup><?php endif; ?>
                        <optgroup label="<?= e($b['groupe']) ?>">
                    <?php endif; ?>
                    <option value="<?= e($b['val']) ?>"<?= $benefCourant === $b['val'] ? ' selected' : '' ?>><?= e($b['libelle']) ?></option>
                <?php endforeach; ?>
                <?php if ($grpCourant !== null): ?></optgroup><?php endif; ?>
            </select>
        </label>
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

    <label class="mt-16">Remarques <textarea name="notes" rows="2"><?= e($v('notes')) ?></textarea></label>
</form>
</div></div>
<?php // Le bénéficiaire suit le type choisi. Sans script le champ reste
      // visible et fonctionne : il ne sert simplement à rien sur une facture
      // reçue, et le serveur l'ignore (SPEC_PIECES.md § 10). ?>
<script nonce="<?= e(csp_nonce()) ?>">
(() => {
    const bloc = document.getElementById('piece-beneficiaire');
    if (!bloc) return;
    const suivre = () => {
        const coche = document.querySelector('input[name="type"]:checked');
        bloc.hidden = !coche || coche.value !== 'quittance';
    };
    document.querySelectorAll('input[name="type"]').forEach(r => r.addEventListener('change', suivre));
    suivre();
})();
</script>
