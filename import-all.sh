#!/usr/bin/env bash
# import-all.sh — Importa todos os tipos de conteúdo sequencialmente
# Uso: bash import-all.sh
# Background: nohup bash import-all.sh > storage/logs/import.log 2>&1 &

set -euo pipefail

LOG_FILE="storage/logs/import-$(date +%Y%m%d-%H%M%S).log"
PAGES=1000

log() { echo "[$(date '+%H:%M:%S')] $*" | tee -a "$LOG_FILE"; }

log "========================================"
log "  Importação em lote iniciada"
log "  Páginas: $PAGES"
log "========================================"

# ── 1. Anime + Manga + Movie + TV (um único comando importa tudo) ──────────────
log ""
log "[1/2] Importando anime, manga, movie, tv..."
php artisan content:import \
    --pages="$PAGES" \
    --force \
    --details \
    2>&1 | tee -a "$LOG_FILE"

# ── 2. Manhwa e Manhua (origin separado dentro do tipo manga) ─────────────────
log ""
log "[2/2] Re-importando manga com prioridade manhwa (origin detection)..."
php artisan content:import \
    --type=manga \
    --origin=manhwa \
    --pages="$PAGES" \
    --force \
    2>&1 | tee -a "$LOG_FILE"

log ""
log "========================================"
log "  Importação concluída."
log "  Log salvo em: $LOG_FILE"
log "========================================"
