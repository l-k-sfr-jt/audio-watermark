import boto3


def download_from_s3(bucket: str, key: str, local_path: str) -> None:
    """Stream an S3 object to a local file."""
    s3 = boto3.client("s3")
    s3.download_file(bucket, key, local_path)


def upload_to_s3(local_path: str, bucket: str, key: str) -> None:
    """Upload a local file to S3."""
    s3 = boto3.client("s3")
    s3.upload_file(local_path, bucket, key)


def generate_presigned_url(bucket: str, key: str, expiry: int = 172800) -> str:
    """Return a presigned GET URL valid for `expiry` seconds (default 48 h)."""
    s3 = boto3.client("s3")
    return s3.generate_presigned_url(
        "get_object",
        Params={"Bucket": bucket, "Key": key},
        ExpiresIn=expiry,
    )
