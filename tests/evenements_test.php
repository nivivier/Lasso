<?php
// Tests du module événements. Lancement : php tests/evenements_test.php
// N'utilise pas la base de l'application (fonctions pures de lib/evenements.php).
// evenements_delai_decompte_mois() et evenements_export_token()/regenerer_token()
// appellent param()/db() : non testées ici (nécessitent la base applicative).

require_once __DIR__ . '/../lib/helpers.php'; // e()

// Stub minimal de param() pour evenement_statut_suisa() (délais SUISA).
function param(string $cle, $defaut = null)
{
    return match ($cle) {
        'suisa_delai_decompte_mois' => '12',
        'suisa_delai_abandon_mois'  => '60',
        default => $defaut,
    };
}

require_once __DIR__ . '/../lib/evenements.php';

$tests = 0;
$fails = 0;
function check(string $label, $attendu, $obtenu): void
{
    global $tests, $fails;
    $tests++;
    $ok = $attendu === $obtenu;
    if (!$ok) {
        $fails++;
        printf("  FAIL  %-52s attendu %s, obtenu %s\n", $label, var_export($attendu, true), var_export($obtenu, true));
    } else {
        printf("  ok    %s\n", $label);
    }
}

echo "1) Statut SUISA dérivé (6 valeurs)\n";
check('ne s\'applique pas (prioritaire sur tout le reste)', 'ne_sapplique_pas', evenement_statut_suisa([
    'suisa_applicable' => 0, 'suisa_envoye_le' => '2020-01-01', 'suisa_decompte_le' => '',
]));
check('décompte reçu', 'decompte_recu', evenement_statut_suisa([
    'suisa_applicable' => 1, 'suisa_envoye_le' => '2020-01-01', 'suisa_decompte_le' => '2020-03-01',
]));
check('décompte reçu même sans date d\'envoi enregistrée', 'decompte_recu', evenement_statut_suisa([
    'suisa_applicable' => 1, 'suisa_envoye_le' => '', 'suisa_decompte_le' => '2020-05-01',
]));
check('à faire (jamais envoyée)', 'a_faire', evenement_statut_suisa([
    'suisa_applicable' => 1, 'suisa_envoye_le' => '', 'suisa_decompte_le' => '',
]));
check('envoyé récemment, dans le délai', 'envoye', evenement_statut_suisa([
    'suisa_applicable' => 1, 'suisa_envoye_le' => date('Y-m-d', strtotime('-2 months')), 'suisa_decompte_le' => '',
]));
check('envoyé il y a plus de 12 mois, sans décompte → manquant', 'manquant', evenement_statut_suisa([
    'suisa_applicable' => 1, 'suisa_envoye_le' => date('Y-m-d', strtotime('-13 months')), 'suisa_decompte_le' => '',
]));
check('envoyé il y a exactement 11 mois → pas encore manquant', 'envoye', evenement_statut_suisa([
    'suisa_applicable' => 1, 'suisa_envoye_le' => date('Y-m-d', strtotime('-11 months')), 'suisa_decompte_le' => '',
]));
check('événement très ancien, jamais envoyé, sans décompte → abandonné (prioritaire sur à faire)', 'abandonne', evenement_statut_suisa([
    'suisa_applicable' => 1, 'suisa_envoye_le' => '', 'suisa_decompte_le' => '',
    'date' => date('Y-m-d', strtotime('-61 months')),
]));
check('événement très ancien, envoyé en retard, sans décompte → abandonné (prioritaire sur manquant)', 'abandonne', evenement_statut_suisa([
    'suisa_applicable' => 1, 'suisa_envoye_le' => date('Y-m-d', strtotime('-70 months')), 'suisa_decompte_le' => '',
    'date' => date('Y-m-d', strtotime('-71 months')),
]));
check('événement ancien mais dans le délai d\'abandon → pas encore abandonné', 'a_faire', evenement_statut_suisa([
    'suisa_applicable' => 1, 'suisa_envoye_le' => '', 'suisa_decompte_le' => '',
    'date' => date('Y-m-d', strtotime('-59 months')),
]));
check('décompte reçu prioritaire même sur un événement très ancien', 'decompte_recu', evenement_statut_suisa([
    'suisa_applicable' => 1, 'suisa_envoye_le' => '', 'suisa_decompte_le' => date('Y-m-d', strtotime('-1 months')),
    'date' => date('Y-m-d', strtotime('-100 months')),
]));
check('sans date d\'événement (champ absent) : jamais abandonné', 'a_faire', evenement_statut_suisa([
    'suisa_applicable' => 1, 'suisa_envoye_le' => '', 'suisa_decompte_le' => '',
]));

$evDecompte = ['suisa_applicable' => 1, 'suisa_envoye_le' => '2020-01-01', 'suisa_decompte_le' => '2020-03-15'];
check('badge SUISA : libellé par défaut', true, str_contains(evenement_suisa_badge($evDecompte), 'Décompte reçu'));
check('badge SUISA : date du décompte si demandée (liste événements)', true, str_contains(evenement_suisa_badge($evDecompte, true), '15.03.2020'));

echo "2) Exportabilité (visibilité + statut)\n";
check('non répertorié → jamais exportable', false, evenement_exportable(['visibilite' => 'non_repertorie', 'statut' => 'confirme']));
check('en option → jamais exportable', false, evenement_exportable(['visibilite' => 'public', 'statut' => 'option']));
check('public confirmé → exportable', true, evenement_exportable(['visibilite' => 'public', 'statut' => 'confirme']));
check('public annulé → exportable (affiché comme annulé)', true, evenement_exportable(['visibilite' => 'public', 'statut' => 'annule']));
check('privé confirmé → exportable', true, evenement_exportable(['visibilite' => 'prive', 'statut' => 'confirme']));

echo "3) Filtrage des champs exposés selon la visibilité\n";
$evPublic = [
    'id' => 1, 'date' => '2026-08-01', 'visibilite' => 'public', 'statut' => 'annule',
    'ville' => 'Genève', 'salle' => 'Salle du Faubourg', 'festival' => 'Festival X',
    'lien_infos' => 'https://exemple.ch', 'spectacle_nom' => 'Mon spectacle', 'remarques' => 'Note interne',
];
$donneesPublic = evenement_export_donnees($evPublic);
check('public : ville exposée', 'Genève', $donneesPublic['ville']);
check('public : salle exposée', 'Salle du Faubourg', $donneesPublic['salle']);
check('public : annule=true dérivé du statut', true, $donneesPublic['annule']);
check('public : remarques exposées', 'Note interne', $donneesPublic['remarques']);
check('public : prive=false', false, $donneesPublic['prive']);
check('public : lien_texte par défaut si absent', "Plus d'informations", $donneesPublic['lien_texte']);

$evPublicLienTexte = $evPublic;
$evPublicLienTexte['lien_texte'] = 'Réserver';
check('public : lien_texte propre à l\'événement prioritaire sur le défaut', 'Réserver', evenement_export_donnees($evPublicLienTexte)['lien_texte']);

$evSansLien = $evPublic;
$evSansLien['lien_infos'] = '';
check('public : pas de lien_texte si lien_infos absent', false, array_key_exists('lien_texte', evenement_export_donnees($evSansLien)));

$evPrive = [
    'id' => 2, 'date' => '2026-09-01', 'visibilite' => 'prive', 'statut' => 'confirme',
    'ville' => 'Lausanne', 'salle' => 'Secret', 'festival' => '', 'lien_infos' => '',
    'spectacle_nom' => 'Spectacle secret', 'remarques' => 'Ne jamais divulguer',
];
$donneesPrive = evenement_export_donnees($evPrive);
check('privé : seuls id/date/prive présents', ['id', 'date', 'prive'], array_keys($donneesPrive));
check('privé : prive=true', true, $donneesPrive['prive']);
check('privé : aucune fuite de ville/salle/remarques', false, isset($donneesPrive['ville']) || isset($donneesPrive['salle']) || isset($donneesPrive['remarques']));

echo "4) Génération iCal (échappement + événement privé)\n";
$ics = evenements_generer_ical([$donneesPublic, $donneesPrive]);
check('en-tête VCALENDAR', true, str_starts_with($ics, "BEGIN:VCALENDAR"));
check('événement public : résumé annulé + spectacle', true, str_contains($ics, 'SUMMARY:[ANNULÉ] Mon spectacle'));
check('événement privé : résumé générique, jamais le nom du spectacle', true,
    str_contains($ics, 'SUMMARY:Événement privé') && !str_contains($ics, 'Spectacle secret'));
check('événement privé : jamais de LOCATION avec la salle secrète', false, str_contains($ics, 'Secret'));

$evRegionPays = [
    'id' => 3, 'date' => '2026-10-10', 'visibilite' => 'public', 'statut' => 'confirme',
    'ville' => 'Besançon', 'region' => '25', 'pays' => 'FR', 'salle' => '', 'festival' => '',
    'lien_infos' => '', 'spectacle_nom' => '', 'remarques' => '',
];
$icsRegionPays = evenements_generer_ical([evenement_export_donnees($evRegionPays)]);
check('LOCATION combine ville, région et pays', true, str_contains($icsRegionPays, 'LOCATION:Besançon (25\\, FR)'));

echo "5) Validation stricte de date (checkdate, pas de \"roulement\")\n";
check('date valide', true, date_valide('2026-07-16'));
check('31 avril rejeté (checkdate)', false, date_valide('2026-04-31'));
check('29 février rejeté hors année bissextile', false, date_valide('2026-02-29'));
check('29 février accepté en année bissextile', true, date_valide('2028-02-29'));
check('format incorrect rejeté', false, date_valide('16-07-2026'));
check('chaîne vide rejetée', false, date_valide(''));

echo "6) Prédicat SQL du statut SUISA (synchronisé avec evenement_statut_suisa())\n";
$pdo = new PDO('sqlite::memory:');
$pdo->exec("CREATE TABLE evenements (
    id INTEGER PRIMARY KEY, date TEXT, suisa_applicable INTEGER, suisa_envoye_le TEXT, suisa_decompte_le TEXT
)");
$ins = $pdo->prepare('INSERT INTO evenements (id, date, suisa_applicable, suisa_envoye_le, suisa_decompte_le) VALUES (?, ?, ?, ?, ?)');
$aujourdhui = date('Y-m-d');
$cas = [
    1 => ['date' => $aujourdhui, 'applicable' => 0, 'envoye' => '2020-01-01', 'decompte' => ''],                                          // ne_sapplique_pas
    2 => ['date' => $aujourdhui, 'applicable' => 1, 'envoye' => '2020-01-01', 'decompte' => '2020-03-01'],                                 // decompte_recu
    3 => ['date' => $aujourdhui, 'applicable' => 1, 'envoye' => '', 'decompte' => ''],                                                     // a_faire
    4 => ['date' => $aujourdhui, 'applicable' => 1, 'envoye' => date('Y-m-d', strtotime('-2 months')), 'decompte' => ''],                  // envoye
    5 => ['date' => $aujourdhui, 'applicable' => 1, 'envoye' => date('Y-m-d', strtotime('-13 months')), 'decompte' => ''],                 // manquant
    6 => ['date' => $aujourdhui, 'applicable' => 1, 'envoye' => '', 'decompte' => '2020-05-01'],                                           // decompte_recu (saisie manuelle sans date d'envoi)
    7 => ['date' => date('Y-m-d', strtotime('-61 months')), 'applicable' => 1, 'envoye' => '', 'decompte' => ''],                          // abandonne (jamais envoyée)
    8 => ['date' => date('Y-m-d', strtotime('-71 months')), 'applicable' => 1, 'envoye' => date('Y-m-d', strtotime('-70 months')), 'decompte' => ''], // abandonne (aurait été manquant)
];
foreach ($cas as $id => $c) {
    $ins->execute([$id, $c['date'], $c['applicable'], $c['envoye'], $c['decompte']]);
}
foreach (EVENEMENTS_STATUTS_SUISA_FILTRE as $statut) {
    $sql = 'SELECT id FROM evenements WHERE ' . evenement_sql_statut_suisa($statut);
    // Même correspondance statut → paramètres liés que route_evenements_liste().
    $liaisonParams = match ($statut) {
        'manquant' => [12, 60],
        'a_faire', 'envoye', 'abandonne' => [60],
        default => [],
    };
    $stmt = $pdo->prepare($sql);
    $stmt->execute($liaisonParams);
    $idsSql = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    // Référence : la même règle appliquée en PHP (evenement_statut_suisa()) sur les mêmes cas —
    // sauf le filtre 'envoye', qui englobe volontairement aussi 'manquant' (voir
    // evenement_sql_statut_suisa()).
    $statutsPhpAttendus = $statut === 'envoye' ? ['envoye', 'manquant'] : [$statut];
    $idsPhp = [];
    foreach ($cas as $id => $c) {
        $ev = ['date' => $c['date'], 'suisa_applicable' => $c['applicable'], 'suisa_envoye_le' => $c['envoye'], 'suisa_decompte_le' => $c['decompte']];
        if (in_array(evenement_statut_suisa($ev), $statutsPhpAttendus, true)) {
            $idsPhp[] = $id;
        }
    }
    sort($idsSql);
    sort($idsPhp);
    check("SQL == PHP pour le statut « $statut »", $idsPhp, $idsSql);
}

echo "7) Import CSV — date JJ/MM/AAAA -> ISO\n";
check('date valide', '2026-01-30', date_csv_vers_iso('30/01/2026'));
check('jour/mois sur un chiffre acceptés', '2026-02-01', date_csv_vers_iso('1/2/2026'));
check('espaces ignorés', '2026-01-30', date_csv_vers_iso(' 30/01/2026 '));
check('31 avril rejeté', null, date_csv_vers_iso('31/04/2026'));
check('« TBA/2027 » rejeté (pas une date)', null, date_csv_vers_iso('TBA/2027'));
check('format ISO refusé ici (JJ/MM/AAAA attendu)', null, date_csv_vers_iso('2026-01-30'));

echo "8) Import CSV — normalisation du nom de spectacle (rapprochement)\n";
check('« anticoncert » == « Anti-concert »', normaliser_nom_spectacle('Anti-concert'), normaliser_nom_spectacle('anticoncert'));
check('espaces et casse ignorés', normaliser_nom_spectacle('Le Grand Spectacle'), normaliser_nom_spectacle(' le  grand-spectacle '));
check('noms réellement différents restent différents', false, normaliser_nom_spectacle('Anti-concert') === normaliser_nom_spectacle('Autre spectacle'));

echo "\n$tests tests, $fails échec(s)\n";
exit($fails > 0 ? 1 : 0);
