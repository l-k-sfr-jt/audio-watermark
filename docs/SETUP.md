# Setup Guide — from zero to a watermarking shop

Everything you need to go from a fresh AWS account and a running WooCommerce
site to having the service live and your first audiobook download working.

The guide has four parts that must be done in order:

1. **Deploy the AWS service** — the Lambda + S3 + API Gateway stack.
2. **Install the WordPress plugin** — the WooCommerce integration.
3. **Configure and test the connection** — paste the API credentials, click the
   test button.
4. **Set up an audiobook product and place a test order** — confirm the end-to-end
   flow works before going live.

Then a **Day-to-day operations** section covers everything you will do repeatedly
after the initial setup: re-triggering failed watermarks, forensic tracing, and
revoking access after a refund.

---

## Prerequisites

| Requirement | Notes |
|-------------|-------|
| AWS account | See [docs/DEPLOYMENT.md — Step 1](DEPLOYMENT.md#-step-1--create-the-aws-account) if you don't have one yet. |
| AWS CLI + SAM CLI + Docker | Installed locally. Run `./scripts/check-prereqs.sh` to verify. |
| WordPress site with WooCommerce 6.0+ | Active and reachable. |
| PHP 7.4+ on your WordPress host | Required by the plugin. |
| An audiobook WAV or MP3 file for testing | Any short file works for the smoke test. |

---

## Part 1 — Deploy the AWS service

> **Full details:** [docs/DEPLOYMENT.md](DEPLOYMENT.md).
> The summary below covers the essential steps; consult the full guide for
> troubleshooting, CI/CD setup, and teardown.

### 1a. Configure AWS credentials

If you have not already done so:

```bash
aws configure
# Access key ID:     <from IAM → Users → deployer → Security credentials>
# Secret access key: <shown once at creation time>
# Default region:    eu-central-1
# Default output:    json
```

### 1b. Check prerequisites

```bash
./scripts/check-prereqs.sh
```

Fix any ✗ items before continuing (usually a missing tool or Docker not running).

### 1c. Deploy the stack

```bash
./scripts/deploy.sh
```

The first run uses `sam deploy --guided`. Accept the prompted defaults — they
are pre-filled from `samconfig.toml`. The deployment takes a few minutes while
it builds the container image and creates the CloudFormation stack.

When it finishes you will see:

```
  API base URL : https://xxxx.execute-api.eu-central-1.amazonaws.com/Prod
  API key      : <long secret string>
```

**Copy both values and keep them safe.** You will paste them into WordPress in
Part 3. If you lose them, retrieve them with:

```bash
# API base URL — from CloudFormation outputs:
aws cloudformation describe-stacks \
  --stack-name audio-watermark \
  --query "Stacks[0].Outputs[?OutputKey=='ApiBaseUrl'].OutputValue" \
  --output text

# API key value:
aws apigateway get-api-keys --include-values \
  --query "items[?name=='WatermarkApiKey'].value" \
  --output text \
  --region eu-central-1
```

### 1d. Run the smoke test

```bash
./scripts/smoke-test.sh path/to/any_audio.wav 9001
```

This exercises the full deployed flow (upload → watermark → download →
idempotency → 403 without key). If it passes, the service is working correctly.

---

## Part 2 — Install the WordPress plugin

The plugin is the folder `wordpress/audio-watermark-woo/` in this repo.

### Option A — Upload via WordPress admin (recommended for most setups)

1. Zip the folder:
   ```bash
   cd wordpress
   zip -r audio-watermark-woo.zip audio-watermark-woo/
   ```
2. In WordPress admin: **Plugins → Add New Plugin → Upload Plugin**.
3. Choose `audio-watermark-woo.zip` and click **Install Now**.
4. Click **Activate Plugin**.

### Option B — Copy directly to the server

If you have SSH or SFTP access to your web server:

```bash
scp -r wordpress/audio-watermark-woo/ user@yourserver:/path/to/wp-content/plugins/
```

Then go to **WordPress admin → Plugins** and activate **Audio Watermark for
WooCommerce**.

### Verify activation

After activating you should see no error notices. WooCommerce 6.0+ is required;
if it is missing or inactive the plugin shows an admin notice and does nothing.

---

## Part 3 — Configure and test the connection

### 3a. Enter the API credentials

1. In WordPress admin go to **WooCommerce → Settings → Audiobook WM**.
2. Fill in:
   - **Watermark service base URL** — the `API base URL` from Step 1c (no
     trailing slash), e.g. `https://xxxx.execute-api.eu-central-1.amazonaws.com/Prod`.
   - **API key** — the secret from Step 1c.
3. Click **Save changes**.

### 3b. Test the connection

Once the settings are saved, click **Test connection**. The button POSTs an
intentionally invalid request to the service; you should see:

> ✔ Connection OK — API key accepted.

If instead you see:

| Message | What to check |
|---------|---------------|
| ✖ API key rejected (403 Forbidden) | Paste the API key again — a trailing space or missing character is common. |
| ✖ Could not reach service: … | Check the base URL — make sure there is no trailing slash and the region in the URL matches your deploy region. |
| ✖ Unexpected response HTTP 404 | The `/watermark` path is wrong; your URL may already include `/Prod` twice or be missing a stage segment. |

---

## Part 4 — Set up an audiobook product and run a test order

### 4a. Create the product

1. **Products → Add New** (or edit an existing product).
2. Set the product type to **Simple product**.
3. In the **General** tab, scroll to the **Audiobook watermarking** section.
4. Tick **Enable audiobook watermarking**.

### 4b. Upload the master audio

You can upload **one or more master files** per product (e.g. separate chapters
or CD parts — each becomes its own download button for the buyer).

1. Click **Add master audio files**.
2. Pick one **or more** WAV/MP3 files (the picker accepts multiple selections).
   The browser requests a presigned S3 URL for each and uploads the files directly
   to S3, one after another — they never pass through WordPress. For a 40-chapter
   audiobook you can select all the files at once.
3. Wait for the status line to show:
   > ✔ Upload complete! Save the product to persist.
4. Each file appears in the master file list below the button with its filename
   and a **Remove** link.
5. **Repeat** if you want to add more files in a later batch.
6. Click **Publish** / **Update** to save the product.

> **Single-file audiobook:** just upload one file. The buyer sees one download
> button labelled "Download: [product name]".
>
> **Multi-file audiobook (chapters/parts):** upload each file in order. The buyer
> sees one button per file: "Download: [product name] — [filename stem]".
>
> **Important:** the file list is only saved to the database when you save the
> product. If you leave the page before saving, the files are in S3 but the
> product has no record of them. Re-upload and save.

### 4c. Set a price and publish

Give the product a price (even £0.01 for testing) and make sure it is
published and in stock. Watermarking now fires automatically as soon as the order
reaches **processing** (i.e. on payment) as well as on **completed**, so digital
goods that never get manually marked completed are still watermarked and
delivered.

### 4d. Place a test order

1. Open a browser tab as a customer (or use a separate account / incognito
   window).
2. Add the product to the cart and complete checkout.
3. In WooCommerce admin (**WooCommerce → Orders**), find the new order.
4. The order should reach **Processing** automatically on payment — that already
   triggers watermarking. (Marking it **Completed** later re-triggers harmlessly;
   the work is idempotent and the delivery email is sent only once.)

### 4e. Confirm watermarking happened

1. Reload the order detail page.
2. In the **Order notes** panel (bottom right) you should see:
   > [Audio WM] Audiobook watermarked — item #N (product #M), code XXXXXX

   If you see a failure note instead, check the service URL and key in Step 3,
   or look at the retry notes (automatic retries run at +5 min, +30 min, +2 h).

### 4f. Download as the customer

The customer can reach their downloads three ways:

- **Delivery email (guests included):** once the order is processing/completed,
  WooCommerce emails the buyer a download link per audio file/part, plus a
  durable **"request a new link"** link. Download links expire after 30 days; the
  re-request link lets the buyer self-serve fresh links (throttled to once per
  hour) with no login. You can edit the email's subject/heading or enable/disable
  it under **WooCommerce → Settings → Emails** ("Audiobook download").
- **Order-received / thank-you page:** the same download buttons appear right
  after checkout, even for guest buyers.
- **My Account (logged-in buyers):** as below.

1. As the customer, go to **My Account → Orders → view** the order.
2. Below the order table you should see an **Audiobook Downloads** section.
   - **Single-file product:** one button "Download: [product name]".
   - **Multi-file product:** one button per chapter, e.g. "Download: [product name] — chapter-01".
   - **Non-audio products** (PDFs, physical goods): no button — they are skipped automatically.
3. Click a button. The browser should download a file named
   `<stem>_orderXXXXXX.mp3` (e.g. `chapter-01_order12345.mp3`). The order ID
   is embedded in both the watermark and the filename.
4. Play the file and confirm it sounds correct.

### 4g. Verify the watermark (optional forensic check)

On your development machine:

```bash
python cli.py detect path/to/downloaded_file.mp3
```

You should see:

```
Detected user_id: XXXXXX  (confidence: 0.XX)
```

Where `XXXXXX` matches the WooCommerce order ID. Any confidence value above
~0.1 is a reliable detection.

---

## Day-to-day operations

### Recovering a failed watermark

If watermarking fails for an order and all automatic retries (at +5, +30, +120
minutes) are exhausted:

1. Open the order in WooCommerce admin.
2. In the **Order Actions** dropdown (top right of the order detail page) select
   **Re-watermark audiobook(s)**.
3. Click the arrow button to run it.
4. Check the order notes — you should see the attempt logged within seconds.

This clears the per-item state so the process runs fresh. The buyer's download
button will appear (or be fixed) once the retry succeeds.

### Revoking download access after a refund

Refund the order in WooCommerce (**Order → Refund**). Once the order status
changes away from `completed` / `processing`, any click on the Download button
returns:

> This order is no longer eligible for downloads.

No extra steps are needed — the status check is automatic.

### Adding, replacing, or removing master audio files

**Add a new chapter/part:** click **Add master audio files** on the product edit
page, upload the file(s), and save the product. Future orders will include the
new file. Existing orders are unaffected.

**Replace a file:** remove the old entry from the list (click **Remove** next to
it), then add the new file via **Add master audio files**, and save the product.

**Note on existing buyers:** buyer copies already in S3 (`orders/…`) were created
from whichever masters were live at order time. Removing or replacing a master
does not re-watermark those copies. After the 30-day expiry, the next download
click regenerates from the then-current master. If you need all buyers to get a
corrected version immediately, delete the relevant `orders/<id>/<item>/...` objects
from S3 manually — the next download will re-watermark automatically.

### Tracing a leaked file

If a copy of a watermarked audiobook appears online:

```bash
python cli.py detect leaked_file.mp3
```

The output shows the WooCommerce order ID. Look up that order to identify the
buyer (name, email, address).

The watermark survives MP3 re-encoding down to 64 kbps. It does **not** survive
trimming the first ~12 seconds of audio (the mark lives in the opening segment).

### Rotating the API key

If the API key is compromised:

1. In **AWS Console → API Gateway → API Keys**, delete `WatermarkApiKey`.
2. Update `template.yaml` to rename the key resource (e.g. `WatermarkApiKey2`)
   and redeploy: `./scripts/deploy.sh`.
3. The deploy output prints the new key. Paste it into **WooCommerce → Settings
   → Audiobook WM → API key** and use the **Test connection** button to confirm.

### Deploying updates to the service

From your local machine:

```bash
./scripts/deploy.sh
```

Or push to `master` on GitHub — the **Deploy to AWS** Actions workflow runs
automatically. See [docs/DEPLOYMENT.md — GitHub Actions](DEPLOYMENT.md#deploying-from-github-actions-alternative-to-local-deploy)
for the one-time OIDC role setup required.

---

## Pre-launch checklist

Before going live with real customers, confirm each item:

- [ ] Smoke test passes: `./scripts/smoke-test.sh audiobook.wav 9001`
- [ ] **Test connection** button shows "Connection OK" in WooCommerce settings
- [ ] Billing budget alarm set in AWS (see [DEPLOYMENT.md Step 3](DEPLOYMENT.md#-step-3--set-a-billing-budget-alarm))
- [ ] Placed a real test order end-to-end and downloaded the MP3
- [ ] `python cli.py detect` on the downloaded file returns the correct order ID
- [ ] Refund the test order and confirm the Download button is blocked (403)
- [ ] Confirmed order notes show watermarking activity after order completion
- [ ] Master audio files are WAV or MP3; content type checked at upload (other types are rejected)
- [ ] IAM user / OIDC role is scoped appropriately (not AdministratorAccess in production — see [DEPLOYMENT.md — Going to production](DEPLOYMENT.md#going-to-production-later))
