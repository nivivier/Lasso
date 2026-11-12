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
          // en tête de assets/css/) : plus aucune requête vers fonts.googleapis.com.
          // Préchargée car elle est découverte tardivement (référencée depuis la
          // feuille de style, donc après son téléchargement et son analyse). ?>
    <?php // URL sans « ?v= », volontairement : elle doit correspondre au
          // caractère près à celle du @font-face, sinon le navigateur
          // télécharge le fichier deux fois et le préchargement est perdu. La
          // police est immuable — si elle change un jour, changer son nom. ?>
    <link rel="preload" href="assets/fonts/inter-latin-var.woff2" as="font" type="font/woff2" crossorigin>
    <?= css_liens_html() ?>
    <script src="<?= e(asset_url('assets/app.js')) ?>"></script>
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
      // décor a le sien (assets/css/, section « Décors de fond »). ?>
<?php // fondc-<combo> : les deux teintes du décor (assets/css/). Posée même
      // sur les pages hors session — un décor y paraît aussi, et il doit avoir
      // les mêmes couleurs qu'une fois connecté. ?>
<body class="<?= $u ? 'has-sidebar' : 'auth-bg' ?> fond-<?= e($u ? $fondDecor : $fondDecorAuth) ?> fondc-<?= e(param_fond_couleurs()) ?><?= $mbarTitre !== '' ? ' titre-dans-barre' : '' ?>">
<?php if ($u): ?>
<?php // Premier élément atteint au clavier : sauter le rail et ses quarante
      // liens pour arriver droit au contenu. Invisible tant qu'il n'a pas le
      // focus (.lien-evitement, assets/css/). ?>
<a class="btn lien-evitement" href="#contenu">Aller au contenu</a>
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
                <?php // Deux boîtes : la grille porte la hauteur qu'on anime au
                      // dépliage, l'intérieur le dépassement masqué (assets/css/).
                      // Même recette que la branche d'un entonnoir arborescent. ?>
                <div class="nav-sous"><div class="nav-sous-inner">
                    <?php foreach ($navO[4] as $navSRoute => $navSLib): ?>
                    <a href="?p=<?= e((string) $navSRoute) ?>&depuis=<?= e($navCle) ?>" class="nav-sous-page<?= $cur === (string) $navSRoute ? ' on' : '' ?>"><?= e($navSLib) ?></a>
                    <?php endforeach; ?>
                </div></div>
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
                <div class="nav-sous"><div class="nav-sous-inner">
                    <?php foreach ($ptSections as $ptRoute => $ptLib): ?>
                    <?php $ptAlias = in_array($cur, (array) ($ptGroupe[2] ?? []), true) && $ptRoute === array_key_first($ptSections); ?>
                    <a href="?p=<?= e((string) $ptRoute) ?>" class="nav-sous-page<?= $cur === (string) $ptRoute || $ptAlias ? ' on' : '' ?>"><?= e((string) $ptLib) ?></a>
                    <?php endforeach; ?>
                </div></div>
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
<main class="content" id="contenu">
    <?php // Message posé en session par un refus survenu AILLEURS que dans la
          // route qui rend la page (le verrou de clôture, index.php) : il
          // s'affiche une fois, sur la page où l'on revient. ?>
    <?php if (!empty($_SESSION['flash_err'])): ?>
        <p class="err flash"><?= e((string) $_SESSION['flash_err']) ?></p>
        <?php unset($_SESSION['flash_err']); ?>
    <?php endif; ?>
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
<script type="application/json" id="lasso-gabarit-donnees"><?= json_encode(['fermerHtml' => $htmlFermerApercu], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
<script src="<?= e(asset_url('assets/gabarit.js')) ?>"></script>
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
