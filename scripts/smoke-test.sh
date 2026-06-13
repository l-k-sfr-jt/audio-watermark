#!/usr/bin/env bash
# End-to-end test of the deployed stack: uploads a master, invokes the Lambda,
# and prints the response. If it returns status "ok", check the recipient inbox
# for the download link.
#
# Usage:  ./scripts/smoke-test.sh <local-audio-file> <recipient-email> [user_id]
set -euo pipefail

AUDIO="${1:-}"
RECIPIENT="${2:-}"
USER_ID="${3:-4582}"
REGION="${AWS_REGION:-eu-central-1}"
STACK="${STACK_NAME:-audio-watermark}"

if [[ -z "$AUDIO" || -z "$RECIPIENT" ]]; then
  echo "Usage: $0 <local-audio-file> <recipient-email> [user_id]" >&2
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

BUCKET=$(out BucketName)
FUNCTION=$(out FunctionName)
API_URL=$(out ApiEndpoint)
API_KEY_ID=$(out ApiKeyId)
API_KEY=$(aws apigateway get-api-keys --include-values \
  --query "items[?id=='${API_KEY_ID}'].value" --output text --region "$REGION")
KEY="masters/$(basename "$AUDIO")"

echo "==> Uploading $AUDIO → s3://$BUCKET/$KEY"
aws s3 cp "$AUDIO" "s3://$BUCKET/$KEY" --region "$REGION"

PAYLOAD=$(printf '{"s3_key":"%s","user_id":%s,"email":"%s","order_id":"smoke-001"}' \
  "$KEY" "$USER_ID" "$RECIPIENT")

echo "==> Invoking $FUNCTION"
echo "    payload: $PAYLOAD"
aws lambda invoke \
  --function-name "$FUNCTION" \
  --region "$REGION" \
  --cli-binary-format raw-in-base64-out \
  --payload "$PAYLOAD" \
  /tmp/watermark-smoke-response.json >/dev/null

echo
echo "==> Lambda response:"
cat /tmp/watermark-smoke-response.json
echo
echo
echo "If the response shows \"status\": \"ok\", check the inbox for $RECIPIENT"
echo "for the watermarked download link (valid 48h)."

echo
echo "==> Verifying API key enforcement on $API_URL"
HTTP_NO_KEY=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$API_URL" \
  -H "Content-Type: application/json" -d '{}')
if [[ "$HTTP_NO_KEY" == "403" ]]; then
  echo "  [PASS] Request without x-api-key correctly rejected (403)"
else
  echo "  [FAIL] Expected 403, got $HTTP_NO_KEY — API key may not be enforced" >&2
fi

HTTP_WITH_KEY=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$API_URL" \
  -H "Content-Type: application/json" \
  -H "x-api-key: $API_KEY" \
  -d "$PAYLOAD")
if [[ "$HTTP_WITH_KEY" == "200" ]]; then
  echo "  [PASS] Request with x-api-key accepted (200)"
else
  echo "  [WARN] Request with x-api-key returned HTTP $HTTP_WITH_KEY"
fi

echo
echo "Store the API key below in your WooCommerce plugin config (Phase 5):"
echo "  $API_KEY"
