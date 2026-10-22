FROM mcr.microsoft.com/playwright:v1.64.0-noble
USER root
WORKDIR /worker
COPY services/document-service/requirements.txt /worker/requirements.txt
RUN apt-get update && apt-get install -y --no-install-recommends python3-venv php-cli fonts-liberation \
    && python3 -m venv /opt/documents-venv \
    && /opt/documents-venv/bin/pip install --no-cache-dir -r /worker/requirements.txt \
    && rm -rf /var/lib/apt/lists/*
COPY services/document-service/package.json services/document-service/package-lock.json /worker/
RUN npm ci --omit=dev --ignore-scripts
COPY scripts/export-cv.mjs scripts/extract-cv-pdf.py scripts/write-cv-word.php /worker/scripts/
COPY app/Services/CvWordWriter.php /worker/app/Services/CvWordWriter.php
COPY services/document-service/server.mjs services/document-service/health.mjs /worker/services/document-service/
RUN mkdir -p /var/lib/document-service/nonces /var/lib/document-service/tmp \
    && chown -R pwuser:pwuser /var/lib/document-service
ENV NODE_ENV=production DOCUMENT_BIND=0.0.0.0 PORT=3100 \
    DOCUMENT_NONCE_DIR=/var/lib/document-service/nonces \
    DOCUMENT_TEMP_DIR=/var/lib/document-service/tmp DOCUMENT_BROWSER_CHANNEL=chromium
USER pwuser
EXPOSE 3100
CMD ["node", "services/document-service/server.mjs"]
