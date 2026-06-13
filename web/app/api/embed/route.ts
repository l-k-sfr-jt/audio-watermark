import { NextRequest, NextResponse } from "next/server";
import { getConfig } from "@/lib/config";
import { presignGet, putObject } from "@/lib/aws";

export const runtime = "nodejs"; // uses the AWS SDK + Buffer — not the edge runtime

// order_id is validated by the Lambda as ^[A-Za-z0-9_\-]+$ (it ends up in the
// S3 key), so we generate one that always satisfies that.
function newOrderId(): string {
  return `web-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
}

// Keep an upload key that is a safe relative S3 path (no traversal, no spaces).
function uploadKey(orderId: string, filename: string): string {
  const ext = (filename.match(/\.[A-Za-z0-9]+$/)?.[0] ?? "").toLowerCase();
  return `uploads/${orderId}${ext}`;
}

export async function POST(req: NextRequest) {
  let cfg;
  try {
    cfg = getConfig();
  } catch (err) {
    return NextResponse.json({ error: (err as Error).message }, { status: 500 });
  }

  const form = await req.formData();
  const file = form.get("file");
  const userIdRaw = form.get("user_id");

  if (!(file instanceof File)) {
    return NextResponse.json({ error: "No audio file provided" }, { status: 400 });
  }
  const userId = Number(userIdRaw);
  if (!Number.isInteger(userId) || userId < 0 || userId >= 2 ** 32) {
    return NextResponse.json(
      { error: "user_id must be a 32-bit non-negative integer" },
      { status: 400 },
    );
  }

  const orderId = newOrderId();
  const s3Key = uploadKey(orderId, file.name);
  const bytes = Buffer.from(await file.arrayBuffer());

  // 1. Stage the master in S3 so the Lambda can read it by key.
  try {
    await putObject(s3Key, bytes, file.type || "application/octet-stream");
  } catch (err) {
    return NextResponse.json(
      { error: `Failed to upload to S3: ${(err as Error).message}` },
      { status: 502 },
    );
  }

  // 2. Invoke the deployed watermark API (embeds + writes temp/{order}_{user}.wav).
  const headers: Record<string, string> = { "Content-Type": "application/json" };
  if (cfg.apiKey) headers["x-api-key"] = cfg.apiKey;

  let apiRes: Response;
  try {
    apiRes = await fetch(cfg.apiUrl, {
      method: "POST",
      headers,
      body: JSON.stringify({
        s3_key: s3Key,
        user_id: userId,
        email: cfg.notifyEmail,
        order_id: orderId,
      }),
    });
  } catch (err) {
    return NextResponse.json(
      { error: `Could not reach the watermark API: ${(err as Error).message}` },
      { status: 502 },
    );
  }

  const apiText = await apiRes.text();
  if (!apiRes.ok) {
    // 403 here almost always means the API now requires x-api-key — surface that.
    const hint =
      apiRes.status === 403
        ? " (the endpoint may now require an API key — set WATERMARK_API_KEY in .env.local)"
        : "";
    return NextResponse.json(
      { error: `Watermark API returned ${apiRes.status}: ${apiText}${hint}` },
      { status: 502 },
    );
  }

  // 3. The Lambda always writes the result to this deterministic key.
  const resultKey = `temp/${orderId}_${userId}.wav`;
  let watermarkUrl: string;
  try {
    watermarkUrl = await presignGet(resultKey);
  } catch (err) {
    return NextResponse.json(
      { error: `Watermark succeeded but presigning the result failed: ${(err as Error).message}` },
      { status: 502 },
    );
  }

  return NextResponse.json({
    status: "ok",
    userId,
    orderId,
    resultKey,
    watermarkUrl,
    apiResponse: apiText,
  });
}
