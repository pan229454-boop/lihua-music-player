#!/bin/bash
pkill -f "php -S 127.0.0.1:8080" 2>/dev/null && echo "[web] 已停止" || echo "[web] 未在运行"
if mysqladmin --socket=/run/mysqld/mysqld.sock -uroot ping >/dev/null 2>&1; then
  mysqladmin --socket=/run/mysqld/mysqld.sock -uroot shutdown && echo "[db] 已停止"
else
  echo "[db] 未在运行"
fi
