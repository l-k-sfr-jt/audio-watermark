import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = {
  title: "Audio Watermark — Test Console",
  description: "Local testing UI for the forensic audio-watermark service",
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en">
      <body>{children}</body>
    </html>
  );
}
