#!/usr/bin/env bash
set -e

cd "$(dirname "$0")"

PHP_BIN="../tools/php/php.exe"
if [ ! -x "$PHP_BIN" ]; then
    PHP_BIN="php"
fi

"$PHP_BIN" -S 127.0.0.1:18101 -t integrations/edu-data-exchange integrations/edu-data-exchange/router.php >/tmp/chengyue-exchange.log 2>&1 &
EXCHANGE_PID=$!

"$PHP_BIN" -S 127.0.0.1:18100 -t public >/tmp/chengyue-campus.log 2>&1 &
APP_PID=$!

echo "澄岳学生管理系统：http://127.0.0.1:18100"
echo "合作方学籍数据交换服务：http://127.0.0.1:18101/student-status.txt"
echo "按 Ctrl+C 停止服务。"

trap 'kill "$EXCHANGE_PID" "$APP_PID" 2>/dev/null || true' EXIT INT TERM
wait
