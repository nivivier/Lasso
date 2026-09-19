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

// Les moments d'une journée de tournée. D'une date à l'autre ce sont les mêmes,
// dans le même ordre — seules les heures changent. Proposés en suggestions sous
// le champ « Intitulé » d'un horaire (<datalist>, views/_evenement_feuille.php)
// plutôt qu'imposés : une ligne de déroulé porte le nom qu'on lui donne.
const FEUILLE_HORAIRES_TYPES = ['Départ', 'Get-in', 'Soundcheck', 'Repas', 'Show'];

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

// Repositionnement par glisser-déposer : la liste complète des identifiants, dans
// leur nouvel ordre. C'est le SERVEUR qui renumérote — la page n'invente aucun
// rang local, sinon l'ordre affiché et l'ordre stocké finissent par diverger.
//
// Les identifiants étrangers à l'événement sont écartés : l'ordre vient du
// client, il ne fait pas foi sur l'appartenance.
function feuille_ordonner(int $evenementId, array $ids): void
{
    $stmt = db()->prepare('SELECT id FROM evenement_feuille WHERE evenement_id = ?');
    $stmt->execute([$evenementId]);
    $siens = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $ordre = array_values(array_filter(array_map('intval', $ids), fn (int $id): bool => in_array($id, $siens, true)));
    if (!$ordre) {
        return;
    }
    $maj = db()->prepare('UPDATE evenement_feuille SET ordre = ? WHERE id = ? AND evenement_id = ?');
    db()->beginTransaction();
    $rang = 0;
    foreach ($ordre as $id) {
        $maj->execute([++$rang, $id, $evenementId]);
    }
    // Ce que le client n'a pas listé (ajouté entre-temps dans un autre onglet)
    // part à la suite, plutôt que de rester sur un rang qui le ferait remonter.
    foreach (array_diff($siens, $ordre) as $id) {
        $maj->execute([++$rang, $id, $evenementId]);
    }
    db()->commit();
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
// feuilles de route. Jusqu'à trois sortes d'entrées par date :
//
//   — une BANDE de journée entière, qui porte la feuille de route complète dans
//     sa description et les pièces jointes en ATTACH ;
//   — le SPECTACLE, sur l'heure de représentation de la date (celle qui
//     s'affiche sur sa fiche) ;
//   — un ÉVÉNEMENT DATÉ par horaire du déroulé (get-in, balances, show), pour
//     que la journée se lise dans la vue « jour » d'un téléphone.
//
// Les trois se complètent : la bande dit tout, le spectacle dit l'heure qu'on
// annonce, les horaires disent le reste de la journée. Leurs UID sont distincts
// de ceux de l'export public, pour qu'un agenda abonné aux deux flux ne prenne
// pas l'un pour une mise à jour de l'autre.

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
        // Les organisateurs et leurs contacts, comme sur la feuille imprimée :
        // c'est à eux qu'on téléphone en arrivant, et l'agenda est justement ce
        // qu'on a sous la main ce jour-là.
        $ev['organisateurs'] = feuille_organisateurs((int) $ev['id']);
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

// La feuille de route dans la description du calendrier. Une description iCal
// est un bloc de texte brut : pas de gras, pas de liste, rien que des lignes.
// D'où des SECTIONS EN CAPITALES séparées par une ligne vide — c'est la seule
// mise en page dont on dispose, et elle suffit à retrouver une adresse ou un
// numéro sans tout relire, sur un écran de téléphone, dans une loge.
//
// L'ordre suit celui de la journée : ce qu'on cherche en premier (l'heure, le
// lieu) est en tête, ce qu'on consulte à l'occasion (pièces jointes, notes) à
// la fin. Le déroulé garde SON ordre à lui, celui que la feuille a posé — à
// l'heure de représentation près, qui s'y glisse à son rang chronologique.
function feuille_ical_bloc(string $titre, array $lignes): array
{
    $lignes = array_values(array_filter($lignes, fn (string $l): bool => trim($l) !== ''));
    return $lignes ? array_merge([mb_strtoupper($titre, 'UTF-8')], $lignes) : [];
}

// Une ligne de déroulé, l'heure en tête : « 14:00  Get-in — porte de derrière ».
// L'heure d'abord parce que c'est par elle qu'on cherche, et que les heures
// alignées en début de ligne se lisent comme une colonne.
function feuille_ical_ligne_horaire(array $el): string
{
    $h = evenement_horaire_texte(['heure_debut' => $el['debut'] ?? '', 'heure_fin' => $el['fin'] ?? '']);
    $reste = implode(' — ', array_filter([
        feuille_element_titre($el),
        trim((string) ($el['remarque'] ?? '')),
    ]));
    return trim($h !== '' ? $h . '  ' . $reste : $reste);
}

// « Intitulé : valeur — remarque », pour les adresses et les pièces jointes.
function feuille_ical_ligne_detail(array $el, string $valeur): string
{
    $titre = feuille_element_titre($el);
    $corps = ($valeur !== '' && $valeur !== $titre) ? $titre . ' : ' . $valeur : $titre;
    $remarque = trim((string) ($el['remarque'] ?? ''));
    return trim($corps . ($remarque !== '' ? ' — ' . $remarque : ''));
}

// Les sections d'une feuille de route, dans l'ordre où on les consulte. Une
// même feuille mêle le déroulé de la journée, des adresses, des contacts, des
// pièces jointes et des notes : les ranger par nature est ce qui permet de
// retrouver un numéro ou un code d'entrée sans tout relire.
//
// Partagé par la feuille affichée/imprimée et par la description du calendrier
// d'équipe : c'est la même feuille, elle ne peut pas exister en deux versions
// qui divergeraient à la première retouche.
const FEUILLE_SECTIONS = [
    'horaire' => 'Déroulé',
    'adresse' => 'Adresses',
    'contact' => 'Contacts',
    'fichier' => 'Pièces jointes',
    'note'    => 'Notes',
];

// Les éléments d'une date groupés par section, les sections vides retirées.
// L'heure de représentation prend sa place DANS le déroulé, à son rang
// chronologique : la journée se lit d'une traite, du get-in au show, sans avoir
// à remonter la chercher ailleurs. Elle s'insère avant le premier horaire plus
// tardif qu'elle ; à défaut, elle ferme la liste. Un horaire sans heure garde sa
// place et ne décide de rien — on ne sait pas où il tombe.
function feuille_sections(array $ev): array
{
    $par = [];
    foreach ($ev['feuille'] ?? $ev['elements'] ?? [] as $el) {
        $par[(string) $el['type']][] = $el;
    }

    $spectacle = heure_normalisee((string) ($ev['heure_debut'] ?? ''));
    if ($spectacle !== '') {
        // Un élément de feuille comme les autres, mais sans id : il n'est pas en
        // base, il est déduit de la date elle-même. Les deux rendus le traitent
        // donc sans rien savoir de sa nature particulière.
        $ligne = ['id' => 0, 'type' => 'horaire', 'libelle' => 'Spectacle',
                  'debut' => (string) ($ev['heure_debut'] ?? ''), 'fin' => (string) ($ev['heure_fin'] ?? ''),
                  'remarque' => ''];
        $horaires = [];
        $pose = false;
        foreach ($par['horaire'] ?? [] as $el) {
            $debut = heure_normalisee((string) ($el['debut'] ?? ''));
            if (!$pose && $debut !== '' && $debut > $spectacle) {
                $horaires[] = $ligne;
                $pose = true;
            }
            $horaires[] = $el;
        }
        if (!$pose) {
            $horaires[] = $ligne;
        }
        $par['horaire'] = $horaires;
    }

    $sections = [];
    foreach (FEUILLE_SECTIONS as $type => $titre) {
        if (!empty($par[$type])) {
            $sections[] = ['type' => $type, 'titre' => $titre, 'elements' => $par[$type]];
        }
    }
    return $sections;
}

function feuille_ical_description(array $ev): string
{
    // Ce que la date dit d'elle-même, avant ce que la feuille y ajoute : c'est
    // le même bloc qu'en tête de la feuille imprimée, et les mêmes informations
    // que l'export public — d'où son nom. On y lit l'heure et le lieu sans
    // avoir à dérouler.
    $publiques = [];
    $lieu = feuille_ical_lieu($ev);
    if ($lieu !== '') {
        $publiques[] = 'Lieu : ' . $lieu;
    }
    $publiques[] = 'Statut : ' . evenement_statut_libelle((string) ($ev['statut'] ?? ''))
        . ' · ' . mb_strtolower(evenement_visibilite_libelle((string) ($ev['visibilite'] ?? '')), 'UTF-8');
    if (trim((string) ($ev['lien_infos'] ?? '')) !== '') {
        $publiques[] = 'Lien : ' . trim((string) $ev['lien_infos']);
    }
    if (trim((string) ($ev['remarques'] ?? '')) !== '') {
        $publiques[] = 'Remarques : ' . trim((string) $ev['remarques']);
    }

    $sections = [feuille_ical_bloc('Infos publiques', $publiques)];

    // Les sections, calculées une fois pour les deux rendus (feuille_sections()).
    // Chacune a sa mise en ligne : l'heure en tête pour le déroulé, « intitulé :
    // valeur » pour une adresse. L'adresse de la représentation n'est pas
    // répétée ici — elle est en tête, ce n'est pas « une adresse parmi
    // d'autres ».
    $parSection = [];
    foreach (feuille_sections($ev) as $sec) {
        $parSection[$sec['type']] = $sec['elements'];
    }

    $sections[] = feuille_ical_bloc(FEUILLE_SECTIONS['horaire'],
        array_map('feuille_ical_ligne_horaire', $parSection['horaire'] ?? []));

    $sections[] = feuille_ical_bloc(FEUILLE_SECTIONS['adresse'], array_map(
        fn (array $el): string => feuille_ical_ligne_detail($el, trim((string) ($el['adresse'] ?? ''))),
        $parSection['adresse'] ?? []
    ));

    // Les contacts de la feuille d'abord — ceux qu'on a notés pour CETTE date —,
    // puis ceux de l'organisation, pris dans le carnet d'adresses. Les seconds
    // disent à qui s'adresser quand les premiers ne répondent pas.
    $contacts = [];
    foreach ($parSection['contact'] ?? [] as $el) {
        $qui = implode(' · ', array_filter([
            feuille_contact_nom($el),
            trim((string) ($el['c_role'] ?? '')),
            trim((string) ($el['s_nom'] ?? '')),
        ]));
        $coord = implode(' · ', array_filter([feuille_contact_telephone($el), feuille_contact_email($el)]));
        $titre = feuille_element_titre($el);
        $ligne = ($qui !== '' && $qui !== $titre) ? $titre . ' : ' . $qui : $titre;
        $contacts[] = trim($ligne . ($coord !== '' ? ' — ' . $coord : ''));
        $remarque = trim((string) ($el['remarque'] ?? ''));
        if ($remarque !== '') {
            $contacts[] = '  ' . $remarque;
        }
    }
    foreach ($ev['organisateurs'] ?? [] as $org) {
        $entete = (string) $org['nom']
            . ($org['organise'] ? ' (organisateur de ' . implode(', ', $org['organise']) . ')' : '');
        $adresseOrg = feuille_structure_adresse($org);
        $contacts[] = $entete . ($adresseOrg !== '' ? ' — ' . $adresseOrg : '');
        foreach ($org['contacts'] as $c) {
            $contacts[] = '  ' . feuille_contact_ligne($c);
        }
    }
    $sections[] = feuille_ical_bloc(FEUILLE_SECTIONS['contact'], $contacts);

    // Le nom du fichier sert de titre quand l'élément n'en porte pas : le
    // répéter donnerait « fiche.pdf : fiche.pdf ». On compare donc au nom NU,
    // avant de lui accoler sa taille.
    $sections[] = feuille_ical_bloc(FEUILLE_SECTIONS['fichier'], array_map(
        function (array $el): string {
            $nom = trim((string) ($el['nom_origine'] ?? ''));
            $avecTaille = $nom !== '' ? $nom . ' (' . feuille_taille_texte((int) $el['taille']) . ')' : '';
            $titre = feuille_element_titre($el);
            $corps = ($nom !== '' && $nom !== $titre) ? $titre . ' : ' . $avecTaille : ($avecTaille ?: $titre);
            $remarque = trim((string) ($el['remarque'] ?? ''));
            return trim($corps . ($remarque !== '' ? ' — ' . $remarque : ''));
        },
        $parSection['fichier'] ?? []
    ));

    // Une note sans intitulé n'est que sa remarque : la préfixer de « Note — »
    // n'apprendrait rien, la section le dit déjà.
    $sections[] = feuille_ical_bloc(FEUILLE_SECTIONS['note'], array_map(
        fn (array $el): string => trim((string) ($el['libelle'] ?? '')) !== ''
            ? feuille_ical_ligne_detail($el, '')
            : trim((string) ($el['remarque'] ?? '')),
        $parSection['note'] ?? []
    ));

    // Une ligne vide entre les sections, aucune en trop : une section absente ne
    // laisse pas de trou.
    return implode("\n\n", array_map(
        fn (array $bloc): string => implode("\n", $bloc),
        array_values(array_filter($sections, fn (array $b): bool => $b !== []))
    ));
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
        // ainsi la trace de ce qui était prévu. Les trois événements d'une même
        // date portent le même statut — la bande, le spectacle et les horaires.
        $statut = static function (array $ev): array {
            return match ((string) $ev['statut']) {
                'annule' => ['STATUS:CANCELLED'],
                'option' => ['STATUS:TENTATIVE'],
                default  => [],
            };
        };
        $lignes = array_merge($lignes, $statut($ev));
        foreach ($ev['feuille'] ?? [] as $el) {
            if ((string) $el['type'] === 'fichier' && trim((string) $el['fichier']) !== '') {
                $lignes[] = 'ATTACH;FMTTYPE=' . ((string) $el['mime'] ?: 'application/octet-stream')
                    . ':' . $base . '&id=' . (int) $el['id'];
            }
        }
        $lignes[] = 'END:VEVENT';

        // L'heure de représentation de la date — celle qui s'affiche sur sa
        // fiche — pose son propre créneau : « Spectacle », de début à fin. Elle
        // ne vivait jusqu'ici que dans la description de la bande de journée,
        // où aucun agenda ne sait la placer sur une grille horaire. C'est
        // pourtant l'heure autour de laquelle tourne le reste de la journée.
        //
        // Distinct d'un éventuel « Show » du déroulé : celui-là est ce que
        // l'équipe se note, celui-ci est ce que la date annonce. Les deux
        // peuvent coexister — et se recouvrir, ce qui n'a rien d'anormal.
        $bornesEv = evenement_bornes_utc($ev);
        if ($bornesEv !== null) {
            $lignes[] = 'BEGIN:VEVENT';
            $lignes[] = 'UID:equipe-spectacle-' . $id . '@lasso';
            $lignes[] = 'DTSTAMP:' . $stamp;
            $lignes[] = 'DTSTART:' . $bornesEv['debut'];
            if ($bornesEv['fin'] !== null) {
                $lignes[] = 'DTEND:' . $bornesEv['fin'];
            }
            $lignes[] = 'SUMMARY:' . evenements_ical_echap('Spectacle — ' . feuille_ical_titre($ev));
            if ($lieu !== '') {
                $lignes[] = 'LOCATION:' . evenements_ical_echap($lieu);
            }
            $lignes = array_merge($lignes, $statut($ev));
            $lignes[] = 'END:VEVENT';
        }

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
            $lignes = array_merge($lignes, $statut($ev));
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

// ----------------------------------------------------- ENVOI PAR E-MAIL
// La feuille de route envoyée à l'équipe, sur le modèle d'une fiche de salaire
// (envoyer_fiche_email(), lib/helpers.php) : le corps partagé avec l'écran et
// l'impression, enveloppé dans un document HTML autonome qui embarque la
// feuille de style — un client mail ne va pas chercher un fichier CSS.
//
// data-theme="clair" comme sur la page d'impression : une messagerie en thème
// sombre donnerait sinon au document les couleurs du thème, sur fond blanc.
function feuille_email_html(array $evenement, array $elements, array $organisateurs): string
{
    $css = @file_get_contents(__DIR__ . '/../assets/app.css') ?: '';
    ob_start();
    require __DIR__ . '/../views/_evenement_feuille_corps.php';
    $corps = ob_get_clean();

    return '<!doctype html><html lang="fr" data-theme="clair"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<style>' . $css . ' body{background:#fff;margin:0;padding:18px}</style></head>'
        . '<body class="fr-print">' . $corps . '</body></html>';
}

// Objet de l'e-mail : la date et le lieu, c'est-à-dire ce qui permet de
// retrouver le message six semaines plus tard dans une boîte encombrée.
function feuille_email_sujet(array $evenement): string
{
    $ou = trim((string) ($evenement['festival'] ?? '')) ?: trim((string) ($evenement['salle'] ?? ''));
    $ville = trim((string) ($evenement['ville'] ?? ''));
    $lieu = implode(', ', array_filter([$ou, $ville]));
    return 'Feuille de route — ' . date('d.m.Y', strtotime((string) $evenement['date']))
        . ($lieu !== '' ? ' · ' . $lieu : '');
}

// Les employés à qui l'envoyer : ceux qui sont liés à la date et qui ont une
// adresse valide. Retourne [destinataires, sans_adresse] — les seconds sont
// nommés à l'écran, sans quoi on croirait la feuille partie à toute l'équipe.
function feuille_destinataires(int $evenementId): array
{
    $ids = evenement_employe_ids($evenementId);
    if (!$ids) {
        return [[], []];
    }
    $stmt = db()->prepare(
        'SELECT id, prenom, nom, email FROM employes WHERE id IN (' . sql_in($ids) . ') ORDER BY nom, prenom'
    );
    $stmt->execute($ids);
    $avec = [];
    $sans = [];
    foreach ($stmt->fetchAll() as $e) {
        $nom = trim($e['prenom'] . ' ' . $e['nom']);
        if (filter_var(trim((string) $e['email']), FILTER_VALIDATE_EMAIL)) {
            $avec[] = ['nom' => $nom, 'email' => trim((string) $e['email'])];
        } else {
            $sans[] = $nom;
        }
    }
    return [$avec, $sans];
}
