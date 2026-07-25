<?php
// Tests du module booking. Lancement : php tests/booking_test.php
// N'utilise pas la base de l'application — seulement les fonctions pures de
// lib/booking.php (pas de dépendance à db()) : normalisation de nom,
// chevauchement de période festival, personnalisation de gabarit, lecture
// CSV, conversion de date. Les fonctions qui interrogent db() (filtrage
// mailing, analyse/application de l'import, dernier contact dérivé) suivent
// le même mécanisme que le reste de l'app (global db()) et ne sont pas
// couvertes ici, comme pour les autres modules (cf. facturation_test.php,
// evenements_test.php, qui ne testent que les fonctions paramétrées par $pdo).

require_once __DIR__ . '/../lib/booking.php';

$tests = 0;
$fails = 0;
function check(string $label, $attendu, $obtenu): void
{
    global $tests, $fails;
    $tests++;
    $ok = $attendu === $obtenu;
    if (!$ok) {
        $fails++;
        printf("  FAIL  %-56s attendu %s, obtenu %s\n", $label, var_export($attendu, true), var_export($obtenu, true));
    } else {
        printf("  ok    %s\n", $label);
    }
}

echo "1) normaliser_nom_structure() — casse/espaces/ponctuation ignorés\n";
check('casse ignorée', normaliser_nom_structure('anticoncert'), normaliser_nom_structure('AntiConcert'));
check('espaces ignorés', 'latannerie', normaliser_nom_structure('La Tannerie'));
check('ponctuation ignorée', normaliser_nom_structure('anticoncert'), normaliser_nom_structure('Anti-Concert'));
check('accents neutralisés (via mb_strtolower, pas de suppression diacritique)', true, normaliser_nom_structure('Café de la Tannerie') !== '');
check('noms différents restent différents', false, normaliser_nom_structure('La Tannerie') === normaliser_nom_structure('Café de la Tannerie'));

echo "2) periode_chevauche() — période de festival vs plage filtrée\n";
check('chevauchement simple (juin-sept vs avril-sept)', true, periode_chevauche(6, 9, 4, 9));
check('aucun chevauchement (juin-sept vs octobre-décembre)', false, periode_chevauche(6, 9, 10, 12));
check('passage d\'année côté festival (nov-fév vs déc-janv)', true, periode_chevauche(11, 2, 12, 1));
check('passage d\'année côté festival, hors plage (nov-fév vs avril-sept)', false, periode_chevauche(11, 2, 4, 9));
check('passage d\'année côté filtre (mai-juin vs nov-fév)', false, periode_chevauche(5, 6, 11, 2));
check('mois unique (juillet vs juin-août)', true, periode_chevauche(7, 7, 6, 8));

echo "3) mailing_personnaliser() — variables {{prenom}}/{{nom_structure}}\n";
check(
    'les deux variables résolues',
    'Bonjour Jean, ici La Tannerie.',
    mailing_personnaliser('Bonjour {{prenom}}, ici {{nom_structure}}.', ['nom' => 'La Tannerie'], ['prenom' => 'Jean'])
);
check(
    'contact absent → prénom vide',
    'Bonjour , ici La Tannerie.',
    mailing_personnaliser('Bonjour {{prenom}}, ici {{nom_structure}}.', ['nom' => 'La Tannerie'], null)
);

echo "4) structure_date_csv_vers_iso() — JJ/MM/AAAA, JJ.MM.AA, ISO → AAAA-MM-JJ\n";
check('JJ/MM/AAAA', '2019-06-07', structure_date_csv_vers_iso('07/06/2019'));
check('JJ.MM.AA (points, année 2 chiffres)', '2022-11-21', structure_date_csv_vers_iso('21.11.22'));
check('JJ-MM-AAAA (tirets)', '2024-03-05', structure_date_csv_vers_iso('5-3-2024'));
check('ISO AAAA-MM-JJ accepté tel quel', '2019-06-07', structure_date_csv_vers_iso('2019-06-07'));
check('numéro de série Excel', '2022-11-21', structure_date_csv_vers_iso('44886'));
check('numéro de série Excel avec fraction horaire', '2022-11-21', structure_date_csv_vers_iso('44886.5'));
check('nombre hors plage de dates (ex. jauge)', null, structure_date_csv_vers_iso('12345'));
check('date invalide (jour hors calendrier)', null, structure_date_csv_vers_iso('31/02/2019'));
check('format non reconnu (texte)', null, structure_date_csv_vers_iso('hier'));
check('vide', null, structure_date_csv_vers_iso(''));

echo "5) structures_detecter_delimiteur() / structures_lire_csv()\n";
check('virgule majoritaire', ',', structures_detecter_delimiteur('nom,ville,email'));
check('point-virgule majoritaire (export Excel FR)', ';', structures_detecter_delimiteur('nom;ville;email'));
[$entete, $lignes] = structures_lire_csv("nom;ville\nLa Tannerie;Bourg-en-Bresse\nKitsch'n bar;Strasbourg");
check('en-tête lu', ['nom', 'ville'], $entete);
check('nombre de lignes de données', 2, count($lignes));
check('première ligne', ['La Tannerie', 'Bourg-en-Bresse'], $lignes[0]);
[$enteteBom, ] = structures_lire_csv("\xEF\xBB\xBFnom,ville\nTest,Ici");
check('BOM UTF-8 initial retiré', 'nom', $enteteBom[0]);
[$enteteVide, $lignesVide] = structures_lire_csv('');
check('fichier vide → en-tête vide', [], $enteteVide);
check('fichier vide → aucune ligne', [], $lignesVide);

// Régression : un champ entre guillemets contenant un retour à la ligne (cellule
// multi-ligne d'un export Excel) ne doit pas être coupé en deux enregistrements —
// bug corrigé (découpage par flux fgetcsv, plus par regex sur les \n du texte brut).
$csvMultiligne = "nom,notes,ville\nLa Tannerie,\"Ligne 1\nLigne 2\nLigne 3\",Bourg-en-Bresse\nKitsch'n bar,RAS,Strasbourg";
[, $lignesMulti] = structures_lire_csv($csvMultiligne);
check('champ multi-ligne entre guillemets : 2 enregistrements (pas 4)', 2, count($lignesMulti));
check('champ multi-ligne entre guillemets : contenu complet préservé', "Ligne 1\nLigne 2\nLigne 3", $lignesMulti[0][1]);
check('champ multi-ligne entre guillemets : colonne suivante pas décalée', 'Bourg-en-Bresse', $lignesMulti[0][2]);
check('ligne suivante intacte après un champ multi-ligne', "Kitsch'n bar", $lignesMulti[1][0]);

// Virgule et guillemet échappé (doublé) à l'intérieur d'un champ entre guillemets.
$csvVirguleGuillemet = 'nom,notes' . "\n" . 'Rousselet,"Jean-Luc, dit ""Jeannot"""';
[, $lignesVg] = structures_lire_csv($csvVirguleGuillemet);
check('virgule dans un champ entre guillemets : pas de colonne en trop', 2, count($lignesVg[0]));
check('guillemet doublé décodé', 'Jean-Luc, dit "Jeannot"', $lignesVg[0][1]);

echo "6) structure_tags_depuis_statut() — décodage d'une cellule « Statut » codée\n";
check('symbole isolé', ['Actif'], structure_tags_depuis_statut('&'));
check('plusieurs symboles séparés par des espaces', ['Actif', "À contacter en cas d'événement", 'À contacter en cas de tournée'], structure_tags_depuis_statut('& E T'));
check('casse distincte : x (minuscule) != X (majuscule)', ['Ne pas contacter (trop gros, autre style)'], structure_tags_depuis_statut('x'));
check('casse distincte : X (majuscule)', ['Ne pas contacter (autre raison)'], structure_tags_depuis_statut('X'));
check('symboles inconnus ignorés', [], structure_tags_depuis_statut('???unknown???'));
check('cellule vide', [], structure_tags_depuis_statut(''));
check('doublons dédupliqués', ['Actif'], structure_tags_depuis_statut('& &'));

echo "7) structure_import_groupe() — organisateur depuis colonne ou parenthèses\n";
$g = structure_import_groupe(['nom' => 'Festival Pause Guitare (Arpèges et Trémolos)']);
check('parenthèse : organisateur extrait', 'Arpèges et Trémolos', $g['organisateur']);
check('parenthèse : nom du lieu nettoyé', 'Festival Pause Guitare', $g['nom_lieu']);
check('parenthèse : source = parenthese', 'parenthese', $g['org_source']);
$g = structure_import_groupe(['nom' => 'Festival Les Ptits Bouchons', 'organisateur' => 'Arpèges et Trémolos']);
check('colonne : organisateur prioritaire', 'Arpèges et Trémolos', $g['organisateur']);
check('colonne : nom du lieu conservé tel quel', 'Festival Les Ptits Bouchons', $g['nom_lieu']);
check('colonne : source = colonne', 'colonne', $g['org_source']);
$g = structure_import_groupe(['nom' => 'La Tannerie']);
check('sans organisateur : vide', '', $g['organisateur']);
check('sans organisateur : nom du lieu = nom', 'La Tannerie', $g['nom_lieu']);
check('sans organisateur : source vide', '', $g['org_source']);
$g = structure_import_groupe(['nom' => 'Arpèges et Trémolos', 'organisateur' => 'Arpèges et Trémolos']);
check('organisateur = lui-même (self)', 'Arpèges et Trémolos', $g['nom_lieu']);

echo "8) structures_grouper() — regroupement (catégorie organisateur + entité réelle)\n";
// Construit une ligne d'analyse (cat_organisateur = catégorie salle/festival par défaut).
$mkrow = function (int $i, string $nom, string $orgCol = '', bool $cat = true): array {
    $g = structure_import_groupe(['nom' => $nom, 'organisateur' => $orgCol]);
    return ['index' => $i, 'organisateur' => $g['organisateur'], 'nom_lieu' => $g['nom_lieu'],
            'org_source' => $g['org_source'], 'cat_organisateur' => $cat];
};
$cle = normaliser_nom_structure('Arpèges et Trémolos');
// Cas nominal : organisateur présent comme sa propre ligne + 2 festivals.
$groupes = structures_grouper([
    $mkrow(0, 'Arpèges et Trémolos'),
    $mkrow(1, 'Festival Pause Guitare (Arpèges et Trémolos)'),
    $mkrow(2, 'Festival Les Ptits Bouchons (Arpèges et Trémolos)'),
    $mkrow(3, 'La Tannerie'),
]);
check('un seul groupe (self présent)', 1, count($groupes));
check('deux salles/festivals dans le groupe', 2, count($groupes[$cle]['lieux'] ?? []));
check('ligne organisateur repérée (self_index)', 0, $groupes[$cle]['self_index'] ?? null);
// FAUX POSITIF ville : « X (Le Havre) », « Y (Le Havre) » sans ligne « Le Havre ».
check('parenthèse de ville sans entité réelle → aucun groupe', 0, count(structures_grouper([
    $mkrow(0, 'Concert Machin (Le Havre)'),
    $mkrow(1, 'Concert Bidule (Le Havre)'),
])));
// Colonne « Organisateur » explicite : de confiance même sans ligne organisateur.
check('organisateur en colonne (sans self) → groupe conservé', 1, count(structures_grouper([
    $mkrow(0, 'Salle A', 'Asso Mère'),
    $mkrow(1, 'Salle B', 'Asso Mère'),
])));
// FAUX POSITIF catégorie : radios (cat_organisateur = false) jamais regroupées.
check('médias/radios (hors catégorie organisateur) → aucun groupe', 0, count(structures_grouper([
    $mkrow(0, 'Radio Machin (France Bleu)', '', false),
    $mkrow(1, 'Radio Bidule (France Bleu)', '', false),
    $mkrow(2, 'France Bleu', '', false),
])));
check('sans aucune parenthèse ni colonne : aucun groupe', 0, count(structures_grouper([
    $mkrow(0, 'A'),
    $mkrow(1, 'B'),
])));
check('organisateur sans lieu (que le self) : pas de groupe', 0, count(structures_grouper([
    $mkrow(0, 'Solo', 'Solo'),
])));

echo "9) mois_token_vers_numero() — numéros, noms, abréviations\n";
check('numéro', 6, mois_token_vers_numero('6'));
check('numéro hors bornes → null', null, mois_token_vers_numero('13'));
check('nom complet', 3, mois_token_vers_numero('mars'));
check('nom avec accent', 2, mois_token_vers_numero('février'));
check('abréviation « juil » → juillet', 7, mois_token_vers_numero('juil'));
check('abréviation « déc » → décembre', 12, mois_token_vers_numero('déc'));
check('code anglais « feb » → février', 2, mois_token_vers_numero('feb'));
check('code anglais « apr » → avril', 4, mois_token_vers_numero('apr'));
check('code anglais « may » → mai', 5, mois_token_vers_numero('may'));
check('code anglais « jun » → juin', 6, mois_token_vers_numero('jun'));
check('code anglais « aug » → août', 8, mois_token_vers_numero('aug'));
check('code anglais « Dec » (casse) → décembre', 12, mois_token_vers_numero('Dec'));
check('jeton inconnu → null', null, mois_token_vers_numero('xyz'));

echo "10) mois_plage_depuis_liste() — champ mois séparés par des espaces\n";
check('contigu « 6 7 8 »', ['debut' => 6, 'fin' => 8], mois_plage_depuis_liste('6 7 8'));
check('un seul mois', ['debut' => 4, 'fin' => 4], mois_plage_depuis_liste('4'));
check('désordonné « 8 6 7 »', ['debut' => 6, 'fin' => 8], mois_plage_depuis_liste('8 6 7'));
check('passage d\'année « 11 12 1 2 »', ['debut' => 11, 'fin' => 2], mois_plage_depuis_liste('11 12 1 2'));
check('noms « nov déc jan fév »', ['debut' => 11, 'fin' => 2], mois_plage_depuis_liste('nov déc jan fév'));
check('tous les mois → 1..12', ['debut' => 1, 'fin' => 12], mois_plage_depuis_liste('1 2 3 4 5 6 7 8 9 10 11 12'));
check('vide → null/null', ['debut' => null, 'fin' => null], mois_plage_depuis_liste(''));
check('séparateurs mixtes « 6, 7 ; 8 »', ['debut' => 6, 'fin' => 8], mois_plage_depuis_liste('6, 7 ; 8'));

echo "11) texte_sans_accents() — repli des accents pour rapprochement d'intitulés\n";
check('accents français repliés', 'media', texte_sans_accents('Média'));
check('majuscules + accents', 'theatre', texte_sans_accents('THÉÂTRE'));
check('cédille', 'ca', texte_sans_accents('Çà'));
check('ligature œ', 'oeuvre', texte_sans_accents('œuvre'));
check('espaces/casse', 'media', texte_sans_accents('  Media  '));

echo "12) structure_import_resoudre_categorie() — colonne catégorie tolérante\n";
$racines = ['organisateur' => 'Organisateur', 'media' => 'Media', 'autres' => 'Autres'];
$subs = [
    'radio'    => ['parent' => 'Media', 'nom' => 'Radio'],
    'festival' => ['parent' => 'Organisateur', 'nom' => 'Festival'],
];
$rc = fn ($cat, $sous, $typeCat) => structure_import_resoudre_categorie($cat, $sous, $typeCat, $racines, $subs, 'Organisateur');
check('racine exacte', ['categorie' => 'Media', 'sous_categorie' => ''], $rc('Media', '', ''));
check('racine avec accent', ['categorie' => 'Media', 'sous_categorie' => ''], $rc('Média', '', ''));
check('sous-cat en colonne catégorie → parent + sous', ['categorie' => 'Media', 'sous_categorie' => 'Radio'], $rc('Radio', '', ''));
check('sous-cat mais sous déjà fournie → ne pas écraser', ['categorie' => 'Media', 'sous_categorie' => 'Perso'], $rc('Radio', 'Perso', ''));
check('type de lieu → parent, pas de sous réinjectée', ['categorie' => 'Organisateur', 'sous_categorie' => ''], $rc('Festival', '', 'Festival'));
check('inconnu → défaut, sous conservée', ['categorie' => 'Organisateur', 'sous_categorie' => 'X'], $rc('Bidule', 'X', ''));

echo "\n$tests tests, $fails échec(s)\n";
exit($fails > 0 ? 1 : 0);
