#!/usr/bin/env bash
# deploy-server.sh — build and deploy Magento directly on the VPS
#
# Run as the deploy user on the server:
#   cd /srv/legal/repo && ./scripts/deploy-server.sh

set -euo pipefail
IFS=$'\n\t'

DEPLOY_PATH="/srv/legal"
REPO_DIR="$DEPLOY_PATH/repo"
COMPOSE=(docker compose -f "$REPO_DIR/deploy/docker-compose.prod.yml" --env-file "$DEPLOY_PATH/.env.prod")
BUILD_IMAGE="wardenenv/php-fpm:8.4-magento2"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
LOCALES=(en_US uk_UA)

NEW="$DEPLOY_PATH/builds/new"
CURRENT="$DEPLOY_PATH/builds/current"
ARCHIVE_STORE="$DEPLOY_PATH/builds/archive"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; NC='\033[0m'
step() { echo -e "\n${CYAN}▶ $*${NC}"; }
ok()   { echo -e "${GREEN}  ✓ $*${NC}"; }
die()  { echo -e "${RED}  ✗ $*${NC}" >&2; exit 1; }

[[ ! -f "$DEPLOY_PATH/auth.json" ]]  && die "auth.json not found at $DEPLOY_PATH/auth.json"
grep -q "hyva-themes.repo.packagist.com" "$DEPLOY_PATH/auth.json" \
  || die "auth.json missing hyva-themes.repo.packagist.com credentials — required to install the Hyvä theme package"
[[ ! -f "$DEPLOY_PATH/shared/env.php" ]] && die "shared/env.php not found — run setup:install first"

echo -e "\n${GREEN}╔══════════════════════════════════════════════╗"
echo    "║  Magento server deploy — $TIMESTAMP"
echo -e "╚══════════════════════════════════════════════╝${NC}"

# ── Step 1: Pull latest code ──────────────────────────────────────────────────
step "1/6 — git pull"
git -C "$REPO_DIR" pull
ok "$(git -C "$REPO_DIR" rev-parse --short HEAD)"

# ── Step 2: Export clean source ───────────────────────────────────────────────
step "2/6 — Export clean source from git"
# Previous builds create files as root inside the build container (bind mount),
# so remove via the same image rather than the host user, which may lack permission.
mkdir -p "$DEPLOY_PATH/builds"
docker run --rm -v "$DEPLOY_PATH/builds:/builds" "$BUILD_IMAGE" rm -rf /builds/new
mkdir -p "$NEW"
git -C "$REPO_DIR" archive HEAD | tar -x -C "$NEW"
ok "Source exported to $NEW"

# ── Step 3: composer install + Hyvä theme assets + di:compile ─────────────────
step "3/6 — composer install --no-dev, build Hyvä theme assets, setup:di:compile"

mkdir -p "$DEPLOY_PATH/shared/composer-cache" "$DEPLOY_PATH/shared/npm-cache"

docker run --rm \
  -v "$NEW:/app" \
  -v "$DEPLOY_PATH/shared/composer-cache:/root/.composer/cache" \
  -v "$DEPLOY_PATH/shared/npm-cache:/root/.npm" \
  -e COMPOSER_AUTH="$(cat "$DEPLOY_PATH/auth.json")" \
  -w /app \
  "$BUILD_IMAGE" bash -c '
    set -euo pipefail

    echo "--- composer install --no-dev ---"
    composer install --no-dev --no-interaction --optimize-autoloader --prefer-dist

    echo "--- Enable 2FA for production ---"
    php bin/magento module:enable \
      Magento_TwoFactorAuth Magento_AdminAdobeImsTwoFactorAuth \
      --no-backup 2>/dev/null || true
    php bin/magento module:disable \
      MarkShust_DisableTwoFactorAuth \
      --no-backup 2>/dev/null || true

    # app/etc/hyva-themes.json is committed to the repo rather than regenerated
    # here: "hyva:config:generate" needs app/etc/env.php (DeploymentConfig must
    # be available), which is not copied in until Step 4. Regenerate it locally
    # with `bin/magento hyva:config:generate` and commit it when the set of
    # Hyvä-compatible composer packages changes.

    echo "--- Build Hyvä theme frontend assets (Tailwind) ---"
    find app/design/frontend -path "*/web/tailwind/package.json" -print0 |
      while IFS= read -r -d "" pkgjson; do
        themedir=$(dirname "$pkgjson")
        echo "  building $themedir"
        ( cd "$themedir" && npm ci --no-audit --no-fund && npm run build && rm -rf node_modules )
      done

    echo "--- setup:di:compile ---"
    php bin/magento setup:di:compile
  '
ok "Build complete"

# ── Step 4: Copy shared env.php ───────────────────────────────────────────────
step "4/6 — Link shared env.php"
mkdir -p "$NEW/app/etc" "$NEW/var"
cp "$DEPLOY_PATH/shared/env.php" "$NEW/app/etc/env.php"
ok "env.php in place"

# ── Step 5: setup:upgrade + static:deploy (needs live DB) ─────────────────────
step "5/6 — setup:upgrade + setup:static-content:deploy"

docker run --rm \
  --network legal_internal \
  -v "$NEW:/var/www/html" \
  -v "$DEPLOY_PATH/shared/media:/var/www/html/pub/media" \
  -v "$DEPLOY_PATH/shared/logs:/var/www/html/var/log" \
  -e MAGE_MODE=production \
  "$BUILD_IMAGE" \
  php /var/www/html/bin/magento setup:upgrade --keep-generated
ok "setup:upgrade done"

docker run --rm \
  --network legal_internal \
  -v "$NEW:/var/www/html" \
  -v "$DEPLOY_PATH/shared/media:/var/www/html/pub/media" \
  -v "$DEPLOY_PATH/shared/logs:/var/www/html/var/log" \
  -e MAGE_MODE=production \
  "$BUILD_IMAGE" \
  php /var/www/html/bin/magento setup:static-content:deploy -f "${LOCALES[@]}" --no-interaction
ok "static-content:deploy done"

# ── Step 6: Swap builds + restart ─────────────────────────────────────────────
step "6/6 — Swap builds and restart"

mkdir -p "$ARCHIVE_STORE"
if [[ -d "$CURRENT" ]]; then
  mv "$CURRENT" "$ARCHIVE_STORE/${TIMESTAMP}-prev"
  ln -sfn "$ARCHIVE_STORE/${TIMESTAMP}-prev" "$DEPLOY_PATH/builds/previous" 2>/dev/null || true
fi
mv "$NEW" "$CURRENT"
ok "Build swapped → current"

"${COMPOSE[@]}" restart php-fpm nginx cron
ok "Containers restarted"

"${COMPOSE[@]}" exec -T php-fpm php bin/magento cache:flush
ok "Cache flushed"

VCL_LABEL="vcl_${TIMESTAMP}"
docker cp "$REPO_DIR/deploy/varnish/default.vcl" legal-varnish-1:/etc/varnish/default.vcl
docker exec legal-varnish-1 varnishadm vcl.load "$VCL_LABEL" /etc/varnish/default.vcl
docker exec legal-varnish-1 varnishadm vcl.use "$VCL_LABEL"
ok "Varnish VCL reloaded ($VCL_LABEL)"

# Prune old archives (keep 3) — via container; archived builds contain
# root-owned files from the build container (see Step 2), so the host user
# may not have permission to remove them directly.
docker run --rm -v "$ARCHIVE_STORE:/archive" "$BUILD_IMAGE" \
  bash -c 'cd /archive && ls -dt -- *-prev 2>/dev/null | tail -n +4 | xargs -r rm -rf --'

echo -e "\n${GREEN}  Deploy $TIMESTAMP complete!${NC}"
echo    "  Rollback: ./scripts/rollback-server.sh"
