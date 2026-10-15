<?php
/** @var array $annees */ /** @var array $anneesCompta */ /** @var array $comptesCamt */ /** @var bool $errCamt */
/** @var bool $errSauvegarde */
/** @var array $anneesEvenements */

// Types de données exportables : un seul sélecteur, une seule carte — le
// contenu (description + formulaire) de chaque type est montré/masqué selon
// la sélection, sans reprendre le libellé du type en titre (déjà donné par le
// menu déroulant). Même esprit que le sélecteur de views/import.php.
// Le champ « Année » est lui aussi mutualisé (un seul <select>, à droite du
// type) : rattaché en JS au formulaire du type actif via l'attribut form=
// (même principe que les cases à cocher de l'onglet Incohérences), ses
// options étant reconstruites depuis $anneesParType ci-dessous.
// Le format dépend de ce que sait faire le serveur (lib/sauvegarde.php) : une
// archive quand l'extension zip ou phar est là — le cas courant —, la base
// seule sinon, auquel cas la carte le dit au lieu de laisser croire que les
// fichiers déposés sont dedans.
$formatSauvegarde = sauvegarde_format();
$typesExport = ['sauvegarde' => 'Sauvegarde complète (.' . $formatSauvegarde . ')'];
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

    <div class="export-bloc mt-16" data-type="sauvegarde">
        <?php if ($errSauvegarde ?? false): ?>
        <p class="err mb-8">La sauvegarde n'a pas pu être créée — disque plein, droits insuffisants, ou archive impossible à finaliser.
            Le détail est dans le journal d'erreurs du serveur. Rien n'a été téléchargé.</p>
        <?php endif; ?>
        <?php // ⚠️ La liste des modules se LIT dans MODULES, elle ne se récrit pas
              // ici. Écrite à la main, elle avait vieilli de trois chantiers : elle
              // ignorait le carnet d'adresses, les pièces et la recherche de fonds,
              // et rangeait encore les structures sous la facturation et les projets
              // sous les événements. Une sauvegarde qui n'annonce pas ce qu'elle
              // contient inquiète à juste titre, alors qu'elle n'avait rien perdu
              // (VACUUM INTO copie la base entière, il n'énumère aucune table).
              //
              // MODULES au complet, et non modules_actifs() : la phrase dit « quels
              // que soient les modules activés », et c'est vrai — un module éteint
              // garde ses tables, et la sauvegarde les emporte. ?>
        <p class="muted small mb-8">Copie intégrale de la base : <strong>toutes les tables</strong>, quels que soient les modules activés —
            <?= e(implode(', ', array_column(MODULES, 'label'))) ?>,
            ainsi que les paramètres, les comptes utilisateurs et les catégories (pays, régions, types de lieu).
            À conserver régulièrement en lieu sûr — c'est ta sauvegarde.</p>
        <?php if ($formatSauvegarde === 'sqlite'): ?>
        <p class="warn small mb-0"><?= icon('info') ?> Ce serveur ne sait produire ni archive ZIP ni archive TAR
            (extensions <code>zip</code> et <code>phar</code> absentes) : le téléchargement ne contient que la base.
            Les <strong>fichiers déposés</strong> — logos, photos, icônes, pièces jointes — sont à sauvegarder à part,
            en copiant les dossiers <code>uploads/</code> et <code>data/fichiers/</code>.</p>
        <?php else: ?>
        <p class="muted small mb-0"><?= icon('info') ?> L'archive contient aussi les <strong>fichiers déposés</strong> —
            logos, photos d'employés, icônes, feuilles SUISA, pièces jointes —, que la base ne fait que désigner sans
            les contenir. Une note de restauration est jointe à l'intérieur. Seul <code>lib/config.local.php</code>,
            la <strong>configuration du serveur</strong>, en est absent : il porte le secret d'installation et
            l'emplacement de la base, et se conserve à part.</p>
        <?php // ⚠️ Dire où sont les secrets, et lesquels. « L'archive ne contient
              // pas de mot de passe » serait faux : les mots de passe des BOÎTES
              // D'ENVOI sont en base, et en clair — un serveur SMTP demande le
              // mot de passe, pas son empreinte. Ceux des COMPTES, eux, n'y sont
              // qu'en empreinte bcrypt. Les deux sont dans l'archive, et ce
              // n'est pas la même chose : l'un se rejoue tel quel, l'autre non. ?>
        <p class="warn small mt-8 mb-0"><?= icon('info') ?> <strong>Une sauvegarde se range comme un document
            confidentiel.</strong> Elle porte toute la base : salaires nominatifs, carnet d'adresses, et les mots de passe
            des <strong>boîtes d'envoi</strong> (SMTP) <strong>en clair</strong> — un serveur de messagerie réclame le mot
            de passe lui-même. Les mots de passe des <strong>comptes de connexion</strong>, eux, n'y figurent qu'en
            empreinte bcrypt : ils ne se relisent pas, mais la sauvegarde restaurée rend à chacun le sien.</p>
        <?php endif; ?>
        <div class="form-actions">
            <a class="btn" href="?p=sauvegarde"><?= icon('download') ?> Télécharger la sauvegarde</a>
        </div>
    </div>

    <?php if (module_actif('compta')): ?>
    <div class="export-bloc mt-16" data-type="ecritures_csv" hidden>
        <p class="muted small mb-0">Exporte toutes les écritures d'une année au format CSV (séparateur « ; », encodage UTF-8). Chaque ligne contient la date, le texte, le tiers, le montant, le compte bancaire et la catégorie de lettrage.</p>
        <?php if (!$anneesCompta): ?>
            <div class="form-actions"><p class="muted mb-0">Aucune écriture à exporter.</p></div>
        <?php else: ?>
        <form method="get" action="index.php" id="export-form-ecritures_csv" class="form-actions">
            <input type="hidden" name="p" value="compta_ecritures_exporter_csv">
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
            <input type="hidden" name="p" value="compta_ecritures_exporter_camt053">
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
            <input type="hidden" name="p" value="certificat_exporter_xml">
            <button type="submit"><?= icon('download') ?> Télécharger le XML</button>
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
        <form method="get" action="index.php" id="export-form-evenements" class="form-actions">
            <input type="hidden" name="p" value="evenements_suisa_exporter">
            <input type="hidden" name="statut_suisa" value="tous">
            <input type="hidden" name="projet_id" value="0">
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
