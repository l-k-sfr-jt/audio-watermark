"use client";

import { useEffect, useMemo, useState } from "react";

interface EmbedResult {
  userId: number;
  orderId: string;
  resultKey: string;
  watermarkUrl: string;
}

export default function Home() {
  // ---- Embed state ----
  const [file, setFile] = useState<File | null>(null);
  const [userId, setUserId] = useState("4582");
  const [embedding, setEmbedding] = useState(false);
  const [embed, setEmbed] = useState<EmbedResult | null>(null);
  const [embedError, setEmbedError] = useState<string | null>(null);

  // Object URL for playing the chosen original locally (no upload needed).
  const originalUrl = useMemo(() => (file ? URL.createObjectURL(file) : null), [file]);
  useEffect(() => () => { if (originalUrl) URL.revokeObjectURL(originalUrl); }, [originalUrl]);

  // ---- Detect state ----
  const [detecting, setDetecting] = useState(false);
  const [detected, setDetected] = useState<number | null>(null);
  const [detectError, setDetectError] = useState<string | null>(null);
  const [detectFile, setDetectFile] = useState<File | null>(null);

  async function runEmbed() {
    if (!file) return;
    setEmbedding(true);
    setEmbed(null);
    setEmbedError(null);
    setDetected(null);
    setDetectError(null);
    try {
      const fd = new FormData();
      fd.append("file", file);
      fd.append("user_id", userId);
      const res = await fetch("/api/embed", { method: "POST", body: fd });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error ?? `Request failed (${res.status})`);
      setEmbed(data);
    } catch (err) {
      setEmbedError((err as Error).message);
    } finally {
      setEmbedding(false);
    }
  }

  // Verify the watermark survived by detecting the just-embedded result. We
  // pass the S3 key (not the file) so the server reads it directly and there's
  // no browser→S3 CORS hop.
  async function detectResult() {
    if (!embed) return;
    setDetecting(true);
    setDetected(null);
    setDetectError(null);
    try {
      const res = await fetch("/api/detect", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ key: embed.resultKey }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error ?? `Request failed (${res.status})`);
      setDetected(data.userId);
    } catch (err) {
      setDetectError((err as Error).message);
    } finally {
      setDetecting(false);
    }
  }

  async function detectUpload() {
    if (!detectFile) return;
    setDetecting(true);
    setDetected(null);
    setDetectError(null);
    try {
      const fd = new FormData();
      fd.append("file", detectFile);
      const res = await fetch("/api/detect", { method: "POST", body: fd });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error ?? `Request failed (${res.status})`);
      setDetected(data.userId);
    } catch (err) {
      setDetectError((err as Error).message);
    } finally {
      setDetecting(false);
    }
  }

  const expectedId = Number(userId);
  const detectMatches = detected !== null && detected === expectedId;

  return (
    <main>
      <h1>Audio Watermark — Test Console</h1>
      <p className="subtitle">
        Embed via the deployed AWS API, then verify by detecting locally. For testing only.
      </p>

      {/* ---------------- Embed ---------------- */}
      <section className="panel">
        <h2>1 · Embed a watermark (AWS)</h2>
        <div className="row">
          <div>
            <label htmlFor="audio">Audio file (MP3 / WAV)</label>
            <input
              id="audio"
              type="file"
              accept="audio/*,.mp3,.wav,.flac"
              onChange={(e) => setFile(e.target.files?.[0] ?? null)}
            />
          </div>
          <div>
            <label htmlFor="uid">User ID (32-bit integer)</label>
            <input
              id="uid"
              type="number"
              min={0}
              value={userId}
              onChange={(e) => setUserId(e.target.value)}
            />
          </div>
        </div>

        {originalUrl && (
          <>
            <label>Original</label>
            <audio src={originalUrl} controls />
          </>
        )}

        <button onClick={runEmbed} disabled={!file || embedding}>
          {embedding ? "Embedding…" : "Embed via AWS"}
        </button>
        <p className="hint">
          Uploads to S3 → calls the Lambda → presigns the watermarked result.
        </p>

        {embedError && <div className="result err">{embedError}</div>}

        {embed && (
          <div className="result ok">
            <div>
              Embedded user_id <strong>{embed.userId}</strong> · order{" "}
              <code>{embed.orderId}</code>
            </div>
            <label style={{ marginTop: "0.75rem" }}>Watermarked</label>
            <audio src={embed.watermarkUrl} controls />
            <a className="download" href={embed.watermarkUrl} download={`${embed.orderId}.wav`}>
              ↓ Download watermarked WAV
            </a>
          </div>
        )}
      </section>

      {/* ---------------- Detect ---------------- */}
      <section className="panel">
        <h2>2 · Detect a watermark (local CLI)</h2>

        {embed ? (
          <button className="secondary" onClick={detectResult} disabled={detecting}>
            {detecting ? "Detecting…" : "Detect the result above"}
          </button>
        ) : (
          <p className="hint">Embed something first, or upload a file below to detect.</p>
        )}

        <div style={{ marginTop: "1.25rem" }}>
          <label htmlFor="detectAudio">…or detect any audio file</label>
          <input
            id="detectAudio"
            type="file"
            accept="audio/*,.mp3,.wav,.flac"
            onChange={(e) => setDetectFile(e.target.files?.[0] ?? null)}
          />
          <button className="secondary" onClick={detectUpload} disabled={!detectFile || detecting}>
            {detecting ? "Detecting…" : "Detect uploaded file"}
          </button>
        </div>

        {detectError && <div className="result err">{detectError}</div>}

        {detected !== null && (
          <div className={`result ${detectMatches ? "ok" : "err"}`}>
            <div>
              Detected user_id: <span className="badge">{detected}</span>
            </div>
            {embed && (
              <div className="hint">
                {detectMatches
                  ? `✓ matches the embedded id (${expectedId})`
                  : `✗ does not match the embedded id (${expectedId})`}
              </div>
            )}
          </div>
        )}
      </section>
    </main>
  );
}
