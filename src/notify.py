import boto3


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

    ses = boto3.client("ses")
    ses.send_email(
        Source=from_address,
        Destination={"ToAddresses": [to_address]},
        Message={
            "Subject": {"Data": subject, "Charset": "UTF-8"},
            "Body": {"Text": {"Data": body, "Charset": "UTF-8"}},
        },
    )
