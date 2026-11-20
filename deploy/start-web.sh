#!/bin/bash
# 启动站点（PHP 内置服务器），用法: bash deploy/start-web.sh [地址] [端口]
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
HOST="${1:-127.0.0.1}"
PORT="${2:-8080}"
LOG=/tmp/lihua-php-server.log
if pgrep -f "php -S ${HOST}:${PORT}" >/dev/null 2>&1; then
  echo "[web] 站点已在运行: http://${HOST}:${PORT}"; exit 0
fi
chmod -R u+rwX "$APP_DIR/runtime" 2>/dev/null
PHP_CLI_SERVER_WORKERS=4 setsid php -S "${HOST}:${PORT}" -t "$APP_DIR/public" "$APP_DIR/public/router.php" \
  < /dev/null > "$LOG" 2>&1 &
sleep 2
if curl -s -o /dev/null -w '%{http_code}' "http://${HOST}:${PORT}/" | grep -q 200; then
  echo "[web] 站点已启动: http://${HOST}:${PORT}  (日志 $LOG)"
else
  echo "[web] 启动异常，见 $LOG"; exit 1
fi
