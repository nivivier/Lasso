<?php /** @var ?array $p */ /** @var ?array $champs */ /** @var string $err */ /** @var array $categories */
/** @var ?array $ecrSource */ /** @var array $beneficiaires */ /** @var string $typeDefaut */

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
        ];
        if (isset($depuis[$cle])) { return $depuis[$cle]; }
    }
    return (string) $defaut;
};
$id      = (int) ($p['id'] ?? 0);
$montant = (float) ($champs['montant'] ?? $p['montant'] ?? $ecrSource['montant'] ?? 0);
$sens    = $montant > 0 ? 'recette' : 'depense';
// Le type est la première question, et elle est déjà répondue : on arrive ici
// par une entrée du menu « Nouvelle pièce » (pieces_menu_nouvelle_html()).
// C'est lui qui décide des champs posés — d'où PIECE_TYPES présent tout au
// long de cette vue, et la même table rendue au script plus bas.
$type     = $v('type', $typeDefaut);
$typeInfo = PIECE_TYPES[$type] ?? PIECE_TYPES['autre'];

// Une pièce qui naît payée : le ticket de carte est sorti du compte au moment
// de l'achat, la quittance constate de l'argent déjà reçu. La date du jour s'y
// pose d'office — à la CRÉATION seulement : rouvrir une pièce ne doit jamais
// réécrire ce qu'elle dit.
$datePayee = $v('date_payee');
$payeeAuto = $id === 0 && $champs === null && $datePayee === '' && $typeInfo['paye'];
if ($payeeAuto) { $datePayee = date('Y-m-d'); }
$horsCompte = $v('mode_paiement') === 'hors_compte';

// Le bénéficiaire, dans la forme que porte le sélecteur unique : « s:12 » ou
// « p:34 ». Un seul champ plutôt que deux menus, qui auraient laissé en
// remplir deux et posé la question de qui gagne.
$benefCourant = '';
if ($p !== null) {
    if (!empty($p['structure_id'])) { $benefCourant = 's:' . (int) $p['structure_id']; }
    elseif (!empty($p['personne_id'])) { $benefCourant = 'p:' . (int) $p['personne_id']; }
}
// Le repli « Plus de détails » s'ouvre quand il y a quelque chose à y voir :
// une pièce qu'on modifie, ou une saisie qui vient d'être refusée. Sur une
// création, il reste fermé — c'est sa raison d'être.
$plusOuvert = $id > 0 || $err !== '';
?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>

<div class="module-content"><div class="module-content-inner">
<?= lien_retour_contextuel($id ? '?p=piece&id=' . $id : '?p=pieces', $id ? (string) $p['libelle'] : 'Pièces') ?>

<?php if ($err !== ''): ?><p class="err flash"><?= e($err) ?></p><?php endif; ?>

<div class="page-head">
    <div class="page-head-title"><h1><?= $id ? 'Modifier la pièce' : e($typeInfo['nouveau']) ?></h1></div>
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

    <?php // Premier temps : le document. C'est ce qu'on a sous la main, et tout
          // le reste se lit dessus. ?>
    <h3 class="sub no-mt">Le justificatif</h3>
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

    <?php // Second temps : les quatre champs qu'on lit sur le document. Tout le
          // reste attend derrière « Plus de détails » — sur dix pièces déposées,
          // neuf n'en auront jamais besoin. ?>
    <h3 class="sub">L'essentiel</h3>
    <div class="grid2">
        <?php // Le tiers avant l'intitulé : c'est le nom qu'on lit en haut du
              // document, et il suffit souvent à nommer la pièce — une pièce
              // sans intitulé reprend son tiers à l'enregistrement. ?>
        <label>Tiers <input name="tiers" value="<?= e($v('tiers')) ?>" placeholder="ex. Swisscom" autofocus></label>
        <label>Intitulé <input name="libelle" value="<?= e($v('libelle')) ?>" placeholder="ex. Facture Swisscom janvier"></label>
    </div>

    <div class="grid2 mt-16" id="piece-ligne-montant">
        <label><span>Montant</span>
            <span class="pct-input">
                <input name="montant" type="text" inputmode="decimal" required
                       value="<?= $montant != 0.0 ? e(number_format(abs($montant), 2, '.', '')) : '' ?>">
                <span class="pct-suffix">CHF</span>
            </span>
        </label>
        <?php // Le sens n'est posé que pour « Autre » : une facture reçue est
              // une dépense, une quittance une recette, et PIECE_TYPES le sait
              // (piece_montant_poste()). Le demander partout, c'était faire
              // choisir ce qu'on savait déjà — et ouvrir la porte à une facture
              // reçue enregistrée en recette. ?>
        <label id="piece-sens"<?= $typeInfo['sens'] === '' ? '' : ' hidden' ?>><span>Sens</span>
            <select name="sens">
                <option value="depense"<?= $sens === 'depense' ? ' selected' : '' ?>>Dépense (argent qui sort)</option>
                <option value="recette"<?= $sens === 'recette' ? ' selected' : '' ?>>Recette (argent qui entre)</option>
            </select>
        </label>
        <label>Date de la pièce <input type="date" name="date_piece" value="<?= e($v('date_piece') ?: date('Y-m-d')) ?>"></label>
    </div>

    <?php // Une pièce qui naît payée le dit : sans cette ligne, « payée
          // aujourd'hui » se déciderait derrière un repli fermé, et rien ne
          // dirait pourquoi la pièce ne figure pas dans « à payer ». ?>
    <p class="muted small mt-8" id="piece-paye-note"<?= $payeeAuto ? '' : ' hidden' ?>>
        <?= icon('check') ?> Marquée payée aujourd'hui — modifiable ci-dessous.
    </p>

    <details class="form-plus mt-16" id="piece-plus"<?= $plusOuvert ? ' open' : '' ?>>
        <summary>Plus de détails</summary>
        <div class="form-plus-corps">
            <?php // Le type a été choisi dans le menu : il se change ici, et non
                  // sur le chemin courant. Avec LIBELLÉ — cinq feuilles de
                  // papier se ressemblent trop pour qu'une icône seule dise
                  // « facture reçue » plutôt que « quittance ». ?>
            <span class="lbl-champ">Type de pièce</span>
            <?= icon_picker('type', array_map(fn ($t) => ['icone' => $t['icone'], 'label' => $t['libelle']], PIECE_TYPES), $type, 'Type de pièce', ['avecLabel' => true]) ?>

            <div class="grid2 mt-16">
                <label id="piece-echeance"<?= $typeInfo['echeance'] ? '' : ' hidden' ?>><span>Échéance</span>
                    <input type="date" name="date_echeance" value="<?= e($v('date_echeance')) ?>"></label>
                <label><span>Catégorie présumée</span>
                    <select name="plan_compte_id">
                        <option value="">— Aucune —</option>
                        <?php foreach ($categories as $c): ?>
                        <option value="<?= (int) $c['id'] ?>"<?= (int) $v('plan_compte_id') === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['chemin']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <?php // Un seul champ pour deux questions, parce que c'est la même
                  // donnée : qui est en face. Une quittance a un bénéficiaire,
                  // une note de frais quelqu'un à rembourser — seul l'intitulé
                  // change (PIECE_TYPES['tiersPour']). ?>
            <div class="mt-16" id="piece-beneficiaire"<?= $typeInfo['tiersPour'] !== '' ? '' : ' hidden' ?>>
                <label><span id="piece-beneficiaire-lbl"><?= e($typeInfo['tiersPour'] ?: 'Bénéficiaire') ?></span>
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

            <h3 class="sub">Le paiement</h3>
            <div class="grid2">
                <label>Payée le <input type="date" name="date_payee" id="piece-date-payee"
                                       value="<?= e($datePayee) ?>"<?= $payeeAuto ? ' data-auto="1"' : '' ?>></label>
                <?php // Une case et non un menu de deux lignes : le cas courant
                      // est le compte suivi, et l'exception se dit mieux cochée.
                      // Elle porte à conséquence — « hors du compte » veut dire
                      // qu'aucune écriture ne viendra jamais, et c'est à ce
                      // titre qu'elle classe la pièce (piece_etat()). ?>
                <label class="check piece-hors-compte">
                    <input type="checkbox" name="hors_compte" value="1"<?= $horsCompte ? ' checked' : '' ?>>
                    Payée hors des comptes suivis (espèces, autre banque…)
                </label>
            </div>

            <label class="mt-16">Remarques <textarea name="notes" rows="2"><?= e($v('notes')) ?></textarea></label>
        </div>
    </details>
</form>
</div></div>
<?php // Les champs que le type commande. Sans script, tout reste visible et le
      // formulaire fonctionne : le serveur, lui, tranche de toute façon depuis
      // PIECE_TYPES (piece_montant_poste(), piece_champs_postes()).
      //
      // La table vient du PHP plutôt que d'être recopiée ici : un type ajouté
      // dans lib/pieces.php ne doit pas obliger à se souvenir de ce fichier. ?>
<script nonce="<?= e(csp_nonce()) ?>">
(() => {
    const TYPES = <?= json_encode(array_map(fn ($t) => [
        'echeance'  => (bool) $t['echeance'],
        'sens'      => $t['sens'],
        'paye'      => (bool) $t['paye'],
        'tiersPour' => $t['tiersPour'],
    ], PIECE_TYPES), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const creation = <?= $id ? 'false' : 'true' ?>;
    const aujourdhui = <?= json_encode(date('Y-m-d')) ?>;

    const ligne  = document.getElementById('piece-ligne-montant');
    const sens   = document.getElementById('piece-sens');
    const ech    = document.getElementById('piece-echeance');
    const benef  = document.getElementById('piece-beneficiaire');
    const benefL = document.getElementById('piece-beneficiaire-lbl');
    const payee  = document.getElementById('piece-date-payee');
    const note   = document.getElementById('piece-paye-note');
    if (!ligne || !payee) return;

    const suivre = () => {
        const coche = document.querySelector('input[name="type"]:checked');
        const t = (coche && TYPES[coche.value]) || TYPES.autre;

        // Le sens ne paraît que pour « Autre » ; la rangée passe alors à trois
        // colonnes, sinon le troisième champ tomberait seul sur une ligne.
        sens.hidden = t.sens !== '';
        ligne.classList.toggle('grid3', t.sens === '');
        ligne.classList.toggle('grid2', t.sens !== '');

        ech.hidden = !t.echeance;
        benef.hidden = t.tiersPour === '';
        if (t.tiersPour !== '') { benefL.textContent = t.tiersPour; }

        // « Payée d'office » suit le type tant que la date n'a pas été touchée
        // à la main (data-auto) : changer d'avis sur le type ne doit pas
        // laisser derrière soi une date que plus rien ne justifie — ni effacer
        // une date saisie exprès.
        if (creation && (payee.value === '' || payee.dataset.auto === '1')) {
            payee.value = t.paye ? aujourdhui : '';
            payee.dataset.auto = t.paye ? '1' : '';
            note.hidden = !t.paye;
        } else {
            note.hidden = true;
        }
    };
    document.querySelectorAll('input[name="type"]').forEach(r => r.addEventListener('change', suivre));
    payee.addEventListener('input', () => { payee.dataset.auto = ''; note.hidden = true; });
    suivre();
})();
</script>
