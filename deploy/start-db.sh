#!/bin/bash
# 启动 MariaDB（PRoot 容器适配）
SOCK=/run/mysqld/mysqld.sock
if mysqladmin --socket="$SOCK" -uroot ping >/dev/null 2>&1; then
  echo "[db] MariaDB 已在运行"; exit 0
fi
mkdir -p /run/mysqld /var/log/mysql
setsid mariadbd --user=root --datadir=/var/lib/mysql --socket="$SOCK" \
  --pid-file=/run/mysqld/mysqld.pid --port=3306 --bind-address=127.0.0.1 \
  --innodb_use_native_aio=0 --skip-name-resolve \
  --log-error=/var/log/mysql/error.log < /dev/null > /var/log/mysql/stdout.log 2>&1 &
for i in $(seq 1 20); do
  sleep 1
  if mysqladmin --socket="$SOCK" -uroot ping >/dev/null 2>&1; then
    echo "[db] MariaDB 已启动 (127.0.0.1:3306)"; exit 0
  fi
done
echo "[db] 启动失败，见 /var/log/mysql/error.log"; exit 1
