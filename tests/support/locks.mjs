import { mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { setTimeout as delay } from 'node:timers/promises';

const SAFE_LOCK = /^[a-z][a-z0-9_.-]{0,63}$/;

export async function withResourceLock(name, callback, options = {}) {
  if (!SAFE_LOCK.test(name)) throw new Error('Invalid resource lock name');
  const root = resolve(options.root ?? process.env.PROJEQTOR_TEST_LOCK_DIR ?? new URL('../.runtime/locks', import.meta.url).pathname);
  const lock = resolve(root, `${name}.lock`);
  const timeoutMs = options.timeoutMs ?? 60_000;
  const staleMs = options.staleMs ?? 15 * 60_000;
  await mkdir(root, { recursive: true, mode: 0o700 });
  const deadline = Date.now() + timeoutMs;
  for (;;) {
    try {
      await mkdir(lock, { mode: 0o700 });
      await writeFile(resolve(lock, 'owner.json'), `${JSON.stringify({ pid: process.pid, acquiredAt: new Date().toISOString() })}\n`, { mode: 0o600 });
      break;
    } catch (error) {
      if (error.code !== 'EEXIST') throw error;
      try {
        const owner = JSON.parse(await readFile(resolve(lock, 'owner.json'), 'utf8'));
        if (Date.now() - Date.parse(owner.acquiredAt) > staleMs) {
          await rm(lock, { recursive: true, force: true });
          continue;
        }
      } catch {}
      if (Date.now() >= deadline) throw new Error(`Timed out waiting for resource lock ${name}`);
      await delay(100 + Math.floor(Math.random() * 100));
    }
  }
  try {
    return await callback();
  } finally {
    await rm(lock, { recursive: true, force: true });
  }
}
