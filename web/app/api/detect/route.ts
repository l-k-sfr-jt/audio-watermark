import { NextRequest, NextResponse } from "next/server";
import { execFile } from "node:child_process";
import { mkdtemp, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import path from "node:path";
import { promisify } from "node:util";
import { getConfig } from "@/lib/config";
import { getObjectBytes } from "@/lib/aws";

export const runtime = "nodejs";

const execFileAsync = promisify(execFile);

// web/ is a subfolder of the repo; cli.py lives at the repo root (one level up).
const REPO_ROOT = path.resolve(process.cwd(), "..");

/**
 * Detection has no deployed endpoint — and the PN matrix is seeded with numpy's
 * RNG, which can't be reproduced in JS — so we run the project's own Python CLI.
 * In production this mirrors reality: detection is an offline forensic step run
 * on a recovered leaked file, not a buyer-facing API call.
 */
async function detectFile(localPath: string, pythonBin: string): Promise<number> {
  const bin = path.isAbsolute(pythonBin) ? pythonBin : path.resolve(REPO_ROOT, pythonBin);
  const { stdout } = await execFileAsync(bin, ["cli.py", "detect", localPath], {
    cwd: REPO_ROOT,
  });
  const match = stdout.match(/Detected user_id:\s*(\d+)/);
  if (!match) {
    throw new Error(`Could not parse detector output: ${stdout.trim()}`);
  }
  return Number(match[1]);
}

export async function POST(req: NextRequest) {
  let cfg;
  try {
    cfg = getConfig();
  } catch (err) {
    return NextResponse.json({ error: (err as Error).message }, { status: 500 });
  }

  let bytes: Buffer;
  let ext = ".wav";
  const contentType = req.headers.get("content-type") || "";

  try {
    if (contentType.includes("application/json")) {
      // Detect the just-embedded result by S3 key (avoids browser→S3 CORS).
      const { key } = await req.json();
      if (typeof key !== "string" || !key) {
        return NextResponse.json({ error: "JSON body must include a string 'key'" }, { status: 400 });
      }
      bytes = await getObjectBytes(key);
      ext = path.extname(key) || ".wav";
    } else {
      // Detect an arbitrary uploaded file.
      const form = await req.formData();
      const file = form.get("file");
      if (!(file instanceof File)) {
        return NextResponse.json({ error: "No audio file provided" }, { status: 400 });
      }
      bytes = Buffer.from(await file.arrayBuffer());
      ext = path.extname(file.name) || ".wav";
    }
  } catch (err) {
    return NextResponse.json(
      { error: `Could not read audio to detect: ${(err as Error).message}` },
      { status: 502 },
    );
  }

  const dir = await mkdtemp(path.join(tmpdir(), "wm-detect-"));
  const localPath = path.join(dir, `audio${ext}`);
  try {
    await writeFile(localPath, bytes);
    const userId = await detectFile(localPath, cfg.pythonBin);
    return NextResponse.json({ status: "ok", userId });
  } catch (err) {
    const msg = (err as Error).message;
    const hint = /ENOENT|not found|No such file/.test(msg)
      ? " (check PYTHON_BIN in .env.local points at the project's .venv)"
      : "";
    return NextResponse.json({ error: `Detection failed: ${msg}${hint}` }, { status: 500 });
  } finally {
    await rm(dir, { recursive: true, force: true });
  }
}
