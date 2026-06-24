<?php
/** @var array $comptes */ /** @var array $imports */ /** @var ?array $msg */ /** @var bool $okDel */
?>
<div class="page-head page-head-sub">
    <?= lien_retour('?p=compta_ecritures', 'Écritures') ?>
    <h1>Importer</h1>
</div>
<?php if ($okDel): ?><p class="ok flash">Import supprimé.</p><?php endif; ?>
<?php if ($msg): ?><p class="<?= $msg[0] === 'ok' ? 'ok' : 'err' ?>"><?= e($msg[1]) ?></p><?php endif; ?>

<div class="card form">
    <h2>Importer un export PostFinance</h2>
    <p class="muted small">Téléversez un fichier CSV de mouvements PostFinance. Le compte bancaire est reconnu automatiquement par son IBAN — <strong>s'il n'existe pas encore, il est créé automatiquement</strong> (vous pourrez le renommer dans « Comptes bancaires »). Les doublons sont ignorés : vous pouvez réimporter ou ajouter des périodes qui se chevauchent sans risque. Les règles de lettrage sont appliquées automatiquement après l'import.</p>
    <form method="post" action="?p=compta_import" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <div class="add-row">
            <label>Fichier CSV <input type="file" name="fichier" accept=".csv,text/csv" required></label>
            <button type="submit"><?= icon('upload') ?> Importer</button>
        </div>
    </form>
</div>

<?php if ($imports): ?>
<div class="card">
    <h2 class="mt-0">Historique des imports</h2>
    <div class="table-scroll">
    <table class="list">
        <thead><tr><th>Date</th><th>Compte</th><th>Fichier</th><th>Période</th><th class="num">Ajoutées</th><th class="num">Doublons</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($imports as $i): ?>
            <tr>
                <td><?= e(date('d.m.Y H:i', strtotime((string) $i['importe_le']))) ?></td>
                <td><?= e($i['compte_libelle']) ?></td>
                <td class="muted small"><?= e($i['nom_fichier']) ?></td>
                <td class="small"><?= e($i['date_debut']) ?> → <?= e($i['date_fin']) ?></td>
                <td class="num"><?= (int) $i['nb_importees'] ?></td>
                <td class="num"><?= (int) $i['nb_doublons'] ?></td>
                <td class="actions">
                    <?php
                    $nbAct = (int) $i['nb_actuelles'];
                    $nbLet = (int) $i['nb_lettrees'];
                    $confirm = "Annuler cet import ?\\n\\n$nbAct écriture(s) seront supprimées"
                        . ($nbLet > 0 ? " (dont $nbLet déjà lettrée(s) — leur lettrage sera perdu)" : '') . '.';
                    ?>
                    <form method="post" action="?p=compta_import" onsubmit="return confirm('<?= e($confirm) ?>');" class="d-inline">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="section" value="del">
                        <input type="hidden" name="id" value="<?= (int) $i['id'] ?>">
                        <button type="submit" class="btn ghost btn-sm icon-only" title="Annuler cet import" aria-label="Annuler cet import"><?= icon('trash') ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>
