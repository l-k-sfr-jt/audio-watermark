import os

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
_presigner_client = None

_REGION = os.environ.get("AWS_DEFAULT_REGION", "eu-central-1")


def _s3():
    global _s3_client
    if _s3_client is None:
        _s3_client = boto3.client("s3", region_name=_REGION, config=_CONFIG)
    return _s3_client


def _presigner_s3():
    # Uses a dedicated IAM user with permanent credentials (AKIA...) so that
    # presigned URLs remain valid for their full 48-hour window. Lambda's own
    # execution-role STS credentials (ASIA...) expire in ≤12 h, which would
    # silently invalidate any presigned URL signed after that point.
    global _presigner_client
    if _presigner_client is None:
        _presigner_client = boto3.client(
            "s3",
            region_name=_REGION,
            aws_access_key_id=os.environ["PRESIGNER_KEY_ID"],
            aws_secret_access_key=os.environ["PRESIGNER_SECRET"],
            config=_CONFIG,
        )
    return _presigner_client


def download_from_s3(bucket: str, key: str, local_path: str) -> None:
    _s3().download_file(bucket, key, local_path)


def upload_to_s3(local_path: str, bucket: str, key: str) -> None:
    _s3().upload_file(local_path, bucket, key)


def generate_presigned_url(bucket: str, key: str, expiry: int = 172800) -> str:
    return _presigner_s3().generate_presigned_url(
        "get_object",
        Params={"Bucket": bucket, "Key": key},
        ExpiresIn=expiry,
    )
