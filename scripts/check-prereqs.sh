#!/usr/bin/env bash
# Verify your Mac has everything needed to deploy, before you run deploy.sh.
set -euo pipefail

REGION="${AWS_REGION:-eu-central-1}"
ok=true

check() {
  if command -v "$1" >/dev/null 2>&1; then
    echo "  ✓ $1 found"
  else
    echo "  ✗ $1 MISSING — $2"
    ok=false
  fi
}

echo "Checking required tools:"
check aws    "install with: brew install awscli"
check sam    "install with: brew install aws-sam-cli"
check docker "install Docker Desktop: https://www.docker.com/products/docker-desktop/"

echo
echo "Checking Docker is running:"
if docker info >/dev/null 2>&1; then
  echo "  ✓ Docker daemon is up"
else
  echo "  ✗ Docker daemon not running — start Docker Desktop"
  ok=false
fi

echo
echo "Checking AWS credentials (region: $REGION):"
if aws sts get-caller-identity --region "$REGION" >/dev/null 2>&1; then
  acct=$(aws sts get-caller-identity --region "$REGION" --query Account --output text)
  echo "  ✓ Authenticated to AWS account $acct"
else
  echo "  ✗ Not authenticated — run: aws configure   (see docs/DEPLOYMENT.md)"
  ok=false
fi

echo
if $ok; then
  echo "All prerequisites satisfied. Next: ./scripts/deploy.sh you@example.com"
else
  echo "Some prerequisites are missing. Fix the ✗ items above, then re-run."
  exit 1
fi
