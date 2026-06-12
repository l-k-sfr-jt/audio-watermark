import json
import logging
import os
import re
import tempfile

from src import notify, storage, watermark

logger = logging.getLogger(__name__)
logger.setLevel(logging.INFO)

# Stricter than the previous regex: requires 2+ char TLD, rejects consecutive
# dots and invalid leading/trailing chars in each label.
_EMAIL_RE = re.compile(
    r"^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9][a-zA-Z0-9\-]*(\.[a-zA-Z0-9\-]+)*\.[a-zA-Z]{2,}$"
)

# order_id must be alphanumeric + hyphens/underscores only — it is embedded
# directly in the S3 key, so arbitrary chars (slashes, spaces, dots) would
# create malformed keys or unexpected S3 "directories".
_ORDER_ID_RE = re.compile(r"^[A-Za-z0-9_\-]+$")


def _parse_event(event: dict) -> dict:
    """Extract and validate fields from the Lambda event (or API Gateway proxy)."""
    body = event.get("body", event)
    if isinstance(body, str):
        body = json.loads(body)

    s3_key = body.get("s3_key", "")
    user_id = body.get("user_id")
    email = body.get("email", "")
    order_id = body.get("order_id", "")

    # Coerce numeric floats sent by lenient serialisers (e.g. 4582.0 → 4582)
    if isinstance(user_id, float) and user_id.is_integer():
        user_id = int(user_id)

    errors = []

    if not s3_key:
        errors.append("s3_key is required")
    elif s3_key.startswith("/") or ".." in s3_key.split("/"):
        # Reject absolute paths and directory-traversal attempts.
        # S3 itself doesn't resolve ".." but downstream tooling might.
        errors.append("s3_key must be a relative path with no traversal (no '..' segments)")

    if not isinstance(user_id, int) or not (0 <= user_id < 2**32):
        errors.append("user_id must be a 32-bit non-negative integer")

    if not _EMAIL_RE.match(email):
        errors.append("email is not a valid address")

    if not order_id:
        errors.append("order_id is required")
    elif not _ORDER_ID_RE.match(order_id):
        errors.append("order_id must contain only letters, digits, hyphens, and underscores")

    if errors:
        raise ValueError("; ".join(errors))

    return {"s3_key": s3_key, "user_id": user_id, "email": email, "order_id": order_id}


def _error(status_code: int, message: str) -> dict:
    logger.error(message)
    return {"statusCode": status_code, "body": json.dumps({"error": message})}


def lambda_handler(event: dict, context) -> dict:  # noqa: ANN001
    bucket = os.environ.get("BUCKET_NAME", "")
    ses_from = os.environ.get("SES_FROM_EMAIL", "")

    if not bucket or not ses_from:
        return _error(500, "Missing required environment variables: BUCKET_NAME, SES_FROM_EMAIL")

    # 1. Validate input
    try:
        params = _parse_event(event)
    except (ValueError, KeyError, json.JSONDecodeError) as exc:
        return _error(400, f"Invalid input: {exc}")

    s3_key = params["s3_key"]
    user_id = params["user_id"]
    email = params["email"]
    order_id = params["order_id"]

    with tempfile.TemporaryDirectory() as tmp_dir:
        input_path = os.path.join(tmp_dir, "original")
        wm_path = os.path.join(tmp_dir, "watermarked.wav")
        upload_key = f"temp/{order_id}_{user_id}.wav"

        # 2. Download from S3
        try:
            storage.download_from_s3(bucket, s3_key, input_path)
        except Exception as exc:
            return _error(500, f"S3 download failed for key '{s3_key}': {exc}")

        # 3. Embed watermark
        try:
            watermark.embed_watermark(input_path, user_id, wm_path)
        except Exception as exc:
            return _error(500, f"Watermark embedding failed: {exc}")

        # 4. Upload result
        try:
            storage.upload_to_s3(wm_path, bucket, upload_key)
        except Exception as exc:
            return _error(500, f"S3 upload failed for key '{upload_key}': {exc}")

        # 5. Generate presigned URL (48 h)
        try:
            download_url = storage.generate_presigned_url(bucket, upload_key)
        except Exception as exc:
            return _error(500, f"Presigned URL generation failed: {exc}")

        # 6. Send email
        try:
            notify.send_download_email(email, ses_from, order_id, download_url)
        except Exception as exc:
            # Email failure is non-fatal: the file is already uploaded
            logger.warning("SES email failed (file still available): %s", exc)

    logger.info("Watermark job complete: order=%s user_id=%s", order_id, user_id)
    return {
        "statusCode": 200,
        "body": json.dumps({"status": "ok", "watermark_id": user_id}),
    }
