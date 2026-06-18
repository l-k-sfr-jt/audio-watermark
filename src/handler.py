import json
import logging
import os
import re
import tempfile
import time

from botocore.exceptions import ClientError

from src import storage, watermark

logger = logging.getLogger(__name__)
logger.setLevel(logging.INFO)

_PRODUCT_ID_RE = re.compile(r"^[A-Za-z0-9_\-]+$")
# application/octet-stream is intentionally excluded: it's a generic type that
# would let any binary file bypass the audio-only restriction on presigned PUTs.
# The WooCommerce plugin always sends an explicit audio MIME type.
_AUDIO_CONTENT_TYPES = {"audio/wav", "audio/x-wav", "audio/mpeg", "audio/mp3",
                         "audio/flac", "audio/x-flac", "audio/aiff", "audio/ogg",
                         "audio/opus"}


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


def _emf(metrics: list[dict], dimensions: dict, values: dict) -> None:
    """Emit a CloudWatch Embedded Metrics Format record to stdout.

    CloudWatch parses these automatically from Lambda logs at no extra cost —
    they appear as real metrics (no PutMetricData API calls needed).
    """
    record = {
        "_aws": {
            "Timestamp": int(time.time() * 1000),
            "CloudWatchMetrics": [
                {
                    "Namespace": "AudioWM",
                    "Dimensions": [list(dimensions.keys())],
                    "Metrics": metrics,
                }
            ],
        },
        **dimensions,
        **values,
    }
    print(json.dumps(record), flush=True)


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
    content_type = str(body.get("content_type", "")).strip()

    if not product_id or not _PRODUCT_ID_RE.match(product_id):
        return _error(400, "product_id must be non-empty alphanumeric/hyphen/underscore")
    if not filename:
        return _error(400, "filename is required")
    # Reject non-audio content types to prevent the bucket from being used as
    # a general-purpose file store via presigned PUTs.
    if content_type.split(";")[0].strip().lower() not in _AUDIO_CONTENT_TYPES:
        return _error(400, f"content_type must be an audio MIME type, got: {content_type}")

    safe_name = _safe_filename(filename)
    s3_key = f"masters/{product_id}/{safe_name}"

    try:
        upload_url = storage.generate_presigned_put(bucket, s3_key, content_type, expiry=900)
    except Exception as exc:
        logger.error("generate_presigned_put failed for key=%s: %s", s3_key, exc)
        return _error(500, "Failed to generate upload URL")

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
    item_id_raw = body.get("item_id")

    # Validate master_key — must be a masters/ path so callers cannot read
    # arbitrary keys (e.g. orders/ buyer copies) from the same bucket.
    if not master_key:
        return _error(400, "master_key is required")
    if not master_key.startswith("masters/"):
        return _error(400, "master_key must start with 'masters/'")
    if ".." in master_key.split("/"):
        return _error(400, "master_key must not contain '..' path segments")

    # Validate order_id — must be a positive integer ≤ 2^32-1 (WooCommerce order ID
    # also serves as the embedded 32-bit watermark code).
    if isinstance(order_id_raw, float) and order_id_raw.is_integer():
        order_id_raw = int(order_id_raw)
    if not isinstance(order_id_raw, int) or not (1 <= order_id_raw <= 2**32 - 1):
        return _error(400, "order_id must be a positive 32-bit integer (WooCommerce order ID)")
    order_id: int = order_id_raw

    # Validate optional item_id — a WooCommerce order-item ID used only to
    # namespace the stored copy. One order can contain several different
    # audiobooks; without per-item keys they would all collide on
    # orders/<order_id>.mp3 and the first title watermarked would be served for
    # every item. With it, each title gets its own correct copy. The embedded
    # code stays the order_id, so forensic tracing is unchanged. Omitting
    # item_id falls back to the order-level key (single-item / web-console path).
    if item_id_raw is not None:
        if isinstance(item_id_raw, float) and item_id_raw.is_integer():
            item_id_raw = int(item_id_raw)
        if not isinstance(item_id_raw, int) or not (1 <= item_id_raw <= 2**63 - 1):
            return _error(400, "item_id, if provided, must be a positive integer (WooCommerce order-item ID)")

        # Optional `part` — a sanitized filename stem that namespaces per-file
        # output within one item (multi-part audiobooks). Only valid with item_id.
        part = str(body.get("part", "")).strip()
        if part:
            if not re.match(r"^[A-Za-z0-9._\-]+$", part) or len(part) > 128:
                return _error(400, "part must contain only alphanumeric, dot, hyphen or underscore characters (max 128 chars)")
            output_key = f"orders/{order_id}/{item_id_raw}/{part}.mp3"
        else:
            output_key = f"orders/{order_id}/{item_id_raw}.mp3"
    else:
        part = str(body.get("part", "")).strip()
        if part:
            return _error(400, "part requires item_id to also be present")
        output_key = f"orders/{order_id}.mp3"

    # Derive a human-friendly filename from the master key so the browser saves
    # the file as e.g. "my_audiobook_order456789.mp3" instead of "7.mp3".
    _original_name = master_key.split("/")[-1]
    _stem = _original_name.rsplit(".", 1)[0] if "." in _original_name else _original_name
    download_name = f"{_stem}_order{order_id}.mp3"

    # Idempotent fast path: serve an existing buyer copy without re-watermarking.
    # If the cache check itself fails (e.g. S3 permissions issue), log and skip
    # to the slow path rather than returning an error — the watermark call will
    # still work as long as GetObject and PutObject are allowed.
    try:
        if storage.object_exists(bucket, output_key):
            download_url = storage.generate_presigned_url(bucket, output_key, filename=download_name)
            logger.info(json.dumps({
                "event": "cache_hit", "order_id": order_id,
                "item_id": item_id_raw, "output_key": output_key,
            }))
            _emf(
                [{"Name": "CacheHit", "Unit": "Count"}],
                {"Service": "audio-watermark"},
                {"CacheHit": 1},
            )
            return _ok({"download_url": download_url, "watermark_code": order_id, "from_cache": True})
    except Exception as exc:
        logger.warning("object_exists check failed for key=%s (skipping cache, continuing to watermark): %s", output_key, exc)

    # Slow path: download master → embed watermark + mux to MP3 → upload → presign.
    with tempfile.TemporaryDirectory() as tmp_dir:
        input_path = os.path.join(tmp_dir, "master")
        mp3_path = os.path.join(tmp_dir, "watermarked.mp3")

        try:
            storage.download_from_s3(bucket, master_key, input_path)
        except ClientError as exc:
            code = exc.response.get("Error", {}).get("Code", "")
            if code in ("NoSuchKey", "NoSuchBucket", "404"):
                return _error(404, f"Master not found in S3: {master_key}")
            logger.error("S3 download failed for key=%s: %s", master_key, exc)
            return _error(500, "Failed to retrieve audio master")
        except Exception as exc:
            logger.error("S3 download failed for key=%s: %s", master_key, exc)
            return _error(500, "Failed to retrieve audio master")

        # embed_mp3 stream-copies the untouched tail for MP3 masters (only the
        # watermarked head is re-encoded), and transparently falls back to a full
        # decode→watermark→transcode for non-MP3 or very short files.
        embed_start = time.monotonic()
        try:
            watermark.embed_mp3(input_path, order_id, mp3_path)
        except Exception as exc:
            logger.error("embed_mp3 failed for order=%s: %s", order_id, exc)
            return _error(500, "Audio processing failed")
        embed_ms = (time.monotonic() - embed_start) * 1000

        try:
            storage.upload_to_s3(mp3_path, bucket, output_key)
        except Exception as exc:
            logger.error("S3 upload failed for key=%s: %s", output_key, exc)
            return _error(500, "Failed to store processed audio")

        try:
            download_url = storage.generate_presigned_url(bucket, output_key, filename=download_name)
        except Exception as exc:
            logger.error("generate_presigned_url failed for key=%s: %s", output_key, exc)
            return _error(500, "Failed to generate download link")

    logger.info(json.dumps({
        "event": "watermark_complete", "order_id": order_id,
        "item_id": item_id_raw, "master_key": master_key,
        "embed_ms": round(embed_ms, 1),
    }))
    _emf(
        [
            {"Name": "EmbedDuration", "Unit": "Milliseconds"},
            {"Name": "CacheMiss", "Unit": "Count"},
        ],
        {"Service": "audio-watermark"},
        {"EmbedDuration": round(embed_ms, 1), "CacheMiss": 1},
    )
    return _ok({"download_url": download_url, "watermark_code": order_id, "from_cache": False})


def lambda_handler(event: dict, context) -> dict:  # noqa: ANN001
    path = event.get("path", "/watermark")

    if path == "/products/upload-url":
        return route_upload_url(event)
    elif path in ("/watermark", "/"):
        return route_watermark(event)
    else:
        return _error(404, f"Unknown path: {path}")
