import Jimp from "jimp";
import { S3Client, GetObjectCommand } from "@aws-sdk/client-s3";

const REGION = "us-east-1";
const BUCKET_NAME = process.env.BUCKET_NAME || "GANTI_DENGAN_NAMA_BUCKET";
const ALLOWED_TEMPLATES = new Set([
  "latar1.jpeg",
  "latar2.jpeg",
  "latar3.jpeg",
  "latar4.jpeg",
]);

const s3 = new S3Client({ region: REGION });

async function streamToBuffer(stream) {
  const chunks = [];
  for await (const chunk of stream) chunks.push(chunk);
  return Buffer.concat(chunks);
}

function cleanText(value, maxLength) {
  return String(value || "").trim().slice(0, maxLength);
}

export const handler = async (event) => {
  try {
    const qs = event.queryStringParameters || {};
    const template = cleanText(qs.template || "latar1.jpeg", 50);
    const judul = cleanText(qs.judul || "Judul Puisi", 150);
    const penulis = cleanText(qs.penulis || "Penulis", 100);
    const bait = cleanText(qs.bait || "Penggalan bait puisi", 700);

    if (!ALLOWED_TEMPLATES.has(template)) {
      return {
        statusCode: 400,
        headers: { "content-type": "text/plain; charset=utf-8" },
        body: "Template tidak valid",
      };
    }

    if (!judul || !bait) {
      return {
        statusCode: 400,
        headers: { "content-type": "text/plain; charset=utf-8" },
        body: "Judul dan bait wajib diisi",
      };
    }

    const s3Res = await s3.send(
      new GetObjectCommand({
        Bucket: BUCKET_NAME,
        Key: template,
      }),
    );

    const buffer = await streamToBuffer(s3Res.Body);
    const image = await Jimp.read(buffer);
    const width = image.bitmap.width;
    const height = image.bitmap.height;
    const sidePadding = Math.max(40, Math.round(width * 0.10));
    const textWidth = width - sidePadding * 2;

    const titleFont = await Jimp.loadFont(Jimp.FONT_SANS_32_BLACK);
    const bodyFont = await Jimp.loadFont(Jimp.FONT_SANS_16_BLACK);

    image.print(
      titleFont,
      sidePadding,
      Math.round(height * 0.25),
      {
        text: judul,
        alignmentX: Jimp.HORIZONTAL_ALIGN_CENTER,
        alignmentY: Jimp.VERTICAL_ALIGN_MIDDLE,
      },
      textWidth,
      Math.round(height * 0.18),
    );

    image.print(
      bodyFont,
      sidePadding,
      Math.round(height * 0.46),
      {
        text: `— ${penulis} —`,
        alignmentX: Jimp.HORIZONTAL_ALIGN_CENTER,
        alignmentY: Jimp.VERTICAL_ALIGN_MIDDLE,
      },
      textWidth,
      Math.round(height * 0.08),
    );

    image.print(
      bodyFont,
      sidePadding,
      Math.round(height * 0.56),
      {
        text: `“${bait}”`,
        alignmentX: Jimp.HORIZONTAL_ALIGN_CENTER,
        alignmentY: Jimp.VERTICAL_ALIGN_MIDDLE,
      },
      textWidth,
      Math.round(height * 0.30),
    );

    const outputBuffer = await image.getBufferAsync(Jimp.MIME_JPEG);

    return {
      statusCode: 200,
      headers: {
        "content-type": "image/jpeg",
        "cache-control": "public, max-age=86400",
      },
      body: outputBuffer.toString("base64"),
      isBase64Encoded: true,
    };
  } catch (err) {
    console.error("Processing error:", err);
    return {
      statusCode: 500,
      headers: { "content-type": "text/plain; charset=utf-8" },
      body: "Error: " + err.message,
    };
  }
};
