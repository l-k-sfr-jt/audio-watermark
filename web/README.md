# Audio Watermark — Test Console (Next.js)

A small local UI for testing the forensic audio-watermark service. It is a
**testing tool**, not production: embedding goes through the deployed AWS
`/watermark` API, and detection runs the project's own Python CLI locally.

## How it works

```
Browser ── upload audio + user_id ──▶ /api/embed (Next server)
                                         ├─ PutObject  → s3://bucket/uploads/<order>.<ext>
                                         ├─ POST       → deployed /watermark Lambda  (embeds)
                                         └─ presign GET → temp/<order>_<user>.wav
Browser ◀── presigned URL (play + download) ──┘

Browser ── "detect" (S3 key or uploaded file) ──▶ /api/detect (Next server)
                                                    └─ runs `python cli.py detect` in the repo root
```

Why detection is local: there is no deployed detect endpoint, and the watermark's
PN sequences are seeded with numpy's RNG (not reproducible in JS). In production
this mirrors reality — detection is an offline forensic step on a recovered file.

## Prerequisites

- Node.js 18.18+ (20+ recommended)
- The deployed AWS stack (run `../scripts/deploy.sh` from the repo root)
- AWS credentials available to your shell (the same `~/.aws/credentials` /
  `AWS_PROFILE` your `aws` CLI uses) — the server reads them via the default chain
- The project's Python virtualenv set up at `../.venv` (for detection):
  ```bash
  cd ..               # repo root
  python3 -m venv .venv && source .venv/bin/activate
  pip install -r requirements.txt
  ```

## Setup

```bash
cd web
cp .env.local.example .env.local   # then fill in the values
npm install
npm run dev
```

Open http://localhost:3000.

### Environment (`.env.local`)

| Variable            | From                                   | Notes |
|---------------------|----------------------------------------|-------|
| `WATERMARK_API_URL` | deploy output `ApiEndpoint`            | required |
| `WATERMARK_BUCKET`  | deploy output `BucketName`             | required |
| `AWS_REGION`        | your stack region                      | default `eu-central-1` |
| `WATERMARK_API_KEY` | API key (Phase 4)                      | leave blank until key auth is deployed |
| `NOTIFY_EMAIL`      | any valid address                      | email is non-fatal in the Lambda; app doesn't rely on it |
| `PYTHON_BIN`        | path to the venv python                | `../.venv/bin/python` (resolved from repo root) |

## Notes / limits

- **MP3 robustness** isn't exercised here — embed returns a WAV. To test 64 kbps
  survival, use the CLI: `python cli.py roundtrip-test input.mp3 12345`.
- Uploaded masters accumulate under `uploads/` in the bucket (no lifecycle rule);
  watermarked results under `temp/` auto-expire after 2 days.
- This app is intentionally unauthenticated and for local use only.
