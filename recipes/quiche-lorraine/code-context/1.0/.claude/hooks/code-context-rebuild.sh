#!/usr/bin/env bash
set -euo pipefail
cd "${CLAUDE_PROJECT_DIR:-$(pwd)}"

if [ -x vendor/bin/code-context ]; then
    echo "[code-context] Rebuilding index…"
    vendor/bin/code-context generate >/dev/null
fi
