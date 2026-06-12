#!/usr/bin/env bash
# While your SES account is in the sandbox (the default for new accounts), you
# can only send email TO addresses you've verified. Use this to verify a test
# recipient so smoke-test.sh can deliver the download link to your own inbox.
#
# Usage:  ./scripts/verify-recipient.sh <recipient-email>
set -euo pipefail

EMAIL="${1:-}"
REGION="${AWS_REGION:-eu-central-1}"

if [[ -z "$EMAIL" ]]; then
  echo "Usage: $0 <recipient-email>" >&2
  exit 1
fi

aws ses verify-email-identity --email-address "$EMAIL" --region "$REGION"
echo "Verification email sent to $EMAIL. Click the link, then you can receive"
echo "watermark download links at this address while in the SES sandbox."
