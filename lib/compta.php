<?php
// Logique du module de comptabilité (fonctions pures, sans état) :
// lecture des exports PostFinance, dédoublonnage, lettrage par règles,
// agrégation du compte de résultat. Aucune dépendance externe.

require_once __DIR__ . '/db.php';

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

    // Ancien format plat (rétrocompatibilité : tests, script Souka, règles sans conditions_lettrage).
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
// Si une règle n'a pas encore de conditions (ex. script Souka), reconstitue à partir
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
// Renvoie [nb_inserees, nb_doublons].
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
        $ex = extraire_tiers((string) $l['texte']);
        $ins->execute([$compteId, $importId, $l['date_op'], $l['texte'], $ex['tiers'], $ex['communication'],
            $l['montant'], $l['solde'], $hashes[$i]]);
        $nbIns += $ins->rowCount();
    }
    $nbDup = count($parse['lignes']) - $nbIns;
    db()->prepare('UPDATE imports SET nb_importees = ?, nb_doublons = ? WHERE id = ?')
        ->execute([$nbIns, $nbDup, $importId]);
    db()->commit();
    return [$nbIns, $nbDup];
}
