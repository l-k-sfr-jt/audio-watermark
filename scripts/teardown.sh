#!/usr/bin/env bash
# Delete everything this project created in AWS so it stops incurring any cost.
# Empties the S3 bucket first (CloudFormation can't delete a non-empty bucket),
# then deletes the stack (Lambda, API, SES identity, bucket, and the ECR repo).
#
# Usage:  ./scripts/teardown.sh
set -euo pipefail

REGION="${AWS_REGION:-eu-central-1}"
STACK="${STACK_NAME:-audio-watermark}"

if ! aws cloudformation describe-stacks --stack-name "$STACK" --region "$REGION" >/dev/null 2>&1; then
  echo "Stack '$STACK' not found in $REGION — nothing to tear down."
  exit 0
fi

BUCKET=$(aws cloudformation describe-stacks --stack-name "$STACK" --region "$REGION" \
  --query "Stacks[0].Outputs[?OutputKey=='BucketName'].OutputValue" --output text)

if [[ -n "$BUCKET" && "$BUCKET" != "None" ]]; then
  echo "==> Emptying s3://$BUCKET"
  aws s3 rm "s3://$BUCKET" --recursive --region "$REGION" || true
fi

echo "==> Deleting stack '${STACK}' (Lambda, API, SES identity, bucket, ECR repo)..."
sam delete --stack-name "$STACK" --region "$REGION" --no-prompts

echo "Done. All project resources removed."
