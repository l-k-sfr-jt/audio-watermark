"""Local CLI for embed / detect / roundtrip-test without any AWS dependency."""

import argparse
import os
import shutil
import sys
import tempfile

from src.watermark import detect_watermark, embed_watermark


def cmd_embed(args: argparse.Namespace) -> None:
    out = embed_watermark(args.input, args.user_id, args.output)
    print(f"Watermarked: {out}")


def cmd_detect(args: argparse.Namespace) -> None:
    uid, confidence = detect_watermark(args.input)
    if uid == -1:
        print("ERROR: audio too short to contain a watermark", file=sys.stderr)
        sys.exit(1)
    print(f"Detected user_id: {uid}  (confidence: {confidence:.2f})")


def cmd_roundtrip(args: argparse.Namespace) -> None:
    if shutil.which("ffmpeg") is None:
        print(
            "WARNING: ffmpeg not found on PATH — skipping roundtrip re-encode test.\n"
            "Install ffmpeg and re-run to validate MP3 robustness.",
            file=sys.stderr,
        )
        sys.exit(0)

    from pydub import AudioSegment

    print(f"Embedding watermark (user_id={args.user_id}) …")

    # All intermediate files go into a temp directory so nothing is left
    # behind in the input directory after the test completes.
    with tempfile.TemporaryDirectory() as tmp_dir:
        wm_path = os.path.join(tmp_dir, "watermarked.wav")
        embed_watermark(args.input, args.user_id, wm_path)
        print(f"  Embedded → {wm_path}")

        results = []
        for bitrate in (64, 128):
            mp3_path = os.path.join(tmp_dir, f"test_{bitrate}k.mp3")
            AudioSegment.from_wav(wm_path).export(mp3_path, format="mp3", bitrate=f"{bitrate}k")
            detected, _conf = detect_watermark(mp3_path)
            ok = detected == args.user_id
            results.append((bitrate, detected, ok))
            status = "PASS" if ok else "FAIL"
            print(f"  [{status}] {bitrate} kbps — expected {args.user_id}, detected {detected}")

    if not all(ok for _, _, ok in results):
        sys.exit(1)


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Forensic audio watermark — local CLI (no AWS required)"
    )
    sub = parser.add_subparsers(dest="command", required=True)

    p_embed = sub.add_parser("embed", help="Embed a user_id watermark into an audio file")
    p_embed.add_argument("input", help="Input audio file (MP3 or WAV)")
    p_embed.add_argument("user_id", type=int, help="32-bit integer user ID to embed")
    p_embed.add_argument("output", help="Output WAV path")
    p_embed.set_defaults(func=cmd_embed)

    p_detect = sub.add_parser("detect", help="Detect the watermark in an audio file")
    p_detect.add_argument("input", help="Watermarked audio file (MP3 or WAV)")
    p_detect.set_defaults(func=cmd_detect)

    p_rt = sub.add_parser(
        "roundtrip-test",
        help="Embed, re-encode through ffmpeg at 64 & 128 kbps, then detect",
    )
    p_rt.add_argument("input", help="Input audio file")
    p_rt.add_argument("user_id", type=int, help="32-bit integer user ID to embed")
    p_rt.set_defaults(func=cmd_roundtrip)

    args = parser.parse_args()
    args.func(args)


if __name__ == "__main__":
    main()
