<?php
/** @var ?string $errFiches */ /** @var ?array $resultatsFiches */ /** @var ?array $resumeFiches */ /** @var bool $simuleFiches */
/** @var ?string $errFactures */ /** @var ?array $resultatsFactures */ /** @var ?array $resumeFactures */ /** @var bool $simuleFactures */
/** @var ?array $msgEcritures */
/** @var ?string $errEvenements */ /** @var ?array $resultatsEvenements */ /** @var ?array $resumeEvenements */ /** @var bool $simuleEvenements */
/** @var string $errRestaure */ /** @var string $motifRestaure */

// Types de données importables : un seul formulaire, la logique (route cible,
// formats acceptés, champ de saisie, boutons) change selon la sélection. La
// page, elle, n'appartient à aucun module (?p=import) ; chaque route de
// traitement reste distincte et vit dans le sien (fiches_importer,
// factures_importer, …).
$types = [];
if (module_actif('salaires') && peut_ecrire('salaires')) {
    $types['fiches'] = [
        'libelle' => 'Fiches de salaire (JSON)',
        'action'  => '?p=fiches_importer',
        'accept'  => 'application/json,.json',
        'simuler' => true,
        'bouton'  => 'Importer',
        'confirm' => 'Importer réellement les fiches nouvelles ?',
    ];
}
if (module_actif('facturation') && peut_ecrire('facturation')) {
    $types['factures'] = [
        'libelle' => 'Factures (JSON)',
        'action'  => '?p=factures_importer',
        'accept'  => 'application/json,.json',
        'simuler' => true,
        'bouton'  => 'Importer',
        'confirm' => 'Importer réellement les factures nouvelles ?',
    ];
}
if (module_actif('compta') && peut_ecrire('compta')) {
    $types['ecritures'] = [
        'libelle' => 'Écritures bancaires (CSV / XML camt.053)',
        'action'  => '?p=compta_ecritures_importer_valider',
        'accept'  => '.csv,text/csv,.xml,application/xml,text/xml',
        'simuler' => true,
        'bouton'  => 'Importer directement',
        'confirm' => 'Importer réellement ces écritures ?',
    ];
}
if (module_actif('evenements') && peut_ecrire('evenements')) {
    $types['evenements'] = [
        'libelle' => 'Événements (CSV)',
        'action'  => '?p=evenements_importer',
        'accept'  => 'text/csv,.csv',
        'simuler' => true,
        'bouton'  => 'Importer',
        'confirm' => 'Importer réellement les événements nouveaux ?',
    ];
}
if (module_actif('booking') && peut_ecrire('booking')) {
    $types['structures'] = [
        'libelle' => 'Structures — carnet d\'adresses (CSV)',
        'action'  => '?p=structures_importer',
        'accept'  => 'text/csv,.csv',
        'simuler' => false,
        'bouton'  => 'Analyser le fichier',
        'confirm' => '',
        'etape'   => 'mapper', // entre dans l'assistant (correspondance des colonnes…)
    ];
    // La liste « ne pas contacter » est un import à part, pas une variante du
    // carnet d'adresses : elle n'ajoute aucune structure, elle en désinscrit —
    // et elle se colle au lieu de se téléverser (d'où 'saisie'). Elle était
    // auparavant une carte qui apparaissait sous le carnet, ce qui laissait
    // croire à une étape de celui-ci.
    $types['exclusions'] = [
        'libelle' => 'Liste « ne pas contacter » (adresses e-mail)',
        'action'  => '?p=structures_importer',
        'accept'  => '',
        'simuler' => false,
        'bouton'  => 'Ajouter à la liste',
        'confirm' => '',
        'etape'   => 'exclusion',
        'saisie'  => true,
    ];
}
// La sauvegarde complète est un type comme les autres dans ce menu, et le
// DERNIER : c'est le seul qui REMPLACE au lieu d'ajouter, et le seul réservé à
// l'administration (écriture « coeur », comme la sauvegarde dont il est
// l'inverse — le dispatcher le vérifie, cet affichage ne fait que suivre).
// Rien à simuler : on ne prévisualise pas un remplacement intégral. D'où
// 'simuler' => false, et un bouton rouge que 'danger' allume pour lui seul.
if (est_admin()) {
    $types['sauvegarde'] = [
        'libelle' => 'Sauvegarde complète — remplace toutes les données',
        'action'  => '?p=sauvegarde_restaurer',
        'accept'  => '.zip,.gz,.tar.gz,.sqlite,application/zip,application/gzip',
        'simuler' => false,
        'bouton'  => 'Restaurer la sauvegarde',
        'confirm' => "Remplacer TOUTE la base par cette sauvegarde ?\n\nLes données actuelles seront mises de côté dans un fichier .bak, puis vous serez déconnecté : les comptes de la sauvegarde ne sont pas forcément ceux d'aujourd'hui.",
        'danger'  => true,
    ];
}

// Type actif : déduit des résultats affichés (la route qui vient de tourner),
// sinon laissé au choix mémorisé côté navigateur (localStorage, voir plus bas).
$typeActif = null;
if ($errFiches !== null || $resumeFiches !== null) $typeActif = 'fiches';
elseif ($errFactures !== null || $resumeFactures !== null) $typeActif = 'factures';
elseif ($msgEcritures !== null) $typeActif = 'ecritures';
elseif ($errEvenements !== null || $resumeEvenements !== null) $typeActif = 'evenements';
elseif ($errRestaure !== '') $typeActif = 'sauvegarde';
?>
<?php require __DIR__ . '/_param_tabs.php'; ?>

<?php if (!$types): ?>
<p class="muted">Vous n'avez pas les droits d'écriture nécessaires pour importer des données.</p>
<?php else: ?>
<div class="card form" id="import-unifie" data-type-actif="<?= e((string) $typeActif) ?>">
    <h2 class="mt-0">Importer des données</h2>
    <?php // Retour d'une restauration REFUSÉE. Une restauration réussie ne
          // repasse jamais par ici : elle déconnecte, et c'est l'écran de
          // connexion qui l'annonce. ?>
    <?php if ($errRestaure === 'taille'): $maxT = televersement_max(); ?>
    <p class="err mb-8">Le fichier dépasse ce que ce serveur accepte de recevoir<?= $maxT ? ' (' . e(fichier_taille_lisible($maxT)) . ')' : '' ?>.
        Rien n'a été touché. Posez l'archive sur le serveur par FTP et suivez la note <code>SAUVEGARDE.txt</code> qu'elle contient.</p>
    <?php elseif ($errRestaure === 'refus'): ?>
    <p class="err mb-8">Sauvegarde refusée : <?= e($motifRestaure) ?><br>
        <strong>Rien n'a été remplacé</strong> — l'archive est vérifiée avant que la base ne soit touchée.</p>
    <?php elseif ($errRestaure === 'fichier'): ?>
    <p class="err mb-8">Aucun fichier reçu. Rien n'a été touché.</p>
    <?php endif; ?>
    <?php // Sans JavaScript, le menu ne reconfigure rien : le formulaire part sur
          // le premier type disponible (et non sur les fiches de salaire en dur,
          // qui n'existent pas si le module est éteint). ?>
    <form method="post" action="<?= e($types[array_key_first($types)]['action']) ?>" enctype="multipart/form-data" id="import-form">
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
            <label id="import-champ-fichier">Fichier à importer
                <input type="file" name="fichier" id="import-fichier" required>
            </label>
        </div>
        <?php if (module_actif('booking') && peut_ecrire('booking')): ?>
        <label id="import-champ-emails" hidden>Adresses à exclure
            <textarea name="emails" id="import-emails" rows="3" placeholder="contact1@exemple.com&#10;contact2@exemple.com"></textarea>
        </label>
        <?php endif; ?>

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
        <p class="muted small import-aide" data-for="evenements" hidden>Agenda de tournée en <strong>CSV</strong> (colonnes : <code>date, ville, departement_canton, pays, lieu, festival, details, type, statut, lien, lien_texte</code> — ordre libre, seules <code>date</code> et <code>ville</code> obligatoires, date JJ/MM/AAAA, séparateur <strong>virgule ou point-virgule</strong> détecté automatiquement). <code>pays</code> en <strong>code ISO2</strong> (<code>CH</code>, <code>FR</code>…) ; <code>departement_canton</code> = <strong>canton</strong> (2 lettres, ex. <code>GE</code>, <code>NE</code>) pour la Suisse ou <strong>numéro de département</strong> (ex. <code>25</code>) pour la France — jamais un nom de région/canton en toutes lettres, sinon la grande région (Romandie, Bourgogne-Franche-Comté…) ne peut pas être déduite automatiquement. Un événement à la même date/ville/salle est <strong>ignoré</strong>. Les événements importés sont créés <strong>non répertoriés</strong>.
            <a href="assets/exemples/evenements.csv" target="_blank">Voir un exemple de fichier</a>.</p>
        <?php endif; ?>
        <?php if (module_actif('booking')): ?>
        <p class="muted small import-aide" data-for="structures" hidden>Carnet d'adresses <strong>CSV</strong> à colonnes libres : la correspondance des colonnes se fait à l'écran suivant, puis regroupements par organisateur et résolution des conflits un par un.</p>
        <p class="muted small import-aide" data-for="exclusions" hidden>Une adresse par ligne (ou séparées par des virgules). Chacune est <strong>désinscrite immédiatement</strong> du mailing, sans jamais pouvoir être réimportée par erreur. N'ajoute ni ne modifie aucune structure.</p>
        <?php endif; ?>
        <?php if (est_admin()): $maxTeleversement = televersement_max(); ?>
        <p class="warn small import-aide" data-for="sauvegarde" hidden><?= icon('info') ?> <strong>Remplace toute la base</strong>
            par celle de la sauvegarde — employés, fiches, écritures, carnet d'adresses, et <strong>les comptes de connexion</strong>,
            qui remplacent ceux d'aujourd'hui, chacun avec son propre mot de passe.
            Avant de remplacer quoi que ce soit, l'archive est vérifiée, puis <strong>la base actuelle est mise de côté</strong>
            dans un fichier <code>.sqlite.bak</code> à côté d'elle. Les logos, photos et pièces jointes de l'archive sont
            <strong>ajoutés</strong> aux dossiers existants, jamais substitués. Une sauvegarde plus ancienne est mise à jour
            automatiquement. Vous serez <strong>déconnecté</strong> à la fin : reconnectez-vous avec un compte de cette sauvegarde-là.
            Archive produite par <a href="?p=export">Exporter les données</a> : <code>.zip</code>, <code>.tar.gz</code> ou <code>.sqlite</code><?php
            if ($maxTeleversement): ?>, jusqu'à <strong><?= e(fichier_taille_lisible($maxTeleversement)) ?></strong> par envoi<?php endif; ?>.</p>
        <?php endif; ?>

        <div class="form-actions">
            <button type="submit" name="simuler" value="1" class="btn ghost" id="import-simuler"><?= icon('bar-chart') ?> Simuler</button>
            <button type="submit" name="appliquer" value="1" id="import-go"><?= icon('import') ?> <span id="import-go-lbl">Importer</span></button>
        </div>
        <p class="muted small" id="import-note">« Simuler » montre ce qui serait importé sans rien enregistrer. « Importer » enregistre réellement.</p>
        <noscript><p class="warn">JavaScript est requis pour choisir le type de données (sans lui, le formulaire importe le premier type de la liste : <?= e($types[array_key_first($types)]['libelle']) ?>).</p></noscript>
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


<script nonce="<?= e(csp_nonce()) ?>">
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
    var champFichier = document.getElementById('import-champ-fichier');
    var champEmails = document.getElementById('import-champ-emails');
    var emails = document.getElementById('import-emails');

    function applique() {
        var c = configs[sel.value];
        if (!c) return;
        form.action = c.action;
        fichier.accept = c.accept;
        btnSim.hidden = !c.simuler;
        note.hidden = !c.simuler;
        lblGo.textContent = c.bouton;
        btnGo.dataset.confirm = c.confirm || '';
        // Rouge pour le seul type qui REMPLACE au lieu d'ajouter : dans un menu
        // où tout se ressemble, la couleur du bouton est ce qui distingue
        // « j'ajoute quelques lignes » de « je remplace tout ».
        btnGo.classList.toggle('danger', !!c.danger);
        // Assistant structures : le POST d'upload attend etape=mapper.
        if (c.etape) { etape.value = c.etape; etape.disabled = false; } else { etape.disabled = true; }
        // Un type se colle (la liste « ne pas contacter »), les autres se
        // téléversent : on échange le champ, et on DÉSACTIVE celui qu'on cache
        // — un champ requis mais invisible empêcherait l'envoi du formulaire.
        if (champEmails) {
            var saisie = !!c.saisie;
            champFichier.hidden = saisie;
            fichier.disabled = saisie;
            fichier.required = !saisie;
            champEmails.hidden = !saisie;
            emails.disabled = !saisie;
            emails.required = saisie;
        }
        document.querySelectorAll('.import-aide').forEach(function (p) { p.hidden = p.dataset.for !== sel.value; });
        try { localStorage.setItem('importType', sel.value); } catch (e) {}
    }
    // La confirmation elle-même est prise en charge par l'écouteur global de
    // [data-confirm] (assets/app.js) : la dupliquer ici afficherait deux boîtes
    // de dialogue à la suite. Seule la VALEUR du message est posée ici, car
    // elle dépend du type d'import sélectionné (voir applique() ci-dessus).
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
