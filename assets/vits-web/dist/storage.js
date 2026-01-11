import { PATH_MAP, HF_BASE } from './fixtures.js';
import { fetchBlob } from './http.js';
import { removeBlob, writeBlob } from './opfs.js';

/**
 * Prefetch a model for later use
 */
export async function download(voiceId, callback) {
  const path = PATH_MAP[voiceId];
  const urls = [`${HF_BASE}/${path}`, `${HF_BASE}/${path}.json`];

  await Promise.all(urls.map(async (url) => {
    writeBlob(url, await fetchBlob(url, url.endsWith('.onnx') ? callback : undefined));
  }));
}

/**
 * Remove a model from opfs
 */
export async function remove(voiceId) {
  const path = PATH_MAP[voiceId];
  const urls = [`${HF_BASE}/${path}`, `${HF_BASE}/${path}.json`];

  await Promise.all(urls.map(url => removeBlob(url)));
}

/**
 * Get all stored models
 */
export async function stored() {
  const root = await navigator.storage.getDirectory();
  const dir = await root.getDirectoryHandle('piper', {
    create: true,
  });
  const result = [];

  for await (const name of dir.keys()) {
    const key = name.split('.')[0];
    if (name.endsWith('.onnx') && key in PATH_MAP) {
      result.push(key);
    }
  }

  return result;
}

/**
 * Delete the models directory
 */
export async function flush() {
  try {
    const root = await navigator.storage.getDirectory();
    const dir = await root.getDirectoryHandle('piper');
    await dir.remove({ recursive: true });
  } catch (e) {
    console.error(e);
  }
}
