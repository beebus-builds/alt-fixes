// server.js
import express from 'express';
import cors from 'cors';
import multer from 'multer';
import fs from 'fs';
import path from 'path';
import { pipeline } from '@xenova/transformers';
import fetch from 'node-fetch';

const app = express();
app.use(cors());
app.use(express.json({ limit: '10mb' }));

const API_KEY = process.env.API_KEY || '';
function requireApiKey(req, res, next) {
  if (!API_KEY) return next();
  const key = req.headers['x-api-key'] || req.query.api_key;
  if (key !== API_KEY) return res.status(401).json({ error: 'Unauthorized' });
  next();
}
app.use('/caption', requireApiKey);

const upload = multer({ storage: multer.memoryStorage(), limits: { fileSize: 15 * 1024 * 1024 } });

let captionPipeline = null;

async function loadPipeline() {
  if (!captionPipeline) {
    console.log('Loading image-to-text pipeline...');
    captionPipeline = await pipeline('image-to-text', 'Xenova/vit-gpt2-image-captioning', { quantized: true });
    console.log('Model loaded.');
  }
  return captionPipeline;
}

async function getImageBuffer(input) {
  if (input.startsWith('data:image')) {
    const base64 = input.split(',')[1];
    return Buffer.from(base64, 'base64');
  }
  if (input.startsWith('http')) {
    const res = await fetch(input);
    if (!res.ok) throw new Error('Failed to fetch image');
    const buf = await res.buffer();
    return buf;
  }
  throw new Error('Unsupported image format');
}

app.post('/caption', upload.single('image'), async (req, res) => {
  try {
    let imageBuffer;
    if (req.file) {
      imageBuffer = req.file.buffer;
    } else if (req.body.image) {
      imageBuffer = await getImageBuffer(req.body.image);
    } else {
      return res.status(400).json({ error: 'Provide image file or base64/data URL in body.image' });
    }
    const pipe = await loadPipeline();
    const tmpPath = path.join(process.cwd(), 'tmp_' + Date.now() + '.jpg');
    fs.writeFileSync(tmpPath, imageBuffer);
    const output = await pipe(tmpPath);
    fs.unlinkSync(tmpPath);
    const caption = output?.[0]?.generated_text || '';
    res.json({ caption, alt_text: caption });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: err.message });
  }
});

app.get('/health', (req, res) => {
  res.json({ status: 'ok', model_loaded: !!captionPipeline });
});

const PORT = process.env.PORT || 3000;
app.listen(PORT, () => {
  console.log(`Caption API listening on http://localhost:${PORT}`);
});
