/** @type {import('next').NextConfig} */
const nextConfig = {
  // The /api/embed route receives full audio uploads as multipart form data.
  // Next's default body-size cap is generous for Route Handlers, but be explicit
  // about server actions in case larger masters are tested.
  experimental: {
    serverActions: {
      bodySizeLimit: "50mb",
    },
  },
};

export default nextConfig;
