# Container-image Lambda. We use an image (not zip) because the watermarking
# stack is heavy: scipy/numpy are large, and MP3 decoding via pydub needs an
# ffmpeg binary that the Lambda runtime does not ship. soundfile bundles its
# own libsndfile in the PyPI wheel, so only ffmpeg has to be added by hand.
FROM public.ecr.aws/lambda/python:3.11

# --- ffmpeg (static build) for pydub MP3/AAC decoding -----------------------
# Amazon Linux 2 has no ffmpeg package, so drop in a static x86_64 binary.
# On Apple Silicon you can build faster/cheaper on arm64 instead: switch the
# function to Architectures: [arm64] in template.yaml and change the URL below
# to the arm64 static build.
RUN yum install -y tar xz && \
    curl -L https://johnvansickle.com/ffmpeg/releases/ffmpeg-release-amd64-static.tar.xz \
        -o /tmp/ffmpeg.tar.xz && \
    tar -xf /tmp/ffmpeg.tar.xz -C /tmp && \
    cp /tmp/ffmpeg-*-amd64-static/ffmpeg  /usr/local/bin/ffmpeg && \
    cp /tmp/ffmpeg-*-amd64-static/ffprobe /usr/local/bin/ffprobe && \
    rm -rf /tmp/ffmpeg* && \
    yum clean all

# --- Python dependencies ----------------------------------------------------
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# --- Application code --------------------------------------------------------
COPY src/ ${LAMBDA_TASK_ROOT}/src/

# Lambda handler: module path . function name
CMD ["src.handler.lambda_handler"]
