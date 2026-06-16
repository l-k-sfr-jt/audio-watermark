"use client";

import { useState } from "react";

interface EmbedResult {
  orderId: number;
  watermarkCode: number;
  masterKey: string;
  resultKey: string;
  watermarkUrl: string;
  fromCache: boolean;
}

export default function Home() {
  // ---- Embed state ----
  const [file, setFile] = useState<File | null>(null);
  const [orderId, setOrderId] = useState("1001");
  const [embedding, setEmbedding] = useState(false);
  const [embed, setEmbed] = useState<EmbedResult | null>(null);
  const [embedError, setEmbedError] = useState<string | null>(null);

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
      fd.append("order_id", orderId);
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

  // Detect using the S3 key returned by the embed (server reads directly from S3).
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

  const expectedId = Number(orderId);
  const detectMatches = detected !== null && detected === expectedId;

  return (
    <main>
      <h1>Audio Watermark — Test Console</h1>
      <p className="subtitle">
        Upload a master → watermark with order ID → play the MP3 → detect locally.
      </p>

      {/* ---------------- Embed ---------------- */}
      <section className="panel">
        <h2>1 · Embed a watermark (AWS)</h2>
        <div className="row">
          <div>
            <label htmlFor="audio">Master audio file (WAV recommended)</label>
            <input
              id="audio"
              type="file"
              accept="audio/*,.mp3,.wav,.flac"
              onChange={(e) => setFile(e.target.files?.[0] ?? null)}
            />
          </div>
          <div>
            <label htmlFor="oid">Order ID (WooCommerce order number)</label>
            <input
              id="oid"
              type="number"
              min={1}
              value={orderId}
              onChange={(e) => setOrderId(e.target.value)}
            />
          </div>
        </div>

        <button onClick={runEmbed} disabled={!file || embedding}>
          {embedding ? "Processing…" : "Watermark via AWS"}
        </button>
        <p className="hint">
          Uploads master → calls /products/upload-url → /watermark → returns MP3 link.
          Idempotent: re-running the same order ID returns a fresh link instantly.
        </p>

        {embedError && <div className="result err">{embedError}</div>}

        {embed && (
          <div className="result ok">
            <div>
              Watermark code <strong>{embed.watermarkCode}</strong> (order {embed.orderId})
              {embed.fromCache && <span className="hint"> · served from cache</span>}
            </div>
            <label style={{ marginTop: "0.75rem" }}>Watermarked MP3</label>
            <audio src={embed.watermarkUrl} controls />
            <a className="download" href={embed.watermarkUrl} download={`order-${embed.orderId}.mp3`}>
              ↓ Download watermarked MP3
            </a>
          </div>
        )}
      </section>

      {/* ---------------- Detect ---------------- */}
      <section className="panel">
        <h2>2 · Detect a watermark (local CLI)</h2>

        {embed ? (
          <>
            <button className="secondary" onClick={detectResult} disabled={detecting}>
              {detecting ? "Detecting…" : "Detect the master above"}
            </button>
            <p className="hint">
              Detects from the master S3 key (watermark is also in the MP3, but
              detection requires the uncompressed WAV used before transcoding).
            </p>
          </>
        ) : (
          <p className="hint">Embed something first, or upload a WAV file below to detect.</p>
        )}

        <div style={{ marginTop: "1.25rem" }}>
          <label htmlFor="detectAudio">…or detect any watermarked WAV</label>
          <input
            id="detectAudio"
            type="file"
            accept=".wav,audio/wav"
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
              Detected order ID (watermark code): <span className="badge">{detected}</span>
            </div>
            {embed && (
              <div className="hint">
                {detectMatches
                  ? `✓ matches the embedded order ID (${expectedId})`
                  : `✗ does not match the embedded order ID (${expectedId})`}
              </div>
            )}
          </div>
        )}
      </section>
    </main>
  );
}
