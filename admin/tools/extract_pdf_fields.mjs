#!/usr/bin/env node
import fs from 'fs';
import path from 'path';
import { fileURLToPath, pathToFileURL } from 'url';

global.DOMMatrix = class {};

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const pdfjsPath = path.resolve(__dirname, '../../assets/pdfjs/pdf.min.mjs');
const pdfjsLib = await import(pathToFileURL(pdfjsPath).href);

const FIELD_TYPES = ['text', 'multiline', 'date', 'number', 'grade', 'checkbox', 'radio', 'select', 'signature'];

function normalizeType(rawType, multilineFlag) {
  const t = String(rawType || '').trim();
  const u = t.toUpperCase();
  if (u === 'TX' || u === 'TEXT') return multilineFlag ? 'multiline' : 'text';
  if (u === 'CH' || u === 'SELECT') return 'select';
  if (u === 'SIG' || u === 'SIGNATURE') return 'signature';
  if (u === 'BTN') return 'checkbox';
  if (u === 'CHECKBOX') return 'checkbox';
  if (u === 'RADIO') return 'radio';
  return multilineFlag ? 'multiline' : 'radio';
}

function pickLabelFromLine(textItems, fieldRect) {
  const cy = (fieldRect[1] + fieldRect[3]) / 2;
  const fh = Math.abs(fieldRect[3] - fieldRect[1]);
  const yTol = Math.max(6, fh * 0.6);
  const lineTol = Math.max(3, fh * 0.3);
  const yMin = Math.min(fieldRect[1], fieldRect[3]) - yTol * 1.2;
  const yMax = Math.max(fieldRect[1], fieldRect[3]) + yTol * 1.2;
  const xMin = Math.min(fieldRect[0], fieldRect[2]);
  const xMax = Math.max(fieldRect[0], fieldRect[2]);
  const xTol = Math.max(6, Math.abs(fieldRect[2] - fieldRect[0]) * 0.15);

  const buildLines = (items) => {
    const sorted = items.slice().sort((a, b) => (b.y - a.y) || (a.x - b.x));
    const lines = [];
    for (const item of sorted) {
      const line = lines.find(l => Math.abs(l.y - item.y) <= lineTol);
      if (line) {
        line.items.push(item);
        line.y = (line.y * (line.items.length - 1) + item.y) / line.items.length;
      } else {
        lines.push({ y: item.y, items: [item] });
      }
    }
    return lines.map(line => {
      const items = line.items.slice().sort((a, b) => a.x - b.x);
      const text = items.map(it => it.str.trim()).join(' ').replace(/\s+/g, ' ').trim();
      const xMax = Math.max(...items.map(it => it.x));
      const xMin = Math.min(...items.map(it => it.x));
      return { y: line.y, text, xMax, xMin };
    }).filter(l => l.text);
  };

  const rowLeftItems = textItems.filter(it => {
    if (!it.str || it.str.trim() === '') return false;
    if (it.x > xMin - 2) return false;
    return Math.abs(it.y - cy) <= Math.max(yTol, fh * 0.8);
  });
  if (rowLeftItems.length) {
    const sorted = rowLeftItems.slice().sort((a, b) => a.x - b.x);
    const segments = [];
    let seg = null;
    const gapTol = Math.max(10, fh * 0.8, lineTol * 2);
    for (const item of sorted) {
      if (!seg) {
        seg = { items: [item], xMin: item.x, xMax: item.x, yAvg: item.y };
        continue;
      }
      const gap = item.x - seg.xMax;
      if (gap > gapTol) {
        segments.push(seg);
        seg = { items: [item], xMin: item.x, xMax: item.x, yAvg: item.y };
      } else {
        seg.items.push(item);
        seg.xMax = Math.max(seg.xMax, item.x);
        seg.yAvg = (seg.yAvg * (seg.items.length - 1) + item.y) / seg.items.length;
      }
    }
    if (seg) segments.push(seg);

    const candidates = segments
      .filter(s => Math.abs(s.yAvg - cy) <= Math.max(lineTol * 2, fh))
      .filter(s => s.xMax <= xMin + 2);

    if (candidates.length) {
      candidates.sort((a, b) => (xMin - a.xMax) - (xMin - b.xMax));
      const target = candidates[0];
      const label = target.items.map(it => it.str.trim()).join(' ').replace(/\s+/g, ' ').trim();
      if (label.length >= 2) return label;
    }
  }

  const inColumnItems = textItems.filter(it => {
    if (!it.str || it.str.trim() === '') return false;
    if (it.x < xMin - xTol || it.x > xMax + xTol) return false;
    return it.y >= yMax && it.y <= (yMax + yTol * 3);
  });

  const columnLines = buildLines(inColumnItems).sort((a, b) => b.y - a.y);
  if (columnLines.length) {
    const label = columnLines.map(l => l.text).join(' ').replace(/\s+/g, ' ').trim();
    if (label.length >= 2) return label;
  }

  const leftItems = textItems.filter(it => {
    if (!it.str || it.str.trim() === '') return false;
    if (it.x > xMin - 2) return false;
    return it.y >= yMin && it.y <= yMax;
  });

  if (!leftItems.length) return null;

  const lines = buildLines(leftItems);
  if (!lines.length) return null;

  lines.sort((a, b) => (xMin - a.xMax) - (xMin - b.xMax));
  const anchor = lines[0];
  const maxDx = (xMin - anchor.xMax) + Math.max(8, fh * 0.4);

  const selected = lines
    .filter(l => (xMin - l.xMax) <= maxDx)
    .sort((a, b) => b.y - a.y);

  const label = selected.map(l => l.text).join(' ').replace(/\s+/g, ' ').trim();
  if (label.length < 2) return null;
  return label;
}

async function main() {
  const pdfPath = process.argv[2];
  if (!pdfPath) {
    console.error('Missing PDF path.');
    process.exit(1);
  }

  const data = new Uint8Array(fs.readFileSync(pdfPath));
  if (pdfjsLib.GlobalWorkerOptions) {
    pdfjsLib.GlobalWorkerOptions.workerSrc = '';
  }

  const loadingTask = pdfjsLib.getDocument({ data, disableWorker: true });
  const pdf = await loadingTask.promise;

  const out = new Map();
  let sort = 0;

  if (pdf.getFieldObjects) {
    const fo = await pdf.getFieldObjects();
    if (fo && typeof fo === 'object') {
      for (const [name, arr] of Object.entries(fo)) {
        const first = (Array.isArray(arr) && arr[0]) ? arr[0] : {};
        const rawType = first.type || first.fieldType || '';
        const multilineFlag = !!(first.multiline || first.multiLine);
        const type = normalizeType(rawType, multilineFlag);

        out.set(name, {
          name,
          type,
          label: name,
          help_text: '',
          multiline: multilineFlag,
          sort: sort++,
          meta: { type: rawType, multiline: multilineFlag }
        });
      }
    }
  }

  for (let p = 1; p <= pdf.numPages; p++) {
    const page = await pdf.getPage(p);

    const textContent = await page.getTextContent();
    const textItems = (textContent.items || []).map(it => {
      const str = (it.str || '').toString();
      const tr = it.transform || [0, 0, 0, 0, 0, 0];
      return { str, x: tr[4] || 0, y: tr[5] || 0 };
    });

    const annots = await page.getAnnotations({ intent: 'display' });
    for (const a of annots) {
      if (a.subtype !== 'Widget') continue;
      const name = (a.fieldName || '').toString().trim();
      if (!name) continue;

      const rect = Array.isArray(a.rect) && a.rect.length === 4 ? a.rect : null;
      const rawType = a.fieldType || a.type || '';
      let type = normalizeType(rawType, false);

      if (a.radioButton === true) type = 'radio';
      if (a.checkBox === true) type = 'checkbox';

      const hint = (a.alternativeText || a.altText || a.tooltip || a.title || a.fieldLabel || '')?.toString?.() || '';

      if (!out.has(name)) {
        out.set(name, {
          name,
          type: FIELD_TYPES.includes(type) ? type : 'radio',
          label: name,
          help_text: hint || '',
          multiline: false,
          sort: sort++,
          meta: { type: rawType }
        });
      } else {
        const it = out.get(name);
        if (it && type === 'radio') it.type = 'radio';
        if (it && !it.help_text && hint) it.help_text = hint;
      }

      const item = out.get(name);
      if (item && rect) {
        item.meta = item.meta || {};
        if (!item.meta.page) item.meta.page = p;
        if (!item.meta.rect) item.meta.rect = rect;

        const suggested = pickLabelFromLine(textItems, rect);
        if (suggested) {
          if (!item.label || item.label === item.name) item.label = suggested;
          if (!item.help_text && suggested.length > 18) item.help_text = suggested;
        }
      }
    }
  }

  const fields = Array.from(out.values()).sort((a, b) => (a.sort ?? 0) - (b.sort ?? 0));
  process.stdout.write(JSON.stringify(fields));
}

main().catch(err => {
  console.error(err?.stack || err);
  process.exit(1);
});
