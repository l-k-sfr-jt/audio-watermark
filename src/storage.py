import boto3
from botocore.config import Config

# Lambda-tuned client config: retry transient errors with exponential backoff
# (standard mode), but fail fast on connection/read hangs so we stay well
# within the 60 s function timeout.
_CONFIG = Config(
    retries={"total_max_attempts": 3, "mode": "standard"},
    connect_timeout=5,
    read_timeout=30,
)

# A single S3 client is created lazily on first use and reused thereafter.
# boto3 clients are thread-safe; in Lambda this means one client per cold
# start, shared across all warm invocations (avoids per-call construction).
_s3_client = None


def _s3():
    global _s3_client
    if _s3_client is None:
        _s3_client = boto3.client("s3", config=_CONFIG)
    return _s3_client


def download_from_s3(bucket: str, key: str, local_path: str) -> None:
    """Download an S3 object to a local file (managed multipart + retries)."""
    _s3().download_file(bucket, key, local_path)


def upload_to_s3(local_path: str, bucket: str, key: str) -> None:
    """Upload a local file to S3 (managed multipart + retries)."""
    _s3().upload_file(local_path, bucket, key)


def generate_presigned_url(bucket: str, key: str, expiry: int = 172800) -> str:
    """Return a presigned GET URL valid for `expiry` seconds (default 48 h)."""
    return _s3().generate_presigned_url(
        "get_object",
        Params={"Bucket": bucket, "Key": key},
        ExpiresIn=expiry,
    )
