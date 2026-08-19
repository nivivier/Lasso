<?php /** @var array $candidats */ ?>
<?php require __DIR__ . '/_module_tabs.php'; ?>
<?php require __DIR__ . '/_page_head_band.php'; ?>
<?= lien_retour('?p=structures', 'Structures') ?>
<div class="page-head">
    <h1>Fusionner des structures</h1>
</div>

<p class="muted small">
    Choisissez la structure à conserver : ses propres informations (nom, adresse, catégorie…) sont
    gardées telles quelles. Les contacts, notes, factures, étiquettes, lieux liés et événements des
    autres structures sélectionnées lui sont rattachés, puis ces autres structures sont
    <strong>définitivement supprimées</strong>.
</p>

<?php if (!(peut_ecrire('facturation') || peut_ecrire('booking'))): ?>
<p class="err">Vous n'avez pas les droits d'écriture nécessaires pour cette action.</p>
<?php else: ?>
<form method="post" action="?p=structure_fusion" class="form">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <?php foreach ($candidats as $c): ?>
        <label class="card mt-16 fusion-candidat">
            <div class="linked-add">
                <input type="radio" name="garder_id" value="<?= (int) $c['id'] ?>" required>
                <span>
                    <strong><?= e($c['nom']) ?></strong>
                    <span class="muted small"> — <?= e($c['categorie']) ?></span>
                    <?php if ($c['sous_categorie']): ?><span class="muted small"> — <?= e($c['sous_categorie']) ?></span><?php endif; ?>
                </span>
            </div>
            <div class="muted small">
                <?= e(trim($c['adresse_rue'] . ' ' . $c['adresse_npa'] . ' ' . $c['adresse_localite'])) ?: '—' ?>
                <?php if ($c['email']): ?> — <?= e($c['email']) ?><?php endif; ?>
            </div>
            <div class="muted small mt-10">
                <?= (int) $c['nb_contacts'] ?> contact(s), <?= (int) $c['nb_notes'] ?> note(s),
                <?= (int) $c['nb_factures'] ?> facture(s), <?= (int) $c['nb_tags'] ?> étiquette(s),
                <?= (int) $c['nb_lieux'] ?> salle(s)/festival(s) lié(s), <?= (int) $c['nb_evenements'] ?> événement(s) lié(s)
            </div>
        </label>
    <?php endforeach; ?>
    <div class="form-actions mt-16">
        <button type="submit" data-confirm="Fusionner ces structures ? Les structures non conservées seront définitivement supprimées."><?= icon('save') ?> Fusionner</button>
        <a class="btn ghost" href="?p=structures">Annuler</a>
    </div>
</form>
<?php endif; ?>
