<?php
// Données de démonstration : 3 salariés + 5 fiches chacun (CLI uniquement).
// Lancement : php lib/seed_demo.php
// Idempotent : ne fait rien si des employés existent déjà.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/calc.php';
require_once __DIR__ . '/helpers.php';

$pdo = db();

if ((int) $pdo->query('SELECT COUNT(*) FROM employes')->fetchColumn() > 0) {
    fwrite(STDERR, "Des employés existent déjà — seed ignoré.\n");
    exit(0);
}

// --- Taux horaires prédéfinis ---
if ((int) $pdo->query('SELECT COUNT(*) FROM taux_horaires')->fetchColumn() === 0) {
    $th = $pdo->prepare('INSERT INTO taux_horaires (libelle, montant) VALUES (?, ?)');
    foreach ([['Standard', 30], ['Animation', 35], ['Direction', 45]] as $r) {
        $th->execute($r);
    }
}

// --- Employés ---
$employes = [
    ['Aïcha', 'Benali', 'Rue de Carouge 12', '1205 Genève', '756.1111.2222.33', 'Ordinaire', 0.0833, 0, 30],
    ['Marco', 'Rossi', 'Av. de la Praille 5', '1227 Carouge', '756.4444.5555.66', 'Ordinaire', 0.1064, 0, 35],
    ['Sophie', 'Meyer', 'Ch. des Tulipes 8', '1208 Genève', '756.7777.8888.99', 'Ordinaire avec impôt à la source', 0.0833, 0.09, 45],
];
$insEmp = $pdo->prepare(
    'INSERT INTO employes (prenom, nom, rue, npa_localite, numero_avs, canton, procedure, supplement_vacances, impot_source_taux, actif)
     VALUES (?, ?, ?, ?, ?, "Genève", ?, ?, ?, 1)'
);
$empIds = [];
$empTaux = [];
foreach ($employes as $e) {
    $insEmp->execute([$e[0], $e[1], $e[2], $e[3], $e[4], $e[5], $e[6], $e[7]]);
    $empIds[]  = (int) $pdo->lastInsertId();
    $empTaux[] = $e[8]; // taux horaire utilisé pour ses fiches
}

// --- 5 fiches par employé (janvier à mai 2026) ---
$annee  = 2026;
$heures = [62, 64, 72, 58, 80];
$taux2026 = taux_pour_annee($annee);

foreach ($empIds as $i => $empId) {
    $stmt = $pdo->prepare('SELECT * FROM employes WHERE id = ?');
    $stmt->execute([$empId]);
    $emp = $stmt->fetch();
    $emp['salaire_horaire'] = $empTaux[$i];

    for ($mois = 1; $mois <= 5; $mois++) {
        $h = $heures[($mois - 1 + $i) % 5];
        $c = calculer_fiche($emp, $h, $taux2026);
        $data = [
            'employe_id'      => $empId,
            'annee'           => $annee,
            'mois'            => $mois,
            'date_paiement'   => sprintf('%04d-%02d-25', $annee, $mois),
            'employe_nom'     => $emp['prenom'] . ' ' . $emp['nom'],
            'employe_rue'     => $emp['rue'],
            'employe_npa'     => $emp['npa_localite'],
            'employe_avs'     => $emp['numero_avs'],
            'canton'          => $emp['canton'],
            'procedure'       => $emp['procedure'],
            'salaire_horaire' => $emp['salaire_horaire'],
            'nombre_heures'   => $h,
            'supplement_taux' => $emp['supplement_vacances'],
            'taux_json'       => json_encode($taux2026 + ['impot_source' => (float) $emp['impot_source_taux']]),
        ] + $c;
        $cols  = implode(',', array_keys($data));
        $marks = ':' . implode(',:', array_keys($data));
        $pdo->prepare("INSERT INTO fiches ($cols) VALUES ($marks)")->execute($data);
    }
}

$n = (int) $pdo->query('SELECT COUNT(*) FROM fiches')->fetchColumn();
echo "Seed OK : " . count($empIds) . " employés, $n fiches.\n";
