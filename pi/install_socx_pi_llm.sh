#!/bin/sh
set -eu

APP_DIR="${SOCX_PI_APP_DIR:-/opt/socx-pi-llm}"
SIDECAR_DIR="${SOCX_PI_SIDECAR_DIR:-/opt/socx-pi-sidecar}"
SERVICE_FILE="/etc/systemd/system/socx-pi-llm.service"
SIDECAR_SERVICE_FILE="/etc/systemd/system/socx-pi-sidecar.service"
SRC_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"

if [ "$(id -u)" -ne 0 ]; then
    echo "Run with sudo: sudo sh pi/install_socx_pi_llm.sh" >&2
    exit 1
fi

install -d -m 0755 "$APP_DIR"
install -d -m 0755 "$SIDECAR_DIR"
install -m 0755 "$SRC_DIR/socx_pi_llm_orchestrator.py" "$APP_DIR/socx_pi_llm_orchestrator.py"
install -m 0644 "$SRC_DIR/requirements.txt" "$APP_DIR/requirements.txt"
[ -f "$SRC_DIR/socx_pi_sidecar.py" ] && install -m 0755 "$SRC_DIR/socx_pi_sidecar.py" "$SIDECAR_DIR/socx_pi_sidecar.py"
[ -f "$SRC_DIR/socx-pi-sidecar.service" ] && install -m 0644 "$SRC_DIR/socx-pi-sidecar.service" "$SIDECAR_SERVICE_FILE"

if command -v apt-get >/dev/null 2>&1; then
    apt-get update
    apt-get install -y python3 python3-venv python3-pip curl
fi

python3 -m venv "$APP_DIR/.venv"
"$APP_DIR/.venv/bin/python" -m pip install --upgrade pip
"$APP_DIR/.venv/bin/python" -m pip install -r "$APP_DIR/requirements.txt"

if ! command -v ollama >/dev/null 2>&1; then
    echo "Ollama is not installed. Install it from https://ollama.com/download/linux if you want local model roles."
else
    systemctl enable --now ollama >/dev/null 2>&1 || true
    sleep 1
    ollama pull "${SOCX_PI_CPU_TRIAGE_MODEL:-llama3.2:3b}" || true
    ollama pull "${SOCX_PI_CPU_EVIDENCE_MODEL:-qwen2.5:3b}" || true
    ollama pull "${SOCX_PI_CPU_ACTION_MODEL:-llama3.2:3b}" || true
fi

cat > "$SERVICE_FILE" <<EOF
[Unit]
Description=SOCX Raspberry Pi 3-LLM Orchestrator
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
WorkingDirectory=$APP_DIR
Environment=SOCX_PI_LLM_TRIAGE_MODEL=${SOCX_PI_LLM_TRIAGE_MODEL:-llama3.2:3b}
Environment=SOCX_PI_LLM_EVIDENCE_MODEL=${SOCX_PI_LLM_EVIDENCE_MODEL:-qwen2.5-instruct:1.5b}
Environment=SOCX_PI_LLM_ACTION_MODEL=${SOCX_PI_LLM_ACTION_MODEL:-qwen2.5-coder:1.5b}
Environment=SOCX_PI_LLM_TIMEOUT=${SOCX_PI_LLM_TIMEOUT:-75}
Environment=SOCX_PI_HAILO_CHAT_URL=${SOCX_PI_HAILO_CHAT_URL:-http://127.0.0.1:8000/api/chat}
Environment=SOCX_PI_HAILO_TIMEOUT=${SOCX_PI_HAILO_TIMEOUT:-150}
Environment=SOCX_PI_LLM_CONCURRENCY=${SOCX_PI_LLM_CONCURRENCY:-1}
Environment=SOCX_PI_LLM_NUM_PREDICT=${SOCX_PI_LLM_NUM_PREDICT:-96}
Environment=SOCX_PI_LLM_NUM_CTX=${SOCX_PI_LLM_NUM_CTX:-2048}
ExecStart=$APP_DIR/.venv/bin/python -m uvicorn socx_pi_llm_orchestrator:app --host 0.0.0.0 --port 8095
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable --now socx-pi-llm
if [ -f "$SIDECAR_SERVICE_FILE" ]; then
    systemctl enable --now socx-pi-sidecar >/dev/null 2>&1 || true
fi
sleep 2
systemctl --no-pager status socx-pi-llm || true
curl -fsS http://127.0.0.1:8095/health || true
echo
pi_ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
echo "SOCX Pi dashboard: http://${pi_ip:-127.0.0.1}:8095/dashboard"
if command -v ollama >/dev/null 2>&1 && curl -fsS http://127.0.0.1:11434/api/tags >/dev/null 2>&1; then
    echo "Ollama is reachable on 127.0.0.1:11434; SOCX model roles can run."
else
    echo "WARNING: Ollama is not reachable on 127.0.0.1:11434. SOCX Pi service will answer, but roles will show 0/3 until Ollama is installed and running."
    echo "Repair on Pi: curl -fsSL https://ollama.com/install.sh | sh && sudo systemctl enable --now ollama"
fi
