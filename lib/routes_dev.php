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

    $type = in_array($_GET['type'] ?? '', ['structures', 'contacts', 'tous'], true) ? $_GET['type'] : 'tous';

    $doublonsErr     = null;
    // Doublons potentiels : seuil de ressemblance choisi à l'écran, borné à la
    // liste proposée (la valeur arrive de l'URL).
    $seuil = (int) ($_GET['seuil'] ?? DOUBLONS_POTENTIELS_SEUIL_DEFAUT);
    if (!in_array($seuil, DOUBLONS_POTENTIELS_SEUILS, true)) {
        $seuil = DOUBLONS_POTENTIELS_SEUIL_DEFAUT;
    }
    $potentielsErr       = null;
    $potentielsIgnoresN  = null;
    $potentielsRepriseN  = null;
    $datesEtape      = 'upload';
    $datesErr        = null;
    $datesResultat   = null;
    $datesAppliqueN  = null;
    $grandesRegionsErr      = null;
    $grandesRegionsAppliqueN = null;
    $evenementsLieuxErr        = null;
    $evenementsLieuxLiesN      = null;
    $evenementsLieuxCreesN     = null;
    $evenementsLieuxCreesEvN   = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $action = $_POST['action'] ?? '';
        // Identifiants cochés (case « sel[] », voir views/dev.php) — chaque
        // action ci-dessous n'applique le changement qu'aux lignes présentes
        // dans cet ensemble, jamais à tout ce qui a été détecté par défaut.
        // Format de clé propre à chaque action, voir le filtre correspondant.
        $selection = array_flip(array_map('strval', $_POST['sel'] ?? []));

        if ($action === 'doublons_potentiels_ignorer' || $action === 'doublons_potentiels_reprendre') {
            // Ni l'une ni l'autre ne touche aux fiches : elles n'ajoutent ou ne
            // retirent que des lignes d'exclusion, d'où l'absence de sauvegarde
            // préalable — contrairement à la fusion juste en dessous.
            $paires = doublons_potentiels_lire_cles($_POST['sel'] ?? []);
            if (!$paires) {
                $potentielsErr = 'Aucune paire sélectionnée.';
            } elseif ($action === 'doublons_potentiels_ignorer') {
                $potentielsIgnoresN = doublons_potentiels_ignorer($paires);
            } else {
                $potentielsRepriseN = doublons_potentiels_reprendre($paires);
            }
        } elseif ($action === 'doublons_potentiels_fusionner') {
            // Les clés cochées sont re-confrontées à la détection : une paire
            // qui n'y figure plus (fiche supprimée entre-temps, seuil changé
            // dans un autre onglet) ne doit pas être fusionnée sur la seule foi
            // du formulaire posté.
            $proposees = [];
            foreach (doublons_potentiels_detecter($seuil) as $paire) {
                $proposees[$paire['cle']] = true;
            }
            $paires = array_values(array_filter(
                doublons_potentiels_lire_cles($_POST['sel'] ?? []),
                fn ($p) => isset($proposees[doublons_potentiels_cle($p[0], $p[1])])
            ));
            if (!$paires) {
                $potentielsErr = 'Aucune paire sélectionnée parmi celles proposées.';
            } else {
                $bak = sauvegarder_base('avant_doublons_potentiels');
                if ($bak === null) {
                    $potentielsErr = 'Échec de la sauvegarde préalable — fusion annulée.';
                } else {
                    $ns = doublons_potentiels_fusionner($paires);
                    redirect('dev', ['ok' => 'doublons', 'ns' => $ns, 'nc' => 0, 'seuil' => $seuil]);
                    return;
                }
            }
        } elseif ($action === 'doublons_fusionner') {
            $type = in_array($_POST['type'] ?? '', ['structures', 'contacts', 'tous'], true) ? $_POST['type'] : 'tous';
            $gr  = doublons_detecter($type);
            foreach (['structures', 'contacts'] as $k) {
                $gr[$k] = array_values(array_filter($gr[$k], fn ($g) => isset($selection["$k:{$g['ids'][0]}"])));
            }
            if (!$gr['structures'] && !$gr['contacts']) {
                $doublonsErr = 'Aucun groupe sélectionné.';
            } else {
                $bak = sauvegarder_base('avant_doublons');
                if ($bak === null) {
                    $doublonsErr = 'Échec de la sauvegarde préalable — fusion annulée.';
                } else {
                    $ns = doublons_fusionner_structures($gr['structures']);
                    $nc = doublons_fusionner_contacts($gr['contacts']);
                    redirect('dev', ['ok' => 'doublons', 'ns' => $ns, 'nc' => $nc]);
                    return;
                }
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
                $aEcrireSel = array_values(array_filter($resultat['aEcrire'], fn ($op) => isset($selection["{$op[0]}:{$op[1]}"])));
                if (!$aEcrireSel) {
                    $datesErr = 'Rien à écrire — aucune ligne sélectionnée.';
                } else {
                    $bak = sauvegarder_base('avant_maj_dates');
                    if ($bak === null) {
                        $datesErr = 'Échec de la sauvegarde préalable — écriture annulée.';
                    } else {
                        maj_dates_appliquer($aEcrireSel);
                        $datesAppliqueN = count($aEcrireSel);
                        unset($_SESSION['dev_dates_csv'], $_SESSION['dev_dates_nom']);
                    }
                }
            }
        } elseif ($action === 'grandes_regions_appliquer') {
            $lignes = grande_regions_detecter();
            $lignesSel = array_values(array_filter($lignes, fn ($l) => isset($selection["{$l['table']}:{$l['id']}"])));
            if (!$lignesSel) {
                $grandesRegionsErr = 'Aucune fiche sélectionnée.';
            } else {
                $bak = sauvegarder_base('avant_grandes_regions');
                if ($bak === null) {
                    $grandesRegionsErr = 'Échec de la sauvegarde préalable — écriture annulée.';
                } else {
                    $grandesRegionsAppliqueN = grande_regions_appliquer($lignesSel);
                }
            }
        } elseif ($action === 'evenements_lieux_lier') {
            $repartition = evenements_lieux_repartir(evenements_lieux_detecter());
            $univoquesSel = array_values(array_filter($repartition['univoques'], fn ($d) => isset($selection[(string) $d['evenement_id']])));
            if (!$univoquesSel) {
                $evenementsLieuxErr = 'Rien à lier — aucune ligne sélectionnée.';
            } else {
                $bak = sauvegarder_base('avant_evenements_lieux');
                if ($bak === null) {
                    $evenementsLieuxErr = 'Échec de la sauvegarde préalable — écriture annulée.';
                } else {
                    $evenementsLieuxLiesN = evenements_lieux_lier($univoquesSel);
                }
            }
        } elseif ($action === 'evenements_lieux_creer') {
            $groupes = evenements_lieux_grouper_aucune(evenements_lieux_repartir(evenements_lieux_detecter())['aucune']);
            $groupesSel = array_values(array_filter($groupes, fn ($g) => isset($selection[(string) $g['evenements'][0]['id']])));
            if (!$groupesSel) {
                $evenementsLieuxErr = 'Rien à créer — aucune ligne sélectionnée.';
            } else {
                $bak = sauvegarder_base('avant_evenements_lieux');
                if ($bak === null) {
                    $evenementsLieuxErr = 'Échec de la sauvegarde préalable — écriture annulée.';
                } else {
                    $evenementsLieuxCreesN = evenements_lieux_creer($groupesSel);
                    $evenementsLieuxCreesEvN = array_sum(array_map(fn ($g) => count($g['evenements']), $groupesSel));
                }
            }
        }
    }

    $evenementsLieuxRepartition = evenements_lieux_repartir(evenements_lieux_detecter());
    $evenementsLieuxAucuneGroupes = evenements_lieux_grouper_aucune($evenementsLieuxRepartition['aucune']);

    render('dev', [
        'type'           => $type,
        'doublons'       => doublons_detecter($type),
        'doublonsErr'    => $doublonsErr,
        'seuil'              => $seuil,
        'seuils'             => DOUBLONS_POTENTIELS_SEUILS,
        'potentiels'         => doublons_potentiels_detecter($seuil),
        'potentielsIgnores'  => doublons_potentiels_ignores(),
        'potentielsErr'      => $potentielsErr,
        'potentielsIgnoresN' => $potentielsIgnoresN,
        'potentielsRepriseN' => $potentielsRepriseN,
        'ok'             => $_GET['ok'] ?? null,
        'ns'             => (int) ($_GET['ns'] ?? 0),
        'nc'             => (int) ($_GET['nc'] ?? 0),
        'datesEtape'     => $datesEtape,
        'datesErr'       => $datesErr,
        'datesResultat'  => $datesResultat,
        'datesAppliqueN' => $datesAppliqueN,
        'grandesRegions'         => grande_regions_detecter(),
        'grandesRegionsErr'      => $grandesRegionsErr,
        'grandesRegionsAppliqueN' => $grandesRegionsAppliqueN,
        'evenementsLieuxUnivoques'    => $evenementsLieuxRepartition['univoques'],
        'evenementsLieuxAmbigues'     => $evenementsLieuxRepartition['ambigues'],
        'evenementsLieuxAucuneGroupes' => $evenementsLieuxAucuneGroupes,
        'evenementsLieuxErr'      => $evenementsLieuxErr,
        'evenementsLieuxLiesN'    => $evenementsLieuxLiesN,
        'evenementsLieuxCreesN'   => $evenementsLieuxCreesN,
        'evenementsLieuxCreesEvN' => $evenementsLieuxCreesEvN,
    ], 'Incohérences');
}
