# Deployment Guide — from zero AWS account to a live service

This walks you from **no AWS account at all** to a deployed, tested watermarking
service. Steps are split into two kinds:

- 🧑 **You, in a browser** — things no script or AI can do (account, payment,
  identity verification). These are unavoidable.
- 🤖 **One command** — everything else is automated by the scripts in `scripts/`.

The service provisions an S3 bucket, a container-image Lambda, and an API Gateway
with an API key + usage plan. There is **no SES / email** — WordPress handles all
customer email, and buyers download via the WooCommerce plugin.

Region for everything: **eu-central-1 (Frankfurt)** (override with `AWS_REGION`).

---

## Cost expectations

This service fits comfortably in the **AWS Free Tier** for development:
- Lambda: 1M free requests/month
- S3: 5 GB free; buyer copies under `orders/` auto-delete after 30 days
- API Gateway: 1M free calls/month for 12 months
- ECR (stores the container image): ~$0.10/month for the image

The realistic dev cost is **near zero**. Still, do Step 3 (budget alarm) so there
are no surprises, and run `./scripts/teardown.sh` when you're done experimenting.

---

## 🧑 Step 1 — Create the AWS account

1. Go to https://aws.amazon.com/ and choose **Create an AWS Account**.
2. Enter your email, a strong root password, and an account name.
3. Provide billing info (a small temporary auth charge may appear).
4. Verify your phone number.
5. Choose the **Basic support — Free** plan.

> This step requires your real identity and payment method. It cannot be
> automated by any tool.

## 🧑 Step 2 — Secure the account and create access keys

1. Sign in as **root** (the email you just used).
2. Search for **IAM** → enable **MFA** on the root user. Then stop using root for
   daily work.
3. IAM → **Users** → **Create user** (e.g. `deployer`).
   - Attach **AdministratorAccess** (fine for a solo dev; tighten later).
   - Open the user → **Security credentials** → **Create access key** →
     **Command Line Interface (CLI)**.
   - Copy the **Access key ID** and **Secret access key** (shown only once).

## 🧑 Step 3 — Set a billing budget alarm

1. **Billing and Cost Management** → **Budgets** → **Create budget**.
2. Pick **Zero spend budget** (alerts the moment anything bills) or a small
   monthly amount. Enter your email for alerts.

## 🧑 Step 4 — Install the tools

```bash
brew install awscli aws-sam-cli      # macOS
```

Docker is required to build the container image:
- Install **Docker Desktop**: https://www.docker.com/products/docker-desktop/
- Launch it once and let it finish starting.

## 🧑 Step 5 — Configure credentials

```bash
aws configure
```
Enter the access key ID and secret from Step 2, region `eu-central-1`, output
format `json`.

---

## 🤖 Step 6 — Check prerequisites

```bash
./scripts/check-prereqs.sh
```
Confirms `aws`, `sam`, and `docker` are installed, Docker is running, and your
credentials work. Fix any ✗ items before continuing.

## 🤖 Step 7 — Deploy the whole stack

```bash
./scripts/deploy.sh
```
Builds the image and creates the S3 bucket, Lambda, API Gateway, API key, and
usage plan. The first run uses `sam deploy --guided` (accept the defaults; they
are pre-filled from `samconfig.toml`). When it finishes it prints:

```
  API base URL : https://xxxx.execute-api.eu-central-1.amazonaws.com/Prod
  API key      : <secret>
```

Keep these for the WooCommerce plugin (Step 10).

## 🤖 Step 8 — Smoke test end to end

```bash
./scripts/smoke-test.sh path/to/audiobook.wav 9001
```
This exercises the real deployed flow:
1. `POST /products/upload-url` → presigned PUT,
2. PUT the master to S3,
3. `POST /watermark` (slow path — must return `from_cache: false`),
4. download the resulting MP3 and assert it is non-empty,
5. re-call `/watermark` (idempotency — must return `from_cache: true`),
6. assert a request **without** `x-api-key` is rejected with `403`.

To confirm the embedded code, download the MP3 and run
`python cli.py detect <file>` locally — it should print the order ID you passed.

## 🧑 / 🤖 Step 9 — Retrieve the API key at any time

`deploy.sh` and `smoke-test.sh` both print it. You can also fetch it directly:

```bash
aws apigateway get-api-keys --include-values \
  --query "items[?name=='WatermarkApiKey'].value" --output text \
  --region eu-central-1
```

## 🧑 Step 10 — Configure WooCommerce

In WordPress: **WooCommerce → Settings → Audiobook WM** and paste:
- **Watermark service base URL** = the API base URL from Step 7,
- **API key** = the secret from Step 7/9.

Then on a product: tick **Enable audiobook watermarking**, click **Upload master
audio**, save. Place a test order, mark it **completed**, and confirm a
**Download** button appears on the order page that serves a working MP3. (See
[USE-CASES.md](USE-CASES.md) for the full flow.)

---

## Going to production (later)

- **Lock down IAM:** replace the `deployer` AdministratorAccess user with a
  scoped deployment role.
- **Rotate the API key:** update `WatermarkApiKey` in CloudFormation (or rename
  the key resource and redeploy) and update the WooCommerce setting.
- **arm64 Lambda:** set `Architectures: [arm64]` in `template.yaml` and the arm64
  ffmpeg build in `Dockerfile` for ~20% cheaper/faster execution.
- **Multi-item orders:** before selling orders that contain two or more
  watermark-enabled audiobooks, address the per-item output-key limitation noted
  in [USE-CASES.md](USE-CASES.md) (Known limitations → G1).

## Tearing it all down

```bash
./scripts/teardown.sh
```
Empties the bucket (CloudFormation can't delete a non-empty bucket) and deletes
the stack (Lambda, API, bucket, and the ECR repo).

---

## Troubleshooting

**`sam build` is extremely slow on Apple Silicon**
You're building an x86_64 image under emulation. Switch to native arm64: set
`Architectures: [arm64]` in `template.yaml` and the arm64 ffmpeg URL in
`Dockerfile`. arm64 Lambda is also ~20% cheaper.

**`/watermark` returns 404 "Master not found in S3"**
The `master_key` in the request doesn't exist under `masters/`. Check the upload
in Step 8 succeeded and the key matches exactly.

**`403` from the API**
The `x-api-key` header is missing or wrong. Retrieve the current key (Step 9) and
set it in the WooCommerce settings.

**Lambda times out**
60 s is plenty for watermarking (~12 s of audio is processed regardless of file
length). A timeout usually means a very large master download — check the file
size and your network, or raise `Timeout` in `template.yaml`.
