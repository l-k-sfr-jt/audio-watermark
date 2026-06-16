#!/usr/bin/env bash
# Build the container image and deploy the whole stack (S3 + Lambda + API).
#
# Usage:  ./scripts/deploy.sh
set -euo pipefail

REGION="${AWS_REGION:-eu-central-1}"
STACK="${STACK_NAME:-audio-watermark}"

cd "$(dirname "$0")/.."

echo "==> Building container image (this is slow the first time)..."
sam build

echo
echo "==> Deploying stack '${STACK}' to ${REGION}..."
# --guided on the very first deploy lets SAM record any missing settings; on
# subsequent runs samconfig.toml supplies them and this is non-interactive.
# --no-disable-rollback is forced on the command line so a stale local
# samconfig.toml can't silently disable rollback. With rollback enabled, a
# failed update reverts to the last good state instead of getting stuck in
# UPDATE_FAILED, and CloudFormation is permitted to perform replacement-type updates.
if aws cloudformation describe-stacks --stack-name "$STACK" --region "$REGION" >/dev/null 2>&1; then
  sam deploy --region "$REGION" --stack-name "$STACK" \
    --no-disable-rollback
else
  sam deploy --guided --region "$REGION" --stack-name "$STACK" \
    --no-disable-rollback
fi

echo
echo "==> Stack outputs:"
aws cloudformation describe-stacks --stack-name "$STACK" --region "$REGION" \
  --query "Stacks[0].Outputs" --output table

echo
API_BASE=$(aws cloudformation describe-stacks --stack-name "$STACK" --region "$REGION" \
  --query "Stacks[0].Outputs[?OutputKey=='ApiBaseUrl'].OutputValue" --output text)
API_KEY_ID=$(aws cloudformation describe-stacks --stack-name "$STACK" --region "$REGION" \
  --query "Stacks[0].Outputs[?OutputKey=='ApiKeyId'].OutputValue" --output text)
API_KEY=$(aws apigateway get-api-keys --include-values \
  --query "items[?id=='${API_KEY_ID}'].value" --output text --region "$REGION")

echo "Configure these in your WooCommerce plugin (WooCommerce → Settings → Audiobook WM):"
echo "  API base URL : $API_BASE"
echo "  API key      : $API_KEY"
echo
echo "Next: run a smoke test:"
echo "  ./scripts/smoke-test.sh path/to/audiobook.wav 123"
