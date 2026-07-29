<?php
/** @var ?string $errFiches */ /** @var ?array $resultatsFiches */ /** @var ?array $resumeFiches */ /** @var bool $simuleFiches */
/** @var ?string $errFactures */ /** @var ?array $resultatsFactures */ /** @var ?array $resumeFactures */ /** @var bool $simuleFactures */
/** @var ?array $msgEcritures */
/** @var ?string $errEvenements */ /** @var ?array $resultatsEvenements */ /** @var ?array $resumeEvenements */ /** @var bool $simuleEvenements */

// Types de données importables : un seul formulaire, la logique (route cible,
// formats acceptés, boutons) change selon la sélection. Chaque route de
// traitement reste distincte (import_fiches / import_factures / …).
$types = [];
if (module_actif('salaires')) {
    $types['fiches'] = [
        'libelle' => 'Fiches de salaire (JSON)',
        'action'  => '?p=import_fiches',
        'accept'  => 'application/json,.json',
        'simuler' => true,
        'bouton'  => 'Importer',
        'confirm' => 'Importer réellement les fiches nouvelles ?',
    ];
}
if (module_actif('facturation')) {
    $types['factures'] = [
        'libelle' => 'Factures (JSON)',
        'action'  => '?p=import_factures',
        'accept'  => 'application/json,.json',
        'simuler' => true,
        'bouton'  => 'Importer',
        'confirm' => 'Importer réellement les factures nouvelles ?',
    ];
}
if (module_actif('compta')) {
    $types['ecritures'] = [
        'libelle' => 'Écritures bancaires (CSV / XML camt.053)',
        'action'  => '?p=import_ecritures',
        'accept'  => '.csv,text/csv,.xml,application/xml,text/xml',
        'simuler' => true,
        'bouton'  => 'Importer directement',
        'confirm' => 'Importer réellement ces écritures ?',
    ];
}
if (module_actif('evenements')) {
    $types['evenements'] = [
        'libelle' => 'Événements (CSV)',
        'action'  => '?p=import_evenements',
        'accept'  => 'text/csv,.csv',
        'simuler' => true,
        'bouton'  => 'Importer',
        'confirm' => 'Importer réellement les événements nouveaux ?',
    ];
}
if (module_actif('booking')) {
    $types['structures'] = [
        'libelle' => 'Structures — carnet d\'adresses (CSV)',
        'action'  => '?p=import_structures',
        'accept'  => 'text/csv,.csv',
        'simuler' => false,
        'bouton'  => 'Analyser le fichier',
        'confirm' => '',
        'etape'   => 'mapper', // entre dans l'assistant (correspondance des colonnes…)
    ];
}

// Type actif : déduit des résultats affichés (la route qui vient de tourner),
// sinon laissé au choix mémorisé côté navigateur (localStorage, voir plus bas).
$typeActif = null;
if ($errFiches !== null || $resumeFiches !== null) $typeActif = 'fiches';
elseif ($errFactures !== null || $resumeFactures !== null) $typeActif = 'factures';
elseif ($msgEcritures !== null) $typeActif = 'ecritures';
elseif ($errEvenements !== null || $resumeEvenements !== null) $typeActif = 'evenements';
?>
<?php require __DIR__ . '/_param_tabs.php'; ?>

<div class="card form" id="import-unifie" data-type-actif="<?= e((string) $typeActif) ?>">
    <h2 class="mt-0">Importer des données</h2>
    <form method="post" action="?p=import_fiches" enctype="multipart/form-data" id="import-form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="etape" value="" id="import-etape" disabled>
        <div class="grid2">
            <label>Type de données à importer
                <select id="import-type">
                    <?php foreach ($types as $cle => $t): ?>
                        <option value="<?= e($cle) ?>"><?= e($t['libelle']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Fichier à importer
                <input type="file" name="fichier" id="import-fichier" required>
            </label>
        </div>

        <?php if (module_actif('salaires')): ?>
        <p class="muted small import-aide" data-for="fiches" hidden>Fichier <strong>JSON</strong> (format d'export « fiches_salaire »). Correspondance des employés par <strong>numéro AVS</strong>. Une fiche déjà présente (même employé, année, mois) est <strong>ignorée</strong> — jamais écrasée.
            <a href="assets/exemples/fiches_salaire.json" target="_blank">Voir un exemple de ligne</a>.</p>
        <?php endif; ?>
        <?php if (module_actif('facturation')): ?>
        <p class="muted small import-aide" data-for="factures" hidden>Factures déjà émises avant Lasso, en <strong>JSON</strong>. La structure est retrouvée par <strong>nom exact</strong> (créée si absente). Un <strong>numéro</strong> déjà présent est <strong>ignoré</strong> — jamais écrasé.
            <a href="assets/exemples/factures.json" target="_blank">Voir un exemple de ligne</a>.</p>
        <?php endif; ?>
        <?php if (module_actif('compta')): ?>
        <p class="muted small import-aide" data-for="ecritures" hidden>Export PostFinance (<strong>CSV</strong>) ou relevé <strong>ISO 20022 camt.053</strong> (XML). Compte retrouvé par <strong>IBAN</strong> (nommable après simulation). Écritures déjà importées <strong>ignorées</strong> (dédoublonnage) ; règles de lettrage appliquées après l'import.</p>
        <?php endif; ?>
        <?php if (module_actif('evenements')): ?>
        <p class="muted small import-aide" data-for="evenements" hidden>Agenda de tournée en <strong>CSV</strong> (colonnes : <code>date, ville, departement_canton, pays, lieu, festival, details, type, statut, lien, lien_texte</code> — ordre libre, seules <code>date</code> et <code>ville</code> obligatoires, date JJ/MM/AAAA). <code>pays</code> en <strong>code ISO2</strong> (<code>CH</code>, <code>FR</code>…) ; <code>departement_canton</code> = <strong>canton</strong> (2 lettres, ex. <code>GE</code>, <code>NE</code>) pour la Suisse ou <strong>numéro de département</strong> (ex. <code>25</code>) pour la France — jamais un nom de région/canton en toutes lettres, sinon la grande région (Romandie, Bourgogne-Franche-Comté…) ne peut pas être déduite automatiquement. Un événement à la même date/ville/salle est <strong>ignoré</strong>. Les événements importés sont créés <strong>non répertoriés</strong>.
            <a href="assets/exemples/evenements.csv" target="_blank">Voir un exemple de fichier</a>.</p>
        <?php endif; ?>
        <?php if (module_actif('booking')): ?>
        <p class="muted small import-aide" data-for="structures" hidden>Carnet d'adresses <strong>CSV</strong> à colonnes libres : la correspondance des colonnes se fait à l'écran suivant, puis regroupements par organisateur et résolution des conflits un par un.</p>
        <?php endif; ?>

        <div class="form-actions">
            <button type="submit" name="simuler" value="1" class="btn ghost" id="import-simuler"><?= icon('bar-chart') ?> Simuler</button>
            <button type="submit" name="appliquer" value="1" id="import-go"><?= icon('import') ?> <span id="import-go-lbl">Importer</span></button>
        </div>
        <p class="muted small" id="import-note">« Simuler » montre ce qui serait importé sans rien enregistrer. « Importer » enregistre réellement.</p>
        <noscript><p class="warn">JavaScript est requis pour choisir le type de données (sans lui, le formulaire importe des fiches de salaire).</p></noscript>
    </form>
</div>

<?php if (module_actif('booking')): ?>
<div class="card form mt-22 import-extra" data-for="structures" hidden>
    <h3 class="sub no-mt">Liste « ne pas contacter » (structures)</h3>
    <p class="muted small">Une adresse par ligne — désinscrit immédiatement du mailing, sans jamais pouvoir être réimportée par erreur.</p>
    <form method="post" action="?p=import_structures">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="etape" value="exclusion">
        <textarea name="emails" rows="3" placeholder="contact1@exemple.com&#10;contact2@exemple.com"></textarea>
        <div class="form-actions">
            <button type="submit" class="btn ghost btn-sm">Ajouter à la liste d'exclusion</button>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if (module_actif('salaires')): ?>
    <?php require __DIR__ . '/_import_fiches_section.php'; ?>
<?php endif; ?>
<?php if (module_actif('facturation')): ?>
    <?php require __DIR__ . '/_import_factures_section.php'; ?>
<?php endif; ?>
<?php if (module_actif('compta')): ?>
    <?php require __DIR__ . '/_import_ecritures_section.php'; ?>
<?php endif; ?>
<?php if (module_actif('evenements')): ?>
    <?php require __DIR__ . '/_import_evenements_section.php'; ?>
<?php endif; ?>

<script>
(function () {
    var configs = <?= json_encode($types, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var sel = document.getElementById('import-type');
    var form = document.getElementById('import-form');
    var fichier = document.getElementById('import-fichier');
    var etape = document.getElementById('import-etape');
    var btnSim = document.getElementById('import-simuler');
    var btnGo = document.getElementById('import-go');
    var lblGo = document.getElementById('import-go-lbl');
    var note = document.getElementById('import-note');

    function applique() {
        var c = configs[sel.value];
        if (!c) return;
        form.action = c.action;
        fichier.accept = c.accept;
        btnSim.hidden = !c.simuler;
        note.hidden = !c.simuler;
        lblGo.textContent = c.bouton;
        btnGo.dataset.confirm = c.confirm || '';
        // Assistant structures : le POST d'upload attend etape=mapper.
        if (c.etape) { etape.value = c.etape; etape.disabled = false; } else { etape.disabled = true; }
        document.querySelectorAll('.import-aide').forEach(function (p) { p.hidden = p.dataset.for !== sel.value; });
        document.querySelectorAll('.import-extra').forEach(function (d) { d.hidden = d.dataset.for !== sel.value; });
        try { localStorage.setItem('importType', sel.value); } catch (e) {}
    }
    btnGo.addEventListener('click', function (e) {
        if (this.dataset.confirm && !confirm(this.dataset.confirm)) e.preventDefault();
    });
    sel.addEventListener('change', applique);

    // Sélection initiale : le type dont les résultats viennent de s'afficher,
    // sinon le dernier choisi sur ce navigateur, sinon le premier.
    var actif = document.getElementById('import-unifie').dataset.typeActif;
    if (!actif) { try { actif = localStorage.getItem('importType'); } catch (e) {} }
    if (actif && configs[actif]) sel.value = actif;
    applique();

    // Ne pas revenir en haut de la page après Simuler/Importer — on restaure
    // la position de défilement au retour.
    var scrollKey = 'import-scroll';
    document.querySelectorAll('form').forEach(function (f) {
        f.addEventListener('submit', function () { sessionStorage.setItem(scrollKey, String(window.scrollY)); });
    });
    var saved = sessionStorage.getItem(scrollKey);
    if (saved !== null) {
        sessionStorage.removeItem(scrollKey);
        window.addEventListener('load', function () { window.scrollTo(0, parseInt(saved, 10)); });
    }
})();
</script>
