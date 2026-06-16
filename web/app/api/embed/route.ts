import { NextRequest, NextResponse } from "next/server";
import { getConfig } from "@/lib/config";

export const runtime = "nodejs";

export async function POST(req: NextRequest) {
  let cfg;
  try {
    cfg = getConfig();
  } catch (err) {
    return NextResponse.json({ error: (err as Error).message }, { status: 500 });
  }

  const form = await req.formData();
  const file = form.get("file");
  const orderIdRaw = form.get("order_id");

  if (!(file instanceof File)) {
    return NextResponse.json({ error: "No audio file provided" }, { status: 400 });
  }
  const orderId = Number(orderIdRaw);
  if (!Number.isInteger(orderId) || orderId < 1 || orderId > 2 ** 32 - 1) {
    return NextResponse.json(
      { error: "order_id must be a positive 32-bit integer (WooCommerce order ID)" },
      { status: 400 },
    );
  }

  const apiHeaders: Record<string, string> = { "Content-Type": "application/json" };
  if (cfg.apiKey) apiHeaders["x-api-key"] = cfg.apiKey;

  // Step 1: get a presigned PUT URL from the service so we can upload directly to S3.
  const uploadUrlRes = await fetch(`${cfg.apiBaseUrl}/products/upload-url`, {
    method: "POST",
    headers: apiHeaders,
    body: JSON.stringify({
      product_id: `web-test`,
      filename: file.name,
      content_type: file.type || "audio/wav",
    }),
  }).catch((err) =>
    NextResponse.json(
      { error: `Could not reach the watermark API: ${(err as Error).message}` },
      { status: 502 },
    ),
  );
  if (uploadUrlRes instanceof NextResponse) return uploadUrlRes;
  if (!uploadUrlRes.ok) {
    const text = await uploadUrlRes.text();
    return NextResponse.json(
      { error: `upload-url API returned ${uploadUrlRes.status}: ${text}` },
      { status: 502 },
    );
  }
  const { upload_url: uploadUrl, s3_key: s3Key } = await uploadUrlRes.json();

  // Step 2: PUT the file directly to S3 using the presigned URL.
  const bytes = Buffer.from(await file.arrayBuffer());
  const putRes = await fetch(uploadUrl, {
    method: "PUT",
    body: bytes,
    headers: { "Content-Type": file.type || "audio/wav" },
  }).catch((err) =>
    NextResponse.json(
      { error: `S3 upload failed: ${(err as Error).message}` },
      { status: 502 },
    ),
  );
  if (putRes instanceof NextResponse) return putRes;
  if (!putRes.ok) {
    return NextResponse.json(
      { error: `S3 PUT returned ${putRes.status}` },
      { status: 502 },
    );
  }

  // Step 3: call /watermark — embeds order_id as the watermark code and
  // transcodes to MP3. Idempotent: re-calling returns a fresh presigned URL.
  const wmRes = await fetch(`${cfg.apiBaseUrl}/watermark`, {
    method: "POST",
    headers: apiHeaders,
    body: JSON.stringify({ master_key: s3Key, order_id: orderId }),
  }).catch((err) =>
    NextResponse.json(
      { error: `Could not reach the watermark API: ${(err as Error).message}` },
      { status: 502 },
    ),
  );
  if (wmRes instanceof NextResponse) return wmRes;
  if (!wmRes.ok) {
    const text = await wmRes.text();
    const hint =
      wmRes.status === 403
        ? " (set WATERMARK_API_KEY in .env.local)"
        : "";
    return NextResponse.json(
      { error: `Watermark API returned ${wmRes.status}: ${text}${hint}` },
      { status: 502 },
    );
  }

  const { download_url: downloadUrl, watermark_code: watermarkCode, from_cache: fromCache } =
    await wmRes.json();

  // The watermarked buyer copy lives at this deterministic key.
  const resultKey = `orders/${orderId}.mp3`;

  return NextResponse.json({
    status: "ok",
    orderId,
    watermarkCode,
    watermarkUrl: downloadUrl,
    masterKey: s3Key,
    resultKey,
    fromCache,
  });
}
