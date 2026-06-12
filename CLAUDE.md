# CLAUDE.md — audio-watermark-lambda

Forensic audio watermarking service: embeds a unique 32-bit `user_id` into
audiobook WAV files using DCT spread-spectrum so leaked copies can be traced
back to the buyer. This phase covers local algorithm development and Lambda
code only — no AWS deployment yet.

## Project Structure

```
src/
  handler.py      # Lambda entry point — orchestrates download → embed → upload → email
  watermark.py    # Core DCT algorithm: embed_watermark() / detect_watermark()
                  #   *** NO boto3/AWS imports allowed here — must stay portable ***
  storage.py      # S3 download/upload, presigned URL generation (boto3)
  notify.py       # SES email dispatch (boto3)
tests/
  test_watermark.py   # Unit tests: embed/detect roundtrip + MP3 64kbps robustness
  sample_audio/       # Short test .mp3 / .wav fixtures
cli.py            # Local CLI — runs embed/detect/roundtrip without AWS
requirements.txt  # numpy, scipy, soundfile, pydub, boto3, pytest
template.yaml     # SAM scaffold — DO NOT deploy; reference only
README.md
```

## Algorithm Constants (watermark.py)

| Constant      | Value | Purpose                                           |
|---------------|-------|---------------------------------------------------|
| `BLOCK_SIZE`  | 2048  | Samples per DCT block                             |
| `NUM_BLOCKS`  | 256   | Blocks embedded (~12 s at 44.1 kHz)               |
| `FREQ_LOW`    | 150   | First DCT index in embedding band                 |
| `FREQ_HIGH`   | 500   | Last DCT index in embedding band (mid-freq)       |
| `REPETITIONS` | 8     | Bit repetition factor; majority vote on detection |
| `ALPHA`       | 0.025 | Embedding strength — tunable, survives 64 kbps   |

The watermark encodes a 32-bit integer across `NUM_BLOCKS` DCT blocks, each
`BLOCK_SIZE` samples. Detection uses majority voting across the 8 repetitions
of each bit. Output of `embed_watermark()` is always WAV (PCM 16-bit).

## Development Commands

```bash
# Install dependencies
pip install -r requirements.txt

# CLI usage (no AWS required)
python cli.py embed input.mp3 12345 output.wav
python cli.py detect output.wav
python cli.py roundtrip-test input.mp3 12345   # embed → ffmpeg MP3 @ 64 & 128 kbps → detect

# Run tests
pytest tests/ -v
```

The `roundtrip-test` command and the MP3 robustness unit test both require
`ffmpeg` on `PATH`. If absent they skip gracefully with a clear message.

## Lambda Event Schema

```json
{ "s3_key": "originals/book.mp3", "user_id": 4582,
  "email": "buyer@example.com", "order_id": "wc_1234" }
```

Success response: `{"statusCode": 200, "body": {"status": "ok", "watermark_id": 4582}}`

Watermarked file is uploaded to `temp/{order_id}_{user_id}.wav`. A presigned
GET URL (48-hour expiry) is emailed to the buyer via SES.

Lambda config: **1024 MB memory, 60 s timeout**.

## Environment Variables (Lambda / local simulation)

| Variable          | Description                           |
|-------------------|---------------------------------------|
| `BUCKET_NAME`     | S3 bucket for source and output audio |
| `SES_FROM_EMAIL`  | Verified SES sender address           |

## Architectural Rules

1. `watermark.py` must never import `boto3` or any AWS SDK — it must run
   identically in Lambda and the local CLI without AWS credentials.
2. All S3 and SES calls live exclusively in `storage.py` and `notify.py`.
3. `template.yaml` is scaffold only — do not run `sam deploy` in this phase.

## Current Phase Scope

In scope: local watermark implementation (`watermark.py`, `cli.py`, `tests/`),
Lambda handler wiring (`handler.py`, `storage.py`, `notify.py`), SAM scaffold.

Out of scope until later phases: AWS deployment, API Gateway, WordPress/WooCommerce
plugin.
