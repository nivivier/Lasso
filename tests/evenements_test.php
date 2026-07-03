<?php
// Tests du module événements. Lancement : php tests/evenements_test.php
// N'utilise pas la base de l'application (fonctions pures de lib/evenements.php).
// evenements_delai_decompte_mois() et evenements_export_token()/regenerer_token()
// appellent param()/db() : non testées ici (nécessitent la base applicative).

require_once __DIR__ . '/../lib/helpers.php'; // e()

// Stub minimal de param() pour evenement_statut_suisa() (délai SUISA).
function param(string $cle, $defaut = null)
{
    return $cle === 'suisa_delai_decompte_mois' ? '12' : $defaut;
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

echo "1) Statut SUISA dérivé (5 valeurs)\n";
check('ne s\'applique pas (prioritaire sur tout le reste)', 'ne_sapplique_pas', evenement_statut_suisa([
    'suisa_applicable' => 0, 'suisa_envoye_le' => '2020-01-01', 'suisa_decompte_le' => '',
]));
check('décompte reçu', 'decompte_recu', evenement_statut_suisa([
    'suisa_applicable' => 1, 'suisa_envoye_le' => '2020-01-01', 'suisa_decompte_le' => '2020-03-01',
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
    id INTEGER PRIMARY KEY, suisa_applicable INTEGER, suisa_envoye_le TEXT, suisa_decompte_le TEXT
)");
$ins = $pdo->prepare('INSERT INTO evenements (id, suisa_applicable, suisa_envoye_le, suisa_decompte_le) VALUES (?, ?, ?, ?)');
$cas = [
    1 => ['applicable' => 0, 'envoye' => '2020-01-01', 'decompte' => ''],                                    // ne_sapplique_pas
    2 => ['applicable' => 1, 'envoye' => '2020-01-01', 'decompte' => '2020-03-01'],                           // decompte_recu
    3 => ['applicable' => 1, 'envoye' => '', 'decompte' => ''],                                               // a_faire
    4 => ['applicable' => 1, 'envoye' => date('Y-m-d', strtotime('-2 months')), 'decompte' => ''],            // envoye
    5 => ['applicable' => 1, 'envoye' => date('Y-m-d', strtotime('-13 months')), 'decompte' => ''],           // manquant
];
foreach ($cas as $id => $c) {
    $ins->execute([$id, $c['applicable'], $c['envoye'], $c['decompte']]);
}
foreach (EVENEMENTS_STATUTS_SUISA_FILTRE as $statut) {
    $sql = 'SELECT id FROM evenements WHERE ' . evenement_sql_statut_suisa($statut);
    $needsDelai = in_array($statut, ['envoye', 'manquant'], true);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($needsDelai ? [12] : []);
    $idsSql = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    // Référence : la même règle appliquée en PHP (evenement_statut_suisa()) sur les mêmes cas.
    $idsPhp = [];
    foreach ($cas as $id => $c) {
        $ev = ['suisa_applicable' => $c['applicable'], 'suisa_envoye_le' => $c['envoye'], 'suisa_decompte_le' => $c['decompte']];
        if (evenement_statut_suisa($ev) === $statut) {
            $idsPhp[] = $id;
        }
    }
    sort($idsSql);
    sort($idsPhp);
    check("SQL == PHP pour le statut « $statut »", $idsPhp, $idsSql);
}

echo "\n$tests tests, $fails échec(s)\n";
exit($fails > 0 ? 1 : 0);
