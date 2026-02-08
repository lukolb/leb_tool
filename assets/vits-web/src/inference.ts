import { InferenceConfg, ProgressCallback, VoiceId } from "./types";
import { HF_BASE, ONNX_BASE, PATH_MAP, WASM_BASE } from './fixtures';
import { readBlob, writeBlob } from './opfs';
import { fetchBlob } from './http.js';
import { pcm2wav } from './audio';

interface TtsSessionOptions {
  voiceId: VoiceId;
  progress?: ProgressCallback;
}

export class TtsSession {
  ready = false;
  voiceId: VoiceId;
  waitReady: Promise<void>;
  #createPiperPhonemize?: (moduleArg?: {}) => any;
  #modelConfig?: any;
  #ort?: typeof import("onnxruntime-web");
  #ortSession?: import("onnxruntime-web").InferenceSession
  #progressCallback?: ProgressCallback;
  #maxPhonemeId?: number;

  constructor({ voiceId, progress }: TtsSessionOptions) {
    this.voiceId = voiceId;
    this.#progressCallback = progress;
    this.waitReady = this.init();
  }

  static async create(options: TtsSessionOptions) {
    const session = new TtsSession(options);
    await session.waitReady;
    return session;
  }

  async init() {
    const { createPiperPhonemize } = await import("./piper.js");
    this.#createPiperPhonemize = createPiperPhonemize;
    this.#ort = await import("onnxruntime-web");

    this.#ort.env.allowLocalModels = false;
    this.#ort.env.wasm.numThreads = navigator.hardwareConcurrency;
    this.#ort.env.wasm.wasmPaths = ONNX_BASE;

    const path = PATH_MAP[this.voiceId];
    const modelConfigBlob = await getBlob(`${HF_BASE}/${path}.json`);
    this.#modelConfig = JSON.parse(await modelConfigBlob.text());
    this.#maxPhonemeId = this.resolveMaxPhonemeId(this.#modelConfig);

    const modelBlob = await getBlob(
      `${HF_BASE}/${path}`,
      this.#progressCallback
    );
    this.#ortSession = await this.#ort.InferenceSession.create(
      await modelBlob.arrayBuffer()
    );
  }

  async predict(text: string): Promise<Blob> {
    await this.waitReady; // wait for the session to be ready

    const input = JSON.stringify([{ text: text.trim() }]);

    const rawPhonemeIds: Array<number | string> = await new Promise(async (resolve) => {
      const module = await this.#createPiperPhonemize!({
        print: (data: any) => {
          resolve(JSON.parse(data).phoneme_ids);
        },
        printErr: (message: any) => {
          throw new Error(message);
        },
        locateFile: (url: string) => {
          if (url.endsWith(".wasm")) return `${WASM_BASE}.wasm`;
          if (url.endsWith(".data")) return `${WASM_BASE}.data`;
          return url;
        },
      });

      module.callMain([
        "-l",
        this.#modelConfig.espeak.voice,
        "--input",
        input,
        "--espeak_data",
        "/espeak-ng-data",
      ]);
    });
    const phonemeIds = this.normalizePhonemeIds(rawPhonemeIds);
    if (!phonemeIds.length) {
      throw new Error("No phoneme IDs generated for input text.");
    }

    const speakerId = 0;
    const sampleRate = this.#modelConfig.audio.sample_rate;
    const noiseScale = this.#modelConfig.inference.noise_scale;
    const lengthScale = this.#modelConfig.inference.length_scale;
    const noiseW = this.#modelConfig.inference.noise_w;

    const session = this.#ortSession!;
    const feeds = {
      input: new this.#ort!.Tensor("int64", phonemeIds, [1, phonemeIds.length]),
      input_lengths: new this.#ort!.Tensor("int64", [phonemeIds.length]),
      scales: new this.#ort!.Tensor("float32", [
        noiseScale,
        lengthScale,
        noiseW,
      ]),
    };
    if (Object.keys(this.#modelConfig.speaker_id_map).length) {
      Object.assign(feeds, {
        sid: new this.#ort!.Tensor("int64", [speakerId]),
      });
    }

    const {
      output: { data: pcm },
    } = await session.run(feeds);

    return new Blob([pcm2wav(pcm as Float32Array, 1, sampleRate)], {
      type: "audio/x-wav",
    });
  }

  private resolveMaxPhonemeId(modelConfig: any): number | undefined {
    if (!modelConfig) return undefined;
    if (typeof modelConfig.num_symbols === "number") {
      return Math.max(0, Math.floor(modelConfig.num_symbols) - 1);
    }
    const map = modelConfig.phoneme_id_map;
    if (map && typeof map === "object") {
      const values = Object.values(map)
        .map((value) => Number(value))
        .filter((value) => Number.isFinite(value));
      if (values.length) {
        return Math.max(...values);
      }
    }
    return undefined;
  }

  private normalizePhonemeIds(rawIds: Array<number | string>): BigInt64Array {
    const maxId = this.#maxPhonemeId;
    const normalized = rawIds
      .map((id) => Number(id))
      .filter((id) => Number.isFinite(id))
      .map((id) => {
        const clamped = maxId !== undefined
          ? Math.min(Math.max(Math.trunc(id), 0), maxId)
          : Math.trunc(id);
        return BigInt(clamped);
      });
    return BigInt64Array.from(normalized);
  }
}

/**
 * Run text to speech inference in new worker thread. Fetches the model
 * first, if it has not yet been saved to opfs yet.
 */
export async function predict(
  config: InferenceConfg,
  callback?: ProgressCallback
): Promise<Blob> {
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
async function getBlob(url: string, callback?: ProgressCallback) {
  let blob: Blob | undefined = await readBlob(url);

  if (!blob) {
    blob = await fetchBlob(url, callback);
    await writeBlob(url, blob);
  }

  return blob;
}
