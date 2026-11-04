#!/usr/bin/env bash
set -euo pipefail

# Script sao lưu cơ sở dữ liệu và tài nguyên cho dự án AutoSEO
BACKUP_DIR="${BACKUP_DIR:-./backups}"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
BACKUP_FILE="${BACKUP_DIR}/autoseo_db_${TIMESTAMP}.sql.gz"

mkdir -p "${BACKUP_DIR}"

echo "[INFO] Đang bắt đầu sao lưu CSDL autoseo tại $(date)..."
docker-compose exec -T db mariadb-dump -u "${DB_USERNAME:-autoseo_user}" -p"${DB_PASSWORD:-autoseo_secret}" "${DB_DATABASE:-autoseo}" | gzip > "${BACKUP_FILE}"

echo "[SUCCESS] Đã sao lưu thành công vào: ${BACKUP_FILE}"
ls -lh "${BACKUP_FILE}"

# Giữ lại 7 bản sao lưu gần nhất (Retention policy)
find "${BACKUP_DIR}" -name "autoseo_db_*.sql.gz" -mtime +7 -delete || true
echo "[INFO] Đã dọn dẹp các bản sao lưu cũ hơn 7 ngày."
