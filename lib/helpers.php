<?php
// Fonctions utilitaires partagées — la porte unique, comme lib/db.php l'est
// pour le schéma et les migrations.
//
// Ce fichier faisait 4 082 lignes et une bonne dizaine de métiers. Quatre
// d'entre eux en sont sortis, chacun cohérent et lisible seul ; ils sont
// chargés ICI et ne se requièrent jamais directement, pour que « require
// lib/helpers.php » continue de tout apporter :
//
//   lib/helpers/session.php  session, authentification, CSRF, anti-force-brute,
//                            mot de passe oublié, en-têtes de sécurité et CSP
//   lib/helpers/listes.php   filtres, tri, pagination, recherche — ce qui fait
//                            que toutes les listes de l'app se manipulent pareil
//   lib/helpers/geo.php      pays, régions, cantons, départements, drapeaux
//   lib/helpers/icones.php   dessins Lucide, icon() et le sprite de page
//
// Restent ici : formats (chf, pct, dates), avatars, couleurs et thème, logos et
// cache disque, e-mail et SMTP, composants HTML, render(). Le découpage s'est
// fait par DÉPLACEMENT de blocs entiers, sans toucher une ligne de code — les
// pages rendues avant et après sont identiques à l'octet près.
require_once __DIR__ . '/helpers/session.php';
require_once __DIR__ . '/helpers/listes.php';
require_once __DIR__ . '/helpers/geo.php';
require_once __DIR__ . '/helpers/icones.php';

// Validation stricte d'une date « Y-m-d » : DateTime::createFromFormat() seul
// accepterait silencieusement une date invalide comme "2026-02-30" en la
// « roulant » au 2 mars — checkdate() la rejette explicitement. Partagée entre
// lib/evenements.php (dates d'événement) et lib/compta.php (dates camt.053).
function date_valide(string $s): bool
{
    return (bool) preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)
        && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

// URL de lien externe affichable comme <a href> cliquable sans risque (schéma
// http(s) explicite, donc jamais "javascript:" ou autre schéma actif, +
// FILTER_VALIDATE_URL) — partagé entre l'import événements
// (lib/evenements.php) et son aperçu (views/_import_evenements_section.php).
// Les adresses VALIDES d'une saisie libre : séparateur virgule, point-virgule
// ou retour à la ligne, au choix de qui tape. Ce qui n'est pas une adresse est
// écarté sans bruit — on ne refuse pas l'envoi d'un message pour une virgule en
// trop, et l'écran montre ensuite ce qui est parti.
function adresses_email_liste(string $brut): array
{
    $out = [];
    foreach (preg_split('/[\s,;]+/', $brut) as $a) {
        $a = trim($a);
        if ($a !== '' && filter_var($a, FILTER_VALIDATE_EMAIL)) {
            $out[strtolower($a)] = $a;
        }
    }
    return array_values($out);
}

function lien_http_valide(string $lien): bool
{
    return $lien !== '' && preg_match('#^https?://#i', $lien) === 1 && filter_var($lien, FILTER_VALIDATE_URL) !== false;
}

// Détecte le séparateur (virgule ou point-virgule, fréquent dans les exports
// Excel francophones) à partir de la ligne d'en-tête d'un CSV — partagé entre
// l'import structures (lib/booking.php) et l'import événements
// (lib/evenements.php).
function csv_detecter_delimiteur(string $ligneEntete): string
{
    return substr_count($ligneEntete, ';') > substr_count($ligneEntete, ',') ? ';' : ',';
}

// Crée un élément DOM namespacé avec texte optionnel — factorise le patron
// répété par les deux générateurs XML du dépôt (build_certificat_xml() pour
// l'eCS CSI, compta_generer_camt053() pour le relevé bancaire). $prefix est
// préfixé devant $name si fourni (ex. 'sd' → <sd:Nom>), sinon l'élément est
// créé dans le namespace par défaut (sans préfixe).
function dom_el(DOMDocument $doc, string $ns, string $name, ?string $text = null, ?string $prefix = null): DOMElement
{
    $qname = $prefix !== null ? $prefix . ':' . $name : $name;
    $n = $doc->createElementNS($ns, $qname);
    if ($text !== null && $text !== '') {
        $n->appendChild($doc->createTextNode($text));
    }
    return $n;
}

// $ancre : l'identifiant d'une carte de la page d'arrivée (« carte-feuille »).
// Sur une fiche longue, revenir en haut après chaque geste oblige à redescendre
// — on ajoute rarement un seul élément à une feuille de route.
function redirect(string $route, array $params = [], string $ancre = ''): void
{
    // Propage ?depuis=type:id (lien de retour contextuel, voir lien_retour_contextuel())
    // s'il était présent sur la requête courante et que l'appelant ne l'a pas déjà
    // explicitement fourni — pour qu'il survive à un POST-puis-redirection vers la
    // même page (ex. enregistrer la carte « Informations » d'un événement).
    if (!isset($params['depuis']) && ($_GET['depuis'] ?? '') !== '') {
        $params['depuis'] = $_GET['depuis'];
    }
    $url = '?p=' . urlencode($route);
    foreach ($params as $k => $v) {
        // Filtre multi-valeurs (voir filtre_coche()) : une paire clé[]=valeur
        // par élément, plutôt que (string) $v qui produirait "Array" + un
        // avertissement PHP.
        if (is_array($v)) {
            foreach ($v as $vv) {
                $url .= '&' . urlencode($k) . '[]=' . urlencode((string) $vv);
            }
            continue;
        }
        $url .= '&' . urlencode($k) . '=' . urlencode((string) $v);
    }
    if ($ancre !== '') {
        $url .= '#' . rawurlencode($ancre);
    }
    header('Location: ' . $url);
    exit;
}

// Supprime la ligne $id de $table, sauf si des lignes de $tableRef y font
// encore référence via $colonneRef (ex. un employé qui a des fiches, un
// débiteur qui a des factures) — la suppression est alors refusée.
// Retourne true si la suppression a eu lieu, false si elle a été refusée.
//
// Les quatre noms sont interpolés dans le SQL (impossible de les paramétrer :
// PDO ne prépare que des valeurs, pas des identifiants). Tous les appels
// actuels passent des littéraux du code, jamais une valeur issue de la requête
// — mais c'était une invariante tenue par convention, vérifiable nulle part.
// Le contrôle ci-dessous la rend explicite : un identifiant qui n'est pas un
// nom simple (lettres, chiffres, souligné) lève au lieu d'atteindre le SQL.
// Volontairement un contrôle de forme et non une liste des tables autorisées :
// une liste exhaustive devrait être tenue à jour à chaque nouvelle table, et
// finirait par être élargie sans réflexion — la forme, elle, suffit à écarter
// toute injection, puisqu'aucun caractère de syntaxe SQL ne passe.
function supprimer_si_non_reference(string $table, int $id, string $tableRef, string $colonneRef): bool
{
    foreach (['table' => $table, 'tableRef' => $tableRef, 'colonneRef' => $colonneRef] as $nom => $val) {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $val)) {
            throw new InvalidArgumentException("supprimer_si_non_reference() : \$$nom invalide.");
        }
    }
    $stmt = db()->prepare("SELECT COUNT(*) FROM $tableRef WHERE $colonneRef = ?");
    $stmt->execute([$id]);
    if ((int) $stmt->fetchColumn() > 0) {
        return false;
    }
    db()->prepare("DELETE FROM $table WHERE id = ?")->execute([$id]);
    return true;
}

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

// Placeholders "?,?,?..." (un par élément de $valeurs) pour une clause IN (...)
// préparée — un seul point de vérité pour ce pattern, avant répété (copié à
// la main : implode(',', array_fill(0, count($x), '?'))) plus de 40 fois
// dans lib/. Prend le tableau lui-même (pas juste sa taille) pour que
// l'appelant n'ait pas à écrire count($x) séparément.
// Découpe une liste d'identifiants en lots, pour les requêtes « IN (…) » qui
// lient un paramètre par valeur. SQLite plafonne le nombre de paramètres d'une
// requête (SQLITE_MAX_VARIABLE_NUMBER : 999 avant SQLite 3.32, 32766 ensuite)
// et le serveur d'un hébergement mutualisé n'a pas la version du poste de
// travail (docs/DECISIONS.md § Le SQLite d'un hébergement mutualisé) : une
// liste de quelques milliers de structures passe ici et échoue là-bas, avec un
// « too many SQL variables » que rien ne laissait prévoir. 500 par lot laisse
// de la place aux autres paramètres de la requête.
function lots_ids(array $ids, int $taille = 500): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    return $ids ? array_chunk($ids, $taille) : [];
}

function sql_in(array $valeurs): string
{
    return implode(',', array_fill(0, count($valeurs), '?'));
}

// Pastille d'initiales colorée, posée devant un nom dans une liste. Elle donne
// à chaque ligne un point d'accroche en début de rangée : sans elle, une liste
// de trente lignes oblige à LIRE chaque nom pour repérer le sien.
//
// La teinte est dérivée du nom, donc stable : la même personne garde sa couleur
// d'un écran à l'autre et d'une session à l'autre. Palette fixe et non dérivée
// de la couleur d'employeur : ces pastilles doivent se distinguer ENTRE ELLES,
// pas s'accorder à la marque, et toutes portent du blanc lisible.
const AVATAR_TEINTES = ['#6d4ade', '#0c9486', '#e0473c', '#9a6700', '#2563eb', '#b8397f', '#3f7d3a', '#7a4bd6'];

// $couleur et $photo, quand ils sont renseignés, remplacent la teinte dérivée :
// la photo d'abord, la couleur choisie ensuite, le nom en dernier recours.
function avatar_initiales(string $nom, string $couleur = '', string $photo = ''): string
{
    $nom = trim($nom);
    if ($nom === '') {
        return '';
    }
    $photo = trim($photo);
    if ($photo !== '' && str_starts_with($photo, 'uploads/')) {
        return '<span class="avatar-ini avatar-photo" aria-hidden="true">'
            . '<img src="' . e($photo) . '" alt=""></span>';
    }
    $mots = preg_split('/\s+/u', $nom) ?: [];
    $ini = '';
    foreach (array_slice($mots, 0, 2) as $m) {
        $ini .= mb_strtoupper(mb_substr($m, 0, 1, 'UTF-8'), 'UTF-8');
    }
    return '<span class="avatar-ini" style="background:' . e(avatar_teinte($nom, $couleur)) . '" aria-hidden="true">'
        . e($ini) . '</span>';
}

// Enregistre la photo recadrée envoyée par le formulaire, sous forme de data
// URI produit par le cropper (assets/vendor/cropperjs). Renvoie le chemin web
// (uploads/…).
//
// Le recadrage se fait dans le navigateur : ce qui arrive ici est déjà une
// vignette carrée de quelques dizaines de Ko, pas la photo d'origine. On la
// valide malgré tout comme un upload de fichier — un data URI reste une entrée
// utilisateur, et rien n'oblige un client à passer par notre formulaire.
// $prefixe : de quoi reconnaître le fichier dans uploads/ — « avatar » pour la
// photo d'un employé, « projet » pour l'icône d'un projet. Le reste du
// traitement est le même, d'où une seule fonction.
// Prochain numéro d'une série ANNUELLE, format « PRÉFIXE-AAAA-NNN » (F-2026-001,
// Q-2026-001), séquentiel et sans trou.
//
// ⚠️ MAX() NUMÉRIQUE (CAST) et non un tri lexicographique sur le numéro
// complet : un tri texte classerait « 2026-999 » après « 2026-1000 », le
// caractère '1' venant avant '9' — et donnerait le mauvais dernier numéro dès
// la millième pièce de l'année. Ce piège a été payé une fois dans la
// facturation ; il n'a pas à l'être une seconde dans les quittances, d'où
// cette fonction commune. $table et $prefixe ne viennent JAMAIS de
// l'utilisateur : ce sont des littéraux du code appelant.
// $pdo est passé et non pris de db() : tests/facturation_test.php numérote sur
// une base en mémoire, et c'est une bonne raison de ne pas supposer laquelle.
function prochain_numero_annuel(PDO $pdo, string $table, string $prefixe, int $annee): string
{
    $debut = $prefixe . '-' . $annee . '-';
    $stmt = $pdo->prepare("SELECT MAX(CAST(substr(numero, length(?) + 1) AS INTEGER)) FROM \"$table\" WHERE numero LIKE ?");
    $stmt->execute([$debut, $debut . '%']);
    return $debut . sprintf('%03d', ((int) $stmt->fetchColumn()) + 1);
}

// Décode une image envoyée en data URI et PROUVE que c'en est une.
//
// ⚠️ C'est le garde-fou, et il n'existe qu'ici. Un navigateur qui annonce
// « data:image/png » n'a rien prouvé du tout : seul getimagesizefromstring()
// regarde les octets. Deux écrans produisent des images de cette façon — le
// recadrage d'avatar et la photo d'un justificatif —, et ils envoient vers des
// dossiers différents, avec des limites différentes ; ce qu'ils partagent,
// c'est exactement cette vérification-là, et elle ne doit pas exister en deux
// exemplaires qui pourraient diverger.
//
// Rend [binaire, extension, mime]. Lève une RuntimeException affichable sinon.
function image_data_uri_decoder(string $dataUri, int $maxOctets): array
{
    if (!preg_match('#^data:image/(png|jpeg|webp);base64,([A-Za-z0-9+/=]+)$#', trim($dataUri), $m)) {
        throw new RuntimeException('Image invalide.');
    }
    $binaire = base64_decode($m[2], true);
    if ($binaire === false || $binaire === '') {
        throw new RuntimeException('Image invalide.');
    }
    if (strlen($binaire) > $maxOctets) {
        throw new RuntimeException('Image trop lourde (' . fichier_taille_lisible($maxOctets) . ' maximum).');
    }
    $info = @getimagesizefromstring($binaire);
    $exts  = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'];
    $mimes = [IMAGETYPE_PNG => 'image/png', IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_WEBP => 'image/webp'];
    if ($info === false || !isset($exts[$info[2]])) {
        throw new RuntimeException('Format non supporté (PNG, JPG ou WebP).');
    }
    return [$binaire, $exts[$info[2]], $mimes[$info[2]]];
}

function avatar_photo_enregistrer(string $dataUri, string $prefixe = 'avatar'): string
{
    [$binaire, $ext] = image_data_uri_decoder($dataUri, 2 * 1024 * 1024);
    $dir = __DIR__ . '/../uploads';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $prefixe = preg_match('/^[a-z]+$/', $prefixe) ? $prefixe : 'avatar';
    $nom = $prefixe . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (file_put_contents($dir . '/' . $nom, $binaire) === false) {
        throw new RuntimeException("Impossible d'enregistrer le fichier.");
    }
    @chmod($dir . '/' . $nom, 0644);
    return 'uploads/' . $nom;
}

// Efface une ancienne photo de pastille. Le préfixe est vérifié : la valeur
// vient de la base, mais rien ne coûte de s'assurer qu'on ne supprime que dans
// uploads/ (même précaution que pour les logos, route_apparence()).
function avatar_photo_supprimer(string $chemin): void
{
    $chemin = trim($chemin);
    if ($chemin === '' || !str_starts_with($chemin, 'uploads/') || str_contains($chemin, '..')) {
        return;
    }
    $fs = __DIR__ . '/../' . $chemin;
    if (is_file($fs)) {
        @unlink($fs);
    }
}

// Teinte d'une pastille : celle qu'on a choisie si elle fait partie de la
// palette, sinon celle que le nom désigne. La validation n'est pas cosmétique —
// la valeur part dans un attribut style.
// crc32 plutôt qu'une somme de caractères : deux noms proches (« Vivier »,
// « Voide ») donneraient sinon souvent la même teinte.
function avatar_teinte(string $nom, string $couleur = ''): string
{
    $couleur = strtolower(trim($couleur));
    return in_array($couleur, AVATAR_TEINTES, true)
        ? $couleur
        : AVATAR_TEINTES[crc32(trim($nom)) % count(AVATAR_TEINTES)];
}

// Montant CHF, mais un zéro s'efface : dans une colonne de montants, « 0.00 »
// pèse autant qu'une vraie somme alors qu'il ne dit rien. Le tiret garde la
// colonne alignée sans attirer l'œil.
function chf_ou_zero(float $v): string
{
    return abs($v) < 0.005 ? '<span class="montant-zero">–</span>' : e(chf($v));
}

// Montant CHF : "1 234.55"
function chf(float $v): string
{
    return number_format($v, 2, '.', "\u{202F}");
}

// Nombre sans zéros ni point superflus : 3.50 -> "3.5", 4.00 -> "4".
// Utilisé pour les quantités/heures/taux affichés (formulaires, PDF, pct()).
function nombre_court(float $v, int $decimales = 2): string
{
    return rtrim(rtrim(number_format($v, $decimales, '.', ''), '0'), '.');
}

// Pourcentage lisible : 0.053 -> "5.3 %"
function pct(float $v): string
{
    return nombre_court($v * 100, 4) . ' %';
}

// Badge <span> générique (statuts, indicateurs) — factorise le motif répété
// dans evenement_suisa_badge()/evenement_badge_statut() (lib/evenements.php)
// et facturation_badge() (lib/facturation.php). $classe :
// suffixe de couleur ('ok'|'warn'|'muted'|'emise', voir .badge dans
// assets/app.css) ou '' pour le badge neutre par défaut (mauve).
// Un bouton qui mène AILLEURS : le site d'un interlocuteur, un dossier partagé.
// Il ouvre dans un onglet à lui, puisque ce qu'on fait ici n'est pas fini.
//
// $opts : 'nom' (complète l'infobulle : « … — Le Mirage »), 'petit' (format
// d'une ligne, icône seule).
//
// $url est rendue telle qu'elle a été saisie, échappée : jamais interprétée.
// rel="noopener" parce que la page ouverte ne doit pas garder la main sur la
// nôtre.
//
// ⚠️ Seule une adresse http(s) ABSOLUE donne un bouton. Les champs sont des
// <input type="url">, mais les lignes importées ne sont passées par aucun
// navigateur : il s'y trouve des adresses e-mail, un numéro de téléphone, le
// mot « facebook ». Rendues telles quelles, elles ouvriraient un chemin
// RELATIF — une page de notre propre application, qui n'existe pas. Mieux vaut
// pas de bouton qu'un bouton qui ment.
function bouton_lien_externe_html(string $url, string $libelle, array $opts = []): string
{
    $url = trim($url);
    if ($url === '' || !preg_match('~^https?://~i', $url)) {
        return '';
    }
    $nom   = trim((string) ($opts['nom'] ?? ''));
    $titre = $libelle . ($nom !== '' ? ' — ' . $nom : '');
    $petit = !empty($opts['petit']);
    return '<a class="btn ghost' . ($petit ? ' btn-sm icon-only' : '') . '"'
        . ' href="' . e($url) . '" target="_blank" rel="noopener"'
        . ' title="' . e($titre) . '" aria-label="' . e($titre) . '">'
        . icon('external-link') . ($petit ? '' : ' <span class="lbl">' . e($libelle) . '</span>')
        . '</a>';
}

// La PÉRIODE d'une chose datée court-elle encore ? Commencée, pas finie — les
// dates seules, sans regarder où en est le démarchage. C'est ce qui distingue
// une campagne bouclée en avance (tout le monde contacté, mais la saison n'est
// pas finie : les réponses peuvent encore bouger, on montre son avancement)
// d'une campagne vraiment derrière nous. Sert aux campagnes de démarchage
// comme à celles de recherche de fonds : ce sont les mêmes dates.
function periode_courante(array $c, string $aujourdhui = ''): bool
{
    $aujourdhui = $aujourdhui !== '' ? $aujourdhui : date('Y-m-d');
    $debut = (string) ($c['date_debut'] ?? '');
    $fin   = (string) ($c['date_fin'] ?? '');
    return ($debut === '' || $debut <= $aujourdhui)
        && ($fin === '' || $fin >= $aujourdhui);
}

// La cellule « campagnes » d'une ligne de la liste des structures : une pastille
// par campagne, une croix pour l'en retirer, un « + » pour l'y ajouter. Rendue
// ici plutôt que dans chaque module, pour la même raison que la barre
// ci-dessous : le démarchage et la recherche de fonds posent la MÊME cellule, et
// ce qui les sépare tient en quatre chaînes — la route d'une campagne et trois
// phrases. Écrite deux fois, elle divergeait au premier ajustement.
//
// $campagnes : [[id, nom, saison en cours], …] — la forme que rendent
// structures_campagnes() (lib/booking.php) et fonds_structures_campagnes()
// (lib/fonds.php).
// $textes : 'route' (celle d'une campagne), 'confirme' (fn(string $nom): string,
// ce que le retrait détruit, dit par celui qui le sait), 'ajouter_title' et
// 'ajouter_aria'.
function campagnes_cellule_html(int $structureId, array $campagnes, bool $peutEcrire, array $textes): string
{
    $h = '';
    foreach ($campagnes as [$id, $nom, $enCours]) {
        // Teal pour une campagne dont la saison court, comme son nom sur la
        // liste des campagnes : dans une file de pastilles, c'est celle qui
        // appelle du travail. Les autres restent au ton neutre — elles sont du
        // contexte, pas une tâche.
        $h .= '<span class="badge' . ($enCours ? ' camp-en-cours' : '') . '">'
            . '<a href="?p=' . $textes['route'] . '&id=' . $id . '">' . e($nom) . '</a>';
        if ($peutEcrire) {
            $h .= '<button type="button" class="btn-tag-x" data-campagne-retirer="' . $id
                . '" data-retirer-confirme="' . e(($textes['confirme'])($nom)) . '"'
                . ' title="Retirer de cette campagne" aria-label="Retirer de la campagne ' . e($nom) . '">×</button>';
        }
        $h .= '</span> ';
    }
    // Pas de tiret quand il n'y en a aucune : sur une colonne où la plupart des
    // cellules sont vides, une rangée de tirets attirerait l'œil sur ce qui
    // n'existe pas — même choix que pour les étiquettes.
    if ($peutEcrire) {
        $h .= '<button type="button" class="badge campagne-ajouter-btn" data-campagne-structure="' . $structureId
            . '" title="' . e($textes['ajouter_title']) . '"'
            . ' aria-label="' . e($textes['ajouter_aria']) . '">+</button>';
    }
    return $h;
}

// Les identifiants de structures qui EXISTENT, parmi ceux qu'un formulaire a
// postés. Un identifiant forgé doit être ignoré, pas violer une clé étrangère.
// En lots, car une sélection large en compte des milliers (lots_ids()).
// Partagée par les deux formulaires qui enregistrent une sélection de
// structures : une campagne de démarchage et une campagne de recherche de fonds.
function structures_existantes(array $ids): array
{
    $valides = [];
    foreach (lots_ids($ids) as $lot) {
        $stmt = db()->prepare('SELECT id FROM structures WHERE id IN (' . sql_in($lot) . ')');
        $stmt->execute($lot);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $sid) {
            $valides[] = (int) $sid;
        }
    }
    return $valides;
}

// Une barre d'avancement segmentée : une piste, des segments posés dessus, et
// ce qui reste EST la piste. Rendue ici plutôt que dans chaque vue — l'avancement
// d'une campagne de démarchage (campagne_barre_html(), lib/booking.php) et celui
// d'une recherche de fonds en francs (fonds_barre_html(), lib/fonds.php) montrent
// la même chose et doivent la montrer pareil.
//
// $segments : clé => [classe CSS, valeur]. La clé devient data-part, par quoi
// un écran repeint la barre sans recharger (voir le script de views/booking_campagne.php).
//
// $repere : ['pct' => position en %, 'classe' => classe CSS] — un trait posé en
// travers de la piste, pour un seuil qui n'est pas la fin de la barre. La
// recherche de fonds s'en sert pour son objectif minimal, que l'objectif idéal
// dépasse. Facultatif : une barre à un seul horizon n'a pas de seuil à marquer.
function barre_segments_html(array $segments, float $total, string $titre, string $classe = '', ?array $repere = null): string
{
    $pct = fn (float $n): float => $total > 0 ? round($n * 100 / $total, 2) : 0;
    $h = '<span class="camp-barre' . ($classe !== '' ? ' ' . e($classe) : '') . '"'
       . ' role="img" title="' . e($titre) . '" aria-label="' . e($titre) . '">';
    foreach ($segments as $cle => [$cl, $valeur]) {
        $h .= '<span class="camp-seg ' . $cl . '" data-part="' . e((string) $cle) . '"'
            . ' style="width:' . $pct((float) $valeur) . '%"></span>';
    }
    if ($repere !== null && (float) $repere['pct'] > 0) {
        $h .= '<span class="' . e((string) ($repere['classe'] ?? 'camp-repere'))
            . '" style="left:' . round((float) $repere['pct'], 2) . '%"></span>';
    }
    return $h . '</span>';
}

function badge(string $texte, string $classe = ''): string
{
    $cls = $classe !== '' ? ' ' . $classe . '-badge' : '';
    return '<span class="badge' . $cls . '">' . e($texte) . '</span>';
}

// --- Couleurs : dérive la palette de l'appli depuis la couleur principale
// choisie (Paramètres > Employeur) — un seul réglage, tout le reste suit. ---

// Hex (#rrggbb, # optionnel) → [teinte 0-360, saturation 0-100, luminosité 0-100].
// Hex invalide → repli sur la couleur principale par défaut de l'appli.
function hex_vers_hsl(string $hex): array
{
    $hex = ltrim($hex, '#');
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
        $hex = '6d4ade';
    }
    $r = hexdec(substr($hex, 0, 2)) / 255;
    $g = hexdec(substr($hex, 2, 2)) / 255;
    $b = hexdec(substr($hex, 4, 2)) / 255;
    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $l = ($max + $min) / 2;
    if ($max === $min) {
        return [0.0, 0.0, round($l * 100, 1)];
    }
    $d = $max - $min;
    $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
    $h = match ($max) {
        $r      => fmod(($g - $b) / $d, 6),
        $g      => ($b - $r) / $d + 2,
        default => ($r - $g) / $d + 4,
    };
    $h *= 60;
    if ($h < 0) {
        $h += 360;
    }
    return [round($h, 1), round($s * 100, 1), round($l * 100, 1)];
}

// [teinte 0-360, saturation 0-100, luminosité 0-100] → hex (#rrggbb).
function hsl_vers_hex(float $h, float $s, float $l): string
{
    $s = max(0.0, min(100.0, $s)) / 100;
    $l = max(0.0, min(100.0, $l)) / 100;
    $h = fmod($h, 360);
    if ($h < 0) {
        $h += 360;
    }
    $c = (1 - abs(2 * $l - 1)) * $s;
    $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
    $m = $l - $c / 2;
    [$r, $g, $b] = match (true) {
        $h < 60  => [$c, $x, 0],
        $h < 120 => [$x, $c, 0],
        $h < 180 => [0, $c, $x],
        $h < 240 => [0, $x, $c],
        $h < 300 => [$x, 0, $c],
        default  => [$c, 0, $x],
    };
    return sprintf('#%02x%02x%02x', round(($r + $m) * 255), round(($g + $m) * 255), round(($b + $m) * 255));
}

// Teintes dérivées de la couleur principale : boutons (primary/primary-d),
// fond teinté (primary-tint), et teintes sombres pour les titres et la barre
// latérale (brand/brand-2). Voir couleurs_css_vars() pour l'injection en CSS.
function couleurs_derivees(string $hexPrincipale): array
{
    [$h, $s, $l] = hex_vers_hsl($hexPrincipale);
    $primary = hsl_vers_hex($h, $s, $l);
    $rgb     = sscanf($primary, '#%02x%02x%02x');
    
    // Si la couleur principale est trop claire, on l'assombrit légèrement
if ($l > 75) {
    $l = max(75, $l - (($l - 75) * 0.75));
    $primary = hsl_vers_hex($h, $s, $l);

}
    
    return [
        'primary'      => $primary,
        'primary_d'    => hsl_vers_hex($h, $s, max($l - 12, 15)),
        'primary_tint' => hsl_vers_hex($h, min($s + 10, 90), 95),
        'primary_rgb'  => implode(' ', $rgb),
        'brand'        => hsl_vers_hex($h, min($s + 10, 78), 20),
        'brand_2'      => hsl_vers_hex($h, min($s + 8, 78), 35),

        // Variantes pour le mode sombre. Ces quatre valeurs ne peuvent pas être
        // écrites dans assets/app.css : elles dérivent d'une couleur choisie en
        // base (Paramètres → Employeur), et le bloc <style> qui les injecte est
        // émis APRÈS la feuille de style — il l'emporterait donc sur toute
        // media query qui s'y trouverait.
        //
        // Les teintes (L=95, quasi blanches) servent de FOND aux éléments
        // actifs : sur sombre elles doivent devenir des fonds sourds, pas des
        // aplats lumineux. --brand, à l'inverse, sert de couleur de TEXTE et de
        // fond de barre : assombri en clair (L=20), il doit s'éclaircir.
        'primary_tint_sombre' => hsl_vers_hex($h, min($s, 45), 22),
        'brand_sombre'        => hsl_vers_hex($h, min($s + 10, 78), 82),
        'brand_2_sombre'      => hsl_vers_hex($h, min($s + 8, 78), 68),
        // Une couleur d'accent très sombre disparaît sur fond sombre : on lui
        // impose un plancher de luminosité, sans toucher à sa teinte.
        //
        // Plancher à 50 et non plus haut : l'accent sert de FOND à des boutons
        // dont le texte est blanc. Trop éclairci, il satisfait le contraste avec
        // la page mais ruine celui du texte — mesuré à 3,7:1 avec un plancher à
        // 62, sous le seuil de 4,5. Un ton moyen tient les deux contraintes,
        // le fond de page étant lui très sombre.
        'primary_sombre'      => hsl_vers_hex($h, $s, max($l, 50)),
        // La variante « -d » sert au SURVOL. En clair elle assombrit ; sur fond
        // sombre il faut l'inverse, sinon le survol s'enfonce dans le fond — et
        // le texte des badges, qui l'utilise, devenait illisible.
        // Sur seize règles qui l'utilisent, quatorze s'en servent comme COULEUR
        // DE TEXTE sur une teinte (badges, onglets actifs, liens survolés) et
        // deux seulement comme fond de survol. On l'oriente donc vers l'usage
        // dominant : clair, pour être lisible sur les teintes sombres. Les deux
        // fonds de survol sont traités à part dans le bloc sombre d'app.css.
        'primary_d_sombre'    => hsl_vers_hex($h, min($s + 15, 95), 78),
    ];
}

// Bloc <style> qui redéfinit les variables CSS de couleur d'après la couleur
// principale et la couleur de mise en évidence choisies, plus l'image de fond
// personnalisée (page Apparence) — injecté dans <head> (views/layout.php),
// après app.css. Cette dernière remplace la couleur principale à certains
// endroits (boutons principaux, sommes de brut, liens, tags) ; voir
// --highlight* dans app.css pour la liste des règles concernées.
// L'image de fond personnalisée (si configurée) est posée ici en dur
// (body.has-sidebar::before{background-image:...}) plutôt que via une
// variable CSS consommée depuis app.css : une URL relative dans une custom
// property se résout par rapport à la feuille de style où la var() est
// *utilisée*, pas où la propriété est déclarée — utilisée dans app.css
// (assets/app.css), "uploads/…" se serait résolu en "assets/uploads/…"
// (inexistant) au lieu de "uploads/…" à la racine du site. Ici, dans le
// <style> inline de la page elle-même, l'URL relative se résout normalement
// par rapport à la page — comme param_fond()/param_logo() partout ailleurs
// (<img src="uploads/…">). L'effet clair/flouté (filter) est posé sur ce
// même ::before, jamais sur <body> lui-même — voir le commentaire dans
// app.css (body.has-sidebar::before). Sans image personnalisée (cas par
// défaut), rien n'est émis ici : le fond calculé (.wave-decor, voir
// views/_fond_decor.php) prend le relais, posé par views/layout.php.
// Émet les deux blocs qui appliquent une palette sombre : l'un sous la media
// query (mode « automatique »), l'autre sur l'attribut data-theme (choix
// explicite). $valeurs : tokens réels => valeur sombre.
//
// Deux blocs et non un seul parce qu'aucun sélecteur CSS ne combine une media
// query et un attribut ; la liste est donc construite ici, une fois, pour que
// les deux versions ne puissent pas diverger. Le :not([data-theme="clair"])
// est indispensable : sans lui, un thème clair choisi explicitement perdrait
// face à un système réglé en sombre.
// Thème choisi dans Paramètres → Apparence : 'auto' (suit le système), 'clair'
// ou 'sombre'. Réglage commun à l'installation, comme les couleurs et le fond
// — voir docs/DECISIONS.md § Thème.
// Les trois thèmes, dans l'ordre du sélecteur : la valeur stockée, son icône
// et son nom. UNE table, parce que trois endroits énuméraient la même liste —
// le sélecteur de ?p=apparence, le résumé juste au-dessus de lui, et la liste
// blanche de l'enregistrement (lib/routes.php). La forme est celle
// qu'attend icon_picker().
const THEMES = [
    'auto'   => ['icone' => 'monitor', 'label' => 'Automatique (système)'],
    'clair'  => ['icone' => 'sun',     'label' => 'Clair'],
    'sombre' => ['icone' => 'moon',    'label' => 'Sombre'],
];

function param_theme(): string
{
    $t = (string) param('employeur_theme', 'auto');
    return isset(THEMES[$t]) ? $t : 'auto';
}

// Densité des lignes de tableau : « dense », « normal » (défaut) ou « large ».
// Ne joue que sur le retrait VERTICAL des cellules (--row-pad-y) : c'est lui
// qui fait tenir plus ou moins de lignes à l'écran. L'horizontal, lui, aligne
// le texte sur le bord de sa carte ou de sa page et n'a pas à bouger.
// Même forme que param_theme() : liste blanche, repli sur le défaut.
// Même table, même raison que THEMES ci-dessus : une quatrième densité ajoutée
// à une seule des listes aurait été acceptée en base et ignorée à l'affichage,
// ou l'inverse. L'icône dit le nombre de rangées qui tiennent.
const DENSITES = [
    'dense'  => ['icone' => 'rows-4', 'label' => 'Dense'],
    'normal' => ['icone' => 'rows-3', 'label' => 'Normal'],
    'large'  => ['icone' => 'rows-2', 'label' => 'Large'],
];

function param_densite(): string
{
    $d = (string) param('employeur_densite', 'normal');
    return isset(DENSITES[$d]) ? $d : 'normal';
}

function css_palette_sombre(array $valeurs): string
{
    $decl = '';
    foreach ($valeurs as $token => $valeur) {
        $decl .= $token . ':' . $valeur . ';';
    }
    return '@media (prefers-color-scheme: dark){:root:not([data-theme="clair"]){' . $decl . '}}'
        . ':root[data-theme="sombre"]{' . $decl . '}';
}

function couleurs_css_vars(): string
{
    $c = couleurs_derivees((string) param('employeur_couleur_principale', '#6d4ade'));
    $h = couleurs_derivees((string) param('employeur_couleur_evidence', '#2563eb'));
    // param_fond_decor() et non param('employeur_fond') : une image peut rester
    // stockée alors qu'un décor calculé est choisi, et elle ne doit alors plus
    // s'afficher.
    $fondPerso = param_fond_decor() === 'image' ? (string) param('employeur_fond', '') : '';
    $styleFond = '';
    if ($fondPerso !== '') {
        // Effets combinables (voir param_fond_clair()/param_fond_floute()) :
        // les fonctions filter s'enchaînent dans une seule déclaration,
        // chacune s'appliquant sur le résultat de la précédente.
        $filtres = [];
        if (param_fond_clair()) { $filtres[] = 'contrast(.1) brightness(2)'; }
        if (param_fond_floute()) { $filtres[] = 'blur(10px)'; }
        $filtreFond = $filtres ? 'filter:' . implode(' ', $filtres) . ';' : '';
        $styleFond = 'body.has-sidebar::before{background-image:url(' . json_encode($fondPerso) . ');' . $filtreFond . '}';
    }
    // --primary-base : même valeur que --primary, mais que
    // module_couleur_css_vars() ne réécrit JAMAIS. Sert là où il faut la
    // couleur principale de l'employeur telle quelle, indépendamment du module
    // consulté — l'icône du tableau de bord (views/layout.php), qui n'appartient
    // à aucun module et doit rester un repère de teinte constante.
    // Bloc clair, puis surcharge sombre. L'ordre compte : la media query vient
    // après, elle l'emporte donc à spécificité égale quand elle s'applique.
    $clair = ':root{--primary:' . $c['primary'] . ';--primary-d:' . $c['primary_d']
        . ';--primary-tint:' . $c['primary_tint'] . ';--primary-rgb:' . $c['primary_rgb']
        . ';--primary-base:' . $c['primary']
        . ';--brand:' . $c['brand'] . ';--brand-2:' . $c['brand_2']
        . ';--highlight:' . $h['primary'] . ';--highlight-d:' . $h['primary_d']
        . ';--highlight-tint:' . $h['primary_tint'] . ';--highlight-rgb:' . $h['primary_rgb'] . ';}';

    $sombre = css_palette_sombre([
        '--primary'        => $c['primary_sombre'],
        '--primary-base'   => $c['primary_sombre'],
        '--primary-d'      => $c['primary_d_sombre'],
        '--primary-tint'   => $c['primary_tint_sombre'],
        '--brand'          => $c['brand_sombre'],
        '--brand-2'        => $c['brand_2_sombre'],
        '--highlight'      => $h['primary_sombre'],
        '--highlight-d'    => $h['primary_d_sombre'],
        '--highlight-tint' => $h['primary_tint_sombre'],
    ]);

    return '<style>' . $clair . $sombre . $styleFond . '</style>';
}


// Options d'unité de temps pour un <select> de ligne de prestation, encodées
// "heures|libellé" (valeur) — partagées entre le formulaire de fiche de salaire
// et l'ajout rapide de prestation depuis un événement.
function options_unites(array $unites): string
{
    $opts = '';
    foreach ($unites as $u) {
        $val = $u['heures'] . '|' . $u['libelle'];
        $opts .= '<option value="' . e($val) . '" data-h="' . e((string) $u['heures']) . '">'
            . e($u['libelle']) . ' (' . nombre_court($u['heures']) . ' h)</option>';
    }
    return $opts;
}

// Options de taux horaire standard + « Autre » pour un <select> de ligne de prestation.
function options_taux_horaires(array $tauxHoraires): string
{
    $opts = '';
    foreach ($tauxHoraires as $th) {
        $opts .= '<option value="' . e((string) $th['montant']) . '" data-rate="' . e((string) $th['montant']) . '">'
            . e($th['libelle'] . ' — ' . chf((float) $th['montant']) . ' CHF/h') . '</option>';
    }
    $opts .= '<option value="autre">Autre…</option>';
    return $opts;
}

// Valide une valeur contre une liste blanche, avec repli sur une valeur par
// défaut si absente/invalide — un seul point de contrôle plutôt que de
// recopier le in_array(...) ? ... : défaut à chaque nouveau champ validé
// (notamment le dispatcher de modification groupée des événements).
function valeur_autorisee(?string $valeur, array $whitelist, string $defaut = ''): string
{
    return in_array($valeur, $whitelist, true) ? $valeur : $defaut;
}

// Un axe analytique s'écrit dans DEUX registres, et c'est voulu :
//
//   court  — le CODE seul (options_axes() ci-dessous), dans une ligne de
//            tableau : une prestation, une écriture. La cellule est étroite,
//            et qui ventile reconnaît ses axes à leur code.
//   long   — « code — libellé » (options_axes_longues()), dans un formulaire
//            plein écran, où l'on choisit une fois pour toutes et où rien
//            n'oblige à connaître les codes par cœur.
//
// Les deux étaient écrits à la main à cinq endroits, chacun choisissant son
// registre sans le dire. Ils sont ici, et le choix se fait en appelant l'une
// ou l'autre.
//
// Le nom sous lequel un compte s'affiche : prénom et nom, celui des deux qui est
// rempli, et à défaut l'adresse e-mail. Jamais rien : un compte sans prénom ni
// nom — rien ne les rend obligatoires — apparaissait comme une absence d'auteur,
// et une note signée de personne ne se distinguait plus d'une note importée.
// C'est la cascade que le rail de navigation appliquait déjà pour le compte
// connecté ; elle vaut partout où l'on nomme quelqu'un.
function utilisateur_nom_affiche($prenom, $nom, $email = ''): string
{
    $prenom = trim((string) $prenom);
    $nom    = trim((string) $nom);
    $plein  = trim($prenom . ' ' . $nom);
    return $plein !== '' ? $plein : trim((string) $email);
}

// Options d'axe analytique pour un <select> de ligne de prestation (fiche de
// salaire ou événement) — un « — » en tête pour l'absence d'axe.
function options_axes(array $axes): string
{
    $opts = '<option value="">—</option>';
    foreach ($axes as $ax) {
        $label = ($ax['code'] !== '' && $ax['code'] !== null) ? $ax['code'] : $ax['libelle'];
        $opts .= '<option value="' . (int) $ax['id'] . '">' . e($label) . '</option>';
    }
    return $opts;
}

// Le registre long, pour un <select> de formulaire : « code — libellé », ou le
// seul libellé quand l'axe n'a pas de code. $vide est le texte de l'option
// « aucun axe », qui ne dit pas la même chose selon l'écran (« — Aucun — » sur
// un projet, « — Celui du projet — » sur une campagne qui en hérite).
function options_axes_longues(array $axes, int $choisi, string $vide): string
{
    $opts = '<option value="">' . e($vide) . '</option>';
    foreach ($axes as $ax) {
        $code = trim((string) ($ax['code'] ?? ''));
        $label = $code !== '' ? $code . ' — ' . (string) $ax['libelle'] : (string) $ax['libelle'];
        $opts .= '<option value="' . (int) $ax['id'] . '"' . ((int) $ax['id'] === $choisi ? ' selected' : '') . '>'
            . e($label) . '</option>';
    }
    return $opts;
}

// Pré-sélectionne une <option> dans un bloc d'options déjà généré (unité, taux
// horaire, axe…) — une ligne éditée en place est ainsi pré-remplie avec sa
// valeur déjà enregistrée.
function preselectionner_option(string $optionsHtml, string $value): string
{
    if ($value === '') {
        return $optionsHtml;
    }
    return preg_replace_callback('/<option value="([^"]*)"/', function ($m) use ($value) {
        return $m[0] . (html_entity_decode($m[1], ENT_QUOTES) === $value ? ' selected' : '');
    }, $optionsHtml);
}

const MOIS_FR = [
    1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
    5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
    9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre',
];

function mois_nom(int $m): string
{
    return MOIS_FR[$m] ?? (string) $m;
}

// <option> d'un <select> mois, avec un repli vide (« — », valeur '') distinct
// de « Tous » (0) utilisé dans les filtres — ici une valeur absente doit
// pouvoir être enregistrée telle quelle (colonne NULL), pas juste ignorée en
// filtrage. Voir la carte « Période » de ?p=structure (structure_form.php).
function mois_options(int $selectionne): string
{
    $h = '<option value=""' . ($selectionne === 0 ? ' selected' : '') . '>—</option>';
    for ($m = 1; $m <= 12; $m++) {
        $h .= '<option value="' . $m . '"' . ($selectionne === $m ? ' selected' : '') . '>' . e(mois_nom($m)) . '</option>';
    }
    return $h;
}

// Abrégé 3 lettres majuscules (JAN, FÉV… JUN/JUL distincts de juin/juillet,
// contrairement à une simple troncature) — puce de date façon calendrier.
const MOIS_ABREGES_FR = [
    1 => 'JAN', 2 => 'FÉV', 3 => 'MAR', 4 => 'AVR', 5 => 'MAI', 6 => 'JUN',
    7 => 'JUL', 8 => 'AOÛ', 9 => 'SEP', 10 => 'OCT', 11 => 'NOV', 12 => 'DÉC',
];

function mois_abrege(int $m): string
{
    return MOIS_ABREGES_FR[$m] ?? '';
}

// Les quatre logos employeur : deux pour un affichage en grand (fiches, e-mail,
// écran de connexion), deux pour un affichage EN PETIT — le rail et la favicone,
// où un logo large se réduit à une ligne illisible. Les variantes « mini » sont
// facultatives : sans elles, le logo normal sert partout (voir
// logo_petit_variante()).
const LOGO_VARIANTES = [
    'clair'       => 'employeur_logo_clair',
    'sombre'      => 'employeur_logo_sombre',
    'mini_clair'  => 'employeur_logo_mini_clair',
    'mini_sombre' => 'employeur_logo_mini_sombre',
];

// Chemin web d'un logo employeur (clé de LOGO_VARIANTES) ou '' si non défini.
// Une variante inconnue retombe sur le logo pour fond clair.
function param_logo(string $variant): string
{
    return (string) param(LOGO_VARIANTES[$variant] ?? LOGO_VARIANTES['clair'], '');
}

// Variante à utiliser pour un affichage EN PETIT sur le fond demandé, ou null
// si aucun logo n'est configuré. On prend la version « mini » du bon fond si
// elle existe, sinon le logo normal du même fond, et seulement ensuite les
// variantes de l'autre fond : mieux vaut un logo imparfaitement contrasté que
// pas de logo du tout — même arbitrage que pour le rail (views/layout.php).
function logo_petit_variante(string $fond): ?string
{
    $fond  = $fond === 'sombre' ? 'sombre' : 'clair';
    $autre = $fond === 'sombre' ? 'clair' : 'sombre';
    foreach (['mini_' . $fond, $fond, 'mini_' . $autre, $autre] as $variant) {
        if (param_logo($variant) !== '') {
            return $variant;
        }
    }
    return null;
}

// Représentation data: URI (base64) du logo employeur — utilisée pour le logo
// du rail (views/layout.php, .side-logo) afin d'éviter une requête HTTP
// séparée à chaque navigation. Chaque clic dans l'appli recharge la page
// entière (pas de SPA) ; en dev, php -S (voir CLAUDE.md) n'envoie aucun
// en-tête de cache pour les fichiers statiques (vérifié : ni Last-Modified,
// ni Cache-Control), donc l'image était re-téléchargée à chaque clic — visible
// comme un flash de son texte alt le temps du chargement. Inliner l'image
// dans le HTML supprime cette requête, donc ce flash, quelle que soit la
// configuration de cache du serveur (dev ou prod). Repli sur le chemin web
// classique si le fichier est illisible (pas d'image cassée).
// Cache disque d'une chaîne coûteuse à produire, invalidé par la source.
//
// Motivation : les deux fonctions de data-URI ci-dessous s'exécutaient à CHAQUE
// affichage de page (elles ne servent que dans views/layout.php, présent
// partout). asset_data_uri_mini() décode un PNG de 612×553, le rééchantillonne
// via GD, le réencode et le passe en base64 — pour un badge affiché à 12px.
// Le résultat ne change que si le fichier source change : le recalculer à
// chaque requête est du travail pur perdu, particulièrement sur mutualisé.
//
// La signature inclut mtime ET taille de chaque source : un fichier remplacé
// dans la même seconde (mtime identique) mais de taille différente invalide
// quand même l'entrée. Les entrées devenues obsolètes (même clé, autre
// signature) sont supprimées à l'écriture — sans quoi chaque changement de logo
// laisserait un fichier orphelin derrière lui.
//
// Le cache vit à côté de la base (dirname(APP_DB_PATH)) et non dans le dépôt :
// il suit ainsi APP_DB_PATH hors racine web en production, et bénéficie des
// protections déjà posées sur ce dossier. Toute défaillance d'écriture est
// silencieuse et sans conséquence : on renvoie la valeur calculée.
function cache_disque(string $cle, array $fichiersSources, callable $produire): string
{
    $sig = [];
    foreach ($fichiersSources as $f) {
        $sig[] = @filemtime($f) . ':' . @filesize($f);
    }
    $dir     = dirname(APP_DB_PATH) . '/cache';
    $empreinte = substr(hash('sha256', implode('|', $sig)), 0, 16);
    $fichier = $dir . '/' . $cle . '-' . $empreinte . '.txt';

    $cache = @file_get_contents($fichier);
    if ($cache !== false) {
        return $cache;
    }

    $valeur = (string) $produire();

    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
        proteger_dossier_donnees($dir);
    }
    foreach ((array) @glob($dir . '/' . $cle . '-*.txt') as $obsolete) {
        @unlink($obsolete);
    }
    // Écriture atomique : un fichier temporaire puis rename(), pour qu'une
    // requête concurrente ne puisse jamais lire un contenu tronqué.
    $tmp = $fichier . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $valeur) !== false) {
        @rename($tmp, $fichier);
    }
    return $valeur;
}

// Source à utiliser pour AFFICHER un logo employeur.
//
// En production : l'URL du fichier. L'hébergeur le sert avec
// « cache-control: max-age=31536000, immutable » (voir .htaccess), et son nom
// porte une empreinte (handle_logo_upload()) — un remplacement change donc
// l'URL. Le navigateur le télécharge une fois, puis plus jamais.
//
// En développement : le data-URI. Le serveur intégré de PHP n'envoie aucun
// en-tête de cache, et le logo clignotait à chaque navigation.
//
// Pourquoi ce détour plutôt que d'inliner partout : mesuré sur une page de 100
// structures, les logos inlinés pesaient 21 Ko une fois la page compressée, soit
// 52 % de son poids — et sur CHAQUE page. Le base64 gonfle de 33 % et ne se
// compresse pas, alors que le reste du balisage, très répétitif, se réduit à
// presque rien. Le thème sombre avait doublé la note en rendant deux variantes.
function param_logo_src(string $variant): string
{
    return APP_ENV === 'dev' ? param_logo_data_uri($variant) : param_logo($variant);
}

function param_logo_data_uri(string $variant): string
{
    $chemin = param_logo($variant);
    if ($chemin === '') {
        return '';
    }
    $fs = realpath(__DIR__ . '/../' . $chemin) ?: (__DIR__ . '/../' . $chemin);
    if (!is_file($fs) || !is_readable($fs)) {
        return $chemin;
    }
    return cache_disque('logo-' . $variant, [$fs], function () use ($fs, $chemin) {
        $dims = @getimagesize($fs);
        $data = @file_get_contents($fs);
        if ($data === false) {
            return $chemin;
        }
        return 'data:' . ((string) ($dims['mime'] ?? 'image/png')) . ';base64,' . base64_encode($data);
    });
}

// Variante de param_logo_data_uri() pour un petit badge d'image FIXE du dépôt
// (pas un logo employeur configurable) — ex. le logo Lasso du pied du rail
// (views/layout.php, .side-powered-logo), affiché à 12px de haut alors que le
// fichier source (assets/lasso.png) fait 612×553px (~190 Ko) : l'inliner tel
// quel ajouterait ~250 Ko en base64 à CHAQUE page (pas de SPA). Redimensionné
// via GD avant l'encodage — quelques Ko au lieu de ~250 Ko, sans avoir à
// committer un second fichier image à maintenir en plus de l'original.
// $hauteurPx : hauteur cible en pixels réels (prévoir 2-3× la hauteur CSS
// affichée pour rester net sur les écrans haute densité).
function asset_data_uri_mini(string $cheminRelatif, int $hauteurPx): string
{
    $fs = __DIR__ . '/../' . $cheminRelatif;
    if (!is_file($fs) || !is_readable($fs) || !extension_loaded('gd')) {
        return $cheminRelatif;
    }
    // La hauteur cible fait partie de la clé : deux appels sur la même source
    // à des tailles différentes ne doivent pas se marcher dessus.
    return cache_disque(
        'mini-' . preg_replace('/[^a-z0-9]+/i', '_', $cheminRelatif) . '-' . $hauteurPx,
        [$fs],
        fn () => asset_data_uri_mini_calculer($fs, $cheminRelatif, $hauteurPx)
    );
}

function asset_data_uri_mini_calculer(string $fs, string $cheminRelatif, int $hauteurPx): string
{
    $src = @imagecreatefrompng($fs);
    if (!$src) {
        return $cheminRelatif;
    }
    $w = imagesx($src);
    $h = imagesy($src);
    if ($h <= 0) {
        return $cheminRelatif;
    }
    $nH = $hauteurPx;
    $nW = max(1, (int) round($w * $nH / $h));
    $dst = imagecreatetruecolor($nW, $nH);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nW, $nH, $w, $h);
    // Pas d'imagedestroy() : sans effet depuis PHP 8.0 (GC gère les ressources
    // GD), déprécié en 8.5 — un appel émettrait un warning imprimé directement
    // dans le flux de sortie, corrompant l'attribut src= au milieu du rendu.
    ob_start();
    imagepng($dst);
    $data = ob_get_clean();
    if ($data === false || $data === '') {
        return $cheminRelatif;
    }
    return 'data:image/png;base64,' . base64_encode($data);
}

// Fonds proposés par ?p=apparence : sept décors calculés à partir des
// couleurs de l'employeur (SVG en ligne, views/_fond_decor.php) et l'image
// personnalisée. Un seul est actif à la fois — d'où un choix, et non une
// image qui l'emporterait silencieusement sur un décor.
const FONDS_DECOR = [
    'maillage' => 'Maillage',
    'vagues'   => 'Vagues',
    'grille'   => 'Grille',
    'courbes'  => 'Courbes',
    'pois'     => 'Pois',
    'anneaux'  => 'Anneaux',
    'strates'  => 'Strates',
    'image'    => 'Image personnalisée',
];

// Les COULEURS d'un décor calculé. Un décor n'en connaît que deux, --fond-a et
// --fond-b (assets/app.css) ; ce réglage dit à quoi elles renvoient. Le défaut
// « duo » est ce que faisaient les décors avant d'être réglables, et les
// installations en service n'y voient donc aucun changement.
// Rien ici n'est une couleur écrite en dur : les quatre combos recombinent des
// teintes déjà dérivées de celles de l'employeur (couleurs_derivees()).
const FONDS_COULEURS = [
    'duo'                => 'Principale et mise en évidence',
    'principale'         => 'Principale, en deux nuances',
    'evidence'           => 'Mise en évidence, en deux nuances',
    'encre'              => 'Encre, en deux nuances',
    'encre-principale'   => 'Encre et principale',
    'surface-principale' => 'Surface et principale',
    'surface-encre'      => 'Surface et encre',
];

// Combo sûr : tout ce qui n'est pas dans la liste retombe sur le défaut. Pure
// (pas de base) pour être testable, comme fond_decor_valide() ci-dessous.
function fond_couleurs_valide(string $valeur): string
{
    return isset(FONDS_COULEURS[$valeur]) ? $valeur : 'duo';
}

// Combo enregistré. Ne concerne que les décors calculés : une image
// personnalisée a ses propres couleurs, et ses propres effets.
function param_fond_couleurs(): string
{
    return fond_couleurs_valide((string) param('employeur_fond_couleurs', 'duo'));
}

// Valeur de fond sûre : tout ce qui n'est pas dans la liste retombe sur le
// défaut. Pure (pas de base) pour être testable, et appelée aussi bien à
// l'écriture (route_apparence()) qu'à la lecture (param_fond_decor()).
function fond_decor_valide(string $valeur): string
{
    return isset(FONDS_DECOR[$valeur]) ? $valeur : 'maillage';
}

// Fond effectivement affiché. « image » choisie mais aucune image envoyée :
// on retombe sur le défaut plutôt que sur un écran nu — le cas existe, une
// image peut être supprimée sans que le choix suive.
function param_fond_decor(): string
{
    $decor = fond_decor_valide((string) param('employeur_fond_decor', 'maillage'));
    if ($decor === 'image' && (string) param('employeur_fond', '') === '') {
        return 'maillage';
    }
    return $decor;
}

// Chemin web de l'image de fond de l'application (page Apparence) — celle
// uploadée (uploads/…) si personnalisée, sinon le fond par défaut du dépôt.
function param_fond(): string
{
    $p = (string) param('employeur_fond', '');
    return $p !== '' ? $p : 'assets/fond.jpg';
}

// Effets appliqués à l'image de fond (page Apparence, cases à cocher
// combinables — les deux peuvent être actives en même temps) : 'clair'
// (adoucie/éclaircie, meilleure lisibilité) et 'floute'.
// Chaque module porte-t-il sa couleur, ou l'application entière s'en tient-elle
// à la couleur principale de l'employeur ? Par défaut oui, une par module :
// c'est le comportement qu'ont connu toutes les installations jusqu'ici, et le
// rail de navigation s'y lit d'un coup d'œil. Mais une association dont
// l'identité tient à UNE couleur voit son application en arborer six, ce qui
// n'est pas un réglage qu'on devrait subir.
function param_couleurs_modules(): bool
{
    return (string) param('employeur_couleurs_modules', '1') === '1';
}

function param_fond_clair(): bool
{
    return (string) param('employeur_fond_clair', '') === '1';
}
function param_fond_floute(): bool
{
    return (string) param('employeur_fond_floute', '') === '1';
}

// Traite l'upload d'un logo. Renvoie le chemin web relatif (uploads/…) si un
// fichier valide a été envoyé, null si aucun fichier, ou lève RuntimeException.
function handle_logo_upload(string $field): ?string
{
    $f = $_FILES[$field] ?? null;
    if ($f === null || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null; // aucun fichier → inchangé
    }
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException("Échec de l'envoi du fichier (code {$f['error']}).");
    }
    if ($f['size'] > 2 * 1024 * 1024) {
        throw new RuntimeException('Image trop lourde (2 Mo maximum).');
    }
    $info = @getimagesize($f['tmp_name']); // valide que c'est une vraie image
    $exts = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
    if ($info === false || !isset($exts[$info[2]])) {
        throw new RuntimeException('Format non supporté (PNG, JPG, GIF ou WebP).');
    }
    $dir = __DIR__ . '/../uploads';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $name = $field . '_' . bin2hex(random_bytes(6)) . '.' . $exts[$info[2]];
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException("Impossible d'enregistrer le fichier.");
    }
    @chmod($dir . '/' . $name, 0644);
    return 'uploads/' . $name;
}

// Traite l'upload d'un PDF (ex. feuille SUISA pré-remplie d'un projet).
// Même logique que handle_logo_upload() mais validation par mime réel (finfo)
// plutôt que getimagesize(). Renvoie le chemin web relatif (uploads/…) si un
// fichier valide a été envoyé, null si aucun fichier, ou lève RuntimeException.
function handle_pdf_upload(string $field): ?string
{
    $f = $_FILES[$field] ?? null;
    if ($f === null || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null; // aucun fichier → inchangé
    }
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException("Échec de l'envoi du fichier (code {$f['error']}).");
    }
    if ($f['size'] > 2 * 1024 * 1024) {
        throw new RuntimeException('Fichier trop lourd (2 Mo maximum).');
    }
    $mime = @finfo_file(finfo_open(FILEINFO_MIME_TYPE), $f['tmp_name']);
    if ($mime !== 'application/pdf') {
        throw new RuntimeException('Format non supporté (PDF uniquement).');
    }
    $dir = __DIR__ . '/../uploads';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $name = $field . '_' . bin2hex(random_bytes(6)) . '.pdf';
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException("Impossible d'enregistrer le fichier.");
    }
    @chmod($dir . '/' . $name, 0644);
    return 'uploads/' . $name;
}

// Convertit en UTF-8 un contenu de fichier importé dont l'encodage n'est pas
// déjà de l'UTF-8 valide — cas fréquent d'un carnet d'adresses/tableur
// exporté « CSV » sous Windows sans choix explicite d'encodage (Windows-1252
// par défaut) : sans cette conversion, tout caractère accentué (header ou
// donnée) devient une séquence UTF-8 invalide, silencieusement cassée par
// la suite de la chaîne (mb_strtolower(), json_encode(), écriture SQLite…) —
// symptôme observé : les colonnes/valeurs accentuées ne sont pas reconnues.
function normaliser_encodage_utf8(string $s): string
{
    if (str_starts_with($s, "\xFF\xFE")) {
        return (string) mb_convert_encoding(substr($s, 2), 'UTF-8', 'UTF-16LE');
    }
    if (str_starts_with($s, "\xFE\xFF")) {
        return (string) mb_convert_encoding(substr($s, 2), 'UTF-8', 'UTF-16BE');
    }
    if (mb_check_encoding($s, 'UTF-8')) {
        return $s;
    }
    return (string) mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
}

// Lit le fichier téléversé sous le champ "fichier" (import fiches/écritures/
// événements/factures/structures), avec repli sur un contenu déjà mémorisé en
// session (permet de cliquer « Importer » après « Simuler » sans re-téléverser)
// — factorise le motif dupliqué dans les routes d'import. $sessionNomKey
// optionnel : nom du fichier original à relire depuis la session avec le
// contenu (sinon 'nom' reste vide au repli session). Le contenu est toujours
// normalisé en UTF-8 (voir normaliser_encodage_utf8()) avant d'être retourné
// ou mémorisé, donc le repli session n'a pas besoin de reconvertir.
// Retourne ['contenu' => ?string, 'nom' => string, 'err' => ?string].
function lire_fichier_importe(
    int $maxOctets,
    string $msgTropGros,
    string $sessionKey,
    string $msgAucunFichier,
    ?string $sessionNomKey = null
): array {
    $up = $_FILES['fichier'] ?? null;
    if ($up && ($up['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        if (($up['size'] ?? 0) > $maxOctets) {
            return ['contenu' => null, 'nom' => '', 'err' => $msgTropGros];
        }
        $contenu = normaliser_encodage_utf8((string) file_get_contents($up['tmp_name']));
        return ['contenu' => $contenu, 'nom' => (string) $up['name'], 'err' => null];
    }
    if (!empty($_POST['depuis_session']) && !empty($_SESSION[$sessionKey])) {
        $nom = $sessionNomKey !== null ? (string) ($_SESSION[$sessionNomKey] ?? 'import') : '';
        return ['contenu' => (string) $_SESSION[$sessionKey], 'nom' => $nom, 'err' => null];
    }
    return ['contenu' => null, 'nom' => '', 'err' => $msgAucunFichier];
}

// Sépare « 1213 Lancy » en ['1213', 'Lancy'] (NPA + localité).
function split_npa(string $s): array
{
    $s = trim($s);
    if (preg_match('/^\s*(\d{4})\s+(.+)$/', $s, $m)) {
        return [$m[1], trim($m[2])];
    }
    return ['', $s];
}

// Numéro AVS suisse : 756.XXXX.XXXX.XX (13 chiffres, préfixe 756).
function avs_valide(string $avs): bool
{
    return (bool) preg_match('/^756\.\d{4}\.\d{4}\.\d{2}$/', trim($avs));
}

// Lien « retour » discret, à placer au-dessus du titre de page.
function lien_retour(string $href, string $label): string
{
    return '<a class="back-link" href="' . e($href) . '">' . icon('arrow-left') . ' ' . e($label) . '</a>';
}

// Libellé court d'un événement (date + projet + ville), utilisé partout
// où l'on affiche un lien vers un événement sans reprendre toute la fiche.
function evenement_label_court(array $ev): string
{
    $l = date('d.m.Y', strtotime((string) $ev['date']));
    if (!empty($ev['projet_nom'])) $l .= ' — ' . $ev['projet_nom'];
    if (!empty($ev['ville']))         $l .= ' (' . $ev['ville'] . ')';
    return $l;
}

// Titre de la page d'édition d'un événement (« Date : Projet, Ville »,
// parties absentes proprement omises) — même champs que evenement_label_court()
// mais mise en forme propre à cette page (« : » et virgule, pas « — »/parenthèses
// déjà utilisés ailleurs pour les liens de retour contextuels et les tableaux
// de l'onglet Incohérences — format volontairement distinct, à ne pas fusionner).
function evenement_titre_page(array $ev): string
{
    $titre = (string) ($ev['date'] ?? '') !== '' ? date('d.m.Y', strtotime((string) $ev['date'])) : '';
    $reste = array_filter([$ev['projet_nom'] ?? null, $ev['ville'] ?? null], fn ($v) => trim((string) $v) !== '');
    if ($reste) {
        $titre .= ($titre !== '' ? ' : ' : '') . implode(', ', $reste);
    }
    return $titre !== '' ? $titre : 'Événement';
}

// Ajoute (ou complète) le paramètre ?depuis=type:id (ou ?depuis=type seul
// pour une cible statique sans id, ex. 'dashboard'/'compta_ecritures') à une
// URL, pour que la page cible affiche un lien de retour contextuel (voir
// lien_retour_contextuel()).
function url_avec_retour(string $href, string $type, ?int $id = null): string
{
    $sep = str_contains($href, '?') ? '&' : '?';
    return $href . $sep . 'depuis=' . rawurlencode($id !== null ? $type . ':' . $id : $type);
}

// Suffixe "&q=...&page=..." pour le lien d'une ligne de liste (lieux/
// structures/événements/factures) vers sa fiche détail — recherche texte et
// page de pagination ne sont jamais mémorisées en session (voir
// lien_retour_contextuel()), donc perdues au retour sans ce report explicite
// dans l'URL de la fiche elle-même.
function suffixe_retour_liste(string $recherche, int $pgPage): string
{
    $extra = [];
    if ($recherche !== '') { $extra['q'] = $recherche; }
    if ($pgPage > 1) { $extra['page'] = $pgPage; }
    return $extra ? '&' . http_build_query($extra) : '';
}

// Lien « retour » contextuel : si la page a été atteinte via un lien croisé
// inter-module portant ?depuis=type:id (voir url_avec_retour()), pointe vers
// cet objet précis avec son libellé actuel plutôt que vers la liste générique.
function lien_retour_contextuel(string $defautHref, string $defautLabel): string
{
    $depuis = (string) ($_GET['depuis'] ?? '');
    // Recherche texte (q) et page de pagination (page) : ni l'une ni l'autre
    // n'est mémorisée en session (filtre_persistant() ne les couvre pas, voir
    // evenements_lire_filtres()/structures_filtres()/pagination_page()), donc
    // perdues au retour si on ne les reporte pas explicitement — contrairement
    // aux filtres structurés (type/ville/statut…) déjà repris via la session.
    // Portée aux seules cibles « liste » ci-dessous (statiques + $defautHref) :
    // une fiche précise (structure:id…) n'a pas ces champs à remplir.
    $q = trim((string) ($_GET['q'] ?? ''));
    $page = (int) ($_GET['page'] ?? 0);
    $extra = [];
    if ($q !== '') { $extra['q'] = $q; }
    if ($page > 1) { $extra['page'] = $page; }
    $avecExtras = fn (string $href): string => $extra ? $href . (str_contains($href, '?') ? '&' : '?') . http_build_query($extra) : $href;
    // Recherche unifiée : traité à part des cibles statiques ci-dessous parce
    // que son libellé rappelle le terme cherché (« Recherche « hector » »),
    // pour que le lien retour dise où il ramène. Le terme lui-même revient par
    // $avecExtras, qui reporte déjà ?q= — sans quoi on retomberait sur une page
    // de recherche vide, ce qui obligerait à ressaisir la requête.
    if ($depuis === 'recherche') {
        return lien_retour(
            $avecExtras('?p=recherche'),
            $q !== '' ? 'Recherche « ' . $q . ' »' : 'Recherche'
        );
    }
    // Cibles sans id propre (page/liste, pas un objet précis) — le filtrage
    // actif (compte/année/catégorie…) est repris automatiquement au retour
    // via filtre_persistant() (session), pas besoin de l'encoder dans l'URL.
    $statiques = [
        'dashboard'        => ['?p=tableau_bord', 'Tableau de bord'],
        'compta_ecritures' => ['?p=compta_ecritures', 'Écritures'],
        'structures'       => ['?p=structures', 'Structures'],
    ];
    if (isset($statiques[$depuis])) {
        return lien_retour($avecExtras($statiques[$depuis][0]), $statiques[$depuis][1]);
    }
    // Une provenance qui nomme un GROUPE de navigation (booking, facturation,
    // événements, recherche de fonds) ne désigne pas une page : elle dit dans
    // quel module on se trouve. Les écrans partagés par plusieurs modules la
    // posent sur leurs liens — la liste des structures, celle des projets —
    // pour que le rail y reste sur le bon groupe (nav_groupe_actif()).
    //
    // Le retour est donc la liste de CETTE page, dans ce groupe-là : le groupe
    // ne dit pas OÙ revenir, seulement sous quel module. Une table qui aurait
    // renvoyé chaque groupe vers une page fixe aurait ramené la fiche d'un
    // projet à la liste des structures, elle aussi partagée par ces groupes.
    if ($depuis !== '' && isset(nav_groupes()[$depuis])) {
        return lien_retour($avecExtras(url_avec_retour($defautHref, $depuis)), $defautLabel);
    }
    if (preg_match('/^(facture|evenement|fiche|employe|structure|campagne|fonds_demande|personne):(\d+)$/', $depuis, $m)) {
        $id = (int) $m[2];
        if ($m[1] === 'structure') {
            $stmt = db()->prepare('SELECT nom FROM structures WHERE id = ?');
            $stmt->execute([$id]);
            $nom = $stmt->fetchColumn();
            if ($nom !== false) {
                return lien_retour('?p=structure&id=' . $id, (string) $nom);
            }
        } elseif ($m[1] === 'employe') {
            $stmt = db()->prepare('SELECT pe.prenom, pe.nom' . EMPLOYE_FROM . ' WHERE e.id = ?');
            $stmt->execute([$id]);
            $emp = $stmt->fetch();
            if ($emp) {
                return lien_retour('?p=employe&id=' . $id, $emp['prenom'] . ' ' . $emp['nom']);
            }
        } elseif ($m[1] === 'facture') {
            $stmt = db()->prepare('SELECT numero FROM factures WHERE id = ?');
            $stmt->execute([$id]);
            $numero = $stmt->fetchColumn();
            if ($numero !== false) {
                return lien_retour('?p=facture&id=' . $id, $numero !== '' ? 'Facture ' . $numero : 'Facture (brouillon)');
            }
        } elseif ($m[1] === 'evenement') {
            $stmt = db()->prepare('SELECT e.*, s.nom AS projet_nom FROM evenements e
                                    LEFT JOIN projets s ON s.id = e.projet_id WHERE e.id = ?');
            $stmt->execute([$id]);
            $ev = $stmt->fetch();
            if ($ev) {
                return lien_retour('?p=evenement&id=' . $id, evenement_label_court($ev));
            }
        } elseif ($m[1] === 'personne') {
            // Arrivé sur une pièce depuis la fiche de celui qu'elle désigne —
            // la quittance remise, la note de frais à rembourser —, on y
            // retourne.
            $pe = personne_charger($id);
            if ($pe) {
                return lien_retour('?p=personne&id=' . $id, personne_nom($pe));
            }
        } elseif ($m[1] === 'campagne') {
            // Arrivé sur une structure depuis le suivi d'une campagne, on y
            // retourne — pas sur la liste des structures, où l'on n'était pas.
            $c = campagne_charger($id);
            if ($c) {
                return lien_retour('?p=booking_campagne&id=' . $id, (string) $c['nom']);
            }
        } elseif ($m[1] === 'fonds_demande') {
            // Arrivé sur une facture depuis le dossier qu'elle règle, on y
            // retourne : le dossier porte le nom du bailleur, qui dit de quelle
            // subvention il s'agit.
            $dossier = fonds_demande_charger($id);
            if ($dossier) {
                return lien_retour('?p=fonds_demande&id=' . $id, (string) $dossier['structure_nom']);
            }
        } elseif ($m[1] === 'fiche') {
            $stmt = db()->prepare('SELECT mois, annee, employe_nom FROM fiches WHERE id = ?');
            $stmt->execute([$id]);
            $f = $stmt->fetch();
            if ($f) {
                return lien_retour('?p=fiche&id=' . $id, 'Fiche ' . mois_nom((int) $f['mois']) . ' ' . $f['annee'] . ' — ' . $f['employe_nom']);
            }
        }
    }
    return lien_retour($avecExtras($defautHref), $defautLabel);
}

// Mémorise l'état « avant » de lignes modifiées en masse (voir bulk_undo_appliquer()),
// pour permettre une annulation en un clic (lien « Annuler » affiché 10 s + raccourci
// Ctrl-Z/Cmd+Z). Portée volontairement limitée aux modifications de colonnes (UPDATE) —
// les suppressions en masse ne sont pas couvertes (état bien plus lourd à restaurer
// fidèlement : lignes filles, contraintes, etc.). Pour un remplacement de lignes filles
// (ex. ventilations), voir bulk_undo_memoriser_ventilations().
function bulk_undo_memoriser(string $table, array $ids, array $colonnes, string $route, array $retour = []): void
{
    if (!$ids) {
        return;
    }
    $in   = sql_in($ids);
    $cols = implode(',', array_map(fn(string $c) => "\"$c\"", $colonnes));
    $stmt = db()->prepare("SELECT id, $cols FROM \"$table\" WHERE id IN ($in)");
    $stmt->execute($ids);
    $_SESSION['bulk_undo'] = [
        'table' => $table, 'colonnes' => $colonnes, 'rows' => $stmt->fetchAll(),
        'route' => $route, 'retour' => $retour, 'expire' => time() + 300,
    ];
}

// Variante de bulk_undo_memoriser() pour l'affectation d'axe analytique en masse : cette
// action remplace les ventilations existantes (DELETE puis INSERT) au lieu de modifier des
// colonnes, donc rien à restaurer via un simple UPDATE — on mémorise les lignes
// ecriture_id/axe_id/montant à la place (éventuellement aucune, si les écritures n'avaient
// pas encore de ventilation).
function bulk_undo_memoriser_ventilations(array $ecritureIds, string $route, array $retour = []): void
{
    if (!$ecritureIds) {
        return;
    }
    $in   = sql_in($ecritureIds);
    $stmt = db()->prepare("SELECT ecriture_id, axe_id, montant FROM ecritures_ventilations WHERE ecriture_id IN ($in)");
    $stmt->execute($ecritureIds);
    $_SESSION['bulk_undo'] = [
        'kind' => 'ventilations', 'ecriture_ids' => $ecritureIds, 'rows' => $stmt->fetchAll(),
        'route' => $route, 'retour' => $retour, 'expire' => time() + 300,
    ];
}

// Restaure l'état mémorisé par bulk_undo_memoriser()/bulk_undo_memoriser_ventilations(),
// si présent et pas expiré. Renvoie [route, retour] pour la redirection vers la page
// d'origine, ou null si rien à annuler (déjà utilisé, expiré, ou aucune action en attente).
function bulk_undo_appliquer(): ?array
{
    $u = $_SESSION['bulk_undo'] ?? null;
    unset($_SESSION['bulk_undo']);
    if (!$u || $u['expire'] < time()) {
        return null;
    }
    if (($u['kind'] ?? null) === 'ventilations') {
        $ids = $u['ecriture_ids'];
        if (!$ids) {
            return null;
        }
        $in = sql_in($ids);
        db()->prepare("DELETE FROM ecritures_ventilations WHERE ecriture_id IN ($in)")->execute($ids);
        $ins = db()->prepare('INSERT INTO ecritures_ventilations (ecriture_id, axe_id, montant) VALUES (?, ?, ?)');
        foreach ($u['rows'] as $row) {
            $ins->execute([$row['ecriture_id'], $row['axe_id'], $row['montant']]);
        }
        return ['route' => $u['route'], 'retour' => $u['retour']];
    }
    if (!$u['rows']) {
        return null;
    }
    $sets = implode(',', array_map(fn(string $c) => "\"$c\" = ?", $u['colonnes']));
    $stmt = db()->prepare("UPDATE \"{$u['table']}\" SET $sets WHERE id = ?");
    foreach ($u['rows'] as $row) {
        $vals = array_map(fn(string $c) => $row[$c], $u['colonnes']);
        $vals[] = $row['id'];
        $stmt->execute($vals);
    }
    return ['route' => $u['route'], 'retour' => $u['retour']];
}

// Génère le HTML autonome d'une fiche pour un envoi par e-mail (CSS embarqué).
function fiche_email_html(array $f): string
{
    $scheme   = is_https() ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $logoRel  = param_logo('clair');
    // URL absolue (les clients mail n'affichent pas les chemins relatifs) ; '' si aucun logo.
    $logo_src = $logoRel !== '' ? $scheme . '://' . $host . '/' . ltrim($logoRel, '/') : '';
    $impression = true; // pas de lien sur le nom dans l'e-mail
    $css = @file_get_contents(__DIR__ . '/../assets/app.css') ?: '';

    ob_start();
    require __DIR__ . '/../views/_fiche_body.php';
    $corps = ob_get_clean();

    return '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<style>' . $css . ' body{background:#fff;margin:0;padding:18px}</style></head>'
        . '<body>' . $corps . '</body></html>';
}

// --- Préférences d'affichage propres à un compte (migration_74) -------------
// Le dernier choix fait dans un widget, par exemple. Survit à la déconnexion,
// contrairement aux filtres de liste qui vivent en session (filtre_persistant()).
// Sans utilisateur connecté (tâche planifiée, route à jeton), lire rend le
// défaut et écrire ne fait rien : ces routes n'ont personne à qui associer un
// choix.
function preference(string $cle, string $defaut = ''): string
{
    $u = current_user();
    if (!$u) {
        return $defaut;
    }
    $stmt = db()->prepare('SELECT valeur FROM utilisateur_preferences WHERE utilisateur_id = ? AND cle = ?');
    $stmt->execute([(int) $u['id'], $cle]);
    $v = $stmt->fetchColumn();
    return $v === false ? $defaut : (string) $v;
}

function preference_definir(string $cle, string $valeur): void
{
    $u = current_user();
    if (!$u) {
        return;
    }
    db()->prepare("INSERT OR REPLACE INTO utilisateur_preferences (utilisateur_id, cle, valeur, maj_le)
                   VALUES (?, ?, ?, datetime('now'))")
        ->execute([(int) $u['id'], $cle, $valeur]);
}

// --- Disposition des cartes du tableau de bord ------------------------------
//
// L'ordre des cartes et celles qu'on ne veut pas voir : un choix d'AFFICHAGE,
// propre à chaque compte (preference(), ci-dessus), pas un paramètre de
// l'association. Deux personnes qui partagent l'installation ne suivent pas le
// même travail.
//
// Stocké en une seule chaîne, l'ordre des identifiants séparés par des
// virgules, un « - » devant celles qui sont masquées :
//     campagnes,-suisa,evenements
// Lisible d'un coup d'œil dans la base, et ne demande pas de table.
//
// Les identifiants sont ceux que la vue donne à ses cartes, et elle seule :
// dashboard_ordre() reçoit la liste de celles qui ont RÉELLEMENT quelque chose
// à montrer (module actif, droits, données) et ne fait que la réordonner. Une
// carte ajoutée ou retirée là-bas n'a donc rien à déclarer ici.
const DASHBOARD_PREFERENCE = 'dashboard_cartes';

// Les cartes disponibles, dans l'ordre choisi par ce compte, les masquées
// exclues. $dispo est l'ordre par défaut (celui de la vue).
//
// Une carte inconnue de la préférence — parce qu'elle vient d'apparaître, ou
// qu'un module vient d'être activé — passe à la fin, visible : mieux vaut la
// voir arriver au bas du tableau de bord que de la faire disparaître.
function dashboard_ordre(array $dispo): array
{
    [$ordre, $cachees] = dashboard_disposition($dispo);
    return array_values(array_filter($ordre, fn (string $id) => !in_array($id, $cachees, true)));
}

// [ordre complet (masquées comprises), identifiants masqués] — ce qu'il faut au
// panneau de réglages, qui montre aussi ce qui est caché pour pouvoir le
// remontrer.
function dashboard_disposition(array $dispo): array
{
    $ordre = [];
    $cachees = [];
    foreach (explode(',', preference(DASHBOARD_PREFERENCE)) as $jeton) {
        $jeton = trim($jeton);
        $cachee = str_starts_with($jeton, '-');
        $id = $cachee ? substr($jeton, 1) : $jeton;
        // Une carte enregistrée mais plus disponible (module éteint, droit
        // retiré) est ignorée sans être oubliée : la préférence n'est pas
        // réécrite, elle reprendra sa place si le module revient.
        if ($id === '' || !in_array($id, $dispo, true) || in_array($id, $ordre, true)) {
            continue;
        }
        $ordre[] = $id;
        if ($cachee) {
            $cachees[] = $id;
        }
    }
    foreach ($dispo as $id) {
        if (!in_array($id, $ordre, true)) {
            $ordre[] = $id;
        }
    }
    return [$ordre, $cachees];
}

// Enregistre la disposition. $ordre porte TOUTES les cartes connues, $cachees
// celles à masquer.
function dashboard_disposition_definir(array $ordre, array $cachees): void
{
    preference_definir(DASHBOARD_PREFERENCE, implode(',', array_map(
        fn (string $id) => (in_array($id, $cachees, true) ? '-' : '') . $id,
        $ordre
    )));
}

// Adresse de réponse d'un envoi applicatif : celle configurée dans
// Paramètres → E-mails (« Adresse de réponse »), à défaut l'expéditeur. Le
// paramètre existait, s'annonçait comme un reply-to à l'écran… et n'était posé
// nulle part : les réponses revenaient toujours à la boîte d'expédition.
function email_repondre_a(string $expediteur): string
{
    $contact = trim((string) param('employeur_email_contact'));
    return filter_var($contact, FILTER_VALIDATE_EMAIL) ? $contact : $expediteur;
}

// Envoie une fiche par e-mail. En local, journalise au lieu d'envoyer.
// Retourne [bool succès, string mode ('local'|'mail')].
function envoyer_fiche_email(array $f, string $destinataire, string $expediteur): array
{
    $sujet = 'Fiche de salaire — ' . mois_nom((int) $f['mois']) . ' ' . $f['annee'];
    $html  = fiche_email_html($f);
    $entetes = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $expediteur,
        'Reply-To: ' . email_repondre_a($expediteur),
    ]);
    $sujetEnc = '=?UTF-8?B?' . base64_encode($sujet) . '?=';
    return envoyer_email($destinataire, $expediteur, $sujetEnc, $entetes, $html, $sujet);
}

// Transport commun à tout envoi d'e-mail applicatif (fiches, factures…) : en
// dev, journalisé dans data/emails_envoyes.log au lieu d'être envoyé ; en
// prod, SMTP authentifié si configuré, sinon repli sur mail(). $entetesMime =
// en-têtes hors To/Subject (From/Reply-To/Content-Type…) ; $corps = corps déjà
// encodé (HTML simple ou MIME multipart avec pièce jointe) ; $resumeLog =
// ligne de résumé journalisée en dev (pas le corps complet).
// Retourne [bool succès, string mode ('local'|'smtp'|'mail')].
function envoyer_email(string $destinataire, string $expediteur, string $sujetEnc, string $entetesMime, string $corps, string $resumeLog): array
{
    if (APP_ENV === 'dev') {
        $log = dirname(APP_DB_PATH) . '/emails_envoyes.log';
        @file_put_contents($log, '[' . date('c') . "] To: $destinataire | De: $expediteur | $resumeLog\n", FILE_APPEND);
        return [true, 'local'];
    }

    // En prod : SMTP authentifié si configuré (beaucoup d'hébergeurs désactivent mail()),
    // sinon repli sur mail() pour les hébergeurs qui l'autorisent.
    $cfg = smtp_config();
    if ($cfg['user'] !== '') {
        $message = 'To: ' . $destinataire . "\r\n" . 'Subject: ' . $sujetEnc . "\r\n" . $entetesMime . "\r\n\r\n" . $corps;
        return [smtp_transmettre($cfg, $destinataire, $message), 'smtp'];
    }
    if (!function_exists('mail')) {
        error_log('[app] mail() indisponible et SMTP non configuré (écran Employeur ou config.local.php).');
        return [false, 'mail'];
    }
    return [(bool) @mail($destinataire, $sujetEnc, $corps, $entetesMime), 'mail'];
}

// Réglages SMTP effectifs : priorité à la base (écran Employeur), repli sur les
// constantes de lib/config.local.php pour chaque champ laissé vide.
function smtp_config(): array
{
    $val = function (string $cle, string $defaut): string {
        $v = (string) param($cle, '');
        return $v !== '' ? $v : $defaut;
    };
    return [
        'host'   => $val('smtp_host', SMTP_HOST),
        'port'   => (int) $val('smtp_port', (string) SMTP_PORT),
        'secure' => $val('smtp_secure', SMTP_SECURE) === 'tls' ? 'tls' : 'ssl',
        'user'   => $val('smtp_user', SMTP_USER),
        'pass'   => $val('smtp_pass', SMTP_PASS),
    ];
}

// Profil SMTP d'un envoi de mailing. Trois niveaux, du plus précis au plus
// général, chaque champ vide passant la main au suivant :
//   1. la boîte de l'expéditeur choisi (table mailing_expediteurs) — deux
//      expéditeurs peuvent vivre chez deux hébergeurs différents ;
//   2. le profil booking hérité (paramètres smtp_booking_*), pour les
//      installations qui n'avaient qu'une boîte de mailing ;
//   3. le profil général (smtp_config()), celui des salaires et des factures.
// Voir docs/SPEC_BOOKING.md §7.
function smtp_config_booking(?array $expediteur = null): array
{
    $general = smtp_config();
    $val = function (string $cleExpediteur, string $cleParam, string $defaut) use ($expediteur): string {
        $v = trim((string) ($expediteur[$cleExpediteur] ?? ''));
        if ($v !== '') {
            return $v;
        }
        $v = (string) param($cleParam, '');
        return $v !== '' ? $v : $defaut;
    };
    return [
        'host'   => $val('smtp_host', 'smtp_booking_host', $general['host']),
        'port'   => (int) $val('smtp_port', 'smtp_booking_port', (string) $general['port']),
        'secure' => $val('smtp_secure', 'smtp_booking_secure', $general['secure']) === 'tls' ? 'tls' : 'ssl',
        'user'   => $val('smtp_user', 'smtp_booking_user', $general['user']),
        'pass'   => $val('smtp_pass', 'smtp_booking_pass', $general['pass']),
    ];
}

// Envoi d'un mailing booking : contrairement à envoyer_email(), **jamais** de
// repli sur mail() — le débit d'envoi (délai/plafond configurés) ne peut être
// maîtrisé que via SMTP, cf. docs/SPEC_BOOKING.md §7. En dev, journalisé comme les
// autres e-mails plutôt qu'expédié. Retourne [bool succès, string mode].
// $expediteur : ligne de mailing_expediteurs (la boîte choisie) ou null pour
// l'expéditeur général — c'est elle qui décide À LA FOIS de l'adresse affichée
// et du serveur par lequel l'e-mail part.
//
// $copieExpediteur : ajoute l'expéditeur en copie cachée, pour qu'il garde une
// trace du message dans sa boîte. Réservé aux messages écrits un par un
// (bouton « Contacter » d'une fiche structure) — SURTOUT PAS aux campagnes,
// qui en enverraient une copie par destinataire. Un envoi SMTP ne se range pas
// tout seul dans le dossier « Envoyés » : ce dossier est une notion IMAP, que
// seul un logiciel de messagerie alimente. La copie arrive donc dans la boîte
// de réception, pas dans les envoyés.
// $cc : copies VISIBLES — elles figurent dans l'en-tête, le destinataire les
// lit, et c'est tout l'intérêt (« je mets ton collègue en copie »).
// $cci : copies cachées — l'adresse est servie par l'enveloppe et le message ne
// la nomme pas, exactement comme la copie à l'expéditeur plus bas.
function envoyer_mailing_email(string $destinataire, ?array $expediteur, string $sujet, string $corps, bool $copieExpediteur = false, array $cc = [], array $cci = []): array
{
    $from = mailing_expediteur_from($expediteur);
    $adresseExpediteur = trim((string) ($expediteur['email'] ?? param('employeur_email_expediteur')));
    $copie = $copieExpediteur && $adresseExpediteur !== '' && strcasecmp($adresseExpediteur, $destinataire) !== 0;
    if (APP_ENV === 'dev') {
        $log = dirname(APP_DB_PATH) . '/emails_envoyes.log';
        @file_put_contents($log, '[' . date('c') . "] Mailing To: $destinataire | De: $from"
            . ($cc ? ' | Cc: ' . implode(', ', $cc) : '')
            . ($cci ? ' | Cci: ' . implode(', ', $cci) : '')
            . ($copie ? " | Cci: $adresseExpediteur" : '') . " | $sujet\n", FILE_APPEND);
        return [true, 'local'];
    }
    $cfg = smtp_config_booking($expediteur);
    if ($cfg['user'] === '') {
        error_log('[app] Mailing : SMTP non configuré (écran E-mails, profil booking ou général).');
        return [false, 'smtp'];
    }
    $sujetEnc = '=?UTF-8?B?' . base64_encode($sujet) . '?=';
    $entetes = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . $from,
        // Les réponses reviennent à la boîte qui a écrit, pas à l'adresse de
        // réponse générale : un mailing part au nom d'une équipe précise.
        'Reply-To: ' . $from,
    ]);
    $enteteCc = $cc ? 'Cc: ' . implode(', ', $cc) . "\r\n" : '';
    $message = 'To: ' . $destinataire . "\r\n" . $enteteCc . 'Subject: ' . $sujetEnc . "\r\n" . $entetes . "\r\n\r\n" . $corps;
    // Les copies CACHÉES passent par l'enveloppe seulement : aucun en-tête
    // « Bcc: » dans le message, sinon le destinataire les lirait. C'est déjà
    // ainsi que la copie à l'expéditeur voyage.
    $adresses = array_values(array_unique(array_merge(
        [$destinataire],
        $cc,
        $cci,
        $copie ? [$adresseExpediteur] : []
    )));
    return [smtp_transmettre($cfg, count($adresses) > 1 ? $adresses : $destinataire, $message), 'smtp'];
}

// Transmet un message brut déjà complet (en-têtes To/Subject/… + ligne vide + corps)
// par SMTP authentifié, en PHP pur (aucune dépendance). Gère SSL implicite
// (port 465) ou STARTTLS (port 587), AUTH LOGIN. Appelé par envoyer_email(),
// commun à tous les e-mails applicatifs (fiches simples, factures avec pièce jointe).
// $to : une adresse, ou plusieurs (destinataires d'ENVELOPPE — un RCPT TO
// chacun). Une adresse ajoutée ici et absente des en-têtes est exactement ce
// qu'est une copie cachée : le serveur la sert, le message ne la nomme pas.
function smtp_transmettre(array $cfg, string|array $to, string $message): bool
{
    $echec = function (string $msg): bool {
        error_log('[app] SMTP : ' . $msg);
        return false;
    };

    $transport = $cfg['secure'] === 'ssl' ? 'ssl://' : 'tcp://';
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $fp = @stream_socket_client(
        $transport . $cfg['host'] . ':' . $cfg['port'],
        $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx
    );
    if (!$fp) {
        return $echec("connexion impossible à {$cfg['host']}:{$cfg['port']} ($errno $errstr)");
    }
    stream_set_timeout($fp, 20);

    // Lit une réponse SMTP (gère les réponses multi-lignes « 250-… »).
    $lire = function () use ($fp): string {
        $data = '';
        while (($ligne = fgets($fp, 515)) !== false) {
            $data .= $ligne;
            if (strlen($ligne) >= 4 && $ligne[3] === ' ') break; // dernière ligne
        }
        return $data;
    };
    // Envoie une commande et vérifie le code de réponse attendu.
    $cmd = function (string $envoi, string $codeAttendu) use ($fp, $lire, $echec) {
        if ($envoi !== '') {
            fwrite($fp, $envoi . "\r\n");
        }
        $rep = $lire();
        if (substr($rep, 0, 3) !== $codeAttendu) {
            return $echec("réponse inattendue (attendu $codeAttendu) : " . trim($rep));
        }
        return true; // succès
    };

    $hello = ($_SERVER['SERVER_NAME'] ?? null) ?: 'localhost';

    if ($cmd('', '220') !== true) return false;                       // bannière
    if ($cmd('EHLO ' . $hello, '250') !== true) return false;

    if ($cfg['secure'] === 'tls') {
        if ($cmd('STARTTLS', '220') !== true) return false;
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            return $echec('échec du passage en TLS (STARTTLS)');
        }
        if ($cmd('EHLO ' . $hello, '250') !== true) return false;     // re-EHLO après TLS
    }

    if ($cmd('AUTH LOGIN', '334') !== true) return false;
    if ($cmd(base64_encode($cfg['user']), '334') !== true) return false;
    if ($cmd(base64_encode($cfg['pass']), '235') !== true) return false; // 235 = authentifié

    // Enveloppe : MAIL FROM = compte authentifié (souvent exigé par l'hébergeur / SPF).
    if ($cmd('MAIL FROM:<' . $cfg['user'] . '>', '250') !== true) return false;
    foreach ((array) $to as $adresse) {
        if ($cmd('RCPT TO:<' . $adresse . '>', '250') !== true) return false;
    }
    if ($cmd('DATA', '354') !== true) return false;

    // Point-stuffing : une ligne « . » seule terminerait prématurément les données.
    $message = preg_replace('/^\./m', '..', $message);
    fwrite($fp, $message . "\r\n.\r\n");
    if ($cmd('', '250') !== true) return false;                       // message accepté

    fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return true;
}

// Badge de statut de paiement d'une fiche (payé le … / à payer).
function badge_paiement(array $f): string
{
    $date = trim((string) ($f['date_paiement'] ?? ''));
    if ($date !== '') {
        return '<span class="badge ok-badge">' . e(date('d.m.Y', strtotime($date))) . '</span>';
    }
    $annee = (int) ($f['annee'] ?? 0);
    $mois  = (int) ($f['mois'] ?? 0);
    $cy = (int) date('Y');
    $cm = (int) date('n');
    if ($annee > $cy || ($annee === $cy && $mois > $cm)) {
        return '<span class="badge muted-badge">À venir</span>';
    }
    return '<span class="badge warn-badge">À payer</span>';
}

function fiche_a_venir(array $f): bool
{
    if (trim((string) ($f['date_paiement'] ?? '')) !== '') return false;
    $cy = (int) date('Y');
    $cm = (int) date('n');
    $annee = (int) ($f['annee'] ?? 0);
    $mois  = (int) ($f['mois'] ?? 0);
    return $annee > $cy || ($annee === $cy && $mois > $cm);
}

// Coût employeur d'une fiche pour les listes : « — » si aucune charge patronale figée
// (typiquement les anciennes fiches importées avant le calcul des charges).
function cout_emp_affiche(array $f): string
{
    return ((float) ($f['total_charges_emp'] ?? 0)) > 0
        ? chf((float) ($f['cout_total_emp'] ?? 0))
        : '—';
}

// Lignes de prestation d'une fiche. Repli pour les fiches d'avant les unités.
function fiche_lignes_de(array $f): array
{
    $stmt = db()->prepare(
        'SELECT fl.*, a.code AS axe_code, a.libelle AS axe_libelle,
                e.date AS evenement_date, s.nom AS evenement_projet_nom
         FROM fiche_lignes fl
         LEFT JOIN axes_analytiques a ON a.id = fl.axe_analytique_id
         LEFT JOIN evenements e ON e.id = fl.evenement_id
         LEFT JOIN projets s ON s.id = e.projet_id
         WHERE fl.fiche_id = ? ORDER BY fl.ordre, fl.id'
    );
    $stmt->execute([$f['id']]);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        $rows = [[
            // Pas de ligne fiche_lignes réelle (fiche sans lignes personnalisées) :
            // id=0, une valeur qu'aucune vraie ligne ne prend jamais (voir
            // route_fiche_ligne_axe_enregistrer(), qui exige un id existant en base) —
            // affiche/désactive silencieusement l'édition d'axe pour cette ligne
            // implicite plutôt que déclencher un "Undefined array key" (_fiche_body.php).
            'id'            => 0,
            'libelle'       => 'Heures',
            'heures_unite'  => 1.0,
            'quantite'      => (float) $f['nombre_heures'],
            'taux_horaire'  => (float) $f['salaire_horaire'],
            'axe_analytique_id' => null,
            'axe_code'      => null,
            'axe_libelle'   => null,
            'evenement_id'  => null,
            'evenement_date' => null,
            'evenement_projet_nom' => null,
        ]];
    }
    return $rows;
}

// Champ de recherche standard de l'application : la saisie plus une loupe
// CLIQUABLE. Un seul rendu pour les dix champs du site, afin que le geste soit
// le même partout.
//
// Deux natures de champ coexistent, d'où $submit :
//   - true  : le champ est dans un <form> (listes filtrées côté serveur, page
//             de recherche) — la loupe est un vrai bouton submit ;
//   - false : le champ filtre en direct en JS (employés, projets, écritures
//             d'un axe) — il n'y a rien à soumettre, la loupe se contente de
//             rendre le focus au champ (voir assets/app.js).
//
// Conteneur <span> et non <label> : un <label> ne doit pas contenir d'élément
// interactif autre que son propre champ, et son comportement d'activation
// entrerait en conflit avec le clic sur le bouton. L'accessibilité est portée
// par l'aria-label du champ, déjà présent partout.
function champ_recherche(array $opts = []): string
{
    $classe      = trim('search-label ' . ($opts['classe'] ?? ''));
    $submit      = (bool) ($opts['submit'] ?? false);
    $placeholder = $opts['placeholder'] ?? 'Rechercher...';
    $aria        = $opts['aria'] ?? 'Rechercher';

    $attrs = 'type="search" autocomplete="off"';
    foreach (['id', 'name'] as $a) {
        if (($opts[$a] ?? '') !== '') {
            $attrs .= ' ' . $a . '="' . e((string) $opts[$a]) . '"';
        }
    }
    if (isset($opts['valeur'])) {
        $attrs .= ' value="' . e((string) $opts['valeur']) . '"';
    }
    if (!empty($opts['autofocus'])) {
        $attrs .= ' autofocus';
    }

    return '<span class="' . e($classe) . '">'
        . '<input ' . $attrs . ' placeholder="' . e($placeholder) . '" aria-label="' . e($aria) . '">'
        . '<button type="' . ($submit ? 'submit' : 'button') . '" class="search-go" aria-label="' . e($aria) . '">'
        . icon('search')
        . '</button>'
        . '</span>';
}

// Icône « i » avec infobulle : survol/focus sur ordinateur, tap sur mobile
// (voir le script dans views/layout.php). Un seul endroit pour changer
// l'icône/le comportement partout où une infobulle est utilisée sur le site.
function info_tip(string $texte): string
{
    return '<span class="info-tip" tabindex="0" role="button" aria-label="Plus d\'informations">'
        . icon('info')
        . '<span class="info-tip-bulle" role="tooltip">' . e($texte) . '</span></span>';
}

// BASCULE D'AFFICHAGE d'une liste : liste / deux colonnes / carte. Même dessin
// que icon_picker() — .seg-picker, .seg-btn — mais des LIENS et non des radios :
// changer de vue change d'adresse, et doit donc s'ouvrir dans un onglet, se
// mettre en favori, revenir avec le bouton Précédent.
//
// Six écrans l'affichaient, chacun avec sa copie des mêmes sept lignes de
// balisage ; seul le mot « la fiche choisie » changeait d'un commentaire à
// l'autre. Ajouter une vue, ou corriger un aria-label, voulait dire six
// fichiers.
//
// $qs : les paramètres à reconduire (filtres, recherche, tri) — SANS « p »,
// « vue » ni « sel », que cette fonction pose elle-même.
//
// ⚠️ La vue « duo » porte .seg-btn-duo : le CSS efface ce bouton sous 1100px,
// où deux colonnes ne tiennent plus. Un lien effacé reste atteignable au
// clavier, et c'est voulu — la vue fonctionne, elle est seulement à l'étroit.
const VUES_LISTE = [
    'liste' => ['icone' => 'rows-3',    'titre' => 'Liste',          'aria' => 'Affichage en liste'],
    'duo'   => ['icone' => 'columns-2', 'titre' => 'Liste et fiche', 'aria' => 'Affichage en deux colonnes', 'classe' => 'seg-btn-duo'],
    'carte' => ['icone' => 'map',       'titre' => 'Carte',          'aria' => 'Affichage sur une carte'],
];

function bascule_vue_html(string $route, string $vueActive, array $qs, array $vues = ['liste', 'duo']): string
{
    $h = '<div class="seg-picker' . (count($vues) < 3 ? ' seg-picker-duo' : '') . '" role="radiogroup" aria-label="Affichage">';
    foreach ($vues as $vue) {
        $def = VUES_LISTE[$vue];
        $on  = $vueActive === $vue;
        $h .= '<a href="' . e('?p=' . $route . '&' . http_build_query($qs + ['vue' => $vue])) . '"'
            . ' class="seg-btn' . (isset($def['classe']) ? ' ' . $def['classe'] : '') . ($on ? ' on' : '') . '"'
            . ' role="radio" aria-checked="' . ($on ? 'true' : 'false') . '"'
            . ' title="' . e($def['titre']) . '" aria-label="' . e($def['aria']) . '">'
            . icon($def['icone']) . '</a>';
    }
    return $h . '</div>';
}

// Sélecteur segmenté à icônes (ex. type de structure, visibilité d'un
// événement) — remplace un <select> par des boutons-icônes connectés dont un
// seul est actif, exactement le format des droits par module de ?p=utilisateurs
// (.perm-toggle), mais horizontal. Radios cachés : la valeur est soumise
// naturellement dans le formulaire, sans JS. $options : [valeur => ['icone' =>
// nom pour icon(), 'label' => texte accessible/tooltip]], dans l'ordre voulu.
//
// $opts['avecLabel'] écrit le libellé À CÔTÉ de l'icône au lieu de le réserver
// à l'infobulle. À poser dès que les choix ne se devinent pas d'un dessin :
// clair / sombre / automatique se lisent au premier coup d'œil, « facture
// reçue » et « quittance » non — deux feuilles de papier se ressemblent trop.
// Hors de ce cas, l'icône seule reste préférable : elle tient dans une barre
// d'outils.
function icon_picker(string $name, array $options, string $selected, string $ariaLabel = '', array $opts = []): string
{
    $avecLabel = !empty($opts['avecLabel']);
    $h = '<div class="seg-picker' . ($avecLabel ? ' seg-picker-label' : '') . '" role="radiogroup"'
        . ($ariaLabel !== '' ? ' aria-label="' . e($ariaLabel) . '"' : '') . '>';
    foreach ($options as $valeur => $opt) {
        $checked = $selected === $valeur ? ' checked' : '';
        // 'contenu' remplace l'icône quand le choix ne se dessine pas : les
        // couleurs d'un décor se montrent par deux pastilles (?p=apparence).
        // Un second composant à côté de celui-ci aurait fallu tenir d'accord
        // sur la taille des boutons, l'état coché et le focus — c'est
        // exactement ce que docs/UI.md interdit d'ouvrir.
        $contenu = (string) ($opt['contenu'] ?? icon((string) $opt['icone']));
        $classe  = 'seg-btn' . (($opt['classe'] ?? '') !== '' ? ' ' . e((string) $opt['classe']) : '');
        $h .= '<label class="' . $classe . '" title="' . e($opt['label']) . '">'
            . '<input type="radio" name="' . e($name) . '" value="' . e($valeur) . '" aria-label="' . e($opt['label']) . '"' . $checked . '>'
            . $contenu
            . ($avecLabel ? '<span class="seg-btn-txt">' . e($opt['label']) . '</span>' : '')
            . '</label>';
    }
    return $h . '</div>';
}

// Temps écoulé depuis $date, en toutes lettres — « 3 mois », « 4 ans »,
// « ce mois-ci ». '' si la date est vide ou illisible.
//
// Sans « il y a » devant : l'en-tête de colonne dit déjà de quoi il s'agit, et
// deux mots de moins par cellule sur 2959 lignes, ça compte. La date exacte
// reste accessible au survol, posée en title par l'appelant.
//
// Pourquoi une durée plutôt qu'une date : la question qu'on se pose devant ces
// colonnes n'est pas « quand » mais « depuis combien de temps » — « 21.11.2022 »
// demandait un calcul mental à chaque ligne.
function duree_depuis(string $date): string
{
    if ($date === '') {
        return '';
    }
    $ts = strtotime($date);
    if ($ts === false) {
        return '';
    }
    // 30,44 = durée moyenne d'un mois sur une année, pour que « 12 mois » et
    // « 1 an » tombent au même endroit plutôt qu'à trois jours près.
    $mois = max(0.0, (time() - $ts) / (30.44 * 86400));
    if ($mois < 1) {
        return 'ce mois-ci';
    }
    if ($mois < 12) {
        return round($mois) . ' mois';
    }
    $ans = (int) round($mois / 12);
    return $ans . ' an' . ($ans > 1 ? 's' : '');
}

// Retire l'indentation du gabarit ENTRE les cellules d'un tableau. Sur
// ?p=structures elle pesait 751 Ko de HTML (254 octets par ligne, 2959 lignes)
// pour rien : le navigateur ne rend aucune de ces espaces.
//
// Volontairement limité aux frontières où le HTML ne rend jamais rien :
//   - entre deux cellules, et autour de <tr> (les blancs y sont ignorés) ;
//   - en tête et en queue de cellule (les blancs de bord d'un bloc sont rognés).
// À l'INTÉRIEUR d'une cellule, en revanche, l'espace n'est pas supprimée mais
// RÉDUITE À UNE : là, elle sépare bel et bien deux étiquettes ou une icône et
// un nom, et la supprimer les collerait — mais le navigateur fond de toute
// façon toute suite d'espaces en une seule, donc n'en garder qu'une ne change
// rien à l'affichage (218 Ko de plus, l'indentation du gabarit entre deux
// éléments d'une même cellule). Cette dernière règle suppose que le contenu
// n'est pas en white-space: pre — vrai pour les tableaux de listes, à vérifier
// avant d'employer la fonction ailleurs.
function compacter_cellules(string $html): string
{
    // Trois passes, pas une par cas : le tampon fait plusieurs mégaoctets et
    // chaque motif le relit en entier. « t[dhr]\b » ne vise que td/th/tr — la
    // limite de mot écarte thead, tbody, title, track.
    $html = (string) preg_replace(
        [
            '~(</?t[dhr]\b[^>]*>)\s+~', // après l'ouverture ou la fermeture d'une cellule ou d'une ligne
            '~\s+(</t[dhr]>)~',          // juste avant leur fermeture
        ],
        '$1',
        $html
    );
    // Dans la cellule : une seule espace là où le gabarit en laissait vingt.
    return (string) preg_replace('~>\s{2,}<~', '> <', $html);
}

// Statut d'une structure — sélecteur segmenté horizontal (même style que
// icon_picker(), mais cliqué en AJAX au lieu de soumis avec le reste du
// formulaire : voir route_structure_statut() + lassoInitStatutToggle(),
// assets/app.js). 4 états,
// mêmes valeurs que structures.statut (STRUCTURE_STATUTS, lib/booking.php,
// migration_63 — remplace actif+desinscrit) : « Contact privilégié »
// (prioritaire), « Actif », « Ne pas contacter » (désinscrite du mailing),
// « Inactif ».
function structure_statut_toggle_html(int $id, string $statut): string
{
    $h = '<div class="seg-picker statut-toggle" role="radiogroup" aria-label="Statut" data-statut-id="' . $id . '">';
    foreach (STRUCTURE_STATUTS as $val) {
        $on = $statut === $val;
        $label = structure_statut_libelle($val);
        $h .= '<button type="button" class="seg-btn' . ($on ? ' on' : '') . '" data-statut-valeur="' . e($val) . '"'
            . ' role="radio" aria-checked="' . ($on ? 'true' : 'false') . '" title="' . e($label) . '" aria-label="' . e($label) . '">'
            . icon(structure_statut_icone($val)) . '</button>';
    }
    return $h . '</div>';
}

// La puce d'une ligne d'arbre (plan comptable, pays, catégories, projets) :
// un chevron si la ligne a des enfants, sinon un point. Quatre vues l'écrivaient
// à l'identique.
//
// Le point porte une classe à lui : il disparaît là où la POIGNÉE de
// glisser-déposer paraît, pour ne pas poser deux marques au début de la même
// ligne. Le chevron reste, lui — il ne décore pas, il dit que la ligne en
// contient d'autres.
function plan_puce_html(bool $aEnfants): string
{
    return $aEnfants
        ? '<span class="plan-puce" aria-hidden="true">' . icon('chevron-down') . '</span>'
        : '<span class="plan-puce plan-puce-feuille" aria-hidden="true">•</span>';
}

// La POIGNÉE de glisser-déposer d'une ligne ordonnable, et les DEUX boutons
// qui ouvrent et referment son édition : le trio que porte toute ligne de
// liste réordonnable (docs/UI.md § 2d et § 4). Neuf vues écrivaient la
// poignée à l'identique, huit les deux boutons — au titre près à chaque fois,
// et c'est précisément là que la dérive s'était installée : le même crayon
// disait « Modifier » sur trois écrans et « Renommer » sur trois autres.
//
// Le crayon n'ouvre PAS un champ de nom : il ouvre le mode édition de la
// ligne, qui découvre aussi la corbeille et tout ce qui porte .cell-edition.
// Son mot est donc « Modifier », partout.
//
// $quoi nomme la ligne pour les lecteurs d'écran — « cet axe », « cette
// règle ». Vide, l'intitulé reste « Modifier » tout court : dans une liste
// d'une seule espèce, la colonne le dit déjà.
function plan_poignee_html(string $titre = 'Glisser pour ranger ailleurs'): string
{
    return '<span class="plan-grip" draggable="true" title="' . e($titre) . '" aria-hidden="true">' . icon('grip') . '</span>';
}

function plan_boutons_edition_html(string $quoi = ''): string
{
    $label = 'Modifier' . ($quoi !== '' ? ' ' . $quoi : '');
    return '<button type="button" class="btn ghost btn-sm icon-only plan-edit-btn" title="Modifier" aria-label="' . e($label) . '">' . icon('pencil') . '</button>'
        . '<button type="button" class="btn ghost btn-sm icon-only plan-annuler-btn cell-edition" title="Annuler" aria-label="Annuler">' . icon('x') . '</button>';
}

// Le bouton « Fermer » de la barre d'une fenêtre. Six fenêtres l'écrivaient à
// l'identique, à l'identifiant près ; une seule l'écrit maintenant.
//
// Son intitulé vit dans un <span class="btn-txt"> : sur un téléphone, la croix
// seule suffit — c'est le geste le plus connu de toute interface —, et la place
// rendue revient aux commandes qui, elles, ont besoin de leurs mots. C'est la
// même convention que .btn-compact-mobile ailleurs dans la barre (docs/UI.md
// § 7). $id : facultatif, pour le script qui ferme la fenêtre. $classe : la
// classe d'accroche, « modal-fermer » pour une fenêtre ordinaire — l'aperçu de
// document pose la sienne, parce que son bouton est injecté dans la barre de la
// page affichée, qui vit dans une iframe (views/layout.php). La FORME du
// bouton, elle, reste décidée ici dans les deux cas.
function bouton_fermer_modal_html(string $id = '', string $classe = 'modal-fermer'): string
{
    return '<button type="button" class="btn ghost ' . e($classe) . ' btn-compact-mobile"'
        . ($id !== '' ? ' id="' . e($id) . '"' : '')
        . ' title="Fermer" aria-label="Fermer">'
        . icon('x') . ' <span class="btn-txt">Fermer</span></button>';
}

// Les commandes d'une carte (ou d'une ligne) modifiable, dans l'ordre et sous
// l'aspect que l'application leur donne partout : le crayon quand on lit ;
// enregistrer, puis ce que l'écran ajoute, puis fermer, quand on modifie. Le
// script générique de assets/app.js échange les uns contre les autres.
//
// UN SEUL endroit décide de cet ordre, de ces icônes et de leur format :
// changer ici change toute l'application, sans repasser par les écrans. C'est
// la raison d'être de cette fonction — le balisage tenait en six lignes, mais
// il était recopié sur chaque carte.
//
// $opts :
//   'form'    id du <form> que vise « Enregistrer » — obligatoire quand le
//             bouton vit hors du formulaire (le cas dès qu'il est en en-tête)
//   'petit'   boutons au petit format : une ligne de liste, pas une carte
//   'overlay' groupe posé en superposition, pour une carte sans ligne d'en-tête
//   'quoi'    ce que « Modifier » désigne, quand la carte seule ne suffit pas
//             (« Modifier cette entrée »)
//   'extra'   HTML glissé entre « Enregistrer » et « Fermer » — une corbeille,
//             un interrupteur… Il porte lui-même .card-edition pour n'exister
//             qu'en édition.
//   'classe'  classes supplémentaires sur le conteneur
function carte_actions_html(array $opts = []): string
{
    $sm      = !empty($opts['petit']) ? ' btn-sm' : '';
    $form    = isset($opts['form']) ? ' form="' . e((string) $opts['form']) . '"' : '';
    $quoi    = trim((string) ($opts['quoi'] ?? ''));
    $modifie = $quoi !== '' ? 'Modifier ' . $quoi : 'Modifier';
    $classes = 'head-actions'
        . (!empty($opts['overlay']) ? ' card-actions-overlay' : '')
        . (!empty($opts['classe']) ? ' ' . e((string) $opts['classe']) : '');

    return '<div class="' . $classes . '">'
        . '<button type="button" class="btn ghost icon-only' . $sm . ' card-edit-btn"'
        . ' title="' . e($modifie) . '" aria-label="' . e($modifie) . '">' . icon('pencil') . '</button>'
        . '<button type="submit"' . $form . ' class="btn icon-only' . $sm . ' card-save-btn" hidden'
        . ' title="Enregistrer" aria-label="Enregistrer">' . icon('save') . '</button>'
        . (string) ($opts['extra'] ?? '')
        . '<button type="button" class="btn ghost icon-only' . $sm . ' card-cancel-btn" hidden'
        . ' title="Annuler" aria-label="Annuler">' . icon('x') . '</button>'
        . '</div>';
}

// Un MENU DÉROULANT : un bouton qui déplie une courte liste de gestes voisins.
// Bâti sur <details> natif — rien à ouvrir en JavaScript —, refermé au clic
// dehors par l'écouteur global d'assets/app.js (LASSO_MENUS) et repositionné en
// position:fixed à l'ouverture, pour qu'un menu posé dans une ligne de tableau
// ne soit pas rogné par le débordement du conteneur.
//
// Rendu ici plutôt que recopié : trois écrans l'emploient — le « + » du déroulé
// d'une date, « Charger un modèle » de la fenêtre Contacter, « Synchroniser »
// sur la liste des projets. Un quatrième s'y branche, il ne redessine pas le
// sien à côté (docs/UI.md § 6).
//
// $bouton  ['libelle', 'icone', 'titre', 'classe'] — un libellé vide donne un
//          bouton à l'icône seule ; 'classe' complète « btn ghost ».
//          'nu' => true pour un déclencheur qui n'est PAS un bouton (une
//          icône de ligne de tableau, révélée au survol) : seules les classes
//          données restent, sans « btn ghost » qui s'y opposerait.
//          'plein' => true pour un bouton de mise en évidence plutôt que
//          fantôme : un menu qui PROPOSE (le « + » du tableau de bord) n'a pas
//          le même poids qu'un menu qui règle un détail de la page.
// $entrees chacune : ['libelle', 'icone', 'href' (lien) ou absent (bouton),
//          'titre', 'classe', 'attrs' => [attribut => valeur]].
// $opts    'classe' et 'id' posés sur le <details>, 'gauche' => true pour que le
//          panneau s'aligne sur le bord GAUCHE du bouton (par défaut à droite,
//          le bouton étant d'ordinaire en bout de ligne).
function menu_deroulant_html(array $bouton, array $entrees, array $opts = []): string
{
    if (!$entrees) {
        return '';
    }
    $attrs = function (array $a): string {
        $h = '';
        foreach ($a as $nom => $valeur) {
            $h .= ' ' . e((string) $nom) . '="' . e((string) $valeur) . '"';
        }
        return $h;
    };
    $libelle = trim((string) ($bouton['libelle'] ?? ''));
    // Un saut de ligne DANS le libellé est une coupure voulue : elle ne paraît
    // que sur écran étroit (.btn-compact-break, assets/app.css), là où le
    // libellé se réduit et se range sur deux lignes. Ailleurs le <br> n'existe
    // pas — ce n'est pas un repli sous contrainte, c'est un choix de mise en
    // page pour un bouton précis. L'infobulle, elle, reste d'un tenant.
    // ⚠️ L'espace vient APRÈS le <br>, comme sur le bouton « Écriture manuelle »
    // de ?p=compta_ecritures : hors écran étroit le saut de ligne n'existe plus
    // du tout (display:none), et sans cet espace les deux bouts se recollent en
    // « Charger unmodèle ». Sur deux lignes il ouvre la seconde, où il se
    // réduit à rien.
    $libelleHtml = implode(
        '<br class="btn-compact-break"> ',
        array_map(fn (string $bout): string => e(trim($bout)), explode("\n", $libelle))
    );
    $titre   = trim((string) ($bouton['titre'] ?? str_replace("\n", ' ', $libelle)));
    $petit   = !empty($bouton['petit']);

    $h = '<details class="menu-deroulant' . (!empty($opts['gauche']) ? ' menu-deroulant-gauche' : '')
       . (!empty($opts['classe']) ? ' ' . e((string) $opts['classe']) : '') . '"'
       . (!empty($opts['id']) ? ' id="' . e((string) $opts['id']) . '"' : '') . '>';
    // 'nu' : un déclencheur qui n'est pas un bouton. Dans une ligne de tableau,
    // le menu se porte sur un .row-edit-btn — nu, discret, révélé au survol de
    // sa cellule —, et les classes de .btn s'y battaient contre les siennes.
    $classes = !empty($bouton['nu'])
        ? ''
        : 'btn' . (empty($bouton['plein']) ? ' ghost' : '') . ($petit ? ' btn-sm' : '') . ($libelle === '' ? ' icon-only' : '');
    $h .= '<summary class="' . $classes
        . (!empty($bouton['classe']) ? ($classes !== '' ? ' ' : '') . e((string) $bouton['classe']) : '') . '"'
        . ' title="' . e($titre) . '" aria-label="' . e($titre) . '">'
        . icon((string) ($bouton['icone'] ?? ''))
        . ($libelle !== '' ? ' <span class="lbl">' . $libelleHtml . '</span>' : '')
        . '</summary>';
    $h .= '<div class="menu-deroulant-panneau">';
    foreach ($entrees as $entree) {
        $contenu = icon((string) ($entree['icone'] ?? '')) . '<span>' . e((string) ($entree['libelle'] ?? '')) . '</span>';
        $classe  = trim((string) ($entree['classe'] ?? ''));
        $commun  = ($classe !== '' ? ' class="' . e($classe) . '"' : '')
                 . (trim((string) ($entree['titre'] ?? '')) !== '' ? ' title="' . e((string) $entree['titre']) . '"' : '')
                 . $attrs((array) ($entree['attrs'] ?? []));
        $h .= isset($entree['href'])
            ? '<a href="' . e((string) $entree['href']) . '"' . $commun . '>' . $contenu . '</a>'
            : '<button type="button"' . $commun . '>' . $contenu . '</button>';
    }
    return $h . '</div></details>';
}

// Les commandes d'une PAGE qui est un formulaire (créer un employé, une
// facture…) : il n'y a rien à lire, donc pas de crayon — « Enregistrer » et
// « Annuler » vivent dans l'en-tête de page, là où les cartes mettent le leur.
// Même raison d'être que carte_actions_html() : un seul endroit décide de
// l'ordre et de l'aspect, pour les sept écrans concernés.
//
// Même ordre que sur une carte : enregistrer d'abord, ce qui referme ensuite.
//
// $form   id du <form> visé — le bouton vit hors de lui, dans l'en-tête
// $retour l'adresse d'« Annuler » ; vide = pas d'« Annuler » (l'écran a déjà
//         son lien de retour et n'en proposait pas)
// $opts   'libelle' (défaut « Enregistrer »), 'avant' (HTML posé devant — la
//         suppression, quand l'écran la propose), 'icone' (défaut 'save',
//         chaîne vide = aucune icône)
function entete_form_actions_html(string $form, string $retour = '', array $opts = []): string
{
    $icone = (string) ($opts['icone'] ?? 'save');
    return '<div class="head-actions">'
        . (string) ($opts['avant'] ?? '')
        . '<button type="submit" form="' . e($form) . '" class="btn">'
        . ($icone !== '' ? icon($icone) . ' ' : '')
        . '<span class="lbl">' . e((string) ($opts['libelle'] ?? 'Enregistrer')) . '</span></button>'
        . ($retour !== '' ? '<a class="btn ghost" href="' . e($retour) . '">Annuler</a>' : '')
        . '</div>';
}

// Réponse reçue d'une structure dans une campagne — même sélecteur segmenté que
// le statut d'une structure (structure_statut_toggle_html() ci-dessus), et même
// enregistrement à la volée : un clic écrit, sans formulaire ni rechargement
// (route_booking_campagne_reponse_enregistrer() + lassoInitReponseToggle(), assets/app.js).
function campagne_reponse_toggle_html(int $campagneId, int $structureId, string $reponse): string
{
    $h = '<div class="seg-picker reponse-toggle" role="radiogroup" aria-label="Réponse reçue"'
       . ' data-campagne-id="' . $campagneId . '" data-structure-id="' . $structureId . '">';
    foreach (CAMPAGNE_REPONSES as $val => $label) {
        $on = $reponse === $val;
        $h .= '<button type="button" class="seg-btn' . ($on ? ' on' : '') . '" data-reponse-valeur="' . e((string) $val) . '"'
            . ' role="radio" aria-checked="' . ($on ? 'true' : 'false') . '" title="' . e($label) . '" aria-label="' . e($label) . '">'
            . icon(CAMPAGNE_REPONSES_ICONES[$val]) . '</button>';
    }
    return $h . '</div>';
}

// Attribut style="…" d'un badge d'étiquette à la couleur choisie par
// l'utilisateur (?p=tags, structure_tags.couleur) — fond teinté à
// faible opacité (même esprit que --highlight-tint/-d) + texte à la couleur
// pleine. '' (attribut vide) si pas de couleur : le badge garde son style par
// défaut (.badge, assets/app.css).
function badge_style_html(string $couleur): string
{
    if (!preg_match('/^#[0-9a-f]{6}$/i', $couleur)) {
        return '';
    }
    return ' style="background:' . e($couleur) . '1a;color:' . e($couleur) . ';"';
}

// Affichage combiné « Ville 🇫🇷 (canton/département) » — factorisé entre les
// listes structures et événements (ville en gras, drapeau du pays, canton/
// département entre parenthèses en muted — jamais la grande région, voir
// migration_56). $drapeau : émoji déjà résolu par l'appelant (pays_drapeau()
// si le pays est stocké en code ISO2, pays_drapeau_nom() s'il est stocké en
// nom) ; $paysBrut : texte affiché en repli si aucun drapeau n'a pu être
// résolu (ex. valeur non reconnue). Chaîne vide si rien à afficher —
// à l'appelant de décider son propre repli (« — », combiné à d'autres champs).
function ville_departement_canton_html(string $ville, string $drapeau, string $paysBrut, string $departementCanton): string
{
    $h = '';
    if ($ville !== '') {
        $h .= '<strong>' . e($ville) . '</strong>';
    }
    if ($drapeau !== '') {
        $h .= ' <span class="tiny">' . $drapeau . '</span>';
    } elseif ($paysBrut !== '') {
        $h .= ' <span class="tiny muted">' . e($paysBrut) . '</span>';
    }
    if ($departementCanton !== '') {
        $h .= ' <span class="tiny muted">' . e($departementCanton) . '</span>';
    }
    return $h;
}

// Bandeau + formulaire de géocodage par lots de la vue carte (lieux ET
// structures — factorisé pour ne pas dupliquer entre views/_lieux_carte.php
// et views/_structures_carte.php) : compte des villes non localisées, lien
// « Voir la liste » vers le filtre non_localises=1 de la vue liste, formulaire
// de géocodage (avec les filtres actifs repostés en champs cachés) et message
// de fin quand tout est localisé. $nomCompte : accord pour « N ... dont la
// ville... » (ex. « lieu(x) », « structure(s) ») ; $nomPluriel/$accordAffiches :
// accord pour « toutes les villes des ... » (ex. « lieux »/« affichés »,
// « structures »/« affichées »). $hiddenParams : [nom => valeur] des filtres
// actifs à reposter tels quels avec le formulaire. $geocodeDemande : true si
// un lot vient d'être traité (?geocode=N dans l'URL), pour enchaîner
// automatiquement sur le lot suivant tant qu'il en reste (voir le script en
// bas de fonction) ou afficher le message de fin.
function carte_banner_geocodage_html(
    int $carteVillesManquantes,
    string $nomCompte,
    string $nomPluriel,
    string $accordAffiches,
    string $lienListe,
    string $formAction,
    array $hiddenParams,
    bool $geocodeDemande
): string {
    if ($carteVillesManquantes > 0) {
        $h = '<div class="carte-banner">';
        $h .= '<p>' . $carteVillesManquantes . ' ' . e($nomCompte) . " dont la ville n'est pas encore localisée sur la carte.";
        if ($lienListe !== '') {
            $h .= ' <a href="' . e($lienListe) . '">Voir la liste</a>';
        }
        $h .= '</p>';
        $h .= '<form method="post" action="' . e($formAction) . '" id="geocoder-form">';
        $h .= '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
        $h .= hidden_inputs_html($hiddenParams);
        $h .= '<button type="submit" id="geocoder-btn">' . icon('map-pin') . ' Géocoder (par lots, ≈1 seconde par ville)</button>';
        $h .= '</form>';
        $h .= '<p class="muted small" id="geocoder-auto-msg" hidden>Géocodage en cours (service Nominatim/OpenStreetMap, 1 ville par seconde)… vous pouvez quitter la page à tout moment, il reprendra où il s\'est arrêté au prochain clic.</p>';
        $h .= '</div>';
        if ($geocodeDemande) {
            $h .= '<script nonce="' . e(csp_nonce()) . '">(function () {'
                . 'var msg = document.getElementById("geocoder-auto-msg"); if (msg) { msg.hidden = false; }'
                . 'setTimeout(function () { var f = document.getElementById("geocoder-form"); if (f) { f.requestSubmit(); } }, 400);'
                . '})();</script>';
        }
        return $h;
    }
    if ($geocodeDemande) {
        return '<p class="carte-banner ok flash">Géocodage terminé — toutes les villes des ' . e($nomPluriel) . ' ' . e($accordAffiches) . ' sont désormais localisées.</p>';
    }
    return '';
}

// Bandeau « Filtre : non localisés » de la vue liste (lieux et structures) —
// affiché quand on arrive depuis le lien « Voir la liste » du bandeau carte
// ci-dessus, avec un lien pour quitter le filtre sans perdre les autres
// filtres actifs.
function filtre_non_localises_flash_html(bool $actif, string $nomPluriel, string $lienQuitter): string
{
    if (!$actif) {
        return '';
    }
    return '<p class="warn flash">Filtre : ' . e($nomPluriel) . " dont la ville n'a pas pu être localisée sur la carte. "
        . '<a href="' . e($lienQuitter) . '">Quitter ce filtre</a></p>';
}

// Affichage catégorie/sous-catégorie sur deux lignes (catégorie en petit et
// muted au-dessus, sous-catégorie en dessous) — même style que le rappel de
// catégorie de compta_ecritures.php (.row-field-txt/.row-field-prefix).
function categorie_sous_categorie_html(string $categorie, string $sousCategorie): string
{
    $prefixe = $sousCategorie !== '' ? '<span class="row-field-prefix">' . e($categorie) . '</span>' : '';
    $feuille = $sousCategorie !== '' ? $sousCategorie : $categorie;
    return '<span class="row-field-txt">' . $prefixe . '<span>' . e($feuille) . '</span></span>';
}

// Nombre de fiches non payées (date_paiement vide) du mois courant ou avant.
function nb_fiches_a_payer(): int
{
    try {
        $m = (int) date('m');
        $y = (int) date('Y');
        $s = db()->prepare("SELECT COUNT(*) FROM fiches WHERE date_paiement = '' AND (annee < ? OR (annee = ? AND mois <= ?))");
        $s->execute([$y, $y, $m]);
        return (int) $s->fetchColumn();
    } catch (\Exception) {
        return 0;
    }
}

// Nombre d'écritures comptables non lettrées.
function nb_ecritures_a_lettrer(): int
{
    try {
        return (int) db()->query("SELECT COUNT(*) FROM ecritures WHERE plan_compte_id IS NULL AND origine_lettrage <> 'ignore'")->fetchColumn();
    } catch (\Exception) {
        return 0;
    }
}

// La page est tamponnée pour que le sprite d'icônes puisse être inséré après
// coup, juste sous <body> : on ne sait quelles icônes ont servi qu'une fois le
// contenu rendu, et le <symbol> doit précéder les <use> qui le référencent.
function render(string $view, array $data = [], ?string $title = null): void
{
    extract($data);
    $contentView = __DIR__ . '/../views/' . $view . '.php';
    $pageTitle   = $title ?? 'Fiches de salaire';
    icones_mode_sprite(true);
    ob_start();
    require __DIR__ . '/../views/layout.php';
    echo injecter_sprite((string) ob_get_clean());
}

// Rendu d'un FRAGMENT de page (une ligne de liste), renvoyé à un ajout parti en
// arrière-plan pour que le script l'insère sans recharger (voir docs/UI.md § 5).
//
// Le mode sprite est coupé pendant ce rendu : dans une page, icon() émet un
// <use> qui pointe vers le sprite injecté une fois au début du <body>. Une
// ligne arrivant après coup ne peut pas s'y fier — la première adresse d'une
// feuille de route apporte une icône « map-pin » que la page n'a jamais rendue,
// donc absente du sprite, et le <use> ne montrerait rien. Ici les icônes sont
// donc écrites en clair.
function rendre_fragment(string $vue, array $donnees = []): string
{
    $spriteAvant = icones_mode_sprite();
    icones_mode_sprite(false);
    extract($donnees);
    ob_start();
    require __DIR__ . '/../views/' . $vue . '.php';
    $html = (string) ob_get_clean();
    icones_mode_sprite($spriteAvant);
    return trim($html);
}

// Réponse à un ajout parti en arrière-plan : la ligne rendue, ou le message qui
// dit pourquoi ça n'a pas marché. Rend true quand elle a répondu — l'appelant
// s'arrête là au lieu de rediriger. Convention `retour=json` partagée avec les
// cellules de ?p=structures et les réglages de ligne (data-ajax).
function reponse_ajout_json(?string $erreur = null, string $html = ''): bool
{
    if (($_POST['retour'] ?? '') !== 'json') {
        return false;
    }
    header('Content-Type: application/json');
    echo json_encode($erreur !== null ? ['ok' => false, 'erreur' => $erreur] : ['ok' => true, 'html' => $html]);
    return true;
}

// Rendu d'une vue "nue" (sans layout), pour l'impression. Même tampon : ces
// vues produisent aussi un document complet.
function render_bare(string $view, array $data = []): void
{
    extract($data);
    icones_mode_sprite(true);
    ob_start();
    require __DIR__ . '/../views/' . $view . '.php';
    echo injecter_sprite((string) ob_get_clean());
}
