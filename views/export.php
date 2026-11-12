<?php
/** @var array $annees */ /** @var array $anneesCompta */ /** @var array $comptesCamt */ /** @var bool $errCamt */
/** @var string $typeDemande */ /** @var int $anneeDemandee */ /** @var array $anneesEvenements */

// Types de données exportables : un seul sélecteur, une seule carte — le
// contenu (description + formulaire) de chaque type est montré/masqué selon
// la sélection, sans reprendre le libellé du type en titre (déjà donné par le
// menu déroulant). Même esprit que le sélecteur de views/import.php.
// Le champ « Année » est lui aussi mutualisé (un seul <select>, à droite du
// type) : rattaché en JS au formulaire du type actif via l'attribut form=
// (même principe que les cases à cocher de l'onglet Incohérences), ses
// options étant reconstruites depuis $anneesParType ci-dessous.
// La sauvegarde complète n'est pas un type de données : elle a sa page,
// ?p=sauvegardes (route_sauvegardes(), lib/routes.php, dit pourquoi).
$typesExport = [];
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

<?php if (!$typesExport): ?>
<p class="muted">Aucun module actif n'a de données à exporter.</p>
<?php else: ?>
<div class="card form">
    <h2 class="mt-0">Exporter des données</h2>
    <?php // Le choix et son geste sur une même ligne : type, année, puis le
          // bouton de téléchargement du type choisi, poussé à droite. Chaque
          // bouton vit ici et vise le formulaire de son bloc par form= (les
          // champs propres à un type — le compte du camt.053 — restent dans
          // le bloc). Faute de place, le bouton passe à la ligne. ?>
    <div class="export-choix">
        <label>Type de données à exporter
            <select id="export-type"<?= isset($typesExport[$typeDemande]) ? ' data-demande="' . e($typeDemande) . '"' : '' ?><?= $anneeDemandee > 0 ? ' data-annee="' . $anneeDemandee . '"' : '' ?>>
                <?php foreach ($typesExport as $cle => $lib): ?>
                    <option value="<?= e($cle) ?>"<?= $cle === $typeDemande ? ' selected' : '' ?>><?= e($lib) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label id="export-annee-wrap" hidden>Année
            <select id="export-annee" name="annee"></select>
        </label>
        <div class="export-telecharger">
            <?php if (module_actif('compta') && $anneesCompta): ?>
            <button type="submit" form="export-form-ecritures_csv" data-type="ecritures_csv" hidden><?= icon('download') ?> Télécharger le CSV</button>
            <?php endif; ?>
            <?php if (module_actif('compta') && $comptesCamt): ?>
            <button type="submit" form="export-form-ecritures_camt" data-type="ecritures_camt" hidden><?= icon('download') ?> Télécharger le XML</button>
            <?php endif; ?>
            <?php if (module_actif('salaires') && $annees): ?>
            <button type="submit" form="export-form-certificats" data-type="certificats" hidden><?= icon('download') ?> Télécharger le XML</button>
            <?php endif; ?>
            <?php if (module_actif('evenements') && $anneesEvenements): ?>
            <button type="submit" form="export-form-evenements" data-type="evenements" hidden><?= icon('download') ?> Télécharger le CSV</button>
            <?php endif; ?>
        </div>
    </div>
    <noscript><p class="muted small">JavaScript est requis pour choisir le type de données.</p></noscript>

    <?php if (module_actif('compta')): ?>
    <div class="export-bloc mt-16" data-type="ecritures_csv" hidden>
        <p class="muted small mb-0">Exporte toutes les écritures d'une année au format CSV (séparateur « ; », encodage UTF-8). Chaque ligne contient la date, le texte, le tiers, le montant, le compte bancaire et la catégorie de lettrage.</p>
        <?php if (!$anneesCompta): ?>
            <div class="form-actions"><p class="muted mb-0">Aucune écriture à exporter.</p></div>
        <?php else: ?>
        <form method="get" action="index.php" id="export-form-ecritures_csv" hidden>
            <input type="hidden" name="p" value="compta_ecritures_exporter_csv">
        </form>
        <?php endif; ?>
    </div>

    <div class="export-bloc mt-16" data-type="ecritures_camt" hidden>
        <p class="muted small mb-0">Exporte le relevé d'un compte bancaire au format bancaire normalisé <strong>ISO 20022 camt.053</strong> (XML), pour le réimporter dans un autre logiciel comptable (ou dans Lasso lui-même, voir Importer). Une seule IBAN par relevé : choisissez le compte.</p>
        <?php if (!$comptesCamt): ?>
            <div class="form-actions"><p class="muted mb-0">Aucun compte bancaire avec IBAN renseignée.</p></div>
        <?php else: ?>
        <form method="get" action="index.php" id="export-form-ecritures_camt" class="form-actions">
            <input type="hidden" name="p" value="compta_ecritures_exporter_camt053">
            <label class="inline">Compte
                <select name="compte">
                    <?php foreach ($comptesCamt as $c): ?>
                        <option value="<?= (int) $c['id'] ?>"><?= e($c['libelle']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
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
        <form method="get" action="index.php" id="export-form-certificats" hidden>
            <input type="hidden" name="p" value="certificat_exporter_xml">
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (module_actif('evenements')): ?>
    <div class="export-bloc mt-16" data-type="evenements" hidden>
        <p class="muted small mb-0">
            Exporte les événements au format CSV (séparateur « ; », encodage UTF-8) : date, projet,
            ville, département/canton, pays, salle, festival, suivi SUISA, et les coordonnées de
            l'organisateur lié le cas échéant. Même export que le bouton « Export SUISA » de la liste
            des événements, mais toujours sans filtre (hormis l'année ci-dessus) — indépendant des
            filtres actuellement mémorisés sur cette liste.
        </p>
        <?php if (!$anneesEvenements): ?>
            <div class="form-actions"><p class="muted mb-0">Aucun événement à exporter.</p></div>
        <?php else: ?>
        <form method="get" action="index.php" id="export-form-evenements" hidden>
            <input type="hidden" name="p" value="evenements_suisa_exporter">
            <input type="hidden" name="statut_suisa" value="tous">
            <input type="hidden" name="projet_id" value="0">
            <input type="hidden" name="statut" value="tous">
            <input type="hidden" name="visibilite" value="tous">
            <input type="hidden" name="pays" value="tous">
            <input type="hidden" name="salaries" value="tous">
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    var sel = document.getElementById('export-type');
    if (!sel) return;
    var anneeWrap = document.getElementById('export-annee-wrap');
    var anneeSel = document.getElementById('export-annee');
    var anneesParType = <?= json_encode($anneesParType, JSON_UNESCAPED_UNICODE) ?>;
    var anneeSansToutes = <?= json_encode($anneeSansToutes, JSON_UNESCAPED_UNICODE) ?>;

    function applique() {
        var type = sel.value;
        document.querySelectorAll('.export-bloc, .export-telecharger [data-type]').forEach(function (d) { d.hidden = d.dataset.type !== type; });

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
            // L'année d'un lien direct, si ce type la connaît.
            var anneeLien = sel.dataset.annee;
            if (anneeLien && anneeSel.querySelector('option[value="' + anneeLien + '"]')) anneeSel.value = anneeLien;
            var form = document.getElementById('export-form-' + type);
            if (form) { anneeSel.setAttribute('form', form.id); }
        } else {
            anneeWrap.hidden = true;
            anneeSel.removeAttribute('form');
        }
        try { localStorage.setItem('exportType', type); } catch (e) {}
    }
    sel.addEventListener('change', applique);

    // Un lien direct l'emporte sur le dernier type choisi.
    var actif = sel.dataset.demande || null;
    if (!actif) { try { actif = localStorage.getItem('exportType'); } catch (e) {} }
    if (actif && sel.querySelector('option[value="' + actif + '"]')) sel.value = actif;
    applique();
})();
</script>
