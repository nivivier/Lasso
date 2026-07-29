<?php
// Route de l'onglet Paramètres → Dev : lance depuis le web les scripts de
// maintenance ponctuels (lib/dev.php), réservée aux administrateurs (écriture
// cœur, voir index.php). Toujours dry-run avant écriture, sauvegarde
// automatique de la base avant toute fusion/écriture — même principe que
// route_maj()/route_import_structures().

declare(strict_types=1);

function route_dev(): void
{
    require_login();

    $type = in_array($_GET['type'] ?? '', ['structures', 'lieux', 'contacts', 'tous'], true) ? $_GET['type'] : 'tous';

    $doublonsErr     = null;
    $datesEtape      = 'upload';
    $datesErr        = null;
    $datesResultat   = null;
    $datesAppliqueN  = null;
    $grandesRegionsErr      = null;
    $grandesRegionsAppliqueN = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $action = $_POST['action'] ?? '';

        if ($action === 'doublons_fusionner') {
            $type = in_array($_POST['type'] ?? '', ['structures', 'lieux', 'contacts', 'tous'], true) ? $_POST['type'] : 'tous';
            $gr  = doublons_detecter($type);
            $bak = sauvegarder_base('avant_doublons');
            if ($bak === null) {
                $doublonsErr = 'Échec de la sauvegarde préalable — fusion annulée.';
            } else {
                $ns = doublons_fusionner_structures($gr['structures']);
                $nl = doublons_fusionner_lieux($gr['lieux']);
                $nc = doublons_fusionner_contacts($gr['contacts']);
                redirect('dev', ['ok' => 'doublons', 'ns' => $ns, 'nl' => $nl, 'nc' => $nc]);
                return;
            }
        } elseif ($action === 'dates_analyser') {
            $r = lire_fichier_importe(
                2 * 1024 * 1024,
                'Fichier trop volumineux (2 Mo maximum).',
                'dev_dates_csv',
                'Veuillez choisir un fichier CSV.',
                'dev_dates_nom'
            );
            if ($r['err'] !== null) {
                $datesErr = $r['err'];
            } else {
                [$entete, $lignes] = structures_lire_csv((string) $r['contenu']);
                if (!$entete) {
                    $datesErr = 'Fichier vide ou illisible.';
                } else {
                    $index = maj_dates_reperer_colonnes($entete);
                    if ($index['nom'] === null) {
                        $datesErr = 'Colonne « nom » introuvable dans le fichier.';
                    } elseif ($index['contact'] === null && $index['concert'] === null && $index['maj'] === null) {
                        $datesErr = 'Aucune colonne de date reconnue (mise à jour, dernier contact ou dernier concert).';
                    } else {
                        $_SESSION['dev_dates_csv'] = $r['contenu'];
                        $_SESSION['dev_dates_nom'] = $r['nom'];
                        $datesEtape = 'analyse';
                        $datesResultat = maj_dates_analyser($entete, $lignes, $index) + ['entete' => $entete, 'index' => $index, 'nom' => $r['nom']];
                    }
                }
            }
        } elseif ($action === 'dates_appliquer') {
            $csv = (string) ($_SESSION['dev_dates_csv'] ?? '');
            [$entete, $lignes] = structures_lire_csv($csv);
            if (!$entete) {
                $datesErr = 'Fichier introuvable en session — veuillez le re-téléverser.';
            } else {
                $index    = maj_dates_reperer_colonnes($entete);
                $resultat = maj_dates_analyser($entete, $lignes, $index);
                if (!$resultat['aEcrire']) {
                    $datesErr = 'Rien à écrire — aucune date nouvelle ou différente détectée.';
                } else {
                    $bak = sauvegarder_base('avant_maj_dates');
                    if ($bak === null) {
                        $datesErr = 'Échec de la sauvegarde préalable — écriture annulée.';
                    } else {
                        maj_dates_appliquer($resultat['aEcrire']);
                        $datesAppliqueN = count($resultat['aEcrire']);
                        unset($_SESSION['dev_dates_csv'], $_SESSION['dev_dates_nom']);
                    }
                }
            }
        } elseif ($action === 'grandes_regions_appliquer') {
            $lignes = grande_regions_detecter();
            $bak = sauvegarder_base('avant_grandes_regions');
            if ($bak === null) {
                $grandesRegionsErr = 'Échec de la sauvegarde préalable — écriture annulée.';
            } else {
                $grandesRegionsAppliqueN = grande_regions_appliquer($lignes);
            }
        }
    }

    render('dev', [
        'type'           => $type,
        'doublons'       => doublons_detecter($type),
        'doublonsErr'    => $doublonsErr,
        'ok'             => $_GET['ok'] ?? null,
        'ns'             => (int) ($_GET['ns'] ?? 0),
        'nl'             => (int) ($_GET['nl'] ?? 0),
        'nc'             => (int) ($_GET['nc'] ?? 0),
        'datesEtape'     => $datesEtape,
        'datesErr'       => $datesErr,
        'datesResultat'  => $datesResultat,
        'datesAppliqueN' => $datesAppliqueN,
        'grandesRegions'         => grande_regions_detecter(),
        'grandesRegionsErr'      => $grandesRegionsErr,
        'grandesRegionsAppliqueN' => $grandesRegionsAppliqueN,
    ], 'Dev');
}
