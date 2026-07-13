<?php
// Module événements : fonctions pures (statut SUISA dérivé, règles de
// visibilité/export) — voir SPEC_EVENEMENTS.md.

const EVENEMENTS_STATUTS     = ['option', 'confirme', 'annule'];
const EVENEMENTS_VISIBILITES = ['public', 'prive', 'non_repertorie'];
const EVENEMENTS_SUISA_ENVOYE_A = ['suisa', 'organisateur'];
// Les 7 valeurs du statut SUISA dérivé (evenement_statut_suisa()), pour valider
// le filtre de la liste des événements.
const EVENEMENTS_STATUTS_SUISA_FILTRE = ['a_venir', 'a_faire', 'envoye', 'manquant', 'abandonne', 'decompte_recu', 'ne_sapplique_pas'];

function evenement_statut_libelle(string $statut): string
{
    return match ($statut) {
        'confirme' => 'Confirmé',
        'annule'   => 'Annulé',
        default    => 'Option',
    };
}

function evenement_visibilite_libelle(string $visibilite): string
{
    return match ($visibilite) {
        'public' => 'Public',
        'prive'  => 'Privé',
        default  => 'Non répertorié',
    };
}

// Statut SUISA dérivé (jamais stocké), voir SPEC_EVENEMENTS.md §5. Calculé par
// ordre de priorité : ne s'applique pas > décompte reçu > à venir > abandonné
// > manquant > envoyé > à faire. « À venir » : sans décompte, et la date de
// l'événement n'est pas encore passée — inutile de suivre un envoi/décompte
// avant que l'événement ait eu lieu. « Abandonné » : sans décompte, et la date
// dépasse le délai configurable evenements_delai_abandon_mois() (ex. 5 ans) —
// on cesse de le compter comme à relancer, qu'il ait été envoyé ou non.
function evenement_statut_suisa(array $ev): string
{
    if (!(int) ($ev['suisa_applicable'] ?? 1)) {
        return 'ne_sapplique_pas';
    }
    if (trim((string) ($ev['suisa_decompte_le'] ?? '')) !== '') {
        return 'decompte_recu';
    }
    $dateEv = trim((string) ($ev['date'] ?? ''));
    if ($dateEv !== '') {
        if ($dateEv > date('Y-m-d')) {
            return 'a_venir';
        }
        $limiteAbandon = (new DateTimeImmutable($dateEv))
            ->modify('+' . evenements_delai_abandon_mois() . ' months')
            ->format('Y-m-d');
        if ($limiteAbandon < date('Y-m-d')) {
            return 'abandonne';
        }
    }
    $envoyeLe = trim((string) ($ev['suisa_envoye_le'] ?? ''));
    if ($envoyeLe === '') {
        return 'a_faire';
    }
    $limite = (new DateTimeImmutable($envoyeLe))
        ->modify('+' . evenements_delai_decompte_mois() . ' months')
        ->format('Y-m-d');
    return $limite < date('Y-m-d') ? 'manquant' : 'envoye';
}

function evenement_statut_suisa_libelle(string $statut): string
{
    return match ($statut) {
        'decompte_recu'    => 'Décompte reçu',
        'manquant'         => 'Manquant',
        'envoye'           => 'Envoyé',
        'abandonne'        => 'Abandonné',
        'a_venir'          => 'À venir',
        'ne_sapplique_pas' => "Ne s'applique pas",
        default            => 'À faire',
    };
}

// $avecDate : sur la liste des événements, affiche la date du décompte plutôt
// que le libellé « Décompte reçu » (déjà explicite via la couleur du badge) —
// non utilisé sur la fiche événement, où le champ date est juste en dessous.
function evenement_suisa_badge(array $ev, bool $avecDate = false): string
{
    $statut = evenement_statut_suisa($ev);
    $classe = match ($statut) {
        'decompte_recu' => 'ok',
        'a_faire'       => 'warn',   // jaune
        'manquant'      => 'orange',
        'envoye'        => 'emise',
        default         => 'muted', // a_venir, abandonne, ne_sapplique_pas
    };
    $decompteLe = trim((string) ($ev['suisa_decompte_le'] ?? ''));
    $texte = ($statut === 'decompte_recu' && $avecDate && $decompteLe !== '')
        ? date('d.m.Y', strtotime($decompteLe))
        : evenement_statut_suisa_libelle($statut);
    return badge($texte, $classe);
}

// Couleur associée au statut d'un événement (confirmé = ok/vert, option =
// warn/jaune, annulé = muted/gris) — source unique réutilisée par le badge,
// la puce et la date colorée du tableau de bord.
function evenement_statut_couleur(array $ev): string
{
    return match ($ev['statut']) {
        'confirme' => 'ok',
        'annule'   => 'muted',
        default    => 'warn', // option
    };
}

function evenement_badge_statut(array $ev): string
{
    return badge(evenement_statut_libelle((string) $ev['statut']), evenement_statut_couleur($ev));
}

// Icône de visibilité (earth/earth-lock/globe-off, lucide.dev) pour le tableau
// des événements — plus compacte qu'un badge texte. Noir (public) > gris
// (privé) > gris très atténué (non répertorié, le statut le moins visible du
// site public l'est aussi le moins ici).
function evenement_icone_visibilite(array $ev): string
{
    [$nom, $classe] = match ($ev['visibilite']) {
        'public' => ['earth', 'visibilite-public'],
        'prive'  => ['earth-lock', 'visibilite-prive'],
        default  => ['globe-off', 'visibilite-non-repertorie'], // non_repertorie
    };
    $libelle = evenement_visibilite_libelle((string) $ev['visibilite']);
    return '<span class="visibilite-icone ' . $classe . '" title="' . e($libelle) . '" aria-label="' . e($libelle) . '">'
        . icon($nom) . '</span>';
}

// Prédicat SQL du statut SUISA dérivé, même règles que evenement_statut_suisa()
// (à tenir synchronisées) — pour filtrer côté base plutôt que de recharger tous
// les événements en PHP à chaque affichage de la liste. $prefixe : alias de
// table éventuel (ex. "e." si la requête utilise "FROM evenements e"). Filtre
// 'envoye' : englobe aussi 'manquant' (un décompte en retard reste, avant tout,
// un événement envoyé — l'utilisateur veut voir « tout ce qui a été envoyé »
// sans avoir à cocher les deux statuts séparément) ; 'manquant' reste un filtre
// à part pour ne voir que les décomptes effectivement en retard. Tous (sauf
// 'decompte_recu'/'ne_sapplique_pas') excluent 'a_venir' et 'abandonne'
// (priorité plus haute, voir evenement_statut_suisa()) — mutuellement exclusifs
// entre eux (l'un exige une date future, l'autre une date passée depuis
// longtemps). Paramètres à lier par l'appelant, dans l'ordre où ils
// apparaissent dans la chaîne : 'manquant' → [delai_decompte_mois,
// delai_abandon_mois] ; 'a_faire'/'envoye'/'abandonne' → [delai_abandon_mois] ;
// 'a_venir'/'decompte_recu'/'ne_sapplique_pas' → aucun.
function evenement_sql_statut_suisa(string $statut, string $prefixe = ''): string
{
    $applicable   = "{$prefixe}suisa_applicable = 1";
    $nonEnvoyee   = "{$prefixe}suisa_envoye_le = ''";
    $envoyeeSansDecompte = "{$prefixe}suisa_envoye_le <> '' AND {$prefixe}suisa_decompte_le = ''";
    $sansDecompte = "{$prefixe}suisa_decompte_le = ''";
    $limite       = "date({$prefixe}suisa_envoye_le, '+' || ? || ' months')";
    $aVenir       = "date({$prefixe}date) > date('now')";
    $abandonne    = "date({$prefixe}date, '+' || ? || ' months') < date('now')";
    return match ($statut) {
        'decompte_recu' => "$applicable AND {$prefixe}suisa_decompte_le <> ''",
        'a_venir'       => "$applicable AND $sansDecompte AND $aVenir",
        'abandonne'     => "$applicable AND $sansDecompte AND $abandonne", // exclut déjà 'a_venir' (délai positif)
        // Exclut un décompte reçu sans date d'envoi enregistrée (saisie manuelle
        // incomplète) : evenement_statut_suisa() le classe 'decompte_recu' en
        // priorité (voir plus haut), le prédicat SQL doit rester synchronisé.
        'a_faire'       => "$applicable AND $nonEnvoyee AND $sansDecompte AND NOT ($abandonne) AND NOT ($aVenir)",
        'envoye'        => "$applicable AND $envoyeeSansDecompte AND NOT ($abandonne) AND NOT ($aVenir)",
        'manquant'      => "$applicable AND $envoyeeSansDecompte AND $limite < date('now') AND NOT ($abandonne) AND NOT ($aVenir)",
        default         => "{$prefixe}suisa_applicable = 0", // ne_sapplique_pas
    };
}

// Délai (en mois) avant qu'une date SUISA envoyée mais jamais décomptée soit
// considérée « manquante ». Paramètre configurable (onglet Événements).
function evenements_delai_decompte_mois(): int
{
    return max(1, (int) param('suisa_delai_decompte_mois', '12'));
}

// Délai (en mois, depuis la date de l'événement) au-delà duquel un événement
// toujours sans décompte est considéré « abandonné » plutôt que relancé
// indéfiniment. Paramètre configurable (onglet Événements).
function evenements_delai_abandon_mois(): int
{
    return max(1, (int) param('suisa_delai_abandon_mois', '60'));
}

// Texte par défaut du bouton de lien (« plus d'infos ») quand l'événement n'en
// précise pas un lui-même. Paramétrable (onglet Événements).
function evenements_lien_texte_defaut(): string
{
    $v = trim((string) param('evenements_lien_texte_defaut', ''));
    return $v !== '' ? $v : "Plus d'informations";
}

// Liste des pays proposés dans le champ « Région et pays » du formulaire
// événement. Paramétrable (onglet Événements) ; défaut CH/FR/BE/CA.
function evenements_pays_disponibles(): array
{
    $v = trim((string) param('evenements_pays_disponibles', ''));
    $liste = $v !== '' ? array_map('trim', explode(',', $v)) : ['CH', 'FR', 'BE', 'CA'];
    return array_values(array_filter($liste, fn ($p) => $p !== ''));
}

// Terme utilisé dans l'interface pour désigner une série d'événements (le
// regroupement sous un même nom, ex. une pièce jouée à plusieurs dates) —
// paramétrable (onglet Événements), par défaut « Spectacles ». La table et les
// routes internes restent nommées « spectacle(s) », seul l'affichage change.
function evenements_terme_spectacle(bool $pluriel = true): string
{
    $terme = trim((string) param('evenements_terme_spectacle', ''));
    if ($terme === '') {
        $terme = 'Spectacles';
    }
    if ($pluriel) {
        return $terme;
    }
    // Singulier dérivé (règle française courante : le pluriel ajoute un « s »).
    $singulier = rtrim($terme, 's');
    return $singulier !== '' ? $singulier : $terme;
}

// Émoji drapeau à partir d'un code pays ISO 3166-1 alpha-2 (ex. « CH » → 🇨🇭),
// pour l'affichage du lieu d'un événement. Vide si le code n'a pas ce format.
function pays_drapeau(string $code): string
{
    $code = strtoupper(trim($code));
    if (!preg_match('/^[A-Z]{2}$/', $code)) {
        return '';
    }
    $drapeau = '';
    foreach (str_split($code) as $lettre) {
        $drapeau .= mb_chr(127397 + ord($lettre), 'UTF-8');
    }
    return $drapeau;
}

// Les prochains événements (date ≥ aujourd'hui), pour le widget du tableau de
// bord — tous statuts/visibilités confondus (vue interne, pas l'export public).
function evenements_a_venir(int $limite = 5): array
{
    $stmt = db()->prepare(
        "SELECT e.*, s.nom AS spectacle_nom FROM evenements e
         LEFT JOIN spectacles s ON s.id = e.spectacle_id
         WHERE e.date >= date('now') ORDER BY e.date ASC LIMIT ?"
    );
    $stmt->bindValue(1, $limite, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

// Nombre d'événements SUISA « manquants » (badge de menu).
function nb_evenements_suisa_manquants(): int
{
    try {
        $n = 0;
        $stmt = db()->query(
            "SELECT date, suisa_applicable, suisa_envoye_le, suisa_decompte_le FROM evenements
             WHERE suisa_applicable = 1 AND suisa_envoye_le <> '' AND suisa_decompte_le = ''"
        );
        foreach ($stmt as $ev) {
            if (evenement_statut_suisa($ev) === 'manquant') {
                $n++;
            }
        }
        return $n;
    } catch (\Exception) {
        return 0;
    }
}

// Liste des spectacles assignables (feuilles uniquement — un spectacle-parent
// représente un artiste, pure groupement, jamais assigné directement à un
// événement) pour un <select> — formulaire événement. 'nom' porte le chemin
// complet (« Artiste › Spectacle ») pour lever l'ambiguïté dans l'arbre
// (plusieurs artistes peuvent avoir des feuilles au nom proche).
function spectacles_pour_selection(?array $map = null): array
{
    $map ??= spectacle_map();
    $out = [];
    foreach (plan_liste_ordonnee($map) as $r) {
        $id = (int) $r['id'];
        if (!plan_est_feuille($id, $map)) {
            continue;
        }
        $out[] = ['id' => $id, 'nom' => spectacle_chemin($id, $map)];
    }
    return $out;
}

// Liste combinée groupes+feuilles pour le filtre « Spectacle » de la liste des
// événements — contrairement à spectacles_pour_selection() (assignation à un
// événement, feuilles uniquement), un spectacle-groupe (artiste) y est un
// filtre valide : il élargit la recherche à tous ses descendants (même
// principe que l'export public, voir evenements_a_exporter()). Les feuilles
// gardent leur nom seul (pas de préfixe groupe, sinon le select devient vite
// très large) ; les groupes portent leur chemin + suffixe « (groupe) ».
// Ordre de l'arbre (plan_liste_ordonnee) : un groupe est suivi de ses propres
// feuilles.
function spectacles_pour_filtre(?array $map = null): array
{
    $map ??= spectacle_map();
    $out = [];
    foreach (plan_liste_ordonnee($map) as $r) {
        $id = (int) $r['id'];
        $feuille = plan_est_feuille($id, $map);
        $out[] = ['id' => $id, 'nom' => $feuille ? (string) $r['nom'] : spectacle_chemin($id, $map) . ' (groupe)'];
    }
    return $out;
}

// Vrai si $id correspond à un spectacle existant — validé avant tout
// INSERT/UPDATE d'un événement pour éviter une violation de clé étrangère
// (PRAGMA foreign_keys = ON) sur un id invalide/périmé posté.
function spectacle_existe(int $id): bool
{
    $stmt = db()->prepare('SELECT 1 FROM spectacles WHERE id = ?');
    $stmt->execute([$id]);
    return (bool) $stmt->fetchColumn();
}

// Vrai si $id correspond à un spectacle existant ET assignable (feuille) —
// un spectacle-parent (groupe/artiste) ne peut jamais être lié directement à
// un événement. Utilisé côté serveur partout où evenements.spectacle_id est
// écrit (le <select> ne propose déjà que des feuilles, mais un POST forgé ou
// une resoumission ne doit pas pouvoir contourner cette règle).
function spectacle_assignable(int $id): bool
{
    $map = spectacle_map();
    return isset($map[$id]) && plan_est_feuille($id, $map);
}

// ------------------------------------------------ Hiérarchie des spectacles
// Même esprit que le plan comptable (lib/compta.php) : un spectacle-parent
// (nœud non-feuille) représente un artiste, ses enfants ses dates/tournées —
// pas de champ « artiste » séparé, le tri par artiste se fait via l'arbre.
// plan_pid()/plan_enfants()/plan_parents_set()/plan_est_feuille()/
// plan_liste_ordonnee() sont génériques (id/parent_id/ordre uniquement) et
// donc réutilisées telles quelles.

// Spectacles indexés par id (pour l'agrégation et l'affichage de l'arbre).
function spectacle_map(): array
{
    $map = [];
    foreach (db()->query('SELECT * FROM spectacles ORDER BY ordre, id') as $r) {
        $map[(int) $r['id']] = $r;
    }
    return $map;
}

// Chemin lisible « Artiste › Spectacle » d'un spectacle.
function spectacle_chemin(int $id, array $map, string $sep = ' › '): string
{
    $parts = [];
    $cur = $id;
    $garde = 0;
    while (isset($map[$cur]) && $garde++ < 50) {
        array_unshift($parts, (string) $map[$cur]['nom']);
        $cur = plan_pid($map[$cur]['parent_id'] ?? null);
        if ($cur === 0) {
            break;
        }
    }
    return implode($sep, $parts);
}

// Ids de tous les descendants d'un spectacle (pour empêcher les cycles lors
// d'un rattachement à un autre parent). $vus protège contre une récursion
// sans fin si parent_id contenait déjà un cycle (donnée corrompue) — chaque
// id n'est parcouru qu'une fois.
function spectacle_descendants(int $id, array $map): array
{
    $byParent = plan_enfants($map);
    $out = [];
    $vus = [$id => true];
    $walk = function (int $pid) use (&$walk, &$out, &$vus, $byParent) {
        foreach ($byParent[$pid] ?? [] as $child) {
            $cid = (int) $child['id'];
            if (isset($vus[$cid])) {
                continue;
            }
            $vus[$cid] = true;
            $out[] = $cid;
            $walk($cid);
        }
    };
    $walk($id);
    return $out;
}

// date_valide() : déplacée dans lib/helpers.php (partagée avec lib/compta.php,
// qui en avait besoin pour camt.053 sans dépendre de ce fichier).

// --------------------------------------------------------- EXPORT PUBLIC
// Un événement est exposable (site web / JSON / iCal) si sa visibilité n'est
// pas « non_repertorie » et que son statut n'est pas « option » (pas encore
// assez sûr pour être publié) — voir SPEC_EVENEMENTS.md §4.
function evenement_exportable(array $ev): bool
{
    return (string) $ev['visibilite'] !== 'non_repertorie' && (string) $ev['statut'] !== 'option';
}

// Données exposables d'un événement, filtrées selon la visibilité. $ev doit
// contenir un champ spectacle_nom (jointure sur spectacles) le cas échéant.
// Jamais de champ SUISA / facture / employés / fiches dans le résultat.
function evenement_export_donnees(array $ev): array
{
    $donnees = ['id' => (int) $ev['id'], 'date' => (string) $ev['date']];
    if ((string) $ev['visibilite'] === 'prive') {
        $donnees['prive'] = true;
        return $donnees;
    }
    $donnees['prive']   = false;
    $donnees['annule']  = (string) $ev['statut'] === 'annule';
    $donnees['ville']   = (string) ($ev['ville'] ?? '');
    if (trim((string) ($ev['region'] ?? '')) !== '') {
        $donnees['region'] = (string) $ev['region'];
    }
    if (trim((string) ($ev['pays'] ?? '')) !== '') {
        $donnees['pays'] = (string) $ev['pays'];
    }
    if (trim((string) ($ev['salle'] ?? '')) !== '') {
        $donnees['salle'] = (string) $ev['salle'];
    }
    if (trim((string) ($ev['festival'] ?? '')) !== '') {
        $donnees['festival'] = (string) $ev['festival'];
    }
    if (trim((string) ($ev['lien_infos'] ?? '')) !== '') {
        $donnees['lien_infos'] = (string) $ev['lien_infos'];
        $lienTexte = trim((string) ($ev['lien_texte'] ?? ''));
        $donnees['lien_texte'] = $lienTexte !== '' ? $lienTexte : evenements_lien_texte_defaut();
    }
    if (trim((string) ($ev['spectacle_nom'] ?? '')) !== '') {
        $donnees['spectacle'] = (string) $ev['spectacle_nom'];
    }
    $donnees['remarques'] = (string) ($ev['remarques'] ?? '');
    return $donnees;
}

// Liste filtrée/formatée des événements exposables, triée par date. $spectacleId
// optionnel restreint l'export à un spectacle (point d'accès dédié, §8) — s'il
// s'agit d'un spectacle-groupe (artiste), inclut aussi les événements de ses
// feuilles (un groupe n'est jamais assigné directement à un événement, voir
// spectacle_assignable()) : sans ça, l'URL d'export d'un artiste serait toujours
// vide.
function evenements_a_exporter(?int $spectacleId = null): array
{
    $sql = "SELECT e.*, s.nom AS spectacle_nom FROM evenements e
            LEFT JOIN spectacles s ON s.id = e.spectacle_id
            WHERE e.visibilite <> 'non_repertorie' AND e.statut <> 'option'";
    $params = [];
    if ($spectacleId) {
        $ids = array_merge([$spectacleId], spectacle_descendants($spectacleId, spectacle_map()));
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $sql .= " AND e.spectacle_id IN ($in)";
        $params = array_merge($params, $ids);
    }
    $sql .= ' ORDER BY e.date';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return array_map('evenement_export_donnees', $stmt->fetchAll());
}

// Jeton d'accès à l'export public (JSON/iCal). Généré au premier appel si absent.
function evenements_export_token(): string
{
    $token = (string) param('evenements_export_token', '');
    if ($token === '') {
        $token = bin2hex(random_bytes(16));
        db()->prepare('INSERT OR REPLACE INTO parametres (cle, valeur) VALUES (?, ?)')
            ->execute(['evenements_export_token', $token]);
    }
    return $token;
}

function evenements_regenerer_token(): string
{
    $token = bin2hex(random_bytes(16));
    db()->prepare('INSERT OR REPLACE INTO parametres (cle, valeur) VALUES (?, ?)')
        ->execute(['evenements_export_token', $token]);
    return $token;
}

// URL absolue d'un des deux points d'accès d'export, avec jeton (et filtre
// spectacle optionnel) déjà inclus — prête à copier-coller (onglet Paramètres).
function evenements_export_url(string $route, string $token, ?int $spectacleId = null): string
{
    $scheme = is_https() ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $qs     = 'p=' . $route . '&token=' . urlencode($token);
    if ($spectacleId) {
        $qs .= '&spectacle_id=' . $spectacleId;
    }
    return $scheme . '://' . $host . '/?' . $qs;
}

// Échappement des caractères spéciaux iCal (RFC 5545 §3.3.11).
function evenements_ical_echap(string $s): string
{
    return str_replace(["\\", "\n", ',', ';'], ['\\\\', '\\n', '\\,', '\\;'], $s);
}

// Flux iCal (.ics) à partir d'événements déjà filtrés/formatés (evenements_a_exporter()).
function evenements_generer_ical(array $items): string
{
    $lignes = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Lasso//Evenements//FR', 'CALSCALE:GREGORIAN'];
    foreach ($items as $it) {
        $date = str_replace('-', '', (string) $it['date']);
        if ($it['prive']) {
            $summary = 'Événement privé';
        } else {
            $summary = ((bool) $it['annule'] ? '[ANNULÉ] ' : '') . ($it['spectacle'] ?? 'Concert');
        }
        $lignes[] = 'BEGIN:VEVENT';
        $lignes[] = 'UID:evenement-' . $it['id'] . '@lasso';
        $lignes[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
        $lignes[] = 'DTSTART;VALUE=DATE:' . $date;
        $lignes[] = 'SUMMARY:' . evenements_ical_echap($summary);
        if (!$it['prive']) {
            $suffixe = implode(', ', array_filter([$it['region'] ?? '', $it['pays'] ?? '']));
            $ville = trim((string) ($it['ville'] ?? '')) . ($suffixe !== '' ? ' (' . $suffixe . ')' : '');
            $lieu = trim(($it['salle'] ?? '') !== '' ? ($it['salle'] . ', ' . $ville) : $ville);
            if ($lieu !== '') {
                $lignes[] = 'LOCATION:' . evenements_ical_echap($lieu);
            }
            if (!empty($it['festival'])) {
                $lignes[] = 'DESCRIPTION:' . evenements_ical_echap('Festival : ' . $it['festival']);
            }
            if (!empty($it['lien_infos'])) {
                $lignes[] = 'URL:' . evenements_ical_echap($it['lien_infos']);
            }
        }
        $lignes[] = 'END:VEVENT';
    }
    $lignes[] = 'END:VCALENDAR';
    return implode("\r\n", $lignes);
}

// -------------------------------------------------------- IMPORT CSV (agenda de tournée)
// Date au format JJ/MM/AAAA (agendas de tournée) → « Y-m-d », ou null si invalide.
function date_csv_vers_iso(string $s): ?string
{
    if (!preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', trim($s), $m)) {
        return null;
    }
    [, $jour, $mois, $annee] = $m;
    if (!checkdate((int) $mois, (int) $jour, (int) $annee)) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', (int) $annee, (int) $mois, (int) $jour);
}

// Nom de spectacle normalisé pour un rapprochement insensible à la casse, aux
// espaces et à la ponctuation (ex. « anticoncert » ↔ « Anti-concert »).
function normaliser_nom_spectacle(string $s): string
{
    return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim($s), 'UTF-8'));
}

// Importe des événements depuis un CSV d'agenda de tournée (colonnes attendues,
// dans n'importe quel ordre : date, ville, region, pays, lieu, details, type,
// statut, lien, lien_texte — seules date/ville sont obligatoires). $simule =
// true : n'écrit rien, retourne ce qui serait fait. Un événement existant
// (même date + ville + salle, comparaison insensible à la casse) est ignoré —
// jamais écrasé, même esprit que importer_factures_historique(). La colonne
// « type » est recherchée parmi les spectacles existants (nom normalisé) ; à
// défaut, un nouveau spectacle est créé à la volée. « lien_texte » est le texte du bouton de lien
// (ex. « Réserver ») ; ignoré si « lien » est absent/invalide, sinon stocké tel
// quel (une valeur vide utilisera le texte par défaut configurable à l'export,
// voir evenements_lien_texte_defaut()). Visibilité toujours « non_repertorie »
// à l'import (relecture manuelle avant publication) ; statut « confirme » par
// défaut si la colonne est vide ou non reconnue. Renvoie [résultats par ligne, résumé].
function importer_evenements_csv(string $csv, bool $simule): array
{
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv); // BOM UTF-8 (export Excel)
    $lignes = array_values(array_filter(preg_split('/\r\n|\r|\n/', (string) $csv), fn ($l) => trim($l) !== ''));
    $resume = ['total' => 0, 'nouveaux' => 0, 'existants' => 0, 'erreurs' => 0, 'spectacles_crees' => 0];
    if (!$lignes) {
        return [[], $resume];
    }

    $entete = array_map(fn ($c) => mb_strtolower(trim((string) $c), 'UTF-8'), str_getcsv(array_shift($lignes), ',', '"', ''));
    $idx = array_flip($entete);
    $col = fn (array $r, string $nom): string => trim((string) ($r[$idx[$nom] ?? -1] ?? ''));

    // Spectacles existants, indexés par nom normalisé (complété au fil de l'import).
    $spectaclesParNom = [];
    foreach (db()->query('SELECT id, nom FROM spectacles') as $s) {
        $spectaclesParNom[normaliser_nom_spectacle($s['nom'])] = (int) $s['id'];
    }
    $spectaclesACreer = []; // nom normalisé → nom original, pour ne compter/annoncer qu'une fois par lot

    $existe = db()->prepare(
        "SELECT 1 FROM evenements WHERE date = ? AND lower(trim(ville)) = lower(trim(?)) AND lower(trim(salle)) = lower(trim(?))"
    );
    $insEv = db()->prepare(
        "INSERT INTO evenements (spectacle_id, date, statut, visibilite, ville, region, pays, salle, lien_infos, lien_texte, remarques)
         VALUES (?, ?, ?, 'non_repertorie', ?, ?, ?, ?, ?, ?, ?)"
    );
    $insSpec = db()->prepare('INSERT INTO spectacles (nom) VALUES (?)');
    // Même liste blanche que le formulaire d'édition (route_evenement()) : un
    // pays hors liste stocké tel quel serait silencieusement effacé à la
    // première réouverture/sauvegarde de l'événement (le <select> ne peut pas
    // le présélectionner) — on normalise donc dès l'import.
    $paysDisponibles = evenements_pays_disponibles();

    $resultats = [];
    if (!$simule) {
        db()->beginTransaction();
    }
    try {
        foreach ($lignes as $ligne) {
            $r = str_getcsv($ligne, ',', '"', '');
            $resume['total']++;

            $dateRaw = $col($r, 'date');
            $ville   = $col($r, 'ville');
            $region  = $col($r, 'region');
            $pays    = $col($r, 'pays');
            $pays    = in_array($pays, $paysDisponibles, true) ? $pays : '';
            $lieu    = $col($r, 'lieu');
            $details = $col($r, 'details');
            $type    = $col($r, 'type');
            $statutRaw = mb_strtolower($col($r, 'statut'), 'UTF-8');
            $lien    = $col($r, 'lien');
            $lienTexte = $col($r, 'lien_texte');

            $ligneRes = ['date' => $dateRaw, 'ville' => $ville, 'lieu' => $lieu];
            $dateIso = date_csv_vers_iso($dateRaw);
            if ($dateIso === null || $ville === '') {
                $ligneRes['statut'] = 'erreur';
                $ligneRes['detail'] = $dateIso === null
                    ? "Date invalide (attendu JJ/MM/AAAA) : « $dateRaw »."
                    : 'Ville manquante.';
                $resume['erreurs']++; $resultats[] = $ligneRes; continue;
            }

            $existe->execute([$dateIso, $ville, $lieu]);
            if ($existe->fetchColumn()) {
                $ligneRes['statut'] = 'existant';
                $ligneRes['detail'] = 'Un événement à cette date/ville/salle existe déjà — ignoré.';
                $resume['existants']++; $resultats[] = $ligneRes; continue;
            }

            $spectacleId = null;
            if ($type !== '') {
                $norm = normaliser_nom_spectacle($type);
                if (isset($spectaclesParNom[$norm])) {
                    $spectacleId = $spectaclesParNom[$norm];
                } elseif (!isset($spectaclesACreer[$norm])) {
                    $spectaclesACreer[$norm] = $type;
                    $resume['spectacles_crees']++;
                    if (!$simule) {
                        $insSpec->execute([$type]);
                        $spectacleId = (int) db()->lastInsertId();
                        $spectaclesParNom[$norm] = $spectacleId;
                    }
                }
            }

            $statut = match ($statutRaw) {
                'annule', 'annulé' => 'annule',
                'option'           => 'option',
                default            => 'confirme',
            };
            $lienValide = ($lien !== '' && preg_match('#^https?://#i', $lien) && filter_var($lien, FILTER_VALIDATE_URL)) ? $lien : '';
            // Le texte du bouton n'a de sens que si le lien lui-même est valide.
            $lienTexte = $lienValide !== '' ? $lienTexte : '';

            $ligneRes['statut'] = 'nouveau';
            $resume['nouveaux']++;
            if (!$simule) {
                $insEv->execute([$spectacleId, $dateIso, $statut, $ville, $region, $pays, $lieu, $lienValide, $lienTexte, $details]);
            }
            $resultats[] = $ligneRes;
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
