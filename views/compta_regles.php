<?php
/** @var array $regles */ /** @var array $impacts */ /** @var array $feuilles */ /** @var array $comptes */
/** @var string $prefillMotif */ /** @var ?int $prefillCompte */ /** @var bool $saved */ /** @var ?int $test */

$compteOptions = function ($selected) use ($comptes): string {
    $sel  = $selected === null ? '' : (string) $selected;
    $html = '<option value=""' . ($sel === '' ? ' selected' : '') . '>Tous (global)</option>';
    foreach ($comptes as $c) {
        $html .= '<option value="' . (int) $c['id'] . '"' . ($sel === (string) $c['id'] ? ' selected' : '') . '>' . e($c['libelle']) . '</option>';
    }
    return $html;
};

// Composant catégorie cherchable : scrollable ET filtrable en tapant.
$catSearchable = function ($selected, bool $editable = true) use ($feuilles): string {
    $sel   = $selected === null ? '' : (string) $selected;
    if (!$editable) {
        $lib = '';
        foreach ($feuilles as $f) { if ((int) $f['id'] === (int) $sel) { $lib = $f['chemin']; break; } }
        return '<div class="cat-search"><input type="text" class="cat-search-input" value="' . e($lib) . '" disabled></div>';
    }
    $items = '';
    $lib   = '';
    foreach ($feuilles as $f) {
        if ((int) $f['id'] === (int) $sel) { $lib = (string) $f['chemin']; }
        $items .= '<li data-val="' . (int) $f['id'] . '">' . e($f['chemin']) . '</li>';
    }
    // Le libellé est écrit ICI et non rempli au chargement par data-hydrater :
    // « Annuler » réinitialise le formulaire de la carte, et un champ sans
    // valeur par défaut se vide alors — la catégorie disparaissait de l'écran
    // alors qu'elle était toujours enregistrée.
    return '<div class="cat-search" data-cat-search data-texte-vide>'
         . '<input type="text" class="cat-search-input" value="' . e($lib) . '" placeholder="Chercher une catégorie…" autocomplete="off">'
         . '<input type="hidden" name="plan_compte_id" class="cat-search-val" value="' . e($sel) . '">'
         . '<ul class="cat-search-list" hidden role="listbox">' . $items . '</ul>'
         . '</div>';
};

// Rendu d'une ligne de condition (PHP, pour les conditions déjà en base).
$condRow = function (array $cond): string {
    $type   = $cond['type']   ?? 'texte';
    $op     = $cond['op']     ?? 'contient';
    $valeur = (string) ($cond['valeur'] ?? '');

    if (in_array($type, ['montant_min', 'montant_max', 'montant_exact'], true)) {
        $op   = match ($type) { 'montant_max' => '<=', 'montant_exact' => '=', default => '>=' };
        $type = 'montant';
    }

    $typeOpts = '';
    // Les trois champs de texte d'une écriture — libellé, contre-partie,
    // communication —, puis le sens et le montant. Un relevé camt.053 remplit
    // les deux derniers séparément du libellé : une contre-partie peut n'y
    // figurer nulle part (voir regle_match(), lib/compta.php).
    foreach (['texte' => 'Texte', 'tiers' => 'Contre-partie', 'communication' => 'Communication', 'sens' => 'Sens (crédit/débit)', 'montant' => 'Montant'] as $k => $v) {
        $typeOpts .= '<option value="' . $k . '"' . ($type === $k ? ' selected' : '') . '>' . e($v) . '</option>';
    }
    $opOpts = '';
    foreach (['contient' => 'contient', 'commence' => 'commence par', 'exact' => 'égal à'] as $k => $v) {
        $opOpts .= '<option value="' . $k . '"' . ($op === $k ? ' selected' : '') . '>' . e($v) . '</option>';
    }
    $opNumOpts = '';
    foreach (['>=' => '≥', '<=' => '≤', '=' => '='] as $k => $v) {
        $opNumOpts .= '<option value="' . $k . '"' . ($op === $k ? ' selected' : '') . '>' . e($v) . '</option>';
    }

    $isTexte   = in_array($type, ['texte', 'tiers', 'communication'], true);
    $isSens    = $type === 'sens';
    $isMontant = $type === 'montant';
    $valSens   = in_array($valeur, ['credit', 'debit'], true) ? $valeur : 'credit';
    $valNum    = $isMontant ? $valeur : '';

    $sensOpts = '<option value="credit"' . ($valSens === 'credit' ? ' selected' : '') . '>Crédit (+)</option>'
              . '<option value="debit"'  . ($valSens === 'debit'  ? ' selected' : '') . '>Débit (−)</option>';

    return '<div class="cond-row" data-type="' . e($type) . '">'
         . '<select name="cond_type[]" class="cond-type">' . $typeOpts . '</select>'
         . '<select name="cond_op[]" class="cond-op cond-vis-texte"' . ($isTexte ? '' : ' hidden') . '>' . $opOpts . '</select>'
         . '<input name="cond_valeur_text[]" type="text" class="cond-val grow cond-vis-texte" value="' . e($isTexte ? $valeur : '') . '" placeholder="ex. MARTIN"' . ($isTexte ? '' : ' hidden') . '>'
         . '<select name="cond_valeur_sens[]" class="cond-val cond-vis-sens"' . ($isSens ? '' : ' hidden') . '>' . $sensOpts . '</select>'
         . '<select name="cond_op_num[]" class="cond-op-num cond-vis-montant"' . ($isMontant ? '' : ' hidden') . '>' . $opNumOpts . '</select>'
         . '<input name="cond_valeur_num[]" type="number" inputmode="decimal" step="0.01" class="cond-val cond-vis-montant" value="' . e($valNum) . '" placeholder="0.00"' . ($isMontant ? '' : ' hidden') . '>'
         . '<button type="button" class="btn ghost btn-sm icon-only cond-rm" title="Supprimer cette condition" aria-label="Supprimer">' . icon('x') . '</button>'
         . '</div>';
};

// La même condition, dite en toutes lettres : c'est ce que la règle montre au
// repos. Les trois types de $condRow() ci-dessus, en texte plutôt qu'en champs.
$condTexte = function (array $cond): string {
    $type   = $cond['type']   ?? 'texte';
    $op     = $cond['op']     ?? 'contient';
    $valeur = (string) ($cond['valeur'] ?? '');
    if (in_array($type, ['montant_min', 'montant_max', 'montant_exact'], true)) {
        $op   = match ($type) { 'montant_max' => '<=', 'montant_exact' => '=', default => '>=' };
        $type = 'montant';
    }
    if ($type === 'sens') {
        return $valeur === 'debit' ? 'sens débit' : 'sens crédit';
    }
    $champs = ['texte' => 'texte', 'tiers' => 'contre-partie', 'communication' => 'communication'];
    if ($type === 'montant') {
        $sym = ['>=' => '≥', '<=' => '≤', '=' => '='][$op] ?? '≥';
        return 'montant ' . $sym . ' ' . chf((float) $valeur);
    }
    $mots = ['contient' => 'contient', 'commence' => 'commence par', 'exact' => 'est exactement'];
    return ($champs[$type] ?? 'texte') . ' ' . ($mots[$op] ?? 'contient') . ' « ' . $valeur . ' »';
};

$condVide  = fn(string $motif = '') => $condRow(['type' => 'texte', 'op' => 'contient', 'valeur' => $motif]);
// Le libellé d'un compte et le chemin d'une catégorie, par identifiant : la
// ligne de lecture les nomme, là où le formulaire se contentait de les
// présélectionner dans ses menus.
$compteLibelles = [];
foreach ($comptes as $c) { $compteLibelles[(int) $c['id']] = (string) $c['libelle']; }
$cheminsCat = [];
foreach ($feuilles as $f) { $cheminsCat[(int) $f['id']] = (string) $f['chemin']; }

$ouvrirNew = $prefillMotif !== '' || $prefillCompte !== null || isset($_GET['new']);
$peutEcrireRegles = peut_ecrire('compta');
?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php if ($saved): ?><p class="ok flash">Règles mises à jour.</p><?php endif; ?>
<?php if ($test !== null): ?>
    <p class="ok flash"><?= $test < 0
        ? 'Aucune condition à tester.'
        : 'Cette règle toucherait <strong>' . (int) $test . '</strong> écriture(s) non lettrée(s).' ?></p>
<?php endif; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>

<div class="module-content"><div class="module-content-inner">
    <?php if ($peutEcrireRegles): ?>
    <div class="toolbar">
        <?php // Recherche immédiate, sur les lignes déjà chargées : une
              // installation en compte quelques dizaines, et filtrer côté
              // serveur ferait perdre la ligne ouverte à chaque frappe. Le
              // champ cherche dans la PHRASE d'une règle — compte, conditions,
              // catégorie —, c'est-à-dire dans ce qui s'y lit. ?>
        <?= champ_recherche(['id' => 'regles-search', 'placeholder' => 'Catégorie, motif, compte…']) ?>
        <span id="regles-count" class="muted small"></span>
        <div class="head-actions">
            <form method="post" action="?p=compta_ecritures">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="section" value="apply_rules">
                <button type="submit" class="btn ghost"><?= icon('refresh-cw') ?> <span>Appliquer<span class="lbl"> les règles</span></span></button>
            </form>
            <button type="button" id="btn-new-rule" class="btn"><?= icon('plus') ?><span class="lbl"> Nouvelle règle</span></button>
        </div>
    </div>
    <?php endif; ?>

<template id="cond-tpl">
    <div class="cond-row" data-type="texte">
        <select name="cond_type[]" class="cond-type">
            <option value="texte">Texte</option>
            <option value="sens">Sens (crédit/débit)</option>
            <option value="montant">Montant</option>
        </select>
        <select name="cond_op[]" class="cond-op cond-vis-texte">
            <option value="contient">contient</option>
            <option value="commence">commence par</option>
            <option value="exact">égal à</option>
        </select>
        <input name="cond_valeur_text[]" type="text" class="cond-val grow cond-vis-texte" placeholder="ex. MARTIN">
        <select name="cond_valeur_sens[]" class="cond-val cond-vis-sens" hidden>
            <option value="credit">Crédit (+)</option>
            <option value="debit">Débit (−)</option>
        </select>
        <select name="cond_op_num[]" class="cond-op-num cond-vis-montant" hidden>
            <option value=">=">≥</option>
            <option value="<=">≤</option>
            <option value="=">=</option>
        </select>
        <input name="cond_valeur_num[]" type="number" inputmode="decimal" step="0.01" class="cond-val cond-vis-montant" placeholder="0.00" hidden>
        <button type="button" class="btn ghost btn-sm icon-only cond-rm" title="Supprimer cette condition" aria-label="Supprimer"><?= icon('x') ?></button>
    </div>
</template>


    <!-- Nouvelle règle -->
    <?php if ($peutEcrireRegles): ?>
    <div id="new-rule-card" class="regle-card regle-new <?= $ouvrirNew ? '' : 'hidden' ?>">
        <form method="post" action="?p=compta_regles">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <div class="regle-head">
                <!-- Compte -->
                <div class="regle-cond-ctrl">
                    <span class="regle-sub">Compte</span>
                    <select name="compte_bancaire_id" class="regle-ctrl-select"><?= $compteOptions($prefillCompte !== null ? (string) $prefillCompte : '') ?></select>
                </div>
                <!-- Groupe Conditions : toujours affiché ; ET/OU visible si >1 condition -->
                <div class="regle-cond-ctrl">
                    <span class="regle-sub">Conditions</span>
                    <div class="regle-cond-row">
                        <button type="button" class="btn ghost btn-xs icon-only add-cond" data-target="conds-new" title="Ajouter une condition" aria-label="Ajouter une condition"><?= icon('plus') ?></button>
                        <select name="operateur" class="regle-op-select" hidden>
                            <option value="ET">ET</option>
                            <option value="OU">OU</option>
                        </select>
                    </div>
                </div>
                <span class="flex-spacer"></span>
                <span class="test-result muted small"></span>
                <button type="button" class="btn ghost btn-sm btn-tester"><?= icon('search') ?> Tester</button>
                <button type="submit" name="section" value="add" class="btn btn-sm"><?= icon('save') ?> Enregistrer</button>
                <button type="button" id="cancel-new-rule" class="btn ghost btn-sm"><?= icon('x') ?> Annuler</button>
            </div>
            <div class="regle-conds" id="conds-new">
                <?= $prefillMotif !== '' ? $condVide($prefillMotif) : $condVide() ?>
            </div>
            <div class="regle-cat">
                <label class="regle-label grow">Catégorie cible<?= $catSearchable(null) ?></label>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($peutEcrireRegles): ?>
    <?php // Exemplaire unique du formulaire de repositionnement : le script y
          // écrit l'ordre complet au dépôt et l'envoie (docs/UI.md § 4). ?>
    <form method="post" action="?p=compta_regles" id="reorder-form" hidden>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="section" value="reorder">
        <input type="hidden" name="id" value="">
        <input type="hidden" name="order" value="">
    </form>
    <?php endif; ?>

    <?php // Les règles se rangent comme les lignes du décompte (?p=postes) : un
          // tableau de LIGNES, pas une pile de cartes — on en relit dix d'affilée
          // pour comprendre dans quel ordre elles s'appliquent, et une carte par
          // règle noyait cet ordre sous les cadres. Le glisser-déposer vient avec,
          // qui EST la convention de l'application pour réordonner (docs/UI.md § 4) ;
          // les flèches restent en repli sans JavaScript et sur téléphone. ?>
    <?php // Pas de .card : dans un bloc de module, une liste va bord à bord
          // (docs/UI.md § 9) — comme ?p=projets, qui est le même tableau de
          // lignes ordonnables. Porter .card ICI, c'est-à-dire sur l'élément
          // qui EST la zone de défilement, c'est le piège que la guideline
          // nomme : la marge négative de la page tire la carte bord à bord,
          // ses coins restent arrondis en haut sur un bord devenu droit, et
          // son filet du bas s'ajoute à celui de la dernière rangée. ?>
    <div class="form table-scroll" id="regles-card">
    <table class="list mb-0 plan-table regles-table">
        <thead>
            <tr>
                <th class="col-icon" title="Règle appliquée lors du lettrage automatique">Active</th>
                <th>Règle</th>
                <th class="num nowrap">Touche</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$regles): ?>
            <tr id="no-rule"><td colspan="4" class="muted small">Aucune règle définie. Cliquez sur « Nouvelle règle ».</td></tr>
        <?php endif; ?>
        <?php foreach ($regles as $r):
            $rid     = (int) $r['id'];
            $actif   = (int) $r['actif'] === 1;
            $imp     = (int) ($impacts[$rid] ?? 0);
            $nbConds = count($r['conditions']);
            // La règle dite en une phrase : le compte, ses conditions reliées par
            // son ET/OU, et la catégorie qu'elle pose. C'est ce qu'on lit en
            // parcourant la liste ; les champs n'apparaissent qu'au crayon.
            $compteLib = $r['compte_bancaire_id'] === null
                ? 'Tous les comptes'
                : ($compteLibelles[(int) $r['compte_bancaire_id']] ?? 'Compte supprimé');
            $condsTexte = implode(
                ' ' . (($r['operateur'] ?? 'ET') === 'OU' ? 'ou' : 'et') . ' ',
                array_map($condTexte, $r['conditions'])
            );
            $catLib = $cheminsCat[(int) $r['plan_compte_id']] ?? '';
        ?>
            <tr class="plan-row <?= $actif ? '' : 'plan-archive' ?>" data-id="<?= $rid ?>">
                <td class="td-toggle">
                    <?php // La poignée ouvre la ligne, tout à gauche : c'est la place
                          // qu'elle a dans toutes les listes qui se glissent
                          // (docs/UI.md § 4). L'interrupteur la suit, et ne paraît
                          // qu'une ligne ouverte (.cell-edition) comme celui d'un
                          // projet : au repos, c'est l'atténuation de la ligne qui
                          // dit qu'une règle est éteinte. ?>
                    <?php if ($peutEcrireRegles): ?>
                    <?= plan_poignee_html('Glisser pour changer l\'ordre d\'application') ?>
                    <label class="regle-toggle cell-edition" title="<?= $actif ? 'Désactiver' : 'Activer' ?>">
                        <input form="regle-edit-<?= $rid ?>" type="checkbox" name="actif" value="1" <?= $actif ? 'checked' : '' ?>
                               class="regle-actif-cb" aria-label="<?= $actif ? 'Désactiver' : 'Activer' ?> cette règle">
                        <span class="regle-toggle-pill"></span>
                    </label>
                    <?php else: ?>
                    <span class="badge <?= $actif ? 'ok-badge' : 'muted-badge' ?>"><?= $actif ? 'Active' : 'Inactive' ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="inline-edit">
                        <span class="plan-nom regle-disp">
                            <span class="regle-disp-compte"><?= e($compteLib) ?></span>
                            <span class="regle-disp-conds"><?= $condsTexte !== '' ? e($condsTexte) : '<span class="warn-txt">aucune condition</span>' ?></span>
                            <span class="regle-disp-fleche" aria-hidden="true">→</span>
                            <span class="regle-disp-cat"><?= $catLib !== '' ? e($catLib) : '<span class="warn-txt">aucune catégorie</span>' ?></span>
                        </span>
                        <?php if ($peutEcrireRegles): ?>
                        <form method="post" action="?p=compta_regles" class="plan-edit regle-edit" id="regle-edit-<?= $rid ?>">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="id" value="<?= $rid ?>">
                            <input type="hidden" name="section" value="edit">
                            <div class="regle-head">
                                <div class="regle-cond-ctrl">
                                    <span class="regle-sub">Compte</span>
                                    <select name="compte_bancaire_id" class="regle-ctrl-select"><?= $compteOptions($r['compte_bancaire_id'] === null ? '' : (string) $r['compte_bancaire_id']) ?></select>
                                </div>
                                <div class="regle-cond-ctrl">
                                    <span class="regle-sub">Conditions</span>
                                    <div class="regle-cond-row">
                                        <button type="button" class="btn ghost btn-xs icon-only add-cond" data-target="conds-<?= $rid ?>" title="Ajouter une condition" aria-label="Ajouter une condition"><?= icon('plus') ?></button>
                                        <select name="operateur" class="regle-op-select" <?= $nbConds <= 1 ? 'hidden' : '' ?>>
                                            <option value="ET" <?= ($r['operateur'] ?? 'ET') === 'ET' ? 'selected' : '' ?>>ET</option>
                                            <option value="OU" <?= ($r['operateur'] ?? 'ET') === 'OU' ? 'selected' : '' ?>>OU</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="regle-conds" id="conds-<?= $rid ?>">
                                <?php foreach ($r['conditions'] as $cond): ?>
                                    <?= $condRow($cond) ?>
                                <?php endforeach; ?>
                                <?php if (empty($r['conditions'])): ?>
                                    <p class="muted small regle-no-cond">Aucune condition — la règle ne s'applique pas.</p>
                                <?php endif; ?>
                            </div>
                            <div class="regle-cat">
                                <label class="regle-label grow">Catégorie cible<?= $catSearchable((int) $r['plan_compte_id'], true) ?></label>
                            </div>
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
                <td class="num nowrap">
                    <?php if ($actif): ?>
                        <span class="muted small" title="Écritures non lettrées que cette règle attraperait">Touche&nbsp;: <?= $imp ?></span>
                    <?php endif; ?>
                    <span class="test-result muted small"></span>
                </td>
                <td class="actions nowrap">
                    <?php if ($peutEcrireRegles): ?>
                    <?php // Repli sans glisser-déposer (et sur téléphone, où il
                          // n'existe pas) : les deux flèches, rattachées au
                          // formulaire de la ligne par form=. ?>
                    <button type="submit" form="regle-edit-<?= $rid ?>" name="section" value="move_up"   class="btn ghost btn-sm icon-only plan-fallback" title="Monter" aria-label="Monter"><?= icon('chevron-up') ?></button>
                    <button type="submit" form="regle-edit-<?= $rid ?>" name="section" value="move_down" class="btn ghost btn-sm icon-only plan-fallback" title="Descendre" aria-label="Descendre"><?= icon('chevron-down') ?></button>
                    <button type="button" class="btn ghost btn-sm btn-tester cell-edition"><?= icon('search') ?> Tester</button>
                    <button type="submit" form="regle-edit-<?= $rid ?>" name="section" value="edit" class="btn btn-sm cell-edition" title="Enregistrer"><?= icon('save') ?> Enregistrer</button>
                    <button type="submit" form="regle-edit-<?= $rid ?>" name="section" value="del" class="btn danger btn-sm icon-only cell-edition plan-supprimer"
                            title="Supprimer" aria-label="Supprimer cette règle"
                            data-confirm="Supprimer cette règle ?"><?= icon('trash') ?></button>
                    <?= plan_boutons_edition_html('cette règle') ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

</div></div>

<?php // Toujours exécuté, même sans droit d'écriture : c'est « dnd-on » qui
      // bascule la liste en mode lecture — sans lui, les formulaires d'édition
      // resteraient dépliés sur chaque ligne. ?>
<script nonce="<?= e(csp_nonce()) ?>">
lassoOrdreListe({
    containerSelector: '#regles-card',
    rowsSelector: '.regles-table .plan-row',
    scrollKey: 'reglesScroll',
    formAction: '?p=compta_regles',
});
lassoListeClient({
    tableSelector: '.regles-table',
    searchInputSelector: '#regles-search',
    searchCountSelector: '#regles-count',
    // La phrase de la règle, pas la ligne entière : le formulaire replié
    // contient tout le plan comptable (voir lassoListeClient()).
    matchSelector: '.plan-nom',
});
</script>

<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    const tpl = document.getElementById('cond-tpl');

    function updateCondType(row) {
        const type = row.querySelector('.cond-type').value;
        row.dataset.type = type;
        // Les trois types de texte partagent le même champ de valeur et les
        // mêmes opérateurs : seul le champ fouillé change, côté serveur.
        const estTexte = ['texte', 'tiers', 'communication'].includes(type);
        row.querySelectorAll('.cond-vis-texte').forEach(el   => el.hidden = !estTexte);
        row.querySelectorAll('.cond-vis-sens').forEach(el    => el.hidden = (type !== 'sens'));
        row.querySelectorAll('.cond-vis-montant').forEach(el => el.hidden = (type !== 'montant'));
    }

    // Affiche/masque le select ET/OU selon le nombre de conditions.
    function updateOperateurVisibility(form) {
        const n   = form.querySelectorAll('.regle-conds .cond-row').length;
        const sel = form.querySelector('.regle-op-select');
        if (sel) sel.hidden = (n <= 1);
    }

    function initCondRow(row) {
        const sel = row.querySelector('.cond-type');
        if (sel) sel.addEventListener('change', () => updateCondType(row));
        const rm = row.querySelector('.cond-rm');
        if (rm) rm.addEventListener('click', () => {
            const box  = rm.closest('.regle-conds');
            const form = rm.closest('form');
            row.remove();
            if (box && !box.querySelector('.cond-row')) {
                let msg = box.querySelector('.regle-no-cond');
                if (!msg) {
                    msg = document.createElement('p');
                    msg.className = 'muted small regle-no-cond';
                    msg.textContent = 'Aucune condition — la règle ne s\'applique pas.';
                    box.appendChild(msg);
                }
            }
            if (form) updateOperateurVisibility(form);
        });
    }

    document.querySelectorAll('.cond-row').forEach(initCondRow);

    document.querySelectorAll('.add-cond').forEach(btn => {
        btn.addEventListener('click', () => {
            if (!tpl) return;
            const box  = document.getElementById(btn.dataset.target);
            const form = btn.closest('form');
            if (!box) return;
            const clone = tpl.content.firstElementChild.cloneNode(true);
            const msg = box.querySelector('.regle-no-cond');
            if (msg) msg.remove();
            box.appendChild(clone);
            initCondRow(clone);
            clone.querySelector('input, select')?.focus();
            if (form) updateOperateurVisibility(form);
        });
    });

    // Validation catégorie avant envoi. La carte « nouvelle règle » et les
    // lignes du tableau portent toutes deux leur formulaire : on les prend par
    // le formulaire lui-même, pas par ce qui l'enveloppe.
    document.querySelectorAll('#new-rule-card form, .regles-table .plan-edit').forEach(form => {
        form.addEventListener('submit', function (e) {
            const section = e.submitter?.value;
            if (section === 'move_up' || section === 'move_down') return;
            const hidden = form.querySelector('.cat-search-val');
            const input  = form.querySelector('.cat-search-input');
            if (hidden && !hidden.value && input) {
                input.setCustomValidity('Veuillez choisir une catégorie');
                input.reportValidity();
                e.preventDefault();
            } else if (input) {
                input.setCustomValidity('');
            }
        });
    });

    // Tester : fetch sans rechargement. $hote est la carte « nouvelle règle »
    // ou la LIGNE d'une règle existante — l'une et l'autre portent un bouton,
    // un formulaire et un emplacement de résultat.
    function bindTester(hote) {
        const btn = hote.querySelector('.btn-tester');
        if (!btn) return;
        btn.addEventListener('click', () => {
            const form = hote.querySelector('form, .plan-edit');
            if (!form) return;
            const data = new FormData(form);
            data.set('section', 'test');
            const result = hote.querySelector('.test-result');
            btn.disabled = true;
            fetch('?p=compta_regles', { method: 'POST', body: data })
                .then(r => r.json())
                .then(j => { if (result) result.textContent = 'Touche : ' + j.n; })
                .catch(() => { if (result) result.textContent = 'Erreur'; })
                .finally(() => { btn.disabled = false; });
        });
    }
    document.querySelectorAll('#new-rule-card, .regles-table .plan-row').forEach(bindTester);

    // Ouvrir / fermer la nouvelle règle.
    const card      = document.getElementById('new-rule-card');
    const btnNew    = document.getElementById('btn-new-rule');
    const cancelNew = document.getElementById('cancel-new-rule');
    if (btnNew)    btnNew.addEventListener('click', () => { card.classList.remove('hidden'); card.querySelector('input, select')?.focus(); });
    if (cancelNew) cancelNew.addEventListener('click', () => card.classList.add('hidden'));
})();
</script>
