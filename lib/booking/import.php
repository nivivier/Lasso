<?php
// Module booking — l'import CSV d'un carnet d'adresses (docs/SPEC_BOOKING.md §8) :
// correspondance des colonnes, analyse, taxonomie, conflits, application.
// Chargé par lib/booking.php, jamais requis directement.

declare(strict_types=1);

// ------------------------------------------------------- IMPORT CSV (carnets d'adresses)
// Champs importables → libellé affiché dans l'écran de correspondance des
// colonnes (§8 de docs/SPEC_BOOKING.md). Seul « nom » est obligatoire.
const STRUCTURE_IMPORT_CHAMPS = [
    'nom'              => 'Nom de la structure',
    'categorie'        => 'Catégorie (organisateur/média/autres/entourage)',
    'sous_categorie'   => 'Sous-catégorie (ex. Journaliste, Salle de concert…)',
    'type_lieu'        => 'Type de lieu (salle, festival, salle de location…)',
    'adresse_rue'      => 'Adresse (rue)',
    'adresse_npa'      => 'NPA',
    'adresse_localite' => 'Localité',
    'departement_canton' => 'Département / canton',
    'grande_region'    => 'Région (Normandie, Romandie, Acadie…)',
    'pays'             => 'Pays',
    'periode_evenement'     => 'Période de l\'événement (mois, ex. « 6 7 8 » ou « jun jul aug »)',
    'periode_programmation' => 'Période de programmation (mois, ex. « 1 2 3 » ou « jan feb mar »)',
    'dernier_concert'  => 'Dernier concert ou diffusion (date, JJ/MM/AAAA)',
    'site_web'         => 'Site web',
    'via'              => 'Via (source du contact)',
    'notes'            => 'Notes',
    'prenom'           => 'Prénom (contact)',
    'nom_contact'      => 'Nom (contact)',
    'role'             => 'Rôle (contact)',
    'email_contact'    => 'E-mail (contact)',
    'telephone'        => 'Téléphone (contact)',
    'formulaire_url'   => 'Formulaire (contact)',
    'langue'           => 'Langue (contact)',
    'dernier_contact'  => 'Dernier contact (date, JJ/MM/AAAA)',
    'mise_a_jour'      => 'Dernière mise à jour (date, JJ/MM/AAAA)',
    'jauge_min'        => 'Jauge min (salle/festival)',
    'jauge_max'        => 'Jauge max (salle/festival)',
    'tags_statut'      => 'Tags (colonne codée, ex. « & E T »)',
];

// Dictionnaire des symboles de statut utilisés dans certains carnets
// d'adresses (ex. colonne « Statut » d'un ancien outil de mailing) — une
// cellule peut contenir plusieurs symboles séparés par des espaces (ex.
// « & E T »), chacun devient une étiquette distincte. Sensible à la casse :
// 'x'/'xx' (minuscule) et 'X'/'XX' (majuscule) ont des sens différents selon
// les carnets sources, tous deux couverts ici.
const STRUCTURE_IMPORT_TAGS_LEGENDE = [
    '>'    => 'À contacter',
    '...>' => 'À contacter bientôt',
    '>>'   => 'À contacter vite',
    'T'    => 'À contacter en cas de tournée',
    'E'    => 'À contacter en cas d\'événement',
    '<'    => 'Contacté',
    '<<'   => 'Relancé',
    '<<<'  => 'Méga-relancé',
    '&'    => 'Actif',
    'x'    => 'Ne pas contacter (trop gros, autre style)',
    'xx'   => 'Inactif - fermé',
    'XX'   => 'Inactif - fermé',
    '...'  => 'Ne pas contacter pour l\'instant',
    'N'    => 'Réponse négative',
    '!'    => 'Contact privilégié - éviter les mailings',
    'PP'   => 'Trop gros - premières parties',
    '@'    => 'Problème adresse e-mail',
    'D'    => 'Désinscrit',
    'X'    => 'Ne pas contacter (autre raison)',
    'C'    => 'À informer des dates de concert',
    '?'    => 'Info à chercher',
];

// Découpe un CSV brut en [en-tête (noms de colonnes bruts), lignes (tableaux
// de valeurs brutes)] — sans mapping, juste la structure du fichier (étape 1
// de l'import, avant correspondance manuelle des colonnes).
//
// Lecture par flux (fgetcsv), jamais par découpage du texte brut sur les
// retours à la ligne : un champ entre guillemets peut légitimement contenir
// un retour à la ligne (cellule multi-ligne d'un export Excel/LibreOffice,
// ex. une adresse ou une note sur plusieurs lignes) — un pré-découpage
// regex couperait ce champ au mauvais endroit et décalerait toute la suite
// du fichier. fgetcsv() sait nativement où s'arrête un enregistrement même
// quand il s'étend sur plusieurs lignes physiques.
function structures_lire_csv(string $csv): array
{
    $csv = (string) preg_replace('/^\xEF\xBB\xBF/', '', $csv); // BOM UTF-8 (export Excel)
    if (trim($csv) === '') {
        return [[], []];
    }
    // Délimiteur détecté sur la seule première ligne physique (l'en-tête n'a jamais
    // de guillemets/retours à la ligne à ce stade, contrairement aux lignes de données).
    $delim = csv_detecter_delimiteur(substr($csv, 0, strcspn($csv, "\r\n")));

    $flux = fopen('php://memory', 'r+');
    fwrite($flux, $csv);
    rewind($flux);
    $lignesBrutes = [];
    while (($ligne = fgetcsv($flux, 0, $delim, '"', '')) !== false) {
        if ($ligne === [null]) {
            continue; // ligne blanche entre deux enregistrements
        }
        $lignesBrutes[] = $ligne;
    }
    fclose($flux);
    if (!$lignesBrutes) {
        return [[], []];
    }
    $entete = array_shift($lignesBrutes);
    return [$entete, $lignesBrutes];
}

// Date CSV JJ/MM/AAAA → ISO AAAA-MM-JJ, ou null si absente/invalide (jamais devinée).
function structure_date_csv_vers_iso(string $s): ?string
{
    $s = trim($s);
    if ($s === '') {
        return null;
    }
    // Numéro de série Excel/LibreOffice (date exportée « brute » : nombre de jours
    // depuis le 30/12/1899, éventuellement avec une fraction horaire). Plage
    // prudente [15000, 60000] ≈ 1941–2064 pour ne pas confondre avec un autre
    // nombre (ex. une jauge). 25569 = décalage entre l'époque Excel et Unix.
    if (preg_match('#^(\d{5})(?:\.\d+)?$#', $s, $m)) {
        $serial = (int) $m[1];
        if ($serial >= 15000 && $serial <= 60000) {
            return gmdate('Y-m-d', ($serial - 25569) * 86400);
        }
    }
    if (preg_match('#^(\d{4})-(\d{1,2})-(\d{1,2})$#', $s, $m)) {
        // Déjà au format ISO (AAAA-MM-JJ).
        [, $annee, $mois, $jour] = $m;
    } elseif (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2}|\d{4})$#', $s, $m)) {
        // JJ/MM/AAAA, JJ.MM.AA, JJ-MM-AAAA… (séparateurs / . -, année sur 2 ou 4
        // chiffres). Année sur 2 chiffres : pivot à 70 (00–69 → 20xx, 70–99 → 19xx).
        [, $jour, $mois, $annee] = $m;
        if (strlen($annee) === 2) {
            $annee = ((int) $annee <= 69 ? 2000 : 1900) + (int) $annee;
        }
    } else {
        return null;
    }
    if (!checkdate((int) $mois, (int) $jour, (int) $annee)) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', (int) $annee, (int) $mois, (int) $jour);
}

// Décode une cellule « Statut » codée (ex. « & E T ») en libellés d'étiquette
// (STRUCTURE_IMPORT_TAGS_LEGENDE) — un symbole par groupe d'espaces, chaque
// symbole reconnu devient une étiquette ; les symboles non reconnus sont
// ignorés (jamais devinés). Retourne des libellés dédupliqués.
function structure_tags_depuis_statut(string $cellule): array
{
    $tokens = preg_split('/\s+/', trim($cellule), -1, PREG_SPLIT_NO_EMPTY);
    $labels = [];
    foreach ($tokens as $t) {
        if (isset(STRUCTURE_IMPORT_TAGS_LEGENDE[$t])) {
            $labels[STRUCTURE_IMPORT_TAGS_LEGENDE[$t]] = true;
        }
    }
    return array_keys($labels);
}

// Trouve ou crée un structure_tags par nom (insensible à la casse) et
// l'attache à la structure — même logique que route_structure_tag_ajouter().
function structure_attacher_tag(int $structureId, string $nomTag): void
{
    $stmt = db()->prepare('SELECT id FROM structure_tags WHERE nom = ? COLLATE NOCASE');
    $stmt->execute([$nomTag]);
    $tagId = $stmt->fetchColumn();
    if ($tagId === false) {
        db()->prepare('INSERT INTO structure_tags (nom) VALUES (?)')->execute([$nomTag]);
        $tagId = (int) db()->lastInsertId();
    }
    db()->prepare('INSERT OR IGNORE INTO structure_tag_liens (structure_id, tag_id) VALUES (?, ?)')
        ->execute([$structureId, (int) $tagId]);
}

// Mémorise, pour le prochain import, la correspondance choisie sous forme de
// NOMS de colonnes (pas d'index : l'ordre des colonnes peut changer d'un fichier
// à l'autre). Enregistrée dans les paramètres (champ → intitulé de colonne).
function structure_import_memoriser_mapping(array $mapping, array $entete): void
{
    $noms = [];
    foreach ($mapping as $champ => $idx) {
        if ($idx !== null && isset($entete[$idx])) {
            $nom = trim((string) $entete[$idx]);
            if ($nom !== '') {
                $noms[$champ] = $nom;
            }
        }
    }
    db()->prepare('INSERT OR REPLACE INTO parametres (cle, valeur) VALUES (?, ?)')
        ->execute(['import_structures_mapping_noms', json_encode($noms, JSON_UNESCAPED_UNICODE)]);
}

// Propose une correspondance (champ → index de colonne) pour un nouveau fichier,
// à partir des noms de colonnes mémorisés au dernier import : un champ est
// pré-sélectionné si l'intitulé mémorisé se retrouve dans l'en-tête courant
// (comparaison insensible à la casse et aux espaces). Champs absents = ignorés.
function structure_import_mapping_suggere(array $entete): array
{
    $brut = (string) param('import_structures_mapping_noms', '');
    $noms = $brut !== '' ? json_decode($brut, true) : null;
    if (!is_array($noms)) {
        return [];
    }
    $parNom = [];
    foreach ($entete as $i => $col) {
        $parNom[mb_strtolower(trim((string) $col), 'UTF-8')] = (int) $i;
    }
    $suggere = [];
    foreach ($noms as $champ => $nom) {
        $k = mb_strtolower(trim((string) $nom), 'UTF-8');
        if (isset($parNom[$k]) && isset(STRUCTURE_IMPORT_CHAMPS[$champ])) {
            $suggere[$champ] = $parNom[$k];
        }
    }
    return $suggere;
}

// Convertit un jeton de mois (numéro « 6 », nom « juin », abréviation « juil »)
// en numéro de mois 1–12, ou null si non reconnu.
function mois_token_vers_numero(string $tok): ?int
{
    $tok = trim($tok);
    if ($tok === '') {
        return null;
    }
    if (ctype_digit($tok)) {
        $n = (int) $tok;
        return ($n >= 1 && $n <= 12) ? $n : null;
    }
    $t = mb_strtolower($tok, 'UTF-8');
    $t = strtr($t, ['é' => 'e', 'ê' => 'e', 'è' => 'e', 'ë' => 'e', 'û' => 'u', 'ù' => 'u', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'à' => 'a', 'â' => 'a', 'ç' => 'c']);
    // Codes anglais à 3 lettres (jan feb mar…) — plusieurs ne sont pas des
    // préfixes du nom français (feb≠fev, apr≠avr, jun≠jui, aug≠aou), d'où la
    // table explicite. Prioritaire sur la déduction par préfixe français.
    $anglais = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
                'jul' => 7, 'aug' => 8, 'sep' => 9, 'sept' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
    if (isset($anglais[$t])) {
        return $anglais[$t];
    }
    $noms = ['janvier', 'fevrier', 'mars', 'avril', 'mai', 'juin', 'juillet', 'aout', 'septembre', 'octobre', 'novembre', 'decembre'];
    foreach ($noms as $i => $nom) {
        if ($t === $nom) {
            return $i + 1;
        }
    }
    // Abréviation : le jeton (≥ 3 lettres) est un préfixe du nom du mois. « jui »
    // est ambigu (juin/juillet) → on retient le premier (juin) ; « juil » lève
    // l'ambiguïté. Assez robuste pour un champ saisi à la main.
    if (mb_strlen($t, 'UTF-8') >= 3) {
        foreach ($noms as $i => $nom) {
            if (str_starts_with($nom, $t)) {
                return $i + 1;
            }
        }
    }
    return null;
}

// Convertit un champ « mois séparés par des espaces » (ex. « 6 7 8 », « nov déc
// jan fév ») en une plage [début, fin] compatible avec les colonnes mois_*.
// Gère le passage d'année : la plage démarre juste après le plus grand écart
// circulaire entre mois cochés (ex. {11,12,1,2} → début 11, fin 2). Renvoie
// ['debut' => ?int, 'fin' => ?int] (null/null si aucun mois reconnu).
function mois_plage_depuis_liste(string $champ): array
{
    $nums = [];
    foreach (preg_split('/[^0-9A-Za-zÀ-ÿ]+/u', trim($champ)) ?: [] as $tok) {
        $n = mois_token_vers_numero((string) $tok);
        if ($n !== null) {
            $nums[$n] = true;
        }
    }
    $nums = array_keys($nums);
    sort($nums);
    $c = count($nums);
    if ($c === 0) {
        return ['debut' => null, 'fin' => null];
    }
    if ($c === 1) {
        return ['debut' => $nums[0], 'fin' => $nums[0]];
    }
    if ($c === 12) {
        return ['debut' => 1, 'fin' => 12];
    }
    $bestGap = -1;
    $startIdx = 0;
    for ($i = 0; $i < $c; $i++) {
        $gap = ($nums[($i + 1) % $c] - $nums[$i] + 12) % 12;
        if ($gap > $bestGap) {
            $bestGap = $gap;
            $startIdx = ($i + 1) % $c;
        }
    }
    return ['debut' => $nums[$startIdx], 'fin' => $nums[($startIdx - 1 + $c) % $c]];
}

// Applique le mapping (champ → index de colonne CSV) à chaque ligne, et
// détecte les correspondances existantes. Ne modifie rien (analyse pure) —
// réutilisée à la fois pour afficher l'écran de résolution des conflits et
// pour appliquer l'import (mêmes données, pas de re-parsing divergent).
// Résout la catégorie de STRUCTURE d'une ligne d'import contre la taxonomie
// complète (insensible casse + accents, indexes fournis pour rester pur/testable) :
//   1. la valeur est une catégorie racine     → cette racine ;
//   2. la valeur est une sous-catégorie        → sa catégorie parente, et la
//      sous-catégorie est renseignée si vide (sauf si cette valeur est en fait
//      un type de lieu déjà routé vers le lieu, $typeCat non vide) ;
//   3. sinon                                    → la catégorie par défaut.
// $racines : clé pliée → nom racine. $subs : clé pliée → ['parent'=>…, 'nom'=>…].
// Corrige le bug « tout ce qui n'est pas une racine finit dans Organisateur »
// (ex. une colonne catégorie contenant « Radio », « Journaliste », « Média »).
function structure_import_resoudre_categorie(string $cat, string $sous, string $typeCat, array $racines, array $subs, string $defaut): array
{
    $cle = texte_sans_accents($cat);
    if (isset($racines[$cle])) {
        return ['categorie' => $racines[$cle], 'sous_categorie' => $sous];
    }
    if (isset($subs[$cle])) {
        $nouvSous = ($sous === '' && $typeCat === '') ? $subs[$cle]['nom'] : $sous;
        return ['categorie' => $subs[$cle]['parent'], 'sous_categorie' => $nouvSous];
    }
    return ['categorie' => $defaut, 'sous_categorie' => $sous];
}

function structures_analyser_import(array $lignes, array $mapping): array
{
    $col = function (array $r, string $champ) use ($mapping): string {
        $i = $mapping[$champ] ?? null;
        return $i !== null && $i !== '' && isset($r[(int) $i]) ? trim((string) $r[(int) $i]) : '';
    };

    // Préchargements UNE seule fois (l'analyse tourne à chaque étape de l'import
    // sur potentiellement des milliers de lignes) : sans ces index, chaque ligne
    // re-scannait toute la table structures et relançait plusieurs requêtes de
    // catégorie — coût quadratique. Ici : deux requêtes groupées + des tableaux.
    $indexNom = [];   // nom normalisé → [ ['id'=>…, 'ville'=>ville normalisée], … ]
    $indexEmail = []; // e-mail (minuscule) → id de structure existante (1er gagnant)
    foreach (db()->query('SELECT id, nom, adresse_localite FROM structures') as $s) {
        $cle = normaliser_nom_structure((string) $s['nom']);
        if ($cle !== '') {
            $indexNom[$cle][] = [
                'id'    => (int) $s['id'],
                'ville' => normaliser_nom_structure((string) ($s['adresse_localite'] ?? '')),
            ];
        }
    }
    foreach (db()->query("SELECT pe.email, cs.structure_id FROM personne_structures cs JOIN personnes pe ON pe.id = cs.personne_id WHERE pe.email <> ''") as $c) {
        $cle = mb_strtolower(trim((string) $c['email']), 'UTF-8');
        if ($cle !== '' && !isset($indexEmail[$cle])) {
            $indexEmail[$cle] = (int) $c['structure_id'];
        }
    }
    // Taxonomie des structures, indexée par clé « pliée » (minuscule sans accents)
    // pour un rapprochement tolérant à l'import : racines, sous-catégories (→ parent).
    $catMap = structure_categorie_map();
    $catsRacines = []; // clé pliée → nom racine canonique
    $catsSub = [];     // clé pliée → ['parent' => nom racine, 'nom' => nom sous-cat]
    foreach ($catMap as $r) {
        $nom = (string) $r['nom'];
        if (plan_pid($r['parent_id'] ?? null) === 0) {
            $catsRacines[texte_sans_accents($nom)] = $nom;
        }
    }
    foreach ($catMap as $r) {
        $pid = plan_pid($r['parent_id'] ?? null);
        if ($pid !== 0 && isset($catMap[$pid])) {
            $catsSub[texte_sans_accents((string) $r['nom'])] = [
                'parent' => (string) $catMap[$pid]['nom'],
                'nom'    => (string) $r['nom'],
            ];
        }
    }
    $catDefaut = structure_categorie_par_defaut();
    $lieuCats = []; // clé pliée → nom canonique (sous-catégorie « booking »)
    foreach (structure_sous_categories_booking_noms() as $n) {
        $lieuCats[texte_sans_accents((string) $n)] = (string) $n;
    }
    $normLieuCat = fn (string $v): string => $lieuCats[texte_sans_accents($v)] ?? '';

    $analyse = [];
    foreach ($lignes as $index => $r) {
        $donnees = [];
        foreach (array_keys(STRUCTURE_IMPORT_CHAMPS) as $champ) {
            $donnees[$champ] = $col($r, $champ);
        }
        if ($donnees['nom'] === '') {
            continue; // ligne sans nom : ignorée silencieusement (pas assez d'information)
        }
        // Type de lieu (salle de concert, festival, saison culturelle…). Priorité
        // à la colonne dédiée « type_lieu » si elle est mappée (normalisée sur la
        // taxonomie booking — structure_categories.est_booking, voir migration_59
        // —, valeur libre conservée sinon) ; à défaut, détecté sur les valeurs
        // BRUTES de catégorie / sous-catégorie AVANT normalisation. La valeur part
        // sur structures.sous_categorie (voir structure_appliquer_champs_booking_importe())
        // et n'encombre pas le champ catégorie/sous-catégorie « organisation » ;
        // la sous-catégorie qui n'était qu'un type de lieu est vidée.
        $typeMappeRaw = trim((string) ($donnees['type_lieu'] ?? ''));
        $typeSous = $normLieuCat($donnees['sous_categorie']);
        $typeCat  = $normLieuCat($donnees['categorie']);
        if ($typeMappeRaw !== '') {
            $donnees['type_lieu'] = structure_sous_categorie_booking_nom_pour($typeMappeRaw) ?: $typeMappeRaw;
        } else {
            $donnees['type_lieu'] = $typeSous !== '' ? $typeSous : $typeCat;
        }
        if ($typeSous !== '') {
            $donnees['sous_categorie'] = '';
        }
        // La catégorie a-t-elle été RÉELLEMENT fournie par l'import ? (avant
        // résolution, qui applique sinon une catégorie par défaut). Sert à la
        // fusion : sans catégorie fournie, on ne doit pas la compter comme un
        // conflit face à la catégorie existante (on garde celle de la base).
        $donnees['categorie_fournie'] = trim((string) $donnees['categorie']) !== ''
            || trim((string) $donnees['sous_categorie']) !== '';
        // Catégorie de structure : racine, sinon sous-catégorie remontée à son
        // parent, sinon défaut (insensible casse + accents) — voir le helper.
        $res = structure_import_resoudre_categorie(
            $donnees['categorie'], $donnees['sous_categorie'], $typeCat, $catsRacines, $catsSub, $catDefaut
        );
        $donnees['categorie'] = $res['categorie'];
        $donnees['sous_categorie'] = $res['sous_categorie'];

        // Correspondance avec l'existant : e-mail exact (prioritaire, identifiant
        // fort) sinon nom normalisé — MAIS discriminé par la VILLE : deux
        // structures homonymes de villes différentes ne doivent JAMAIS être
        // rapprochées (sinon on fusionne à tort deux lieux distincts). Règle sur
        // le nom : ville renseignée → seule une homonyme de la même ville matche ;
        // ville absente → on ne rapproche que s'il n'y a aucune ambiguïté (un
        // seul homonyme). Via les index préchargés, sans requête par ligne.
        $correspondanceId = null;
        $email = mb_strtolower(trim($donnees['email_contact']), 'UTF-8');
        if ($email !== '' && isset($indexEmail[$email])) {
            $correspondanceId = $indexEmail[$email];
        } else {
            $nomNorm = normaliser_nom_structure($donnees['nom']);
            $villeNorm = normaliser_nom_structure((string) ($donnees['adresse_localite'] ?? ''));
            $candidats = ($nomNorm !== '') ? ($indexNom[$nomNorm] ?? []) : [];
            if ($villeNorm !== '') {
                foreach ($candidats as $cand) {
                    if ($cand['ville'] === $villeNorm) {
                        $correspondanceId = $cand['id'];
                        break;
                    }
                }
            } elseif (count($candidats) === 1) {
                $correspondanceId = $candidats[0]['id'];
            }
        }
        $structureExistante = null;
        if ($correspondanceId) {
            $stmt = db()->prepare('SELECT * FROM structures WHERE id = ?');
            $stmt->execute([$correspondanceId]);
            $structureExistante = $stmt->fetch() ?: null;
        }
        $analyse[] = [
            'index' => $index,
            'donnees' => $donnees,
            'correspondance_id' => $correspondanceId,
            'structure_existante' => $structureExistante,
        ];
    }
    return $analyse;
}

// Cœur : pour une liste de paires ['categorie' => racine, 'sous' => sous-cat],
// crée dans la taxonomie (structure_categories) les sous-catégories manquantes —
// ex. « Blog » sous « Media » — pour qu'elles figurent dans Paramètres →
// Catégories. Seulement sous une catégorie RACINE connue, sans doublon
// (insensible casse + accents ; contrainte UNIQUE(parent_id, nom)). L'ordre suit
// le max existant du même parent. Renvoie le nombre de sous-catégories créées.
function structures_taxonomie_assurer(array $paires): int
{
    $aCreer = structures_taxonomie_a_creer($paires);
    if (!$aCreer) {
        return 0;
    }
    // Ordre : à la suite des sous-catégories déjà présentes sous le même parent.
    $maxOrdre = [];
    foreach (structure_categorie_map() as $r) {
        $pid = plan_pid($r['parent_id'] ?? null);
        $maxOrdre[$pid] = max($maxOrdre[$pid] ?? 0, (int) $r['ordre']);
    }
    $ins = db()->prepare('INSERT OR IGNORE INTO structure_categories (nom, parent_id, ordre) VALUES (?, ?, ?)');
    foreach ($aCreer as $c) {
        $ordre = ($maxOrdre[$c['parent']] ?? 0) + 1;
        $maxOrdre[$c['parent']] = $ordre;
        $ins->execute([$c['nom'], $c['parent'], $ordre]);
    }
    return count($aCreer);
}

// Ce que structures_taxonomie_assurer() créerait, sans rien écrire : clé pliée
// « idParent|nom sans accents » => ['parent' => id racine, 'nom' => sous-cat,
// 'categorie' => nom de la racine]. Séparé pour que ?p=incoherences puisse MONTRER les
// manques avant de proposer de les combler.
function structures_taxonomie_a_creer(array $paires): array
{
    $map = structure_categorie_map();
    $racineId = [];   // clé pliée d'une racine → id
    $racineNom = [];  // id d'une racine → nom affichable
    $existants = [];  // "parentId|nom plié" déjà présents
    foreach ($map as $r) {
        $pid = plan_pid($r['parent_id'] ?? null);
        if ($pid === 0) {
            $racineId[texte_sans_accents((string) $r['nom'])] = (int) $r['id'];
            $racineNom[(int) $r['id']] = (string) $r['nom'];
        } else {
            $existants[$pid . '|' . texte_sans_accents((string) $r['nom'])] = true;
        }
    }
    $aCreer = [];
    foreach ($paires as $p) {
        $sous = trim((string) ($p['sous'] ?? ''));
        if ($sous === '') {
            continue;
        }
        $rid = $racineId[texte_sans_accents((string) ($p['categorie'] ?? ''))] ?? null;
        if ($rid === null) {
            continue;
        }
        $cle = $rid . '|' . texte_sans_accents($sous);
        if (isset($existants[$cle]) || isset($aCreer[$cle])) {
            continue;
        }
        $aCreer[$cle] = ['parent' => $rid, 'nom' => $sous, 'categorie' => $racineNom[$rid] ?? ''];
    }
    return $aCreer;
}

// Enregistre les sous-catégories rencontrées à l'import (paires issues de
// l'analyse). Appelé pendant l'application de l'import.
function structures_import_assurer_sous_categories(array $analyse): int
{
    $paires = array_map(
        fn ($l) => ['categorie' => $l['donnees']['categorie'] ?? '', 'sous' => $l['donnees']['sous_categorie'] ?? ''],
        $analyse
    );
    return structures_taxonomie_assurer($paires);
}

// Réparation : enregistre dans la taxonomie toutes les sous-catégories déjà
// présentes sur des structures mais absentes de structure_categories (ex.
// données importées avant l'ajout de l'enregistrement automatique). Idempotent.
function structures_taxonomie_synchroniser(?array $paires = null): int
{
    return structures_taxonomie_assurer($paires ?? structures_taxonomie_paires_utilisees());
}

// Paires (catégorie racine, sous-catégorie) réellement portées par des
// structures, avec le nombre de fiches concernées.
function structures_taxonomie_paires_utilisees(): array
{
    return db()->query(
        "SELECT categorie, sous_categorie AS sous, COUNT(*) AS nb
           FROM structures WHERE sous_categorie <> ''
          GROUP BY categorie, sous_categorie
          ORDER BY categorie, sous_categorie"
    )->fetchAll(PDO::FETCH_ASSOC);
}

// Sous-catégories vues sur des structures mais absentes de la taxonomie — la
// détection derrière la section « Sous-catégories non déclarées » de ?p=incoherences.
// Chaque entrée : ['cle' => clé stable pour la sélection, 'categorie', 'nom',
// 'nb' => structures concernées].
function structures_taxonomie_manquantes(): array
{
    $utilisees = structures_taxonomie_paires_utilisees();
    $nbParPaire = [];
    foreach ($utilisees as $p) {
        $nbParPaire[(string) $p['categorie'] . "\0" . (string) $p['sous']] = (int) $p['nb'];
    }
    $manques = [];
    foreach (structures_taxonomie_a_creer($utilisees) as $c) {
        $manques[] = [
            'cle'       => structures_taxonomie_cle((string) $c['categorie'], (string) $c['nom']),
            'categorie' => (string) $c['categorie'],
            'nom'       => (string) $c['nom'],
            'nb'        => $nbParPaire[(string) $c['categorie'] . "\0" . (string) $c['nom']] ?? 0,
        ];
    }
    return $manques;
}

// Clé d'une paire dans les cases à cocher de ?p=incoherences. Encodée : un nom de
// catégorie peut contenir n'importe quel caractère, séparateur compris.
function structures_taxonomie_cle(string $categorie, string $sous): string
{
    return base64_encode($categorie . "\0" . $sous);
}

// Paires correspondant aux clés cochées, RECONFRONTÉES à la détection : une clé
// forgée ou périmée ne crée rien (même principe que doublons_potentiels_*).
function structures_taxonomie_lire_cles(array $cles): array
{
    $valides = [];
    foreach (structures_taxonomie_manquantes() as $m) {
        $valides[$m['cle']] = ['categorie' => $m['categorie'], 'sous' => $m['nom']];
    }
    $paires = [];
    foreach ($cles as $cle) {
        if (isset($valides[(string) $cle])) {
            $paires[] = $valides[(string) $cle];
        }
    }
    return $paires;
}

// Champs d'une structure fusionnés à l'import (colonne DB => libellé). Le nom
// est inclus (une correspondance par e-mail peut avoir un nom différent). Clé =
// nom de colonne réel (whitelist : sûr à interpoler dans l'UPDATE dynamique).
const STRUCTURE_IMPORT_FUSION_CHAMPS = [
    'nom'              => 'Nom',
    'categorie'        => 'Catégorie',
    'sous_categorie'   => 'Sous-catégorie',
    'adresse_rue'      => 'Rue',
    'adresse_npa'      => 'NPA',
    'adresse_localite' => 'Localité',
    'departement_canton' => 'Département / canton',
    'grande_region'    => 'Région',
    'adresse_pays'     => 'Pays',
    'site_web'         => 'Site web',
    'via'              => 'Via',
    'notes'            => 'Remarques',
];

// Compare une fiche existante (ligne DB) aux données importées, champ par champ.
// Renvoie ['remplissages' => [colonne => valeur], 'conflits' => [colonne =>
// ['label','actuel','importe']]] :
//   • import vide           → ignoré (on garde la base) ;
//   • base vide, import plein → remplissage (à appliquer d'office) ;
//   • égaux                 → ignoré ;
//   • les deux pleins, différents → conflit (choix utilisateur).
// La colonne DB adresse_pays correspond à la clé 'pays' des données importées.
function structure_import_fusion(array $existante, array $donnees): array
{
    $imp = [
        'nom'              => (string) ($donnees['nom'] ?? ''),
        'categorie'        => (string) ($donnees['categorie'] ?? ''),
        'sous_categorie'   => (string) ($donnees['sous_categorie'] ?? ''),
        'adresse_rue'      => (string) ($donnees['adresse_rue'] ?? ''),
        'adresse_npa'      => (string) ($donnees['adresse_npa'] ?? ''),
        'adresse_localite' => (string) ($donnees['adresse_localite'] ?? ''),
        'departement_canton' => (string) ($donnees['departement_canton'] ?? ''),
        'grande_region'    => (string) ($donnees['grande_region'] ?? ''),
        'adresse_pays'     => (string) ($donnees['pays'] ?? ''),
        'site_web'         => (string) ($donnees['site_web'] ?? ''),
        'via'              => (string) ($donnees['via'] ?? ''),
        'notes'            => (string) ($donnees['notes'] ?? ''),
    ];
    // Catégorie/sous-catégorie non fournies par l'import → à traiter comme vides
    // (garder la base), sinon la catégorie par défaut créerait un faux conflit.
    if (empty($donnees['categorie_fournie'])) {
        $imp['categorie'] = '';
        $imp['sous_categorie'] = '';
    }
    $remplissages = [];
    $conflits = [];
    foreach (STRUCTURE_IMPORT_FUSION_CHAMPS as $col => $label) {
        $db = trim((string) ($existante[$col] ?? ''));
        $iv = trim($imp[$col]);
        if ($iv === '' || $iv === $db) {
            continue;
        }
        if ($db === '') {
            $remplissages[$col] = $iv;
        } else {
            $conflits[$col] = ['label' => $label, 'actuel' => $db, 'importe' => $iv];
        }
    }
    return ['remplissages' => $remplissages, 'conflits' => $conflits];
}

// Parmi les lignes analysées, celles qui correspondent à une fiche existante ET
// présentent au moins un conflit de champ (à trancher par l'utilisateur).
// Chaque entrée = la ligne + 'conflits' (cf. structure_import_fusion).
function structures_import_conflits(array $analyse): array
{
    $out = [];
    foreach ($analyse as $l) {
        if ($l['correspondance_id'] === null) {
            continue;
        }
        $existante = is_array($l['structure_existante'] ?? null) ? $l['structure_existante'] : [];
        $fusion = structure_import_fusion($existante, $l['donnees']);
        if ($fusion['conflits']) {
            $l['conflits'] = $fusion['conflits'];
            $out[] = $l;
        }
    }
    return $out;
}

// Applique l'import : chaque ligne est insérée si sans correspondance, sinon
// FUSIONNÉE champ par champ avec la fiche existante ($choix : index →
// [colonne => true] = prendre l'import pour un champ en conflit ; défaut =
// garder l'actuel ; jamais d'écrasement global). Retourne un résumé.
function structures_appliquer_import(array $analyse, array $choix): array
{
    $resume = ['nouvelles' => 0, 'mises_a_jour' => 0, 'ignorees' => 0, 'sous_categories' => 0];

    // Enregistre d'abord les sous-catégories nouvelles dans la taxonomie (pour
    // qu'elles figurent dans Paramètres → Catégories).
    $resume['sous_categories'] = structures_import_assurer_sous_categories($analyse);

    foreach ($analyse as $ligne) {
        structure_import_appliquer_structure($ligne, $choix, $resume);
    }
    return $resume;
}

// Insère (si nouvelle) ou FUSIONNE champ par champ (si déjà présente) la
// structure d'une ligne d'import, puis y rattache contact/étiquettes/note.
// Pour une fiche existante : remplissage des champs vides + application des
// choix de conflit ($choix[index][colonne] = prendre l'import), défaut =
// garder l'actuel. Renvoie l'id de la structure.
function structure_import_appliquer_structure(array $ligne, array $choix, array &$resume): ?int
{
    $d = $ligne['donnees'];
    $pays = $d['pays'] !== '' ? $d['pays'] : 'Suisse';
    $majLe = structure_date_csv_vers_iso($d['mise_a_jour']) ?? '';
    if ($ligne['correspondance_id'] === null) {
        // Grande région déduite du département/canton importé (France/Suisse
        // hors cantons bilingues) : remplace toute valeur CSV mappée sur
        // grande_region quand le département/canton est reconnu ; sinon
        // comportement actuel (valeur CSV acceptée telle quelle).
        $grandeRegionDeduite = grande_region_deduite($pays, $d['departement_canton']);
        if ($grandeRegionDeduite !== null) {
            $d['grande_region'] = $grandeRegionDeduite;
        }
        db()->prepare(
            'INSERT INTO structures (nom, categorie, sous_categorie, adresse_rue, adresse_npa, adresse_localite, departement_canton, grande_region, adresse_pays,
                                      site_web, via, notes, mise_a_jour_le, statut)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'actif\')'
        )->execute([
            $d['nom'], $d['categorie'], $d['sous_categorie'], $d['adresse_rue'], $d['adresse_npa'], $d['adresse_localite'],
            $d['departement_canton'], $d['grande_region'], $pays, $d['site_web'], $d['via'], $d['notes'], $majLe,
        ]);
        $structureId = (int) db()->lastInsertId();
        $resume['nouvelles']++;
        $grandeRegionFinale = $d['grande_region'];
    } else {
        // Fiche déjà présente : FUSION champ par champ (jamais d'écrasement
        // global). Remplissage des champs vides côté base + application des
        // choix de conflit ($choix[index][colonne] présent = prendre l'import),
        // défaut = garder l'actuel. Seuls les « prendre l'import » sont postés
        // (cases cochées) → borne PHP max_input_vars. Si rien ne change, la
        // fiche est comptée « ignorée » (aucune écriture).
        $structureId = (int) $ligne['correspondance_id'];
        $existante = is_array($ligne['structure_existante'] ?? null) ? $ligne['structure_existante'] : [];
        $fusion = structure_import_fusion($existante, $d);
        $maj = $fusion['remplissages'];
        foreach ($fusion['conflits'] as $col => $info) {
            if (!empty($choix[$ligne['index']][$col])) {
                $maj[$col] = $info['importe'];
            }
        }
        // Le pays effectif de la fiche (pour la taxonomie des régions) : import
        // s'il est renseigné, sinon celui déjà en base.
        $pays = $d['pays'] !== '' ? $d['pays'] : (string) ($existante['adresse_pays'] ?? '');
        // Grande région déduite du département/canton EFFECTIF (celui retenu
        // par la fusion, pas forcément celui du CSV) — seulement si elle
        // diffère de la valeur déjà en base, pour ne pas ajouter une écriture
        // inutile quand tout est déjà cohérent.
        $departementCantonEffectif = array_key_exists('departement_canton', $maj) ? $maj['departement_canton'] : (string) ($existante['departement_canton'] ?? '');
        $grandeRegionDeduite = grande_region_deduite($pays, $departementCantonEffectif);
        if ($grandeRegionDeduite !== null && $grandeRegionDeduite !== (string) ($existante['grande_region'] ?? '')) {
            $maj['grande_region'] = $grandeRegionDeduite;
        }
        if ($maj) {
            // Colonnes issues d'une whitelist (STRUCTURE_IMPORT_FUSION_CHAMPS) →
            // interpolation sûre.
            $sets = [];
            $vals = [];
            foreach ($maj as $col => $v) {
                $sets[] = "$col = ?";
                $vals[] = $v;
            }
            if ($majLe !== '') {
                $sets[] = 'mise_a_jour_le = ?';
                $vals[] = $majLe;
            }
            $vals[] = $structureId;
            db()->prepare('UPDATE structures SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
            $resume['mises_a_jour']++;
        } else {
            $resume['ignorees']++;
        }
        // $d['grande_region'] mis à jour vers la valeur effective (déduite ou
        // fusionnée) : structure_lier_lieu_importe() ci-dessous lit $d, pas $maj.
        $grandeRegionFinale = array_key_exists('grande_region', $maj) ? $maj['grande_region'] : (string) ($existante['grande_region'] ?? '');
        $d['grande_region'] = $grandeRegionFinale;
    }

    // La région (grande_region effective, déduite ou importée) rejoint la
    // taxonomie sous le pays de la fiche si elle en est absente — la liste
    // reste stricte dans les formulaires, mais l'import peut l'enrichir (le
    // lieu auto éventuel réutilise la même).
    pays_region_assurer($pays, (string) $grandeRegionFinale);

    structure_import_attacher_contact($structureId, $d);
    foreach (structure_tags_depuis_statut($d['tags_statut']) as $nomTag) {
        structure_attacher_tag($structureId, $nomTag);
    }

    // Application des champs « booking » (sous-catégorie, jauge, mois, dernier
    // concert) directement sur LA STRUCTURE elle-même (voir
    // structure_appliquer_champs_booking_importe(), migration_59/60) : le type
    // de lieu résolu doit être un VRAI type de la taxonomie booking (ex.
    // « Salle de location »). Les médias et l'entourage n'appliquent jamais
    // ces champs (« Radio », « Journaliste »… n'en sont pas).
    if (structure_sous_categorie_booking_nom_pour((string) ($d['type_lieu'] ?? '')) !== '') {
        structure_appliquer_champs_booking_importe($structureId, $d);
    }

    $dateContact = structure_date_csv_vers_iso($d['dernier_contact']);
    if ($dateContact !== null) {
        journaliser_contact_import($structureId, $dateContact, 'Import CSV — dernier contact connu.');
    }
    structure_recalculer_dernier_contact($structureId);
    return $structureId;
}

// Rattache le contact d'une ligne d'import à une structure (dédoublonnage par
// e-mail, insensible à la casse). Ne fait rien si la ligne n'a aucun contact.
function structure_import_attacher_contact(int $structureId, array $d): void
{
    if ($d['prenom'] === '' && $d['nom_contact'] === '' && $d['email_contact'] === '' && $d['telephone'] === '') {
        return;
    }
    if ($d['email_contact'] !== '') {
        $stmt = db()->prepare('SELECT 1' . CONTACT_FROM . ' WHERE cs.structure_id = ? AND pe.email = ? COLLATE NOCASE');
        $stmt->execute([$structureId, $d['email_contact']]);
        if ($stmt->fetchColumn()) {
            return;
        }
    }
    // Liste « ne pas contacter » : un e-mail exclu peut être (ré)importé comme
    // contact, mais naît désinscrit — l'exclusion n'est jamais contournée.
    $desinscrit = ($d['email_contact'] !== '' && mailing_email_exclu($d['email_contact'])) ? 1 : 0;
    $cid = contact_creer($structureId, [
        'prenom'         => $d['prenom'],
        'nom'            => $d['nom_contact'],
        'email'          => $d['email_contact'],
        'telephone'      => $d['telephone'],
        'langue'         => $d['langue'],
        'role'           => $d['role'],
        'formulaire_url' => $d['formulaire_url'],
    ]);
    if ($desinscrit) {
        $c = contact_charger($cid);
        db()->prepare('UPDATE personnes SET desinscrit = 1 WHERE id = ?')->execute([(int) $c['personne_id']]);
    }
}

// Type d'un lieu à l'import : si une catégorie de lieu a été détectée (colonne
// catégorie/sous-catégorie), on la prend telle quelle ; sinon on déduit du nom
// (« festival » dans le nom → Festival, à défaut Salle).
function structure_import_type_lieu(string $nom, string $typeLieu): string
{
    if (trim($typeLieu) !== '') {
        // Normalise vers l'intitulé canonique de la taxonomie booking (tolère
        // casse et accents, ex. « salle de location » → « Salle de location ») ;
        // conserve la valeur nettoyée si elle n'y figure pas (type libre).
        $reconnu = structure_sous_categorie_booking_nom_pour($typeLieu);
        return $reconnu !== '' ? $reconnu : trim($typeLieu);
    }
    return str_contains(mb_strtolower($nom, 'UTF-8'), 'festival') ? 'Festival' : 'Salle';
}

// Applique les champs « booking » (sous-catégorie déduite, jauge, mois de
// programmation/d'événement, dernier concert) directement sur la structure
// importée — remplace l'ancienne création d'un lieu séparé
// (structure_import_creer_lieu()/structure_lier_lieu_importe()) : depuis la
// fusion lieux→structures (migration_59/60), un lieu EST une structure, plus
// besoin d'une fiche à part ni de structure_lieux. Remplissage des champs
// vides uniquement (jamais d'écrasement, même politique que
// structure_import_fusion()) ; dernier_concert_le prend le MAXIMUM des deux
// valeurs (chaîne ISO, comparable lexicalement) pour ne jamais perdre une
// date plus récente déjà connue.
function structure_appliquer_champs_booking_importe(int $structureId, array $d): void
{
    $stmt = db()->prepare(
        'SELECT sous_categorie, dernier_concert_le FROM structures WHERE id = ?'
    );
    $stmt->execute([$structureId]);
    $s = $stmt->fetch();
    if (!$s) {
        return;
    }

    $sousCategorie = trim((string) $s['sous_categorie']) === ''
        ? structure_import_type_lieu((string) $d['nom'], (string) ($d['type_lieu'] ?? ''))
        : (string) $s['sous_categorie'];

    $jaugeMin = $d['jauge_min'] !== '' ? (int) $d['jauge_min'] : null;
    $jaugeMax = $d['jauge_max'] !== '' ? (int) $d['jauge_max'] : null;
    $evt = mois_plage_depuis_liste($d['periode_evenement'] ?? '');
    $prog = mois_plage_depuis_liste($d['periode_programmation'] ?? '');
    $ancienDC = (string) $s['dernier_concert_le'];
    $dernierConcert = max($ancienDC, structure_date_csv_vers_iso($d['dernier_concert'] ?? '') ?? '');

    db()->prepare(
        'UPDATE structures SET
            sous_categorie = ?,
            jauge_min = COALESCE(jauge_min, ?),
            jauge_max = COALESCE(jauge_max, ?),
            mois_debut = COALESCE(mois_debut, ?),
            mois_fin = COALESCE(mois_fin, ?),
            mois_evenement_debut = COALESCE(mois_evenement_debut, ?),
            mois_evenement_fin = COALESCE(mois_evenement_fin, ?),
            dernier_concert_le = ?
         WHERE id = ?'
    )->execute([
        $sousCategorie, $jaugeMin, $jaugeMax, $prog['debut'], $prog['fin'], $evt['debut'], $evt['fin'],
        $dernierConcert, $structureId,
    ]);

    if ($dernierConcert !== '' && $dernierConcert !== $ancienDC) {
        journaliser('structure', $structureId, 'dernier_concert', 'Dernier concert / diffusion (import) : ' . $dernierConcert, $dernierConcert);
    }
}
