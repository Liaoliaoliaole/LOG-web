#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${1:-http://127.0.0.1}"
API="${BASE_URL%/}/backend/api_channels.php"
XML_PATH="${MORFEAS_XML_PATH:-/home/morfeas/configuration/OPC_UA_Config.xml}"
TC16_SOURCE_ISO="${TC16_SOURCE_ISO:-}"

WORKDIR="$(mktemp -d)"
trap 'rm -rf "$WORKDIR"' EXIT

failures=0

extract_http_status() {
  awk 'toupper($1) ~ /^HTTP\// {code=$2} END {print code+0}' "$1"
}

extract_json_code() {
  local body="$1"
  if command -v jq >/dev/null 2>&1; then
    jq -r '.code // empty' "$body" 2>/dev/null || true
  else
    sed -n 's/.*"code"[[:space:]]*:[[:space:]]*"\([^"]\+\)".*/\1/p' "$body" | head -n1
  fi
}

run_case() {
  local name="$1"
  local method="$2"
  local url="$3"
  local payload="$4"
  local expect_status="$5"
  local expect_codes_csv="$6"

  local hdr="$WORKDIR/${name}.hdr"
  local body="$WORKDIR/${name}.json"

  if [[ -n "$payload" ]]; then
    curl -sS -D "$hdr" -o "$body" -X "$method" "$url" \
      -H 'Content-Type: application/json' \
      -H 'Accept: application/json' \
      --data "$payload" >/dev/null
  else
    curl -sS -D "$hdr" -o "$body" -X "$method" "$url" \
      -H 'Accept: application/json' >/dev/null
  fi

  local status
  status="$(extract_http_status "$hdr")"
  local code
  code="$(extract_json_code "$body")"

  local ok=1
  if [[ "$status" != "$expect_status" ]]; then
    ok=0
  fi

  local code_match=0
  IFS=',' read -r -a expect_codes <<<"$expect_codes_csv"
  for c in "${expect_codes[@]}"; do
    if [[ "$code" == "$c" ]]; then
      code_match=1
      break
    fi
  done
  if [[ $code_match -ne 1 ]]; then
    ok=0
  fi

  if [[ $ok -eq 1 ]]; then
    echo "[PASS] $name -> HTTP $status code=$code"
  else
    echo "[FAIL] $name -> HTTP $status code=$code (expect HTTP $expect_status code in [$expect_codes_csv])"
    echo "--- response body ($name) ---"
    cat "$body" || true
    echo
    failures=$((failures + 1))
  fi
}

hash_file_or_na() {
  local path="$1"
  if [[ -f "$path" ]]; then
    sha256sum "$path" | awk '{print $1}'
  else
    echo "NA"
  fi
}

echo "TC16 API regression smoke"
echo "API: $API"

run_case "method_guard_tc16_replace" "GET" "${API}?include=tc16_replace" "" "405" "tc16_method_not_allowed"
run_case "missing_source_iso_candidates" "GET" "${API}?include=tc16_candidates" "" "400" "missing_source_iso"
run_case "missing_source_iso_replace" "POST" "${API}?include=tc16_replace" '{}' "400" "missing_source_iso"
run_case "missing_target_key_replace" "POST" "${API}?include=tc16_replace" '{"source_iso":"_T9999"}' "400" "missing_target_key"

if [[ -n "$TC16_SOURCE_ISO" ]]; then
  echo "Running optional atomicity check with TC16_SOURCE_ISO=$TC16_SOURCE_ISO"
  before_hash="$(hash_file_or_na "$XML_PATH")"
  run_case "target_not_found_replace" "POST" "${API}?include=tc16_replace" "{\"source_iso\":\"$TC16_SOURCE_ISO\",\"target_key\":\"CAN0.ADDR:250\"}" "409" "tc16_target_not_found,tc16_source_unresolvable,tc16_source_not_offline,tc16_subtype_mismatch,tc16_source_not_full"
  after_hash="$(hash_file_or_na "$XML_PATH")"

  if [[ "$before_hash" != "$after_hash" ]]; then
    echo "[FAIL] atomicity_xml_unchanged -> XML hash changed on failed replace"
    failures=$((failures + 1))
  else
    echo "[PASS] atomicity_xml_unchanged -> XML unchanged on failed replace"
  fi
else
  echo "[INFO] Skip optional atomicity check (set TC16_SOURCE_ISO to enable)."
fi

if [[ $failures -gt 0 ]]; then
  echo "TC16 regression FAILED ($failures case(s))"
  exit 1
fi

echo "TC16 regression PASSED"
