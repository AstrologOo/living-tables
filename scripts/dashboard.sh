#!/bin/bash
# One-screen status of the local compose stack: worker memory/CPU, RabbitMQ
# queues and connections, nodes with their recent runs and notifications, and
# the worker log.
#
# Usage:
#   ./scripts/dashboard.sh [--loop] [name-like-pattern]
#
#   ./scripts/dashboard.sh                  # all nodes, once
#   ./scripts/dashboard.sh 'Shop:%'         # only matching nodes
#   ./scripts/dashboard.sh --loop 'Shop:%'  # refreshed every 2 s
#
# DASHBOARD_DB reads another database than the stack's own.

set -euo pipefail

cd "$(dirname "$0")/.."

LOOP=0
if [ "${1:-}" = "--loop" ]; then
  LOOP=1
  shift
fi
PATTERN="${1:-%}"

query() {
  docker compose exec -T postgres sh -c 'psql -U "$POSTGRES_USER" -d "${1:-$POSTGRES_DB}" -c "$2"' sh "${DASHBOARD_DB:-}" "$1" 2>/dev/null \
    || echo "(database query failed)"
}

render() {
  echo "=== Worker (memory/CPU) ==="
  local worker
  worker=$(docker compose ps -q worker 2>/dev/null)
  if [ -n "$worker" ]; then
    docker stats "$worker" --no-stream --format "mem={{.MemUsage}}  cpu={{.CPUPerc}}"
  else
    echo "(worker container not running)"
  fi

  echo
  echo "=== RabbitMQ queues ==="
  docker compose exec -T rabbitmq rabbitmqctl list_queues name messages consumers 2>/dev/null | tail -n +2

  echo
  echo "=== RabbitMQ connections ==="
  docker compose exec -T rabbitmq rabbitmqctl list_connections name state 2>/dev/null | tail -n +2

  echo
  echo "=== Nodes matching '$PATTERN' ==="
  query "
  select id, name, status, consecutive_failures, retry_at, updated_at
  from nodes where name like '$PATTERN' order by name;
  "

  echo "=== Recent run_stats (last 20, matching nodes) ==="
  query "
  select n.name, r.status, r.rows_total, r.rows_changed, r.tokens_spent, r.duration_ms, r.created_at
  from run_stats r join nodes n on n.id = r.node_id
  where n.name like '$PATTERN'
  order by r.created_at desc limit 20;
  "

  echo "=== Recent notifications (last 10, matching nodes) ==="
  query "
  select n.name, nn.type, nn.payload, nn.created_at
  from node_notifications nn join nodes n on n.id = nn.node_id
  where n.name like '$PATTERN'
  order by nn.created_at desc limit 10;
  "

  echo "=== Worker log, last 30s (empty = idle, not stuck) ==="
  docker compose logs worker --since=30s 2>/dev/null | tail -20

  echo
  echo "=== Errors/exceptions in worker logs (last 5 min) ==="
  docker compose logs worker --since=5m 2>/dev/null | grep -i "error\|exception" | tail -10 || echo "(none)"
}

if [ "$LOOP" = "1" ]; then
  while true; do
    clear
    date
    echo
    render
    sleep 2
  done
else
  render
fi
