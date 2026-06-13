// Centralised, validated access to the environment configuration. Importing
// from here (rather than reading process.env inline) means a missing variable
// fails with one clear message instead of a confusing downstream AWS/SDK error.

export interface AppConfig {
  apiUrl: string;
  bucket: string;
  region: string;
  apiKey: string | undefined;
  notifyEmail: string;
  pythonBin: string;
}

function required(name: string): string {
  const value = process.env[name];
  if (!value) {
    throw new Error(
      `Missing required env var ${name}. Copy web/.env.local.example to ` +
        `web/.env.local and fill it in (see the deploy script's stack outputs).`,
    );
  }
  return value;
}

export function getConfig(): AppConfig {
  return {
    apiUrl: required("WATERMARK_API_URL"),
    bucket: required("WATERMARK_BUCKET"),
    region: process.env.AWS_REGION || "eu-central-1",
    apiKey: process.env.WATERMARK_API_KEY || undefined,
    notifyEmail: process.env.NOTIFY_EMAIL || "test@example.com",
    pythonBin: process.env.PYTHON_BIN || "python3",
  };
}
