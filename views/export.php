<?php
/** @var array $annees */ /** @var array $anneesCompta */ /** @var array $comptesCamt */ /** @var bool $errCamt */
/** @var array $anneesEvenements */

// Types de données exportables : un seul sélecteur, une seule carte — le
// contenu (description + formulaire) de chaque type est montré/masqué selon
// la sélection, sans reprendre le libellé du type en titre (déjà donné par le
// menu déroulant). Même esprit que le sélecteur de views/import_fiches.php.
// Le champ « Année » est lui aussi mutualisé (un seul <select>, à droite du
// type) : rattaché en JS au formulaire du type actif via l'attribut form=
// (même principe que les cases à cocher de l'onglet Incohérences), ses
// options étant reconstruites depuis $anneesParType ci-dessous.
$typesExport = ['backup' => 'Sauvegarde complète (.sqlite)'];
if (module_actif('compta')) {
    $typesExport['ecritures_csv']  = 'Écritures comptables — CSV';
    $typesExport['ecritures_camt'] = 'Écritures comptables — CAMT.053';
}
if (module_actif('salaires')) {
    $typesExport['certificats'] = 'Certificats de salaire — XML (eCS CSI)';
}
if (module_actif('evenements')) {
    $typesExport['evenements'] = 'Événements — CSV (SUISA + organisateur)';
}
$anneesParType = array_filter([
    'ecritures_csv'  => module_actif('compta') ? $anneesCompta : null,
    'ecritures_camt' => module_actif('compta') ? $anneesCompta : null,
    'certificats'    => module_actif('salaires') ? $annees : null,
    'evenements'     => module_actif('evenements') ? $anneesEvenements : null,
], fn ($v) => $v !== null);
// Types dont le sélecteur d'année n'a pas d'option « Toutes les années »
// (une seule année exigée, ex. certificat de salaire).
$anneeSansToutes = ['certificats' => true];
?>
<?php require __DIR__ . '/_param_tabs.php'; ?>
<?php if ($errCamt): ?><p class="err flash">Choisissez un compte bancaire avec IBAN renseignée.</p><?php endif; ?>

<div class="card form">
    <h2 class="mt-0">Exporter des données</h2>
    <div class="grid2">
        <label>Type de données à exporter
            <select id="export-type">
                <?php foreach ($typesExport as $cle => $lib): ?>
                    <option value="<?= e($cle) ?>"><?= e($lib) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label id="export-annee-wrap" hidden>Année
            <select id="export-annee" name="annee"></select>
        </label>
    </div>
    <noscript><p class="muted small">JavaScript est requis pour choisir le type de données — sans lui, seule la sauvegarde complète ci-dessous reste disponible.</p></noscript>

    <div class="export-bloc mt-16" data-type="backup">
        <p class="muted small mb-8">Copie intégrale de la base dans un seul fichier <code>.sqlite</code> : <strong>toutes les tables</strong>, quels que soient les modules activés —
            salaires (employés, fiches, taux, unités), comptabilité (écritures, plan comptable, règles, axes analytiques),
            facturation (factures, structures), événements (événements, spectacles),
            booking (lieux, contacts, étiquettes, notes et historique, mailings, ciblages),
            ainsi que les paramètres, les comptes utilisateurs et les catégories (pays, régions, types de lieu).
            À conserver régulièrement en lieu sûr — c'est ta sauvegarde.</p>
        <p class="muted small mb-0"><?= icon('info') ?> Ne sont pas inclus : les <strong>logos</strong> déposés dans <code>uploads/</code>
            (la base ne mémorise que leur emplacement) et le fichier de configuration du serveur. Pour une restauration complète,
            sauvegarde aussi le dossier <code>uploads/</code>.</p>
        <div class="form-actions">
            <a class="btn" href="?p=backup"><?= icon('download') ?> Télécharger la sauvegarde</a>
        </div>
    </div>

    <?php if (module_actif('compta')): ?>
    <div class="export-bloc mt-16" data-type="ecritures_csv" hidden>
        <p class="muted small mb-0">Exporte toutes les écritures d'une année au format CSV (séparateur « ; », encodage UTF-8). Chaque ligne contient la date, le texte, le tiers, le montant, le compte bancaire et la catégorie de lettrage.</p>
        <?php if (!$anneesCompta): ?>
            <div class="form-actions"><p class="muted mb-0">Aucune écriture à exporter.</p></div>
        <?php else: ?>
        <form method="get" action="index.php" id="export-form-ecritures_csv" class="form-actions">
            <input type="hidden" name="p" value="compta_ecritures_csv">
            <button type="submit"><?= icon('download') ?> Télécharger le CSV</button>
        </form>
        <?php endif; ?>
    </div>

    <div class="export-bloc mt-16" data-type="ecritures_camt" hidden>
        <p class="muted small mb-0">Exporte le relevé d'un compte bancaire au format bancaire normalisé <strong>ISO 20022 camt.053</strong> (XML), pour le réimporter dans un autre logiciel comptable (ou dans Lasso lui-même, voir Importer). Une seule IBAN par relevé : choisissez le compte.</p>
        <?php if (!$comptesCamt): ?>
            <div class="form-actions"><p class="muted mb-0">Aucun compte bancaire avec IBAN renseignée.</p></div>
        <?php else: ?>
        <form method="get" action="index.php" id="export-form-ecritures_camt" class="form-actions">
            <input type="hidden" name="p" value="compta_ecritures_camt053">
            <label class="inline">Compte
                <select name="compte">
                    <?php foreach ($comptesCamt as $c): ?>
                        <option value="<?= (int) $c['id'] ?>"><?= e($c['libelle']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit"><?= icon('download') ?> Télécharger le XML</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (module_actif('salaires')): ?>
    <div class="export-bloc mt-16" data-type="certificats" hidden>
        <p class="muted small mb-0">
            Exporte les certificats de salaire de tous les employés d'une année au format XML
            « eCertificat de salaire CSI ». Importe ensuite ce fichier dans l'application officielle
            <strong>eCertificat de salaire CSI</strong> pour produire les PDF certifiés (avec code-barres).
        </p>
        <?php if (!$annees): ?>
            <div class="form-actions"><p class="muted mb-0">Aucune fiche de salaire à exporter.</p></div>
        <?php else: ?>
        <form method="get" action="index.php" id="export-form-certificats" class="form-actions">
            <input type="hidden" name="p" value="certificat_xml">
            <button type="submit"><?= icon('download') ?> Télécharger le XML</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (module_actif('evenements')): ?>
    <div class="export-bloc mt-16" data-type="evenements" hidden>
        <p class="muted small mb-0">
            Exporte les événements au format CSV (séparateur « ; », encodage UTF-8) : date, spectacle,
            ville, département/canton, pays, salle, festival, suivi SUISA, et les coordonnées de
            l'organisateur lié le cas échéant. Même export que le bouton « Export SUISA » de la liste
            des événements, mais toujours sans filtre (hormis l'année ci-dessus) — indépendant des
            filtres actuellement mémorisés sur cette liste.
        </p>
        <?php if (!$anneesEvenements): ?>
            <div class="form-actions"><p class="muted mb-0">Aucun événement à exporter.</p></div>
        <?php else: ?>
        <form method="get" action="index.php" id="export-form-evenements" class="form-actions">
            <input type="hidden" name="p" value="evenements_export_suisa">
            <input type="hidden" name="statut_suisa" value="tous">
            <input type="hidden" name="spectacle_id" value="0">
            <input type="hidden" name="statut" value="tous">
            <input type="hidden" name="visibilite" value="tous">
            <input type="hidden" name="pays" value="tous">
            <input type="hidden" name="salaries" value="tous">
            <button type="submit"><?= icon('download') ?> Télécharger le CSV</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    var sel = document.getElementById('export-type');
    var anneeWrap = document.getElementById('export-annee-wrap');
    var anneeSel = document.getElementById('export-annee');
    var anneesParType = <?= json_encode($anneesParType, JSON_UNESCAPED_UNICODE) ?>;
    var anneeSansToutes = <?= json_encode($anneeSansToutes, JSON_UNESCAPED_UNICODE) ?>;

    function applique() {
        var type = sel.value;
        document.querySelectorAll('.export-bloc').forEach(function (d) { d.hidden = d.dataset.type !== type; });

        var annees = anneesParType[type];
        if (annees) {
            anneeWrap.hidden = false;
            anneeSel.innerHTML = '';
            if (!anneeSansToutes[type]) {
                var optTous = document.createElement('option');
                optTous.value = '0';
                optTous.textContent = 'Toutes les années';
                anneeSel.appendChild(optTous);
            }
            annees.forEach(function (a) {
                var o = document.createElement('option');
                o.value = String(a); o.textContent = String(a);
                anneeSel.appendChild(o);
            });
            var form = document.getElementById('export-form-' + type);
            if (form) { anneeSel.setAttribute('form', form.id); }
        } else {
            anneeWrap.hidden = true;
            anneeSel.removeAttribute('form');
        }
        try { localStorage.setItem('exportType', type); } catch (e) {}
    }
    sel.addEventListener('change', applique);
    var actif = null;
    try { actif = localStorage.getItem('exportType'); } catch (e) {}
    if (actif && sel.querySelector('option[value="' + actif + '"]')) sel.value = actif;
    applique();
})();
</script>
