import { GetObjectCommand, S3Client } from "@aws-sdk/client-s3";
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

/** Download an object's bytes from the bucket (used for local detection). */
export async function getObjectBytes(key: string): Promise<Buffer> {
  const { bucket } = getConfig();
  const res = await s3().send(new GetObjectCommand({ Bucket: bucket, Key: key }));
  const bytes = await res.Body!.transformToByteArray();
  return Buffer.from(bytes);
}
