<?php
/** @var string $q */ /** @var array $groupes */ /** @var int $total */ /** @var bool $tropCourt */
?>
<div class="module-content"><div class="module-content-inner">

    <form class="recherche-form" method="get" action="">
        <input type="hidden" name="p" value="recherche">
        <?= champ_recherche([
            'name'        => 'q',
            'valeur'      => $q,
            'classe'      => 'recherche-champ',
            'placeholder' => 'Rechercher partout',
            'aria'        => "Rechercher dans toute l'application",
            'autofocus'   => true,
            'submit'      => true,
        ]) ?>
    </form>

    <?php if ($tropCourt): ?>
        <p class="muted">Saisissez au moins <?= RECHERCHE_MIN ?> caractères.</p>

    <?php elseif ($q === ''): ?>
        <p class="muted">
            Cherchez dans les employés, structures, factures, événements et spectacles à la fois.
            Plusieurs mots se cumulent : « hector genève » ne remonte que ce qui contient les deux.
        </p>

    <?php elseif (!$groupes): ?>
        <p class="muted">Aucun résultat pour « <strong><?= e($q) ?></strong> ».</p>
        <p class="muted small">
            Vérifiez l'orthographe, ou essayez un seul mot. La recherche ne porte que sur
            les modules auxquels vous avez accès.
        </p>

    <?php else: ?>
        <p class="muted small">
            <?= $total ?> résultat<?= $total > 1 ? 's' : '' ?> pour « <strong><?= e($q) ?></strong> »
        </p>

        <?php foreach ($groupes as $groupe): ?>
        <div class="card recherche-groupe">
            <h2 class="mt-0 recherche-groupe-titre">
                <?= icon($groupe['icone']) ?>
                <?= e($groupe['label']) ?>
                <span class="muted small">
                    <?php if ($groupe['total'] > count($groupe['resultats'])): ?>
                        <?= count($groupe['resultats']) ?> sur <?= $groupe['total'] ?>
                    <?php else: ?>
                        <?= $groupe['total'] ?>
                    <?php endif; ?>
                </span>
            </h2>
            <table class="list">
                <tbody>
                <?php foreach ($groupe['resultats'] as $r): ?>
                    <?php
                    // depuis=recherche : la fiche atteinte affichera un lien
                    // retour vers CETTE recherche (lien_retour_contextuel()).
                    // suffixe_retour_liste() y rattache le terme cherché, jamais
                    // mémorisé en session — sans lui le retour ramènerait sur
                    // une recherche vide.
                    $url = url_avec_retour('?p=' . urlencode($groupe['route']) . '&id=' . (int) $r['id'], 'recherche')
                         . suffixe_retour_liste($q, 0);
                    ?>
                    <tr class="row-link" data-href="<?= e($url) ?>" tabindex="0">
                        <td>
                            <a href="<?= e($url) ?>"><?= e((string) $r['titre']) ?></a>
                            <?php if (trim((string) $r['sous_titre']) !== ''): ?>
                                <span class="muted small"> — <?= e((string) $r['sous_titre']) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($groupe['total'] > count($groupe['resultats'])): ?>
                <p class="muted small mb-0">
                    <a href="?p=<?= e($groupe['liste']) ?>&amp;q=<?= urlencode($q) ?>">
                        Voir les <?= $groupe['total'] ?> résultats dans <?= e($groupe['label']) ?>
                    </a>
                </p>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

</div></div>
