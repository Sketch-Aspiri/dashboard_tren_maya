#!/usr/bin/env bash
# Despliegue del Dashboard Jefe de Zona en el VPS (Ubuntu 24.04 + CloudPanel).
# Ejecutar como el USUARIO DEL SITIO (nunca root), dentro del directorio del
# proyecto, por SSH a través de Tailscale:
#
#     bash deploy/vps/deploy.sh
#
# Antes de migrar hace un respaldo (mysqldump + documentos) y ABORTA si falla.
# Si algo falla después de iniciar la migración, el sitio se queda en
# mantenimiento a propósito: revisa el respaldo antes de volver a abrirlo.
set -Eeuo pipefail

RAMA="${RAMA:-master}"
DIR_RESPALDOS="${DIR_RESPALDOS:-$HOME/respaldos}"
ESTAMPA="$(date +%Y%m%d-%H%M%S)"
MIGRACION_INICIADA=0

cd "$(dirname "$0")/../.."

[ "$(id -u)" -ne 0 ] || { echo "No ejecutes el despliegue como root."; exit 1; }
[ -f .env ] || { echo "Falta .env (copia deploy/vps/env.produccion.example)."; exit 1; }

env_valor() { grep -E "^$1=" .env | head -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }

[ "$(env_valor APP_ENV)" = "production" ]  || { echo "APP_ENV debe ser production."; exit 1; }
[ "$(env_valor APP_DEBUG)" = "false" ]     || { echo "APP_DEBUG debe ser false."; exit 1; }
[ "$(env_valor TWO_FACTOR_ENABLED)" != "false" ] || echo "AVISO: TWO_FACTOR_ENABLED=false. NO abras el dominio al público así."

al_fallar() {
    if [ "$MIGRACION_INICIADA" -eq 1 ]; then
        echo "FALLÓ después de iniciar la migración. El sitio queda en mantenimiento."
        echo "Respaldo previo: $DIR_RESPALDOS/db-$ESTAMPA.sql.gz"
    else
        echo "FALLÓ antes de migrar. Se reabre el sitio sin cambios de base de datos."
        php artisan up || true
    fi
}
trap al_fallar ERR

respaldar() {
    mkdir -p "$DIR_RESPALDOS"
    chmod 700 "$DIR_RESPALDOS"
    local cnf
    cnf="$(mktemp)"
    trap 'rm -f "$cnf"' RETURN
    chmod 600 "$cnf"
    printf '[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n' \
        "$(env_valor DB_USERNAME)" "$(env_valor DB_PASSWORD)" \
        "$(env_valor DB_HOST)" "$(env_valor DB_PORT)" > "$cnf"
    mysqldump --defaults-extra-file="$cnf" --single-transaction --routines --hex-blob \
        "$(env_valor DB_DATABASE)" | gzip > "$DIR_RESPALDOS/db-$ESTAMPA.sql.gz"
    [ -s "$DIR_RESPALDOS/db-$ESTAMPA.sql.gz" ] || { echo "Respaldo de BD vacío."; return 1; }
    gzip -t "$DIR_RESPALDOS/db-$ESTAMPA.sql.gz"
    if [ -d storage/app/private ]; then
        tar -czf "$DIR_RESPALDOS/docs-$ESTAMPA.tar.gz" -C storage/app private
    fi
    echo "Respaldo listo en $DIR_RESPALDOS (estampa $ESTAMPA)."
}

echo "==> 1/8 Respaldo previo"
respaldar

echo "==> 2/8 Modo mantenimiento"
php artisan down --retry=60

echo "==> 3/8 Código ($RAMA)"
git fetch origin "$RAMA"
git merge --ff-only "origin/$RAMA"

echo "==> 4/8 Dependencias PHP"
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> 5/8 Assets"
npm ci
npm run build

echo "==> 6/8 Migraciones"
MIGRACION_INICIADA=1
php artisan migrate --force

echo "==> 7/8 Cachés y cola"
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart

echo "==> 8/8 Abrir el sitio"
php artisan up
MIGRACION_INICIADA=0

echo "Listo. Verifica: curl -fsS $(env_valor APP_URL)/up"
