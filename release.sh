#!/usr/bin/env bash
# ============================================================================
#  Gestion des versions / canaux de Lasso.
#
#  Canaux :
#    - test   = branche main (tout le travail courant y atterrit)
#    - stable = branche stable (avancée uniquement vers les états validés)
#
#  Usage :
#    ./release.sh test            # pousse main (le canal test avance tout seul)
#    ./release.sh stable 1.4.0    # fige VERSION=1.4.0, tag v1.4.0, avance stable, pousse
# ============================================================================
set -euo pipefail
cd "$(dirname "$0")"

canal="${1:-}"
version="${2:-}"

case "$canal" in
  test)
    git push origin main
    echo "✅ main (canal test) poussé — $(git rev-parse --short HEAD)"
    ;;
  stable)
    [ -n "$version" ] || { echo "Usage : ./release.sh stable X.Y.Z" >&2; exit 1; }
    echo "$version" > VERSION
    git add VERSION
    git commit -m "Version $version (stable)"
    git tag -a "v$version" -m "v$version"
    git branch -f stable HEAD
    git push origin main stable "v$version"
    echo "✅ v$version promue en stable."
    ;;
  *)
    echo "Usage : ./release.sh [test | stable X.Y.Z]" >&2
    exit 1
    ;;
esac
