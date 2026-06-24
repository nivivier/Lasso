#!/usr/bin/env bash
# ============================================================================
#  Déploiement / mise à jour de Lasso sur le serveur.
#  À lancer EN SSH depuis le dossier de l'application :
#      cd ~/sites/votre-domaine && ./deploy.sh
#  Ne contient aucun secret : la config de prod vit dans lib/config.local.php.
# ============================================================================
set -euo pipefail
cd "$(dirname "$0")"

# 1) Sauvegarde de la base avant toute mise à jour (chemin lu depuis la config).
DB="$(php -r 'require "lib/config.php"; echo APP_DB_PATH;' 2>/dev/null || true)"
if [ -n "${DB:-}" ] && [ -f "$DB" ]; then
    BK="${DB%.sqlite}_$(date +%Y%m%d_%H%M%S).sqlite.bak"
    cp "$DB" "$BK"
    echo "→ Sauvegarde base : $BK"
else
    echo "→ (base introuvable ou non configurée — pas de sauvegarde)"
fi

# 2) Récupération du code (fast-forward uniquement : pas de merge surprise).
echo "→ git pull --ff-only…"
git pull --ff-only

# 3) Vérification de la syntaxe PHP de tout le code récupéré.
echo "→ Vérification syntaxe PHP…"
err=0
while IFS= read -r f; do
    php -l "$f" >/dev/null 2>&1 || { echo "  ❌ erreur de syntaxe : $f"; err=1; }
done < <(find . -path ./.git -prune -o -name '*.php' -print)
[ "$err" -eq 0 ] || { echo "❌ Abandon : corrige les erreurs ci-dessus."; exit 1; }

echo "✅ À jour : $(git rev-parse --short HEAD) — $(git log -1 --pretty=%s)"
echo "ℹ️  Les migrations de schéma s'appliquent automatiquement à la 1ʳᵉ requête web."
