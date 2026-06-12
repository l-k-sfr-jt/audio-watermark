#!/usr/bin/env bash
# Build the container image and deploy the whole stack (S3 + SES + Lambda + API).
#
# Usage:  ./scripts/deploy.sh <sender-email>
# Example: ./scripts/deploy.sh noreply@yourdomain.com
set -euo pipefail

SENDER="${1:-}"
REGION="${AWS_REGION:-eu-central-1}"
STACK="${STACK_NAME:-audio-watermark}"

if [[ -z "$SENDER" ]]; then
  echo "Usage: $0 <sender-email>" >&2
  echo "  The sender address SES will verify (you'll click a link in its inbox)." >&2
  exit 1
fi

cd "$(dirname "$0")/.."

echo "==> Building container image (this is slow the first time)…"
sam build

echo
echo "==> Deploying stack '$STACK' to $REGION…"
# --guided on the very first deploy lets SAM record any missing settings; on
# subsequent runs samconfig.toml supplies them and this is non-interactive.
if aws cloudformation describe-stacks --stack-name "$STACK" --region "$REGION" >/dev/null 2>&1; then
  sam deploy --region "$REGION" --stack-name "$STACK" \
    --parameter-overrides "SesFromEmail=$SENDER"
else
  sam deploy --guided --region "$REGION" --stack-name "$STACK" \
    --parameter-overrides "SesFromEmail=$SENDER"
fi

echo
echo "==> Stack outputs:"
aws cloudformation describe-stacks --stack-name "$STACK" --region "$REGION" \
  --query "Stacks[0].Outputs" --output table

echo
echo "IMPORTANT — check the inbox for $SENDER and click the AWS SES verification"
echo "link. Until then, the Lambda cannot send email."
echo
echo "Next: verify a test recipient, then run a smoke test:"
echo "  ./scripts/verify-recipient.sh you@example.com"
echo "  ./scripts/smoke-test.sh path/to/audiobook.mp3 you@example.com"
