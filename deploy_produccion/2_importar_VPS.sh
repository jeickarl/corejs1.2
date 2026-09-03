#!/bin/bash
set -e
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

echo "=== 1) Verificar herramientas ==="
command -v mysql || { echo "mysql client no encontrado (apt install mariadb-client)"; exit 1; }
command -v gunzip || { echo "gunzip no encontrado"; exit 1; }
command -v unzip || { echo "unzip no encontrado (apt install unzip)"; exit 1; }

if [ -z "$DB_USER" ]; then echo "Antes: export DB_USER=corejs_user DB_PASS=TuPassMySQL"; exit 1; fi
if [ -z "$DB_PASS" ]; then echo "Antes: export DB_USER=corejs_user DB_PASS=TuPassMySQL"; exit 1; fi

echo "=== 2) Importar core_master ==="
gunzip < sql/core_master.sql.gz | mysql -h127.0.0.1 -u"$DB_USER" -p"$DB_PASS" --default-character-set=utf8mb4

echo "=== 3) Importar core_tenant_000001 ==="
gunzip < sql/core_tenant_000001.sql.gz | mysql -h127.0.0.1 -u"$DB_USER" -p"$DB_PASS" --default-character-set=utf8mb4

echo "=== 4) Crear bases 000002..000010 (schema base) ==="
SCHEMA_FILE=$(mktemp /tmp/corejs_schema_XXXXXX.sql)
mysqldump -h127.0.0.1 -u"$DB_USER" -p"$DB_PASS" --no-data --routines --triggers core_tenant_000001 > "$SCHEMA_FILE"
for i in 2 3 4 5 6 7 8 9 10; do
  DB=$(printf "core_tenant_%06d" "$i")
  echo "  -> $DB"
  mysql -h127.0.0.1 -u"$DB_USER" -p"$DB_PASS" -e "CREATE DATABASE IF NOT EXISTS \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  mysql -h127.0.0.1 -u"$DB_USER" -p"$DB_PASS" --default-character-set=utf8mb4 "$DB" < "$SCHEMA_FILE"
done
rm -f "$SCHEMA_FILE"

echo "=== 5) Extraer media.zip en apps/backend/uploads ==="
TMP_MEDIA=$(mktemp -d /tmp/corejs_media_XXXXXX)
unzip -o -q media/corejs_media.zip -d "$TMP_MEDIA"
DEST="/var/www/corejs/apps/backend/uploads"
mkdir -p "$DEST"
cp -rf "$TMP_MEDIA/backend/uploads/." "$DEST/"
chown -R www-data:www-data "$DEST" 2>/dev/null || true
find "$DEST" -type f -exec chmod 644 {} \;
find "$DEST" -type d -exec chmod 755 {} \;
echo "  Extraido OK en $DEST"
rm -rf "$TMP_MEDIA"

echo ""
echo "OK - Importacion finalizada"
echo "Siguiente: cd /var/www/corejs && pm2 start deploy_produccion/3_pm2_ecosystem.config.cjs"
