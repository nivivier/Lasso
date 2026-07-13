<?php
/** @var ?array $facture */ /** @var int $id */ /** @var array $debiteurs */ /** @var array $comptes */
/** @var array $axes */ /** @var int $delaiDefaut */ /** @var ?int $evenementId */ /** @var ?int $axeDefautEvenement */
/** @var ?int $debiteurDefautEvenement */ /** @var ?string $err */ /** @var ?array $post */
$edit = $id > 0;
$pv = fn(string $k, $d = '') => e((string) ($post[$k] ?? $d));

// Options de l'axe analytique (select par ligne)
$axeOpts = options_axes($axes);

// Lignes initiales : depuis la facture existante, ou repopulation après erreur, ou une ligne vide.
$lignesInit = [];
if (!empty($post['l_description'])) {
    foreach ($post['l_description'] as $i => $desc) {
        $lignesInit[] = [
            'description' => (string) $desc,
            'quantite'    => (string) ($post['l_quantite'][$i] ?? ''),
            'prix'        => (string) ($post['l_prix'][$i] ?? ''),
            'axe'         => (string) ($post['l_axe'][$i] ?? ''),
        ];
    }
} elseif ($edit && !empty($facture)) {
    foreach (facturation_lignes_de($id) as $l) {
        $lignesInit[] = [
            'description' => (string) $l['description'],
            'quantite'    => nombre_court((float) $l['quantite']),
            'prix'        => (string) $l['prix_unitaire'],
            'axe'         => (string) ($l['axe_analytique_id'] ?? ''),
        ];
    }
}
if (!$lignesInit) {
    // Facture créée depuis un événement : axe de la carte « Comptabilité
    // analytique » présélectionné, modifiable comme n'importe quelle ligne.
    $lignesInit[] = ['description' => '', 'quantite' => '1', 'prix' => '', 'axe' => (string) ($axeDefautEvenement ?? '')];
}

$renderRow = function (array $l) use ($axes, $axeOpts) {
    $axeSel = $axes
        ? '<select name="l_axe[]" class="l-axe" title="Axe analytique">' . preselectionner_option($axeOpts, $l['axe']) . '</select>'
        : '';
    return '<div class="ligne-row">'
        . '<input name="l_description[]" class="l-desc" type="text" placeholder="Description" value="' . e($l['description']) . '">'
        . '<input name="l_quantite[]" class="l-qte" type="text" inputmode="decimal" placeholder="quantité" value="' . e($l['quantite']) . '">'
        . '<input name="l_prix[]" class="l-prix" type="text" inputmode="decimal" placeholder="prix unit. CHF" value="' . e($l['prix']) . '">'
        . $axeSel
        . '<span class="l-sub muted"></span>'
        . '<button type="button" class="btn ghost btn-sm l-del" aria-label="Supprimer la ligne">✕</button>'
        . '</div>';
};
?>
<?php $debiteurCourant = (string) ($post['debiteur_id'] ?? ($facture['debiteur_id'] ?? ($debiteurDefautEvenement ?: ''))); ?>
<?php $nouveauDebiteur = $debiteurCourant === '__new__'; ?>
<?= lien_retour('?p=facturation_liste', 'Facturation') ?>
<div class="page-head">
    <h1><?= $edit ? 'Modifier la facture' : 'Nouvelle facture' ?></h1>
</div>

<?php if (!$comptes): ?>
    <p class="muted">Aucun compte bancaire. Ajoutez-en un dans <a href="?p=compta_comptes">Comptes bancaires</a> d'abord.</p>
<?php else: ?>
<?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>

<form method="post" action="?p=facturation_form" class="card form">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $id ?>"><?php endif; ?>
    <?php if ($evenementId): ?>
        <input type="hidden" name="evenement_id" value="<?= (int) $evenementId ?>">
        <p class="muted small">Facture liée à <a href="?p=evenement&id=<?= (int) $evenementId ?>">l'événement</a>.</p>
    <?php endif; ?>

    <div class="grid2">
        <label>Débiteur
            <select name="debiteur_id" id="debiteur-select" required>
                <option value="">— choisir —</option>
                <option value="__new__" <?= $nouveauDebiteur ? 'selected' : '' ?>>+ Nouveau débiteur</option>
                <?php foreach ($debiteurs as $d): ?>
                    <option value="<?= (int) $d['id'] ?>"
                        <?= $debiteurCourant === (string) $d['id'] ? 'selected' : '' ?>>
                        <?= e($d['nom']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Compte bancaire créancier
            <select name="compte_bancaire_id" required>
                <option value="">— choisir —</option>
                <?php foreach ($comptes as $c): ?>
                    <option value="<?= (int) $c['id'] ?>"
                        <?= (string) ($post['compte_bancaire_id'] ?? ($facture['compte_bancaire_id'] ?? '')) === (string) $c['id'] ? 'selected' : '' ?>>
                        <?= e($c['libelle']) ?><?= $c['iban'] ? ' — ' . e($c['iban']) : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>

    <div id="nouveau-debiteur" <?= $nouveauDebiteur ? '' : 'hidden' ?>>
        <h3 class="sub">Nouveau débiteur</h3>
        <div class="grid2">
            <label>Nom / raison sociale <input name="nd_nom" value="<?= $pv('nd_nom') ?>"></label>
            <label>Type
                <select name="nd_type">
                    <option value="organisation" <?= ($post['nd_type'] ?? 'organisation') === 'organisation' ? 'selected' : '' ?>>Organisation</option>
                    <option value="particulier" <?= ($post['nd_type'] ?? '') === 'particulier' ? 'selected' : '' ?>>Particulier</option>
                </select>
            </label>
        </div>
        <div class="grid3">
            <label>Rue et numéro <input name="nd_adresse_rue" value="<?= $pv('nd_adresse_rue') ?>"></label>
            <label>NPA <input name="nd_adresse_npa" value="<?= $pv('nd_adresse_npa') ?>"></label>
            <label>Localité <input name="nd_adresse_localite" value="<?= $pv('nd_adresse_localite') ?>"></label>
        </div>
        <div class="grid2">
            <label>Pays <input name="nd_adresse_pays" value="<?= $pv('nd_adresse_pays', 'Suisse') ?>"></label>
            <label>E-mail (optionnel) <input name="nd_email" type="email" value="<?= $pv('nd_email') ?>"></label>
        </div>
    </div>

    <h3 class="sub">Lignes <?= info_tip('Description, quantité et prix unitaire. Axe analytique optionnel, par ligne.') ?></h3>
    <div id="lignes">
        <?php foreach ($lignesInit as $l) echo $renderRow($l); ?>
    </div>
    <div class="lignes-foot">
        <button type="button" class="btn ghost btn-sm" id="add-ligne">+ Ajouter une ligne</button>
        <span class="total-h">Total : <strong id="total-chf">0.00</strong> CHF</span>
    </div>

    <div class="grid2 mt-18">
        <label>Délai de paiement (jours)
            <input name="delai_jours" type="number" min="1"
                   value="<?= $pv('delai_jours', (string) ($facture['delai_jours'] ?? $delaiDefaut)) ?>">
        </label>
    </div>
    <label>Communication (optionnel, imprimée sur la facture)
        <textarea name="communication" rows="2"><?= $pv('communication', (string) ($facture['communication'] ?? '')) ?></textarea>
    </label>

    <div class="form-actions">
        <button type="submit"><?= icon('save') ?> Enregistrer le brouillon</button>
        <a class="btn ghost" href="?p=facturation_liste">Annuler</a>
    </div>
</form>

<template id="ligne-tpl"><?= $renderRow(['description' => '', 'quantite' => '1', 'prix' => '', 'axe' => '']) ?></template>

<script>
(function () {
    const debiteurSelect = document.getElementById('debiteur-select');
    const nouveauDebiteur = document.getElementById('nouveau-debiteur');
    debiteurSelect.addEventListener('change', () => {
        nouveauDebiteur.hidden = debiteurSelect.value !== '__new__';
    });

    const lignes = document.getElementById('lignes');
    const tpl = document.getElementById('ligne-tpl');
    const totC = document.getElementById('total-chf');
    function num(v) { return parseFloat((v || '').toString().replace(',', '.')) || 0; }
    function recalc() {
        let chf = 0;
        lignes.querySelectorAll('.ligne-row').forEach(row => {
            const q = num(row.querySelector('.l-qte').value);
            const p = num(row.querySelector('.l-prix').value);
            const montant = q * p;
            row.querySelector('.l-sub').textContent = q > 0 && p > 0 ? ('= ' + (Math.round(montant * 100) / 100).toFixed(2) + ' CHF') : '';
            chf += montant;
        });
        totC.textContent = (Math.round(chf * 100) / 100).toFixed(2);
    }
    lignes.addEventListener('input', recalc);
    lignes.addEventListener('change', recalc);
    lignes.addEventListener('click', e => {
        if (e.target.closest('.l-del')) {
            if (lignes.querySelectorAll('.ligne-row').length > 1) e.target.closest('.ligne-row').remove();
            else { e.target.closest('.ligne-row').querySelectorAll('input').forEach(i => i.value = ''); }
            recalc();
        }
    });
    document.getElementById('add-ligne').addEventListener('click', () => {
        lignes.appendChild(tpl.content.cloneNode(true));
        recalc();
    });
    recalc();
})();
</script>
<?php endif; ?>
