<?php
// Tests de la feuille de route d'un événement.
// Lancement : php tests/feuille_route_test.php
//
// L'ordre et l'affichage sont l'essentiel de cette fonctionnalité : une feuille
// de route se lit dans l'ordre de la journée, et chaque ligne doit rester
// lisible même à moitié remplie. Le reste — envoi de fichier, routes — passe par
// $_FILES et des redirections, vérifiés à la main sur le serveur.

require_once __DIR__ . '/../lib/helpers.php'; // e()

// param() est appelée par lib/evenements.php (délais SUISA) : stub minimal.
function param(string $cle, $defaut = null)
{
    return $defaut;
}

require_once __DIR__ . '/../lib/evenements.php';   // evenement_horaire_texte()
require_once __DIR__ . '/../lib/feuille_route.php';

$tests = 0;
$fails = 0;
function check(string $label, $attendu, $obtenu): void
{
    global $tests, $fails;
    $tests++;
    if ($attendu !== $obtenu) {
        $fails++;
        printf("  FAIL  %-56s attendu %s, obtenu %s\n", $label, var_export($attendu, true), var_export($obtenu, true));
    } else {
        printf("  ok    %s\n", $label);
    }
}

// Base en mémoire : db() est stubbée, les fonctions d'ordre écrivent dedans.
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE evenement_feuille (
    id INTEGER PRIMARY KEY AUTOINCREMENT, evenement_id INTEGER NOT NULL, type TEXT NOT NULL,
    ordre INTEGER NOT NULL DEFAULT 0, libelle TEXT NOT NULL DEFAULT \'\',
    debut TEXT NOT NULL DEFAULT \'\', fin TEXT NOT NULL DEFAULT \'\',
    adresse TEXT NOT NULL DEFAULT \'\',
    prenom TEXT NOT NULL DEFAULT \'\', nom TEXT NOT NULL DEFAULT \'\',
    telephone TEXT NOT NULL DEFAULT \'\', email TEXT NOT NULL DEFAULT \'\',
    structure_id INTEGER, contact_id INTEGER,
    fichier TEXT NOT NULL DEFAULT \'\', nom_origine TEXT NOT NULL DEFAULT \'\',
    mime TEXT NOT NULL DEFAULT \'\', taille INTEGER NOT NULL DEFAULT 0,
    remarque TEXT NOT NULL DEFAULT \'\', cree_le TEXT NOT NULL DEFAULT \'\')');
$pdo->exec('CREATE TABLE structure_contacts (id INTEGER PRIMARY KEY, prenom TEXT, nom TEXT, role TEXT, telephone TEXT, email TEXT)');
$pdo->exec('CREATE TABLE structures (id INTEGER PRIMARY KEY, nom TEXT)');
function db(): PDO
{
    global $pdo;
    return $pdo;
}

// --- Ordre ------------------------------------------------------------------
$ajouter = function (string $type, string $libelle) use ($pdo): int {
    $pdo->prepare('INSERT INTO evenement_feuille (evenement_id, type, ordre, libelle) VALUES (1, ?, ?, ?)')
        ->execute([$type, feuille_ordre_suivant(1), $libelle]);
    return (int) $pdo->lastInsertId();
};
$ordre = fn (): array => array_column(feuille_elements(1), 'libelle');

echo "1) Ordre : chaque ajout va en fin de liste\n";
$a = $ajouter('horaire', 'Get-in');
$b = $ajouter('horaire', 'Balances');
$c = $ajouter('adresse', 'Hôtel');
check('trois éléments, dans l\'ordre d\'ajout', ['Get-in', 'Balances', 'Hôtel'], $ordre());

echo "\n2) Déplacements\n";
feuille_deplacer($c, 'monter');
check('l\'hôtel monte d\'un cran', ['Get-in', 'Hôtel', 'Balances'], $ordre());
feuille_deplacer($c, 'monter');
check('puis en tête', ['Hôtel', 'Get-in', 'Balances'], $ordre());
feuille_deplacer($c, 'monter');
check('monter le premier ne fait rien', ['Hôtel', 'Get-in', 'Balances'], $ordre());
feuille_deplacer($b, 'descendre');
check('descendre le dernier ne fait rien', ['Hôtel', 'Get-in', 'Balances'], $ordre());
feuille_deplacer($a, 'descendre');
check('« Get-in » descend', ['Hôtel', 'Balances', 'Get-in'], $ordre());

echo "\n3) Rangs égaux (import, reprise) : le déplacement reste prévisible\n";
// Des rangs tous identiques ne doivent pas rendre la liste instable : l'ordre
// retombe sur l'id (ORDER BY ordre, id), donc sur l'ordre de création.
$pdo->exec('UPDATE evenement_feuille SET ordre = 5');
check('à rangs égaux, l\'ordre de création fait foi', ['Get-in', 'Balances', 'Hôtel'], $ordre());
feuille_deplacer($b, 'monter');
check('un déplacement réaligne les rangs, puis échange', ['Balances', 'Get-in', 'Hôtel'], $ordre());
$rangs = array_column(feuille_elements(1), 'ordre');
check('les rangs sont renumérotés sans trou', [1, 2, 3], array_map('intval', $rangs));

// --- Affichage --------------------------------------------------------------
echo "\n4) Titre d'un élément : lisible même sans intitulé\n";
check('l\'intitulé prime', 'Get-in', feuille_element_titre(['type' => 'horaire', 'libelle' => 'Get-in']));
check('un horaire sans intitulé', 'Horaire', feuille_element_titre(['type' => 'horaire', 'libelle' => '']));
check('une adresse se nomme par son adresse', '5 av. de la Gare',
    feuille_element_titre(['type' => 'adresse', 'libelle' => '', 'adresse' => '5 av. de la Gare']));
check('un contact se nomme par son nom', 'Jean Dupuis',
    feuille_element_titre(['type' => 'contact', 'libelle' => '', 'prenom' => 'Jean', 'nom' => 'Dupuis']));
check('une pièce jointe par son nom de fichier', 'Fiche technique.pdf',
    feuille_element_titre(['type' => 'fichier', 'libelle' => '', 'nom_origine' => 'Fiche technique.pdf']));
check('rien nulle part : un repli, jamais de vide', 'Contact',
    feuille_element_titre(['type' => 'contact', 'libelle' => '', 'prenom' => '', 'nom' => '']));

echo "\n5) Détail d'un élément\n";
check('horaire complet', ['14:00 – 15:00', 'porte de service'],
    feuille_element_lignes(['type' => 'horaire', 'debut' => '14:00', 'fin' => '15:00', 'remarque' => 'porte de service']));
check('horaire sans fin : pas de tiret orphelin', ['14:00'],
    feuille_element_lignes(['type' => 'horaire', 'debut' => '14:00', 'fin' => '', 'remarque' => '']));
check('adresse seule', ['5 av. de la Gare'],
    feuille_element_lignes(['type' => 'adresse', 'adresse' => '5 av. de la Gare', 'remarque' => '']));
// Le code d'entrée n'a pas de champ à lui : il vit dans la remarque.
check('adresse et son code, noté en remarque', ['5 av. de la Gare', 'code B2403, 2e étage'],
    feuille_element_lignes(['type' => 'adresse', 'adresse' => '5 av. de la Gare', 'remarque' => 'code B2403, 2e étage']));
check('contact saisi librement', ['Jean Dupuis', '+41 79 000 00 00'],
    feuille_element_lignes(['type' => 'contact', 'prenom' => 'Jean', 'nom' => 'Dupuis',
        'telephone' => '+41 79 000 00 00', 'email' => '', 'remarque' => '']));
// Le contact du carnet d'adresses PRIME sur la saisie libre : c'est la fiche qui
// fait foi, et c'est elle qui reste à jour.
check('contact du carnet : la fiche fait foi', ['Kévin Roux · Accueil artiste · Le Bijou', '+41 22 000 00 00'],
    feuille_element_lignes(['type' => 'contact', 'prenom' => 'Périmé', 'nom' => 'Périmé', 'telephone' => '000',
        'email' => '', 'remarque' => '', 'c_prenom' => 'Kévin', 'c_nom' => 'Roux',
        'c_role' => 'Accueil artiste', 'c_telephone' => '+41 22 000 00 00', 'c_email' => '', 's_nom' => 'Le Bijou']));
check('note : la remarque seule', ['deux repas végétariens'],
    feuille_element_lignes(['type' => 'note', 'remarque' => 'deux repas végétariens']));
check('élément vide : aucune ligne, pas une ligne vide', [],
    feuille_element_lignes(['type' => 'note', 'remarque' => '']));

echo "\n6) Pièces jointes : taille et nom affichés\n";
check('kilo-octets', '5 Ko', feuille_taille_texte(5120));
check('méga-octets', '1,4 Mo', feuille_taille_texte(1468006));
check('un fichier minuscule ne vaut pas 0 Ko', '1 Ko', feuille_taille_texte(12));
check('le chemin est retiré du nom', 'facture.pdf', feuille_nom_origine_propre('/etc/passwd/../facture.pdf'));
check('les guillemets sont retirés (en-tête HTTP)', 'fiche.pdf', feuille_nom_origine_propre('"fiche".pdf'));
check('un nom vide reste nommé', 'document', feuille_nom_origine_propre(''));
check('le nom est borné', 120, mb_strlen(feuille_nom_origine_propre(str_repeat('a', 300)), 'UTF-8'));

echo "\n7) Types déclarés\n";
check('cinq types', ['horaire', 'adresse', 'contact', 'fichier', 'note'], array_keys(FEUILLE_TYPES));
foreach (FEUILLE_TYPES as $cle => $meta) {
    check("« $cle » déclare libellé, icône, aide et champs",
        ['libelle', 'icone', 'aide', 'champs'], array_keys($meta));
}

echo "\n8) Calendrier de l'équipe : le spectacle a son créneau\n";
// L'heure de représentation de la date pose son propre événement, distinct de
// la bande de journée et des horaires du déroulé. Europe/Zurich : le 18.09,
// 20:30 locales = 18:30 UTC (heure d'été).
$evIcal = [
    'id' => 42, 'date' => '2026-09-18', 'statut' => 'confirme',
    'heure_debut' => '20:30', 'heure_fin' => '22:00',
    'spectacle_nom' => 'Tant qu\'on déborde', 'spectacle_parent_nom' => 'Hector ou rien',
    'ville' => 'Nyon', 'salle' => 'L\'Usine à Gaz',
    'adresse_rue' => '', 'adresse_npa' => '', 'remarques' => '',
    'feuille' => [['id' => 7, 'type' => 'horaire', 'libelle' => 'Get-in',
                   'debut' => '14:00', 'fin' => '', 'remarque' => '']],
];
$ical = feuille_generer_ical_equipe([$evIcal], 'https://exemple.test/?p=evenement_fichier&jeton=x');
check('un événement « Spectacle » est posé', 1, substr_count($ical, 'UID:equipe-spectacle-42@lasso'));
check('il commence à l\'heure annoncée, en UTC', true, str_contains($ical, 'DTSTART:20260918T183000Z'));
check('il finit à l\'heure annoncée', true, str_contains($ical, 'DTEND:20260918T200000Z'));
check('son titre le nomme', true, str_contains($ical, 'SUMMARY:Spectacle — Hector ou rien (Tant qu\'on déborde) — Nyon'));
check('la bande de journée reste une journée entière', true, str_contains($ical, 'DTSTART;VALUE=DATE:20260918'));
check('les horaires du déroulé restent posés', 1, substr_count($ical, 'UID:equipe-feuille-7@lasso'));

// Sans heure, rien à poser : la bande de journée porte seule la date.
$evSansHeure = ['heure_debut' => '', 'heure_fin' => ''] + $evIcal;
$evSansHeure['id'] = 43;
$icalSansHeure = feuille_generer_ical_equipe([$evSansHeure], 'https://exemple.test/?p=x');
check('sans heure, pas d\'événement « Spectacle »', 0, substr_count($icalSansHeure, 'equipe-spectacle-43'));

// Une date annulée l'est sur ses trois entrées, pas seulement sur la bande.
$evAnnule = $evIcal;
$evAnnule['id'] = 44;
$evAnnule['statut'] = 'annule';
$icalAnnule = feuille_generer_ical_equipe([$evAnnule], 'https://exemple.test/?p=x');
check('annulée : la bande, le spectacle et l\'horaire portent le statut', 3,
    substr_count($icalAnnule, 'STATUS:CANCELLED'));

echo "\n9) Calendrier de l'équipe : la description est sectionnée\n";
// Une description iCal est du texte brut : les sections en capitales, séparées
// d'une ligne vide, sont toute la mise en page dont on dispose.
$evDesc = $evIcal + [];
$evDesc['visibilite'] = 'public';
$evDesc['lien_infos'] = 'https://exemple.test/date';
$evDesc['remarques'] = 'Parking derrière la salle.';
$evDesc['adresse_rue'] = 'Rue César-Soulié 1';
$evDesc['adresse_npa'] = '1260';
$evDesc['feuille'] = [
    ['id' => 1, 'type' => 'horaire', 'libelle' => 'Get-in', 'debut' => '14:00', 'fin' => '', 'remarque' => 'porte de derrière'],
    ['id' => 2, 'type' => 'horaire', 'libelle' => 'Soundcheck', 'debut' => '16:00', 'fin' => '17:30', 'remarque' => ''],
    ['id' => 3, 'type' => 'adresse', 'libelle' => 'Hôtel', 'adresse' => 'Rue de la Gare 4', 'remarque' => 'code 1234B'],
    ['id' => 4, 'type' => 'contact', 'libelle' => 'Régie', 'c_prenom' => 'Kévin', 'c_nom' => 'Roux',
     'c_role' => 'Régisseur général', 's_nom' => 'L\'Usine à Gaz', 'c_telephone' => '+41 22 000 00 00',
     'c_email' => '', 'prenom' => '', 'nom' => '', 'telephone' => '', 'email' => '', 'remarque' => ''],
    ['id' => 5, 'type' => 'fichier', 'libelle' => '', 'nom_origine' => 'fiche.pdf', 'taille' => 1468006, 'remarque' => ''],
    ['id' => 6, 'type' => 'note', 'libelle' => '', 'remarque' => 'deux repas végétariens'],
];
$evDesc['organisateurs'] = [
    ['nom' => 'Le Bijou', 'organise' => [], 'adresse_rue' => 'Rue du Pont 2', 'adresse_npa' => '1260',
     'adresse_localite' => 'Nyon', 'adresse_pays' => 'Suisse',
     'contacts' => [['prenom' => 'Aline', 'nom' => 'Favre', 'role' => 'Programmation',
                     'telephone' => '+41 22 111 11 11', 'email' => '']]],
];
$desc = feuille_ical_description($evDesc);
$sections = array_map(fn (string $b): string => explode("\n", $b)[0], explode("\n\n", $desc));
check('les sections, dans l\'ordre',
    ['INFOS PUBLIQUES', 'DÉROULÉ', 'ADRESSES', 'CONTACTS', 'PIÈCES JOINTES', 'NOTES'], $sections);
check('l\'heure ouvre une ligne de déroulé', true,
    str_contains($desc, "\n14:00  Get-in — porte de derrière\n"));
check('un horaire avec fin garde ses deux bornes', true,
    str_contains($desc, '16:00 – 17:30  Soundcheck'));
check('une adresse porte son intitulé et sa remarque', true,
    str_contains($desc, 'Hôtel : Rue de la Gare 4 — code 1234B'));
check('un contact de la feuille précède ceux de l\'organisation', true,
    strpos($desc, 'Régie : Kévin Roux') < strpos($desc, 'Le Bijou'));
check('les contacts d\'une structure sont indentés sous elle', true,
    str_contains($desc, "\n  Aline Favre (Programmation) — +41 22 111 11 11"));
check('le nom d\'un fichier ne se répète pas', true, str_contains($desc, "\nfiche.pdf (1,4 Mo)"));
check('une note sans intitulé n\'est que sa remarque', true,
    str_contains($desc, "NOTES\ndeux repas végétariens"));
check('le lien et les remarques sont dans les infos publiques', true,
    str_contains($desc, 'Lien : https://exemple.test/date')
    && str_contains($desc, 'Remarques : Parking derrière la salle.'));

// Une date sans feuille ni organisateur ne laisse pas de section vide.
$evNu = ['date' => '2026-09-18', 'statut' => 'confirme', 'visibilite' => 'public',
         'heure_debut' => '', 'heure_fin' => '', 'salle' => '', 'ville' => 'Nyon',
         'adresse_rue' => '', 'adresse_npa' => '', 'remarques' => '', 'feuille' => []];
// L'heure de représentation se glisse dans le déroulé, à son rang.
$lignesDeroule = function (array $ev): array {
    foreach (explode("\n\n", feuille_ical_description($ev)) as $bloc) {
        $l = explode("\n", $bloc);
        if ($l[0] === 'DÉROULÉ') { return array_slice($l, 1); }
    }
    return [];
};
$evOrdre = $evDesc;
$evOrdre['feuille'] = [
    ['id' => 1, 'type' => 'horaire', 'libelle' => 'Get-in', 'debut' => '14:00', 'fin' => '', 'remarque' => ''],
    ['id' => 2, 'type' => 'horaire', 'libelle' => 'Repas', 'debut' => '18:30', 'fin' => '', 'remarque' => ''],
    ['id' => 3, 'type' => 'horaire', 'libelle' => 'Loges libérées', 'debut' => '23:30', 'fin' => '', 'remarque' => ''],
];
check('le spectacle se glisse à son rang chronologique',
    ['14:00  Get-in', '18:30  Repas', '20:30 – 22:00  Spectacle', '23:30  Loges libérées'],
    $lignesDeroule($evOrdre));

$evTard = $evOrdre;
$evTard['heure_debut'] = '23:59';
$evTard['heure_fin'] = '';
check('plus tardif que tous, il ferme la liste',
    ['14:00  Get-in', '18:30  Repas', '23:30  Loges libérées', '23:59  Spectacle'],
    $lignesDeroule($evTard));

$evSeul = $evOrdre;
$evSeul['feuille'] = [];
check('sans aucun horaire, il est le déroulé à lui seul',
    ['20:30 – 22:00  Spectacle'], $lignesDeroule($evSeul));

$evMuet = $evOrdre;
$evMuet['heure_debut'] = '';
$evMuet['heure_fin'] = '';
check('sans heure annoncée, le déroulé reste celui de la feuille',
    ['14:00  Get-in', '18:30  Repas', '23:30  Loges libérées'], $lignesDeroule($evMuet));

check('une date nue n\'a que ses infos publiques',
    ['INFOS PUBLIQUES'],
    array_map(fn (string $b): string => explode("\n", $b)[0], explode("\n\n", feuille_ical_description($evNu))));

echo "\n$tests tests, $fails échec(s)\n";
exit($fails > 0 ? 1 : 0);
