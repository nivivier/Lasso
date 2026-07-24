<?php /** @var array $candidats */ /** @var array $categoriesLieu */ ?>
<?= lien_retour('?p=structures', 'Structures') ?>
<div class="page-head">
    <h1>Transformer en salles / festivals</h1>
</div>

<p class="muted small">
    Choisissez l'<strong>organisateur</strong> parmi les structures sélectionnées : il reste une
    structure. Les autres deviennent des <strong>salles/festivals</strong> qui lui sont rattachés —
    leurs contacts, notes, factures et étiquettes sont repris par l'organisateur, puis ces structures
    sont <strong>définitivement supprimées</strong>.
</p>

<form method="post" action="?p=structure_transformer" class="form">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <div class="form-actions">
        <label>Type des lieux créés
            <select name="type">
                <option value="deduire">Déduire du nom (« festival » → Festival, sinon Salle)</option>
                <?php foreach ($categoriesLieu as $c): ?><option value="<?= e($c) ?>"><?= e($c) ?></option><?php endforeach; ?>
            </select>
        </label>
    </div>

    <?php foreach ($candidats as $c): ?>
        <label class="card mt-16 fusion-candidat">
            <div class="linked-add">
                <input type="radio" name="organisateur_id" value="<?= (int) $c['id'] ?>" required>
                <span>
                    <strong><?= e($c['nom']) ?></strong>
                    <span class="muted small"> — <?= e($c['categorie']) ?></span>
                    <?php if ($c['sous_categorie']): ?><span class="muted small"> — <?= e($c['sous_categorie']) ?></span><?php endif; ?>
                </span>
            </div>
            <div class="muted small">
                <?= e(trim($c['adresse_rue'] . ' ' . $c['adresse_npa'] . ' ' . $c['adresse_localite'])) ?: '—' ?>
            </div>
            <div class="muted small mt-10">
                <?= (int) $c['nb_contacts'] ?> contact(s), <?= (int) $c['nb_notes'] ?> note(s),
                <?= (int) $c['nb_factures'] ?> facture(s)
            </div>
        </label>
    <?php endforeach; ?>

    <p class="muted small mt-16">La structure cochée reste l'organisateur ; les <?= count($candidats) - 1 ?> autre(s) deviennent des salles/festivals liés.</p>
    <div class="form-actions mt-16">
        <button type="submit" onclick="return confirm('Transformer les structures non cochées en salles/festivals de l\'organisateur ? Elles seront définitivement supprimées.');"><?= icon('save') ?> Transformer</button>
        <a class="btn ghost" href="?p=structures">Annuler</a>
    </div>
</form>
