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
