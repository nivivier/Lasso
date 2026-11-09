<?php /** @var string $pageTitle, $contentView */ $u = current_user(); $cur = $_GET['p'] ?? '';
$nomEmployeur = param('employeur_nom') ?: 'Fiches de salaire';
// Les initiales de l'employeur : la pastille du rail réduit quand aucun logo
// n'est configuré. Deux lettres au plus, prises aux deux premiers mots.
$motsEmployeur      = preg_split('/\s+/u', trim($nomEmployeur), -1, PREG_SPLIT_NO_EMPTY) ?: [];
$initialesEmployeur = mb_strtoupper(implode('', array_map(
    fn (string $m): string => mb_substr($m, 0, 1, 'UTF-8'),
    array_slice($motsEmployeur, 0, 2)
)), 'UTF-8');
$logoClair = param_logo('clair'); $logoSombre = param_logo('sombre');
// Fond de l'application : un décor calculé (views/_fond_decor.php) ou l'image
// personnalisée, au choix (?p=apparence, FONDS_DECOR). L'image ne concerne que
// les pages connectées — elle est posée en CSS sur body.has-sidebar::before par
// couleurs_css_vars() — et les pages hors session gardent donc un décor, le
// défaut si c'est l'image qui est choisie.
$fondDecor        = param_fond_decor();
$fondPersonnalise = $fondDecor === 'image';
$fondDecorAuth    = $fondPersonnalise ? 'maillage' : $fondDecor;
// Calculés avant <head> : le rail en a besoin, et $navActif sert aussi au
// titre de la barre supérieure sur téléphone.
$navGroupes = $u ? nav_groupes() : [];
$navActif   = $u ? nav_groupe_actif($navGroupes, $cur, (string) ($_GET['depuis'] ?? '')) : null;
// Le nom du module, affiché dans la barre supérieure sur téléphone. C'est la
// même source que $ntLabel (_module_tabs.php) : nav_groupes() ne dépend que de
// ?p=, la barre peut donc le connaître avant que la vue ne s'exécute. Il
// commande aussi l'effacement du <h1> de la page, qui dirait la même chose
// deux fois — d'où la classe posée sur <body> : si la barre n'a PAS de titre
// (une page hors module), le <h1> reste, sinon l'écran n'aurait plus de nom.
$mbarTitre = $navActif !== null ? (string) ($navGroupes[$navActif][0] ?? '') : '';
// Les paramètres ne sont pas un module — pas de groupe dans nav_groupes() —,
// mais ils forment bien une section, avec son bandeau et ses onglets. La barre
// la nomme comme les autres : c'est parametres_groupes() qui dit si la route
// courante en fait partie (lib/modules.php, la même liste que les onglets).
if ($u && $mbarTitre === '' && parametres_groupe_actif(parametres_groupes(), $cur) !== null) {
    $mbarTitre = 'Paramètres';
}
?>
<!DOCTYPE html>
<?php // data-theme n'est posé que pour un choix EXPLICITE : en mode « auto »
      // l'attribut reste absent, et c'est la media query prefers-color-scheme
      // qui décide. Rendu côté serveur, donc aucun scintillement au chargement
      // et aucun JavaScript — c'est l'avantage d'un réglage stocké en base. ?>
<html lang="fr"<?= param_theme() !== 'auto' ? ' data-theme="' . e(param_theme()) . '"' : '' ?><?= param_densite() !== 'normal' ? ' data-densite="' . e(param_densite()) . '"' : '' ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> — <?= e($nomEmployeur) ?></title>
    <?php // Inter est servie depuis le dépôt (assets/fonts/, voir le @font-face
          // en tête de app.css) : plus aucune requête vers fonts.googleapis.com.
          // Préchargée car elle est découverte tardivement (référencée depuis la
          // feuille de style, donc après son téléchargement et son analyse). ?>
    <?php // URL sans « ?v= », volontairement : elle doit correspondre au
          // caractère près à celle du @font-face, sinon le navigateur
          // télécharge le fichier deux fois et le préchargement est perdu. La
          // police est immuable — si elle change un jour, changer son nom. ?>
    <link rel="preload" href="assets/fonts/inter-latin-var.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="assets/app.css?v=<?= @filemtime(__DIR__ . '/../assets/app.css') ?: '1' ?>">
    <script src="assets/app.js?v=<?= @filemtime(__DIR__ . '/../assets/app.js') ?: '1' ?>"></script>
    <?php // Favicone : les versions réduites du logo employeur si elles ont été
          // fournies, sinon les logos normaux (logo_petit_variante()). L'onglet
          // est dessiné par le navigateur, qui suit le thème du SYSTÈME et non
          // celui réglé dans l'application — d'où la media query plutôt que
          // param_theme().
          //
          // ⚠️ L'ORDRE compte, et le fond sombre passe donc EN PREMIER. Le
          // support de l'attribut media sur un rel="icon" est très inégal : un
          // navigateur qui l'ignore retient le DERNIER lien, quel que soit le
          // thème. Le dernier doit donc être la variante pour fond clair, celle
          // d'une barre d'onglets par défaut — sans quoi un système en
          // apparence claire hérite du logo pensé pour un fond sombre (constaté
          // en préparant la 2.8.8, qui pose cette favicone). Un navigateur qui comprend media, lui, choisit
          // correctement dans les deux sens : les deux liens s'excluent. ?>
    <?php $favClair = logo_petit_variante('clair'); $favSombre = logo_petit_variante('sombre'); ?>
    <?php if ($favSombre !== null && $favSombre !== $favClair): ?>
    <link rel="icon" href="<?= e(param_logo($favSombre)) ?>" media="(prefers-color-scheme: dark)">
    <?php endif; ?>
    <?php if ($favClair !== null): ?>
    <link rel="icon" href="<?= e(param_logo($favClair)) ?>"<?= $favSombre !== null && $favSombre !== $favClair ? ' media="(prefers-color-scheme: light)"' : '' ?>>
    <?php endif; ?>
    <?= couleurs_css_vars() ?>
    <?php // Plus de surcharge de --primary/--brand par le module : la couleur
          // d'un module ne vit QUE dans le rail, où elle est posée vignette par
          // vignette (--vignette, plus bas). Repeindre l'accent de toute la page
          // à chaque module consommait la teinte qui doit dire « c'est ici qu'on
          // agit », et faisait changer de couleur un bouton qui fait pourtant
          // toujours la même chose (docs/UI.md § 13). ?>
</head>
<?php // La classe du décor porte le fond de <body> qui va avec lui : chaque
      // décor a le sien (assets/app.css, section « Décors de fond »). ?>
<?php // fondc-<combo> : les deux teintes du décor (assets/app.css). Posée même
      // sur les pages hors session — un décor y paraît aussi, et il doit avoir
      // les mêmes couleurs qu'une fois connecté. ?>
<body class="<?= $u ? 'has-sidebar' : 'auth-bg' ?> fond-<?= e($u ? $fondDecor : $fondDecorAuth) ?> fondc-<?= e(param_fond_couleurs()) ?><?= $mbarTitre !== '' ? ' titre-dans-barre' : '' ?>">
<?php if ($u): ?>
<?php if (!$fondPersonnalise) { require __DIR__ . '/_fond_decor.php'; } ?>
<?php // Burger AVANT le logo : la navigation est à gauche sur bureau (le rail),
      // elle l'est donc aussi sur téléphone — bouton, tiroir et bouton de
      // fermeture, tous du même côté. ?>
<header class="mobile-bar">
    <button type="button" class="burger" id="burger" title="Menu" aria-label="Menu" aria-expanded="false">
        <?= icon('menu') ?>
    </button>
    <?php // La barre porte le NOM DU MODULE à droite du logo : sans lui, le titre
          // de la page occupait une rangée entière à lui seul juste en dessous.
          // Le logo passe donc à sa version réduite (la pastille carrée du rail)
          // — c'est elle qui libère la largeur. logo_petit_variante() préfère la
          // mini et retombe sur le logo large si elle n'est pas configurée. ?>
    <?php $vMbar = logo_petit_variante('sombre'); ?>
    <?php // Le logo ramène au tableau de bord : c'est le geste attendu d'un
          // logo d'application, et sur téléphone le rail est replié. ?>
    <a href="?p=tableau_bord" class="mbar-accueil" title="Tableau de bord" aria-label="Tableau de bord">
        <?php if ($vMbar !== null): ?><img src="<?= e(param_logo($vMbar)) ?>" alt="<?= e($nomEmployeur) ?>" class="mbar-logo<?= str_starts_with($vMbar, 'mini_') ? ' mbar-logo-mini' : '' ?>"><?php else: ?><span class="mbar-name"><?= e($nomEmployeur) ?></span><?php endif; ?>
    </a>
    <?php // Le même libellé que le <h1> de la page : $navActif est déjà résolu
          // plus haut (il sert aux variables de couleur du module), et c'est la
          // même source que $ntLabel dans _module_tabs.php. ?>
    <?php if ($mbarTitre !== ''): ?><span class="mbar-titre"><?= e($mbarTitre) ?></span><?php endif; ?>
    <?php // L'action principale de la page vient se poser ici, à droite — voir
          // le script en fin de page. Créneau vide tant qu'il n'y en a pas. ?>
    <span class="mbar-action-slot" id="mbar-action-slot"></span>
</header>
<div class="scrim" id="scrim"></div>
<aside class="sidebar" id="sidebar">
    <?php // Replier le rail. Le bouton vit SUR le filet de droite, à hauteur du
          // logo : un seul emplacement pour fermer et pour rouvrir, au même
          // pixel, comme la croix qui referme une ligne en édition. Dans le
          // tiroir du téléphone il n'a rien à replier — le CSS l'y efface.
          //
          // L'état est posé sur <html> par assets/app.js, chargé dans <head> :
          // la classe existe donc avant le premier rendu, et le rail ne
          // s'affiche jamais large avant de se refermer. ?>
    <button type="button" class="side-reduire" id="side-reduire" aria-expanded="true" aria-controls="sidebar" title="Réduire le rail" aria-label="Réduire le rail">
        <span class="side-reduire-fermer"><?= icon('chevron-left') ?></span>
        <span class="side-reduire-ouvrir"><?= icon('chevron-right') ?></span>
    </button>
    <div class="side-brand">
        <div class="side-brand-txt">
            <?php
            // Le rail passe d'un fond clair à un fond sombre selon le thème : le
            // logo doit suivre. En mode « automatique » le serveur ignore le
            // réglage du système, on rend donc LES DEUX variantes et c'est le CSS
            // qui montre la bonne (.side-logo-clair / .side-logo-sombre) — sans
            // JavaScript ni scintillement.
            //
            // DEUX JEUX, et le CSS montre celui du moment : le logo large
            // tant que le rail est déplié — il a la place d'une signature —,
            // sa version réduite une fois replié, où 52px de large n'en
            // laisseraient lire qu'un trait. Les deux sont rendus parce que
            // l'état du rail est une classe posée sur <html> par le script :
            // le serveur ne le connaît pas, et un logo choisi en JavaScript
            // scintillerait à chaque page.
            //
            // ⚠️ Le jeu réduit n'est rendu QUE s'il apporte une autre image.
            // Les logos sont inlinés en data URI (une requête de moins à
            // chaque navigation, voir param_logo_src()) : chaque variante
            // rendue pèse dans la page, et sans version réduite configurée
            // les deux jeux seraient le même fichier deux fois.
            $vGrandClair  = logo_grand_variante('clair');
            $vGrandSombre = logo_grand_variante('sombre');
            $vMiniClair   = logo_petit_variante('clair');
            $vMiniSombre  = logo_petit_variante('sombre');
            $logoRailClair  = $vGrandClair  !== null ? param_logo_src($vGrandClair)  : '';
            $logoRailSombre = $vGrandSombre !== null ? param_logo_src($vGrandSombre) : $logoRailClair;
            $miniDistinct   = $vMiniClair !== $vGrandClair || $vMiniSombre !== $vGrandSombre;
            $logoMiniClair  = $miniDistinct && $vMiniClair  !== null ? param_logo_src($vMiniClair)  : '';
            $logoMiniSombre = $miniDistinct && $vMiniSombre !== null ? param_logo_src($vMiniSombre) : $logoMiniClair;
            ?>
            <?php // Le logo du rail est un lien vers le tableau de bord — le
                  // geste qu'on tente d'instinct sur le logo d'une application. ?>
            <a href="?p=tableau_bord" class="side-accueil" title="Tableau de bord" aria-label="Tableau de bord">
            <?php if ($logoRailClair !== ''): ?>
                <?php // Deux axes, deux mécaniques, et c'est voulu : le JEU
                      // (déplié / replié) se montre ou se cache sur son
                      // enveloppe, la VARIANTE (clair / sombre) sur l'image.
                      // Sur un seul niveau, les deux règles display se
                      // disputaient la même propriété. ?>
                <span class="side-logo-jeu side-logo-jeu-grand">
                    <img src="<?= e($logoRailClair) ?>" alt="<?= e($nomEmployeur) ?>" class="side-logo side-logo-clair">
                    <img src="<?= e($logoRailSombre) ?>" alt="<?= e($nomEmployeur) ?>" class="side-logo side-logo-sombre">
                </span>
                <?php if ($logoMiniClair !== ''): ?>
                <span class="side-logo-jeu side-logo-jeu-mini">
                    <img src="<?= e($logoMiniClair) ?>" alt="<?= e($nomEmployeur) ?>" class="side-logo side-logo-petit side-logo-clair">
                    <img src="<?= e($logoMiniSombre) ?>" alt="<?= e($nomEmployeur) ?>" class="side-logo side-logo-petit side-logo-sombre">
                </span>
                <?php endif; ?>
            <?php else: ?>
                <span class="side-name"><?= e($nomEmployeur) ?></span>
                <?php // Rail réduit, et pas de logo : le nom ne tient pas dans
                      // 68px, ses initiales si. Sans elles le haut du rail
                      // était vide — une installation sans logo n'a plus rien
                      // qui dise chez qui l'on est. ?>
                <span class="side-initiales" aria-hidden="true"><?= e($initialesEmployeur) ?></span>
            <?php endif; ?>
            </a>
            <span class="side-sub">Gestion des salaires</span>
        </div>
        <button type="button" class="side-close" id="side-close" title="Fermer" aria-label="Fermer"><?= icon('x') ?></button>
    </div>
    <?php // La recherche unifiée (?p=recherche) vit sous le logo, dans le rail :
          // elle traverse tous les modules, elle n'appartient donc à aucune
          // page. Elle était jusqu'ici réservée au tableau de bord, c'est-à-dire
          // atteignable seulement en rentrant à l'accueil.
          //
          // Les sources interrogées dépendent des droits du compte
          // (lib/recherche.php) : le champ s'affiche pour tout le monde, les
          // résultats sont filtrés. ?>
    <form class="side-recherche recherche-form" method="get" action="" data-min="<?= RECHERCHE_MIN ?>">
        <input type="hidden" name="p" value="recherche">
        <?= champ_recherche([
            'id'          => 'recherche-rail',
            'name'        => 'q',
            'classe'      => 'recherche-champ',
            'placeholder' => 'Chercher partout',
            'aria'        => "Rechercher dans toute l'application",
            'submit'      => true,
        ]) ?>
    </form>
    <?php
    // Le rail porte TOUTE la navigation : un volet par module, et le volet du
    // module où l'on se trouve déplie ses pages (et les sous-pages de la page
    // ouverte). La rangée d'onglets en tête de page
    // (views/_module_tabs_render.php) ne sert plus que sous 800px, où le rail
    // est un tiroir hors écran — c'est le CSS qui l'efface au-dessus
    // (docs/UI.md § Navigation).
    //
    // --vignette / --vignette-encre : le couple clair/sombre du module
    // (MODULE_COULEURS, lib/modules.php). Posé ici et non en CSS parce qu'il
    // vient du PHP ; absent quand « une couleur par module » est décoché — ou
    // quand le volet n'est pas celui d'un module —, et chaque règle de .nav-*
    // replie alors sur --primary et sur l'encre.
    $navVignette = static function (string $cle): string {
        $teinte = MODULE_COULEURS[$cle] ?? null;
        if (!param_couleurs_modules() || $teinte === null) {
            return '';
        }
        return ' style="--vignette: ' . e($teinte['fond']) . '; --vignette-encre: ' . e($teinte['encre']) . '"';
    };
    ?>
    <nav class="side-nav">
        <?php // Vignette d'encre, comme les Paramètres en bas du rail : le
              // tableau de bord n'est pas un module, il les regarde tous. Une
              // couleur à lui l'alignait sur les sept, et le rail n'avait plus
              // de haut ni de bas, juste huit teintes. ?>
        <div class="nav-volet nav-volet-encre<?= $cur === 'tableau_bord' ? ' on' : '' ?>">
            <a href="?p=tableau_bord" class="nav-mod" title="Tableau de bord">
                <span class="nav-vignette"><?= icon('circle-gauge') ?></span>
                <span class="nav-mod-lbl">Tableau de bord</span>
                <span class="nav-chevron" aria-hidden="true"></span>
            </a>
            <div class="nav-flyout"><span class="nav-flyout-nom">Tableau de bord</span></div>
        </div>
        <?php foreach ($navGroupes as $navCle => $navG): ?>
        <?php
        $navOuvert  = $navActif === $navCle;
        $navOnglets = $navG[2];
        $navBadge   = array_sum(array_column($navOnglets, 2));
        ?>
        <div class="nav-volet<?= $navOuvert ? ' on' : '' ?>"<?= $navVignette($navCle) ?>>
            <?php // &depuis= comme les onglets de module : ?p=structures
                  // appartient à trois groupes (booking, facturation,
                  // événements), et sans ce marqueur le rail y arrivait sans
                  // dire d'où, laissant nav_groupe_actif() deviner — et la
                  // liste afficher des colonnes qui ne concernent pas le
                  // module d'où l'on vient. ?>
            <a href="?p=<?= array_key_first($navOnglets) ?>&depuis=<?= e($navCle) ?>" class="nav-mod" title="<?= e($navG[0]) ?>">
                <span class="nav-vignette"><?= icon($navG[1]) ?></span>
                <span class="nav-mod-lbl"><?= e($navG[0]) ?></span>
                <?php if ($navBadge > 0): ?><span class="nav-badge"><?= $navBadge ?></span><?php endif; ?>
                <?php // Le chevron garde sa place même quand le module n'a rien à
                      // déplier : sinon le libellé d'un module sans sous-écrans
                      // court vingt pixels plus loin que ses voisins, et la
                      // colonne de droite du rail se met à onduler. ?>
                <span class="nav-chevron" aria-hidden="true"><?php if (count($navOnglets) > 1) { echo icon($navOuvert ? 'chevron-down' : 'chevron-right'); } ?></span>
            </a>
            <?php // LE PANNEAU DU MODULE, et le pli du rail réduit. En rail
                  // large, .nav-flyout est en display:contents : ses enfants se
                  // posent dans le volet exactement comme avant, et son nom
                  // reste caché. En rail réduit, le même nœud devient le
                  // panneau qui sort au survol de la vignette — un seul rendu,
                  // deux mises en page. Deux copies des mêmes liens, c'étaient
                  // deux listes à tenir d'accord.
                  //
                  // ⚠️ Les pages de TOUS les modules sont désormais rendues, et
                  // c'est le CSS qui n'affiche que celles du volet ouvert :
                  // sans elles dans le DOM, survoler un module fermé n'aurait
                  // rien à montrer.
                  //
                  // Un module d'un seul écran ne déplie rien : la ligne du
                  // module EST sa page, et une sous-ligne unique répéterait son
                  // nom. Même règle qu'au bandeau d'onglets, qui ne s'affichait
                  // qu'à partir de deux. ?>
            <div class="nav-flyout">
            <span class="nav-flyout-nom"><?= e($navG[0]) ?></span>
            <?php if (count($navOnglets) > 1): ?>
            <div class="nav-pages">
                <?php foreach ($navOnglets as $navRoute => $navO): ?>
                <?php
                $navPageOn = in_array($cur, $navO[1], true);
                // Une page qui porte des sous-pages reste dans la liste de
                // routes de SON onglet : sans cette distinction, l'onglet et la
                // sous-page affichée prenaient tous deux la capsule pleine, et
                // deux lignes se disaient actives en même temps. L'onglet n'est
                // alors que « déplié » : même teinte, posée en lavis.
                $navSousOn = $navPageOn && !empty($navO[4]) && isset($navO[4][$cur]);
                ?>
                <?php // ⚠️ Un onglet qui PORTE des sous-pages ne mène nulle
                      // part : son clic les déplie (assets/app.js). Son href
                      // reste écrit — c'est un lien, il doit s'ouvrir dans un
                      // onglet, se copier, marcher sans script — mais au clic
                      // il ne partait pas déplier, il SAUTAIT sur sa première
                      // sous-page : cliquer « Plan comptable » ouvrait
                      // « Produits et charges » sans qu'on ait rien demandé.
                      // Le chevron dit ce qui va se passer. ?>
                <a href="?p=<?= e((string) $navRoute) ?>&depuis=<?= e($navCle) ?>" class="nav-page<?= $navSousOn ? ' deplie' : ($navPageOn ? ' on' : '') ?>">
                    <span class="nav-page-lbl"><?= e($navO[0]) ?></span>
                    <?php if ((int) $navO[2] > 0): ?><span class="nav-page-badge"><?= (int) $navO[2] ?></span><?php endif; ?>
                    <?php if (!empty($navO[4])): ?><span class="nav-page-chevron" aria-hidden="true"><?= icon('chevron-right') ?></span><?php endif; ?>
                </a>
                <?php // Les sous-pages sont TOUJOURS rendues — le 5e élément de
                      // l'entrée dans nav_groupes(), [route => libellé]. C'est le
                      // CSS qui les replie tant que la page n'est ni ouverte ni
                      // dépliée : sans elles dans le DOM, le clic n'aurait rien
                      // à déplier. ?>
                <?php if (!empty($navO[4])): ?>
                <div class="nav-sous">
                    <?php foreach ($navO[4] as $navSRoute => $navSLib): ?>
                    <a href="?p=<?= e((string) $navSRoute) ?>&depuis=<?= e($navCle) ?>" class="nav-sous-page<?= $cur === (string) $navSRoute ? ' on' : '' ?>"><?= e($navSLib) ?></a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <?php
        // Les Paramètres ferment le rail, comme un module : même volet, mêmes
        // pages dépliées. Ils n'en sont pas un (aucun groupe dans
        // nav_groupes(), pas de couleur dans MODULE_COULEURS) — d'où la
        // vignette d'encre, qui les distingue des sept autres sans les mettre
        // à part. Le tableau de bord, en tête, la porte pour la même raison. Ils vivaient derrière la pastille du compte, où leur rangée
        // d'onglets était leur seule navigation ; depuis que le rail porte
        // tout, c'était le dernier écran à obéir à une autre grammaire.
        $ptG       = parametres_groupes();
        $ptActif   = parametres_groupe_actif($ptG, $cur);
        $ptOuvert  = $ptActif !== null;
        ?>
        <?php if ($ptG): ?>
        <div class="nav-volet nav-volet-encre<?= $ptOuvert ? ' on' : '' ?>">
            <a href="?p=<?= e((string) array_key_first($ptG[array_key_first($ptG)][1])) ?>" class="nav-mod" title="Paramètres">
                <span class="nav-vignette"><?= icon('settings') ?></span>
                <span class="nav-mod-lbl">Paramètres</span>
                <span class="nav-chevron" aria-hidden="true"><?= icon($ptOuvert ? 'chevron-down' : 'chevron-right') ?></span>
            </a>
            <div class="nav-flyout">
            <span class="nav-flyout-nom">Paramètres</span>
            <div class="nav-pages">
                <?php foreach ($ptG as $ptCle => $ptGroupe): ?>
                <?php
                $ptSections = $ptGroupe[1];
                $ptPageOn   = $ptCle === $ptActif;
                $ptSousOn   = $ptPageOn && count($ptSections) > 1;
                ?>
                <?php // Même règle que les onglets d'un module ci-dessus : un
                      // groupe à plusieurs sections se déplie au clic, il ne
                      // mène pas à sa première section. ?>
                <?php $ptPlusieurs = count($ptSections) > 1; ?>
                <a href="?p=<?= e((string) array_key_first($ptSections)) ?>" class="nav-page<?= $ptSousOn ? ' deplie' : ($ptPageOn ? ' on' : '') ?>">
                    <span class="nav-page-lbl"><?= e((string) $ptGroupe[0]) ?></span>
                    <?php if ($ptPlusieurs): ?><span class="nav-page-chevron" aria-hidden="true"><?= icon('chevron-right') ?></span><?php endif; ?>
                </a>
                <?php if ($ptPlusieurs): ?>
                <div class="nav-sous">
                    <?php foreach ($ptSections as $ptRoute => $ptLib): ?>
                    <?php $ptAlias = in_array($cur, (array) ($ptGroupe[2] ?? []), true) && $ptRoute === array_key_first($ptSections); ?>
                    <a href="?p=<?= e((string) $ptRoute) ?>" class="nav-sous-page<?= $cur === (string) $ptRoute || $ptAlias ? ' on' : '' ?>"><?= e((string) $ptLib) ?></a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>
            </div>
        </div>
        <?php endif; ?>
    </nav>
    <?php
    $prenom = trim((string)($u['prenom'] ?? ''));
    $nom    = trim((string)($u['nom'] ?? ''));
    if ($prenom !== '' && $nom !== '') {
        $initiales  = mb_strtoupper(mb_substr($prenom, 0, 1) . mb_substr($nom, 0, 1), 'UTF-8');
        $nomComplet = $prenom . ' ' . $nom;
    } elseif ($prenom !== '' || $nom !== '') {
        $n = $prenom !== '' ? $prenom : $nom;
        $initiales  = mb_strtoupper(mb_substr($n, 0, 2), 'UTF-8');
        $nomComplet = $n;
    } else {
        $initiales  = mb_strtoupper(mb_substr($u['email'], 0, 2), 'UTF-8');
        $nomComplet = $u['email'];
    }
    ?>
    <div class="side-avatar-wrap" id="side-avatar-wrap">
        <?php // Paramètres tient compagnie à la pastille du compte plutôt que de
              // figurer dans le rail : ce n'est pas un module, et l'y afficher
              // comme les autres lui donnait le même poids visuel qu'un domaine
              // métier. Les deux sont des réglages, pas du contenu. ?>
        <?php // Une seule ligne : le crédit à gauche, la pastille du compte à
              // droite. Les deux occupaient deux rangées centrées, et deux
              // lignes pour deux objets qui ne se répondent pas prenaient au
              // rail une hauteur que la navigation vaut mieux.
              //
              // Le crédit est DANS .side-bottom et non sous lui : c'est la
              // rangée qui les met à distance (space-between), pas une marge
              // posée sur l'un des deux. ?>
        <div class="side-bottom">
            <a class="side-powered" href="https://github.com/nivivier/Lasso" target="_blank" rel="noopener">
                <?php // ⚠️ C'est l'IMAGE qui porte le texte, et les mots à côté qui sont
      // masqués aux lecteurs d'écran : en rail replié il ne reste que le
      // logo, et il doit se nommer tout seul. L'inverse — un alt vide et le
      // texte seul — laissait un rail replié dont le dernier élément ne
      // disait rien. ?>
<img src="<?= e(asset_data_uri_mini('assets/lasso.png', 32)) ?>" alt="Lasso v<?= e(maj_version_locale()) ?>" class="side-powered-logo"><span class="side-powered-txt" aria-hidden="true"> Lasso <span class="side-version">v<?= e(maj_version_locale()) ?></span></span>
            </a>
            <button class="side-avatar" id="side-avatar-btn" title="Mon compte" aria-label="Mon compte" aria-haspopup="true" aria-expanded="false">
                <?= e($initiales) ?>
            </button>
            <?php // (Plus d'engrenage ici : les Paramètres ont leur volet dans le
                  // rail, au-dessus. Deux portes vers le même endroit, dont une
                  // qui ne dit pas où elle mène, c'était une de trop — et celle
                  // du bas tenait une liste de routes à jour à la main.) ?>
        </div>
        <div class="side-avatar-menu" id="side-avatar-menu" hidden>
            <div class="side-avatar-id">
                <strong><?= e($nomComplet) ?></strong>
                <span><?= e($u['email']) ?></span>
            </div>
            <a href="?p=mon_compte" class="<?= $cur === 'mon_compte' ? 'on' : '' ?>">Mon compte</a>
            <a href="?p=deconnexion">Déconnexion</a>
        </div>
    </div>
</aside>
<main class="content">
    <?php require $contentView; ?>
</main>
<?php // Le bouton « Fermer » de l'aperçu est posé dans la barre d'outils de la
      // page affichée, qui vit dans l'iframe — laquelle n'a pas le sprite de
      // CETTE page. Son icône doit donc être un SVG complet, pas un <use>. ?>
<?php $spriteAvant = icones_mode_sprite(); icones_mode_sprite(false);
      $icoFermerApercu = icon('x');
      // Le bouton « Fermer » injecté dans la barre de la page affichée : le même
      // que celui des fenêtres (bouton_fermer_modal_html()), à sa classe
      // d'accroche près. Écrit ici — sprite coupé — pour que son icône soit un
      // SVG complet : l'iframe n'a pas le sprite de CETTE page.
      $htmlFermerApercu = bouton_fermer_modal_html('', 'print-toolbar-fermer');
      icones_mode_sprite($spriteAvant); ?>
<div id="preview-modal" hidden aria-modal="true" role="dialog" aria-label="Aperçu">
    <div id="preview-modal-inner">
        <?php // Repli : ce bouton flottant ne paraît que si la page affichée n'a
              // pas de barre d'outils où poser « Fermer ». Toutes en ont une
              // aujourd'hui — il couvre le cas d'une page qui n'en aurait pas,
              // plutôt que de laisser la fenêtre sans sortie visible. ?>
        <button id="preview-modal-close" hidden title="Fermer l'aperçu" aria-label="Fermer l'aperçu"><?= icon('x') ?></button>
        <iframe id="preview-modal-frame" src="" title="Aperçu"></iframe>
    </div>
</div>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
    const body = document.body, burger = document.getElementById('burger'),
          close = document.getElementById('side-close'), scrim = document.getElementById('scrim');

    // Le rail garde sa position de défilement d'une page à l'autre. Chaque clic
    // recharge la page (pas de SPA) : sans ça, qui travaille dans Booking ou
    // dans les Paramètres — tout en bas de la colonne — remontait au Tableau de
    // bord à chaque navigation et devait redescendre.
    //
    // sessionStorage et non localStorage : la position appartient à cet onglet
    // et à cette session de travail. En navigation privée l'accès peut lever,
    // d'où les try/catch — le rail fonctionne sans, il oublie seulement.
    const railNav = document.querySelector('.side-nav');
    if (railNav) {
        const cleRail = 'lasso-rail-defilement';
        let memorise = null;
        try { memorise = sessionStorage.getItem(cleRail); } catch (e) {}
        if (memorise !== null) {
            railNav.scrollTop = parseInt(memorise, 10) || 0;
        } else {
            // Première visite de la session : on montre où l'on est plutôt que
            // le haut de la colonne — la page ouverte peut être sous la ligne
            // de flottaison (Paramètres, Recherche de fonds).
            const actif = railNav.querySelector('.nav-page.on, .nav-sous-page.on, .nav-volet.on');
            if (actif) { actif.scrollIntoView({ block: 'nearest' }); }
        }
        railNav.addEventListener('scroll', () => {
            try { sessionStorage.setItem(cleRail, String(Math.round(railNav.scrollTop))); } catch (e) {}
        }, { passive: true });
    }
    function toggle(open) {
        body.classList.toggle('nav-open', open);
        burger.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    // (Plus de mesure du bandeau : il n'est plus collant, donc l'en-tête d'un
    // tableau se colle au bord de la fenêtre et n'a plus de décalage à
    // calculer — voir .table-scroll .list thead th dans assets/app.css.)

    burger.addEventListener('click', () => toggle(!body.classList.contains('nav-open')));
    close.addEventListener('click', () => toggle(false));
    scrim.addEventListener('click', () => toggle(false));

    // L'action principale de la page remonte dans la barre supérieure sur
    // téléphone (docs/UI.md § 1). Laquelle : le bouton MIS EN ÉVIDENCE de la
    // barre d'outils — un `.btn` sans `.ghost` —, il y en a un ou aucun. Le
    // bouton est DÉPLACÉ, pas dupliqué : un second exemplaire se serait
    // désynchronisé du premier (libellé, lien, droits) au premier écran qui
    // change le sien.
    //
    // Pourquoi en JavaScript, alors que l'application en met le moins possible :
    // la barre est écrite AVANT la vue (elle est dans le gabarit), donc le
    // serveur ne connaît pas encore le bouton quand il la rend. Et le poser en
    // CSS ne marche pas : `.toolbar` ouvre un contexte d'empilement (z-index:2),
    // dont un enfant en position:fixed ne sort pas — il serait peint SOUS la
    // barre quel que soit son z-index. Sans script, le bouton reste donc à sa
    // place d'origine dans la barre d'outils : la page garde exactement le
    // comportement qu'elle avait.
    //
    // Où le chercher : la rangée d'actions d'une barre d'outils ou d'un en-tête
    // de page — et rien d'autre, surtout pas « un .btn quelque part dans
    // l'en-tête » : le bouton « Appliquer » d'un panneau d'entonnoir serait
    // parti dans la barre. Un menu déroulant (le « + » du tableau de bord) a
    // son bouton pour <summary> : c'est alors le <details> entier qui monte,
    // son panneau avec lui.
    //
    // ⚠️ Un LIEN ou un menu, jamais un <button> : sur un écran qui est un
    // formulaire, « Enregistrer » est lui aussi mis en évidence dans l'en-tête
    // (entete_form_actions_html()), et le monter dans la barre le séparerait
    // d'« Annuler » et de « Supprimer », qui forment un trio. Ce que la barre
    // prend, c'est l'action qui mène AILLEURS — créer, importer, choisir.
    //
    // ⚠️ Les créneaux sont essayés UN À UN, du plus précis au plus large, et
    // non réunis en une seule liste de sélecteurs : querySelector('a, b') rend
    // le premier élément dans l'ordre du DOCUMENT, pas le premier sélecteur qui
    // trouve. Comme le bandeau de page précède la barre d'outils, une page qui
    // aurait un bouton aux deux endroits aurait laissé monter celui du bandeau,
    // l'inverse de l'ordre écrit ici.
    const slotAction = document.getElementById('mbar-action-slot');
    const creneaux = ['.toolbar > .head-actions', '.page-head > .head-actions', '.page-head']
        .flatMap(c => [c + ' > a.btn:not(.ghost)', c + ' > .menu-deroulant > summary.btn:not(.ghost)']);
    const trouve = creneaux.reduce((trouvee, sel) => trouvee || document.querySelector(sel), null);
    const actionPrinc = trouve && trouve.tagName === 'SUMMARY' ? trouve.parentElement : trouve;
    if (slotAction && actionPrinc) {
        // Ancre invisible laissée à la place d'origine : au-delà du seuil, le
        // bouton doit revenir EXACTEMENT là où il était, et non à la fin de la
        // rangée d'actions — « Modifier » y est toujours le dernier.
        const ancre = document.createComment('action principale');
        actionPrinc.after(ancre);
        const etroit = matchMedia('(max-width: 800px)');
        const placer = () => {
            if (etroit.matches) { slotAction.appendChild(actionPrinc); }
            else { ancre.parentNode.insertBefore(actionPrinc, ancre); }
            actionPrinc.classList.toggle('mbar-action', etroit.matches);
        };
        placer();
        etroit.addEventListener('change', placer);
    }

    // Les actions de la page remontent dans le bandeau, à droite du surtitre :
    // c'est là qu'on les cherche, et la barre d'outils d'une liste n'a plus à
    // porter deux choses de nature différente — ce qui FILTRE ce qu'on voit, et
    // ce qui AGIT dessus (docs/UI.md § 1).
    //
    // Déplacement et non réécriture des trente vues concernées : chacune rend
    // déjà son bloc .head-actions au même endroit, et le bandeau est rendu
    // AVANT le contenu — il ne peut pas connaître des boutons qui n'existent pas
    // encore. L'ancre laissée en place sert au retour sous 800px, où le bandeau
    // n'a pas la largeur de les accueillir. Ce bloc vient APRÈS celui de la
    // barre mobile, qui doit repérer l'action principale là où la vue l'a mise.
    // Même mécanique pour la recherche de la page, qui monte à droite du TITRE
    // (une ligne plus bas que les actions) : elle appartient à ce qu'on
    // regarde. Les deux partagent le même interrupteur de largeur.
    const large = matchMedia('(min-width: 801px)');
    const remonter = (creneau, bloc) => {
        if (!creneau || !bloc) return;
        const ancre = document.createComment('bloc remonté dans le bandeau');
        bloc.after(ancre);
        const placer = () => {
            if (large.matches) { creneau.appendChild(bloc); }
            else { ancre.parentNode.insertBefore(bloc, ancre); }
        };
        placer();
        large.addEventListener('change', placer);
    };
    const blocActions = document.querySelector('.module-content .toolbar > .head-actions');
    // La bascule Liste/Carte ne monte PAS avec les actions : elle ne crée rien,
    // elle change ce qu'on voit — c'est l'étage 2 (docs/UI.md § 1). Sortie du
    // bloc avant son déplacement, elle reste dans la barre d'outils, poussée à
    // droite par le CSS.
    const bascule = blocActions?.querySelector('.seg-picker');
    if (bascule && blocActions) { blocActions.parentNode.appendChild(bascule); }
    remonter(document.getElementById('page-head-actions'), blocActions);

    // La barre d'outils — recherche, bouton de filtres, bascule de vue —
    // monte sur la ligne du TITRE, au milieu du vide entre lui et les
    // actions : l'en-tête perd alors son second étage, et ce qui filtre
    // reste à portée de ce qu'il filtre. Elle n'y monte que si la ligne la
    // tient ; sinon elle redescend exactement où la vue l'avait mise.
    //
    // MESURÉ, et non décidé par un point de rupture : la place dépend du
    // titre (« Correspondance avec les structures » contre « Projets »), du
    // nombre de boutons d'action et du contenu de la barre elle-même — trois
    // choses qu'une media query ignore. Le test se fait sur la ligne du titre
    // une fois la barre posée dedans : débordement horizontal, ou rangée
    // d'actions qui passe à deux lignes (.page-head-actions a flex-wrap:wrap,
    // elle se replie avant de faire déborder quoi que ce soit).
    //
    // ⚠️ La barre est TOUJOURS redescendue avant d'être mesurée : montée, elle
    // occupe elle-même le vide qu'on cherche à mesurer, et la réponse serait
    // « ça tient » à toutes les largeurs. Le va-et-vient ne coûte qu'un calcul
    // de mise en page, jamais un affichage intermédiaire — rien n'est peint
    // entre les deux dans la même tâche.
    const creneauOutils = document.getElementById('page-head-outils');
    // La ligne est déduite du créneau, et non cherchée dans .page-head-band :
    // les Paramètres rendent le même en-tête par _param_tabs.php, dans une
    // autre enveloppe.
    const ligneTitre = creneauOutils && creneauOutils.closest('.page-head-titre-page');
    const barreOutils = document.querySelector('.module-content > .module-content-inner > .toolbar');
    if (creneauOutils && ligneTitre && barreOutils) {
        const ancreOutils = document.createComment('barre d\'outils remontée sur le titre');
        barreOutils.after(ancreOutils);
        const actionsLigne = ligneTitre.querySelector('.page-head-actions');
        const placerOutils = () => {
            ancreOutils.parentNode.insertBefore(barreOutils, ancreOutils);
            if (!large.matches) return;
            const hActions = actionsLigne ? actionsLigne.offsetHeight : 0;
            creneauOutils.appendChild(barreOutils);
            const deborde = ligneTitre.scrollWidth > ligneTitre.clientWidth + 1
                || (actionsLigne && actionsLigne.offsetHeight > hActions);
            if (deborde) { ancreOutils.parentNode.insertBefore(barreOutils, ancreOutils); }
        };
        placerOutils();
        // Les largeurs ne sont justes qu'une fois les polices chargées : un
        // titre rendu dans la police de secours est plus étroit, et la barre
        // serait montée sur une ligne qui ne la tiendra pas.
        if (document.fonts && document.fonts.ready) { document.fonts.ready.then(placerOutils); }
        large.addEventListener('change', placerOutils);
        // Coalescé en requestAnimationFrame, comme le panneau de filtres de
        // assets/app.js : glisser le bord de la fenêtre déclenche des dizaines
        // d'événements par seconde, et chacun coûte ici deux mises en page.
        let rafOutils = false;
        window.addEventListener('resize', () => {
            if (rafOutils) return;
            rafOutils = true;
            requestAnimationFrame(() => { rafOutils = false; placerOutils(); });
        });
    }

    // Une FICHE range son en-tête comme une liste : le lien de retour et les
    // actions sur la ligne haute, le titre en dessous. Le retour est un frère
    // du bloc de titre dans le balisage (et un <a>, donc rien ne peut s'y
    // nicher) : on le fait entrer dans le bloc, et une grille remet chacun à
    // sa place. Le jour où une vue met déjà son retour à l'intérieur
    // (?p=evenement), il n'y a rien à faire.
    const bande = document.querySelector('.module-content-inner > .page-head-bande');
    if (bande) {
        const retour = bande.previousElementSibling;
        if (retour && retour.classList.contains('back-link')) {
            bande.insertBefore(retour, bande.firstChild);
        }
        if (bande.querySelector(':scope > .back-link')) {
            bande.classList.add('page-head-bande-range');
        }
    }

    // Les actions secondaires passent derrière les trois points. Une seule
    // reste dehors : l'action PRINCIPALE — le bouton plein, ou à défaut le
    // dernier du bloc, puisque la convention de l'application met l'action
    // majeure en fin de rangée (docs/UI.md § 1). Deux actions de même poids
    // côte à côte, c'est deux fois rien qui se dispute l'œil.
    //
    // ⚠️ Déplacement des nœuds EUX-MÊMES, écouteurs compris (le « + Écriture
    // manuelle » de la compta est un bouton piloté par la vue) : rien n'est
    // recréé, seules les classes de bouton tombent — dans le panneau, une
    // entrée est une ligne de menu, pas un bouton de plus.
    const menuPlus = document.getElementById('page-head-plus');
    const panneauPlus = document.getElementById('page-head-plus-panneau');
    if (blocActions && menuPlus && panneauPlus) {
        const depouiller = (el) => {
            el.classList.remove('btn', 'ghost', 'btn-sm', 'btn-compact', 'icon-only', 'mbar-action');
            el.querySelectorAll('.btn-compact-break').forEach(br => br.remove());
        };
        const enfants = Array.from(blocActions.children);
        // Un menu déroulant déjà posé par la vue reste dehors : il porte ses
        // propres entrées, et l'imbriquer dans un second n'aurait pas de sens.
        const menusVue = enfants.filter(el => el.classList.contains('menu-deroulant'));
        const deplacables = enfants.filter(el => !el.classList.contains('menu-deroulant'));
        // L'action principale reste dehors elle aussi : le bouton PLEIN, ou à
        // défaut le dernier du bloc. ⚠️ Sauf quand la vue a déjà son menu : il
        // EST l'action principale (« Nouvelle pièce » sur ?p=pieces), et tout
        // le reste part derrière les trois points — sans cette exception, un
        // bouton secondaire resté seul se prenait pour le principal et
        // l'archive de l'année trônait à côté du menu.
        const plein = deplacables.filter(el =>
            (el.classList.contains('btn') && !el.classList.contains('ghost')) || el.querySelector('.btn:not(.ghost)')
        ).pop();
        const principale = plein || (menusVue.length ? null : deplacables[deplacables.length - 1]);
        const secondaires = deplacables.filter(el => el !== principale);
        if (secondaires.length) {
            secondaires.forEach(el => {
                depouiller(el);
                el.querySelectorAll('.btn').forEach(depouiller);
                panneauPlus.appendChild(el);
            });
            menuPlus.hidden = false;
        }
    }

    // Pastille utilisateur : ouvre/ferme le menu au clic, ferme si clic dehors.
    const avatarBtn  = document.getElementById('side-avatar-btn');
    const avatarMenu = document.getElementById('side-avatar-menu');
    avatarBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        const open = avatarMenu.hasAttribute('hidden');
        avatarMenu.toggleAttribute('hidden', !open);
        avatarBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    document.addEventListener('click', () => {
        avatarMenu.setAttribute('hidden', '');
        avatarBtn.setAttribute('aria-expanded', 'false');
    });

    // Infobulles « i » (.info-tip) : tap pour basculer — indispensable sur
    // mobile où :hover ne s'applique pas. Une seule ouverte à la fois, fermeture
    // au clic dehors ou à Echap.
    document.addEventListener('click', e => {
        const tip = e.target.closest('.info-tip');
        document.querySelectorAll('.info-tip.open').forEach(t => { if (t !== tip) t.classList.remove('open'); });
        if (tip) {
            // Empêche le <label> englobant de transférer le clic à son champ
            // (sinon ce clic « fantôme » referme aussitôt la bulle qu'on ouvre).
            e.preventDefault();
            e.stopPropagation();
            tip.classList.toggle('open');
        }
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') document.querySelectorAll('.info-tip.open').forEach(t => t.classList.remove('open'));
    });

    // Messages flottants : disparition automatique après 3 s
    document.querySelectorAll('.flash').forEach(el => {
        setTimeout(() => { el.classList.add('flash-out'); setTimeout(() => el.remove(), 400); }, 3000);
    });

    // Lignes cliquables (souris + clavier). Un clic sur un lien/bouton/case à
    // cocher dans la ligne garde son comportement propre — form inclus (ex.
    // formulaire d'ajout d'étiquette par ligne, ?p=structures, ou les
    // formulaires déjà présents dans les lignes de ?p=projets) : sans ça,
    // un clic dans un espace du formulaire hors bouton/champ (padding entre
    // deux champs, etc.) déclenchait quand même la navigation de la ligne.
    // .cat-search-list répétée à part (déjà couverte par closest('form') vu
    // qu'elle y est nichée aujourd'hui) : au cas où une future liste de
    // suggestions apparaisse un jour hors d'un <form> dans une ligne.
    // Destination d'une ligne cliquable : data-href s'il est posé, sinon le lien
    // de titre que la ligne contient déjà. ?p=structures ne pose plus l'attribut
    // — il recopiait ce href à l'identique sur 2959 lignes — et les listes qui
    // n'ont pas de .titre-lien continuent de le fournir.
    function go(el) {
        const u = el.getAttribute('data-href') || el.querySelector('a.titre-lien')?.getAttribute('href');
        if (u) location.href = u;
    }
    document.querySelectorAll('tr.row-link').forEach(row => {
        // .plan-grip : poignée de glisser-déposer (?p=projets). Elle portait
        // un onclick="event.stopPropagation()" ; les attributs de gestionnaire
        // ayant été supprimés pour permettre le durcissement de la CSP, son
        // exclusion se déclare ici, comme celle des autres éléments interactifs.
        // .menu-deroulant : un menu posé dans une ligne (« Synchroniser »,
        // ?p=projets). Son <summary> n'est ni un <a> ni un <button> — sans
        // cette exclusion, l'ouvrir naviguait vers la fiche.
        row.addEventListener('click', e => { if (!e.target.closest('a,button,input,form,.cat-search-list,.plan-grip,.menu-deroulant')) go(row); });
        row.addEventListener('keydown', e => {
            // e.target !== row : la ligne ne s'active au clavier que si c'est
            // ELLE qui a le focus. Sans ce test, une frappe dans un champ de la
            // ligne remontait jusqu'ici — taper une espace dans le champ
            // « nouvelle étiquette » de ?p=structures naviguait vers la fiche
            // au lieu d'écrire l'espace, et les étiquettes en deux mots étaient
            // impossibles à saisir. Le gestionnaire de clic juste au-dessus
            // faisait déjà cette exclusion ; celui-ci l'avait oubliée.
            if (e.target !== row) return;
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(row); }
        });
    });

    // Clic sur le texte résumé → bascule résumé ↔ texte brut complet (toutes pages).
    // Une seule fonction pour la cellule et pour la colonne entière : deux
    // chemins vers le même état finiraient par en donner deux différents.
    const basculerTexte = (td, expanded) => {
        const txt = td.querySelector('.texte-cell-txt');
        if (!txt) return;
        td.classList.toggle('expanded', expanded);
        txt.textContent = expanded ? td.title : txt.dataset.summary;
    };
    document.addEventListener('click', e => {
        const td = e.target.closest('.compta-lettrage .texte-cell');
        if (!td || e.target.closest('a,button')) return;
        basculerTexte(td, !td.classList.contains('expanded'));
    });
    // Et le bouton d'en-tête, qui fait d'un coup ce que la colonne fait une
    // cellule à la fois. Il regarde l'état réel des cellules plutôt que de
    // garder le sien : une cellule dépliée à la main ne doit pas le
    // désynchroniser. Tant qu'il en reste une repliée, il déplie tout.
    document.addEventListener('click', e => {
        const btn = e.target.closest('.texte-tout-btn');
        if (!btn) return;
        const cells = [...document.querySelectorAll('.compta-lettrage .texte-cell')];
        const tout = cells.some(td => !td.classList.contains('expanded'));
        cells.forEach(td => basculerTexte(td, tout));
        btn.classList.toggle('on', tout);
        btn.title = tout ? 'Tout résumer' : 'Tout détailler';
        btn.setAttribute('aria-label', btn.title);
        btn.innerHTML = btn.dataset[tout ? 'icoFold' : 'icoUnfold'];
    });

    // Fenêtre d'aperçu (liens [data-preview]). DEUX formats, et pas un de plus :
    //   « a4 » (défaut) — un document destiné au papier : facture, fiche de
    //          salaire, certificat, bilan. La fenêtre a la largeur d'une feuille.
    //   « ajuste »      — un contenu qui n'est pas une feuille : le tableau de
    //          l'export SUISA, par exemple. La fenêtre prend la place disponible.
    // Le format se déclare sur le lien (data-preview="ajuste"), et non dans la
    // page cible : la fenêtre s'ouvre avant que l'iframe ait chargé, elle doit
    // donc connaître sa taille tout de suite.
    const previewModal = document.getElementById('preview-modal');
    const previewFrame = document.getElementById('preview-modal-frame');
    const previewClose = document.getElementById('preview-modal-close');
    function openPreview(url, format) {
        previewModal.classList.toggle('preview-ajuste', format === 'ajuste');
        previewFrame.style.height = '';   // remis à la hauteur du format courant
        previewFrame.src = url;
        previewClose.hidden = false; // masqué au chargement si la page a une barre d'outils
        previewModal.removeAttribute('hidden');
        document.body.style.overflow = 'hidden';
    }
    function closePreview() {
        previewModal.setAttribute('hidden', '');
        previewFrame.src = '';
        previewFrame.style.height = '';
        document.body.style.overflow = '';
    }
    // Format « ajuste » : la hauteur du cadre suit celle du contenu, plafonnée à
    // la place disponible. Sans cette mesure, l'iframe occuperait toute la
    // fenêtre même pour trois lignes de tableau — un cadre vide sous le contenu.
    function ajusterHauteur() {
        if (!previewModal.classList.contains('preview-ajuste')) return;
        try {
            const doc = previewFrame.contentDocument;
            const dispo = previewModal.clientHeight - 64; // padding 32px en haut et en bas
            previewFrame.style.height = Math.min(doc.documentElement.scrollHeight + 2, dispo) + 'px';
        } catch (err) {}
    }
    document.addEventListener('click', e => {
        const a = e.target.closest('a[data-preview]');
        if (!a || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey) return;
        e.preventDefault();
        openPreview(a.href, a.dataset.preview);
    });
    previewClose.addEventListener('click', closePreview);
    previewModal.addEventListener('click', e => { if (e.target === previewModal) closePreview(); });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && !previewModal.hasAttribute('hidden')) closePreview();
    });
    // « Fermer » rejoint la barre d'outils de la page affichée (même origine →
    // accès DOM autorisé), tout à droite : c'est un bouton comme les autres, à
    // la fin de la rangée des actions, et non une pastille posée par-dessus le
    // document. Il est injecté d'ici plutôt qu'écrit dans les huit vues
    // d'impression : ces pages s'ouvrent aussi seules, hors de la fenêtre
    // d'aperçu, où « Fermer » n'aurait rien à fermer.
    const fermerHtml = <?= json_encode($htmlFermerApercu, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    previewFrame.addEventListener('load', () => {
        ajusterHauteur();
        try {
            const doc = previewFrame.contentDocument;
            const barre = doc.querySelector('.print-toolbar');
            if (barre && !barre.querySelector('.print-toolbar-fermer')) {
                // Inséré en HTML, et non monté nœud par nœud : le bouton vient
                // tout fait du serveur, classes et <span class="btn-txt">
                // compris — c'est ce span qui laisse « Fermer » s'effacer sur
                // un téléphone, et le réécrire ici le ferait diverger.
                barre.insertAdjacentHTML('beforeend', fermerHtml);
                barre.querySelector('.print-toolbar-fermer')
                     .addEventListener('click', closePreview);
            }
            previewClose.hidden = !!barre;
            doc.addEventListener('keydown', ev => {
                if (ev.key === 'Escape') { ev.stopPropagation(); closePreview(); }
            }, true);
        } catch(err) {}
    });
})();
</script>
<?php else: ?>
<?php $fondDecor = $fondDecorAuth; require __DIR__ . '/_fond_decor.php'; ?>
<main class="auth-wrap">
    <?php
    // Le fond de connexion s'assombrit avec le thème, exactement comme le rail :
    // le logo doit suivre, sinon c'est la variante à encre foncée qui se retrouve
    // sur fond sombre, où elle ne se voit plus. Même mécanique que .side-logo
    // plus haut : les DEUX variantes sont rendues côté serveur et le CSS montre
    // la bonne — en mode « automatique », le serveur ignore le réglage système,
    // et c'est la seule façon de trancher sans JavaScript ni scintillement.
    // Même repli aussi : une seule variante configurée sert aux deux thèmes.
    $logoAuthClair  = $logoClair !== '' ? $logoClair : $logoSombre;
    $logoAuthSombre = $logoSombre !== '' ? $logoSombre : $logoAuthClair;
    ?>
    <?php if ($logoAuthClair !== ''): ?>
        <img src="<?= e($logoAuthClair) ?>" alt="<?= e($nomEmployeur) ?>" class="auth-logo auth-logo-clair">
        <img src="<?= e($logoAuthSombre) ?>" alt="<?= e($nomEmployeur) ?>" class="auth-logo auth-logo-sombre">
    <?php else: ?><div class="auth-name"><?= e($nomEmployeur) ?></div><?php endif; ?>
    <?php require $contentView; ?>
    <a class="side-powered auth-powered" href="https://github.com/nivivier/Lasso" target="_blank" rel="noopener">
        <?php // ⚠️ C'est l'IMAGE qui porte le texte, et les mots à côté qui sont
      // masqués aux lecteurs d'écran : en rail replié il ne reste que le
      // logo, et il doit se nommer tout seul. L'inverse — un alt vide et le
      // texte seul — laissait un rail replié dont le dernier élément ne
      // disait rien. ?>
<img src="<?= e(asset_data_uri_mini('assets/lasso.png', 32)) ?>" alt="Lasso v<?= e(maj_version_locale()) ?>" class="side-powered-logo"><span class="side-powered-txt" aria-hidden="true"> Lasso <span class="side-version">v<?= e(maj_version_locale()) ?></span></span>
    </a>
</main>
<?php endif; ?>
</body>
</html>
