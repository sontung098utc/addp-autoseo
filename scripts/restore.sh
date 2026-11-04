#!/usr/bin/env bash
set -euo pipefail

# Script phục hồi cơ sở dữ liệu autoseo sang môi trường kiểm chứng
if [ "$#" -ne 1 ]; then
    echo "Usage: $0 <path_to_backup_file.sql.gz>"
    exit 1
fi

BACKUP_FILE="$1"

if [ ! -f "${BACKUP_FILE}" ]; then
    echo "[ERROR] Không tìm thấy file sao lưu: ${BACKUP_FILE}"
    exit 1
fi

echo "[WARNING] Thao tác này sẽ ghi đè toàn bộ dữ liệu CSDL autoseo!"
echo "[INFO] Đang tiến hành khôi phục từ: ${BACKUP_FILE}..."

START_TIME=$(date +%s)
gunzip -c "${BACKUP_FILE}" | docker-compose exec -T db mariadb -u "${DB_USERNAME:-autoseo_user}" -p"${DB_PASSWORD:-autoseo_secret}" "${DB_DATABASE:-autoseo}"
END_TIME=$(date +%s)

DURATION=$((END_TIME - START_TIME))
echo "[SUCCESS] Phục hồi CSDL thành công trong ${DURATION} giây."
