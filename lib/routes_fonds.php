<?php
// Module « Recherche de fonds » — suivi des demandes de subvention.
// Le besoin et les décisions de conception : SPEC_SUBVENTIONS.md.
//
// Ce que ce module NE fait pas, parce que d'autres le font déjà :
//   — tenir un carnet d'adresses : un bailleur est une `structure`, avec ses
//     contacts, son historique et son bouton « Contacter » (lib/booking.php) ;
//   — stocker des fichiers : les pièces d'un dossier vivent sur un drive
//     externe, la campagne n'en garde que le lien (§ 3 quater) ;
//   — inventer une ventilation : l'axe analytique est celui du projet financé
//     (spectacles.axe_analytique_id, migration_91).

declare(strict_types=1);

// Les recherches de fonds, de la plus récemment commencée à la plus ancienne.
// Même forme que campagnes_liste() : la liste porte déjà ce qu'il faut pour
// l'afficher, pour que la vue n'ait aucune requête à faire.
function fonds_campagnes_liste(): array
{
    $sql = "SELECT c.*,
                   (SELECT COUNT(*) FROM fonds_demandes d WHERE d.campagne_id = c.id) AS nb_demandes,
                   (SELECT COUNT(*) FROM fonds_demandes d WHERE d.campagne_id = c.id AND d.date_depot <> '') AS nb_deposees,
                   (SELECT COALESCE(SUM(d.montant_demande), 0) FROM fonds_demandes d WHERE d.campagne_id = c.id AND d.date_depot <> '' AND d.montant_accorde = 0) AS montant_en_attente,
                   (SELECT COALESCE(SUM(d.montant_accorde), 0) FROM fonds_demandes d WHERE d.campagne_id = c.id) AS montant_obtenu
              FROM fonds_campagnes c
          ORDER BY c.date_debut DESC, c.id DESC";
    return db()->query($sql)->fetchAll();
}

// La liste des recherches de fonds.
function route_fonds(): void
{
    require_login();
    render('fonds', ['campagnes' => fonds_campagnes_liste()], 'Recherche de fonds');
}
