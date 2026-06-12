# audio-watermark-lambda

Forensic audio watermarking service. Embeds a unique 32-bit `user_id` into
audiobook WAV files using DCT spread-spectrum so leaked copies can be traced
back to the buyer.

## Prerequisites

- Python 3.11+
- [ffmpeg](https://ffmpeg.org/download.html) on `PATH` — required only for
  `roundtrip-test` and the MP3 robustness unit tests

## Setup

```bash
pip install -r requirements.txt
```

## CLI usage (no AWS required)

```bash
# Embed a watermark
python cli.py embed input.mp3 12345 output.wav

# Detect the watermark
python cli.py detect output.wav

# Full roundtrip: embed → ffmpeg re-encode at 64 & 128 kbps → detect
python cli.py roundtrip-test input.mp3 12345
```

The `roundtrip-test` command prints `[PASS]` / `[FAIL]` for each bitrate and
exits non-zero if any detection fails. Requires ffmpeg.

## Run tests

```bash
pytest tests/ -v
```

MP3 robustness tests are skipped automatically if ffmpeg is not found.

## Simulate a Lambda invocation locally

```bash
BUCKET_NAME=my-bucket SES_FROM_EMAIL=sender@example.com python - <<'EOF'
import json
from src.handler import lambda_handler

event = {
    "s3_key": "masters/book.mp3",
    "user_id": 4582,
    "email": "buyer@example.com",
    "order_id": "wc_order_98765",
}
print(json.dumps(lambda_handler(event, None), indent=2))
EOF
```

This requires valid AWS credentials and a real S3 key. To test the watermark
algorithm itself without AWS, use the CLI commands above.

## Project structure

```
src/
  handler.py    # Lambda entry point
  watermark.py  # Core DCT algorithm — no AWS imports
  storage.py    # S3 operations (boto3)
  notify.py     # SES email (boto3)
tests/
  test_watermark.py
cli.py
template.yaml   # SAM scaffold (do not deploy yet)
```

## Deployment

`template.yaml` is scaffolding only — do not run `sam deploy` until IAM
policies and API Gateway integration have been reviewed.
