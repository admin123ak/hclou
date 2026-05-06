#!/usr/bin/env bash
set -u
APP_DIR="/www/wwwroot/hclou.com"
LOG_FILE="$APP_DIR/data/mbbank_poll_loop.log"
INTERVAL="${MBBANK_POLL_INTERVAL:-5}"
mkdir -p "$APP_DIR/data"
cd "$APP_DIR"
echo "$(date '+%Y-%m-%d %H:%M:%S') mbbank poll loop started interval=${INTERVAL}s" >> "$LOG_FILE"
while true; do
  started=$(date +%s)
  out=$(php "$APP_DIR/mbbank_poll.php" 2>&1)
  code=$?
  echo "$(date '+%Y-%m-%d %H:%M:%S') code=${code} ${out}" >> "$LOG_FILE"
  elapsed=$(( $(date +%s) - started ))
  sleep_for=$(( INTERVAL - elapsed ))
  if [ "$sleep_for" -lt 1 ]; then sleep_for=1; fi
  sleep "$sleep_for"
done
