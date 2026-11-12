<?php
// ============================================================================
//  Base de DÉMONSTRATION — que des données inventées.
//
//  Fabrique un fichier SQLite complet (schéma + migrations + contenu factice)
//  pour montrer, essayer ou déboguer l'application sans jamais ouvrir les
//  vraies données de l'association : ni salaire, ni n° AVS, ni adresse, ni
//  contact réel. Toutes les personnes, structures et sommes ci-dessous sont
//  imaginaires, et les adresses e-mail utilisent le domaine réservé
//  « .test » (RFC 2606), qui ne peut recevoir aucun message.
//
//  Lancement :
//      php scripts/base_demo.php                  → data/demo.sqlite
//      php scripts/base_demo.php /chemin/x.sqlite → ailleurs
//      php scripts/base_demo.php --ecraser        → refait par-dessus
//      php scripts/base_demo.php --sans-scan      → sans fabriquer de justificatif
//
//  Pour s'en servir, pointer la base dessus dans lib/config.local.php :
//      define('APP_DB_PATH', __DIR__ . '/../data/demo.sqlite');
//  puis commenter cette ligne pour revenir à la vraie base.
//
//  ⚠️ Le script REFUSE d'écrire sur database.sqlite, et refuse d'écraser un
//  fichier existant sans --ecraser : le but est de ne jamais pouvoir détruire
//  des données réelles par une faute de frappe.
//
//  Le tirage est DÉTERMINISTE (mt_srand fixe) : deux exécutions donnent la
//  même base, ce qui permet de comparer deux rendus d'une page sans que le
//  contenu bouge sous les pieds.
// ============================================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$racine = dirname(__DIR__);
$args   = array_slice($argv, 1);
$ecraser  = in_array('--ecraser', $args, true);
// --sans-scan : ne fabrique AUCUN justificatif. Les faux scans sont les seules
// choses que ce script écrit hors du fichier de base — ils vont dans
// data/fichiers, le seul endroit où l'application sait les relire. Ce drapeau
// sert à tout ce qui doit repartir sans laisser de trace (tests, essai rapide).
$sansScan = in_array('--sans-scan', $args, true);
$args     = array_values(array_filter($args, fn ($a) => $a[0] !== '-'));
$chemin  = $args[0] ?? $racine . '/data/demo.sqlite';
if ($chemin[0] !== '/') {
    $chemin = getcwd() . '/' . $chemin;
}

// Garde-fou : jamais sur la base de travail, quel que soit le dossier.
if (basename($chemin) === 'database.sqlite') {
    fwrite(STDERR, "Refus : « database.sqlite » est le nom de la vraie base. Choisissez un autre nom.\n");
    exit(1);
}
if (file_exists($chemin) && !$ecraser) {
    fwrite(STDERR, "Refus : $chemin existe déjà. Relancez avec --ecraser pour le refaire.\n");
    exit(1);
}
foreach ([$chemin, $chemin . '-wal', $chemin . '-shm'] as $f) {
    if (file_exists($f)) {
        unlink($f);
    }
}

define('APP_DB_PATH', $chemin);
require_once $racine . '/lib/db.php';        // crée le schéma à la première connexion
require_once $racine . '/lib/calc.php';
require_once $racine . '/lib/helpers.php';
require_once $racine . '/lib/modules.php';
require_once $racine . '/lib/personnes.php';
require_once $racine . '/lib/pieces.php';
require_once $racine . '/lib/compta.php';
require_once $racine . '/lib/facturation.php';
require_once $racine . '/lib/evenements.php';
require_once $racine . '/lib/booking.php';
require_once $racine . '/lib/fonds.php';
require_once $racine . '/lib/routes_salaires.php'; // sauvegarder_fiche()

mt_srand(20260101);

$pdo = db();
$ins = function (string $table, array $data) use ($pdo): int {
    $cols = implode(',', array_keys($data));
    $m    = ':' . implode(',:', array_keys($data));
    $pdo->prepare("INSERT INTO $table ($cols) VALUES ($m)")->execute($data);
    return (int) $pdo->lastInsertId();
};
$choix = fn (array $a) => $a[mt_rand(0, count($a) - 1)];
$jour  = fn (string $debut, int $n) => date('Y-m-d', strtotime($debut . ' +' . $n . ' days'));

// ---------------------------------------------------------------------------
//  L'employeur : une association inventée de toutes pièces.
// ---------------------------------------------------------------------------
$parametres = [
    'employeur_nom'            => 'Association Grand-Vent',
    'employeur_rue'            => 'Rue des Bossons 14',
    'employeur_npa'            => '1213 Petit-Lancy',
    'employeur_pays'           => 'Suisse',
    'employeur_telephone'      => '022 000 00 00',
    'employeur_email_contact'  => 'contact@grand-vent.test',
    'employeur_email_expediteur' => 'ne-pas-repondre@grand-vent.test',
    'employeur_contact_nom'    => 'Amaya Trüb',
    'employeur_contact_tel'    => '022 000 00 01',
    'employeur_heures_hebdo'   => '40.00',
    'employeur_couleur_principale' => '#2f6f5e',
    'employeur_couleur_evidence'   => '#c2622d',
    'evenements_terme_projet'          => 'Projets',
    'evenements_terme_projet_singulier' => 'Projet',
    'modules_actifs'           => implode(',', array_keys(MODULES)),
];
$up = $pdo->prepare('INSERT INTO parametres (cle, valeur) VALUES (?, ?)
                     ON CONFLICT(cle) DO UPDATE SET valeur = excluded.valeur');
foreach ($parametres as $k => $v) {
    $up->execute([$k, $v]);
}

// ---------------------------------------------------------------------------
//  Comptes de connexion. Mots de passe annoncés en fin de script : une base de
//  démonstration qu'on ne peut pas ouvrir ne sert à rien.
// ---------------------------------------------------------------------------
const DEMO_MDP = 'demo1234';
$comptes = [
    ['admin@grand-vent.test',     'Amaya',  'Trüb',       array_fill_keys(PERMISSION_MODULES, 'ecriture')],
    ['tresorier@grand-vent.test', 'Basile', 'Krähenbühl', ['compta' => 'ecriture', 'analytique' => 'ecriture',
                                                           'pieces' => 'ecriture', 'facturation' => 'ecriture',
                                                           'carnet' => 'lecture']],
    ['booking@grand-vent.test',   'Céleste', 'Nyffeler',  ['booking' => 'ecriture', 'mailing' => 'ecriture',
                                                           'carnet' => 'ecriture', 'evenements' => 'ecriture',
                                                           'fonds' => 'lecture']],
];
$utilisateurs = [];
foreach ($comptes as [$email, $prenom, $nom, $droits]) {
    $uid = $ins('utilisateurs', [
        'email'        => $email,
        'mot_de_passe' => password_hash(DEMO_MDP, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]),
        'prenom'       => $prenom,
        'nom'          => $nom,
        'derniere_connexion_le' => gmdate('Y-m-d H:i:s', strtotime('-' . mt_rand(1, 20) . ' days')),
    ]);
    enregistrer_permissions_utilisateur($uid, $droits);
    $utilisateurs[] = $uid;
}

// ---------------------------------------------------------------------------
//  Le carnet d'adresses : les personnes d'abord, puisque tout le reste s'y
//  rattache (employés, contacts d'une structure, bénéficiaires d'une pièce).
// ---------------------------------------------------------------------------
$prenoms = ['Amaya', 'Basile', 'Céleste', 'Dimitri', 'Elvire', 'Fabio', 'Garance', 'Hadrien',
            'Iris', 'Joaquim', 'Kenza', 'Léonard', 'Maïa', 'Noé', 'Orane', 'Pablo',
            'Quentin', 'Rosalie', 'Simon', 'Tamara', 'Ulysse', 'Violaine', 'Wanda', 'Xavier',
            'Yann', 'Zoé', 'Alban', 'Bérénice', 'Cyprien', 'Delphine'];
$noms = ['Trüb', 'Krähenbühl', 'Nyffeler', 'Volery', 'Baumgartner', 'Lanzrein', 'Progin',
         'Dübendorfer', 'Mottier', 'Cheseaux', 'Zufferey', 'Bonvin', 'Délèze', 'Rouiller',
         'Gillabert', 'Pittet', 'Jaquier', 'Berthoud', 'Vuillème', 'Perrenoud', 'Chollet',
         'Margairaz', 'Fellay', 'Udry', 'Bussien', 'Sandoz', 'Clerc', 'Monnard', 'Rapin', 'Favrod'];
$villes = [['1205', 'Genève'], ['1227', 'Carouge'], ['1213', 'Petit-Lancy'], ['1260', 'Nyon'],
           ['1800', 'Vevey'], ['1003', 'Lausanne'], ['2000', 'Neuchâtel'], ['1950', 'Sion'],
           ['2800', 'Delémont'], ['1700', 'Fribourg']];
$rues = ['Rue du Vieux-Four', 'Chemin des Mésanges', 'Avenue des Bleuets', 'Ruelle du Lavoir',
         'Route de la Fontaine', 'Chemin du Clos-Brûlé', 'Rue des Tanneurs', 'Sentier des Vignes'];

$personnes = [];
for ($i = 0; $i < 30; $i++) {
    $prenom = $prenoms[$i];
    $nom    = $noms[$i];
    [$npa, $ville] = $villes[$i % count($villes)];
    $personnes[] = $ins('personnes', [
        'prenom'       => $prenom,
        'nom'          => $nom,
        'email'        => str_replace(["'", ' '], '', texte_sans_accents($prenom . '.' . $nom)) . '@example.test',
        'telephone'    => '07' . mt_rand(6, 9) . ' ' . mt_rand(100, 999) . ' ' . mt_rand(10, 99) . ' ' . mt_rand(10, 99),
        'langue'       => $choix(['fr', 'fr', 'fr', 'de']),
        'rue'          => $choix($rues) . ' ' . mt_rand(1, 60),
        'npa_localite' => $npa . ' ' . $ville,
        'date_naissance' => sprintf('%04d-%02d-%02d', mt_rand(1962, 2002), mt_rand(1, 12), mt_rand(1, 28)),
        'notes'        => $i % 7 === 0 ? 'Préfère être jointe par téléphone.' : '',
    ]);
}

// Les trois comptes de connexion désignent les trois premières personnes :
// un compte et un être humain, pas deux identités (docs/SPEC_PERSONNES.md).
foreach ($utilisateurs as $rang => $uid) {
    $pdo->prepare('UPDATE utilisateurs SET personne_id = ?, prenom = "", nom = "" WHERE id = ?')
        ->execute([$personnes[$rang], $uid]);
}

// ---------------------------------------------------------------------------
//  Les structures : salles, festivals, médias, bailleurs. Lieux inventés,
//  avec des NPA plausibles mais aucune adresse réelle.
// ---------------------------------------------------------------------------
$tags = [];
foreach ([['Coup de cœur', '#2f6f5e'], ['Petite jauge', '#8a6d3b'], ['Scène ouverte', '#5b6abf'],
          ['Déjà joué', '#7a4f9c'], ['À relancer', '#c2622d']] as [$nomTag, $couleur]) {
    $tags[] = $ins('structure_tags', ['nom' => $nomTag, 'couleur' => $couleur]);
}

// [nom, rue, npa, localité, pays, canton/dept, catégorie, sous-catégorie, jauge min, jauge max, statut]
$structuresSrc = [
    ['Salle des Marronniers',          'Place du Bourg 3',        '1260', 'Nyon',             'Suisse', 'VD', 'organisateur', 'Salle',              120, 260, 'actif'],
    ['Café du Vieux-Pont',             'Quai des Pêcheurs 7',     '1800', 'Vevey',            'Suisse', 'VD', 'organisateur', 'Café-concert',        40,  90, 'contact_privilegie'],
    ['La Grange aux Luthiers',         'Chemin des Ocres 21',     '1950', 'Sion',             'Suisse', 'VS', 'organisateur', 'Salle de location',   60, 140, 'actif'],
    ['Théâtre du Pré-Vert',            'Rue de la Filature 9',    '1227', 'Carouge',          'Suisse', 'GE', 'organisateur', 'Salle',              180, 320, 'actif'],
    ['Maison de quartier des Cerisiers', 'Avenue des Cerisiers 44', '1203', 'Genève',         'Suisse', 'GE', 'organisateur', 'Café associatif',     50, 110, 'actif'],
    ['Caveau Saint-Loup',              'Rue Basse 12',            '2000', 'Neuchâtel',        'Suisse', 'NE', 'organisateur', 'Café-concert',        35,  70, 'a_verifier'],
    ['Le Hangar 7',                    'Route de l\'Industrie 7', '1030', 'Bussigny',         'Suisse', 'VD', 'organisateur', 'Salle de location',  200, 450, 'actif'],
    ['Salle communale de Fontenailles','Place de la Cure 1',      '1400', 'Yverdon-les-Bains','Suisse', 'VD', 'organisateur', 'Salle communale',    100, 220, 'inactif'],
    ['Espace Tilleul',                 'Chemin du Tilleul 5',     '2800', 'Delémont',         'Suisse', 'JU', 'organisateur', 'Saison culturelle',  150, 300, 'actif'],
    ['Les Docks du Rhône',             'Quai du Seujet 18',       '1201', 'Genève',           'Suisse', 'GE', 'organisateur', 'Salle',              300, 600, 'contact_privilegie'],
    ['Cave à Jazz du Chêne',           'Route de Chêne 102',      '1224', 'Chêne-Bougeries',  'Suisse', 'GE', 'organisateur', 'Café-concert',        45,  85, 'actif'],
    ['Festival Rives Sonores',         'Case postale 44',         '1110', 'Morges',           'Suisse', 'VD', 'organisateur', 'Saison culturelle',  400, 900, 'actif'],
    ['Scène du Marais',                'Rue des Tilleuls 2',      '74100', 'Annemasse',       'France', '74', 'organisateur', 'Salle',              160, 280, 'actif'],
    ['L\'Atelier des Granges',         'Avenue du Lavoir 15',     '01200', 'Bellegarde',      'France', '01', 'organisateur', 'Salle de location',   70, 150, 'a_verifier'],
    ['Le Préau',                       'Chemin des Vignes 8',     '74200', 'Thonon',          'France', '74', 'organisateur', 'Café-concert',        55, 100, 'ne_pas_contacter'],
    ['Médiathèque du Lignon',          'Place du Lignon 30',      '1219', 'Le Lignon',        'Suisse', 'GE', 'organisateur', 'Salle communale',     60, 120, 'actif'],
    ['Radio Champ-Libre',              'Rue du Pré-Jérôme 4',     '1205', 'Genève',           'Suisse', 'GE', 'media',        '',                      0,   0, 'actif'],
    ['Journal « Le Courant »',         'Boulevard des Acacias 60','1227', 'Carouge',          'Suisse', 'GE', 'media',        '',                      0,   0, 'actif'],
    ['Fondation du Pont-Neuf',         'Rue du Pont-Neuf 2',      '1003', 'Lausanne',         'Suisse', 'VD', 'autres',       '',                      0,   0, 'actif'],
    ['Commune de Pré-Fleuri',          'Mairie, Place Centrale 1','1213', 'Petit-Lancy',      'Suisse', 'GE', 'autres',       '',                      0,   0, 'actif'],
    ['Fonds culturel Trois-Rivières',  'Case postale 180',        '1700', 'Fribourg',         'Suisse', 'FR', 'autres',       '',                      0,   0, 'actif'],
    ['Atelier Papier-Carbone',         'Rue du Stand 29',         '1204', 'Genève',           'Suisse', 'GE', 'entourage',    '',                      0,   0, 'actif'],
    ['Fondation des Quatre-Saules',    'Rue Haute 11',            '2000', 'Neuchâtel',        'Suisse', 'NE', 'autres',       '',                      0,   0, 'actif'],
    ['Loterie des Communes du Lac',    'Case postale 9',          '1260', 'Nyon',             'Suisse', 'VD', 'autres',       '',                      0,   0, 'actif'],
    ['Fonds Mécénat Belle-Vue',        'Avenue du Parc 77',       '1005', 'Lausanne',         'Suisse', 'VD', 'autres',       '',                      0,   0, 'actif'],
    ['Service culturel cantonal',      'Rue de l\'Hôtel-de-Ville 2', '1204', 'Genève',       'Suisse', 'GE', 'autres',       '',                      0,   0, 'contact_privilegie'],
];

$structures = [];
$pdo->beginTransaction();
foreach ($structuresSrc as $i => $s) {
    [$nom, $rue, $npa, $loc, $pays, $dept, $cat, $sousCat, $jMin, $jMax, $statut] = $s;
    $id = $ins('structures', [
        'nom'               => $nom,
        'adresse_rue'       => $rue,
        'adresse_npa'       => $npa,
        'adresse_localite'  => $loc,
        'adresse_pays'      => $pays,
        'departement_canton' => $dept,
        'grande_region'     => grande_region_deduite($pays, $dept, false) ?? '',
        'categorie'         => $cat,
        'sous_categorie'    => $sousCat,
        'site_web'          => $cat === 'organisateur' ? 'https://' . str_replace(["'", ' ', '«', '»'], '', texte_sans_accents($nom)) . '.test' : '',
        'jauge_min'         => $jMin ?: null,
        'jauge_max'         => $jMax ?: null,
        'statut'            => $statut,
        'notes'             => $i % 5 === 0 ? "Scène correcte, régie sur place.\nPrévoir le repas d'équipe." : '',
        'mois_debut'        => $cat === 'organisateur' ? 9 : null,
        'mois_fin'          => $cat === 'organisateur' ? 5 : null,
        'cree_le'           => gmdate('Y-m-d H:i:s', strtotime('-' . (400 - $i * 11) . ' days')),
    ]);
    $structures[] = $id;
    // Une à trois étiquettes, et un lien avec deux à trois personnes du carnet.
    foreach ((array) array_rand(array_flip($tags), mt_rand(1, 3)) as $t) {
        $pdo->prepare('INSERT OR IGNORE INTO structure_tag_liens (structure_id, tag_id) VALUES (?, ?)')->execute([$id, $t]);
    }
}
$pdo->commit();

// Les contacts : une personne du carnet RATTACHÉE à une structure, avec son
// rôle. Deux personnes apparaissent exprès dans deux structures — c'est le cas
// que le carnet existe pour tenir (docs/SPEC_PERSONNES.md).
$roles = ['Programmation', 'Direction', 'Technique', 'Administration', 'Communication', 'Bénévole'];
$pdo->beginTransaction();
foreach ($structures as $i => $sid) {
    $nb = $i < 16 ? mt_rand(1, 3) : 1;
    for ($k = 0; $k < $nb; $k++) {
        $pid = $personnes[($i * 2 + $k * 7 + 3) % count($personnes)];
        $role = $roles[($i + $k) % count($roles)];
        $ins('personne_structures', [
            'personne_id'        => $pid,
            'structure_id'       => $sid,
            'role'               => $role,
            'est_booking'        => $role === 'Programmation' ? 1 : 0,
            'est_administration' => $role === 'Administration' ? 1 : 0,
            'actif'              => 1,
        ]);
    }
}
$pdo->commit();

// ---------------------------------------------------------------------------
//  Salaires : des taux horaires, six employés, puis des fiches calculées par
//  l'application elle-même (sauvegarder_fiche()) — pas d'écriture directe dans
//  `fiches`, pour que la copie figée des lignes (fiche_postes) soit exacte.
// ---------------------------------------------------------------------------
$tauxHoraires = [];
foreach ([['Standard', 32], ['Animation', 38], ['Technique', 42], ['Direction', 48]] as $th) {
    $tauxHoraires[$th[0]] = $ins('taux_horaires', ['libelle' => $th[0], 'montant' => $th[1]]);
}

// Les taux par poste de l'année en cours : poste_taux fait foi (CLAUDE.md),
// taux_par_annee n'est qu'un repli. Une base de démonstration doit donc les
// porter, sinon l'écran « Lignes du décompte » paraît vide.
$annee = (int) date('Y');
$tauxPostes = [
    'avs' => 0.053, 'ac' => 0.011, 'amat' => 0.00029, 'lpp' => 0.07,
    'emp_avs' => 0.053, 'emp_ac' => 0.011, 'emp_amat' => 0.00029, 'emp_af' => 0.0222,
    'emp_frais' => 0.0, 'emp_cpe' => 0.0007, 'emp_lfp' => 0.00082, 'emp_lpp' => 0.08,
];
$insTaux = $pdo->prepare('INSERT INTO poste_taux (poste_id, annee, valeur, valeur_alt) VALUES (?, ?, ?, ?)');
foreach ($pdo->query('SELECT id, code, mode FROM postes_salariaux') as $p) {
    $code = (string) $p['code'];
    if ($code === 'laa' || $code === 'emp_laa') {
        // laa_seuil : deux taux, le réduit et le plein (valeur / valeur_alt).
        $insTaux->execute([$p['id'], $annee, 0.0053, 0.0096]);
    } elseif (isset($tauxPostes[$code])) {
        $insTaux->execute([$p['id'], $annee, $tauxPostes[$code], null]);
    }
}
postes_oublier();

$employesSrc = [
    [3, '756.1234.5678.90', 'Ordinaire',                          0.0833, 0,      32],
    [1, '756.2345.6789.01', 'Ordinaire',                          0.1064, 0,      48],
    [2, '756.3456.7890.12', 'Ordinaire avec impôt à la source',   0.0833, 0.082,  42],
    [5, '756.4567.8901.23', 'Ordinaire',                          0.0833, 0,      38],
    [7, '756.5678.9012.34', 'Ordinaire',                          0.0833, 0,      32],
    [9, '756.6789.0123.45', 'Ordinaire',                          0.1064, 0,      38],
];
$employes = [];
foreach ($employesSrc as $e) {
    [$rang, $avs, $procedure, $vacances, $impot, $tarif] = $e;
    $employes[] = ['id' => $ins('employes', [
        'personne_id'         => $personnes[$rang],
        'numero_avs'          => avs_stocker($avs),
        'canton'              => 'Genève',
        'procedure'           => $procedure,
        'salaire_horaire'     => $tarif,
        'supplement_vacances' => $vacances,
        'impot_source_taux'   => $impot,
        'actif'               => 1,
        'avatar_couleur'      => $choix(['#2f6f5e', '#c2622d', '#5b6abf', '#7a4f9c', '#8a6d3b']),
    ]), 'tarif' => $tarif];
}

// Dix mois de fiches, deux à quatre lignes de prestation chacune.
$libellesPresta = ['Répétitions', 'Concert', 'Atelier scolaire', 'Administration',
                   'Régie son', 'Médiation', 'Préparation tournée'];
$nbFiches = 0;
foreach ($employes as $i => $emp) {
    $stmt = $pdo->prepare('SELECT ' . EMPLOYE_SELECT . EMPLOYE_FROM . ' WHERE e.id = ?');
    $stmt->execute([$emp['id']]);
    $e = $stmt->fetch();
    for ($mois = 1; $mois <= 10; $mois++) {
        if (($mois + $i) % 4 === 0) {
            continue; // tout le monde ne travaille pas tous les mois
        }
        $lignes = [];
        for ($l = 0; $l < mt_rand(2, 4); $l++) {
            $lignes[] = [
                'libelle'      => $libellesPresta[($i + $mois + $l) % count($libellesPresta)],
                'heures_unite' => $choix([2, 3, 4, 7.5]),
                'quantite'     => mt_rand(1, 6),
                'taux_horaire' => $emp['tarif'],
            ];
        }
        sauvegarder_fiche($e, $annee, $mois, sprintf('%04d-%02d-25', $annee, $mois), $lignes, null, 1);
        $nbFiches++;
    }
}
// Trois fiches restent SANS date de paiement : « Salaires à verser » est une
// carte du tableau de bord, et une base où tout est payé ne la montre jamais.
$pdo->exec('UPDATE fiches SET date_paiement = "" WHERE id IN (SELECT id FROM fiches ORDER BY id DESC LIMIT 3)');

// ---------------------------------------------------------------------------
//  Projets et axes analytiques : ce à quoi tout le reste se rattache.
// ---------------------------------------------------------------------------
$axes = [];
foreach ([['Carnets de voyage', 'CDV'], ['Petite Fanfare', 'PF'], ['Résidence Écho', 'RE'],
          ['Fonctionnement', 'FCT'], ['Administration', 'ADM']] as $o => $a) {
    $axes[$a[1]] = $ins('axes_analytiques', ['libelle' => $a[0], 'code' => $a[1], 'ordre' => $o, 'actif' => 1]);
}
$projets = [];
foreach ([['Carnets de voyage', 'CDV'], ['Petite Fanfare', 'PF'], ['Résidence Écho', 'RE']] as $o => $p) {
    $projets[] = $ins('projets', [
        'nom' => $p[0], 'ordre' => $o, 'actif' => 1, 'axe_analytique_id' => $axes[$p[1]],
        'notes' => 'Projet de démonstration — aucune donnée réelle.',
    ]);
}

// ---------------------------------------------------------------------------
//  Comptabilité : deux comptes bancaires, un plan un peu étoffé, puis un an
//  et demi d'écritures. Les IBAN sont volontairement faux (CH00…).
// ---------------------------------------------------------------------------
$comptesBancaires = [
    'courant' => $ins('comptes_bancaires', ['libelle' => 'Compte courant', 'banque' => 'Banque de démonstration',
        'iban' => 'CH00 0000 0000 0000 0000 0', 'ordre' => 0, 'actif' => 1, 'solde_initial' => 8400]),
    'epargne' => $ins('comptes_bancaires', ['libelle' => 'Compte épargne', 'banque' => 'Banque de démonstration',
        'iban' => 'CH00 0000 0000 0000 0000 1', 'ordre' => 1, 'actif' => 1, 'solde_initial' => 15000]),
];
$plan = [];
foreach ($pdo->query('SELECT id, libelle FROM plan_comptes') as $r) {
    $plan[$r['libelle']] = (int) $r['id'];
}
// Quelques comptes de plus, dont deux qui ATTENDENT un justificatif : c'est ce
// qui fait apparaître des écritures « pièce manquante » dans la démonstration.
foreach ([['Cachets et défraiements', 'charge', 1], ['Déplacements', 'charge', 1],
          ['Locations de matériel', 'charge', 1], ['Billetterie', 'produit', 0],
          ['Recettes de bar', 'produit', 0]] as $pc) {
    $plan[$pc[0]] = $ins('plan_comptes', ['libelle' => $pc[0], 'sens' => $pc[1], 'actif' => 1,
        'piece_attendue' => $pc[2], 'ordre' => 20]);
}

$mouvements = [
    ['Cotisations des membres',   'produit', 'Cotisation annuelle — membre',       [60, 200]],
    ['Dons',                      'produit', 'Don de soutien',                      [50, 1200]],
    ['Billetterie',               'produit', 'Billetterie du concert',              [320, 2400]],
    ['Recettes de bar',           'produit', 'Recette bar de la soirée',            [80, 540]],
    ['Subventions',               'produit', 'Subvention — tranche',                [2000, 12000]],
    ['Salaires et mandats',       'charge',  'Virement salaire',                    [900, 3200]],
    ['Cachets et défraiements',   'charge',  'Cachet artiste',                      [250, 1400]],
    ['Déplacements',              'charge',  'Billets de train — tournée',          [40, 460]],
    ['Locations de matériel',     'charge',  'Location sono',                       [120, 900]],
    ['Loyer',                     'charge',  'Loyer du local de répétition',        [650, 650]],
    ['Électricité',               'charge',  'Électricité du local',                [60, 180]],
    ['Matériel',                  'charge',  'Achat de matériel scénique',          [45, 720]],
    ['Frais bancaires',           'charge',  'Frais de tenue de compte',            [5, 18]],
    ['Frais informatiques',       'charge',  'Abonnement hébergement',              [12, 95]],
    ['Autres charges',            'charge',  'Frais divers',                        [20, 300]],
];
$insEcr = $pdo->prepare(
    'INSERT INTO ecritures (compte_bancaire_id, date_op, texte, tiers, montant, plan_compte_id,
                            origine_lettrage, axe_analytique_id, hash, communication)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$nomsTiers = array_map(fn ($s) => $s[0], $structuresSrc);
$ecritures = [];
$pdo->beginTransaction();
$depart = strtotime(($annee - 1) . '-01-10');
for ($i = 0; $i < 220; $i++) {
    $m = $mouvements[$i % count($mouvements)];
    $montant = mt_rand($m[3][0] * 100, $m[3][1] * 100) / 100;
    if ($m[1] === 'charge') {
        $montant = -$montant;
    }
    $date = date('Y-m-d', $depart + $i * 2 * 86400 + mt_rand(0, 86400));
    // Une écriture sur dix reste non lettrée : c'est l'état de départ d'un
    // relevé fraîchement importé, et l'écran de lettrage a ainsi de quoi vivre.
    $lettree = $i % 10 !== 3;
    $texte = $m[2] . ' — ' . $choix($nomsTiers);
    $id = 0;
    $insEcr->execute([
        $i % 7 === 0 ? $comptesBancaires['epargne'] : $comptesBancaires['courant'],
        $date, $texte, $choix($nomsTiers), $montant,
        $lettree ? $plan[$m[0]] : null,
        $lettree ? ($i % 3 === 0 ? 'regle' : 'manuel') : '',
        $i % 4 === 0 ? $axes[$choix(['CDV', 'PF', 'RE', 'FCT'])] : null,
        sha1('demo-ecriture-' . $i), 'Réf. ' . strtoupper(substr(sha1((string) $i), 0, 8)),
    ]);
    $ecritures[] = ['id' => (int) $pdo->lastInsertId(), 'date' => $date, 'montant' => $montant,
                    'compte' => $m[0], 'sens' => $m[1]];
}
$pdo->commit();

// Deux règles de lettrage, pour que l'écran des règles ne soit pas vide.
foreach ([['Loyer', 'Loyer du local', 'charge'], ['Frais bancaires', 'Frais de tenue', 'charge']] as $r) {
    $rid = $ins('regles_lettrage', [
        'compte_bancaire_id' => $comptesBancaires['courant'],
        'motif'       => $r[1],
        'type_match'  => 'contient',
        'sens_filtre' => $r[2] === 'charge' ? 'debit' : 'credit',
        'plan_compte_id' => $plan[$r[0]],
        'operateur'   => 'et',
        'priorite'    => 10,
        'actif'       => 1,
    ]);
}

// Bilan : quelques postes et leurs valeurs sur deux exercices.
foreach ([['Liquidités', [23400, 18950]], ['Créances', [4200, 6100]],
          ['Dettes fournisseurs', [-3100, -2450]], ['Fonds propres', [24500, 22600]]] as $o => $pb) {
    $pid = $ins('postes_bilan', ['libelle' => $pb[0], 'ordre' => $o]);
    foreach ([$annee - 1, $annee] as $k => $an) {
        $pdo->prepare('INSERT INTO postes_bilan_valeurs (poste_id, annee, montant) VALUES (?, ?, ?)')
            ->execute([$pid, $an, $pb[1][$k]]);
    }
}

// ---------------------------------------------------------------------------
//  Pièces comptables : les cinq types et les trois états, avec un justificatif
//  réellement déposé (une image fabriquée ici) pour une pièce sur deux — sans
//  quoi tout le chemin « voir le justificatif » resterait sans objet.
// ---------------------------------------------------------------------------
$dossierFichiers = $racine . '/data/fichiers';
if (!$sansScan && !is_dir($dossierFichiers)) {
    mkdir($dossierFichiers, 0770, true);
}
// Un faux scan : une image lisible qui dit ce qu'elle est, pour qu'on ne la
// prenne jamais pour un vrai document.
$fabriquerScan = function (string $titre, string $tiers, float $montant, string $date) use ($dossierFichiers, $sansScan): ?array {
    if ($sansScan || !function_exists('imagecreatetruecolor')) {
        return null;
    }
    $img = imagecreatetruecolor(840, 1100);
    $blanc = imagecolorallocate($img, 252, 251, 247);
    $encre = imagecolorallocate($img, 40, 44, 52);
    $gris  = imagecolorallocate($img, 150, 150, 150);
    imagefill($img, 0, 0, $blanc);
    imagerectangle($img, 40, 40, 800, 1060, $gris);
    $lignes = [
        'DOCUMENT DE DEMONSTRATION',
        '',
        strtoupper(texte_sans_accents($titre)),
        'Tiers : ' . texte_sans_accents($tiers),
        'Montant : CHF ' . number_format($montant, 2, '.', "'"),
        'Date : ' . $date,
        '',
        'Aucune valeur. Donnees inventees.',
    ];
    foreach ($lignes as $i => $l) {
        imagestring($img, 5, 80, 120 + $i * 34, $l, $i === 0 ? $gris : $encre);
    }
    $nom = 'demo-piece-' . bin2hex(random_bytes(8)) . '.png';
    imagepng($img, $dossierFichiers . '/' . $nom);
    return ['fichier' => $nom, 'nom_origine' => 'scan.png', 'mime' => 'image/png',
            'taille' => (int) filesize($dossierFichiers . '/' . $nom)];
};
// Le même faux document, mais en PDF : c'est l'autre moitié de ce qu'un
// fournisseur envoie, et l'aperçu de ?p=piece ne l'affiche pas du tout de la
// même façon qu'une image (visionneuse du navigateur dans un cadre, contre une
// balise <img>). Une base de démonstration qui n'aurait que des images ne
// montrerait donc qu'un des deux chemins.
$fabriquerPdf = function (string $titre, string $tiers, float $montant, string $date) use ($racine, $dossierFichiers, $sansScan): ?array {
    if ($sansScan || !is_file($racine . '/vendor/autoload.php')) {
        return null;
    }
    require_once $racine . '/vendor/autoload.php';
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
    $pdf->SetCreator('Lasso — démonstration');
    $pdf->SetTitle($titre);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage();
    $pdf->SetFont('helvetica', 'B', 18);
    $pdf->Cell(0, 14, 'DOCUMENT DE DEMONSTRATION', 0, 1);
    $pdf->SetFont('helvetica', '', 12);
    foreach (['', texte_sans_accents($tiers), '', texte_sans_accents($titre),
              '', 'Montant : CHF ' . number_format($montant, 2, '.', "'"),
              'Date : ' . $date, '', 'Aucune valeur. Donnees inventees.'] as $l) {
        $pdf->Cell(0, 8, $l, 0, 1);
    }
    $nom = 'demo-piece-' . bin2hex(random_bytes(8)) . '.pdf';
    $pdf->Output($dossierFichiers . '/' . $nom, 'F');
    return ['fichier' => $nom, 'nom_origine' => 'justificatif.pdf', 'mime' => 'application/pdf',
            'taille' => (int) filesize($dossierFichiers . '/' . $nom)];
};

$piecesSrc = [
    // [type, libellé, tiers, montant, mois, état voulu]
    ['facture_recue', 'Location de la salle',          'Salle des Marronniers',   780.00,  1, 'classee'],
    ['facture_recue', 'Impression des affiches',       'Atelier Papier-Carbone',  432.50,  2, 'classee'],
    ['facture_recue', 'Assurance responsabilité',      'Fondation du Pont-Neuf',  615.00,  3, 'a_rapprocher'],
    ['facture_recue', 'Hébergement du site',           'Atelier Papier-Carbone',   95.00,  4, 'a_payer'],
    ['facture_recue', 'Location sono et lumières',     'Le Hangar 7',            1240.00,  5, 'a_payer'],
    ['facture_recue', 'Maintenance instruments',       'La Grange aux Luthiers',  380.00,  6, 'classee'],
    ['ticket',        'Cordes et accessoires',         'Atelier Papier-Carbone',   86.40,  2, 'classee'],
    ['ticket',        'Repas d\'équipe après concert', 'Café du Vieux-Pont',      214.00,  3, 'classee'],
    ['ticket',        'Carburant — tournée',           'Scène du Marais',         132.75,  4, 'a_rapprocher'],
    ['ticket',        'Fournitures de bureau',         'Atelier Papier-Carbone',   47.90,  5, 'classee'],
    ['remboursement', 'Billets de train (avance)',     '',                        168.00,  3, 'a_payer'],
    ['remboursement', 'Achat de cordes (avance)',      '',                         59.80,  4, 'classee'],
    ['remboursement', 'Péages et parking',             '',                         92.50,  6, 'a_rapprocher'],
    ['quittance',     'Don en espèces',                '',                        500.00,  2, 'classee'],
    ['quittance',     'Participation aux frais',       '',                        250.00,  5, 'classee'],
    ['autre',         'Contrat de coproduction',       'Théâtre du Pré-Vert',    3200.00,  1, 'a_payer'],
    ['autre',         'Attestation de subvention',     'Commune de Pré-Fleuri',  6000.00,  2, 'classee'],
];
$structuresParNom = [];
foreach ($pdo->query('SELECT id, nom FROM structures') as $r) {
    $structuresParNom[$r['nom']] = (int) $r['id'];
}
$ecrituresLibres = array_values(array_filter($ecritures, fn ($e) => $e['sens'] === 'charge'));
$nPiece = 0;
$pdo->beginTransaction();
foreach ($piecesSrc as $i => $ps) {
    [$type, $libelle, $tiers, $montant, $mois, $etat] = $ps;
    $datePiece = sprintf('%04d-%02d-%02d', $annee, $mois, mt_rand(2, 26));
    $meta = PIECE_TYPES[$type];
    // Une dépense avancée (remboursement) et une quittance désignent QUI :
    // une personne du carnet, ou une structure.
    $personneId = in_array($type, ['remboursement', 'quittance'], true) ? $personnes[($i * 3) % count($personnes)] : null;
    $structureId = $tiers !== '' ? ($structuresParNom[$tiers] ?? null) : null;
    $data = [
        'type'       => $type,
        'libelle'    => $libelle,
        'tiers'      => $tiers,
        'montant'    => $montant,
        'date_piece' => $datePiece,
        'date_echeance' => $meta['echeance'] ? date('Y-m-d', strtotime($datePiece . ' +30 days')) : '',
        'date_payee' => $etat === 'a_payer' ? '' : date('Y-m-d', strtotime($datePiece . ' +' . mt_rand(1, 20) . ' days')),
        'mode_paiement' => $etat === 'a_payer' ? '' : ($etat === 'classee' && $i % 5 === 0 ? 'hors_compte' : 'compte'),
        'plan_compte_id' => $meta['sens'] === 'recette' ? $plan['Dons'] : $plan[$choix(['Matériel', 'Déplacements', 'Autres charges', 'Locations de matériel'])],
        'notes'      => '',
        'structure_id' => $structureId,
        'personne_id'  => $personneId,
        'cree_le'      => $datePiece . ' 09:' . sprintf('%02d', mt_rand(0, 59)) . ':00',
    ];
    if ($type === 'quittance') {
        $data['origine'] = 'emise';
        $data['numero']  = quittance_prochain_numero($annee);
    }
    // Une pièce sur deux porte un justificatif, et une sur quatre l'a en PDF :
    // de quoi rencontrer les trois cas de l'écran — une image, un PDF, rien.
    if ($i % 2 === 0) {
        $quoi = $tiers !== '' ? $tiers : 'personne du carnet';
        $scan = $i % 4 === 0
            ? $fabriquerPdf($libelle, $quoi, $montant, $datePiece)
            : $fabriquerScan($libelle, $quoi, $montant, $datePiece);
        if ($scan) {
            $data += $scan;
        }
    }
    $pid = $ins('pieces', $data);
    // « Classée » = rapprochée d'une écriture (ou payée hors du compte suivi).
    if ($etat === 'classee' && $data['mode_paiement'] === 'compte' && $ecrituresLibres) {
        $e = array_shift($ecrituresLibres);
        $pdo->prepare('UPDATE pieces SET ecriture_id = ? WHERE id = ?')->execute([$e['id'], $pid]);
    }
    $nPiece++;
}
$pdo->commit();

// ---------------------------------------------------------------------------
//  Facturation : des factures émises à des organisateurs, dans les quatre
//  statuts. Le numéro et la référence passent par les fonctions du module, pour
//  que la série annuelle et la référence SCOR soient de vraies valeurs.
// ---------------------------------------------------------------------------
$prestations = [
    ['Concert — Carnets de voyage',  1,  2400.00],
    ['Concert — Petite Fanfare',     1,  1800.00],
    ['Atelier scolaire (demi-jour)', 3,   420.00],
    ['Médiation — rencontre public', 1,   350.00],
    ['Défraiement déplacement',      1,   180.00],
    ['Résidence — semaine',          1,  3200.00],
];
$organisateurs = array_slice($structures, 0, 16);
$statutsFacture = ['payee', 'payee', 'emise', 'emise', 'brouillon', 'annulee'];
$factures = [];
$pdo->beginTransaction();
for ($i = 0; $i < 14; $i++) {
    $statut  = $statutsFacture[$i % count($statutsFacture)];
    $emission = sprintf('%04d-%02d-%02d', $annee, ($i % 10) + 1, mt_rand(3, 25));
    $numero   = $statut === 'brouillon' ? '' : facturation_prochain_numero($pdo, $annee);
    $lignes   = [];
    $total    = 0.0;
    foreach (array_slice($prestations, $i % 3, mt_rand(1, 3)) as $o => $pr) {
        $montant = $pr[1] * $pr[2];
        $total  += $montant;
        $lignes[] = [$pr[0], $pr[1], $pr[2], $montant, $o];
    }
    $fid = $ins('factures', [
        'structure_id'       => $organisateurs[$i % count($organisateurs)],
        'compte_bancaire_id' => $comptesBancaires['courant'],
        'numero'             => $numero,
        'reference_paiement' => $numero !== '' ? facturation_generer_reference($numero) : '',
        'date_emission'      => $emission,
        'delai_jours'        => 30,
        'date_echeance'      => facturation_date_echeance($emission, 30),
        'statut'             => $statut,
        'montant_total'      => $total,
        'communication'      => 'Merci de votre soutien.',
        'envoyee_le'         => in_array($statut, ['emise', 'payee'], true) ? $emission : '',
        'payee_le'           => $statut === 'payee' ? date('Y-m-d', strtotime($emission . ' +' . mt_rand(5, 40) . ' days')) : '',
        'cree_le'            => $emission . ' 10:00:00',
    ]);
    foreach ($lignes as [$desc, $qte, $pu, $montant, $o]) {
        $ins('facture_lignes', ['facture_id' => $fid, 'description' => $desc, 'quantite' => $qte,
            'prix_unitaire' => $pu, 'montant' => $montant, 'ordre' => $o,
            'axe_analytique_id' => $axes[$choix(['CDV', 'PF', 'RE'])]]);
    }
    $factures[] = $fid;
}
$pdo->commit();

// ---------------------------------------------------------------------------
//  Événements : la saison, avec son organisateur, ses employés et sa feuille
//  de route. Quelques-uns sont passés, d'autres à venir.
// ---------------------------------------------------------------------------
$typesFeuille = ['transport', 'hebergement', 'contact', 'horaire', 'remarque'];
$evenements = [];
$pdo->beginTransaction();
for ($i = 0; $i < 26; $i++) {
    $sIdx = $i % 16;
    [$nomS, $rueS, $npaS, $locS, $paysS, $deptS] = $structuresSrc[$sIdx];
    $date = date('Y-m-d', strtotime(($annee) . '-01-15 +' . ($i * 17) . ' days'));
    $passe = $date < date('Y-m-d');
    $evId = $ins('evenements', [
        'projet_id'   => $projets[$i % 3],
        'date'        => $date,
        'heure_debut' => $choix(['19:00', '20:00', '20:30', '18:30']),
        'heure_fin'   => '23:00',
        'statut'      => $passe ? 'confirme' : $choix(['confirme', 'confirme', 'option', 'annule']),
        'visibilite'  => $choix(['public', 'public', 'public', 'prive']),
        'ville'       => $locS,
        'salle'       => $nomS,
        'adresse_rue' => $rueS,
        'adresse_npa' => $npaS,
        'pays'        => $paysS === 'Suisse' ? 'CH' : 'FR',
        'departement_canton' => $deptS,
        'grande_region' => grande_region_deduite($paysS, $deptS, false) ?? '',
        'organisateur_structure_id' => $structures[$sIdx],
        'suisa_applicable' => 1,
        'suisa_envoye_a'   => $passe && $i % 3 === 0 ? 'suisa' : '',
        'suisa_envoye_le'  => $passe && $i % 3 === 0 ? date('Y-m-d', strtotime($date . ' +14 days')) : '',
        'remarques'   => $i % 4 === 0 ? 'Balances à 16 h. Repas fourni.' : '',
        'lien_infos'  => $i % 5 === 0 ? 'https://exemple.test/concert-' . $i : '',
        'lien_texte'  => $i % 5 === 0 ? 'Page de l\'organisateur' : '',
    ]);
    $evenements[] = $evId;
    $ins('evenement_structures', ['evenement_id' => $evId, 'structure_id' => $structures[$sIdx], 'est_facturation' => 1]);
    foreach (array_slice($employes, 0, mt_rand(2, 4)) as $emp) {
        $pdo->prepare('INSERT OR IGNORE INTO evenement_employes (evenement_id, employe_id) VALUES (?, ?)')
            ->execute([$evId, $emp['id']]);
    }
    if ($i % 3 === 0) {
        foreach ([['horaire', 'Balances', '16:00', '17:30'], ['transport', 'Train aller', '13:12', '15:48'],
                  ['hebergement', 'Chambres réservées', '', '']] as $o => $fl) {
            $ins('evenement_feuille', ['evenement_id' => $evId, 'type' => $fl[0], 'libelle' => $fl[1],
                'debut' => $fl[2], 'fin' => $fl[3], 'ordre' => $o,
                'adresse' => $fl[0] === 'hebergement' ? 'Auberge du Chêne-Creux, ' . $npaS . ' ' . $locS : '']);
        }
        $ins('evenement_feuille', ['evenement_id' => $evId, 'type' => 'contact', 'ordre' => 9,
            'prenom' => $prenoms[$i % 30], 'nom' => $noms[$i % 30],
            'telephone' => '079 000 00 ' . sprintf('%02d', $i),
            'email' => 'regie@example.test', 'structure_id' => $structures[$sIdx]]);
    }
}
$pdo->commit();

// ---------------------------------------------------------------------------
//  Booking : deux campagnes de démarchage, leurs structures et leurs réponses,
//  plus l'historique des prises de contact.
// ---------------------------------------------------------------------------
$campagnes = [];
foreach ([['Saison ' . $annee . '–' . ($annee + 1) . ' — Romandie', ($annee) . '-02-01', ($annee) . '-06-30', [0, 1]],
          ['Tournée France voisine', ($annee) . '-04-01', ($annee) . '-11-30', [2]]] as $ci => $c) {
    $cid = $ins('campagnes', ['nom' => $c[0], 'date_debut' => $c[1], 'date_fin' => $c[2],
        'cree_le' => $c[1] . ' 08:00:00']);
    $campagnes[] = $cid;
    foreach ($c[3] as $pIdx) {
        $pdo->prepare('INSERT OR IGNORE INTO campagne_projets (campagne_id, projet_id) VALUES (?, ?)')
            ->execute([$cid, $projets[$pIdx]]);
    }
    foreach (array_slice($structures, $ci * 3, 12) as $k => $sid) {
        // Les quatre états du suivi : à contacter (rien), sans réponse
        // (contactée mais muette), pas intéressé, intéressé.
        $reponse = match ($k % 4) {
            0 => '',
            1 => '',
            2 => 'pas_interesse',
            3 => 'interesse',
        };
        $pdo->prepare('INSERT OR IGNORE INTO campagne_structures (campagne_id, structure_id, reponse) VALUES (?, ?, ?)')
            ->execute([$cid, $sid, $reponse]);
        if ($k % 4 !== 0) {
            journaliser('structure', $sid, 'mailing', 'Présentation de la saison envoyée.',
                gmdate('Y-m-d H:i:s', strtotime($c[1] . ' +' . ($k * 3) . ' days')));
        }
    }
}

// Notes et historique libres : ce qu'on écrit dans une fiche de structure.
$notes = [
    'Rappeler en septembre, la programmation se boucle en octobre.',
    'Très bon accueil, scène petite mais bien équipée.',
    'Demande un dossier complet avec fiche technique.',
    'Budget serré cette saison, revoir l\'an prochain.',
    'Contact changé : voir la nouvelle personne à la programmation.',
];
foreach (array_slice($structures, 0, 14) as $i => $sid) {
    $ins('structure_notes', ['structure_id' => $sid, 'contenu' => $notes[$i % count($notes)],
        'utilisateur_id' => $utilisateurs[0],
        'cree_le' => gmdate('Y-m-d H:i:s', strtotime('-' . mt_rand(10, 300) . ' days'))]);
    if ($i % 3 === 0) {
        journaliser('structure', $sid, 'dernier_concert', 'Concert joué dans cette salle.',
            gmdate('Y-m-d H:i:s', strtotime('-' . mt_rand(60, 500) . ' days')));
        $pdo->prepare('UPDATE structures SET dernier_concert_le = ? WHERE id = ?')
            ->execute([date('Y-m-d', strtotime('-' . mt_rand(60, 500) . ' days')), $sid]);
    }
}

// ---------------------------------------------------------------------------
//  Recherche de fonds : une campagne de demandes, ses bailleurs, ses réponses
//  et les versements déjà reçus.
// ---------------------------------------------------------------------------
$bailleurs = array_slice($structures, 18);
$fondsCampagnes = [];
foreach ([['Soutiens ' . $annee . ' — Carnets de voyage', $axes['CDV'], $projets[0], 18000, 32000, ($annee - 1) . '-09-01', $annee . '-12-31'],
          ['Création ' . ($annee + 1) . ' — Résidence Écho', $axes['RE'], $projets[2], 25000, 48000, $annee . '-03-01', ($annee + 1) . '-06-30']] as $fc) {
    $cid = $ins('fonds_campagnes', [
        'nom' => $fc[0], 'date_debut' => $fc[5], 'date_fin' => $fc[6],
        'axe_analytique_id' => $fc[1], 'montant_minimal' => $fc[3], 'montant_ideal' => $fc[4],
        'notes' => 'Campagne de démonstration.',
    ]);
    $pdo->prepare('INSERT OR IGNORE INTO fonds_campagne_projets (campagne_id, projet_id) VALUES (?, ?)')
        ->execute([$cid, $fc[2]]);
    $fondsCampagnes[] = $cid;
}

// Les statuts d'une demande racontent toute la vie d'un dossier : à préparer,
// déposé, accordé (ou en partie), refusé, soldé, bilan à rendre. La base de
// démonstration les porte tous, sinon la moitié des écrans ne se voit jamais.
$statutsFonds = ['accordee', 'partielle', 'refusee', 'deposee', 'a_preparer', 'soldee', 'bilan_a_rendre', 'en_retard'];
$rang = 0;
foreach ($fondsCampagnes as $ci => $fondsCampagne) {
    foreach ($bailleurs as $i => $sid) {
        $statut  = $statutsFonds[$rang % count($statutsFonds)];
        $rang++;
        $demande = [4000, 6000, 9000, 12000][($i + $ci) % 4];
        $accorde = in_array($statut, ['accordee', 'soldee', 'bilan_a_rendre'], true) ? $demande
                 : ($statut === 'partielle' ? round($demande * 0.6) : 0);
        $did = $ins('fonds_demandes', [
            'campagne_id'    => $fondsCampagne,
            'structure_id'   => $sid,
            'statut'         => $statut,
            'montant_demande' => $demande,
            'montant_accorde' => $accorde,
            'date_limite'    => sprintf('%04d-%02d-15', $annee, 3 + $ci),
            'date_depot'     => in_array($statut, ['a_preparer', 'en_retard'], true) ? '' : sprintf('%04d-%02d-08', $annee, 2 + $ci),
            'date_reponse'   => $accorde > 0 || $statut === 'refusee' ? sprintf('%04d-%02d-20', $annee, 4 + $ci) : '',
            'date_limite_bilan' => $accorde > 0 ? ($annee + 1) . '-03-31' : '',
            'notes'          => $statut === 'refusee' ? 'Dossier à retravailler pour l\'an prochain.' : '',
        ]);
        if ($accorde > 0) {
            $ins('fonds_versements', ['demande_id' => $did, 'montant' => $accorde,
                'date_prevue' => sprintf('%04d-%02d-30', $annee, 5 + $ci),
                'date_recue'  => $statut === 'soldee' ? sprintf('%04d-%02d-28', $annee, 6 + $ci) : '']);
        }
    }
}

// ---------------------------------------------------------------------------
//  Envois groupés : un expéditeur, deux modèles, quelques désinscriptions.
// ---------------------------------------------------------------------------
$expediteur = $ins('mailing_expediteurs', ['nom' => 'Association Grand-Vent',
    'email' => 'booking@grand-vent.test', 'ordre' => 0]);
foreach ([['Présentation de saison', 'La nouvelle création de Grand-Vent',
           "Bonjour,\n\nNous vous présentons notre création de la saison.\n\nBien à vous,\nL'équipe de Grand-Vent"],
          ['Relance programmation', 'Une date pour la saison prochaine ?',
           "Bonjour,\n\nNous revenons vers vous au sujet de notre proposition.\n\nBelle journée,\nL'équipe"]] as $m) {
    $mid = $ins('mailing_modeles', ['nom' => $m[0], 'sujet' => $m[1], 'corps' => $m[2],
        'expediteur_id' => $expediteur]);
    $pdo->prepare('INSERT OR IGNORE INTO mailing_modele_projets (modele_id, projet_id) VALUES (?, ?)')
        ->execute([$mid, $projets[0]]);
}
foreach ([24, 27] as $rang) {
    $em = $pdo->query('SELECT email FROM personnes WHERE id = ' . (int) $personnes[$rang])->fetchColumn();
    $ins('mailing_exclusions', ['email' => (string) $em]);
    $pdo->prepare('UPDATE personnes SET desinscrit = 1 WHERE id = ?')->execute([$personnes[$rang]]);
}
$ins('mailing_ciblages', ['nom' => 'Salles romandes, petite jauge',
    'criteres' => json_encode(['statut' => ['actif', 'contact_privilegie'], 'pays' => ['Suisse']])]);

// ---------------------------------------------------------------------------
//  Le compte rendu : ce qui a été fabriqué, et comment s'en servir.
// ---------------------------------------------------------------------------
$compte = fn (string $t) => (int) $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
echo "\nBase de démonstration écrite : $chemin\n";
if (!$sansScan) {
    echo "Justificatifs fabriqués dans : $dossierFichiers (préfixe « demo-piece- »)\n";
}
echo str_repeat('-', 64), "\n";
// mb_str_pad : « employés » et « événements » comptent plus d'octets que de
// caractères, et printf aligne sur les octets.
$ligne = function (string $a, int $na, string $b, int $nb): void {
    printf("  %s %4d    %s %4d\n",
        mb_str_pad($a, 20, ' '), $na, mb_str_pad($b, 20, ' '), $nb);
};
$ligne('personnes', $compte('personnes'), 'structures', $compte('structures'));
$ligne('employés', $compte('employes'), 'fiches de salaire', $compte('fiches'));
$ligne('écritures', $compte('ecritures'), 'pièces', $compte('pieces'));
$ligne('factures', $compte('factures'), 'événements', $compte('evenements'));
$ligne('campagnes booking', $compte('campagnes'), 'demandes de fonds', $compte('fonds_demandes'));
echo str_repeat('-', 64), "\n";
echo "Comptes (mot de passe : " . DEMO_MDP . ")\n";
foreach ($comptes as [$email]) {
    echo "  $email\n";
}
echo "\nPour y brancher l'application, dans lib/config.local.php :\n";
echo "  define('APP_DB_PATH', '" . $chemin . "');\n";
echo "puis commenter cette ligne pour revenir à la vraie base.\n";
