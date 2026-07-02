<?php
// Module facturation : fonctions pures (numérotation, totaux, référence de
// paiement SCOR, statut dérivé) + génération de la facture PDF (QR-facture
// suisse). S'appuie sur la librairie vendorisée sprain/swiss-qr-bill +
// tecnickcom/tcpdf (exception actée à la règle « zéro dépendance », voir
// SPEC_FACTURATION.md §6 — Composer utilisé uniquement en local, vendor/
// commité, aucune commande Composer nécessaire en production).

const FACTURATION_STATUTS = ['brouillon', 'emise', 'payee', 'annulee'];

// Montant d'une ligne (quantité × prix unitaire), arrondi à 2 décimales.
function facturation_calc_ligne(float $quantite, float $prixUnitaire): float
{
    return r2($quantite * $prixUnitaire);
}

// Total d'une facture = somme des montants de lignes (déjà arrondis).
function facturation_calc_total(array $lignes): float
{
    $total = 0.0;
    foreach ($lignes as $l) {
        $total += (float) ($l['montant'] ?? 0);
    }
    return r2($total);
}

// Enregistre un brouillon (création si $id vide, modification sinon) : facture
// + lignes, dans une transaction. $id doit déjà être vérifié statut='brouillon'
// par l'appelant (une facture émise ne se modifie plus). Retourne l'id de la facture.
function facturation_sauvegarder_brouillon(
    ?int $id, int $debiteurId, ?int $compteId, int $delaiJours, string $communication, array $lignes
): int {
    $montantTotal = facturation_calc_total($lignes);

    db()->beginTransaction();
    if ($id) {
        db()->prepare("UPDATE factures SET debiteur_id=?, compte_bancaire_id=?, delai_jours=?, communication=?, montant_total=? WHERE id=? AND statut='brouillon'")
            ->execute([$debiteurId, $compteId, $delaiJours, $communication, $montantTotal, $id]);
        db()->prepare('DELETE FROM facture_lignes WHERE facture_id = ?')->execute([$id]);
        $factureId = $id;
    } else {
        db()->prepare("INSERT INTO factures (debiteur_id, compte_bancaire_id, delai_jours, communication, montant_total, statut)
                        VALUES (?, ?, ?, ?, ?, 'brouillon')")
            ->execute([$debiteurId, $compteId, $delaiJours, $communication, $montantTotal]);
        $factureId = (int) db()->lastInsertId();
    }
    $insL = db()->prepare('INSERT INTO facture_lignes (facture_id, description, quantite, prix_unitaire, montant, axe_analytique_id, ordre) VALUES (?,?,?,?,?,?,?)');
    foreach ($lignes as $ordre => $l) {
        $insL->execute([$factureId, $l['description'], $l['quantite'], $l['prix_unitaire'], $l['montant'], $l['axe_analytique_id'], $ordre]);
    }
    db()->commit();
    return $factureId;
}

// Prochain numéro de facture pour une année civile donnée, format « AAAA-NNN »
// (ex. 2026-001), séquentiel sans trou dans la séquence.
// MAX() numérique (CAST) plutôt qu'un tri lexicographique sur le numéro complet :
// un tri texte classerait "2026-999" après "2026-1000" (le caractère '1' < '9'),
// donnant le mauvais dernier numéro dès la 1000e facture de l'année.
function facturation_prochain_numero(PDO $pdo, int $annee): string
{
    $prefixe = $annee . '-';
    $stmt = $pdo->prepare('SELECT MAX(CAST(substr(numero, length(?) + 1) AS INTEGER)) FROM factures WHERE numero LIKE ?');
    $stmt->execute([$prefixe, $prefixe . '%']);
    $dernier = (int) $stmt->fetchColumn();
    return $prefixe . sprintf('%03d', $dernier + 1);
}

// Référence structurée SCOR (ISO 11649, préfixe RF + somme de contrôle mod97),
// dérivée du numéro de facture (seuls les caractères alphanumériques comptent).
function facturation_generer_reference(string $numero): string
{
    require_once __DIR__ . '/../vendor/autoload.php';
    $brut = preg_replace('/[^A-Za-z0-9]/', '', $numero);
    return \Sprain\SwissQrBill\Reference\RfCreditorReferenceGenerator::generate((string) $brut);
}

// Date d'échéance = date d'émission + délai (jours).
function facturation_date_echeance(string $dateEmission, int $delaiJours): string
{
    $d = new DateTimeImmutable($dateEmission);
    return $d->modify("+{$delaiJours} days")->format('Y-m-d');
}

// Montant CHF pour le PDF (police core Helvetica de TCPDF : pas d'espace fine
// insécable comme chf(), qui s'affiche en glyphe manquant dans cette police).
function facturation_chf_pdf(float $v): string
{
    return number_format($v, 2, '.', ' ');
}

// Statut effectif d'une facture : « en_retard » est dérivé (jamais stocké),
// dès que le statut est « emise » et que l'échéance est dépassée.
function facturation_statut_effectif(array $facture): string
{
    $statut = (string) $facture['statut'];
    if ($statut === 'emise') {
        $echeance = (string) ($facture['date_echeance'] ?? '');
        if ($echeance !== '' && $echeance < date('Y-m-d')) {
            return 'en_retard';
        }
    }
    return $statut;
}

// Prédicat SQL « en retard », même règle que facturation_statut_effectif()
// (à tenir synchronisées si la définition change, ex. délai de grâce) — pour
// filtrer côté base plutôt que de recharger toutes les factures en PHP.
// $prefixe : alias de table éventuel (ex. "f." si la requête utilise "FROM factures f").
// Le paramètre attendu (date du jour, "Y-m-d") doit être lié par l'appelant.
function facturation_sql_en_retard(string $prefixe = ''): string
{
    return "{$prefixe}statut = 'emise' AND {$prefixe}date_echeance <> '' AND {$prefixe}date_echeance < ?";
}

// Nombre de factures émises en retard de paiement (pour le badge de menu).
function nb_factures_en_retard(): int
{
    try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM factures WHERE ' . facturation_sql_en_retard());
        $stmt->execute([date('Y-m-d')]);
        return (int) $stmt->fetchColumn();
    } catch (\Exception) {
        return 0;
    }
}

// Badge de statut affiché dans les listes (statut effectif, « en retard » inclus).
function facturation_badge(array $facture): string
{
    return match (facturation_statut_effectif($facture)) {
        'payee'     => '<span class="badge ok-badge">Payée</span>',
        'en_retard' => '<span class="badge warn-badge">En retard</span>',
        'annulee'   => '<span class="badge muted-badge">Annulée</span>',
        'emise'     => '<span class="badge emise-badge">Émise</span>',
        default     => '<span class="badge muted-badge">Brouillon</span>',
    };
}

// Code pays ISO 3166-1 alpha-2, requis par la QR-facture (StructuredAddress).
// Repli sur CH : la quasi-totalité des débiteurs de l'association sont suisses.
const FACTURATION_PAYS_ISO2 = [
    'suisse' => 'CH', 'schweiz' => 'CH', 'svizzera' => 'CH', 'switzerland' => 'CH',
    'france' => 'FR', 'allemagne' => 'DE', 'deutschland' => 'DE',
    'italie' => 'IT', 'italia' => 'IT', 'autriche' => 'AT', 'österreich' => 'AT',
    'liechtenstein' => 'LI',
];

function facturation_pays_iso2(string $pays): string
{
    $p = trim($pays);
    if (preg_match('/^[A-Za-z]{2}$/', $p)) {
        return strtoupper($p);
    }
    return FACTURATION_PAYS_ISO2[mb_strtolower($p, 'UTF-8')] ?? 'CH';
}

// --------------------------------------------------------------- QR-FACTURE
// Construit l'objet QrBill (sprain/swiss-qr-bill) à partir d'une facture, ses
// lignes, son débiteur et le compte bancaire créancier. Lève une exception si
// les données sont invalides (IBAN, adresse…) — appelant : capturer et afficher.
function facturation_construire_qrbill(array $facture, array $debiteur, array $compte): \Sprain\SwissQrBill\QrBill
{
    require_once __DIR__ . '/../vendor/autoload.php';

    $QrBill = \Sprain\SwissQrBill\QrBill::class;
    $qrBill = $QrBill::create();

    [$empNpa, $empVille] = split_npa((string) param('employeur_npa'));
    $qrBill->setCreditor(
        \Sprain\SwissQrBill\DataGroup\Element\StructuredAddress::createWithStreet(
            (string) param('employeur_nom'),
            (string) param('employeur_rue'),
            null,
            $empNpa,
            $empVille,
            facturation_pays_iso2((string) param('employeur_pays', 'Suisse'))
        )
    );
    $qrBill->setCreditorInformation(
        \Sprain\SwissQrBill\DataGroup\Element\CreditorInformation::create((string) $compte['iban'])
    );
    $qrBill->setUltimateDebtor(
        \Sprain\SwissQrBill\DataGroup\Element\StructuredAddress::createWithStreet(
            (string) $debiteur['nom'],
            (string) $debiteur['adresse_rue'],
            null,
            (string) $debiteur['adresse_npa'],
            (string) $debiteur['adresse_localite'],
            facturation_pays_iso2((string) $debiteur['adresse_pays'])
        )
    );
    $qrBill->setPaymentAmountInformation(
        \Sprain\SwissQrBill\DataGroup\Element\PaymentAmountInformation::create('CHF', (float) $facture['montant_total'])
    );
    $qrBill->setPaymentReference(
        \Sprain\SwissQrBill\DataGroup\Element\PaymentReference::create(
            \Sprain\SwissQrBill\DataGroup\Element\PaymentReference::TYPE_SCOR,
            (string) $facture['reference_paiement']
        )
    );
    $qrBill->setAdditionalInformation(
        \Sprain\SwissQrBill\DataGroup\Element\AdditionalInformation::create('Facture ' . $facture['numero'])
    );

    return $qrBill;
}

// En-tête de la facture PDF : logo/employeur, titre + dates, débiteur, communication.
function facturation_pdf_entete(TCPDF $pdf, array $facture, array $debiteur): void
{
    $logo = param_logo('clair');
    $logoAffiche = false;
    if ($logo !== '') {
        $logoPath = realpath(__DIR__ . '/../' . $logo) ?: (__DIR__ . '/../' . $logo);
        if (is_file($logoPath) && is_readable($logoPath)) {
            $pdf->Image($logoPath, 15, 15, 0, 18);
            $logoAffiche = true;
        } else {
            // Logo référencé en base mais illisible depuis le PHP-CLI/FPM (droits,
            // open_basedir…) — la vue HTML l'affiche via une requête HTTP du
            // navigateur, ce qui ne passe pas par les mêmes restrictions serveur.
            error_log("[facturation] logo PDF introuvable ou illisible : $logoPath");
        }
    }
    $pdf->SetY($logoAffiche ? 15 + 18 + 4 : 15);

    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 8, (string) param('employeur_nom'), 0, 1);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 5, (string) param('employeur_rue'), 0, 1);
    $pdf->Cell(0, 5, (string) param('employeur_npa'), 0, 1);
    $pdf->Ln(8);

    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 8, 'Facture ' . $facture['numero'], 0, 1);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 5, "Date d'émission : " . date('d.m.Y', strtotime((string) $facture['date_emission'])), 0, 1);
    $pdf->Cell(0, 5, "Échéance : " . date('d.m.Y', strtotime((string) $facture['date_echeance'])), 0, 1);
    $pdf->Ln(6);

    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 5, (string) $debiteur['nom'], 0, 1);
    $pdf->SetFont('helvetica', '', 10);
    if (trim((string) $debiteur['adresse_rue']) !== '') {
        $pdf->Cell(0, 5, (string) $debiteur['adresse_rue'], 0, 1);
    }
    $pdf->Cell(0, 5, trim($debiteur['adresse_npa'] . ' ' . $debiteur['adresse_localite']), 0, 1);
    $pdf->Ln(8);

    if (trim((string) ($facture['communication'] ?? '')) !== '') {
        $pdf->SetFont('helvetica', 'I', 10);
        $pdf->MultiCell(0, 5, (string) $facture['communication'], 0, 'L');
        $pdf->Ln(4);
    }
}

// Tableau des lignes + total. Saute de page si une ligne dépasse la zone
// réservée à la QR-facture (105 derniers mm d'une page A4).
function facturation_pdf_lignes(TCPDF $pdf, array $lignes, array $facture): void
{
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell(95, 7, 'Description', 1, 0, 'L', true);
    $pdf->Cell(20, 7, 'Qté', 1, 0, 'R', true);
    $pdf->Cell(30, 7, 'Prix unit.', 1, 0, 'R', true);
    $pdf->Cell(35, 7, 'Montant', 1, 1, 'R', true);
    $pdf->SetFont('helvetica', '', 9);
    foreach ($lignes as $l) {
        $desc  = (string) $l['description'];
        $rowH  = max(6, $pdf->getStringHeight(95, $desc));
        if ($pdf->GetY() + $rowH > 175) {
            $pdf->AddPage();
        }
        $x = $pdf->GetX();
        $y = $pdf->GetY();
        $pdf->MultiCell(95, $rowH, $desc, 1, 'L', false, 0, $x, $y);
        $pdf->SetXY($x + 95, $y);
        $pdf->Cell(20, $rowH, nombre_court((float) $l['quantite']), 1, 0, 'R');
        $pdf->Cell(30, $rowH, facturation_chf_pdf((float) $l['prix_unitaire']), 1, 0, 'R');
        $pdf->Cell(35, $rowH, facturation_chf_pdf((float) $l['montant']), 1, 1, 'R');
        $pdf->SetXY($x, $y + $rowH);
    }
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(145, 7, 'Total (CHF)', 1, 0, 'R', true);
    $pdf->Cell(35, 7, facturation_chf_pdf((float) $facture['montant_total']), 1, 1, 'R', true);
}

// Génère le PDF complet de la facture (contenu + zone de paiement QR normée),
// en octets. $facture doit contenir montant_total/numero/reference_paiement
// figés ; $lignes triées par ordre ; $compte = ligne comptes_bancaires (IBAN).
function facturation_generer_pdf(array $facture, array $lignes, array $debiteur, array $compte): string
{
    require_once __DIR__ . '/../vendor/autoload.php';

    $qrBill = facturation_construire_qrbill($facture, $debiteur, $compte);
    if (!$qrBill->isValid()) {
        $messages = array_map(fn($v) => $v->getMessage(), iterator_to_array($qrBill->getViolations()));
        throw new RuntimeException("QR-facture invalide : " . implode(' ; ', $messages));
    }

    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();

    facturation_pdf_entete($pdf, $facture, $debiteur);
    facturation_pdf_lignes($pdf, $lignes, $facture);

    // La zone de paiement QR doit occuper les 105 derniers mm d'une page A4 :
    // nouvelle page si le contenu déborde de la zone réservée.
    if ($pdf->GetY() > 180) {
        $pdf->AddPage();
    }

    $output = new \Sprain\SwissQrBill\PaymentPart\Output\TcPdfOutput\TcPdfOutput($qrBill, 'fr', $pdf);
    $displayOptions = new \Sprain\SwissQrBill\PaymentPart\Output\DisplayOptions();
    $displayOptions->setPrintable(true);
    $output->setDisplayOptions($displayOptions)->getPaymentPart();

    return (string) $pdf->Output('', 'S');
}

// --------------------------------------------------------------- E-MAIL
// Construit les en-têtes MIME + corps d'un e-mail multipart avec pièce jointe
// PDF (facture), réutilisable par SMTP authentifié et par mail().
function facturation_email_parts(string $expediteur, string $html, string $pdfContenu, string $nomFichier): array
{
    $boundary = 'lasso_' . bin2hex(random_bytes(8));
    $entetesMime = implode("\r\n", [
        'MIME-Version: 1.0',
        'From: ' . $expediteur,
        'Reply-To: ' . $expediteur,
        'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
    ]);
    $corps = "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n$html\r\n"
        . "--$boundary\r\nContent-Type: application/pdf; name=\"$nomFichier\"\r\n"
        . "Content-Transfer-Encoding: base64\r\n"
        . "Content-Disposition: attachment; filename=\"$nomFichier\"\r\n\r\n"
        . chunk_split(base64_encode($pdfContenu))
        . "--$boundary--\r\n";
    return [$entetesMime, $corps];
}

// Envoie une facture par e-mail (PDF en pièce jointe). Transport commun avec
// les fiches de salaire (envoyer_email(), lib/helpers.php). Retourne [succès, mode].
function envoyer_facture_email(array $facture, string $pdfContenu, string $destinataire, string $expediteur): array
{
    $sujet = 'Facture ' . $facture['numero'];
    $sujetEnc = '=?UTF-8?B?' . base64_encode($sujet) . '?=';
    $html = '<p>Bonjour,</p><p>Veuillez trouver ci-joint la facture ' . e((string) $facture['numero'])
        . ', échéance au ' . e(date('d.m.Y', strtotime((string) $facture['date_echeance']))) . '.</p>';
    $nomFichier = 'facture-' . $facture['numero'] . '.pdf';
    [$entetesMime, $corps] = facturation_email_parts($expediteur, $html, $pdfContenu, $nomFichier);
    $resume = $sujet . ' (PDF joint, ' . strlen($pdfContenu) . ' octets)';
    return envoyer_email($destinataire, $expediteur, $sujetEnc, $entetesMime, $corps, $resume);
}

// ------------------------------------------------------- RAPPROCHEMENT COMPTA
// Tente d'associer automatiquement les écritures bancaires d'UN IMPORT donné
// (pas tout l'historique du compte, qui ne fait que grossir au fil des années)
// à des factures émises non payées : montant exact + nom du débiteur retrouvé
// dans le texte/tiers de l'écriture. Ne réaffecte jamais une écriture déjà
// lettrée à une facture ni une facture déjà payée.
function facturation_suggerer_rapprochements(PDO $pdo, int $importId): int
{
    $sqlFactures = "SELECT f.*, d.nom AS debiteur_nom FROM factures f
                     JOIN debiteurs d ON d.id = f.debiteur_id
                     WHERE f.statut = 'emise'";
    $factures = $pdo->query($sqlFactures)->fetchAll();
    if (!$factures) {
        return 0;
    }

    $stmt = $pdo->prepare('SELECT * FROM ecritures WHERE import_id = ? AND facture_id IS NULL AND montant > 0');
    $stmt->execute([$importId]);
    $ecritures = $stmt->fetchAll();
    if (!$ecritures) {
        return 0;
    }

    $updEcr = $pdo->prepare('UPDATE ecritures SET facture_id = ? WHERE id = ?');
    $updFac = $pdo->prepare("UPDATE factures SET statut = 'payee', payee_le = ?, ecriture_id = ? WHERE id = ?");
    $utilisees = [];
    $n = 0;
    foreach ($factures as $f) {
        $montant = (float) $f['montant_total'];
        $nomDebiteur = mb_strtolower((string) $f['debiteur_nom'], 'UTF-8');
        foreach ($ecritures as $e) {
            $eid = (int) $e['id'];
            if (isset($utilisees[$eid]) || abs((float) $e['montant'] - $montant) > 0.01) {
                continue;
            }
            $texte = mb_strtolower((string) $e['texte'] . ' ' . $e['tiers'] . ' ' . $e['communication'], 'UTF-8');
            if ($nomDebiteur === '' || !str_contains($texte, $nomDebiteur)) {
                continue;
            }
            $updEcr->execute([(int) $f['id'], $eid]);
            $updFac->execute([(string) $e['date_op'], $eid, (int) $f['id']]);
            $utilisees[$eid] = true;
            $n++;
            break; // une écriture ne sert qu'une fois
        }
    }
    return $n;
}

// -------------------------------------------------------- IMPORT HISTORIQUE
// Importe des factures déjà émises avant l'utilisation de Lasso (JSON, format
// « factures_historique »). Débiteur retrouvé par nom exact, créé sinon (avec
// l'adresse/e-mail fournis). Statut/numéro/dates imposés directement (pas de
// passage par le brouillon → émission normal). Une facture dont le numéro
// existe déjà est ignorée — jamais écrasée (historique figé). $simule = true :
// n'écrit rien, retourne ce qui serait fait. Renvoie [résultats par ligne, résumé].
function importer_factures_historique(array $facturesData, bool $simule): array
{
    $findDeb = db()->prepare('SELECT id FROM debiteurs WHERE nom = ?');
    $existe  = db()->prepare('SELECT 1 FROM factures WHERE numero = ?');
    $resultats = [];
    $resume = ['total' => 0, 'nouvelles' => 0, 'existantes' => 0, 'erreurs' => 0];

    if (!$simule) {
        db()->beginTransaction();
    }
    try {
        foreach ($facturesData as $f) {
            $resume['total']++;
            $numero        = trim((string) ($f['numero'] ?? ''));
            $debiteurNom   = trim((string) ($f['debiteur_nom'] ?? ''));
            $dateEmission  = trim((string) ($f['date_emission'] ?? ''));
            $statutFacture = in_array($f['statut'] ?? '', ['emise', 'payee', 'annulee'], true) ? $f['statut'] : 'emise';

            $lignes = [];
            foreach ((array) ($f['lignes'] ?? []) as $l) {
                $qte  = (float) ($l['quantite'] ?? 0);
                $pu   = (float) ($l['prix_unitaire'] ?? 0);
                $desc = trim((string) ($l['description'] ?? ''));
                if ($desc === '' || $qte <= 0) {
                    continue;
                }
                $lignes[] = ['description' => $desc, 'quantite' => $qte, 'prix_unitaire' => $pu,
                    'montant' => facturation_calc_ligne($qte, $pu)];
            }
            $montantTotal = facturation_calc_total($lignes);

            $ligne = ['numero' => $numero, 'debiteur' => $debiteurNom, 'date_emission' => $dateEmission,
                'montant' => $montantTotal, 'statut_facture' => $statutFacture];

            if ($numero === '' || $debiteurNom === '' || $dateEmission === '' || !$lignes) {
                $ligne['statut'] = 'erreur';
                $ligne['detail'] = "Champs obligatoires manquants (numéro, débiteur, date d'émission, au moins une ligne valide).";
                $resume['erreurs']++; $resultats[] = $ligne; continue;
            }
            $existe->execute([$numero]);
            if ($existe->fetch()) {
                $ligne['statut'] = 'existante';
                $ligne['detail'] = 'Une facture avec ce numéro existe déjà — ignorée.';
                $resume['existantes']++; $resultats[] = $ligne; continue;
            }

            $ligne['statut'] = 'nouvelle';
            $resume['nouvelles']++;

            if (!$simule) {
                $findDeb->execute([$debiteurNom]);
                $debiteurId = $findDeb->fetchColumn();
                if ($debiteurId === false) {
                    db()->prepare('INSERT INTO debiteurs (type, nom, adresse_rue, adresse_npa, adresse_localite, adresse_pays, email, actif)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, 1)')
                        ->execute([
                            'organisation', $debiteurNom,
                            trim((string) ($f['debiteur_adresse_rue'] ?? '')),
                            trim((string) ($f['debiteur_adresse_npa'] ?? '')),
                            trim((string) ($f['debiteur_adresse_localite'] ?? '')),
                            trim((string) ($f['debiteur_adresse_pays'] ?? '')) ?: 'Suisse',
                            trim((string) ($f['debiteur_email'] ?? '')),
                        ]);
                    $debiteurId = (int) db()->lastInsertId();
                } else {
                    $debiteurId = (int) $debiteurId;
                }

                $compteId  = null;
                $compteRef = trim((string) ($f['compte_bancaire'] ?? ''));
                if ($compteRef !== '') {
                    $stmtC = db()->prepare('SELECT id FROM comptes_bancaires WHERE iban = ? OR libelle = ?');
                    $stmtC->execute([$compteRef, $compteRef]);
                    $found = $stmtC->fetchColumn();
                    if ($found !== false) {
                        $compteId = (int) $found;
                    }
                }

                $dateEcheance = trim((string) ($f['date_echeance'] ?? '')) ?: facturation_date_echeance($dateEmission, 30);
                $delaiJours   = max(1, (int) round((strtotime($dateEcheance) - strtotime($dateEmission)) / 86400));

                // Référence SCOR régénérée pour cohérence de l'affichage ; sans conséquence
                // sur le paiement déjà effectué dans le monde réel pour cet historique.
                $reference = '';
                try {
                    $reference = facturation_generer_reference($numero);
                } catch (Throwable $e) {
                    $reference = '';
                }
                $payeeLe = $statutFacture === 'payee' ? (trim((string) ($f['payee_le'] ?? '')) ?: $dateEmission) : '';
                $communication = trim((string) ($f['communication'] ?? ''));

                db()->prepare('INSERT INTO factures
                    (debiteur_id, compte_bancaire_id, numero, reference_paiement, date_emission, date_echeance,
                     delai_jours, statut, montant_total, communication, payee_le)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$debiteurId, $compteId, $numero, $reference, $dateEmission, $dateEcheance,
                        $delaiJours, $statutFacture, $montantTotal, $communication, $payeeLe]);
                $factureId = (int) db()->lastInsertId();

                $insL = db()->prepare('INSERT INTO facture_lignes (facture_id, description, quantite, prix_unitaire, montant, ordre) VALUES (?, ?, ?, ?, ?, ?)');
                foreach ($lignes as $ordre => $l) {
                    $insL->execute([$factureId, $l['description'], $l['quantite'], $l['prix_unitaire'], $l['montant'], $ordre]);
                }
            }
            $resultats[] = $ligne;
        }
        if (!$simule) {
            db()->commit();
        }
    } catch (Throwable $e) {
        if (!$simule && db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }
    return [$resultats, $resume];
}
