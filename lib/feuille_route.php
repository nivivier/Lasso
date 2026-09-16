<?php
// Feuille de route d'un événement : ce qu'il faut avoir sous les yeux le jour
// même. Une liste ORDONNÉE d'éléments de types différents — un horaire, puis
// l'adresse de l'hôtel, puis le contact du régisseur, puis la fiche technique —
// parce que c'est ainsi qu'on la lit : dans l'ordre de la journée, pas rangée
// par catégorie.
//
// Rien de ce qui est ici n'est public. La feuille de route ne part jamais dans
// l'export du site (voir evenement_export_donnees(), lib/evenements.php) :
// l'adresse d'un hébergement, un code d'entrée, le portable d'un régisseur et
// une confirmation d'hôtel n'ont rien à y faire.

// Les cinq types d'élément, dans l'ordre où le menu « Ajouter » les propose.
// « champs » énumère les colonnes que ce type remplit — le reste de la ligne
// reste vide (voir migration_89).
//
// « aide » sert de texte indicatif DANS le champ d'intitulé, et de bulle sur le
// bouton du type. Il tenait auparavant sur une ligne de description au-dessus du
// formulaire : dire deux fois la même chose, une fois en gris au-dessus du champ
// et une fois dedans, allongeait le formulaire sans rien apprendre.
const FEUILLE_TYPES = [
    'horaire' => [
        'libelle' => 'Horaire',
        'icone'   => 'clock',
        'aide'    => 'Get-in, balances, catering, show…',
        'champs'  => ['libelle', 'debut', 'fin', 'remarque'],
    ],
    'adresse' => [
        'libelle' => 'Adresse',
        'icone'   => 'map-pin',
        'aide'    => 'Salle, hôtel, parking, loge…',
        'champs'  => ['libelle', 'adresse', 'remarque'],
    ],
    'contact' => [
        'libelle' => 'Contact',
        'icone'   => 'user',
        'aide'    => 'Régisseur, accueil artiste, hébergement…',
        'champs'  => ['libelle', 'contact_id', 'prenom', 'nom', 'telephone', 'email', 'remarque'],
    ],
    'fichier' => [
        'libelle' => 'Pièce jointe',
        'icone'   => 'file-text',
        'aide'    => 'Fiche technique, réservation d\'hôtel…',
        'champs'  => ['libelle', 'remarque'],
    ],
    'note' => [
        'libelle' => 'Note',
        'icone'   => 'message-square',
        'aide'    => 'Tout ce qui ne rentre pas ailleurs…',
        'champs'  => ['libelle', 'remarque'],
    ],
];

// Le déroulé type d'une journée. D'une date à l'autre, ce sont les mêmes cinq
// moments, dans le même ordre — seules les heures changent. Les proposer d'un
// coup évite de retaper cinq fois les mêmes mots ; ce qui ne sert pas se
// supprime, ce qui manque s'ajoute, comme n'importe quelle autre ligne.
const FEUILLE_HORAIRES_TYPES = ['Départ', 'Get-in', 'Soundcheck', 'Repas', 'Show'];

// Pose le déroulé type sur une feuille de route, sans heures — elles se
// remplissent ensuite, ligne par ligne. N'ajoute que les intitulés ABSENTS :
// deux clics de suite ne doivent pas donner deux « Get-in », et l'on peut
// compléter un déroulé déjà entamé. Retourne le nombre de lignes ajoutées.
function feuille_deroule_type(int $evenementId): int
{
    $stmt = db()->prepare("SELECT libelle FROM evenement_feuille WHERE evenement_id = ? AND type = 'horaire'");
    $stmt->execute([$evenementId]);
    $deja = array_map(
        fn (string $l): string => mb_strtolower(trim($l), 'UTF-8'),
        $stmt->fetchAll(PDO::FETCH_COLUMN)
    );
    $ordre = feuille_ordre_suivant($evenementId);
    $ins = db()->prepare("INSERT INTO evenement_feuille (evenement_id, type, ordre, libelle) VALUES (?, 'horaire', ?, ?)");
    $ajoutes = 0;
    foreach (FEUILLE_HORAIRES_TYPES as $libelle) {
        if (in_array(mb_strtolower($libelle, 'UTF-8'), $deja, true)) {
            continue;
        }
        $ins->execute([$evenementId, $ordre++, $libelle]);
        $ajoutes++;
    }
    return $ajoutes;
}

// Dossier des pièces jointes : sous data/, et non dans uploads/ comme les logos.
// C'est toute la différence — uploads/ est délibérément servi par le serveur web,
// data/ est délibérément refusé (« Require all denied » dans data/.htaccess, et
// « RedirectMatch 404 ^/data/ » dans le .htaccess racine, qui ne dépend d'aucun
// fichier du dossier lui-même). Ces pièces-là — réservations d'hôtel, contrats,
// fiches techniques — portent des noms, des numéros de chambre et des montants :
// elles ne sont servies que par route_evenement_fichier(), qui vérifie qui
// demande.
//
// ⚠️ En développement (php -S), .htaccess est ignoré : tout le dossier data/ est
// alors lisible, base comprise. C'est déjà le cas sans ces fichiers, et ce n'est
// vrai que là.
function feuille_fichiers_dir(): string
{
    return dirname(__DIR__) . '/data/fichiers';
}

// Ce qu'on accepte en pièce jointe : extension servie => type MIME réel attendu.
// La vérification porte sur le CONTENU (finfo), jamais sur le nom du fichier.
// Assez large pour couvrir ce qu'un organisateur envoie — un PDF le plus
// souvent, parfois un scan, parfois un document bureautique.
const FEUILLE_FICHIERS_MIMES = [
    'application/pdf' => 'pdf',
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/webp'      => 'webp',
    'image/heic'      => 'heic',
    'text/plain'      => 'txt',
    'text/csv'        => 'csv',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'       => 'xlsx',
    'application/vnd.oasis.opendocument.text'        => 'odt',
    'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
];

// 8 Mo : une fiche technique avec ses plans de scène dépasse largement les 2 Mo
// tolérés pour un logo.
const FEUILLE_FICHIER_MAX = 8388608;

// Les éléments d'une feuille de route, dans l'ordre. Le contact repris du carnet
// d'adresses est résolu ici, à la lecture, et non recopié à l'enregistrement :
// un numéro de téléphone qui change sur la fiche doit changer sur la feuille.
function feuille_elements(int $evenementId): array
{
    $stmt = db()->prepare(
        'SELECT f.*, c.prenom AS c_prenom, c.nom AS c_nom, c.role AS c_role,
                c.telephone AS c_telephone, c.email AS c_email, s.nom AS s_nom
           FROM evenement_feuille f
           LEFT JOIN structure_contacts c ON c.id = f.contact_id
           LEFT JOIN structures s ON s.id = f.structure_id
          WHERE f.evenement_id = ?
          ORDER BY f.ordre, f.id'
    );
    $stmt->execute([$evenementId]);
    return $stmt->fetchAll();
}

// Un élément par son id, ou null.
function feuille_element(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM evenement_feuille WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

// Rang du prochain élément ajouté : à la fin de la liste.
function feuille_ordre_suivant(int $evenementId): int
{
    $stmt = db()->prepare('SELECT COALESCE(MAX(ordre), 0) + 1 FROM evenement_feuille WHERE evenement_id = ?');
    $stmt->execute([$evenementId]);
    return (int) $stmt->fetchColumn();
}

// Monte ou descend un élément d'un cran, en échangeant son rang avec celui de
// son voisin. Rien à faire s'il est déjà au bout.
//
// L'échange porte sur les rangs stockés et non sur des positions recalculées :
// deux éléments créés dans la même seconde peuvent partager un rang (import,
// reprise), et une renumérotation globale à chaque déplacement rendrait le
// geste imprévisible. On réaligne donc les rangs d'abord.
function feuille_deplacer(int $id, string $sens): void
{
    $el = feuille_element($id);
    if (!$el) {
        return;
    }
    $evenementId = (int) $el['evenement_id'];
    feuille_renumeroter($evenementId);
    $el = feuille_element($id);
    $ordre = (int) $el['ordre'];
    $cible = $sens === 'monter' ? $ordre - 1 : $ordre + 1;

    $stmt = db()->prepare('SELECT id FROM evenement_feuille WHERE evenement_id = ? AND ordre = ?');
    $stmt->execute([$evenementId, $cible]);
    $voisin = (int) ($stmt->fetchColumn() ?: 0);
    if (!$voisin) {
        return;
    }
    $maj = db()->prepare('UPDATE evenement_feuille SET ordre = ? WHERE id = ?');
    $maj->execute([$cible, $id]);
    $maj->execute([$ordre, $voisin]);
}

// Rangs remis à 1, 2, 3… dans l'ordre actuel, sans trou ni doublon.
function feuille_renumeroter(int $evenementId): void
{
    $stmt = db()->prepare('SELECT id FROM evenement_feuille WHERE evenement_id = ? ORDER BY ordre, id');
    $stmt->execute([$evenementId]);
    $maj = db()->prepare('UPDATE evenement_feuille SET ordre = ? WHERE id = ?');
    $rang = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $maj->execute([++$rang, (int) $id]);
    }
}

// Titre affiché d'un élément : son libellé, ou à défaut ce qui l'identifie le
// mieux selon son type — un élément sans titre reste lisible.
function feuille_element_titre(array $el): string
{
    $libelle = trim((string) ($el['libelle'] ?? ''));
    if ($libelle !== '') {
        return $libelle;
    }
    return match ((string) $el['type']) {
        'contact' => trim(feuille_contact_nom($el)) ?: 'Contact',
        'fichier' => trim((string) ($el['nom_origine'] ?? '')) ?: 'Pièce jointe',
        'adresse' => trim((string) ($el['adresse'] ?? '')) ?: 'Adresse',
        'horaire' => 'Horaire',
        default   => 'Note',
    };
}

// Nom d'un contact : celui du carnet d'adresses s'il y est rattaché, sinon
// celui saisi sur la feuille.
function feuille_contact_nom(array $el): string
{
    $prenom = trim((string) ($el['c_prenom'] ?? '')) ?: trim((string) ($el['prenom'] ?? ''));
    $nom    = trim((string) ($el['c_nom'] ?? '')) ?: trim((string) ($el['nom'] ?? ''));
    return trim($prenom . ' ' . $nom);
}

function feuille_contact_telephone(array $el): string
{
    return trim((string) ($el['c_telephone'] ?? '')) ?: trim((string) ($el['telephone'] ?? ''));
}

function feuille_contact_email(array $el): string
{
    return trim((string) ($el['c_email'] ?? '')) ?: trim((string) ($el['email'] ?? ''));
}

// Le détail d'un élément, en lignes de texte — ce qui s'affiche sous son titre,
// s'imprime sur la feuille et se recopie dans la description du calendrier de
// l'équipe. Une seule source pour ces trois usages : ils doivent dire la même
// chose.
function feuille_element_lignes(array $el): array
{
    $lignes = [];
    switch ((string) $el['type']) {
        case 'horaire':
            $h = evenement_horaire_texte(['heure_debut' => $el['debut'] ?? '', 'heure_fin' => $el['fin'] ?? '']);
            if ($h !== '') {
                $lignes[] = $h;
            }
            break;
        case 'adresse':
            // Le code d'entrée n'a pas de champ à lui : il se note dans la
            // remarque, avec l'étage ou la place de parking. Un champ par
            // information de ce genre aurait fini par en faire six.
            if (trim((string) $el['adresse']) !== '') {
                $lignes[] = (string) $el['adresse'];
            }
            break;
        case 'contact':
            $role = trim((string) ($el['c_role'] ?? ''));
            $structure = trim((string) ($el['s_nom'] ?? ''));
            $qui = implode(' · ', array_filter([feuille_contact_nom($el), $role, $structure]));
            if ($qui !== '') {
                $lignes[] = $qui;
            }
            $coord = array_filter([feuille_contact_telephone($el), feuille_contact_email($el)]);
            if ($coord) {
                $lignes[] = implode(' · ', $coord);
            }
            break;
        case 'fichier':
            $nom = trim((string) ($el['nom_origine'] ?? ''));
            if ($nom !== '') {
                $lignes[] = $nom . ' (' . feuille_taille_texte((int) $el['taille']) . ')';
            }
            break;
    }
    if (trim((string) ($el['remarque'] ?? '')) !== '') {
        $lignes[] = (string) $el['remarque'];
    }
    return $lignes;
}

// Taille d'un fichier en une expression lisible (« 1,4 Mo »).
function feuille_taille_texte(int $octets): string
{
    if ($octets >= 1048576) {
        return str_replace('.', ',', (string) round($octets / 1048576, 1)) . ' Mo';
    }
    return max(1, (int) round($octets / 1024)) . ' Ko';
}

// Enregistre le fichier téléversé sous $champ dans data/fichiers/ et retourne
// [nom stocké, nom d'origine, mime, taille], ou null si aucun fichier n'a été
// envoyé. Lève une RuntimeException avec un message affichable en cas de refus.
//
// Le type est déduit du CONTENU (finfo) et non de l'extension envoyée : c'est le
// nom du fichier qui ment, jamais ses premiers octets. Le nom stocké est tiré au
// sort — deux organisateurs envoient « fiche technique.pdf ».
function feuille_fichier_enregistrer(string $champ): ?array
{
    $f = $_FILES[$champ] ?? null;
    if ($f === null || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException("Échec de l'envoi du fichier (code {$f['error']}).");
    }
    if ($f['size'] > FEUILLE_FICHIER_MAX) {
        throw new RuntimeException('Fichier trop lourd (8 Mo maximum).');
    }
    $mime = (string) @finfo_file(finfo_open(FILEINFO_MIME_TYPE), $f['tmp_name']);
    if (!isset(FEUILLE_FICHIERS_MIMES[$mime])) {
        throw new RuntimeException('Format non accepté : PDF, image, texte ou document bureautique.');
    }
    $dir = feuille_fichiers_dir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Impossible de créer le dossier des pièces jointes.");
    }
    $stocke = 'feuille_' . bin2hex(random_bytes(8)) . '.' . FEUILLE_FICHIERS_MIMES[$mime];
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $stocke)) {
        throw new RuntimeException("Impossible d'enregistrer le fichier.");
    }
    @chmod($dir . '/' . $stocke, 0644);
    return [
        'fichier'     => $stocke,
        'nom_origine' => feuille_nom_origine_propre((string) $f['name']),
        'mime'        => $mime,
        'taille'      => (int) $f['size'],
    ];
}

// Nom d'origine réduit à ce qui s'affiche sans danger : pas de chemin, pas de
// caractère de contrôle, 120 caractères au plus. Il ne sert qu'à l'affichage et
// au téléchargement — le fichier sur le disque porte un nom tiré au sort.
function feuille_nom_origine_propre(string $nom): string
{
    $nom = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', basename($nom));
    $nom = trim(str_replace(['"', '\\'], '', $nom));
    return mb_substr($nom !== '' ? $nom : 'document', 0, 120, 'UTF-8');
}

// Supprime un élément et, s'il portait une pièce jointe, le fichier avec lui —
// sans quoi data/fichiers/ accumulerait indéfiniment des fichiers que plus rien
// ne désigne.
function feuille_supprimer(int $id): void
{
    $el = feuille_element($id);
    if (!$el) {
        return;
    }
    db()->prepare('DELETE FROM evenement_feuille WHERE id = ?')->execute([$id]);
    $fichier = trim((string) ($el['fichier'] ?? ''));
    if ($fichier !== '') {
        @unlink(feuille_fichiers_dir() . '/' . basename($fichier));
    }
}

// ---------------------------------------------- CALENDRIER DE L'ÉQUIPE
// Le flux iCal réservé à l'équipe : tout ce que l'export public tait. Les dates
// encore en option, celles qui ne sont pas répertoriées, et le contenu des
// feuilles de route. Deux entrées par date :
//
//   — une BANDE de journée entière, qui porte la feuille de route complète dans
//     sa description et les pièces jointes en ATTACH ;
//   — un ÉVÉNEMENT DATÉ par horaire (get-in, balances, show), pour que la
//     journée se lise dans la vue « jour » d'un téléphone.
//
// Les deux se complètent : la bande dit tout, les horaires disent quand. Leurs
// UID sont distincts de ceux de l'export public, pour qu'un agenda abonné aux
// deux flux ne prenne pas l'un pour une mise à jour de l'autre.

// Les événements du calendrier d'équipe : tous, sans filtre de visibilité ni de
// statut, avec leur feuille de route. $spectacleId restreint à un spectacle et
// à ses sous-spectacles, comme l'export public.
function feuille_evenements_equipe(?int $spectacleId = null): array
{
    $sql = 'SELECT e.*, s.nom AS spectacle_nom, sp.nom AS spectacle_parent_nom
              FROM evenements e
              LEFT JOIN spectacles s ON s.id = e.spectacle_id
              LEFT JOIN spectacles sp ON sp.id = s.parent_id';
    $params = [];
    if ($spectacleId) {
        $ids = array_merge([$spectacleId], spectacle_descendants($spectacleId, spectacle_map()));
        $sql .= ' WHERE e.spectacle_id IN (' . sql_in($ids) . ')';
        $params = $ids;
    }
    $sql .= ' ORDER BY e.date';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $evenements = $stmt->fetchAll();
    foreach ($evenements as &$ev) {
        $ev['feuille'] = feuille_elements((int) $ev['id']);
    }
    return $evenements;
}

// Titre d'une date dans le calendrier de l'équipe : le spectacle, précédé de ce
// qui doit sauter aux yeux — une date annulée ou encore en option n'engage pas
// au même titre qu'une date confirmée.
function feuille_ical_titre(array $ev): string
{
    $parent = trim((string) ($ev['spectacle_parent_nom'] ?? ''));
    $nom    = trim((string) ($ev['spectacle_nom'] ?? '')) ?: 'Date';
    $titre  = $parent !== '' ? $parent . ' (' . $nom . ')' : $nom;
    $prefixe = match ((string) $ev['statut']) {
        'annule' => '[ANNULÉ] ',
        'option' => '[OPTION] ',
        default  => '',
    };
    $ville = trim((string) ($ev['ville'] ?? ''));
    return $prefixe . $titre . ($ville !== '' ? ' — ' . $ville : '');
}

// Le lieu d'une date, tel qu'un agenda l'ouvre dans un itinéraire.
function feuille_ical_lieu(array $ev): string
{
    $salle = trim((string) ($ev['salle'] ?? ''));
    $adresse = evenement_adresse_texte($ev);
    return trim(implode(', ', array_filter([$salle, $adresse])));
}

// La feuille de route mise à plat, une ligne par élément, pour la description
// du calendrier. Même contenu que la carte à l'écran (feuille_element_lignes()),
// parce que c'est la même feuille.
function feuille_ical_description(array $ev): string
{
    $lignes = [];
    $horaire = evenement_horaire_texte($ev);
    if ($horaire !== '') {
        $lignes[] = 'Représentation : ' . $horaire;
    }
    foreach ($ev['feuille'] ?? [] as $el) {
        $detail = feuille_element_lignes($el);
        $lignes[] = feuille_element_titre($el) . ($detail ? ' — ' . implode(' · ', $detail) : '');
    }
    if (trim((string) ($ev['remarques'] ?? '')) !== '') {
        $lignes[] = (string) $ev['remarques'];
    }
    return implode("\n", $lignes);
}

// Flux iCal du calendrier de l'équipe. $base est l'URL absolue de l'application
// (jeton compris) à partir de laquelle se téléchargent les pièces jointes —
// elles vivent hors racine web et ne se servent que par une route.
function feuille_generer_ical_equipe(array $evenements, string $base): string
{
    $lignes = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Lasso//Equipe//FR', 'CALSCALE:GREGORIAN'];
    $stamp = gmdate('Ymd\THis\Z');
    foreach ($evenements as $ev) {
        $id   = (int) $ev['id'];
        $date = str_replace('-', '', (string) $ev['date']);
        $lieu = feuille_ical_lieu($ev);

        // La bande de journée : tout ce qu'on sait de la date.
        $lignes[] = 'BEGIN:VEVENT';
        $lignes[] = 'UID:equipe-evenement-' . $id . '@lasso';
        $lignes[] = 'DTSTAMP:' . $stamp;
        $lignes[] = 'DTSTART;VALUE=DATE:' . $date;
        $lignes[] = 'SUMMARY:' . evenements_ical_echap(feuille_ical_titre($ev));
        if ($lieu !== '') {
            $lignes[] = 'LOCATION:' . evenements_ical_echap($lieu);
        }
        $description = feuille_ical_description($ev);
        if ($description !== '') {
            $lignes[] = 'DESCRIPTION:' . evenements_ical_echap($description);
        }
        // Une date annulée reste dans le flux, marquée comme telle : la faire
        // disparaître laisserait croire à un oubli, et l'agenda de chacun garde
        // ainsi la trace de ce qui était prévu.
        if ((string) $ev['statut'] === 'annule') {
            $lignes[] = 'STATUS:CANCELLED';
        } elseif ((string) $ev['statut'] === 'option') {
            $lignes[] = 'STATUS:TENTATIVE';
        }
        foreach ($ev['feuille'] ?? [] as $el) {
            if ((string) $el['type'] === 'fichier' && trim((string) $el['fichier']) !== '') {
                $lignes[] = 'ATTACH;FMTTYPE=' . ((string) $el['mime'] ?: 'application/octet-stream')
                    . ':' . $base . '&id=' . (int) $el['id'];
            }
        }
        $lignes[] = 'END:VEVENT';

        // Puis un événement daté par horaire.
        foreach ($ev['feuille'] ?? [] as $el) {
            if ((string) $el['type'] !== 'horaire') {
                continue;
            }
            $bornes = evenement_bornes_utc([
                'date' => (string) $ev['date'], 'heure_debut' => $el['debut'], 'heure_fin' => $el['fin'],
            ]);
            if ($bornes === null) {
                continue; // un horaire sans heure n'a rien à poser dans un agenda
            }
            $lignes[] = 'BEGIN:VEVENT';
            $lignes[] = 'UID:equipe-feuille-' . (int) $el['id'] . '@lasso';
            $lignes[] = 'DTSTAMP:' . $stamp;
            $lignes[] = 'DTSTART:' . $bornes['debut'];
            if ($bornes['fin'] !== null) {
                $lignes[] = 'DTEND:' . $bornes['fin'];
            }
            $lignes[] = 'SUMMARY:' . evenements_ical_echap(feuille_element_titre($el) . ' — ' . feuille_ical_titre($ev));
            if ($lieu !== '') {
                $lignes[] = 'LOCATION:' . evenements_ical_echap($lieu);
            }
            if (trim((string) $el['remarque']) !== '') {
                $lignes[] = 'DESCRIPTION:' . evenements_ical_echap((string) $el['remarque']);
            }
            $lignes[] = 'END:VEVENT';
        }
    }
    $lignes[] = 'END:VCALENDAR';
    return implode("\r\n", $lignes);
}

// Les structures organisatrices d'une date, avec leurs contacts : la salle, le
// festival, l'association qui invite. C'est à eux qu'on téléphone en arrivant, et
// leurs coordonnées n'ont pas à être recopiées à la main sur la feuille de route
// — elles sont déjà dans le carnet d'adresses, et c'est lui qui fait foi.
//
// Toutes les structures liées, et non la seule marquée « à facturer » : une date
// se joue souvent avec un lieu ET un organisateur, et le jour même les deux
// comptent autant.
//
// Leurs structures MÈRES suivent (structure_organisateurs : l'association qui
// fait tourner la salle, la faîtière d'un festival). C'est souvent là que se
// trouvent l'administration et la personne qu'on appelle quand la salle ne
// répond pas — et la feuille de route est justement ce qu'on ouvre quand ça
// coince. Une mère déjà liée en direct n'est pas répétée.
function feuille_organisateurs(int $evenementId): array
{
    $structures = evenement_structures_liees($evenementId);
    if (!$structures) {
        return [];
    }
    $ids = array_map(fn (array $s): int => (int) $s['id'], $structures);
    $nomsParId = [];
    foreach ($structures as $s) {
        $nomsParId[(int) $s['id']] = (string) $s['nom'];
    }

    // Les mères, et pour chacune la ou les structures liées dont elle l'est —
    // « organisateur de X » n'a de sens que dit comme ça.
    $stmtM = db()->prepare(
        'SELECT so.structure_id AS enfant_id, m.*, m.adresse_localite AS ville
           FROM structure_organisateurs so JOIN structures m ON m.id = so.organisateur_id
          WHERE so.structure_id IN (' . sql_in($ids) . ')
          ORDER BY m.nom'
    );
    $stmtM->execute($ids);
    $meres = [];
    foreach ($stmtM->fetchAll() as $m) {
        $mid = (int) $m['id'];
        if (isset($nomsParId[$mid])) {
            continue; // déjà présente en direct
        }
        if (!isset($meres[$mid])) {
            // « organise » et non « via » : la table structures a DÉJÀ une
            // colonne via (« Connu via »), que SELECT s.* ramène — la clé se
            // serait écrasée, et la valeur lue ici aurait été celle de la fiche.
            $m['organise'] = [];
            $meres[$mid] = $m;
        }
        $meres[$mid]['organise'][] = $nomsParId[(int) $m['enfant_id']] ?? '';
    }
    $toutes = array_merge($structures, array_values($meres));

    $tousIds = array_map(fn (array $s): int => (int) $s['id'], $toutes);
    $stmt = db()->prepare(
        'SELECT id, structure_id, prenom, nom, role, telephone, email
           FROM structure_contacts
          WHERE actif = 1 AND structure_id IN (' . sql_in($tousIds) . ')
          ORDER BY est_administration DESC, nom, prenom'
    );
    $stmt->execute($tousIds);
    $parStructure = [];
    foreach ($stmt->fetchAll() as $c) {
        $parStructure[(int) $c['structure_id']][] = $c;
    }
    foreach ($toutes as &$s) {
        $s['contacts'] = $parStructure[(int) $s['id']] ?? [];
        $s['organise'] = array_values(array_filter((array) ($s['organise'] ?? [])));
    }
    return $toutes;
}

// Adresse d'une structure en une ligne, pour la feuille de route.
function feuille_structure_adresse(array $s): string
{
    $rue = trim((string) ($s['adresse_rue'] ?? ''));
    $npaVille = trim(trim((string) ($s['adresse_npa'] ?? '')) . ' ' . trim((string) ($s['adresse_localite'] ?? $s['ville'] ?? '')));
    $pays = trim((string) ($s['adresse_pays'] ?? ''));
    return implode(', ', array_filter([$rue, $npaVille, $pays !== 'Suisse' ? $pays : '']));
}

// Un contact en une ligne : qui c'est, et comment le joindre.
function feuille_contact_ligne(array $c): string
{
    $nom = trim(trim((string) $c['prenom']) . ' ' . trim((string) $c['nom']));
    $role = trim((string) ($c['role'] ?? ''));
    $qui = $nom . ($role !== '' ? ' (' . $role . ')' : '');
    $coord = array_filter([trim((string) ($c['telephone'] ?? '')), trim((string) ($c['email'] ?? ''))]);
    return trim($qui . ($coord ? ' — ' . implode(' · ', $coord) : ''));
}
