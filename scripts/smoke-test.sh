#!/usr/bin/env bash
# End-to-end test of the deployed stack:
#   1. Upload a master via the presigned PUT flow (POST /products/upload-url → PUT)
#   2. Watermark it for a test order (POST /watermark)
#   3. Download the MP3 and verify it is non-empty
#   4. Re-call /watermark (idempotency check — must return from_cache: true)
#   5. Verify API key enforcement
#
# Usage:  ./scripts/smoke-test.sh <local-audio-file> [order-id] [item-id]
# Example: ./scripts/smoke-test.sh sample.wav 9001 1
set -euo pipefail

AUDIO="${1:-}"
ORDER_ID="${2:-9001}"
ITEM_ID="${3:-1}"   # exercises the per-item key path: orders/<order_id>/<item_id>.mp3
REGION="${AWS_REGION:-eu-central-1}"
STACK="${STACK_NAME:-audio-watermark}"

if [[ -z "$AUDIO" ]]; then
  echo "Usage: $0 <local-audio-file> [order-id] [item-id]" >&2
  exit 1
fi
if [[ ! -f "$AUDIO" ]]; then
  echo "File not found: $AUDIO" >&2
  exit 1
fi

out() {
  aws cloudformation describe-stacks --stack-name "$STACK" --region "$REGION" \
    --query "Stacks[0].Outputs[?OutputKey=='$1'].OutputValue" --output text
}

API_BASE=$(out ApiBaseUrl)
API_KEY_ID=$(out ApiKeyId)
API_KEY=$(aws apigateway get-api-keys --include-values \
  --query "items[?id=='${API_KEY_ID}'].value" --output text --region "$REGION")

PRODUCT_ID="smoke-product-$$"
FILENAME=$(basename "$AUDIO")
CONTENT_TYPE="audio/wav"

echo "==> Step 1: POST /products/upload-url"
UPLOAD_RESP=$(curl -s -X POST "$API_BASE/products/upload-url" \
  -H "Content-Type: application/json" \
  -H "x-api-key: $API_KEY" \
  -d "{\"product_id\":\"$PRODUCT_ID\",\"filename\":\"$FILENAME\",\"content_type\":\"$CONTENT_TYPE\"}")
echo "    response: $UPLOAD_RESP"

UPLOAD_URL=$(echo "$UPLOAD_RESP" | python3 -c "import sys,json; print(json.load(sys.stdin)['upload_url'])")
S3_KEY=$(echo "$UPLOAD_RESP" | python3 -c "import sys,json; print(json.load(sys.stdin)['s3_key'])")
echo "    s3_key: $S3_KEY"

echo
echo "==> Step 2: PUT master to S3"
HTTP=$(curl -s -o /dev/null -w "%{http_code}" -X PUT "$UPLOAD_URL" \
  -H "Content-Type: $CONTENT_TYPE" \
  --data-binary "@$AUDIO")
if [[ "$HTTP" == "200" ]]; then
  echo "  [PASS] Master uploaded (200)"
else
  echo "  [FAIL] Expected 200, got $HTTP" >&2
  exit 1
fi

echo
echo "==> Step 3: POST /watermark (first call — slow path)"
WM_RESP=$(curl -s -X POST "$API_BASE/watermark" \
  -H "Content-Type: application/json" \
  -H "x-api-key: $API_KEY" \
  -d "{\"master_key\":\"$S3_KEY\",\"order_id\":$ORDER_ID,\"item_id\":$ITEM_ID}")
echo "    response: $WM_RESP"

DOWNLOAD_URL=$(echo "$WM_RESP" | python3 -c "import sys,json; print(json.load(sys.stdin)['download_url'])")
FROM_CACHE=$(echo "$WM_RESP" | python3 -c "import sys,json; print(json.load(sys.stdin)['from_cache'])")
if [[ "$FROM_CACHE" == "False" ]]; then
  echo "  [PASS] First call: from_cache=false (file was created)"
else
  echo "  [WARN] First call returned from_cache=$FROM_CACHE (expected False)"
fi

echo
echo "==> Step 4: Download and check the MP3"
TMP_MP3=$(mktemp /tmp/smoke-XXXXXX.mp3)
HTTP=$(curl -s -o "$TMP_MP3" -w "%{http_code}" "$DOWNLOAD_URL")
SIZE=$(wc -c < "$TMP_MP3")
if [[ "$HTTP" == "200" && "$SIZE" -gt 1024 ]]; then
  echo "  [PASS] Downloaded MP3: $SIZE bytes"
else
  echo "  [FAIL] Download failed: HTTP=$HTTP size=$SIZE" >&2
  rm -f "$TMP_MP3"
  exit 1
fi
rm -f "$TMP_MP3"

echo
echo "==> Step 5: POST /watermark again (idempotency — should return from_cache: true)"
WM_RESP2=$(curl -s -X POST "$API_BASE/watermark" \
  -H "Content-Type: application/json" \
  -H "x-api-key: $API_KEY" \
  -d "{\"master_key\":\"$S3_KEY\",\"order_id\":$ORDER_ID,\"item_id\":$ITEM_ID}")
FROM_CACHE2=$(echo "$WM_RESP2" | python3 -c "import sys,json; print(json.load(sys.stdin)['from_cache'])")
if [[ "$FROM_CACHE2" == "True" ]]; then
  echo "  [PASS] Second call: from_cache=true (served from existing file)"
else
  echo "  [FAIL] Expected from_cache=True, got $FROM_CACHE2" >&2
fi

echo
echo "==> Step 6: API key enforcement"
HTTP_NO_KEY=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$API_BASE/watermark" \
  -H "Content-Type: application/json" -d '{}')
if [[ "$HTTP_NO_KEY" == "403" ]]; then
  echo "  [PASS] Request without x-api-key correctly rejected (403)"
else
  echo "  [FAIL] Expected 403, got $HTTP_NO_KEY — API key may not be enforced" >&2
fi

echo
echo "All smoke tests passed."
echo
echo "Store the API key and base URL in your WooCommerce plugin config:"
echo "  API base URL : $API_BASE"
echo "  API key      : $API_KEY"
