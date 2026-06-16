import os

import boto3
from botocore.config import Config
from botocore.exceptions import ClientError

# Lambda-tuned client config: retry transient errors with exponential backoff
# (standard mode), but fail fast on connection/read hangs so we stay well
# within the 60 s function timeout.
_CONFIG = Config(
    retries={"total_max_attempts": 3, "mode": "standard"},
    connect_timeout=5,
    read_timeout=30,
)

# A single S3 client, created lazily on first use and reused across warm
# invocations. boto3 clients are thread-safe.
_s3_client = None

_REGION = os.environ.get("AWS_DEFAULT_REGION", "eu-central-1")


def _s3():
    global _s3_client
    if _s3_client is None:
        _s3_client = boto3.client("s3", region_name=_REGION, config=_CONFIG)
    return _s3_client


def download_from_s3(bucket: str, key: str, local_path: str) -> None:
    _s3().download_file(bucket, key, local_path)


def upload_to_s3(local_path: str, bucket: str, key: str) -> None:
    _s3().upload_file(local_path, bucket, key)


def object_exists(bucket: str, key: str) -> bool:
    """Return True if the object exists, False if 404, raise on other errors."""
    try:
        _s3().head_object(Bucket=bucket, Key=key)
        return True
    except ClientError as exc:
        if exc.response["Error"]["Code"] in ("404", "NoSuchKey"):
            return False
        raise


def generate_presigned_put(bucket: str, key: str, content_type: str, expiry: int = 900) -> str:
    """Return a presigned PUT URL so a browser/client can upload directly to S3."""
    return _s3().generate_presigned_url(
        "put_object",
        Params={"Bucket": bucket, "Key": key, "ContentType": content_type},
        ExpiresIn=expiry,
    )


def generate_presigned_url(bucket: str, key: str, expiry: int = 3600) -> str:
    """Return a presigned GET URL valid for `expiry` seconds (default 1 h).

    Signed with the Lambda execution role (STS credentials). Since buyer copies
    are re-minted on every download request and expire in 1 h, they will always
    be regenerated before the STS session (≤12 h) expires.
    """
    return _s3().generate_presigned_url(
        "get_object",
        Params={"Bucket": bucket, "Key": key},
        ExpiresIn=expiry,
    )
