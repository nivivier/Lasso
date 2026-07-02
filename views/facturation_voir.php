<?php
/** @var array $facture */ /** @var array $lignes */ /** @var string $statutEffectif */ /** @var ?string $saved */
/** @var array $ecrituresLibres */
$f = $facture;
$brouillon = $f['statut'] === 'brouillon';
$peutAnnuler = !in_array($f['statut'], ['payee', 'annulee'], true);
$peutEmail = !$brouillon && filter_var($f['debiteur_email'] ?? '', FILTER_VALIDATE_EMAIL);
$aDesAxes = (bool) array_filter($lignes, fn($l) => $l['axe_analytique_id']);
$numeroAffiche = $f['numero'] !== '' ? e($f['numero']) : '(brouillon)';

// Paiement manuel : écriture bancaire à lier (voir route_facture_payee()).
$peutPayer = in_array($f['statut'], ['emise', 'payee'], true);
$libelleEcr = fn(array $e): string => date('d.m.Y', strtotime($e['date_op'])) . ' — ' . chf((float) $e['montant']) . ' CHF — ' . mb_substr((string) $e['texte'], 0, 50);
$ecritureActuelleId = (int) ($f['ecriture_id'] ?? 0);
$ecritureActuelle = array_values(array_filter($ecrituresLibres, fn($e) => (int) $e['id'] === $ecritureActuelleId));
$ecritureActuelleLabel = $ecritureActuelle ? $libelleEcr($ecritureActuelle[0]) : '';
?>
<?php if (($saved ?? null) === 'emise'): ?><p class="ok flash">Facture émise.</p><?php endif; ?>
<?php if (($saved ?? null) === 'payee'): ?><p class="ok flash">Facture marquée comme payée.</p><?php endif; ?>
<?php switch ($_GET['mail'] ?? null) {
    case 'ok':  echo '<p class="ok flash">Facture envoyée par e-mail.</p>'; break;
    case 'err': echo '<p class="err flash">L\'envoi de l\'e-mail a échoué. Réessayez plus tard.</p>'; break;
} ?>
<?php switch ($_GET['err'] ?? null) {
    case 'compte':   echo '<p class="err flash">Choisissez un compte bancaire créancier avant d\'émettre.</p>'; break;
    case 'lignes':   echo '<p class="err flash">La facture doit avoir au moins une ligne.</p>'; break;
    case 'emission': echo '<p class="err flash">L\'émission a échoué (numéro ou référence de paiement invalide). Réessayez.</p>'; break;
    case 'pdf':      echo '<p class="err flash">La génération du PDF a échoué (vérifiez l\'IBAN et l\'adresse du débiteur).</p>'; break;
} ?>
<?= lien_retour('?p=facturation_liste', 'Facturation') ?>
<div class="page-head">
    <h1>Facture <?= $numeroAffiche ?></h1>
    <div class="head-actions">
        <?php if ($brouillon): ?>
            <a class="btn ghost" href="?p=facturation_form&id=<?= (int) $f['id'] ?>"><?= icon('pencil') ?> <span class="lbl">Modifier</span></a>
            <form method="post" action="?p=facture_emettre" class="d-inline" onsubmit="return confirm('Émettre cette facture ? Le numéro et la référence de paiement seront figés.');">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                <button type="submit" class="btn"><?= icon('check') ?> <span class="lbl">Émettre</span></button>
            </form>
            <form method="post" action="?p=facture_delete" class="d-inline" onsubmit="return confirm('Supprimer ce brouillon ?');">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                <button type="submit" class="btn danger icon-only" title="Supprimer" aria-label="Supprimer"><?= icon('trash') ?></button>
            </form>
        <?php else: ?>
            <a class="btn ghost" href="?p=facture_pdf&id=<?= (int) $f['id'] ?>" data-preview target="_blank" title="Aperçu / PDF"><?= icon('eye') ?> <span class="lbl">PDF</span></a>
            <?php if ($statutEffectif === 'en_retard'): ?>
                <a class="btn ghost" href="?p=facture_rappel&id=<?= (int) $f['id'] ?>" data-preview target="_blank"><?= icon('mail') ?> <span class="lbl">Lettre de rappel</span></a>
            <?php endif; ?>
            <?php if ($peutEmail): ?>
                <form method="post" action="?p=facture_email" class="d-inline" onsubmit="return confirm('Envoyer cette facture par e-mail à <?= e($f['debiteur_email']) ?> ?');">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                    <button type="submit" class="btn" title="Envoyer par e-mail"><?= icon('mail') ?> <span class="lbl">Envoyer</span></button>
                </form>
            <?php endif; ?>
            <?php if ($peutAnnuler): ?>
                <form method="post" action="?p=facture_annuler" class="d-inline" onsubmit="return confirm('Annuler cette facture ?');">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                    <button type="submit" class="btn danger">Annuler</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div class="fiche-wrapper">
<div class="fiche-main">
<div class="card">
    <div class="facture-head-row">
        <?php $logoFacture = param_logo('clair'); ?>
        <?php if ($logoFacture !== ''): ?>
            <div class="ps-head"><img src="<?= e($logoFacture) ?>" alt="" class="ps-logo"></div>
        <?php endif; ?>
        <p class="facture-statut">
            <?= facturation_badge($f) ?>
            <?php if (trim((string) ($f['envoyee_le'] ?? '')) !== ''): ?>
                <span class="mail-sent" title="Envoyée le <?= e(date('d.m.Y à H:i', strtotime($f['envoyee_le']))) ?>"><?= icon('check') ?> Envoyée</span>
            <?php endif; ?>
        </p>
    </div>
    <div class="ps-title">
        <h2>Facture</h2>
        <div class="ps-period"><?= $numeroAffiche ?></div>
    </div>
    <div class="ps-parties mb-24">
        <div>
            <h3>Émise par</h3>
            <p><strong><?= e(param('employeur_nom')) ?></strong><br>
                <?= e(param('employeur_rue')) ?><br>
                <?= e(param('employeur_npa')) ?></p>
        </div>
        <div>
            <h3>Débiteur</h3>
            <p><strong><a href="?p=debiteur&id=<?= (int) $f['debiteur_id'] ?>"><?= e($f['debiteur_nom']) ?></a></strong><br>
                <?= e($f['adresse_rue']) ?><?= $f['adresse_rue'] ? '<br>' : '' ?>
                <?= e(trim($f['adresse_npa'] . ' ' . $f['adresse_localite'])) ?></p>
        </div>
    </div>
    <p class="muted small">
        Émission : <?= $f['date_emission'] !== '' ? e(date('d.m.Y', strtotime($f['date_emission']))) : '—' ?><br>
        Échéance : <?= $f['date_echeance'] !== '' ? e(date('d.m.Y', strtotime($f['date_echeance']))) : '—' ?><br>
        Compte : <?= e($f['compte_libelle'] ?? '—') ?><br>
        <?php if ($f['reference_paiement'] !== ''): ?>Référence : <?= e($f['reference_paiement']) ?><br><?php endif; ?>
    </p>

    <?php if (trim((string) $f['communication']) !== ''): ?>
        <p><strong><?= nl2br(e($f['communication'])) ?></strong></p>
    <?php endif; ?>

    <table class="list">
        <thead><tr><th>Description</th><th class="num">Qté</th><th class="num">Prix unit.</th><th class="num">Montant</th><?php if ($aDesAxes): ?><th>Axe</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($lignes as $l): ?>
            <tr>
                <td><?= e($l['description']) ?></td>
                <td class="num"><?= nombre_court((float) $l['quantite']) ?></td>
                <td class="num"><?= chf((float) $l['prix_unitaire']) ?></td>
                <td class="num"><?= chf((float) $l['montant']) ?></td>
                <?php if ($aDesAxes): ?>
                    <td class="muted small"><?= e($l['axe_code'] ?: $l['axe_libelle'] ?: '—') ?></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="3" class="num strong">Total</td><td class="num strong"><?= chf((float) $f['montant_total']) ?></td><?php if ($aDesAxes): ?><td></td><?php endif; ?></tr></tfoot>
    </table>
</div>
</div>
<?php if ($peutPayer): ?>
<aside class="fiche-aside facture-aside">
    <form method="post" action="?p=facture_payee" class="paiement-form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
        <h2>Paiement</h2>
        <input type="date" name="payee_le" id="payee-le" value="<?= e($f['payee_le']) ?>" class="paiement-date">
        <?php if ($ecrituresLibres): ?>
            <div class="ecr-liee-box">
                <h3 class="sub no-mt">Écriture liée</h3>
                <div class="cat-search ecr-search">
                    <input type="text" class="cat-search-input" id="ecriture-recherche" placeholder="— aucune, juste marquer payée —" autocomplete="off" value="<?= e($ecritureActuelleLabel) ?>">
                    <input type="hidden" name="ecriture_id" id="ecriture-select" value="<?= $ecritureActuelleId ?: '' ?>">
                    <ul class="cat-search-list" hidden role="listbox">
                        <li data-val="">— aucune, juste marquer payée —</li>
                        <?php foreach ($ecrituresLibres as $e):
                            $libelle = $libelleEcr($e);
                            $ligneHaut = date('d.m.Y', strtotime($e['date_op'])) . ' — ' . chf((float) $e['montant']) . ' CHF';
                        ?>
                            <li data-val="<?= (int) $e['id'] ?>"
                                data-montant="<?= (float) $e['montant'] ?>"
                                data-date="<?= e($e['date_op']) ?>"
                                data-label="<?= e($libelle) ?>"
                                data-recherche="<?= e(mb_strtolower($libelle, 'UTF-8')) ?>">
                                <span class="ecr-opt-top"><?= e($ligneHaut) ?></span>
                                <span class="ecr-opt-texte"><?= e(mb_substr((string) $e['texte'], 0, 90)) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <label class="check small">
                    <input type="checkbox" id="ecriture-meme-montant" checked>
                    Montant exact (<?= chf((float) $f['montant_total']) ?> CHF)
                </label>
                <p class="muted small" id="ecriture-compte"></p>
            </div>
        <?php endif; ?>
        <button type="submit" class="btn"><?= icon('check') ?> <?= $f['statut'] === 'payee' ? 'Enregistrer' : 'Marquer comme payée' ?></button>
    </form>
</aside>
<?php endif; ?>
</div>
<?php if ($peutPayer && $ecrituresLibres): ?>
<script>
(function () {
    const wrap = document.querySelector('.ecr-search');
    const input = wrap.querySelector('.cat-search-input');
    const hidden = document.getElementById('ecriture-select');
    const list = wrap.querySelector('.cat-search-list');
    const items = Array.from(list.querySelectorAll('li'));
    const memeMontant = document.getElementById('ecriture-meme-montant');
    const compteur = document.getElementById('ecriture-compte');
    const datePaiement = document.getElementById('payee-le');
    const montantFacture = <?= json_encode(round((float) $f['montant_total'], 2)) ?>;

    function filtrer(q) {
        if (q === undefined) { q = input.value.trim().toLowerCase(); }
        let visibles = 0;
        items.forEach(li => {
            if (li.dataset.val === '') { return; } // « aucune » toujours visible
            const okTexte = !q || li.dataset.recherche.includes(q);
            const okMontant = !memeMontant.checked || Math.abs(parseFloat(li.dataset.montant) - montantFacture) < 0.01;
            li.hidden = !(okTexte && okMontant);
            if (!li.hidden) visibles++;
        });
        compteur.textContent = visibles + ' écriture(s) correspondante(s) sur ' + (items.length - 1) + '.';
    }
    input.addEventListener('focus', () => { input.value = ''; filtrer(''); list.hidden = false; });
    input.addEventListener('input',  () => { filtrer(); list.hidden = false; });
    input.addEventListener('blur', () => {
        setTimeout(() => {
            list.hidden = true;
            const cur = items.find(li => li.dataset.val === hidden.value);
            input.value = cur && cur.dataset.val !== '' ? cur.dataset.label : '';
        }, 150);
    });
    items.forEach(li => {
        li.addEventListener('mousedown', e => {
            e.preventDefault();
            hidden.value = li.dataset.val;
            input.value = li.dataset.val !== '' ? li.dataset.label : '';
            if (li.dataset.date) { datePaiement.value = li.dataset.date; }
            list.hidden = true;
        });
    });
    memeMontant.addEventListener('change', () => filtrer());
    filtrer(''); // état initial : reflète la case « Montant exact » sans tenir compte du libellé pré-rempli
})();
</script>
<?php endif; ?>
