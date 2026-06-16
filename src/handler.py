import json
import logging
import os
import re
import tempfile

from botocore.exceptions import ClientError

from src import storage, watermark

logger = logging.getLogger(__name__)
logger.setLevel(logging.INFO)

_PRODUCT_ID_RE = re.compile(r"^[A-Za-z0-9_\-]+$")
# master_key must be a safe relative S3 path: no leading slash, no '..' segments.
_KEY_RE = re.compile(r"^[A-Za-z0-9_\-][A-Za-z0-9_./ \-]*$")


def _parse_body(event: dict) -> dict:
    body = event.get("body", event)
    if isinstance(body, str):
        body = json.loads(body)
    return body if isinstance(body, dict) else {}


def _error(status_code: int, message: str) -> dict:
    logger.error(message)
    return {
        "statusCode": status_code,
        "body": json.dumps({"error": message}),
        "headers": {"Content-Type": "application/json"},
    }


def _ok(payload: dict) -> dict:
    return {
        "statusCode": 200,
        "body": json.dumps(payload),
        "headers": {"Content-Type": "application/json"},
    }


def _safe_filename(name: str) -> str:
    """Strip directory components and replace unsafe chars so the name is a valid S3 key segment."""
    base = os.path.basename(name)
    # Replace anything that isn't alphanumeric, dot, hyphen, or underscore.
    return re.sub(r"[^A-Za-z0-9._\-]", "_", base) or "master"


def route_upload_url(event: dict) -> dict:
    """POST /products/upload-url → presigned PUT URL so admin can upload a master."""
    bucket = os.environ.get("BUCKET_NAME", "")
    if not bucket:
        return _error(500, "Missing required environment variable: BUCKET_NAME")

    body = _parse_body(event)
    product_id = str(body.get("product_id", "")).strip()
    filename = str(body.get("filename", "")).strip()
    content_type = str(body.get("content_type", "application/octet-stream")).strip()

    if not product_id or not _PRODUCT_ID_RE.match(product_id):
        return _error(400, "product_id must be non-empty alphanumeric/hyphen/underscore")
    if not filename:
        return _error(400, "filename is required")

    safe_name = _safe_filename(filename)
    s3_key = f"masters/{product_id}/{safe_name}"

    try:
        upload_url = storage.generate_presigned_put(bucket, s3_key, content_type, expiry=900)
    except Exception as exc:
        return _error(500, f"Failed to generate presigned PUT URL: {exc}")

    logger.info("Issued upload URL: product=%s key=%s", product_id, s3_key)
    return _ok({"upload_url": upload_url, "s3_key": s3_key})


def route_watermark(event: dict) -> dict:
    """POST /watermark → watermark a master and return a presigned download URL.

    Idempotent: if orders/<order_id>.mp3 already exists in S3 a fresh presigned
    URL is returned without re-processing. The 30-day S3 lifecycle rule expires
    the object automatically; the next call after expiry re-creates it from the
    master transparently.
    """
    bucket = os.environ.get("BUCKET_NAME", "")
    if not bucket:
        return _error(500, "Missing required environment variable: BUCKET_NAME")

    body = _parse_body(event)
    master_key = str(body.get("master_key", "")).strip()
    order_id_raw = body.get("order_id")

    # Validate master_key
    if not master_key:
        return _error(400, "master_key is required")
    if master_key.startswith("/") or ".." in master_key.split("/"):
        return _error(400, "master_key must be a relative path with no '..' segments")

    # Validate order_id — must be a positive integer ≤ 2^32-1 (WooCommerce order ID
    # also serves as the embedded 32-bit watermark code).
    if isinstance(order_id_raw, float) and order_id_raw.is_integer():
        order_id_raw = int(order_id_raw)
    if not isinstance(order_id_raw, int) or not (1 <= order_id_raw <= 2**32 - 1):
        return _error(400, "order_id must be a positive 32-bit integer (WooCommerce order ID)")
    order_id: int = order_id_raw

    output_key = f"orders/{order_id}.mp3"

    # Idempotent fast path: serve an existing buyer copy without re-watermarking.
    try:
        if storage.object_exists(bucket, output_key):
            download_url = storage.generate_presigned_url(bucket, output_key)
            logger.info("Cache hit: order=%s", order_id)
            return _ok({"download_url": download_url, "watermark_code": order_id, "from_cache": True})
    except Exception as exc:
        return _error(500, f"S3 head-object failed: {exc}")

    # Slow path: download master → embed watermark → transcode → upload → presign.
    with tempfile.TemporaryDirectory() as tmp_dir:
        input_path = os.path.join(tmp_dir, "master")
        wav_path = os.path.join(tmp_dir, "watermarked.wav")
        mp3_path = os.path.join(tmp_dir, "watermarked.mp3")

        try:
            storage.download_from_s3(bucket, master_key, input_path)
        except ClientError as exc:
            code = exc.response.get("Error", {}).get("Code", "")
            if code in ("NoSuchKey", "NoSuchBucket", "404"):
                return _error(404, f"Master not found in S3: {master_key}")
            return _error(500, f"S3 download failed for '{master_key}': {exc}")
        except Exception as exc:
            return _error(500, f"S3 download failed for '{master_key}': {exc}")

        try:
            watermark.embed_watermark(input_path, order_id, wav_path)
        except Exception as exc:
            return _error(500, f"Watermark embedding failed: {exc}")

        try:
            watermark.transcode_to_mp3(wav_path, mp3_path)
        except Exception as exc:
            return _error(500, f"MP3 transcoding failed: {exc}")

        try:
            storage.upload_to_s3(mp3_path, bucket, output_key)
        except Exception as exc:
            return _error(500, f"S3 upload failed for '{output_key}': {exc}")

        try:
            download_url = storage.generate_presigned_url(bucket, output_key)
        except Exception as exc:
            return _error(500, f"Presigned URL generation failed: {exc}")

    logger.info("Watermark job complete: order=%s master=%s", order_id, master_key)
    return _ok({"download_url": download_url, "watermark_code": order_id, "from_cache": False})


def lambda_handler(event: dict, context) -> dict:  # noqa: ANN001
    path = event.get("path", "/watermark")

    if path == "/products/upload-url":
        return route_upload_url(event)
    elif path in ("/watermark", "/"):
        return route_watermark(event)
    else:
        return _error(404, f"Unknown path: {path}")
