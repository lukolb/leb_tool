import { HF_BASE } from './fixtures.js';

/**
 * Retrieves all available voices from huggingface
 */
export async function voices() {
  const res = await fetch(`${HF_BASE}/voices.json`);

  if (!res.ok) {
    throw new Error('Could not retrieve voices file from huggingface');
  }

  return Object.values(await res.json());
}
