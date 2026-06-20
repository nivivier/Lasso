<?php /** @var array $emp */ /** @var array $fiches */ ?>
<?= lien_retour('?p=employes', 'Employés') ?>
<?php if (($_GET['err'] ?? '') === 'fiches'): ?><p class="err">Impossible de supprimer : cet employé a des fiches de salaire.</p><?php endif; ?>
<div class="page-head">
    <h1><?= e($emp['prenom'] . ' ' . $emp['nom']) ?>
        <?php if (!$emp['actif']): ?><span class="badge">inactif</span><?php endif; ?>
    </h1>
    <div class="head-actions">
        <a class="btn ghost" href="?p=employe&id=<?= (int) $emp['id'] ?>"><?= icon('pencil') ?> Modifier l'employé</a>
        <?php if (!$fiches): ?>
            <form method="post" action="?p=employe_delete" onsubmit="return confirm('Supprimer définitivement cet employé ?');" class="d-inline">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= (int) $emp['id'] ?>">
                <button type="submit" class="btn danger icon-only" title="Supprimer" aria-label="Supprimer l'employé"><?= icon('trash') ?></button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-22">
    <h2 class="mt-0">Informations</h2>
    <?php $adresse = trim($emp['rue'] . ($emp['rue'] && $emp['npa_localite'] ? ', ' : '') . $emp['npa_localite']); ?>
    <dl class="info-grid">
        <div><dt>Date de naissance</dt><dd><?= trim((string) ($emp['date_naissance'] ?? '')) !== '' ? e(date('d.m.Y', strtotime($emp['date_naissance']))) : '—' ?></dd></div>
        <div><dt>Numéro AVS</dt><dd><?= e($emp['numero_avs']) ?: '—' ?></dd></div>
        <div><dt>E-mail</dt><dd><?= $emp['email'] ? '<a href="mailto:' . e($emp['email']) . '">' . e($emp['email']) . '</a>' : '—' ?></dd></div>
        <div><dt>Adresse</dt><dd><?= $adresse !== '' ? e($adresse) : '—' ?></dd></div>
        <div><dt>Canton</dt><dd><?= e($emp['canton']) ?: '—' ?></dd></div>
        <div><dt>Procédure</dt><dd><?= e($emp['procedure']) ?: '—' ?></dd></div>
        <div><dt>Supplément vacances</dt><dd><?= pct((float) $emp['supplement_vacances']) ?></dd></div>
        <div><dt>Impôt à la source</dt><dd><?= $emp['procedure'] === 'Ordinaire avec impôt à la source' ? pct((float) $emp['impot_source_taux']) : '—' ?></dd></div>
    </dl>
</div>

<div class="page-head">
    <h2 class="mt-0 mb-0">Fiches de salaire</h2>
    <div class="head-actions">
        <?php if ($fiches): ?>
            <a class="btn ghost" href="?p=certificat&employe_id=<?= (int) $emp['id'] ?>"><?= icon('file-text') ?> Certificat de salaire</a>
        <?php endif; ?>
        <a class="btn" href="?p=fiche_new&employe_id=<?= (int) $emp['id'] ?>">+ Nouvelle fiche</a>
    </div>
</div>

<?php if (!$fiches): ?>
    <p class="muted">Aucune fiche de salaire pour cet employé.</p>
<?php else: ?>
<div class="table-scroll">
<table class="list list-wide">
    <thead>
        <tr><th>Mois</th><th class="num">Brut</th><th class="num">Net</th><th>Paiement</th><th class="num">Coût employeur</th><th class="center">Envoyée</th></tr>
    </thead>
    <tbody>
    <?php foreach ($fiches as $f): $apayer = trim((string) $f['date_paiement']) === ''; ?>
        <tr class="row-link" tabindex="0" role="link" data-href="?p=fiche&id=<?= (int) $f['id'] ?>">
            <td><?= e(mois_nom((int) $f['mois'])) ?> <?= (int) $f['annee'] ?></td>
            <td class="num col-brut"><?= chf((float) $f['salaire_brut']) ?></td>
            <td class="num strong <?= $apayer ? 'net-apayer' : '' ?>"><?= chf((float) $f['salaire_net']) ?></td>
            <td><?= badge_paiement($f) ?></td>
            <td class="num col-cout"><?= cout_emp_affiche($f) ?></td>
            <td class="center"><?php if (trim((string) ($f['email_envoye_le'] ?? '')) !== ''): ?><span class="mail-sent" title="Envoyée le <?= e(date('d.m.Y', strtotime((string) $f['email_envoye_le']))) ?>"><?= icon('check') ?></span><?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
