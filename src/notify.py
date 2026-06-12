import boto3
from botocore.config import Config

# Same retry/timeout posture as storage: retry transient SES errors, fail fast
# on hangs to respect the Lambda timeout.
_CONFIG = Config(
    retries={"total_max_attempts": 3, "mode": "standard"},
    connect_timeout=5,
    read_timeout=15,
)

# Single SES client, created lazily and reused (see storage._s3 for rationale).
_ses_client = None


def _ses():
    global _ses_client
    if _ses_client is None:
        _ses_client = boto3.client("ses", config=_CONFIG)
    return _ses_client


def send_download_email(
    to_address: str,
    from_address: str,
    order_id: str,
    download_url: str,
) -> None:
    """Send a plain-text download-ready email via SES."""
    subject = f"Your audiobook download is ready — order {order_id}"
    body = (
        f"Hello,\n\n"
        f"Your personalised audiobook for order {order_id} is ready.\n\n"
        f"Download link (valid for 48 hours):\n{download_url}\n\n"
        f"This link is unique to your purchase. Please do not share it.\n\n"
        f"Thank you for your order."
    )

    _ses().send_email(
        Source=from_address,
        Destination={"ToAddresses": [to_address]},
        Message={
            "Subject": {"Data": subject, "Charset": "UTF-8"},
            "Body": {"Text": {"Data": body, "Charset": "UTF-8"}},
        },
    )
