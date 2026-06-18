#!/usr/bin/env bash
# Build the container image and deploy the whole stack (S3 + Lambda + API + CloudFront).
#
# Usage:  ./scripts/deploy.sh
set -euo pipefail

REGION="${AWS_REGION:-eu-central-1}"
STACK="${STACK_NAME:-audio-watermark}"

cd "$(dirname "$0")/.."

# ── CloudFront signing key bootstrap ─────────────────────────────────────────
# CloudFront signed URLs require an RSA 2048-bit key pair.  The private key is
# stored as an SSM SecureString (never leaves AWS); the public key is stored as
# a plain SSM String so CloudFormation can read it via {{resolve:ssm:...}}.
#
# This block is idempotent: if the SSM parameters already exist the key pair is
# not regenerated.  To rotate the key: delete both SSM parameters and redeploy.
CF_PRIV_PARAM="/${STACK}/cf-private-key"
CF_PUB_PARAM="/${STACK}/cf-public-key"

if ! aws ssm get-parameter --name "$CF_PRIV_PARAM" --region "$REGION" \
       --query Parameter.Name --output text >/dev/null 2>&1; then
  echo "==> CloudFront key pair not found — generating RSA 2048-bit key pair..."
  TMPDIR_CF=$(mktemp -d)
  openssl genrsa 2048 2>/dev/null > "$TMPDIR_CF/cf_private.pem"
  openssl rsa -pubout -in "$TMPDIR_CF/cf_private.pem" \
              -out "$TMPDIR_CF/cf_public.pem" 2>/dev/null

  aws ssm put-parameter \
    --name "$CF_PRIV_PARAM" \
    --type SecureString \
    --value "$(cat "$TMPDIR_CF/cf_private.pem")" \
    --description "CloudFront signing private key — ${STACK}" \
    --region "$REGION" >/dev/null
  aws ssm put-parameter \
    --name "$CF_PUB_PARAM" \
    --type String \
    --value "$(cat "$TMPDIR_CF/cf_public.pem")" \
    --description "CloudFront signing public key — ${STACK}" \
    --region "$REGION" >/dev/null

  rm -rf "$TMPDIR_CF"
  echo "==> CloudFront key pair stored in SSM ($CF_PRIV_PARAM / $CF_PUB_PARAM)."
else
  echo "==> CloudFront key pair already exists in SSM."
fi

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
PARAM_OVERRIDES="CFPrivateKeyParam=${CF_PRIV_PARAM} CFPublicKeyParam=${CF_PUB_PARAM}"

if aws cloudformation describe-stacks --stack-name "$STACK" --region "$REGION" >/dev/null 2>&1; then
  sam deploy --region "$REGION" --stack-name "$STACK" \
    --no-disable-rollback \
    --parameter-overrides "$PARAM_OVERRIDES"
else
  sam deploy --guided --region "$REGION" --stack-name "$STACK" \
    --no-disable-rollback \
    --parameter-overrides "$PARAM_OVERRIDES"
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
