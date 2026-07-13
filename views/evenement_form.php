<?php
/** @var ?array $evenement */ /** @var int $id */ /** @var array $spectacles */ /** @var array $spectacleMap */
/** @var array $employesLies */ /** @var array $employesDispo */ /** @var array $prestations */
/** @var array $fichesParEmploye */ /** @var array $unites */ /** @var array $tauxHoraires */
/** @var array $factures */ /** @var array $facturesDispo */ /** @var ?array $organisateur */ /** @var array $debiteursDispo */
/** @var array $paysDisponibles */ /** @var array $axes */ /** @var ?string $err */ /** @var array $post */
$isEdit = $id > 0;
$v = fn (string $k, $d = '') => e((string) ($post[$k] ?? $evenement[$k] ?? $d));
$vRaw = fn (string $k, $d = '') => (string) ($post[$k] ?? $evenement[$k] ?? $d);
$retour = $isEdit ? '?p=evenement&id=' . (int) $id : '?p=evenements_liste';
// Reporté sur les formulaires de cette page qui redirigent vers elle-même,
// pour que le lien de retour contextuel (lien_retour_contextuel()) survive à
// un enregistrement (voir redirect() dans lib/helpers.php).
$depuisQs = isset($_GET['depuis']) ? '&depuis=' . rawurlencode($_GET['depuis']) : '';
$ok = $_GET['ok'] ?? null;
$errLigne = $_GET['errLigne'] ?? null;
$errEmploye = $_GET['errEmploye'] ?? null;
$errOrganisateur = $_GET['errOrganisateur'] ?? null;
$errProdExterne = $_GET['errProdExterne'] ?? null;
$prodExterne = (bool) ($evenement['production_externe'] ?? false);

$confirmSuppr = null;
if ($isEdit) {
    $nbFiches = count(evenement_fiche_ids($id));
    $impacts = [];
    if ($employesLies) $impacts[] = count($employesLies) . ' employé(s) lié(s)';
    if ($nbFiches) $impacts[] = $nbFiches . ' fiche(s) de salaire liée(s)';
    if ($factures) $impacts[] = count($factures) . ' facture(s) qui perdront ce lien';
    $confirmSuppr = 'Supprimer cet événement ?' . ($impacts ? ' ' . implode(', ', $impacts) . '.' : '');
}
$uniteOpts = options_unites($unites);
$tauxOpts  = options_taux_horaires($tauxHoraires);

// Options de l'axe analytique par défaut (carte « Comptabilité analytique ») et
// pour la ligne d'ajout de prestation — même présentation que fiche_form.php.
$axeOpts = options_axes($axes);
$axeSelect = function (string $name, string $class, int $selected, bool $hidden = false) use ($axeOpts): string {
    $html = preselectionner_option($axeOpts, $selected ? (string) $selected : '');
    return '<select name="' . e($name) . '" class="' . e($class) . '"' . ($hidden ? ' hidden' : '') . '>' . $html . '</select>';
};
// Le spectacle déjà lié peut avoir gagné des enfants depuis (devenu un groupe
// « artiste », non assignable) : on le garde visible dans le select pour ne pas
// changer silencieusement l'événement au prochain enregistrement.
$spectacleActuelId = (int) $vRaw('spectacle_id', '0');
if ($spectacleActuelId && !array_filter($spectacles, fn($s) => (int) $s['id'] === $spectacleActuelId)) {
    if (isset($spectacleMap[$spectacleActuelId])) {
        $spectacles[] = ['id' => $spectacleActuelId, 'nom' => spectacle_chemin($spectacleActuelId, $spectacleMap) . ' (groupe, non réassignable)'];
    }
}
?>
<?= lien_retour_contextuel('?p=evenements_liste', 'Événements') ?>
<div class="page-head">
    <h1><?= $isEdit ? "Modifier l'événement" : 'Nouvel événement' ?></h1>
    <?php if ($isEdit): ?>
    <div class="head-actions">
        <form method="post" action="?p=evenement_delete" class="d-inline" onsubmit="return confirm(<?= e(json_encode($confirmSuppr, JSON_UNESCAPED_UNICODE)) ?>);">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int) $id ?>">
            <button type="submit" class="btn danger icon-only" title="Supprimer" aria-label="Supprimer"><?= icon('trash') ?></button>
        </form>
    </div>
    <?php endif; ?>
</div>

<?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>

<div class="card">
    <h2 class="mt-0">Informations</h2>
    <?php if ($ok === 'infos'): ?><p class="ok flash">Informations enregistrées.</p><?php endif; ?>
    <form method="post" action="?p=evenement<?= $isEdit ? '&id=' . (int) $id : '' ?><?= $depuisQs ?>" class="form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int) $id ?>"><?php endif; ?>

        <div class="grid4">
            <label>Date <input type="date" name="date" value="<?= $v('date') ?>" required></label>
            <label><?= e(evenements_terme_spectacle(false)) ?>
                <select name="spectacle_id">
                    <option value="">—</option>
                    <?php foreach ($spectacles as $s): ?>
                        <option value="<?= (int) $s['id'] ?>" <?= $vRaw('spectacle_id') === (string) $s['id'] ? 'selected' : '' ?>><?= e($s['nom']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Statut
                <select name="statut">
                    <?php foreach (EVENEMENTS_STATUTS as $s): ?>
                        <option value="<?= $s ?>" <?= $vRaw('statut', 'option') === $s ? 'selected' : '' ?>><?= e(evenement_statut_libelle($s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><span>Type d'audience <?= info_tip(
                "Public : affiché sur le site avec ville, salle, festival, lien, " . mb_strtolower(evenements_terme_spectacle(false)) . " et remarques. "
                . "Privé : seule la date apparaît, avec la mention « Événement privé ». "
                . "Non répertorié : n'apparaît jamais sur le site (usage interne)."
            ) ?></span>
                <select name="visibilite">
                    <?php foreach (EVENEMENTS_VISIBILITES as $vi): ?>
                        <option value="<?= $vi ?>" <?= $vRaw('visibilite', 'non_repertorie') === $vi ? 'selected' : '' ?>><?= e(evenement_visibilite_libelle($vi)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <div class="grid4">
            <label>Ville <input name="ville" value="<?= $v('ville') ?>"></label>
            <label>Région et pays
                <div class="field-pair">
                    <input name="region" value="<?= $v('region') ?>" placeholder="canton ou département">
                    <select name="pays">
                        <option value="">—</option>
                        <?php foreach ($paysDisponibles as $p): ?>
                            <option value="<?= e($p) ?>" <?= $vRaw('pays') === $p ? 'selected' : '' ?>><?= e($p) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </label>
            <label>Salle <input name="salle" value="<?= $v('salle') ?>"></label>
            <label>Festival <input name="festival" value="<?= $v('festival') ?>"></label>
        </div>
        <div class="grid3">
            <label>Lien <input type="url" name="lien_infos" value="<?= $v('lien_infos') ?>" placeholder="https://…"></label>
            <label>Texte du bouton de lien <input name="lien_texte" value="<?= $v('lien_texte') ?>" placeholder="Plus d'informations"></label>
            <label>Remarques <input name="remarques" value="<?= $v('remarques') ?>"></label>
        </div>

        <div class="form-actions">
            <button type="submit"><?= icon('save') ?> Enregistrer</button>
            <a class="btn ghost" href="<?= e($retour) ?>">Annuler</a>
        </div>
    </form>
</div>

<?php if ($isEdit): ?>
<div class="card mt-22">
    <div class="page-head">
        <h2 class="mt-0">Suivi SUISA</h2>
        <?= evenement_suisa_badge($evenement) ?>
    </div>
    <?php if ($ok === 'suisa'): ?><p class="ok flash">Suivi SUISA enregistré.</p><?php endif; ?>
    <form method="post" action="?p=evenement_suisa<?= $depuisQs ?>" class="form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= (int) $id ?>">
        <?php $suisaApplicable = (bool) $evenement['suisa_applicable']; ?>
        <label class="check">
            <input type="checkbox" name="suisa_applicable" id="suisa-applicable" value="1" <?= $suisaApplicable ? 'checked' : '' ?>>
            La SUISA s'applique à cet événement
        </label>
        <div class="grid3" id="suisa-champs" <?= $suisaApplicable ? '' : 'hidden' ?>>
            <label>Envoyée à
                <select name="suisa_envoye_a" <?= $suisaApplicable ? '' : 'disabled' ?>>
                    <option value="">—</option>
                    <?php foreach (EVENEMENTS_SUISA_ENVOYE_A as $ea): ?>
                        <option value="<?= e($ea) ?>" <?= $vRaw('suisa_envoye_a') === $ea ? 'selected' : '' ?>><?= e(evenement_suisa_envoye_a_libelle($ea)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Date d'envoi <input type="date" name="suisa_envoye_le" value="<?= $v('suisa_envoye_le') ?>" <?= $suisaApplicable ? '' : 'disabled' ?>></label>
            <label>Date du décompte <input type="date" name="suisa_decompte_le" value="<?= $v('suisa_decompte_le') ?>" <?= $suisaApplicable ? '' : 'disabled' ?>></label>
        </div>
        <div class="form-actions">
            <button type="submit"><?= icon('save') ?> Enregistrer</button>
            <a class="btn ghost" href="<?= e($retour) ?>">Annuler</a>
        </div>
    </form>
</div>

<?php if ($axes): ?>
<div class="card mt-22">
    <h2 class="mt-0">Comptabilité analytique</h2>
    <?php if ($ok === 'axe'): ?><p class="ok flash">Axe par défaut enregistré.</p><?php endif; ?>
    <form method="post" action="?p=evenement_axe_defaut<?= $depuisQs ?>" class="form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= (int) $id ?>">
        <label><span>Axe par défaut <?= info_tip(
            "Présélectionné pour les nouvelles prestations ajoutées ci-dessous et pour les lignes "
            . "d'une facture créée depuis cet événement. Modifiable au cas par cas ensuite, sans "
            . "effet rétroactif sur les prestations ou factures déjà enregistrées."
        ) ?></span>
            <?= $axeSelect('axe_analytique_id_defaut', '', (int) ($evenement['axe_analytique_id_defaut'] ?? 0)) ?>
        </label>
        <div class="form-actions">
            <button type="submit"><?= icon('save') ?> Enregistrer</button>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="card mt-22" id="carte-employes">
    <div class="page-head">
        <h2 class="mt-0">Employés <?= info_tip(
            "Une fiche de salaire ne peut être liée que via un employé lié — une seule ligne de "
            . "prestation par événement. Pour un cachet couvrant plusieurs dates, ajoutez la "
            . "prestation depuis un seul des événements de la tournée et liez les autres depuis "
            . "la fiche elle-même."
        ) ?></h2>
        <div class="head-actions">
            <form method="post" action="?p=evenement_production_externe<?= $depuisQs ?>" id="prod-externe-form">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= (int) $id ?>">
                <label class="check">
                    <input type="checkbox" name="production_externe" id="prod-externe-check" value="1" <?= $prodExterne ? 'checked' : '' ?>>
                    Production externe
                </label>
            </form>
            <?php if ($employesDispo): ?>
                <form method="post" action="?p=evenement_employe_lier<?= $depuisQs ?>" class="linked-add">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= (int) $id ?>">
                    <select name="employe_id">
                        <?php foreach ($employesDispo as $emp): ?>
                            <option value="<?= (int) $emp['id'] ?>"><?= e($emp['prenom'] . ' ' . $emp['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn ghost"><?= icon('user-plus') ?> Ajouter un employé</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($errLigne === '1'): ?><p class="err">Prestation invalide : vérifiez l'unité, la quantité et le taux horaire.</p><?php endif; ?>
    <?php if ($errLigne === 'payee'): ?><p class="err">La fiche de ce mois a déjà été payée : créez plutôt une fiche complémentaire depuis « Fiches de salaire ».</p><?php endif; ?>
    <?php if ($errEmploye === 'paye'): ?><p class="err">Impossible de retirer cet employé : sa prestation pour cet événement a déjà été payée.</p><?php endif; ?>
    <?php if ($errProdExterne === 'paye'): ?><p class="err">Impossible d'activer « Production externe » : une prestation liée est déjà sur une fiche payée (figée, jamais modifiée). Retirez-la manuellement d'abord.</p><?php endif; ?>

    <?php if (!$employesLies): ?>
        <p class="muted small">Aucun employé lié.</p>
    <?php elseif ($prodExterne): ?>
        <!-- Production externe : pas de prestation/fiche de salaire à gérer ici,
             juste la liste des employés (cachet géré par l'organisateur externe). -->
        <div class="table-scroll">
        <table class="list evenement-employes">
            <thead><tr><th>Employé</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($employesLies as $emp): ?>
                <tr>
                    <td><?= e($emp['prenom'] . ' ' . $emp['nom']) ?></td>
                    <td class="epf-actions-cell">
                        <form method="post" action="?p=evenement_employe_delier<?= $depuisQs ?>" onsubmit="return confirm('Retirer cet employé de l\'événement ?');">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="id" value="<?= (int) $id ?>">
                            <input type="hidden" name="employe_id" value="<?= (int) $emp['id'] ?>">
                            <button type="submit" class="btn ghost btn-sm icon-only" title="Retirer l'employé" aria-label="Retirer l'employé"><?= icon('trash') ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php else: ?>
        <?php $colspanMsg = 4 + ($axes ? 1 : 0); ?>
        <div class="table-scroll">
        <table class="list evenement-employes">
            <thead><tr>
                <th>Employé</th><th>Fiche de salaire</th><?php if ($axes): ?><th>Axe</th><?php endif; ?>
                <th>Durée et taux horaire</th><th class="num">Total brut</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($employesLies as $emp):
                $eid = (int) $emp['id'];
                $ligne = $prestations[$eid] ?? null;
                $fichesEmp = $fichesParEmploye[$eid] ?? [];
                $moisEvenement = $vRaw('date') !== '' ? $vRaw('date') : ($evenement['date'] ?? date('Y-m-d'));
                $formId = 'pf-' . $eid;
                $axeLabel = '';
                if ($ligne) {
                    foreach ($axes as $ax) {
                        if ((int) $ax['id'] === (int) ($ligne['axe_analytique_id'] ?? 0)) { $axeLabel = $ax['code'] ?: $ax['libelle']; break; }
                    }
                }
                $totalBrut = $ligne ? (float) $ligne['heures_unite'] * (float) $ligne['quantite'] * (float) $ligne['taux_horaire'] : 0;
            ?>
                <tr>
                    <td><?= e($emp['prenom'] . ' ' . $emp['nom']) ?></td>
                    <?php if (!$unites || !$tauxHoraires): ?>
                        <td colspan="<?= $colspanMsg ?>" class="muted small">
                            Configurez au moins une unité de temps et un taux horaire (Paramètres &gt; Employeur) pour ajouter une prestation.
                        </td>
                    <?php else:
                        $huSel = $ligne ? $ligne['heures_unite'] . '|' . $ligne['libelle'] : '';
                        $tauxSel = '';
                        if ($ligne) {
                            $match = null;
                            foreach ($tauxHoraires as $th) {
                                if ((float) $th['montant'] === (float) $ligne['taux_horaire']) { $match = (string) $th['montant']; break; }
                            }
                            $tauxSel = $match ?? 'autre';
                        }
                    ?>
                        <td class="epf-col-sm">
                            <?php if ($ligne): ?>
                                <span class="epf-disp"><a href="<?= e(url_avec_retour('?p=fiche&id=' . (int) $ligne['fiche_id'], 'evenement', $id)) ?>"><?= e(mois_nom((int) $ligne['mois']) . ' ' . $ligne['annee']) ?></a></span>
                            <?php endif; ?>
                            <select form="<?= e($formId) ?>" name="fiche_id" class="fiche-select-sm epf-editable"<?= $ligne ? ' hidden' : '' ?>>
                                <option value="">— Créer une fiche (<?= e(mois_nom((int) substr($moisEvenement, 5, 2)) . ' ' . substr($moisEvenement, 0, 4)) ?>) —</option>
                                <?php foreach ($fichesEmp as $f): ?>
                                    <option value="<?= (int) $f['id'] ?>" <?= $ligne && (int) $ligne['fiche_id'] === (int) $f['id'] ? 'selected' : '' ?>><?= e(mois_nom((int) $f['mois']) . ' ' . $f['annee']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <?php if ($axes): ?>
                        <td class="epf-col-sm">
                            <?php if ($ligne): ?>
                                <span class="epf-disp"><?= e($axeLabel !== '' ? $axeLabel : '—') ?></span>
                            <?php endif; ?>
                            <?= str_replace('name="l_axe"', 'form="' . e($formId) . '" name="l_axe"', $axeSelect('l_axe', 'l-axe epf-editable', (int) ($ligne['axe_analytique_id'] ?? ($evenement['axe_analytique_id_defaut'] ?? 0)), (bool) $ligne)) ?>
                        </td>
                        <?php endif; ?>
                        <td class="epf-col-sm">
                            <div class="epf-duree">
                                <?php if ($ligne): ?>
                                    <span class="epf-disp"><?= e($ligne['libelle'] . ' × ' . nombre_court((float) $ligne['quantite']) . ' — ' . chf((float) $ligne['taux_horaire']) . ' CHF/h') ?></span>
                                <?php endif; ?>
                                <select form="<?= e($formId) ?>" name="l_unite" class="l-unite epf-editable"<?= $ligne ? ' hidden' : '' ?>><?= preselectionner_option($uniteOpts, $huSel) ?></select>
                                <input form="<?= e($formId) ?>" name="l_quantite" class="l-qte epf-editable" type="text" inputmode="decimal" placeholder="qté" value="<?= $ligne ? e(nombre_court((float) $ligne['quantite'])) : '' ?>"<?= $ligne ? ' hidden' : '' ?>>
                                <select form="<?= e($formId) ?>" name="l_taux_choix" class="l-taux-choix epf-editable"<?= $ligne ? ' hidden' : '' ?>><?= preselectionner_option($tauxOpts, $tauxSel) ?></select>
                                <input form="<?= e($formId) ?>" name="l_taux_manuel" class="l-taux-manuel epf-editable" type="text" inputmode="decimal" placeholder="CHF/h" value="<?= ($ligne && $tauxSel === 'autre') ? e(nombre_court((float) $ligne['taux_horaire'])) : '' ?>"<?= $ligne ? ' hidden' : '' ?>>
                            </div>
                        </td>
                        <td class="num"><span class="epf-total-live"><?= $totalBrut > 0 ? chf($totalBrut) . ' CHF' : '—' ?></span></td>
                        <td class="epf-actions-cell">
                            <form id="<?= e($formId) ?>" method="post" action="?p=evenement_ligne_ajouter<?= $depuisQs ?>">
                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="id" value="<?= (int) $id ?>">
                                <input type="hidden" name="employe_id" value="<?= $eid ?>">
                            </form>
                            <div class="epf-actions">
                                <button type="button" form="<?= e($formId) ?>" class="btn ghost btn-sm icon-only epf-edit-btn" title="Modifier" aria-label="Modifier"<?= $ligne ? '' : ' hidden' ?>><?= icon('pencil') ?></button>
                                <button type="submit" form="<?= e($formId) ?>" class="btn btn-sm icon-only epf-editable" title="Enregistrer la prestation" aria-label="Enregistrer la prestation"<?= $ligne ? ' hidden' : '' ?>><?= icon('save') ?></button>
                                <button type="submit" form="<?= e($formId) ?>" formaction="?p=evenement_employe_delier<?= $depuisQs ?>" class="btn ghost btn-sm icon-only epf-editable" title="Retirer l'employé" aria-label="Retirer l'employé"<?= $ligne ? ' hidden' : '' ?>><?= icon('trash') ?></button>
                            </div>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<?php if (module_actif('facturation')): ?>
<div class="card mt-22">
    <h2 class="mt-0">Organisateur <?= info_tip(
        "Débiteur à facturer pour cet événement (recherché parmi les débiteurs existants, ou créé "
        . "à la volée). Présélectionné à la création d'une facture liée. Un seul organisateur à la "
        . "fois : relier un autre débiteur remplace le précédent."
    ) ?></h2>
    <?php if ($ok === 'organisateur'): ?><p class="ok flash">Organisateur enregistré.</p><?php endif; ?>
    <?php if ($errOrganisateur === '1'): ?><p class="err">Le nom du nouveau débiteur est obligatoire.</p><?php endif; ?>

    <?php if ($organisateur):
        $adrOrg = trim($organisateur['adresse_rue'] . ' ' . trim($organisateur['adresse_npa'] . ' ' . $organisateur['adresse_localite']));
    ?>
        <div class="linked-add">
            <span>
                <strong><?= e($organisateur['nom']) ?></strong>
                <?php if ($adrOrg !== ''): ?><span class="muted small"> — <?= e($adrOrg) ?></span><?php endif; ?>
                <?php if ($organisateur['email']): ?><span class="muted small"> — <?= e($organisateur['email']) ?></span><?php endif; ?>
                <?php if ($organisateur['telephone']): ?><span class="muted small"> — <?= e($organisateur['telephone']) ?></span><?php endif; ?>
                <?php if ($organisateur['personne_contact']): ?><span class="muted small"> — <?= e($organisateur['personne_contact']) ?></span><?php endif; ?>
            </span>
            <a class="btn ghost btn-sm" href="?p=debiteur&id=<?= (int) $organisateur['id'] ?>">Voir la fiche</a>
            <form method="post" action="?p=evenement_organisateur_delier<?= $depuisQs ?>" onsubmit="return confirm('Délier cet organisateur ?');">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= (int) $id ?>">
                <button type="submit" class="btn ghost btn-sm icon-only" title="Délier" aria-label="Délier"><?= icon('x') ?></button>
            </form>
        </div>
    <?php else: ?>
        <p class="muted small">Aucun organisateur lié.</p>
    <?php endif; ?>

    <form method="post" action="?p=evenement_organisateur_lier<?= $depuisQs ?>" class="linked-add" id="organisateur-form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= (int) $id ?>">
        <div class="cat-search organisateur-search">
            <input type="text" class="cat-search-input" placeholder="Rechercher un débiteur…" autocomplete="off">
            <input type="hidden" name="debiteur_id" class="cat-search-val" value="">
            <ul class="cat-search-list" hidden role="listbox">
                <li data-val="__new__">+ Nouveau débiteur</li>
                <?php foreach ($debiteursDispo as $d): ?>
                    <li data-val="<?= (int) $d['id'] ?>"><?= e($d['nom']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <button type="submit" class="btn ghost btn-sm"><?= $organisateur ? 'Changer' : 'Lier' ?> l'organisateur</button>

        <div id="organisateur-nouveau" class="grid2" hidden>
            <label>Nom / raison sociale <input name="org_nom"></label>
            <label>Type
                <select name="org_type">
                    <option value="organisation">Organisation</option>
                    <option value="particulier">Particulier</option>
                </select>
            </label>
            <label>Rue et numéro <input name="org_adresse_rue"></label>
            <label>NPA <input name="org_adresse_npa"></label>
            <label>Localité <input name="org_adresse_localite"></label>
            <label>Pays <input name="org_adresse_pays" value="Suisse"></label>
            <label>E-mail (optionnel) <input name="org_email" type="email"></label>
            <label>Téléphone (optionnel) <input name="org_telephone" type="tel"></label>
            <label>Personne de contact (optionnel) <input name="org_personne_contact"></label>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="card mt-22">
    <div class="page-head">
        <h2 class="mt-0">Factures liées</h2>
        <?php if (module_actif('facturation')): ?>
            <a class="btn ghost" href="?p=facturation_form&evenement_id=<?= (int) $id ?>"><?= icon('file-plus') ?> Créer une facture liée</a>
        <?php endif; ?>
    </div>
    <?php if (!$factures): ?>
        <p class="muted small">Aucune facture liée à cet événement.</p>
    <?php else: ?>
        <table class="list">
            <thead><tr><th>Numéro</th><th>Débiteur</th><th class="num">Montant</th><th>Statut</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($factures as $fa): ?>
                <tr>
                    <td><a href="<?= e(url_avec_retour('?p=facture&id=' . (int) $fa['id'], 'evenement', $id)) ?>"><?= $fa['numero'] !== '' ? e($fa['numero']) : '<span class="muted">(brouillon)</span>' ?></a></td>
                    <td><?= e($fa['debiteur_nom']) ?></td>
                    <td class="num strong"><?= chf((float) $fa['montant_total']) ?></td>
                    <td><?= facturation_badge($fa) ?></td>
                    <td>
                        <form method="post" action="?p=evenement_facture_delier<?= $depuisQs ?>" onsubmit="return confirm('Délier cette facture de l\'événement ?');">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="id" value="<?= (int) $id ?>">
                            <input type="hidden" name="facture_id" value="<?= (int) $fa['id'] ?>">
                            <button type="submit" class="btn ghost btn-sm" title="Délier" aria-label="Délier"><?= icon('x') ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <?php if (module_actif('facturation') && $facturesDispo): ?>
        <form method="post" action="?p=evenement_facture_lier<?= $depuisQs ?>" class="linked-add mt-18">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int) $id ?>">
            <div class="cat-search facture-search">
                <input type="text" class="cat-search-input" placeholder="Rechercher une facture à lier…" autocomplete="off">
                <input type="hidden" name="facture_id" class="cat-search-val" value="">
                <ul class="cat-search-list" hidden role="listbox">
                    <?php foreach ($facturesDispo as $fa):
                        $label = ($fa['numero'] !== '' ? $fa['numero'] : '(brouillon)') . ' — ' . $fa['debiteur_nom'] . ' — ' . chf((float) $fa['montant_total']) . ' CHF';
                    ?>
                        <li data-val="<?= (int) $fa['id'] ?>"><?= e($label) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <button type="submit" class="btn ghost btn-sm">Lier une facture existante</button>
        </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
(function () {
    const suisaCheck = document.getElementById('suisa-applicable');
    if (suisaCheck) {
        const suisaChamps = document.getElementById('suisa-champs');
        suisaCheck.addEventListener('change', () => {
            suisaChamps.hidden = !suisaCheck.checked;
            suisaChamps.querySelectorAll('select, input').forEach(el => { el.disabled = !suisaCheck.checked; });
        });
    }

    // Case « Production externe » : cocher détache les prestations déjà liées
    // (côté serveur, route_evenement_production_externe()) — confirmation avant
    // de soumettre si des prestations existent. Décocher ne supprime rien.
    const prodCheck = document.getElementById('prod-externe-check');
    if (prodCheck) {
        const aDesPrestations = <?= json_encode((bool) array_filter($prestations)) ?>;
        prodCheck.addEventListener('change', () => {
            if (prodCheck.checked && aDesPrestations
                && !confirm('Cocher « Production externe » va supprimer les prestations déjà liées sur les fiches de salaire des employés de cet événement. Continuer ?')) {
                prodCheck.checked = false;
                return;
            }
            document.getElementById('prod-externe-form').requestSubmit();
        });
    }

    // Ligne de prestation (carte Employés) : les champs vivent dans des <td>
    // séparés (colonnes) mais partagent un même <form> via l'attribut form="…" —
    // on les manipule donc via la <tr> commune plutôt que via le <form>.
    document.querySelectorAll('.evenement-employes tbody tr').forEach(tr => {
        const unite  = tr.querySelector('.l-unite');
        const qte    = tr.querySelector('.l-qte');
        const choix  = tr.querySelector('.l-taux-choix');
        const manuel = tr.querySelector('.l-taux-manuel');
        const total  = tr.querySelector('.epf-total-live');
        if (!unite || !qte || !choix || !manuel) return; // ligne "configurez une unité…"

        const num = v => parseFloat((v || '').toString().replace(',', '.')) || 0;
        const sync = () => {
            manuel.style.display = choix.value === 'autre' ? '' : 'none';
            if (total) {
                const opt = unite.selectedOptions[0];
                const hu = opt ? num(opt.dataset.h) : 0;
                const t  = choix.value === 'autre' ? num(manuel.value) : num(choix.value);
                const montant = hu * num(qte.value) * t;
                total.textContent = montant > 0 ? (Math.round(montant * 100) / 100).toFixed(2) + ' CHF' : '—';
            }
        };
        [unite, choix].forEach(el => el.addEventListener('change', sync));
        [qte, manuel].forEach(el => el.addEventListener('input', sync));
        sync();
    });

    // Ligne de prestation : mode lecture (texte + crayon) tant que rien n'est
    // modifié, mode édition (tous les champs + disquette/corbeille) après un
    // clic sur le crayon — soumis en un seul formulaire, pas d'action séparée.
    document.addEventListener('click', ev => {
        const btn = ev.target.closest('.epf-edit-btn');
        if (!btn) return;
        const tr = btn.closest('tr');
        tr.querySelectorAll('.epf-disp').forEach(el => { el.hidden = true; });
        tr.querySelectorAll('.epf-editable').forEach(el => { el.hidden = false; });
        btn.hidden = true;
        const choix = tr.querySelector('.l-taux-choix');
        if (choix) choix.dispatchEvent(new Event('change'));
        const sel = tr.querySelector('.fiche-select-sm');
        if (sel) sel.focus();
    });

    // Ne pas revenir en haut de la page après Ajouter un employé / Enregistrer /
    // Retirer (carte Employés) — on restaure la position de défilement au retour.
    const carteEmployes = document.getElementById('carte-employes');
    if (carteEmployes) {
        const scrollKey = 'evenement-scroll-<?= (int) $id ?>';
        carteEmployes.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', () => {
                sessionStorage.setItem(scrollKey, String(window.scrollY));
            });
        });
        const savedScroll = sessionStorage.getItem(scrollKey);
        if (savedScroll !== null) {
            sessionStorage.removeItem(scrollKey);
            window.addEventListener('load', () => window.scrollTo(0, parseInt(savedScroll, 10)));
        }
    }

    // Recherche de facture à lier (même widget que le rapprochement d'écriture/catégorie).
    document.querySelectorAll('.facture-search').forEach(wrap => {
        const input = wrap.querySelector('.cat-search-input');
        lassoInitCatSearch(wrap, {
            showPlaceholderText: true,
            clearHiddenOnInput: true,
            onSelect: () => input.setCustomValidity(''),
        });
    });
    document.querySelectorAll('.facture-search').forEach(wrap => {
        wrap.closest('form').addEventListener('submit', e => {
            const hidden = wrap.querySelector('.cat-search-val');
            const input = wrap.querySelector('.cat-search-input');
            if (!hidden.value) {
                input.setCustomValidity('Veuillez choisir une facture dans la liste');
                input.reportValidity();
                e.preventDefault();
            }
        });
    });

    // Organisateur (carte du même nom) : même widget de recherche, avec en plus
    // une option « + Nouveau débiteur » qui révèle les champs de création rapide.
    const organisateurWrap = document.querySelector('.organisateur-search');
    if (organisateurWrap) {
        const orgInput   = organisateurWrap.querySelector('.cat-search-input');
        const orgHidden  = organisateurWrap.querySelector('.cat-search-val');
        const orgNouveau = document.getElementById('organisateur-nouveau');
        lassoInitCatSearch(organisateurWrap, {
            showPlaceholderText: true,
            clearHiddenOnInput: true,
            onSelect: li => {
                orgInput.setCustomValidity('');
                orgNouveau.hidden = li.dataset.val !== '__new__';
            },
        });
        document.getElementById('organisateur-form').addEventListener('submit', e => {
            if (!orgHidden.value) {
                orgInput.setCustomValidity('Veuillez choisir un débiteur dans la liste, ou « + Nouveau débiteur »');
                orgInput.reportValidity();
                e.preventDefault();
            }
        });
    }
})();
</script>
