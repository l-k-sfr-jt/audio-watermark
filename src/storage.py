import os
from datetime import datetime, timedelta

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

_s3_client = None
_ssm_client = None
_cf_signer_cache = None  # CloudFrontSigner instance, built once per warm container

_REGION = os.environ.get("AWS_DEFAULT_REGION", "eu-central-1")


def _s3():
    global _s3_client
    if _s3_client is None:
        _s3_client = boto3.client("s3", region_name=_REGION, config=_CONFIG)
    return _s3_client


def _ssm():
    global _ssm_client
    if _ssm_client is None:
        _ssm_client = boto3.client("ssm", region_name=_REGION, config=_CONFIG)
    return _ssm_client


def _get_cf_signer():
    """Return a CloudFrontSigner, or None if CloudFront is not configured."""
    global _cf_signer_cache
    if _cf_signer_cache is not None:
        return _cf_signer_cache

    key_id = os.environ.get("CF_KEY_ID", "")
    param_name = os.environ.get("CF_PRIVATE_KEY_PARAM", "")
    if not key_id or not param_name:
        return None

    resp = _ssm().get_parameter(Name=param_name, WithDecryption=True)
    private_key_pem = resp["Parameter"]["Value"].encode()

    from cryptography.hazmat.primitives import hashes, serialization
    from cryptography.hazmat.primitives.asymmetric import padding as asym_padding
    from botocore.signers import CloudFrontSigner

    private_key = serialization.load_pem_private_key(private_key_pem, password=None)

    def _rsa_sign(message: bytes) -> bytes:
        return private_key.sign(message, asym_padding.PKCS1v15(), hashes.SHA1())

    _cf_signer_cache = CloudFrontSigner(key_id, _rsa_sign)
    return _cf_signer_cache


def download_from_s3(bucket: str, key: str, local_path: str) -> None:
    _s3().download_file(bucket, key, local_path)


def upload_to_s3(local_path: str, bucket: str, key: str, content_disposition: str | None = None) -> None:
    extra: dict = {}
    if content_disposition:
        extra["ContentDisposition"] = content_disposition
    _s3().upload_file(local_path, bucket, key, ExtraArgs=extra if extra else None)


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


def generate_presigned_url(bucket: str, key: str, expiry: int = 3600, filename: str | None = None) -> str:
    """Return a presigned download URL — CloudFront signed if configured, else S3 presigned.

    When CloudFront is configured the filename is carried via S3 object metadata
    (ContentDisposition set at upload time by upload_to_s3), so the filename
    parameter is ignored for the CloudFront path.
    """
    cf_url = _generate_cloudfront_url(key, expiry)
    if cf_url is not None:
        return cf_url
    return _generate_s3_presigned_url(bucket, key, expiry, filename)


def _generate_cloudfront_url(key: str, expiry: int = 3600) -> str | None:
    """CloudFront signed URL valid for `expiry` seconds, or None if not configured."""
    cf_domain = os.environ.get("CF_DOMAIN", "")
    signer = _get_cf_signer()
    if not cf_domain or signer is None:
        return None

    url = f"https://{cf_domain}/{key}"
    expires_at = datetime.utcnow() + timedelta(seconds=expiry)
    return signer.generate_presigned_url(url, date_less_than=expires_at)


def _generate_s3_presigned_url(bucket: str, key: str, expiry: int = 3600, filename: str | None = None) -> str:
    """S3 presigned GET URL (used when CloudFront is not configured)."""
    params: dict = {"Bucket": bucket, "Key": key}
    if filename:
        params["ResponseContentDisposition"] = f'attachment; filename="{filename}"'
    return _s3().generate_presigned_url("get_object", Params=params, ExpiresIn=expiry)
