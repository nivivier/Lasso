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

echo "\n3 bis) Déroulé type\n";
$pdo->exec('DELETE FROM evenement_feuille');
check('cinq lignes posées d\'un coup', 5, feuille_deroule_type(1));
check('dans l\'ordre de la journée', FEUILLE_HORAIRES_TYPES, $ordre());
check('toutes sans heure — elles se remplissent ensuite', ['', '', '', '', ''],
    array_column(feuille_elements(1), 'debut'));
// Deux clics de suite ne doivent pas donner deux « Get-in ».
check('un second appel n\'ajoute rien', 0, feuille_deroule_type(1));
$pdo->exec("DELETE FROM evenement_feuille WHERE libelle IN ('Repas', 'Show')");
check('seules les lignes manquantes reviennent', 2, feuille_deroule_type(1));
check('et reprennent leur place à la suite', FEUILLE_HORAIRES_TYPES, $ordre());
// Un intitulé retapé à la main, dans une autre casse, reste le même moment.
$pdo->exec('DELETE FROM evenement_feuille');
$pdo->exec("INSERT INTO evenement_feuille (evenement_id, type, ordre, libelle) VALUES (1, 'horaire', 1, 'get-in')");
check('la casse n\'engendre pas de doublon', 4, feuille_deroule_type(1));
$pdo->exec('DELETE FROM evenement_feuille');

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

echo "\n$tests tests, $fails échec(s)\n";
exit($fails > 0 ? 1 : 0);
