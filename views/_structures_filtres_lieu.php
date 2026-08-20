<?php
/** Champs « jauge / mois » de ?p=structures.
 *
 * Rendu à deux endroits : replié derrière l'entonnoir « Plus de filtres » de la
 * toolbar en vue bureau, et à l'intérieur du panneau « Filtres » en mobile, où
 * les entonnoirs d'en-tête de colonne n'existent plus. Un seul gabarit pour les
 * deux, sinon les deux copies divergent au premier ajout de filtre.
 *
 * @var ?int $lieuJaugeMin @var ?int $lieuJaugeMax
 * @var int $lieuMoisEvenement @var int $lieuMoisProg
 */
?>
<label class="jauge-filtre">Jauge min
    <input type="number" name="lieu_jauge_min" min="0" value="<?= $lieuJaugeMin !== null ? (int) $lieuJaugeMin : '' ?>" data-submit-on-change placeholder="200">
</label>
<label class="jauge-filtre">Jauge max
    <input type="number" name="lieu_jauge_max" min="0" value="<?= $lieuJaugeMax !== null ? (int) $lieuJaugeMax : '' ?>" data-submit-on-change placeholder="1000">
</label>
<label>Mois d'événement
    <select name="lieu_mois_evenement" data-submit-on-change>
        <option value="0">Tous</option>
        <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?= $m ?>" <?= $lieuMoisEvenement === $m ? 'selected' : '' ?>><?= mois_nom($m) ?></option>
        <?php endfor; ?>
    </select>
</label>
<label>Mois de programmation
    <select name="lieu_mois_prog" data-submit-on-change>
        <option value="0">Tous</option>
        <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?= $m ?>" <?= $lieuMoisProg === $m ? 'selected' : '' ?>><?= mois_nom($m) ?></option>
        <?php endfor; ?>
    </select>
</label>
