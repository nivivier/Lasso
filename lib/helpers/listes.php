<?php
// Ce qu'une LISTE sait faire : filtrer, trier, paginer, chercher — extrait de
// lib/helpers.php sans rien changer à son comportement.
//
// Toutes les listes de l'application se tiennent par ces fonctions, et c'est
// ce qui fait qu'elles se manipulent pareil : le même entonnoir de colonne, le
// même tri mémorisé en session, la même pagination (client en deçà d'un seuil,
// serveur au-delà), la même recherche insensible aux accents.
//
// ⚠️ Chargé par lib/helpers.php, jamais requis directement.

// Reporte un tableau de paramètres GET (filtres actuels, souvent construit
// avec filtre_coche()) dans un formulaire, sous forme de <input type="hidden">
// — une paire nom[]=valeur par élément pour un filtre multi-valeurs (tableau),
// une seule nom=valeur sinon ; jamais (string) $v directement, qui produirait
// "Array" + un avertissement PHP. Repris à l'identique par tous les
// formulaires qui doivent survivre à une soumission sans perdre les filtres
// déjà actifs (dropdown de colonne, bannière de géocodage, pagination) : un
// seul point de vérité pour ce pattern. redirect() plus haut a une logique
// très proche mais produit une query string (urlencode), pas du HTML échappé
// — volontairement resté séparé plutôt que forcé dans cette fonction.
function hidden_inputs_html(array $params): string
{
    $h = '';
    foreach ($params as $nom => $valeur) {
        foreach ((array) $valeur as $vv) {
            $h .= '<input type="hidden" name="' . e(is_array($valeur) ? $nom . '[]' : (string) $nom) . '" value="' . e((string) $vv) . '">';
        }
    }
    return $h;
}

// Fabrique une closure $autresFiltres('champ') => tous les $tousFiltres SAUF
// celui nommé, sans valeurs vides (array_filter) — pour reporter dans un
// panneau de filtre de colonne (filtre_colonne_html()) les AUTRES filtres
// actuellement actifs de la page, sans celui-là (qui a déjà son propre état
// posté par ce panneau-là). Usage : $autresFiltres = autres_filtres_fn([
// 'annee' => $annee, 'categorie' => $categorieFilter, ...]); puis
// $autresFiltres('annee') à chaque filtre_colonne_html() — évite d'écrire à
// la main, pour chaque filtre, un littéral listant tous les autres.
function autres_filtres_fn(array $tousFiltres): Closure
{
    return fn (string $cle): array => array_filter(array_diff_key($tousFiltres, [$cle => true]));
}

// Filtre persistant entre requêtes (listes avec filtres : fiches, écritures,
// factures…) : priorité au paramètre GET (et mémorisé en session pour les
// navigations suivantes, ex. retour depuis une fiche), sinon dernière valeur
// en session, sinon défaut. $cleSession est généralement préfixée par l'écran
// (« fiches_annee », « ecr_annee »…) pour ne pas mélanger les filtres entre
// pages qui partagent un même nom de paramètre GET (ex. « annee »).
function filtre_persistant(string $cleGet, string $cleSession, $defaut)
{
    if (isset($_GET[$cleGet])) {
        $_SESSION[$cleSession] = $_GET[$cleGet];
    }
    return $_SESSION[$cleSession] ?? $defaut;
}

// --- La vue deux colonnes (?vue=duo) ---------------------------------------
//
// Six écrans l'offrent — structures, personnes, employés, fiches, factures,
// événements — et ils faisaient six fois le même geste, à douze lignes pièce :
// lire la vue mémorisée, prendre ?sel=, retomber sur la première ligne quand
// l'identifiant ne figure pas dans la page, charger la fiche. Six copies d'un
// même geste finissent par ne plus dire la même chose ; en voici une seule.
// Le gabarit des deux volets, lui, vit dans views/_duo.php.

// La vue d'une liste, mémorisée en session comme ses filtres. Tout ce qui
// n'est pas « duo » (ni l'un des $autres — « carte », pour les structures et
// les événements) retombe sur la liste : la valeur vient de l'URL, elle peut
// dire n'importe quoi.
function vue_liste(string $cleSession, array $autres = []): string
{
    $vue = (string) filtre_persistant('vue', $cleSession, 'liste');
    return in_array($vue, array_merge(['duo'], $autres), true) ? $vue : 'liste';
}

// La fiche du volet droit : celle que désigne ?sel=, à défaut la première de
// la page affichée. $donnees est la fonction qui charge une fiche entière
// (employe_donnees_vue() et ses pareilles) — la MÊME que la page pleine, pour
// que les deux volets ne puissent pas diverger.
//
// Rend les données de la fiche plus 'duoSel' (0 quand il n'y a rien à
// montrer : liste vide, ou fiche introuvable — supprimée entre deux clics).
function duo_selection(string $vue, array $lignes, callable $donnees): array
{
    if ($vue !== 'duo' || !$lignes) {
        return ['duoSel' => 0];
    }
    $ids = array_map('intval', array_column($lignes, 'id'));
    $selId = (int) ($_GET['sel'] ?? 0);
    if (!in_array($selId, $ids, true)) {
        $selId = (int) $ids[0];
    }
    $fiche = $donnees($selId);
    return $fiche === null ? ['duoSel' => 0] : $fiche + ['duoSel' => $selId];
}

// --- Tri par colonne d'une liste -------------------------------------------
//
// Six listes le portent : structures, fiches, employés, événements, factures,
// écritures. Ce sont les seules paginées côté serveur, donc les seules où un
// tri ait un enjeu — trier la page qu'on a sous les yeux, sur trente pages,
// dirait n'importe quoi.
//
// Le tri est donc SQL, et mémorisé en session comme les filtres et la taille de
// page : il survit à un changement de page, de filtre ou de recherche. Deux
// paramètres, `tri` (la clé de colonne) et `sens` (asc|desc).
//
// $colonnes est une liste blanche « clé => expression SQL », l'expression
// pouvant être un TABLEAU quand la colonne se trie sur plusieurs champs (nom
// puis prénom). La clé seule voyage dans l'URL ; l'expression n'en vient
// JAMAIS. C'est ce qui permet d'écrire l'ORDER BY par concaténation sans ouvrir
// une injection.
//
// Le sens est appliqué à CHAQUE terme, pas au dernier : « ORDER BY nom, prenom
// DESC » trie les noms en croissant et ne renverse que les prénoms — une erreur
// silencieuse, la liste ayant l'air triée.
//
// Renvoie ['cle', 'sens', 'sql'] — 'sql' vide quand aucun tri n'est choisi,
// l'appelant garde alors l'ordre naturel de sa liste.
function tri_colonne(string $cleSession, array $colonnes): array
{
    $cle = (string) filtre_persistant('tri', $cleSession . '_tri', '');
    $sens = (string) filtre_persistant('sens', $cleSession . '_sens', 'asc');
    if (!isset($colonnes[$cle])) {
        $cle = '';
    }
    if ($sens !== 'desc') {
        $sens = 'asc';
    }
    $sql = '';
    if ($cle !== '') {
        $dir = $sens === 'desc' ? ' DESC' : ' ASC';
        $sql = ' ORDER BY ' . implode(', ', array_map(
            fn (string $expr) => $expr . $dir,
            (array) $colonnes[$cle]
        ));
    }
    return ['cle' => $cle, 'sens' => $sens, 'sql' => $sql];
}

// L'en-tête cliquable d'une colonne triable. Trois états qui s'enchaînent au
// clic : croissant, décroissant, puis retour à l'ordre naturel de la liste —
// sans ce troisième, on ne pourrait plus jamais retrouver l'ordre d'origine
// (la date la plus récente en tête, par exemple) une fois qu'on a trié.
//
// La flèche ne s'affiche que sur la colonne active : une flèche pâle sur chaque
// en-tête ferait une rangée de chevrons là où il n'y a qu'une information.
// C'est le survol qui révèle qu'une colonne est cliquable.
//
// $autresParams : tout ce que la page doit emporter avec elle (filtres actifs,
// recherche, id…), comme pour les panneaux de filtre — un lien ne connaît que
// ce qu'on lui écrit.
function tri_entete_html(string $page, string $cle, string $libelle, array $tri, array $autresParams = []): string
{
    $actif = $tri['cle'] === $cle;
    $suivant = !$actif ? ['tri' => $cle, 'sens' => 'asc']
             : ($tri['sens'] === 'asc' ? ['tri' => $cle, 'sens' => 'desc'] : ['tri' => '', 'sens' => 'asc']);
    // `tri` vide et non absent : filtre_persistant() n'écrase la session que si
    // la clé est PRÉSENTE en GET. Absente, on retomberait sur le tri mémorisé.
    $url = '?' . http_build_query(['p' => $page] + $suivant + array_filter($autresParams, fn ($v) => $v !== '' && $v !== []));
    $titre = !$actif ? 'Trier par « ' . strip_tags($libelle) . ' »'
           : ($tri['sens'] === 'asc' ? 'Trier en ordre décroissant' : "Revenir à l'ordre par défaut");
    return '<a class="col-tri' . ($actif ? ' on' : '') . '" href="' . e($url) . '" title="' . e($titre) . '">'
        . $libelle
        . ($actif ? icon($tri['sens'] === 'asc' ? 'chevron-up' : 'chevron-down') : '')
        . '</a>';
}

// --- Pagination générique (listes potentiellement longues : fiches, écritures,
// factures, débiteurs, employés, événements) -------------------------------

const PAGINATION_TAILLES = [25, 50, 100, 200];
const PAGINATION_TAILLE_DEFAUT = 100;

// Seuil (nombre total d'éléments, filtres structurés déjà appliqués mais
// recherche texte exclue) en dessous duquel une liste passe en mode "client" :
// toutes les lignes sont chargées d'un coup et la pagination + la recherche se
// font entièrement en JS (lassoListeClient(), assets/app.js + _pagination_client.php),
// sans aller-retour serveur — pour du texte pur, un aller-retour par frappe est
// inutile en dessous de ce volume. Au-delà, voir lassoRechercheServeur() +
// _pagination.php (aller-retour serveur, pagination + LIMIT SQL).
// Valeur PAR DÉFAUT du seuil ; la valeur effective se règle dans
// Paramètres → Serveur (voir pagination_seuil_client()). Elle vit en base parce
// que le bon réglage dépend des données et du matériel, pas du code : sur les
// 2 965 structures, le mode client tient en 207 Ko compressés, 268 ms de
// chargement et 21 ms par frappe — mais 89 600 nœuds DOM, ce qui peut être trop
// pour un appareil modeste. Se règle sans redéploiement.
const PAGINATION_SEUIL_CLIENT = 4000;

// Borne haute du réglage : au-delà, le navigateur garderait en mémoire un DOM
// que même une machine de bureau peinerait à tenir. Ce n'est pas une limite
// mesurée au cordeau, c'est un garde-fou contre une saisie absurde.
const PAGINATION_SEUIL_MAX = 20000;

// Seuil effectif : réglage enregistré, sinon le défaut ci-dessus. Borné des deux
// côtés — 0 force toutes les listes côté serveur, ce qui est un choix légitime.
function pagination_seuil_client(): int
{
    $v = param('pagination_seuil_client', '');
    $v = $v === '' ? PAGINATION_SEUIL_CLIENT : (int) $v;
    return max(0, min(PAGINATION_SEUIL_MAX, $v));
}

// true si le total (filtres structurés, hors recherche texte) tient sous le
// seuil client — décide, pour chaque route de liste, si elle doit charger
// toutes les lignes (mode client) ou paginer/rechercher côté serveur.
function pagination_mode_client(int $totalSansRecherche): bool
{
    return $totalSansRecherche <= pagination_seuil_client();
}

// Nombre de lignes par page : GET prioritaire (et mémorisé en session, comme
// filtre_persistant()) sinon 100 par défaut. Bornée à PAGINATION_TAILLES pour
// qu'une valeur arbitraire dans l'URL ne force pas une requête énorme.
function pagination_taille(string $cleSession): int
{
    $t = (int) filtre_persistant('taille', $cleSession, PAGINATION_TAILLE_DEFAUT);
    return in_array($t, PAGINATION_TAILLES, true) ? $t : PAGINATION_TAILLE_DEFAUT;
}

// Page courante (1-based). Jamais mémorisée en session (contrairement aux
// filtres) : sinon, changer d'année/statut pourrait rouvrir sur une page qui
// n'existe plus pour le nouveau résultat filtré.
function pagination_page(): int
{
    $p = (int) ($_GET['page'] ?? 1);
    return $p > 0 ? $p : 1;
}

// Clause SQL " LIMIT ? OFFSET ?" + les deux valeurs à ajouter à $params, dans
// l'ordre — évite de recalculer l'offset à chaque route.
function pagination_sql(int $page, int $taille): array
{
    return [' LIMIT ? OFFSET ?', [$taille, ($page - 1) * $taille]];
}

// Numéros de page affichés autour de la page courante (± 1) + toujours la
// première et la dernière, « … » (chaîne, pas de sens numérique) pour les
// trous — évite d'afficher 50+ numéros pour une grosse pagination. Mêmes
// règles reprises côté JS pour le mode client (assets/app.js, pagesAffichees()
// dans lassoListeClient()) : les deux doivent rester synchronisées à la main,
// pas de logique partagée possible entre PHP (rendu serveur) et JS (rendu
// dans le navigateur, listes sous PAGINATION_SEUIL_CLIENT).
function pagination_pages_affichees(int $page, int $nbPages): array
{
    if ($nbPages <= 7) {
        return range(1, $nbPages);
    }
    $brut = array_unique([1, 2, $page - 1, $page, $page + 1, $nbPages - 1, $nbPages]);
    $brut = array_values(array_filter($brut, fn ($p) => $p >= 1 && $p <= $nbPages));
    sort($brut);

    $out = [];
    $prec = null;
    foreach ($brut as $p) {
        if ($prec !== null && $p - $prec > 1) {
            $out[] = '…';
        }
        $out[] = $p;
        $prec = $p;
    }
    return $out;
}

// Minuscule + repli des accents sur la lettre de base (é→e, ç→c…), en gardant
// espaces et ponctuation — pour un rapprochement lisible d'intitulés à l'import
// (ex. « Média » ↔ « Media »). Différent de normaliser_nom_structure()
// (lib/booking.php) qui, lui, SUPPRIME accents ET ponctuation (pour comparer
// des noms propres).
//
// Vit ici et non dans lib/booking.php (son emplacement d'origine) parce que
// lib/db.php l'expose à SQLite sous le nom SANS_ACCENTS() pour la recherche
// unifiée : db.php est chargé bien avant booking.php, qui n'accompagne que le
// module booking.
function texte_sans_accents(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    return strtr($s, [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae', 'ÿ' => 'y',
    ]);
}

// Échappe % / _ / \ pour un motif LIKE sûr (à utiliser avec ESCAPE '\\') —
// sinon un utilisateur tapant "%" ou "_" dans une recherche déclencherait un
// joker SQL au lieu d'un caractère littéral. $terme : déjà sans les % de
// bordure, ajoutés par l'appelant ('%' . like_echappe($terme) . '%').
function like_echappe(string $terme): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $terme);
}

// Recherche texte serveur (paginée) : lit ?q=, jamais mémorisée en session
// (comme pagination_page() — une recherche laissée dans la session surprendrait
// à la prochaine visite). $colonnes : expressions SQL (déjà qualifiées si besoin,
// ex. 'e.nom') unies en OR ... LIKE. Retourne [$sqlFragment, $params] à ajouter
// au WHERE ; $sqlFragment est '' si aucune recherche (rien à ajouter).
function recherche_sql(array $colonnes): array
{
    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q === '') {
        return ['', []];
    }
    $motif = '%' . like_echappe($q) . '%';
    $sql = ' AND (' . implode(' OR ', array_map(fn($c) => "$c LIKE ? ESCAPE '\\'", $colonnes)) . ')';
    return [$sql, array_fill(0, count($colonnes), $motif)];
}

// Lecture d'un filtre multi-valeurs (cases à cocher) porté par une colonne :
// GET prioritaire (marqueur <champ>_set toujours présent quand le panneau est
// soumis, même si aucune case n'est cochée — sinon impossible de distinguer
// « toutes les cases décochées » de « rien dans l'URL, retomber sur la
// session ») sinon dernière valeur en session. $valeurs : whitelist connue
// (ex. statut) → intersection ; sinon $texteLibre=false attend des ids
// numériques (annee/employe_id/compte, intval + valeurs nulles écartées, 0
// n'étant jamais un id valide) ; $texteLibre=true conserve les valeurs telles
// quelles (catégorie/axe : mélange d'ids numériques et de sentinelles
// textuelles comme "a_lettrer"/"sans_axe", intval() les corromprait). $defaut :
// valeur de tout premier affichage (session jamais touchée, ex. structures —
// "actif"/"contact_privilegie" pour masquer par défaut le bruit inactif/ne
// pas contacter) ; une fois la session touchée une seule fois (même pour
// tout décocher), $defaut ne revient plus jamais — comme filtre_persistant().
function filtre_coche(string $cle, string $cleSession, ?array $valeurs = null, bool $texteLibre = false, array $defaut = []): array
{
    $normaliser = function (array $brut) use ($valeurs, $texteLibre): array {
        if ($valeurs !== null) {
            return array_values(array_intersect($brut, $valeurs));
        }
        if ($texteLibre) {
            return array_values(array_unique(array_filter(array_map('strval', $brut), fn($v) => $v !== '')));
        }
        return array_values(array_filter(array_map('intval', $brut)));
    };
    if (isset($_GET[$cle . '_set'])) {
        $v = $normaliser((array) ($_GET[$cle] ?? []));
        $_SESSION[$cleSession] = $v;
        return $v;
    }
    if (!isset($_SESSION[$cleSession])) {
        return $defaut;
    }
    return $normaliser((array) $_SESSION[$cleSession]);
}

// L'adresse d'une liste filtrée, pour un lien posé AILLEURS que sur la liste
// elle-même : une carte du tableau de bord, un compte dans un tableau, un
// décompte à côté d'un projet.
//
// ⚠️ C'est le seul moyen correct d'écrire un tel lien. Les filtres de colonne
// sont des filtre_coche() : ils ne lisent `$_GET` que si le marqueur
// « <clé>_set » l'accompagne, et retombent sinon sur la SESSION. Un lien écrit
// « ?p=evenements&statut=annule » n'a donc aucun effet — il rouvre la
// liste telle qu'on l'avait laissée, en promettant autre chose. L'erreur a été
// faite deux fois, et elle ne se voit pas à la lecture du lien : la page
// s'ouvre, elle montre simplement les mauvaises lignes.
//
// $filtres : [clé => valeur | [valeurs]]. Chaque clé citée est POSÉE, donc un
// tableau vide efface le filtre (« tous ») — ce qui est la seule façon de
// garantir ce que le lien va montrer, quoi qu'on ait coché avant. Une clé
// absente de $filtres garde ce qu'elle avait.
// $params : les paramètres ordinaires de la page (q, vue, depuis…), tels quels.
function lien_liste_filtree(string $route, array $filtres, array $params = []): string
{
    $qs = ['p' => $route];
    foreach ($filtres as $cle => $valeurs) {
        $qs[$cle] = array_values(array_map('strval', (array) $valeurs));
        $qs[$cle . '_set'] = 1;
    }
    return '?' . http_build_query($qs + $params);
}

// Filtre de colonne à cases à cocher (EXPÉRIMENTAL, ?p=fiches — Paiement/
// Date/Employé) : bouton entonnoir + panneau (voir .col-filter* dans
// assets/css/), plutôt qu'un <select> dans la barre d'outils, pour que
// le filtre reste visuellement rattaché à la colonne qu'il filtre. $page :
// route courante (p=…), sinon ce <form> sans action= soumettrait sans
// paramètre 'p' et retomberait sur la route par défaut de l'appli au lieu
// de rester sur la page filtrée. $champ : nom du paramètre GET, envoyé en
// tableau (ex. 'statut' → statut[]). $options : [valeur => libellé].
// $actives : sous-ensemble actuellement coché de $options. $autresParams :
// les AUTRES filtres de la page (jamais celui-ci) à reporter en hidden
// inputs pour ne pas les perdre en soumettant ce panneau — valeurs
// scalaires ou tableaux, sérialisées à l'identique.
// Tranches d'ancienneté d'une date, partagées par les filtres « Dernière
// modification » et « Dernier contact » (?p=structures).
//
// Volontairement DISJOINTES (« cette semaine » exclut les dernières 24 h, etc.)
// et non emboîtées : le widget de filtre est une liste de cases à cocher unies
// en OU, or avec des tranches emboîtées cocher « Cette année » et « Ce mois »
// donnerait exactement le même résultat que « Cette année » seule — les cases
// ne voudraient plus rien dire. Disjointes, chaque combinaison a un sens.
//
// « Jamais » n'est pas un ornement : sur les structures, 73 % des dates de
// modification et 78 % des dates de contact sont vides. Sans cette tranche,
// cocher toutes les autres masquerait la majorité des fiches sans expliquer
// pourquoi.
const PERIODES_ANCIENNETE = [
    'j1'     => 'Moins de 24 h',
    'j7'     => 'Cette semaine',
    'j30'    => 'Ce mois',
    'j365'   => 'Cette année',
    'a3'     => '1 à 3 ans',
    'plus3'  => 'Plus de 3 ans',
    'jamais' => 'Jamais',
];

// Fragment SQL (et paramètres) filtrant $colonne sur les tranches cochées.
// Renvoie ['', []] si rien n'est coché — aucune restriction, comme les autres
// filtres de colonne. Les dates sont stockées en « AAAA-MM-JJ » : la
// comparaison lexicographique suffit, d'où date('now', …) côté SQLite.
function periode_anciennete_where(string $colonne, array $tranches): array
{
    // [jours au plus (borne ancienne, incluse), jours au moins (borne récente, exclue)]
    $bornes = [
        'j1'    => [1, null],
        'j7'    => [7, 1],
        'j30'   => [30, 7],
        'j365'  => [365, 30],
        'a3'    => [1095, 365],
        'plus3' => [null, 1095],
    ];
    $conds = [];
    $params = [];
    foreach ($tranches as $t) {
        if ($t === 'jamais') {
            $conds[] = "($colonne IS NULL OR $colonne = '')";
            continue;
        }
        if (!isset($bornes[$t])) {
            continue;
        }
        [$plusVieux, $plusRecent] = $bornes[$t];
        $morceaux = ["$colonne <> ''", "$colonne IS NOT NULL"];
        if ($plusVieux !== null) {
            $morceaux[] = "$colonne >= date('now', ?)";
            $params[] = '-' . $plusVieux . ' days';
        }
        if ($plusRecent !== null) {
            $morceaux[] = "$colonne < date('now', ?)";
            $params[] = '-' . $plusRecent . ' days';
        }
        $conds[] = '(' . implode(' AND ', $morceaux) . ')';
    }
    return $conds ? [' AND (' . implode(' OR ', $conds) . ')', $params] : ['', []];
}

// $libelle : hors d'un tableau (panneau « Filtres » mobile de ?p=structures,
// ciblage de ?p=mailing_campagne), il n'y a pas d'en-tête de colonne pour
// nommer le filtre — le libellé entre alors DANS le <summary>, avec le nombre
// de valeurs actives. Tout le bouton devient cliquable, au lieu d'un entonnoir
// de 18px posé à côté d'un texte inerte, et l'état actif se voit sans ouvrir le
// menu. Laissé vide (le défaut), le bouton reste l'entonnoir seul des <thead>,
// où le nom de la colonne et les pastilles de valeurs actives font déjà ce
// travail.
// Bouton d'ouverture d'un filtre de colonne. Sans libellé : l'entonnoir seul
// des <thead>. Avec : un vrai bouton nommé, qui porte son état actif et, le cas
// échéant, le nombre de valeurs cochées.
function filtre_bouton_html(string $libelle, bool $actif, ?int $nb = null): string
{
    if ($libelle === '') {
        // L'entonnoir seul des <thead> porte lui-même son état : coloré et plus
        // épais dès qu'une valeur est cochée. C'est ce qui permet de ne plus
        // lister les valeurs actives sous l'en-tête — elles y doublaient une
        // information déjà donnée par la barre d'outils, et empilées elles
        // faisaient passer la ligne d'en-tête à 92px de haut.
        // $nb est nul pour les filtres à contenu libre (filtre_colonne_form_html())
        // : ils n'ont pas de valeurs cochées à compter, seulement un état.
        $titre = !$actif ? 'Filtrer'
            : ($nb ? 'Filtre actif — ' . $nb . ' valeur(s) sélectionnée(s)' : 'Filtre actif');
        return '<summary class="col-filter-btn' . ($actif ? ' on' : '') . '" title="' . e($titre) . '">'
            . icon('funnel') . '</summary>';
    }
    $h = '<summary class="col-filter-btn col-filter-btn-nomme' . ($actif ? ' on' : '') . '">'
       . '<span class="col-filter-lib">' . e($libelle) . '</span>';
    if ($actif && $nb !== null && $nb > 0) {
        $h .= '<span class="col-filter-nb">' . $nb . '</span>';
    }
    return $h . icon('chevron-down') . '</summary>';
}

// $actionsParOption (facultatif) : HTML posé À CÔTÉ de chaque case à cocher,
// indexé par valeur d'option — le crayon de renommage du filtre « Tags »
// de ?p=structures. Hors du <label> : un bouton à l'intérieur cocherait la case
// en même temps qu'il s'active. Sans ce paramètre, le balisage reste le <label>
// nu de tous les autres filtres.
// Bouton « retirer tous les filtres », posé au bout de la ligne de titres d'une
// liste. N'apparaît que si quelque chose est effectivement filtré : un bouton
// qui ne ferait rien serait du bruit dans un en-tête déjà chargé d'entonnoirs.
//
// $champsCoches : les filtres de colonne (filtre_coche()) — chacun reçoit son
// marqueur « _set » sans valeur, ce qui vide sa mémoire de session ; sans ce
// marqueur, la session reprendrait la main au chargement suivant.
// $champsVides : les filtres à valeur simple (filtre_persistant()), qu'on
// remet à '' — leur absence de l'URL ne suffit pas à les éteindre.
// La recherche texte et le contexte de retour survivent : l'entonnoir barré
// parle des filtres, pas de ce qu'on a tapé ni d'où l'on vient.
// $params : paramètres à garder dans le lien en plus de q/vue/depuis — l'id de
// la campagne dont on filtre les structures, par exemple, sans lequel « retirer
// les filtres » ramènerait à la liste des campagnes.
function bouton_reinit_filtres(string $page, array $champsCoches, bool $actif, array $champsVides = [], array $params = []): string
{
    if (!$actif) {
        return '';
    }
    $params = ['p' => $page] + $params;
    foreach ($champsCoches as $c) {
        $params[$c . '_set'] = 1;
    }
    foreach ($champsVides as $c) {
        $params[$c] = '';
    }
    foreach (['q', 'vue', 'depuis'] as $c) {
        if (isset($_GET[$c]) && (string) $_GET[$c] !== '') {
            $params[$c] = (string) $_GET[$c];
        }
    }
    return '<a class="reinit-filtres" href="' . e('?' . http_build_query($params)) . '"'
        . ' title="Retirer tous les filtres" aria-label="Retirer tous les filtres">'
        . icon('funnel-x') . '</a>';
}

function filtre_colonne_html(string $page, string $champ, array $options, array $actives, array $autresParams, string $libelle = '', array $actionsParOption = []): string
{
    // Un filtre porteur d'actions a besoin de plus de place : un champ de
    // renommage plus trois icônes ne tiennent pas dans les 200px du panneau
    // ordinaire (voir .col-filter-menu, assets/css/).
    $h = '<details class="col-filter' . ($actionsParOption ? ' col-filter-large' : '') . '">'
       . filtre_bouton_html($libelle, count($actives) > 0, count($actives))
       . '<form method="get" class="col-filter-menu">'
       . '<input type="hidden" name="p" value="' . e($page) . '">'
       . hidden_inputs_html($autresParams);
    $h .= '<input type="hidden" name="' . e($champ) . '_set" value="1">'
        . '<label class="col-filter-tout"><input type="checkbox" data-check-tout' . (count($actives) === count($options) ? ' checked' : '') . '> Tout</label>'
        . '<div class="col-filter-sep"></div><div class="col-filter-options">';
    $activesTxt = array_map('strval', $actives);
    // Libellés arborescents (les catégories de ?p=structures et de
    // ?p=compta_ecritures) : ils arrivent préfixés d'espaces insécables, deux
    // par niveau. Traduits en marge interne sur le <label>, ils décalent la
    // case à cocher EN MÊME TEMPS que son texte ; laissés dans le texte, ils
    // laissaient la colonne de cases parfaitement droite sous une liste
    // visiblement en escalier.
    //
    // Une passe préalable, parce qu'on a besoin du niveau du SUIVANT : une
    // option a des enfants quand celle qui la suit est plus profonde — les
    // options arrivent dans l'ordre d'un parcours en profondeur, et c'est tout
    // ce qu'il faut savoir pour dessiner l'arbre.
    $niveaux = [];
    $libelles = [];
    foreach ($options as $val => $lib) {
        $niveau = 0;
        if (preg_match('/^\x{00A0}+/u', (string) $lib, $m)) {
            $niveau = intdiv(mb_strlen($m[0]), 2);
            $lib = mb_substr((string) $lib, mb_strlen($m[0]));
        }
        $niveaux[]  = $niveau;
        $libelles[] = (string) $lib;
    }
    $arbo = $niveaux !== [] && max($niveaux) > 0;

    // Les descendants d'une rangée sont EMBOÎTÉS dans une branche à elle, et
    // non laissés frères dans une liste plate : c'est ce qui permet de déplier
    // en animant la hauteur du groupe (grid-template-rows, assets/css/)
    // plutôt que de faire apparaître les rangées une à une. La pile retient le
    // niveau de chaque branche ouverte ; on les ferme dès qu'une rangée
    // revient à ce niveau ou plus haut.
    $pile = [];
    $i = 0;
    foreach ($options as $val => $_libBrut) {
        $niveau   = $niveaux[$i];
        $lib      = $libelles[$i];
        $aEnfants = $arbo && ($niveaux[$i + 1] ?? -1) > $niveau;
        $i++;
        while ($pile !== [] && $niveau <= end($pile)) {
            $h .= '</div>';
            array_pop($pile);
        }
        $checked = in_array((string) $val, $activesTxt, true) ? ' checked' : '';
        // data-niv sur l'élément de premier rang : c'est lui que le script
        // parcourt pour replier une branche (lassoFiltreArbo(), assets/app.js).
        // Posé seulement sur une liste arborescente — ailleurs il n'aurait rien
        // à dire, et le script n'a rien à y faire.
        $attrNiv = $arbo ? ' data-niv="' . $niveau . '"' : '';
        $enveloppe = isset($actionsParOption[$val]) || $aEnfants;
        $case = '<label' . ($niveau > 0 ? ' style="--niv:' . $niveau . '"' : '')
              . ($enveloppe ? '' : $attrNiv) . '>'
              . '<input type="checkbox" name="' . e($champ) . '[]" value="' . e((string) $val) . '"' . $checked . '> ' . e($lib) . '</label>';
        // Le chevron d'une catégorie qui en contient d'autres : même geste que
        // celui des onglets dépliables du rail (.nav-page-chevron,
        // views/layout.php) — une seule icône, qui tourne au lieu de changer.
        // <button> et non <span> : c'est un geste au clavier comme à la souris,
        // et hors du <label>, sans quoi l'activer cocherait la case.
        $chevron = $aEnfants
            ? '<button type="button" class="cat-chevron" aria-expanded="true"'
                . ' aria-label="Afficher ou masquer les sous-catégories">' . icon('chevron-right') . '</button>'
            : '';
        $h .= $enveloppe
            ? '<div class="col-filter-opt"' . $attrNiv . '>' . $case . ($actionsParOption[$val] ?? '') . $chevron . '</div>'
            : $case;
        if ($aEnfants) {
            $h .= '<div class="col-filter-branche">';
            $pile[] = $niveau;
        }
    }
    while ($pile !== []) {
        $h .= '</div>';
        array_pop($pile);
    }
    return $h . '</div><button type="submit" class="col-filter-apply">Appliquer</button></form></details>';
}

// Entonnoir « Lieu » : le seul filtre de l'application dont les valeurs se
// CHERCHENT au lieu de se parcourir. Pays, régions et départements tiennent dans
// une liste (165 lignes) et sont écrits ici ; les villes, plus de quinze cents,
// sont demandées à ?p=structures_lieux au premier clic dans le champ et
// ajoutées à la volée (lassoInitFiltreLieu(), assets/app.js). Sans JavaScript,
// on filtre donc par pays, région et département — ce que faisaient les trois
// entonnoirs d'avant — et la ville, qui n'a jamais été filtrable, demande le
// script.
//
// Les valeurs déjà cochées sont rendues en tête, quel que soit leur niveau :
// c'est la seule façon de pouvoir en retirer une ville sans avoir à la
// retrouver.
//
// $options : ['pays' => [jeton => ['libelle', 'pays', 'n']], …] (lieux_options()).
function filtre_colonne_lieu_html(string $page, array $actifs, array $autresParams, array $options, string $libelle = ''): string
{
    $actifs = array_values(array_filter(array_map('strval', $actifs)));
    $h = '<details class="col-filter col-filter-large col-filter-lieu">'
       . filtre_bouton_html($libelle, $actifs !== [], count($actifs))
       . '<form method="get" class="col-filter-menu">'
       . '<input type="hidden" name="p" value="' . e($page) . '">'
       . hidden_inputs_html($autresParams)
       . '<input type="hidden" name="lieu_set" value="1">'
       . '<div class="lieu-champ"><input type="search" class="lieu-recherche" autocomplete="off"'
       . ' placeholder="Pays, région, département, ville…" aria-label="Chercher un lieu"></div>'
       . '<div class="col-filter-options">';

    $case = function (string $jeton, array $o, bool $coche) use (&$h): void {
        $h .= '<label' . ($coche ? ' class="lieu-coche"' : '') . ' data-lieu-niveau="' . e(explode(':', $jeton)[0]) . '">'
            . '<input type="checkbox" name="lieu[]" value="' . e($jeton) . '"' . ($coche ? ' checked' : '') . '> '
            . '<span class="lieu-nom">' . e($o['libelle']) . '</span>'
            . ($o['pays'] !== $o['libelle'] ? '<span class="lieu-pays">' . e($o['pays']) . '</span>' : '')
            . ($o['n'] > 0 ? '<span class="lieu-n">' . (int) $o['n'] . '</span>' : '')
            . '</label>';
    };

    // Ce qui est coché, en tête, dans l'ordre où on l'a coché.
    $connus = array_merge(...array_values($options)) ?: [];
    if ($actifs) {
        $h .= '<div class="lieu-groupe">Sélection</div>';
        foreach ($actifs as $jeton) {
            $l = lieu_decoder($jeton);
            if (!$l) {
                continue;
            }
            // Une ville cochée n'est pas dans $options (elles n'y sont pas
            // écrites) : son libellé se relit dans le jeton lui-même.
            $case($jeton, $connus[$jeton] ?? ['libelle' => $l['valeur'], 'pays' => $l['pays'], 'n' => 0], true);
        }
    }
    foreach ($options as $niveau => $liste) {
        $liste = array_diff_key($liste, array_flip($actifs));
        if (!$liste) {
            continue;
        }
        $h .= '<div class="lieu-groupe" data-lieu-groupe="' . e($niveau) . '">' . e(LIEU_NIVEAUX[$niveau] ?? $niveau) . '</div>';
        foreach ($liste as $jeton => $o) {
            $case((string) $jeton, $o, false);
        }
    }
    $h .= '</div><p class="lieu-aide muted small">Tapez pour chercher une ville.</p>';
    return $h . '<button type="submit" class="col-filter-apply">Appliquer</button></form></details>';
}

// Menu déroulant à cases à cocher, destiné à vivre DANS un formulaire existant
// (fenêtre « Contacter », note d'historique, modèle de message) — même
// apparence que les filtres de colonne, mais sans <form> à lui : un formulaire
// imbriqué dans un autre n'existe pas en HTML.
//
// « Tout » n'est pas une valeur : c'est l'absence de case cochée, comme pour les
// filtres — le bouton affiche alors $libelleVide. Dès qu'une case est cochée, il
// NOMME ce qui l'est (voir plus bas) : dans un formulaire, un entonnoir muet ne
// dirait pas ce qu'on a choisi.
// ⚠️ NE JAMAIS envelopper ce bloc dans un <label>. Cliquer un <label> active
// son premier contrôle : ici la case « Tout », qui coche alors TOUT. Le geste
// se produit dès qu'on clique le libellé du champ ou l'espace vide à côté du
// bouton — donc sans cesse. Utiliser <div class="field-group"> (assets/css/),
// qui a la même apparence sans être un label.
// $attrsParValeur : des attributs HTML à poser sur le <label> d'une option
// donnée — « data-projet-inactif » pour les projets mis de côté, que la case
// « Actifs seulement » masque ensuite (lassoInitProjetsActifs()). Le libellé
// seul ne pouvait pas les porter.
function choix_coches_html(string $champ, array $options, array $actives, string $libelleVide = 'Tout', array $attrsParValeur = []): string
{
    $activesTxt = array_map('strval', $actives);
    // Le bouton porte TOUJOURS un nom, et celui de ce qui est coché. Dans un
    // en-tête de colonne, l'entonnoir seul suffit : la colonne dit déjà de quoi
    // il s'agit. Dans un formulaire, il ne dit rien — on lisait « Projet » puis
    // un entonnoir muet, sans savoir quel projet était choisi sans dérouler.
    $choisis = [];
    foreach ($options as $val => $lib) {
        if (in_array((string) $val, $activesTxt, true)) {
            $choisis[] = (string) $lib;
        }
    }
    $libelle = $choisis
        ? implode(' · ', array_slice($choisis, 0, 2)) . (count($choisis) > 2 ? ' +' . (count($choisis) - 2) : '')
        : $libelleVide;
    // data-vide : ce que le bouton affiche quand plus rien n'est coché. Le
    // libellé est refait côté navigateur à chaque case cochée (lassoInitChoixCoches(),
    // assets/app.js) — sans quoi il ne disait la vérité qu'après enregistrement.
    $h = '<details class="col-filter choix-coches" data-vide="' . e($libelleVide) . '">'
       . filtre_bouton_html($libelle, $activesTxt !== [], null)
       . '<div class="col-filter-menu">'
       . '<label class="col-filter-tout"><input type="checkbox" data-check-tout' . (count($activesTxt) === count($options) && $options ? ' checked' : '') . '> Tout</label>'
       . '<div class="col-filter-sep"></div><div class="col-filter-options">';
    foreach ($options as $val => $lib) {
        $checked = in_array((string) $val, $activesTxt, true) ? ' checked' : '';
        $attrs = (string) ($attrsParValeur[$val] ?? '');
        $h .= '<label' . ($attrs !== '' ? ' ' . $attrs : '') . '><input type="checkbox" name="' . e($champ) . '[]" value="' . e((string) $val) . '"' . $checked . '> ' . e((string) $lib) . '</label>';
    }
    return $h . '</div></div></details>';
}

// Pastilles des valeurs actives d'un filtre_colonne_html() — une par valeur,
// chacune avec sa propre croix pour la retirer individuellement (les autres
// valeurs actives et les autres filtres de la page restent inchangés).
function filtre_colonne_actifs_html(string $page, string $champ, array $options, array $actives, array $autresParams): string
{
    if (!$actives) {
        return '';
    }
    $h = '<span class="col-th-actif-list">';
    foreach ($actives as $val) {
        $lib = $options[$val] ?? (string) $val;
        $qs = ['p' => $page] + $autresParams;
        $qs[$champ . '_set'] = 1;
        $qs[$champ] = array_values(array_diff($actives, [$val]));
        $h .= '<span class="col-th-actif">' . e($lib) . '<a href="?' . e(http_build_query($qs)) . '" title="Retirer « ' . e($lib) . ' »">' . icon('x') . '</a></span>';
    }
    return $h . '</span>';
}

// Pastille de filtre actif pour un critère qui n'est PAS une case à cocher, et
// qui ne peut donc pas passer par filtre_colonne_actifs_html() (rien à cocher,
// donc ni $options ni $actives) : une plage de mois, un encadrement de jauge,
// une date de dernier contact. Une seule pastille par groupe, jamais une par
// champ élémentaire — « De »/« à » forment une plage, on ne retire pas l'un
// sans l'autre ; $autresParams doit donc déjà avoir vidé tous les champs du
// groupe d'un coup. Même balisage que filtre_colonne_actifs_html() : les deux
// se mélangent dans la même bande (.filtres-ciblage-actifs, voir
// ?p=mailing_campagne et le panneau « Filtres » mobile de ?p=structures).
function filtre_pille_groupe_html(string $page, string $label, array $autresParams): string
{
    $qs = ['p' => $page] + $autresParams;
    return '<span class="col-th-actif-list"><span class="col-th-actif">' . e($label)
        . '<a href="?' . e(http_build_query($qs)) . '" title="Retirer « ' . e($label) . ' »">' . icon('x') . '</a></span></span>';
}

// Variante de filtre_colonne_html() pour un panneau au contenu libre (pas des
// cases à cocher) — même enveloppe .col-filter/.col-filter-menu (bouton
// entonnoir + <details>, voir assets/css/), mais $contenu est du HTML déjà
// construit par l'appelant (ex. deux <select> « De »/« à » pour une plage de
// mois, un <input type="date"> + une case à cocher pour ?p=mailing_campagne).
// Pas de marqueur « _set » ni de case « Tout » : ces notions n'ont de sens
// que pour un filtre à cases à cocher (filtre_coche()).
function filtre_colonne_form_html(string $page, array $autresParams, string $contenu, string $libelle = '', bool $actif = false): string
{
    return '<details class="col-filter">'
        . filtre_bouton_html($libelle, $actif)
        . '<form method="get" class="col-filter-menu">'
        . '<input type="hidden" name="p" value="' . e($page) . '">'
        . hidden_inputs_html($autresParams)
        . $contenu
        . '<button type="submit" class="col-filter-apply">Appliquer</button></form></details>';
}
