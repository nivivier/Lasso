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
// long de cette vue.
$type     = $v('type', $typeDefaut);
$typeInfo = PIECE_TYPES[$type] ?? PIECE_TYPES['autre'];
// À la CRÉATION, le type ne se redemande pas : il vient d'être choisi dans le
// menu, le titre de la page le dit, et un champ posé là ne ferait que reposer
// une question déjà tranchée — il part en champ caché.
// En MODIFICATION, il n'y a pas eu de menu : le sélecteur reste, sans quoi
// corriger un type mal choisi obligerait à supprimer la pièce et à redéposer
// son fichier.
// ⚠️ Sauf sur une quittance DÉJÀ NUMÉROTÉE : son numéro vient d'une série sans
// trou et le document a pu partir. Elle cesse donc d'être transformable, et le
// type repart en champ caché comme à la création. Même garde côté serveur,
// dans route_piece_form() — c'est elle qui fait foi.
$quittanceFigee = $id > 0 && (string) ($p['type'] ?? '') === 'quittance' && (string) ($p['numero'] ?? '') !== '';
$typeModifiable = $id > 0 && !$quittanceFigee;

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

<div class="page-head page-head-bande">
    <div class="page-head-title"><h1><?= $id ? 'Modifier la pièce' : e($typeInfo['nouveau']) ?></h1></div>
    <?php // La suppression vit ICI, sur l'écran qui modifie la pièce, et pas sur
          // celui qui la consulte (docs/UI.md § 3) — même place que pour une
          // facture ou un employé. Rien à la création : il n'y a encore rien à
          // détruire. Le message dit ce qui part AVEC la ligne : le fichier.
          // Sans droit d'écriture, la route refuserait de toute façon. ?>
    <?php ob_start(); ?>
        <?php if ($id): ?>
        <form method="post" action="?p=piece_supprimer" class="d-inline"
              data-confirm="Supprimer cette pièce ? Le fichier joint sera effacé du serveur.">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= $id ?>">
            <button type="submit" class="btn danger icon-only" title="Supprimer" aria-label="Supprimer la pièce"><?= icon('trash') ?></button>
        </form>
        <?php endif; ?>
    <?= entete_form_actions_html('piece-form', $id ? '?p=piece&id=' . $id : '?p=pieces', ['avant' => (string) ob_get_clean()]) ?>
</div>

<?php // enctype obligatoire : sans lui, $_FILES reste vide et le fichier part
      // en silence — l'erreur ne se voit qu'à l'enregistrement, quand rien
      // n'est joint. ?>
<form method="post" action="?p=piece_form" id="piece-form" class="card form" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <?php if ($id): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
    <?php // Sans ce champ, piece_champs_postes() retomberait sur « facture
          // reçue » et toute quittance déposée en serait devenue une. ?>
    <?php if (!$typeModifiable): ?><input type="hidden" name="type" value="<?= e($type) ?>"><?php endif; ?>
    <?php if ($depuisEcr): ?>
    <input type="hidden" name="ecriture_id" value="<?= (int) $ecrSource['id'] ?>">
    <p class="muted small mb-16">
        <?= icon('banknote') ?> Sera rattachée à l'écriture du
        <?= e(date('d.m.Y', strtotime((string) $ecrSource['date_op']))) ?>,
        <?= e(chf((float) $ecrSource['montant'])) ?> — <?= e(mb_substr((string) $ecrSource['texte'], 0, 60, 'UTF-8')) ?>
    </p>
    <?php endif; ?>

    <?php // Le document d'abord : c'est ce qu'on a sous la main, et tout le
          // reste se lit dessus. Sans intertitre — « Le justificatif » au-dessus
          // d'un champ qui dit déjà « Un fichier » ne faisait que répéter, et
          // deux titres pour cinq champs pesaient plus que ce qu'ils
          // annonçaient. ?>
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

    <?php // Les quatre champs qu'on lit sur le document, sur UNE rangée : ils
          // sont courts, et empilés ils donnaient quatre lignes pour ce qui en
          // tient sur une. Chacun déclare sa largeur souhaitée et passe à la
          // ligne quand la place manque — c'est la largeur de la carte qui
          // décide, pas un point de rupture codé en dur (docs/UI.md § 10).
          //
          // Le tiers avant l'intitulé : c'est le nom qu'on lit en haut du
          // document, et il suffit souvent à nommer la pièce — une pièce sans
          // intitulé reprend son tiers à l'enregistrement. ?>
    <div class="champs-rangee mt-16">
        <label class="pf-tiers">Tiers <input name="tiers" value="<?= e($v('tiers')) ?>" placeholder="ex. Swisscom" autofocus></label>
        <label class="pf-libelle">Intitulé <input name="libelle" value="<?= e($v('libelle')) ?>" placeholder="ex. Facture Swisscom janvier"></label>
        <label class="pf-montant"><span>Montant</span>
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
        <?php if ($typeInfo['sens'] === '' || $typeModifiable): ?>
        <label class="pf-sens" id="piece-sens"<?= $typeInfo['sens'] === '' ? '' : ' hidden' ?>><span>Sens</span>
            <select name="sens">
                <option value="depense"<?= $sens === 'depense' ? ' selected' : '' ?>>Dépense (argent qui sort)</option>
                <option value="recette"<?= $sens === 'recette' ? ' selected' : '' ?>>Recette (argent qui entre)</option>
            </select>
        </label>
        <?php endif; ?>
        <?php // Une date n'a pas de longueur variable : son champ prend la
              // place de « jj.mm.aaaa » et du calendrier, pas une colonne. ?>
        <label class="pf-date">Date <input type="date" name="date_piece" value="<?= e($v('date_piece') ?: date('Y-m-d')) ?>"></label>
    </div>

    <?php // Un seul champ pour deux questions, parce que c'est la même donnée :
          // qui est en face. Une quittance a un bénéficiaire, une note de frais
          // quelqu'un à rembourser — seul l'intitulé change
          // (PIECE_TYPES['tiersPour']).
          //
          // Il est ICI et non derrière « Plus de détails » : sur les deux seuls
          // types qui le posent, c'est une information essentielle — une note
          // de frais qui ne dit pas à qui l'association doit l'argent ne sert à
          // rien. Sur les trois autres, le bloc n'existe pas (ou se cache, le
          // temps qu'on change de type en modification).
          //
          // Cherchable et non déroulant : le carnet compte trois mille
          // personnes, et c'est la mécanique déjà en place pour la catégorie
          // d'une écriture ou le lieu d'un événement (lassoInitCatSearch(),
          // initialisée toute seule par data-cat-search). ?>
    <?php if ($typeInfo['tiersPour'] !== '' || $typeModifiable): ?>
    <div class="mt-16 piece-benef" id="piece-beneficiaire"<?= $typeInfo['tiersPour'] !== '' ? '' : ' hidden' ?>>
        <span class="lbl-champ" id="piece-beneficiaire-lbl"><?= e($typeInfo['tiersPour'] ?: 'Bénéficiaire') ?></span>
        <?php // data-hydrater : en modification, le champ s'ouvre sur le nom
              // déjà choisi plutôt que vide. data-vider-en-saisie : retaper
              // quelque chose efface le choix précédent, ce qui est la seule
              // façon de le RETIRER — à vide, c'est le « Tiers » saisi plus
              // haut qui sert de nom sur le document. ?>
        <div class="cat-search" data-cat-search data-filtre-groupes data-hydrater data-vider-en-saisie>
            <input type="text" class="cat-search-input" autocomplete="off"
                   placeholder="Employé, structure, personne…" aria-label="Chercher un bénéficiaire">
            <input type="hidden" name="beneficiaire" class="cat-search-val" value="<?= e($benefCourant) ?>">
            <ul class="cat-search-list" hidden role="listbox">
                <?php $grpCourant = null; foreach ($beneficiaires as $b): ?>
                    <?php if ($b['groupe'] !== $grpCourant): $grpCourant = $b['groupe']; ?>
                        <li class="cat-search-group"><?= e($grpCourant) ?></li>
                    <?php endif; ?>
                    <li data-val="<?= e($b['val']) ?>"><?= e($b['libelle']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <?php // Une pièce qui naît payée le dit : sans cette ligne, « payée
          // aujourd'hui » se déciderait derrière un repli fermé, et rien ne
          // dirait pourquoi la pièce ne figure pas dans « à payer ». ?>
    <p class="muted small mt-8" id="piece-paye-note"<?= $payeeAuto ? '' : ' hidden' ?>>
        <?= icon('check') ?> Marquée payée aujourd'hui — modifiable ci-dessous.
    </p>

    <details class="form-plus mt-16" id="piece-plus"<?= $plusOuvert ? ' open' : '' ?>>
        <summary>Plus de détails</summary>
        <div class="form-plus-corps">
            <?php // Avec LIBELLÉ : cinq feuilles de papier se ressemblent trop
                  // pour qu'une icône seule dise « facture reçue » plutôt que
                  // « quittance ». ?>
            <?php if ($typeModifiable): ?>
            <span class="lbl-champ">Type de pièce</span>
            <?= icon_picker('type', array_map(fn ($t) => ['icone' => $t['icone'], 'label' => $t['libelle']], PIECE_TYPES), $type, 'Type de pièce', ['avecLabel' => true]) ?>
            <?php elseif ($quittanceFigee): ?>
            <?php // Le sélecteur a disparu : le type doit alors se LIRE, sinon
                  // rien sur cette page ne dit plus ce qu'on modifie — le titre
                  // d'une modification est « Modifier la pièce » pour tous les
                  // types. ?>
            <span class="lbl-champ">Type de pièce</span>
            <p class="muted mt-0"><?= icon($typeInfo['icone']) ?> <?= e($typeInfo['libelle']) ?> <code><?= e((string) $p['numero']) ?></code> — une quittance numérotée ne change plus de type.</p>
            <?php endif; ?>

            <div class="grid2<?= $typeModifiable || $quittanceFigee ? ' mt-16' : '' ?>">
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

            <h3 class="sub">Le paiement</h3>
            <div class="grid2">
                <label>Payée le <input type="date" name="date_payee" value="<?= e($datePayee) ?>"></label>
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
<?php // Les champs que le type commande — utile seulement là où le type peut
      // encore changer, c'est-à-dire quand on MODIFIE une pièce. À la création
      // il est figé, et le serveur a déjà rendu les bons champs : pas de script
      // du tout. Sans JavaScript, tout reste visible et le formulaire
      // fonctionne ; le serveur tranche de toute façon depuis PIECE_TYPES
      // (piece_montant_poste(), piece_champs_postes()).
      //
      // La table vient du PHP plutôt que d'être recopiée ici : un type ajouté
      // dans lib/pieces.php ne doit pas obliger à se souvenir de ce fichier. ?>
<?php if ($typeModifiable): ?>
<script nonce="<?= e(csp_nonce()) ?>">
(() => {
    const TYPES = <?= json_encode(array_map(fn ($t) => [
        'echeance'  => (bool) $t['echeance'],
        'sens'      => $t['sens'],
        'tiersPour' => $t['tiersPour'],
    ], PIECE_TYPES), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    const sens   = document.getElementById('piece-sens');
    const ech    = document.getElementById('piece-echeance');
    const benef  = document.getElementById('piece-beneficiaire');
    const benefL = document.getElementById('piece-beneficiaire-lbl');
    if (!sens || !ech || !benef) return;

    const suivre = () => {
        const coche = document.querySelector('input[name="type"]:checked');
        const t = (coche && TYPES[coche.value]) || TYPES.autre;
        // Le sens ne se demande que pour « Autre » : ailleurs le type le sait.
        sens.hidden = t.sens !== '';
        ech.hidden = !t.echeance;
        benef.hidden = t.tiersPour === '';
        if (t.tiersPour !== '') { benefL.textContent = t.tiersPour; }
    };
    document.querySelectorAll('input[name="type"]').forEach(r => r.addEventListener('change', suivre));
    suivre();
})();
</script>
<?php endif; ?>
