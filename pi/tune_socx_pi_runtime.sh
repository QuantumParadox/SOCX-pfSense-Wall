#!/bin/sh
# Applies SOCX Pi model-runtime defaults that avoid holding every CPU fallback in RAM.
set -eu

SERVICE=/etc/systemd/system/socx-pi-llm.service

if [ "$(id -u)" -ne 0 ]; then
    echo "Run with sudo: sudo sh pi/tune_socx_pi_runtime.sh" >&2
    exit 1
fi
if [ ! -f "$SERVICE" ]; then
    echo "SOCX Pi service is not installed: $SERVICE" >&2
    exit 1
fi

replace_setting() {
    key="$1"
    value="$2"
    if grep -q "^Environment=$key=" "$SERVICE"; then
        sed -i "s|^Environment=$key=.*|Environment=$key=$value|" "$SERVICE"
    else
        printf 'Environment=%s=%s\n' "$key" "$value" >> "$SERVICE"
    fi
}

replace_setting SOCX_PI_HAILO_TIMEOUT 8
replace_setting SOCX_PI_LLM_TIMEOUT 15
replace_setting SOCX_PI_ROLE_MAX_SECONDS 24
replace_setting SOCX_PI_TOTAL_TIMEOUT 80
replace_setting SOCX_PI_OLLAMA_KEEP_ALIVE 15m
replace_setting SOCX_PI_WARM_CPU_MODELS false

systemctl daemon-reload
systemctl restart socx-pi-llm
sleep 3
systemctl --no-pager --full status socx-pi-llm
curl -fsS --max-time 8 http://127.0.0.1:8095/health
