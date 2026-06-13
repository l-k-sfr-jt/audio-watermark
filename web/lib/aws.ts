import { GetObjectCommand, PutObjectCommand, S3Client } from "@aws-sdk/client-s3";
import { getSignedUrl } from "@aws-sdk/s3-request-presigner";
import { getConfig } from "@/lib/config";

// One S3 client per server process, reused across requests. Credentials come
// from the default AWS provider chain (~/.aws/credentials, AWS_PROFILE, env),
// the same source the `aws` CLI uses — so no keys live in this app.
let client: S3Client | null = null;

function s3(): S3Client {
  if (!client) {
    client = new S3Client({ region: getConfig().region });
  }
  return client;
}

/** Upload raw bytes to the bucket under `key`. */
export async function putObject(key: string, body: Buffer, contentType: string): Promise<void> {
  const { bucket } = getConfig();
  await s3().send(
    new PutObjectCommand({ Bucket: bucket, Key: key, Body: body, ContentType: contentType }),
  );
}

/** Download an object's bytes from the bucket. */
export async function getObjectBytes(key: string): Promise<Buffer> {
  const { bucket } = getConfig();
  const res = await s3().send(new GetObjectCommand({ Bucket: bucket, Key: key }));
  const bytes = await res.Body!.transformToByteArray();
  return Buffer.from(bytes);
}

/**
 * Presign a GET URL so the browser can play / download the object directly.
 * Default 1 h is plenty for a test session (the production email uses 48 h).
 */
export async function presignGet(key: string, expiresIn = 3600): Promise<string> {
  const { bucket } = getConfig();
  return getSignedUrl(s3(), new GetObjectCommand({ Bucket: bucket, Key: key }), { expiresIn });
}
