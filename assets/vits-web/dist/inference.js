import { HF_BASE, ONNX_BASE, PATH_MAP, WASM_BASE } from './fixtures.js';
import { readBlob, writeBlob } from './opfs.js';
import { fetchBlob } from './http.js';
import { pcm2wav } from './audio.js';

export class TtsSession {
  ready = false;
  voiceId;
  waitReady;
  #createPiperPhonemize;
  #modelConfig;
  #ort;
  #ortSession;
  #progressCallback;

  constructor({ voiceId, progress }) {
    this.voiceId = voiceId;
    this.#progressCallback = progress;
    this.waitReady = this.init();
  }

  static async create(options) {
    const session = new TtsSession(options);
    await session.waitReady;
    return session;
  }

  async init() {
    const { createPiperPhonemize } = await import('../src/piper.js');
    this.#createPiperPhonemize = createPiperPhonemize;
    this.#ort = await import('onnxruntime-web');

    this.#ort.env.allowLocalModels = false;
    this.#ort.env.wasm.numThreads = navigator.hardwareConcurrency;
    this.#ort.env.wasm.wasmPaths = ONNX_BASE;

    const path = PATH_MAP[this.voiceId];
    const modelConfigBlob = await getBlob(`${HF_BASE}/${path}.json`);
    this.#modelConfig = JSON.parse(await modelConfigBlob.text());

    const modelBlob = await getBlob(
      `${HF_BASE}/${path}`,
      this.#progressCallback
    );
    this.#ortSession = await this.#ort.InferenceSession.create(
      await modelBlob.arrayBuffer()
    );
  }

  async predict(text) {
    await this.waitReady; // wait for the session to be ready

    const input = JSON.stringify([{ text: text.trim() }]);

    const phonemeIds = await new Promise(async (resolve) => {
      const module = await this.#createPiperPhonemize({
        print: (data) => {
          resolve(JSON.parse(data).phoneme_ids);
        },
        printErr: (message) => {
          throw new Error(message);
        },
        locateFile: (url) => {
          if (url.endsWith('.wasm')) return `${WASM_BASE}.wasm`;
          if (url.endsWith('.data')) return `${WASM_BASE}.data`;
          return url;
        },
      });

      module.callMain([
        '-l',
        this.#modelConfig.espeak.voice,
        '--input',
        input,
        '--espeak_data',
        '/espeak-ng-data',
      ]);
    });

    const speakerId = 0;
    const sampleRate = this.#modelConfig.audio.sample_rate;
    const noiseScale = this.#modelConfig.inference.noise_scale;
    const lengthScale = this.#modelConfig.inference.length_scale;
    const noiseW = this.#modelConfig.inference.noise_w;

    const session = this.#ortSession;
    const feeds = {
      input: new this.#ort.Tensor('int64', phonemeIds, [1, phonemeIds.length]),
      input_lengths: new this.#ort.Tensor('int64', [phonemeIds.length]),
      scales: new this.#ort.Tensor('float32', [
        noiseScale,
        lengthScale,
        noiseW,
      ]),
    };
    if (Object.keys(this.#modelConfig.speaker_id_map).length) {
      Object.assign(feeds, {
        sid: new this.#ort.Tensor('int64', [speakerId]),
      });
    }

    const {
      output: { data: pcm },
    } = await session.run(feeds);

    return new Blob([pcm2wav(pcm, 1, sampleRate)], {
      type: 'audio/x-wav',
    });
  }
}

/**
 * Run text to speech inference in new worker thread. Fetches the model
 * first, if it has not yet been saved to opfs yet.
 */
export async function predict(config, callback) {
  const session = new TtsSession({
    voiceId: config.voiceId,
    progress: callback,
  });
  return session.predict(config.text);
}

/**
 * Tries to get blob from opfs, if it's not stored
 * yet the method will fetch the blob.
 */
async function getBlob(url, callback) {
  let blob = await readBlob(url);

  if (!blob) {
    blob = await fetchBlob(url, callback);
    await writeBlob(url, blob);
  }

  return blob;
}
