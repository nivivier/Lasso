<?php
// Module booking — les campagnes de démarchage : statuts, jauges, réponses,
// projets liés, la carte du tableau de bord et le ciblage des structures.
// Chargé par lib/booking.php, jamais requis directement.

declare(strict_types=1);

// --- Campagnes de contact ----------------------------------------------------
//
// Une campagne est une sélection de structures à contacter pour un ou plusieurs
// projets, entre deux dates. Elle n'envoie rien elle-même : on contacte structure
// par structure, et la jauge compte les prises de contact déjà consignées.

const CAMPAGNE_STATUTS = [
    'a_venir'  => 'À venir',
    'en_cours' => 'En cours',
    'en_retard' => 'En retard',
    'terminee' => 'Terminée',
];

// Réponse reçue d'une structure démarchée. Portée par le lien campagne↔structure
// (migration_86) et non par la structure elle-même : la même salle peut décliner
// une tournée et prendre la suivante. « Aucune » est l'absence de réponse, donc
// la chaîne vide — c'est l'état de départ de toute ligne.
// Les quatre états du suivi d'une structure dans une campagne : les mêmes que
// les quatre parts de la barre d'avancement (campagne_repartition()), et donc
// que sa légende. « À contacter » et « Sans réponse » portent tous deux une
// réponse vide — ce qui les sépare est la prise de contact
// (campagne_contactee_sql()).
const CAMPAGNE_SUIVI = [
    'a_contacter'   => 'À contacter',
    'sans_reponse'  => 'Sans réponse',
    'pas_interesse' => 'Pas intéressé',
    'interesse'     => 'Intéressé',
];

const CAMPAGNE_REPONSES = [
    ''              => 'Aucune réponse',
    'pas_interesse' => 'Pas intéressé',
    'interesse'     => 'Intéressé',
];

// Une bulle de discussion, parce qu'il s'agit de ce que la structure a RÉPONDU :
// pointillée tant qu'elle n'a rien dit, barrée d'une croix quand c'est non, un
// cœur quand c'est oui.
//
// Les couleurs sont celles de la barre d'avancement, segment par segment : teal
// pour un oui — la couleur de ce qui est acquis dans toute l'application —,
// rouge pour un non, gris pour un contact établi dont la réponse se fait
// attendre : la balle est dans son camp, il n'y a rien à faire du nôtre.
// L'ambre, dans toute l'application, dit un geste à faire — une fiche à verser,
// une facture échue, une déclaration SUISA ; il revient donc à ce qui n'a pas
// encore été contacté, c'est-à-dire au fond de la piste.
const CAMPAGNE_REPONSES_ICONES = [
    ''              => 'message-circle-dashed',
    'pas_interesse' => 'message-circle-x',
    'interesse'     => 'message-circle-heart',
];
const CAMPAGNE_REPONSES_CLASSES_ICONE = [
    ''              => 'ico-muted',
    'pas_interesse' => 'ico-danger',
    'interesse'     => 'ico-ok',
];

// État d'une campagne, dans cet ordre de priorité :
//   terminée   — toutes les structures sélectionnées ont été contactées ;
//   à venir    — la date de début n'est pas arrivée (aucun envoi possible) ;
//   en retard  — la date de fin est passée sans que tout soit contacté ;
//   en cours   — le reste.
//
// Fonction pure : la date du jour est passée en argument, pour que le test
// n'ait pas à jouer avec l'horloge. Une campagne vide n'est jamais « terminée »,
// sinon elle s'annoncerait finie avant d'avoir commencé.
function campagne_statut(string $dateDebut, string $dateFin, int $nbTotal, int $nbContactes, string $aujourdhui): string
{
    if ($nbTotal > 0 && $nbContactes >= $nbTotal) {
        return 'terminee';
    }
    if ($dateDebut !== '' && $dateDebut > $aujourdhui) {
        return 'a_venir';
    }
    if ($dateFin !== '' && $dateFin < $aujourdhui) {
        return 'en_retard';
    }
    return 'en_cours';
}

// Date d'une campagne : jour seul, validé. Vide si la saisie n'est pas une date
// réelle — « 2026-13-45 » a la bonne forme mais n'existe pas, et une date
// impossible fausserait silencieusement le statut.
function campagne_date(string $saisie): string
{
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $saisie, $m)
        && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $saisie : '';
}

// Une campagne n'ouvre à l'envoi qu'une fois sa date de début atteinte : on
// prépare une campagne à l'avance sans risquer de partir trop tôt.
function campagne_ouverte(string $dateDebut, string $aujourdhui): bool
{
    return $dateDebut === '' || $dateDebut <= $aujourdhui;
}

// Projets d'une campagne, d'une entrée d'historique ou d'un modèle : la même
// forme de table de liaison partout, donc une seule fonction.
// $table => sa colonne porteuse : campagne_id, historique_id, modele_id.
const PROJETS_LIAISONS = [
    'campagne_projets'       => 'campagne_id',
    'fonds_campagne_projets' => 'campagne_id',
    'historique_projets'     => 'historique_id',
    'mailing_modele_projets' => 'modele_id',
];

function projets_lies(string $table, int $id): array
{
    $col = PROJETS_LIAISONS[$table] ?? null;
    if ($col === null || $id <= 0) {
        return [];
    }
    $stmt = db()->prepare("SELECT projet_id FROM $table WHERE $col = ? ORDER BY projet_id");
    $stmt->execute([$id]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

// Réécrit les projets liés : c'est une sélection, on la remplace en entier.
// Les identifiants sont filtrés contre les projets existants — un id forgé
// violerait la clé étrangère au lieu d'être simplement ignoré.
function projets_lier(string $table, int $id, array $projetIds): void
{
    $col = PROJETS_LIAISONS[$table] ?? null;
    if ($col === null || $id <= 0) {
        return;
    }
    db()->prepare("DELETE FROM $table WHERE $col = ?")->execute([$id]);
    $ids = array_values(array_unique(array_filter(array_map('intval', $projetIds))));
    if (!$ids) {
        return;
    }
    $stmt = db()->prepare('SELECT id FROM projets WHERE id IN (' . sql_in($ids) . ')');
    $stmt->execute($ids);
    $ins = db()->prepare("INSERT OR IGNORE INTO $table ($col, projet_id) VALUES (?, ?)");
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $sid) {
        $ins->execute([$id, (int) $sid]);
    }
}

// Structures d'une campagne déjà contactées POUR SES PROJETS : une entrée
// d'historique « prise de contact » (type « mailing »), liée à l'un de ses
// projets, datée du début de la campagne ou après.
//
// La borne de date est délibérée : un contact de l'an dernier sur le même
// projet ne doit pas faire passer pour faite une campagne qui commence.
// « Cette structure a-t-elle déjà été contactée POUR cette campagne ? », en SQL,
// prête à poser dans un WHERE, un ORDER BY ou un EXISTS. La règle : une prise de
// contact consignée (historique de type « mailing »), rattachée à l'un des
// projets de la campagne, et pas antérieure à son ouverture.
//
// Les valeurs sont écrites DANS la requête plutôt que liées : ce sont des
// identifiants de projet relus de la base (passés par intval()) et une date
// citée par PDO, jamais une saisie. Sans cela, la même condition ne pourrait pas
// servir dans un ORDER BY, où il n'y a pas de paramètres à lier.
//
// $colStructureId est l'expression qui désigne la structure dans la requête
// appelante (« cs.structure_id », « s.id »…), jamais une donnée d'utilisateur.
//
// Renvoie « 0 » — faux — quand la campagne n'a aucun projet : rien ne peut alors
// y être rattaché, donc rien n'y est contacté.
function campagne_contactee_sql(int $campagneId, string $colStructureId): string
{
    $c = campagne_charger($campagneId);
    $projets = $c ? projets_lies('campagne_projets', $campagneId) : [];
    if (!$projets) {
        return '0';
    }
    $ids = implode(',', array_map('intval', $projets));
    $sql = "EXISTS (SELECT 1 FROM historique h
                      JOIN historique_projets hs ON hs.historique_id = h.id
                     WHERE h.entite_type = 'structure' AND h.type = 'mailing'
                       AND h.entite_id = $colStructureId
                       AND hs.projet_id IN ($ids)";
    if ((string) $c['date_debut'] !== '') {
        $sql .= ' AND h.cree_le >= ' . db()->quote((string) $c['date_debut']);
    }
    return $sql . ')';
}

function campagne_structures_contactees(int $campagneId): array
{
    $cond = campagne_contactee_sql($campagneId, 'cs.structure_id');
    if ($cond === '0') {
        return [];
    }
    $stmt = db()->prepare(
        'SELECT cs.structure_id FROM campagne_structures cs
          WHERE cs.campagne_id = ? AND ' . $cond
    );
    $stmt->execute([$campagneId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function campagne_charger(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM campagnes WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

// Années couvertes par une campagne : de celle de son début à celle de sa fin.
// Une campagne à cheval sur deux ans appartient donc aux deux — c'est ce que le
// filtre « Période » de ?p=booking_campagnes doit dire. Une campagne sans aucune date
// n'appartient à aucune année : filtrer par année l'écarte, à raison, puisque
// rien ne la situe dans le temps.
function campagne_annees(string $dateDebut, string $dateFin): array
{
    $an = fn (string $d): ?int => preg_match('/^(\d{4})-\d{2}-\d{2}$/', trim($d), $m) ? (int) $m[1] : null;
    $debut = $an($dateDebut);
    $fin = $an($dateFin);
    if ($debut === null && $fin === null) {
        return [];
    }
    $debut = $debut ?? $fin;
    $fin = $fin ?? $debut;
    // Dates inversées (saisie fautive) : on prend l'intervalle dans l'ordre
    // plutôt que de rendre une liste vide, qui ferait disparaître la campagne.
    return range(min($debut, $fin), max($debut, $fin));
}

// Recherche texte sur une campagne : son nom et le nom de ses projets — les
// deux colonnes qu'on lit dans la liste. Insensible à la casse, comme la
// recherche des autres listes (recherche_sql() → LIKE).
function campagne_correspond(array $campagne, string $q): bool
{
    if (trim($q) === '') {
        return true;
    }
    $foin = (string) ($campagne['nom'] ?? '') . ' ' . implode(' ', (array) ($campagne['projets'] ?? []));
    return mb_stripos($foin, trim($q)) !== false;
}

// Campagnes où figure une structure, la plus récente d'abord, avec la réponse
// qu'elle y a donnée. Lue depuis la fiche de la structure : on y voit d'un coup
// d'œil ce qu'on lui a déjà proposé, et ce qu'elle en a dit.
function campagnes_de_structure(int $structureId): array
{
    $map = projet_map();
    $stmt = db()->prepare(
        'SELECT c.id, c.nom, c.date_debut, c.date_fin, cs.reponse
           FROM campagnes c
           JOIN campagne_structures cs ON cs.campagne_id = c.id
          WHERE cs.structure_id = ?
          ORDER BY c.date_debut DESC, c.id DESC'
    );
    $stmt->execute([$structureId]);
    $out = [];
    foreach ($stmt->fetchAll() as $c) {
        $c['projets'] = array_map(
            fn ($sid) => projet_chemin($sid, $map),
            projets_lies('campagne_projets', (int) $c['id'])
        );
        $out[] = $c;
    }
    return $out;
}

// Campagnes où figurent les structures LIÉES à celle-ci : celles qu'elle
// organise, et celle(s) qui l'organisent (structure_organisateurs, les deux
// sens). Une salle démarchée pour un projet dit quelque chose du festival qui
// la programme, et réciproquement — c'est la même information que les
// événements et les contacts liés, déjà repris sur la fiche.
//
// À part de campagnes_de_structure() et non fondues dedans : ce ne sont pas
// les campagnes de CETTE fiche. La réponse qu'on y lit est celle de l'autre
// structure, on ne peut ni la modifier ici ni retirer la ligne.
//
// Prend $liees telles que la route les a déjà lues (id, nom, sens) plutôt que
// de refaire la requête. Une ligne par couple (campagne, structure liée) : la
// même campagne peut viser la salle ET son organisateur, avec deux réponses
// différentes.
function campagnes_de_structures_liees(array $liees): array
{
    $infos = [];
    foreach ($liees as $l) {
        $infos[(int) $l['id']] = $l;
    }
    if (!$infos) {
        return [];
    }
    $map = projet_map();
    $projets = [];
    $out = [];
    foreach (lots_ids(array_keys($infos)) as $lot) {
        $stmt = db()->prepare(
            'SELECT c.id, c.nom, c.date_debut, c.date_fin, cs.reponse, cs.structure_id
               FROM campagnes c
               JOIN campagne_structures cs ON cs.campagne_id = c.id
              WHERE cs.structure_id IN (' . sql_in($lot) . ')'
        );
        $stmt->execute($lot);
        foreach ($stmt->fetchAll() as $c) {
            $cid = (int) $c['id'];
            // Les projets ne sont lus qu'une fois par campagne, même si
            // plusieurs structures liées y figurent.
            $projets[$cid] ??= array_map(
                fn ($sid) => projet_chemin($sid, $map),
                projets_lies('campagne_projets', $cid)
            );
            $lie = $infos[(int) $c['structure_id']];
            $out[] = $c + [
                'projets'        => $projets[$cid],
                'structure_nom'  => (string) $lie['nom'],
                'structure_sens' => (string) ($lie['sens'] ?? ''),
            ];
        }
    }
    // Même ordre que campagnes_de_structure() (la plus récente d'abord), le nom
    // de la structure liée départageant deux lignes de la même campagne.
    usort($out, fn ($a, $b) => [$b['date_debut'], (int) $b['id'], $a['structure_nom']]
        <=> [$a['date_debut'], (int) $a['id'], $b['structure_nom']]);
    return $out;
}

// Répartition d'une campagne en quatre parts qui somment TOUJOURS au total :
// intéressé, pas intéressé, contactées sans réponse, reste à contacter.
//
// « Sans réponse » n'est pas la colonne `reponse` vide — celle-ci couvre aussi
// ce qui n'a pas encore été contacté : c'est ce qui a été contacté moins ce qui
// a répondu. Le plancher à zéro n'est pas décoratif : on peut noter une réponse
// sans avoir consigné de prise de contact, et sans lui les segments
// dépasseraient la largeur de la barre.
function campagne_repartition(int $total, int $faits, int $interesse, int $refus): array
{
    $sansReponse = max(0, $faits - $interesse - $refus);
    return [
        'interesse'   => $interesse,
        'refus'       => $refus,
        'sansReponse' => $sansReponse,
        'aContacter'  => max(0, $total - $interesse - $refus - $sansReponse),
    ];
}

// La barre d'avancement d'une campagne : une piste, trois segments posés dessus
// (le quatrième — ce qui reste — EST la piste). Rendue ici plutôt que dans
// chaque vue : la carte de ?p=booking_campagne et la liste de ?p=booking_campagnes montrent la
// même chose, elles doivent la montrer pareil.
function campagne_barre_html(array $parts, int $total, string $classe = ''): string
{
    $titre = $parts['interesse'] . ' intéressé, ' . $parts['refus'] . ' pas intéressé, '
           . $parts['sansReponse'] . ' sans réponse, ' . $parts['aContacter'] . ' à contacter';
    return barre_segments_html([
        'interesse'   => ['camp-oui', (float) $parts['interesse']],
        'refus'       => ['camp-non', (float) $parts['refus']],
        'sansReponse' => ['camp-attente', (float) $parts['sansReponse']],
    ], (float) $total, $titre, $classe);
}


// Réponses reçues, comptées pour TOUTES les campagnes en une requête — de quoi
// dresser la liste sans une requête par ligne. [campagne_id => [réponse => n]].
function campagnes_reponses_comptes(): array
{
    $out = [];
    foreach (db()->query('SELECT campagne_id, reponse, COUNT(*) AS n FROM campagne_structures GROUP BY campagne_id, reponse') as $l) {
        $out[(int) $l['campagne_id']][(string) $l['reponse']] = (int) $l['n'];
    }
    return $out;
}

// Avancement de TOUTES les campagnes en une requête : [campagne_id => nombre de
// structures déjà contactées]. Même définition que
// campagne_structures_contactees() — une prise de contact (historique de type
// « mailing ») portant l'un des projets de la campagne, datée de son début ou
// après — mais posée une fois pour l'ensemble, au lieu d'une poignée de
// requêtes par ligne de l'onglet.
//
// Les conditions qui croisent plusieurs alias sont dans le WHERE et non dans
// les JOIN … ON : le SQLite d'un hébergement mutualisé est difficile là-dessus
// (docs/DECISIONS.md § Le SQLite d'un hébergement mutualisé).
function campagnes_contactees_comptes(): array
{
    $sql = "SELECT cs.campagne_id AS cid, COUNT(DISTINCT cs.structure_id) AS n
              FROM campagne_structures cs
              JOIN campagnes c ON c.id = cs.campagne_id
              JOIN campagne_projets cp ON cp.campagne_id = cs.campagne_id
              JOIN historique_projets hs ON hs.projet_id = cp.projet_id
              JOIN historique h ON h.id = hs.historique_id
             WHERE h.entite_type = 'structure'
               AND h.type = 'mailing'
               AND h.entite_id = cs.structure_id
               AND (c.date_debut = '' OR h.cree_le >= c.date_debut)
             GROUP BY cs.campagne_id";
    $out = [];
    foreach (db()->query($sql) as $l) {
        $out[(int) $l['cid']] = (int) $l['n'];
    }
    return $out;
}

// Les campagnes avec, pour chacune, ses projets, son effectif et son avancement
// — en quatre requêtes pour tout l'onglet, quel qu'en soit le nombre.
function campagnes_liste(): array
{
    $map = projet_map();
    // Projets et effectifs de toutes les campagnes, groupés d'avance.
    $projetsParCampagne = [];
    foreach (db()->query('SELECT campagne_id, projet_id FROM campagne_projets ORDER BY projet_id') as $l) {
        $projetsParCampagne[(int) $l['campagne_id']][] = (int) $l['projet_id'];
    }
    $totaux = [];
    foreach (db()->query('SELECT campagne_id, COUNT(*) AS n FROM campagne_structures GROUP BY campagne_id') as $l) {
        $totaux[(int) $l['campagne_id']] = (int) $l['n'];
    }
    $faitsParCampagne = campagnes_contactees_comptes();
    $reponsesParCampagne = campagnes_reponses_comptes();

    $aujourdhui = date('Y-m-d');
    $out = [];
    foreach (db()->query('SELECT * FROM campagnes ORDER BY date_debut DESC, id DESC') as $c) {
        $id = (int) $c['id'];
        $projetIds = $projetsParCampagne[$id] ?? [];
        $total = $totaux[$id] ?? 0;
        $faits = $faitsParCampagne[$id] ?? 0;
        $out[] = $c + [
            'projets'    => array_map(fn ($sid) => projet_chemin($sid, $map), $projetIds),
            // Les pastilles des mêmes projets, dans le même ordre : une icône
            // se repère plus vite qu'un nom dans une liste de campagnes.
            'projets_pastilles' => array_map(fn ($sid) => projet_pastille_html($sid, $map), $projetIds),
            // Les mêmes projets en identifiants : c'est sur eux que filtre la liste.
            'projet_ids' => $projetIds,
            'annees'     => campagne_annees((string) $c['date_debut'], (string) $c['date_fin']),
            'nb_total'   => $total,
            'nb_faits'   => $faits,
            'repartition' => campagne_repartition(
                $total, $faits,
                $reponsesParCampagne[$id]['interesse'] ?? 0,
                $reponsesParCampagne[$id]['pas_interesse'] ?? 0
            ),
            'statut'     => campagne_statut((string) $c['date_debut'], (string) $c['date_fin'], $total, $faits, $aujourdhui),
        ];
    }
    return $out;
}

// Les trois tranches de la liste des campagnes : ce qui demande du travail
// maintenant, ce qui vient, ce qui est derrière.
//
// « En retard » est rangée avec « en cours », comme sur le tableau de bord
// (CAMPAGNES_DASHBOARD_ORDRE, campagnes_actives()) : ce sont les deux états où il reste des
// structures à contacter. La classer dans « passées » aurait rangé du travail
// à faire avec ce qui est fini.
//
// Ces deux états-là forment le démarchage ouvert : une campagne « en retard »
// n'est qu'une campagne en cours dont la date de fin est passée. Une seule
// définition, pour que la tranche de la liste, la carte du tableau de bord et
// les lignes qu'elle met en évidence parlent des mêmes campagnes.
const CAMPAGNE_STATUTS_OUVERTS = ['en_retard', 'en_cours'];

const CAMPAGNES_GROUPES = [
    ['titre' => 'En cours', 'statuts' => CAMPAGNE_STATUTS_OUVERTS],
    ['titre' => 'À venir',  'statuts' => ['a_venir']],
    ['titre' => 'Passées',  'statuts' => ['terminee']],
];

// Ordre CHRONOLOGIQUE : la campagne commencée en premier d'abord. L'id départage
// deux mêmes dates, pour que l'ordre ne dépende pas de celui de la requête.
//
// C'est l'inverse de campagnes_liste(), qui range la plus récente en tête —
// bon pour une liste qu'on consulte, pas pour un suivi. Deux écrans s'en
// servent, et les deux rangent donc pareil : la tranche « à venir » de la liste
// des campagnes (campagnes_groupees()), où elle met en tête ce qui arrive le
// plus tôt plutôt que l'échéance la plus lointaine, et la carte du tableau de
// bord (campagnes_dashboard()), qui se parcourt comme un agenda.
function campagne_cmp_debut(array $a, array $b): int
{
    return [(string) $a['date_debut'], (int) $a['id']] <=> [(string) $b['date_debut'], (int) $b['id']];
}

// Range une liste de campagnes dans ces tranches, dans l'ordre, en sautant
// celles qui n'ont rien : [['titre' => …, 'campagnes' => [...]], …]. L'ordre
// interne de $campagnes est conservé (la plus récente d'abord), sauf la
// tranche « à venir » — voir campagne_cmp_debut().
function campagnes_groupees(array $campagnes): array
{
    $out = [];
    foreach (CAMPAGNES_GROUPES as $groupe) {
        $lot = array_values(array_filter(
            $campagnes,
            fn ($c) => in_array((string) $c['statut'], $groupe['statuts'], true)
        ));
        if (!$lot) {
            continue;
        }
        if ($groupe['statuts'] === ['a_venir']) {
            usort($lot, 'campagne_cmp_debut');
        }
        $out[] = ['titre' => $groupe['titre'], 'campagnes' => $lot];
    }
    return $out;
}

// Les campagnes actuellement EN COURS, en [id => true]. Trois requêtes pour
// toutes les campagnes, quel qu'en soit le nombre.
//
// Passe par campagne_statut() plutôt que par une comparaison de dates : « en
// cours » veut aussi dire « il reste des structures à contacter » — une
// campagne entièrement démarchée est terminée avant sa date de fin. Une seule
// définition, pour qu'une campagne ne soit pas « en cours » ici et « terminée »
// sur ?p=booking_campagnes.
function campagnes_en_cours_ids(): array
{
    $totaux = [];
    foreach (db()->query('SELECT campagne_id, COUNT(*) AS n FROM campagne_structures GROUP BY campagne_id') as $l) {
        $totaux[(int) $l['campagne_id']] = (int) $l['n'];
    }
    $faits = campagnes_contactees_comptes();
    $aujourdhui = date('Y-m-d');
    $out = [];
    foreach (db()->query('SELECT id, date_debut, date_fin FROM campagnes') as $c) {
        $id = (int) $c['id'];
        $statut = campagne_statut(
            (string) $c['date_debut'],
            (string) $c['date_fin'],
            $totaux[$id] ?? 0,
            $faits[$id] ?? 0,
            $aujourdhui
        );
        if ($statut === 'en_cours') {
            $out[$id] = true;
        }
    }
    return $out;
}

// Campagnes d'un lot de structures : [structure_id => [[id, nom, en_cours], …]].
// Agrégées en une requête pour toute une liste, comme les tags — les rechercher
// ligne à ligne ferait une requête par structure affichée.
function structures_campagnes(array $ids): array
{
    $enCours = campagnes_en_cours_ids();
    $out = [];
    foreach (lots_ids($ids) as $lot) {
        $stmt = db()->prepare(
            'SELECT cs.structure_id, c.id, c.nom
               FROM campagne_structures cs JOIN campagnes c ON c.id = cs.campagne_id
              WHERE cs.structure_id IN (' . sql_in($lot) . ')
              ORDER BY c.date_debut DESC, c.id DESC'
        );
        $stmt->execute($lot);
        foreach ($stmt->fetchAll() as $l) {
            $out[(int) $l['structure_id']][] = [
                (int) $l['id'], (string) $l['nom'], isset($enCours[(int) $l['id']]),
            ];
        }
    }
    return $out;
}

// Les campagnes d'UNE structure — après un ajout ou un retrait, pour re-rendre
// sa seule cellule.
function structure_campagnes(int $structureId): array
{
    return structures_campagnes([$structureId])[$structureId] ?? [];
}

// Cellule « Campagnes » d'une ligne de ?p=structures : une pastille par
// campagne, sa croix de retrait, et le « + » qui ouvre la liste des autres.
// Même forme et même mécanique que la cellule des étiquettes
// (structure_tags_cellule_html()) — c'est le même geste, sur une autre liaison.
function structure_campagnes_cellule_html(int $structureId, array $campagnes, bool $peutEcrire): string
{
    return campagnes_cellule_html($structureId, $campagnes, $peutEcrire, [
        'route' => 'booking_campagne',
        // Ce que CE retrait détruit, dit par celui qui le sait : ici une
        // réponse. La cellule jumelle de la recherche de fonds efface tout un
        // dossier, et le dit autrement (fonds_campagnes_cellule_html()).
        'confirme' => fn (string $nom): string => 'Retirer cette structure de la campagne « ' . $nom
            . ' » ? La réponse qui y est notée sera perdue.',
        'ajouter_title' => 'Ajouter à une campagne',
        'ajouter_aria'  => 'Ajouter à une campagne',
    ]);
}

// La colonne « Campagnes de booking » du tableau des structures, telle que
// views/_structures_table.php l'attend. Décrite ici et non dans chaque vue :
// deux écrans la posent (la liste des structures et le suivi d'une campagne),
// et la colonne jumelle de la recherche de fonds se décrit de la même façon
// (colonne_campagnes_fonds(), lib/fonds.php).
//
// « de booking » en toutes lettres : l'application a deux sortes de campagnes,
// et cette liste de structures est partagée par les deux modules.
function colonne_campagnes_booking(array $parStructure, bool $peutEcrire, string $filtre = ''): array
{
    return [
        // Chaque ligne insécable : le <br> doit être la seule coupure, sinon la
        // colonne, étroite, replie le titre sur trois lignes et grandit en
        // hauteur au lieu de grandir en largeur.
        'titre'         => '<span class="nowrap">Campagnes</span><br><span class="nowrap">de booking</span>',
        'filtre'        => $filtre,
        'par_structure' => $parStructure,
        'cellule'       => fn (int $sid, array $campagnes): string
            => structure_campagnes_cellule_html($sid, $campagnes, $peutEcrire),
    ];
}

// --- Campagnes du tableau de bord -------------------------------------------
//
// Ce que le tableau de bord montre du démarchage : les campagnes qui demandent
// du travail d'abord, celles qui viennent ensuite, celles qui sont derrière en
// dernier — et seulement ce qui tient dans la carte.
//
// « En retard » est rangée avec « en cours » : ce sont les deux états où il
// reste des structures à contacter, et le retard mérite d'être vu en premier.
const CAMPAGNES_DASHBOARD_ORDRE = ['en_retard', 'en_cours', 'a_venir', 'terminee'];

// $liste évite de relire la base quand l'appelant tient déjà les campagnes
// (le tableau de bord s'en sert aussi pour campagnes_dashboard_a_venir()).
//
// **Dès qu'une campagne est active, la carte ne montre plus qu'elles** : ce
// qu'on vient y chercher, c'est où en est le démarchage du moment. Les
// campagnes pas encore commencées se résument alors à une ligne « et X autres
// à venir » (campagnes_dashboard_a_venir()), et les campagnes passées sortent
// de la carte — elles sont derrière, la liste complète les garde. Sans campagne
// active, la carte montre la suite comme avant : à venir, puis terminées.
// « Active » est plus large qu'« ouverte » : voir campagnes_actives().
function campagnes_dashboard(int $max = 9, ?array $liste = null): array
{
    $rang = array_flip(CAMPAGNES_DASHBOARD_ORDRE);
    $liste ??= campagnes_liste();
    $actives = campagnes_actives($liste);
    if ($actives) {
        $liste = $actives;
    }
    // Une campagne bouclée dont la saison court se range AVEC celles en cours :
    // à l'écran rien ne les distingue — même saison, même barre d'avancement —,
    // et leur donner un rang à part les reléguait en fin de carte, en travers de
    // l'ordre chronologique. Seul « en retard » garde le sien, en tête : c'est le
    // seul état que la carte montre autrement (une étiquette, pas une barre).
    $rangDe = function (array $c) use ($rang): int {
        if ((string) $c['statut'] === 'terminee' && periode_courante($c)) {
            return $rang['en_cours'];
        }
        return $rang[(string) $c['statut']] ?? 9;
    };
    // À rang égal, l'ordre est CHRONOLOGIQUE : la campagne commencée en premier
    // ouvre la liste (campagne_cmp_debut()). La carte se lit comme un agenda —
    // on y suit une saison, et une saison se parcourt dans le sens du temps ;
    // l'ordre de campagnes_liste(), la plus récente d'abord, convient à une
    // liste qu'on consulte, pas à un suivi. Même règle pour les campagnes à
    // venir, où elle met en tête ce qui arrive le plus tôt — sans quoi la
    // carte, qui ne montre que les neuf premières, gardait les échéances
    // lointaines et coupait celles qui approchent.
    usort($liste, fn (array $a, array $b): int => $rangDe($a) <=> $rangDe($b)
        ?: campagne_cmp_debut($a, $b));
    return array_slice($liste, 0, $max);
}

// Les campagnes de la saison en cours : celles où il reste à contacter (« en
// cours » et « en retard »), PLUS celles dont tout le monde est déjà contacté
// mais dont la période court encore.
//
// Ces dernières ne sont pas « ouvertes » au sens du statut — campagne_statut()
// les bascule sur « terminée » dès le dernier contact, quelles que soient les
// dates — mais elles restent la campagne du moment : les faire disparaître de
// la carte le jour où on finit de démarcher donnerait l'impression qu'il ne se
// passe plus rien. Elles y figurent simplement sans fond ambre, puisqu'elles
// n'attendent plus aucun geste.
function campagnes_actives(array $liste, string $aujourdhui = ''): array
{
    return array_values(array_filter(
        $liste,
        fn (array $c): bool => in_array((string) $c['statut'], CAMPAGNE_STATUTS_OUVERTS, true)
            || ((string) $c['statut'] === 'terminee' && periode_courante($c, $aujourdhui))
    ));
}


// Ce que la carte du tableau de bord laisse de côté quand elle se limite aux
// campagnes actives : celles qui n'ont pas commencé. Zéro quand la carte les
// montre déjà — la même règle que campagnes_dashboard(), au même endroit, pour
// que la ligne « et X autres à venir » ne puisse pas mentir.
function campagnes_dashboard_a_venir(array $liste): int
{
    if (!campagnes_actives($liste)) {
        return 0;
    }
    return count(array_filter($liste, fn ($c) => $c['statut'] === 'a_venir'));
}

// Tout ce qu'il faut à un écran de CIBLAGE, quel qu'il soit : les filtres lus
// dans l'URL, le résultat du ciblage ou les structures déjà retenues, celles
// ajoutées à l'unité par la recherche, et les listes qui peuplent les
// entonnoirs. Deux écrans s'en servent — composer une campagne de démarchage
// (?p=booking_campagne_form) et composer une recherche de fonds (?p=fonds_campagne_form)
// —, parce qu'ils posent la même question : à qui s'adresse-t-on ?
//
// Rendu : le tableau à passer tel quel à la vue (ses clés sont celles que
// views/_structures_table.php et views/_structures_filtres.php attendent).
//
// $retenues : les structures DÉJÀ liées à l'objet qu'on modifie — ce sont elles
// qui sont cochées, et non le résultat du ciblage, qui a pu bouger depuis.
// $get : la requête, d'où viennent les filtres, la prévisualisation et les
// ajouts — l'écran recharge à chaque entonnoir, tout voyage donc dans l'URL.
function ciblage_structures_preparer(array $retenues, array $get): array
{
    $criteres = mailing_criteres_depuis($get);
    $previsualise = isset($get['previsualiser']);
    $apercu = $previsualise ? mailing_structures_eligibles($criteres) : [];

    // Les structures à cocher : celles du ciblage si l'on vient de prévisualiser,
    // sinon celles déjà retenues.
    if (!$apercu && $retenues) {
        // En lots : une campagne peut retenir plusieurs milliers de structures,
        // et tout lier d'un coup dépasserait le plafond de paramètres de SQLite.
        $apercu = [];
        foreach (lots_ids($retenues) as $lot) {
            $stmt = db()->prepare('SELECT * FROM structures WHERE id IN (' . sql_in($lot) . ')');
            $stmt->execute($lot);
            $apercu = array_merge($apercu, $stmt->fetchAll());
        }
        usort($apercu, fn ($a, $b) => strcasecmp((string) $a['nom'], (string) $b['nom']));
    }
    // Structures ajoutées une à une par la recherche du formulaire : elles
    // rejoignent la liste au même titre que le résultat du ciblage, et y sont
    // cochées — on ne va pas chercher une structure pour la décocher.
    //
    // Elles voyagent dans l'URL (?ajout[]=), comme les filtres et comme la
    // saisie en cours : le moindre entonnoir recharge la page, et sans ça
    // l'ajout serait perdu au filtre suivant.
    $ajouts = array_values(array_unique(array_filter(array_map('intval', (array) ($get['ajout'] ?? [])))));
    if ($ajouts) {
        $manquants = array_diff($ajouts, array_map(fn ($s) => (int) $s['id'], $apercu));
        foreach (lots_ids($manquants) as $lot) {
            $stmt = db()->prepare('SELECT * FROM structures WHERE id IN (' . sql_in($lot) . ')');
            $stmt->execute($lot);
            $apercu = array_merge($apercu, $stmt->fetchAll());
        }
        // Même ordre que les deux sources ci-dessus (ORDER BY nom) : une
        // structure ajoutée se retrouve à sa place alphabétique, pas en bout
        // de liste où il faudrait la chercher.
        usort($apercu, fn ($a, $b) => strcasecmp((string) $a['nom'], (string) $b['nom']));
    }
    // Le tableau de sélection est celui de ?p=structures (views/_structures_table.php) :
    // ses colonnes viennent donc des mêmes agrégats, complétés ici pour les seules
    // lignes affichées — le ciblage passe par mailing_structures_eligibles(), qui
    // ne rend que la table `structures`.
    $ids = array_map(fn ($s) => (int) $s['id'], $apercu);
    $colonnes = structures_colonnes_liste($ids);
    foreach ($apercu as &$ligne) {
        $ligne += $colonnes[(int) $ligne['id']] ?? [];
    }
    unset($ligne);

    return [
        'criteres'     => $criteres,
        'apercu'       => $apercu,
        'retenues'     => $retenues,
        'ajouts'       => $ajouts,
        'previsualise' => $previsualise,
        'nbEvenements' => module_actif('evenements') ? structures_nb_evenements($ids) : [],
        'tags' => db()->query('SELECT * FROM structure_tags ORDER BY nom')->fetchAll(),
        // Pour l'entonnoir « Campagnes » du ciblage : composer une sélection en
        // écartant les structures déjà démarchées par une autre, ou au contraire
        // en les reprenant, est une question qu'on se pose souvent.
        'campagnesDispo' => db()->query('SELECT id, nom FROM campagnes ORDER BY date_debut DESC, id DESC')->fetchAll(),
        'regions' => db()->query("SELECT DISTINCT departement_canton FROM structures WHERE departement_canton <> '' ORDER BY departement_canton")->fetchAll(PDO::FETCH_COLUMN),
        'grandesRegions' => pays_regions_map(),
        'villes' => db()->query("SELECT DISTINCT adresse_localite FROM structures WHERE adresse_localite <> '' ORDER BY adresse_localite")->fetchAll(PDO::FETCH_COLUMN),
        'categoriesPourSelect' => structure_categories_pour_select(),
    ];
}

// Le second étage du ciblage : tout ce dont la VUE a besoin pour dessiner les
// entonnoirs — les étiquettes de chaque filtre, les paramètres à reporter d'un
// panneau à l'autre, et ceux qu'emporte le champ d'ajout.
//
// Les filtres pilotent l'URL (GET) et non le formulaire d'enregistrement :
// prévisualiser ne doit rien écrire. Ce qui est déjà saisi voyage donc AVEC
// eux, en paramètres reportés dans chaque panneau — sans cela, cocher une
// catégorie rechargeait la page et effaçait le nom, les dates et les projets
// qu'on venait de choisir.
//
// $saisie : les champs en cours de saisie, propres à l'écran appelant (nom,
// dates, projets…) ; ils reviennent par l'URL et la route les relit.
function ciblage_filtres_vue(array $ciblage, array $saisie, int $id): array
{
    $criteres = $ciblage['criteres'];
    $labels = fn (array $vals): array => array_combine($vals, $vals) ?: [];

    $categorieLabels = [];
    foreach ($ciblage['categoriesPourSelect'] as $c) {
        $categorieLabels[(int) $c['id']] = str_repeat("\u{00A0}\u{00A0}", (int) ($c['profondeur'] ?? 0)) . $c['nom'];
    }
    // « Aucun » en tête des deux listes de liaison, comme sur ?p=structures
    // (views/_structures_filtres.php) : « lesquelles n'ont encore aucun tag ? »,
    // « lesquelles ne sont dans aucune campagne ? » sont justement les questions
    // qu'on pose en composant une sélection.
    $tagLabels = [];
    foreach ($ciblage['tags'] as $t) { $tagLabels[(int) $t['id']] = $t['nom']; }
    if ($tagLabels) { $tagLabels = ['aucun' => 'Aucun tag'] + $tagLabels; }
    $campagneLabels = [];
    foreach ($ciblage['campagnesDispo'] as $c) { $campagneLabels[(int) $c['id']] = $c['nom']; }
    if ($campagneLabels) { $campagneLabels = ['aucun' => 'Aucune campagne'] + $campagneLabels; }
    // Statut : seulement les statuts contactables. Un ciblage ne sort jamais de
    // ceux-là (mailing_structures_eligibles()) — proposer « Inactif » aurait
    // offert un filtre qui ne rend jamais rien.
    $statutLabels = [];
    foreach (STRUCTURE_STATUTS_CONTACTABLES as $st) { $statutLabels[$st] = structure_statut_libelle($st); }
    $paysLabels = [];
    foreach (array_keys($ciblage['grandesRegions']) as $pays) { $paysLabels[$pays] = $pays; }
    $grandeRegionLabels = [];
    foreach ($ciblage['grandesRegions'] as $regions) {
        foreach ($regions as $r) { $grandeRegionLabels[$r] = $r; }
    }

    $base = ['id' => $id ?: null, 'previsualiser' => '1'];
    $criteresActifs = array_filter([
        'categorie_id' => $criteres['categorie_id'], 'tag_id' => $criteres['tag_id'],
        'campagne_id' => $criteres['campagne_id'], 'statut' => $criteres['statut'],
        'pays' => $criteres['pays'], 'grande_region' => $criteres['grande_region'],
        'departement_canton' => $criteres['departement_canton'], 'ville' => $criteres['ville'],
    ]);
    // Les structures ajoutées à la main voyagent avec le reste : un entonnoir
    // recharge la page, et sans elles l'ajout disparaîtrait au filtre suivant.
    $saisie['ajout'] = array_map('strval', $ciblage['ajouts']);
    $tousFiltres = array_filter($criteresActifs + array_filter($saisie) + array_filter($base));
    // Le formulaire d'ajout emporte l'état de la page, moins « previsualiser »
    // tant qu'aucun critère n'est posé : sans critère, prévisualiser veut dire
    // TOUTES les structures — ce n'est pas ce qu'on demande en cherchant un nom.
    $ajoutParams = $tousFiltres;
    if (!$criteresActifs) {
        unset($ajoutParams['previsualiser']);
    }

    return [
        'labels' => $labels,
        'categorieLabels' => $categorieLabels, 'tagLabels' => $tagLabels,
        'campagneLabels' => $campagneLabels, 'statutLabels' => $statutLabels,
        'paysLabels' => $paysLabels, 'grandeRegionLabels' => $grandeRegionLabels,
        'criteresActifs' => $criteresActifs, 'tousFiltres' => $tousFiltres,
        'autres' => autres_filtres_fn($tousFiltres), 'ajoutParams' => $ajoutParams,
    ];
}
