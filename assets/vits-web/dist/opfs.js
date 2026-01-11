export async function writeBlob(url, blob) {
  // only store models
  if (!url.match('https://huggingface.co')) return;
  try {
    const root = await navigator.storage.getDirectory();
    const dir = await root.getDirectoryHandle('piper', {
      create: true,
    });

    const path = url.split('/').at(-1);
    const file = await dir.getFileHandle(path, { create: true });
    const writable = await file.createWritable();
    await writable.write(blob);
    await writable.close();
  } catch (e) {
    console.error(e);
  }
}

export async function removeBlob(url) {
  try {
    const root = await navigator.storage.getDirectory();
    const dir = await root.getDirectoryHandle('piper');
    const path = url.split('/').at(-1);
    const file = await dir.getFileHandle(path);
    await file.remove();
  } catch (e) {
    console.error(e);
  }
}

export async function readBlob(url) {
  if (!url.match('https://huggingface.co')) return;
  try {
    const root = await navigator.storage.getDirectory();
    const dir = await root.getDirectoryHandle('piper', {
      create: true,
    });

    const path = url.split('/').at(-1);
    const file = await dir.getFileHandle(path);

    return await file.getFile();
  } catch (e) {
    return undefined;
  }
}
