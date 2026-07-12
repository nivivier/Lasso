<?php
// Logique du module de comptabilité (fonctions pures, sans état) :
// lecture des exports PostFinance, dédoublonnage, lettrage par règles,
// agrégation du compte de résultat. Aucune dépendance externe.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php'; // date_valide(), dom_el()

// Convertit un montant texte (« 1'234.55 », « -5 ») en float. Tolère les
// séparateurs de milliers suisses (apostrophe, espaces fines) ; décimale « . ».
function montant_float(string $s): float
{
    $s = str_replace(["'", ' ', "\u{202F}", "\u{00A0}"], '', trim($s));
    if ($s === '') {
        return 0.0;
    }
    return (float) $s;
}

// « 31.12.2025 » → « 2025-12-31 ». Renvoie '' si le format n'est pas reconnu.
function date_iso_pf(string $s): string
{
    $s = trim($s);
    if (!preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $s, $m)) {
        return '';
    }
    return $m[3] . '-' . $m[2] . '-' . $m[1];
}

// Normalise un texte pour comparaison : minuscules + accents retirés + espaces
// compactés. Utilisée pour le matching des règles (insensible casse/accents).
function normaliser_texte(string $s): string
{
    $accents = [
        'à','á','â','ã','ä','å','ç','è','é','ê','ë','ì','í','î','ï',
        'ñ','ò','ó','ô','õ','ö','ù','ú','û','ü','ý','ÿ',
    ];
    $sans = [
        'a','a','a','a','a','a','c','e','e','e','e','i','i','i','i',
        'n','o','o','o','o','o','u','u','u','u','y','y',
    ];
    $s = mb_strtolower($s, 'UTF-8');
    $s = str_replace($accents, $sans, $s);
    return trim(preg_replace('/\s+/', ' ', $s) ?? '');
}

// Lit le contenu brut d'un export de mouvements PostFinance.
// Renvoie ['iban','monnaie','date_debut','date_fin','lignes'=>[
//   ['date_op'=>'Y-m-d','texte'=>…,'montant'=>float,'solde'=>?float], …]].
function parse_postfinance_csv(string $contenu): array
{
    // BOM éventuel + normalisation des fins de ligne.
    $contenu = preg_replace('/^\xEF\xBB\xBF/', '', $contenu);
    $lignesBrutes = preg_split('/\r\n|\r|\n/', $contenu) ?: [];

    $meta = ['iban' => '', 'monnaie' => '', 'date_debut' => '', 'date_fin' => ''];
    $lignes = [];
    $dansData = false;

    // Extrait la valeur d'une cellule méta du type  Étiquette:;="VALEUR"
    $valMeta = static function (string $ligne): string {
        $parts = explode(';', $ligne, 2);
        $v = $parts[1] ?? '';
        $v = trim($v);
        if (str_starts_with($v, '="') && str_ends_with($v, '"')) {
            $v = substr($v, 2, -1);
        }
        return trim($v, '"');
    };

    foreach ($lignesBrutes as $ligne) {
        if ($dansData) {
            if (str_starts_with($ligne, 'Disclaimer')) {
                break; // fin du bloc de données
            }
            if (trim($ligne) === '') {
                continue; // ligne vide (dont celle juste après l'en-tête)
            }
            $cols = str_getcsv($ligne, ';', '"', '');
            $dateIso = date_iso_pf($cols[0] ?? '');
            if ($dateIso === '') {
                continue; // ligne non datée → ignorée
            }
            $credit = trim((string) ($cols[2] ?? ''));
            $debit  = trim((string) ($cols[3] ?? ''));
            $soldeS = trim((string) ($cols[5] ?? ''));
            $montant = $credit !== '' ? montant_float($credit) : montant_float($debit);
            $lignes[] = [
                'date_op' => $dateIso,
                'texte'   => trim((string) ($cols[1] ?? '')),
                'montant' => $montant,
                'solde'   => $soldeS === '' ? null : montant_float($soldeS),
            ];
            continue;
        }

        // En-tête de la table de données.
        if (str_starts_with($ligne, 'Date;') && str_contains($ligne, 'notification')) {
            $dansData = true;
            continue;
        }
        if (str_starts_with($ligne, 'Compte:')) {
            $meta['iban'] = $valMeta($ligne);
        } elseif (str_starts_with($ligne, 'Monnaie:')) {
            $meta['monnaie'] = $valMeta($ligne);
        } elseif (str_starts_with($ligne, 'Date de début:')) {
            $meta['date_debut'] = date_iso_pf($valMeta($ligne));
        } elseif (str_starts_with($ligne, 'Date de fin:')) {
            $meta['date_fin'] = date_iso_pf($valMeta($ligne));
        }
    }

    return $meta + ['lignes' => $lignes];
}

// Lit un relevé bancaire au format ISO 20022 camt.053 (XML), toutes versions
// de schéma courantes (001.02 à 001.08 — le préfixe de namespace est détecté
// dynamiquement, jamais supposé fixe). Renvoie le même format que
// parse_postfinance_csv() : ['iban','monnaie','date_debut','date_fin',
// 'lignes'=>[['date_op','texte','montant','solde'], …]] — branchable tel
// quel sur le même flux d'import/dédoublonnage.
// Un seul relevé (<Stmt>) traité : le premier du document (cas normal pour
// un export mono-compte ; un fichier multi-comptes serait hors d'usage ici).
function parse_camt053(string $contenu): array
{
    $vide = ['iban' => '', 'monnaie' => '', 'date_debut' => '', 'date_fin' => '', 'lignes' => []];

    $precedent = libxml_use_internal_errors(true);
    // NONET : pas de résolution réseau (DTD/entités externes) — le parseur
    // libxml2 moderne n'étend déjà pas les entités externes sans LIBXML_NOENT
    // (jamais passé ici), mais NONET ferme aussi la porte à toute requête
    // réseau déclenchée par un DOCTYPE malveillant.
    $xml = simplexml_load_string($contenu, 'SimpleXMLElement', LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($precedent);
    if ($xml === false) {
        return $vide;
    }

    // Namespace par défaut (urn:iso:std:iso:20022:tech:xsd:camt.053.001.0X) —
    // détecté plutôt que supposé, pour rester compatible entre banques/versions.
    // registerXPathNamespace() est PAR OBJET SimpleXMLElement (ne se propage
    // pas aux nœuds enfants renvoyés par xpath()) : chaque sous-élément
    // interrogé doit donc réenregistrer le préfixe avant sa propre requête —
    // d'où le passage systématique par $reg() ci-dessous plutôt qu'un seul
    // registerXPathNamespace() sur la racine.
    $namespaces = $xml->getNamespaces(true);
    $ns = $namespaces[''] ?? '';
    $reg = function (SimpleXMLElement $node) use ($ns): SimpleXMLElement {
        if ($ns !== '') {
            $node->registerXPathNamespace('c', $ns);
        }
        return $node;
    };
    $path = fn(string $p): string => $ns !== '' ? $p : str_replace('c:', '', $p);

    $stmts = $reg($xml)->xpath($path('//c:Stmt'));
    if (!$stmts) {
        return $vide;
    }
    $stmt = $reg($stmts[0]);
    $get = function (string $p) use ($stmt, $path): string {
        $r = $stmt->xpath($path('.' . $p));
        return $r ? trim((string) $r[0]) : '';
    };

    $iban = $get('/c:Acct/c:Id/c:IBAN');
    $monnaie = $get('/c:Acct/c:Ccy');

    // Solde d'ouverture : point de départ du solde courant recalculé ligne à
    // ligne (camt.053 ne porte pas de solde après chaque écriture). Code OPBD
    // (solde d'ouverture) ou, sur un relevé de continuation, PRCD (solde de
    // clôture précédent) — les deux jouent le même rôle ici.
    $soldeCourant = null;
    foreach ($stmt->xpath($path('./c:Bal')) as $bal) {
        $bal = $reg($bal);
        $code = $bal->xpath($path('.//c:Tp/c:CdOrPrtry/c:Cd'));
        if ($code && in_array((string) $code[0], ['OPBD', 'PRCD'], true)) {
            $amt = $bal->xpath($path('.//c:Amt'));
            $ind = $bal->xpath($path('.//c:CdtDbtInd'));
            if ($amt) {
                $v = montant_float((string) $amt[0]);
                $soldeCourant = ($ind && (string) $ind[0] === 'DBIT') ? -$v : $v;
            }
            break;
        }
    }

    $lignes = [];
    foreach ($stmt->xpath($path('.//c:Ntry')) as $entry) {
        $entry = $reg($entry);
        $xp = fn(string $p) => $entry->xpath($path('.' . $p));

        $amtNode = $xp('/c:Amt');
        if (!$amtNode) {
            continue;
        }
        $montant = montant_float((string) $amtNode[0]);
        $indNode = $xp('/c:CdtDbtInd');
        $estDebit = $indNode && (string) $indNode[0] === 'DBIT';
        if ($estDebit) {
            $montant = -$montant;
        }

        // Contre-partie structurée (Débiteur si crédit reçu, Créancier si débit
        // envoyé) — bien plus fiable que la reconnaissance par expression
        // régulière utilisée pour le CSV PostFinance (extraire_tiers(), non
        // pertinente ici : le texte libre Ustrd/AddtlNtryInf n'a pas le
        // vocabulaire fixe de PostFinance).
        $partieNode = $estDebit ? $xp('//c:RltdPties/c:Cdtr/c:Nm') : $xp('//c:RltdPties/c:Dbtr/c:Nm');
        $tiers = $partieNode ? trim((string) $partieNode[0]) : '';

        // Dt (date seule) ou DtTm (horodatage) : les deux formes sont légales
        // pour BookgDt/ValDt en ISO 20022 (DateAndDateTimeChoice) — repli sur
        // chacune, dans cet ordre, avant de tronquer aux 10 premiers caractères
        // (YYYY-MM-DD, communs aux deux formats).
        $dateNode = $xp('/c:BookgDt//c:Dt') ?: $xp('/c:BookgDt//c:DtTm')
            ?: $xp('/c:ValDt//c:Dt') ?: $xp('/c:ValDt//c:DtTm');
        $dateOp = $dateNode ? substr((string) $dateNode[0], 0, 10) : '';
        if ($dateOp === '' || !date_valide($dateOp)) {
            continue; // ligne non datée/invalide → ignorée (même politique que le CSV PostFinance)
        }

        $ustrd = $xp('//c:RmtInf/c:Ustrd');
        $texte = $ustrd ? implode(' ', array_map(fn($u) => trim((string) $u), $ustrd)) : '';
        if ($texte === '') {
            $addtl = $xp('/c:AddtlNtryInf');
            $texte = $addtl ? trim((string) $addtl[0]) : '';
        }

        if ($soldeCourant !== null) {
            $soldeCourant = r2($soldeCourant + $montant);
        }

        $lignes[] = ['date_op' => $dateOp, 'texte' => $texte, 'montant' => $montant, 'solde' => $soldeCourant, 'tiers' => $tiers];
    }

    $dates = array_column($lignes, 'date_op');
    return [
        'iban' => $iban, 'monnaie' => $monnaie,
        'date_debut' => $dates ? min($dates) : '', 'date_fin' => $dates ? max($dates) : '',
        'lignes' => $lignes,
    ];
}


// Génère un relevé ISO 20022 camt.053 (XML) à partir des écritures d'un seul
// compte bancaire — pour ré-importer ailleurs (autre logiciel comptable) ou
// archiver dans un format normalisé. $lignes : mêmes lignes que retournées
// par une requête sur `ecritures` (date_op, texte, montant), triées par date.
function compta_generer_camt053(array $compte, array $lignes, string $dateDebut, string $dateFin, float $soldeOuverture): string
{
    $NS = 'urn:iso:std:iso:20022:tech:xsd:camt.053.001.02';
    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->formatOutput = true;

    $el = fn(string $name, ?string $text = null): DOMElement => dom_el($doc, $NS, $name, $text);
    // <Amt Ccy="CHF">montant</Amt> — seul élément à porter un attribut.
    $amtEl = function (float $montant) use ($el): DOMElement {
        $n = $el('Amt', number_format(abs($montant), 2, '.', ''));
        $n->setAttribute('Ccy', 'CHF');
        return $n;
    };

    $root = $el('Document');
    $doc->appendChild($root);
    $stmtMsg = $el('BkToCstmrStmt');
    $root->appendChild($stmtMsg);

    $hdr = $el('GrpHdr');
    $stmtMsg->appendChild($hdr);
    $hdr->appendChild($el('MsgId', 'LASSO-' . date('YmdHis')));
    $hdr->appendChild($el('CreDtTm', date('c')));

    $stmt = $el('Stmt');
    $stmtMsg->appendChild($stmt);
    $stmt->appendChild($el('Id', 'STMT-' . date('YmdHis')));
    $stmt->appendChild($el('CreDtTm', date('c')));

    $acct = $el('Acct');
    $stmt->appendChild($acct);
    $acctId = $el('Id');
    $acct->appendChild($acctId);
    $acctId->appendChild($el('IBAN', (string) $compte['iban']));
    $acct->appendChild($el('Ccy', 'CHF'));
    $ownr = $el('Ownr');
    $ownr->appendChild($el('Nm', (string) param('employeur_nom')));
    $acct->appendChild($ownr);

    $soldeCourant = r2($soldeOuverture);
    $totalMontant = 0.0;
    foreach ($lignes as $l) {
        $totalMontant += (float) $l['montant'];
    }
    $soldeCloture = r2($soldeCourant + $totalMontant);

    $bal = function (string $code, float $montant, string $date) use ($el, $amtEl): DOMElement {
        $b = $el('Bal');
        $tp = $el('Tp');
        $b->appendChild($tp);
        $cdOrPrtry = $el('CdOrPrtry');
        $tp->appendChild($cdOrPrtry);
        $cdOrPrtry->appendChild($el('Cd', $code));
        $b->appendChild($amtEl($montant));
        $b->appendChild($el('CdtDbtInd', $montant < 0 ? 'DBIT' : 'CRDT'));
        $dt = $el('Dt');
        $b->appendChild($dt);
        $dt->appendChild($el('Dt', $date));
        return $b;
    };
    $stmt->appendChild($bal('OPBD', $soldeCourant, $dateDebut ?: date('Y-m-d')));
    $stmt->appendChild($bal('CLBD', $soldeCloture, $dateFin ?: date('Y-m-d')));

    foreach ($lignes as $l) {
        $montant = (float) $l['montant'];
        $ntry = $el('Ntry');
        $stmt->appendChild($ntry);
        $ntry->appendChild($amtEl($montant));
        $ntry->appendChild($el('CdtDbtInd', $montant < 0 ? 'DBIT' : 'CRDT'));
        $ntry->appendChild($el('Sts', 'BOOK'));
        $bookgDt = $el('BookgDt');
        $ntry->appendChild($bookgDt);
        $bookgDt->appendChild($el('Dt', (string) $l['date_op']));
        $valDt = $el('ValDt');
        $ntry->appendChild($valDt);
        $valDt->appendChild($el('Dt', (string) $l['date_op']));
        $ntryDtls = $el('NtryDtls');
        $ntry->appendChild($ntryDtls);
        $txDtls = $el('TxDtls');
        $ntryDtls->appendChild($txDtls);
        $rmtInf = $el('RmtInf');
        $txDtls->appendChild($rmtInf);
        $rmtInf->appendChild($el('Ustrd', mb_substr((string) $l['texte'], 0, 140)));
    }

    return $doc->saveXML();
}

// Clé de dédoublonnage d'une écriture. $occ = rang d'occurrence des lignes
// strictement identiques dans le même fichier (0 pour la première), ce qui
// préserve les vrais doublons légitimes tout en bloquant les ré-imports.
function ligne_hash(int $compteId, array $ligne, int $occ): string
{
    return sha1(implode('|', [
        $compteId,
        $ligne['date_op'],
        $ligne['texte'],
        number_format((float) $ligne['montant'], 2, '.', ''),
        $ligne['solde'] === null ? '' : number_format((float) $ligne['solde'], 2, '.', ''),
        $occ,
    ]));
}

// Calcule, pour une liste de lignes parsées, les hash dédoublonnés (gère les
// occurrences identiques). Renvoie un tableau de hash dans le même ordre.
function hash_lignes(int $compteId, array $lignes): array
{
    $compteur = [];
    $hashes = [];
    foreach ($lignes as $l) {
        $base = $l['date_op'] . '|' . $l['texte'] . '|' . $l['montant'] . '|' . ($l['solde'] ?? '');
        $occ = $compteur[$base] ?? 0;
        $compteur[$base] = $occ + 1;
        $hashes[] = ligne_hash($compteId, $l, $occ);
    }
    return $hashes;
}

// Tronque un nom de contre-partie avant l'adresse / les méta (heuristique).
function tiers_couper(string $s): string
{
    $s = trim($s);
    if ($s === '') {
        return '';
    }
    // Coupe à partir d'un marqueur d'adresse / méta.
    $s = preg_split('/\s+(?:RUE|ROUTE|CHEMIN|AVENUE|AV\.|RAMPE|PLACE|QUAI|BD|BOULEVARD|STRASSE|CASE POSTALE|MONTANT DE FRAIS|COMMUNICATIONS?|REFERENCES?|R[EÉ]F[EÉ]RENCE|HTTPS?|ID PAIEMENT|NUMERO DE|N\/A)\b/iu', $s)[0] ?? $s;
    // Coupe au premier code postal (4 chiffres) ou IBAN.
    $s = preg_split('/\s+(?:\d{4}\b|CH\d{2}\d)/u', $s)[0] ?? $s;
    $s = trim($s, " .,;-");
    // Limite à 5 mots pour rester une clé de regroupement stable.
    $mots = preg_split('/\s+/', trim($s)) ?: [];
    if (count($mots) > 5) {
        $mots = array_slice($mots, 0, 5);
    }
    return trim(implode(' ', $mots));
}

// Extrait la contre-partie (tiers) et la communication d'un texte PostFinance.
// Renvoie ['tiers' => string, 'communication' => string] (chaînes éventuellement vides).
function extraire_tiers(string $texte): array
{
    $t = trim(preg_replace('/\s+/', ' ', $texte) ?? '');
    $comm = '';
    $tiers = '';

    // --- Communication ---
    if (preg_match('/COMMUNICATIONS?\s*:\s*(.+?)(?:\s+REFERENCES?\s*:|\s+MONTANT DE FRAIS|$)/iu', $t, $m)) {
        $comm = $m[1];
    } elseif (preg_match("/R[EÉ]F[EÉ]RENCE DE L['’]EXP[EÉ]DITEUR\s*:\s*(.+)$/iu", $t, $m)) {
        $comm = $m[1];
    } elseif (preg_match('/TRANSFERT (?:DU|SUR) (?:PROPRE )?COMPTE\s+CH\S+\s+(.+)$/iu', $t, $m)) {
        $comm = $m[1];
    }
    // Retire un long code de référence numérique en fin de communication.
    $comm = trim(preg_replace('/\s+\d{12,}.*$/u', '', $comm) ?? '');

    // --- Tiers ---
    // « EXPÉDITEUR: » contre-partie, mais pas « RÉFÉRENCE DE L'EXPÉDITEUR: » (≠ tiers).
    if (preg_match("/(?:DONNEUR D['’]ORDRE|(?<!['’])EXP[EÉ]DITEUR)\s*:\s*(.+)$/iu", $t, $m)) {
        $tiers = $m[1];
    } elseif (preg_match("/CH\d{2}[0-9A-Z]+\s+(.+?)\s+R[EÉ]F[EÉ]RENCE DE L['’]EXP/iu", $t, $m)) {
        $tiers = $m[1]; // nom de la contre-partie après son IBAN (virements bancaires)
    } elseif (preg_match('/\bDU \d{2}\.\d{2}\.\d{4}\s*(?:CARTE N[O°]\.?\s*\S+\s+)?(.+)$/iu', $t, $m)) {
        $tiers = $m[1]; // ACHAT / VERSEMENT / PF PAY : marchand après la date
    } elseif (preg_match('/^(?:D[EÉ]BIT|CR[EÉ]DIT)\b\s*(.*)$/iu', $t, $m)) {
        $reste = $m[1];
        $reste = preg_replace('/^ORDRE PERMANENT\s*:\s*\S+\s*/iu', '', $reste);
        $reste = preg_replace('/^[0-9-]{6,}\s+/u', '', $reste);     // référence numérique
        $reste = preg_replace('/^CH\d{2}[0-9A-Z]+\s*/iu', '', $reste); // IBAN contre-partie
        $tiers = $reste;
    }

    return ['tiers' => tiers_couper($tiers), 'communication' => $comm];
}

// Une règle correspond-elle à une écriture ? $ecr a 'texte' et 'montant'.
// Si $regle contient 'conditions' (nouveau format migration_10), utilise le builder ET/OU.
// Sinon, rétrocompatibilité avec l'ancien format plat (motif / type_match / sens_filtre / montant_*).
function regle_match(array $regle, array $ecr): bool
{
    if (array_key_exists('conditions', $regle)) {
        $conditions = $regle['conditions'];
        if (empty($conditions)) {
            return false;
        }
        $operateur = $regle['operateur'] ?? 'ET';
        $montant   = (float) $ecr['montant'];
        $abs       = abs($montant);
        $texte     = normaliser_texte((string) $ecr['texte']);

        $evalCond = static function (array $cond) use ($montant, $abs, $texte): bool {
            $type   = $cond['type']   ?? 'texte';
            $op     = $cond['op']     ?? 'contient';
            $valeur = (string) ($cond['valeur'] ?? '');
            if ($type === 'sens') {
                if ($valeur === 'credit' && $montant < 0)  return false;
                if ($valeur === 'debit'  && $montant >= 0) return false;
                return true;
            }
            if ($type === 'montant' || $type === 'montant_min' || $type === 'montant_max') {
                $val = (float) $valeur;
                if ($type === 'montant_min') return $abs >= $val - 0.0001;
                if ($type === 'montant_max') return $abs <= $val + 0.0001;
                return match ($op) { '>=' => $abs >= $val - 0.0001, '<=' => $abs <= $val + 0.0001, default => abs($abs - $val) < 0.01 };
            }
            // type texte
            if ($valeur === '') return false;
            $motif = normaliser_texte($valeur);
            return match ($op) {
                'commence' => str_starts_with($texte, $motif),
                'exact'    => $texte === $motif,
                default    => str_contains($texte, $motif),
            };
        };

        if ($operateur === 'OU') {
            foreach ($conditions as $c) {
                if ($evalCond($c)) return true;
            }
            return false;
        }
        foreach ($conditions as $c) {
            if (!$evalCond($c)) return false;
        }
        return true;
    }

    // Ancien format plat (rétrocompatibilité : tests, script de migration, règles sans conditions_lettrage).
    $sens    = $regle['sens_filtre'] ?? '';
    $montant = (float) $ecr['montant'];
    if ($sens === 'credit' && $montant < 0)  return false;
    if ($sens === 'debit'  && $montant >= 0) return false;
    $abs = abs($montant);
    $min = $regle['montant_min'] ?? null;
    $max = $regle['montant_max'] ?? null;
    if ($min !== null && $min !== '' && $abs < (float) $min - 0.0001) return false;
    if ($max !== null && $max !== '' && $abs > (float) $max + 0.0001) return false;
    $motif = normaliser_texte((string) $regle['motif']);
    if ($motif === '') return false;
    $texte = normaliser_texte((string) $ecr['texte']);
    return match ($regle['type_match'] ?? 'contient') {
        'commence' => str_starts_with($texte, $motif),
        'exact'    => $texte === $motif,
        default    => str_contains($texte, $motif),
    };
}

// Charge les conditions depuis conditions_lettrage et les attache à chaque règle.
// Si une règle n'a pas encore de conditions (ex. script de migration), reconstitue à partir
// des colonnes plates (rétrocompatibilité).
function charger_conditions_regles(array $regles): array
{
    if (empty($regles)) {
        return [];
    }
    $ids  = implode(',', array_map(fn($r) => (int) $r['id'], $regles));
    $conds = db()->query("SELECT * FROM conditions_lettrage WHERE regle_id IN ($ids) ORDER BY regle_id, ordre")->fetchAll();
    $byId = [];
    foreach ($conds as $c) {
        $byId[(int) $c['regle_id']][] = $c;
    }
    return array_map(function ($r) use ($byId) {
        $conds = $byId[(int) $r['id']] ?? [];
        if (empty($conds)) {
            // Règle insérée sans passer par le builder UI : fallback colonnes plates.
            if ((string) $r['motif'] !== '') {
                $conds[] = ['type' => 'texte', 'op' => $r['type_match'] ?: 'contient', 'valeur' => $r['motif']];
            }
            if ((string) ($r['sens_filtre'] ?? '') !== '') {
                $conds[] = ['type' => 'sens', 'op' => '=', 'valeur' => $r['sens_filtre']];
            }
            if (($r['montant_min'] ?? null) !== null) {
                $conds[] = ['type' => 'montant_min', 'op' => '>=', 'valeur' => (string) $r['montant_min']];
            }
            if (($r['montant_max'] ?? null) !== null) {
                $conds[] = ['type' => 'montant_max', 'op' => '<=', 'valeur' => (string) $r['montant_max']];
            }
        }
        $r['conditions'] = $conds;
        $r['operateur']  = $r['operateur'] ?? 'ET';
        return $r;
    }, $regles);
}

// Trie les règles : priorité croissante, puis règle de compte avant règle
// globale, puis id. La première qui correspond l'emporte.
function trier_regles(array $regles): array
{
    usort($regles, static function ($a, $b) {
        $pa = (int) ($a['priorite'] ?? 0);
        $pb = (int) ($b['priorite'] ?? 0);
        if ($pa !== $pb) {
            return $pa <=> $pb;
        }
        // Règle ciblant un compte (spécifique) avant règle globale.
        $sa = ($a['compte_bancaire_id'] ?? null) === null ? 1 : 0;
        $sb = ($b['compte_bancaire_id'] ?? null) === null ? 1 : 0;
        if ($sa !== $sb) {
            return $sa <=> $sb;
        }
        return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
    });
    return $regles;
}

// Applique les règles à des écritures et renvoie [ecriture_id => plan_compte_id]
// pour celles qui correspondent. Ne considère que les règles actives applicables
// au compte de l'écriture (compte_bancaire_id NULL = globale).
function appliquer_regles(array $regles, array $ecritures): array
{
    $regles = trier_regles(array_filter($regles, fn($r) => (int) ($r['actif'] ?? 1) === 1));
    $res = [];
    foreach ($ecritures as $ecr) {
        $cid = (int) $ecr['compte_bancaire_id'];
        foreach ($regles as $r) {
            $rc = $r['compte_bancaire_id'] ?? null;
            if ($rc !== null && (int) $rc !== $cid) {
                continue;
            }
            if (regle_match($r, $ecr)) {
                $res[(int) $ecr['id']] = (int) $r['plan_compte_id'];
                break;
            }
        }
    }
    return $res;
}

// -------------------------------------------------- Ventilations analytiques

// Renvoie les lignes de ventilation d'une écriture avec les labels d'axe.
function compta_ventilations_ecriture(int $ecrId): array
{
    $stmt = db()->prepare(
        'SELECT ev.axe_id, ev.montant, a.libelle, a.code
         FROM ecritures_ventilations ev
         JOIN axes_analytiques a ON a.id = ev.axe_id
         WHERE ev.ecriture_id = ? ORDER BY ev.id'
    );
    $stmt->execute([$ecrId]);
    return $stmt->fetchAll();
}

// Remplace toutes les ventilations d'une écriture (DELETE + INSERT atomique).
// $lignes = [['axe_id' => int, 'montant' => float], ...]
function compta_save_ventilations(int $ecrId, array $lignes): void
{
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM ecritures_ventilations WHERE ecriture_id = ?')->execute([$ecrId]);
    if ($lignes) {
        $ins = $pdo->prepare('INSERT INTO ecritures_ventilations (ecriture_id, axe_id, montant) VALUES (?, ?, ?)');
        foreach ($lignes as $l) {
            $axeId  = (int) $l['axe_id'];
            $montant = (float) $l['montant'];
            if ($axeId > 0) {
                $ins->execute([$ecrId, $axeId, $montant]);
            }
        }
    }
    $pdo->commit();
}

// ----------------------------------------------------- Plan comptable (arbre)
// Normalise un parent_id en int (0 = racine).
function plan_pid($v): int
{
    return ($v === null || $v === '') ? 0 : (int) $v;
}

// Enfants directs groupés par parent : [parentId => [rows triées par ordre]].
// La clé 0 contient les catégories racines.
function plan_enfants(array $plan): array
{
    $byParent = [];
    foreach ($plan as $row) {
        $byParent[plan_pid($row['parent_id'] ?? null)][] = $row;
    }
    foreach ($byParent as &$list) {
        usort($list, fn($a, $b) => [(int) $a['ordre'], (int) $a['id']] <=> [(int) $b['ordre'], (int) $b['id']]);
    }
    return $byParent;
}

// Ensemble des ids ayant au moins un enfant (= catégories non-feuilles).
function plan_parents_set(array $plan): array
{
    $s = [];
    foreach ($plan as $row) {
        $p = plan_pid($row['parent_id'] ?? null);
        if ($p) {
            $s[$p] = true;
        }
    }
    return $s;
}

function plan_est_feuille(int $id, array $plan): bool
{
    return !isset(plan_parents_set($plan)[$id]);
}

// Chemin lisible « Racine › Sous › Feuille » d'une catégorie.
function plan_chemin(int $id, array $plan, string $sep = ' › '): string
{
    $parts = [];
    $cur = $id;
    $garde = 0;
    while (isset($plan[$cur]) && $garde++ < 50) {
        array_unshift($parts, (string) $plan[$cur]['libelle']);
        $cur = plan_pid($plan[$cur]['parent_id'] ?? null);
        if ($cur === 0) {
            break;
        }
    }
    return implode($sep, $parts);
}

// Liste à plat dans l'ordre d'affichage de l'arbre, avec métadonnées par ligne :
// 'profondeur', 'a_enfants', 'est_premier', 'est_dernier' (parmi ses frères).
function plan_liste_ordonnee(array $plan): array
{
    $byParent = plan_enfants($plan);
    $out = [];
    $walk = function (int $pid, int $prof) use (&$walk, &$out, $byParent) {
        $freres = $byParent[$pid] ?? [];
        $n = count($freres);
        foreach ($freres as $i => $row) {
            $row['profondeur']  = $prof;
            $row['a_enfants']   = !empty($byParent[(int) $row['id']]);
            $row['est_premier'] = $i === 0;
            $row['est_dernier'] = $i === $n - 1;
            $out[] = $row;
            $walk((int) $row['id'], $prof + 1);
        }
    };
    $walk(0, 0);
    return $out;
}

// Catégories feuilles (sans enfant), dans l'ordre de l'arbre, avec leur chemin
// et leur racine — pour les listes déroulantes de lettrage et de règles.
function plan_feuilles(array $plan): array
{
    $parents = plan_parents_set($plan);
    $out = [];
    foreach (plan_liste_ordonnee($plan) as $row) {
        $id = (int) $row['id'];
        if (isset($parents[$id])) {
            continue; // a des enfants → non assignable
        }
        $out[] = [
            'id'      => $id,
            'libelle' => (string) $row['libelle'],
            'sens'    => (string) $row['sens'],
            'chemin'  => plan_chemin($id, $plan),
        ];
    }
    return $out;
}

// Ids d'une catégorie et de tous ses descendants (sous-arbre), pour filtrer par
// une sur-catégorie. $byParent = plan_enfants($plan).
function plan_descendants(int $id, array $byParent): array
{
    $ids = [$id];
    foreach ($byParent[$id] ?? [] as $enfant) {
        $ids = array_merge($ids, plan_descendants((int) $enfant['id'], $byParent));
    }
    return $ids;
}

// Sous-total d'une catégorie : sa somme propre si feuille, sinon la somme de ses
// descendants. $sommes : [id => montant] (par feuille lettrée).
function plan_sous_total(int $id, array $byParent, array $sommes): float
{
    if (empty($byParent[$id])) {
        return (float) ($sommes[$id] ?? 0.0);
    }
    $t = 0.0;
    foreach ($byParent[$id] as $child) {
        $t += plan_sous_total((int) $child['id'], $byParent, $sommes);
    }
    return $t;
}

// Agrège un compte de résultat à partir d'écritures lettrées.
// $ecritures : lignes avec 'montant' et 'plan_compte_id' (NULL = non lettré).
// $plan : [id => row] (avec 'sens'). Renvoie les sommes par catégorie + totaux.
function agreger_resultat(array $ecritures, array $plan): array
{
    $sommes = [];
    $tp = 0.0;
    $tc = 0.0;
    $nonLettrees = ['nb' => 0, 'montant' => 0.0];

    foreach ($ecritures as $e) {
        // Marquée « Ne pas lettrer » : volontairement exclue (ni résultat, ni non-lettrées).
        if (($e['origine_lettrage'] ?? '') === 'ignore') {
            continue;
        }
        $pid = $e['plan_compte_id'] ?? null;
        $montant = (float) $e['montant'];
        if ($pid === null || !isset($plan[(int) $pid])) {
            $nonLettrees['nb']++;
            $nonLettrees['montant'] += $montant;
            continue;
        }
        $pid = (int) $pid;
        $sommes[$pid] = ($sommes[$pid] ?? 0.0) + $montant;
        if (($plan[$pid]['sens'] ?? 'charge') === 'produit') {
            $tp += $montant;
        } else {
            $tc += $montant;
        }
    }

    return [
        'sommes'         => $sommes,
        'total_produits' => $tp,
        'total_charges'  => $tc,
        'resultat'       => $tp + $tc,
        'non_lettrees'   => $nonLettrees,
    ];
}

// Résumé lisible d'un texte d'écriture PostFinance.
// Extrait : nom du tiers + communication/référence, en ignorant l'adresse et les
// longues chaînes numériques de référence bancaire.
function resumer_texte_postfinance(string $texte): string
{
    $t = preg_replace('/\s+/', ' ', $texte);

    // --- Communication / référence (partie la plus informative) ---
    $comm = null;

    // COMMUNICATIONS: ... [REFERENCES:]
    if (preg_match('/COMMUNICATIONS?\s*:\s*(.+?)(?:\s+REFERENCES?\s*:|$)/iu', $t, $m)) {
        $c = trim($m[1]);
        if (strcasecmp($c, 'NOTPROVIDED') !== 0 && strlen($c) > 1) {
            $comm = $c;
        }
    }
    // REFERENCE DE L'EXPEDITEUR: ...
    if ($comm === null && preg_match("/REFERENCE\s+DE\s+L.EXPEDITEUR\s*:\s*(.+?)(?:\s+REFERENCES?\s*:|$)/iu", $t, $m)) {
        // Supprimer les longues séquences numériques (refs bancaires)
        $c = preg_replace('/\b\d{8,}\b/', '', trim($m[1]));
        $c = trim(preg_replace('/\s+/', ' ', $c));
        if (strlen($c) > 1) {
            $comm = $c;
        }
    }

    // --- Nom du tiers ---
    $nom = null;
    // Indicateurs de début d'adresse : mots de rue connus, ou n° de bâtiment suivi du NPA (ex. "11 2502")
    $adresse = 'RUE\b|AVENUE\b|CHEMIN\b|ROUTE\b|IMPASSE\b|BOULEVARD\b|PLACE\b|ALL[EÉ]E\b|VIA\b|\d{1,4}\s+\d{4}\b|\d{4,}\s+\p{L}';

    // CRÉDIT DONNEUR D'ORDRE: [nom] [adresse]
    if (preg_match("/DONNEUR\s+D.ORDRE\s*:\s*([\p{L}\s\-\'\(\)\/]+?)(?:\s+(?:$adresse|COMMUNICATIONS?|REFERENCES?))/iu", $t, $m)) {
        $nom = trim($m[1]);
    }

    // Après un IBAN (CHxx…) : prendre les mots jusqu'à l'adresse ou REFERENCE
    if ($nom === null && preg_match('/(CH\d{2}[A-Z0-9]{4,})\s+([\p{L}\s\-\'\(\)\/]+?)(?:\s+(?:' . $adresse . '|REFERENCE))/iu', $t, $m)) {
        $nom = trim($m[2]);
        // Si le nom ressemble à une banque intermédiaire (contient BANK, AG, SA…),
        // chercher un 2e IBAN suivi d'un autre nom.
        if (preg_match('/\b(?:BANK|AG|SA|LTD|GMBH)\b/iu', $nom)) {
            if (preg_match('/(CH\d{2}[A-Z0-9]{4,})\s+([\p{L}\s\-\'\(\)\/]+?)(?:\s+(?:' . $adresse . '|REFERENCE))/iu',
                    substr($t, strpos($t, $m[1]) + strlen($m[1])), $m2)) {
                $nom = trim($m2[2]);
            }
        }
    }

    // Shopping en ligne du DD.MM.YYYY [marchand]
    if ($nom === null && preg_match('/SHOPPING\s+EN\s+LIGNE\s+DU\s+\d{2}\.\d{2}\.\d{4}\s+(.+?)(?:\s+REFERENCES?\s*:|$)/iu', $t, $m)) {
        $nom = trim($m[1]);
    }

    // --- Construction du résumé ---
    $parts = [];
    if ($nom !== null && $nom !== '') {
        $parts[] = mb_convert_case(mb_strtolower($nom, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }
    if ($comm !== null && $comm !== '') {
        $parts[] = $comm;
    }

    if (!$parts) {
        // Fallback : début du texte brut
        return mb_strlen($texte) > 80 ? mb_substr($texte, 0, 80, 'UTF-8') . '…' : $texte;
    }

    $resume = implode(' — ', $parts);
    return mb_strlen($resume, 'UTF-8') > 120 ? mb_substr($resume, 0, 120, 'UTF-8') . '…' : $resume;
}

// Insère les écritures parsées dans la base (dédoublonnage par hash).
// Renvoie [nb_inserees, nb_doublons, import_id].
function compta_inserer_ecritures(array $compte, array $parse, string $nomFichier): array
{
    $compteId = (int) $compte['id'];
    $hashes = hash_lignes($compteId, $parse['lignes']);
    db()->beginTransaction();
    db()->prepare('INSERT INTO imports (compte_bancaire_id, nom_fichier, date_debut, date_fin, nb_total)
                   VALUES (?, ?, ?, ?, ?)')
        ->execute([$compteId, $nomFichier, $parse['date_debut'], $parse['date_fin'], count($parse['lignes'])]);
    $importId = (int) db()->lastInsertId();

    $ins = db()->prepare('INSERT OR IGNORE INTO ecritures
        (compte_bancaire_id, import_id, date_op, texte, tiers, communication, montant, solde, hash)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $nbIns = 0;
    foreach ($parse['lignes'] as $i => $l) {
        // camt.053 fournit directement la contre-partie (champ structuré
        // Débiteur/Créancier, voir parse_camt053()) — plus fiable que la
        // reconnaissance par expression régulière ci-dessous, taillée pour le
        // vocabulaire fixe du CSV PostFinance et non pertinente sur un texte
        // libre bancaire quelconque.
        if (!empty($l['tiers'])) {
            $tiers = $l['tiers'];
            $communication = '';
        } else {
            $ex = extraire_tiers((string) $l['texte']);
            $tiers = $ex['tiers'];
            $communication = $ex['communication'];
        }
        $ins->execute([$compteId, $importId, $l['date_op'], $l['texte'], $tiers, $communication,
            $l['montant'], $l['solde'], $hashes[$i]]);
        $nbIns += $ins->rowCount();
    }
    $nbDup = count($parse['lignes']) - $nbIns;
    db()->prepare('UPDATE imports SET nb_importees = ?, nb_doublons = ? WHERE id = ?')
        ->execute([$nbIns, $nbDup, $importId]);
    db()->commit();
    return [$nbIns, $nbDup, $importId];
}

// Aperçu en lecture seule d'un import (dry-run) : compte reconnu ou non,
// et décompte nouvelles/doublons par hash — sans rien écrire en base.
function compta_previsualiser_import(array $parse): array
{
    $iban = $parse['iban'];
    $compte = null;
    if ($iban !== '') {
        $stmt = db()->prepare('SELECT * FROM comptes_bancaires WHERE iban = ?');
        $stmt->execute([$iban]);
        $compte = $stmt->fetch() ?: null;
    }
    $total = count($parse['lignes']);
    $nbDoublons = 0;
    if ($compte && $total > 0) {
        // hash encode déjà le compte (voir ligne_hash) : UNIQUE global sur ecritures.hash.
        $hashes = hash_lignes((int) $compte['id'], $parse['lignes']);
        $in = implode(',', array_fill(0, count($hashes), '?'));
        $stmt = db()->prepare("SELECT COUNT(*) FROM ecritures WHERE hash IN ($in)");
        $stmt->execute($hashes);
        $nbDoublons = (int) $stmt->fetchColumn();
    }
    return [
        'iban'         => $iban,
        'compte'       => $compte,
        'nomSuggere'   => $iban !== '' ? ('Compte PostFinance ' . substr($iban, -4)) : '',
        'total'        => $total,
        'nouvelles'    => $total - $nbDoublons,
        'doublons'     => $nbDoublons,
    ];
}
