# Deployment Guide (Phase 3) — from zero AWS account to a live service

This walks you from **no AWS account at all** to a deployed, tested
watermarking service. Steps are split into two kinds:

- 🧑 **You, in a browser** — things no script or AI can do (account, payment,
  identity verification). These are unavoidable.
- 🤖 **One command** — everything else is automated by the scripts in `scripts/`.

Region for everything: **eu-central-1 (Frankfurt)**. Lambda, S3, and SES must
share a region or SES won't send.

---

## Cost expectations

This service fits comfortably in the **AWS Free Tier** for development:
- Lambda: 1M free requests/month
- S3: 5 GB free; we auto-delete watermarked outputs after 2 days
- SES: pay-per-email, fractions of a cent; sandbox is free
- API Gateway: 1M free calls/month for 12 months
- ECR (stores the container image): ~$0.10/month for the image

The realistic dev cost is **near zero**. Still, do Step 3 (budget alarm) so
there are no surprises, and run `./scripts/teardown.sh` when you're done
experimenting.

---

## 🧑 Step 1 — Create the AWS account

1. Go to https://aws.amazon.com/ and choose **Create an AWS Account**.
2. Enter your email, a strong root password, and an account name.
3. Provide billing info (a credit/debit card; a small temporary auth charge
   may appear).
4. Verify your phone number (SMS or call).
5. Choose the **Basic support — Free** plan.

> This step requires your real identity and payment method. It cannot be
> automated by any tool.

## 🧑 Step 2 — Secure the account and create access keys

1. Sign in as **root** (the email you just used).
2. Search for **IAM** → enable **MFA** on the root user (use an authenticator
   app). Then stop using root for daily work.
3. In IAM → **Users** → **Create user** (e.g. `deployer`).
   - Attach the **AdministratorAccess** policy (fine for a solo dev; tighten
     later).
   - After creating, open the user → **Security credentials** →
     **Create access key** → choose **Command Line Interface (CLI)**.
   - Copy the **Access key ID** and **Secret access key** (you see the secret
     only once).

> The modern alternative is **IAM Identity Center** (SSO). For a single
> developer, an IAM user with an access key is simpler and fine.

## 🧑 Step 3 — Set a billing budget alarm

1. IAM/root → **Billing and Cost Management** → **Budgets** → **Create budget**.
2. Pick **Zero spend budget** (alerts the moment anything bills) or a small
   monthly amount like $5. Enter your email for alerts.

## 🧑 Step 4 — Install the tools on your Mac

```bash
brew install awscli aws-sam-cli
```

Docker is required to build the container image:
- Install **Docker Desktop**: https://www.docker.com/products/docker-desktop/
- Launch it once and let it finish starting.

## 🧑 Step 5 — Configure credentials

```bash
aws configure
```
Enter the access key ID and secret from Step 2, set region `eu-central-1`,
and output format `json`.

---

## 🤖 Step 6 — Check prerequisites

From the repo root:
```bash
./scripts/check-prereqs.sh
```
This confirms `aws`, `sam`, and `docker` are installed, Docker is running, and
your credentials work. Fix any ✗ items before continuing.

## 🤖 Step 7 — Deploy the whole stack

```bash
./scripts/deploy.sh noreply@yourdomain.com
```
This builds the image and creates the S3 bucket, SES sender identity, Lambda,
and API Gateway. The first run uses `sam deploy --guided` (just accept the
defaults; they're pre-filled from `samconfig.toml`).

## 🧑 Step 8 — Confirm the SES sender

AWS emails a verification link to the sender address you passed in Step 7.
**Open that inbox and click the link.** Until you do, the Lambda can upload and
presign files but cannot send mail.

## 🤖 Step 9 — Verify a test recipient (SES sandbox)

New SES accounts start in the **sandbox**: you can only send to addresses you've
verified, capped at 200 emails/day. Verify your own address to test:
```bash
./scripts/verify-recipient.sh you@example.com
```
Click the link that arrives.

## 🤖 Step 10 — Smoke test end to end

```bash
./scripts/smoke-test.sh ~/Downloads/sample-audiobook.mp3 you@example.com
```
This uploads the file to `masters/`, invokes the Lambda directly, and prints the
response. On `{"status": "ok", ...}`, check `you@example.com` for the download
link (valid 48 hours). Download it and run `python3 cli.py detect <file>` locally
to confirm the embedded `user_id` (4582 by default).

The script also runs a quick API key check at the end: it verifies that a
request without `x-api-key` gets a `403`, and a request with the key gets `200`.

## 🤖 Step 11 — Retrieve the API key for WooCommerce

The smoke test prints the key at the end. You can also retrieve it at any time:

```bash
aws apigateway get-api-keys --include-values \
  --query "items[?name=='WatermarkApiKey'].value" --output text \
  --region eu-central-1
```

Store this value in your WooCommerce plugin config as the `x-api-key` header
secret. Rotate it by updating `WatermarkApiKey` in the CloudFormation console
(or by redeploying with a new key resource name) and updating WooCommerce.

---

## Going to production (later)

- **Leave the SES sandbox:** SES console → **Account dashboard** →
  **Request production access**. This lets you email arbitrary buyers (not just
  verified addresses). Approval is usually quick but is a manual AWS review.
- **Custom domain sender:** verify a domain identity (DKIM) instead of a single
  email, so mail comes from `noreply@yourdomain.com` with better deliverability.
- **Lock down IAM:** replace the `deployer` AdministratorAccess user with a
  scoped deployment role.
- **API auth:** done — `POST /watermark` requires a valid `x-api-key` header
  (provisioned in Step 11). Wire this key into your WooCommerce plugin (Phase 5).

## Tearing it all down

To remove everything and stop any billing:
```bash
./scripts/teardown.sh
```

---

## Troubleshooting

**`sam build` fails / is extremely slow on Apple Silicon**
You're building an x86_64 image under emulation. Switch to native arm64: in
`template.yaml` set `Architectures: [arm64]`, and in `Dockerfile` change the
ffmpeg URL to the `arm64` static build. arm64 Lambda is also ~20% cheaper.

**`MessageRejected` / email never arrives**
You're in the SES sandbox and the recipient isn't verified (Step 9), or the
sender link (Step 8) wasn't clicked. Both sender and recipient must be verified
while sandboxed.

**Lambda returns 404 "Source audio not found"**
The `s3_key` in your request doesn't exist in the bucket. Check the upload in
Step 10 succeeded and the key matches exactly.

**Lambda times out**
60s is plenty for watermarking (~12s of audio is processed regardless of file
length). A timeout usually means a very large download — check the master file
size and your network, or raise `Timeout` in `template.yaml`.
