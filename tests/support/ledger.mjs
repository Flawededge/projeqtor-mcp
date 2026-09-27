import { appendFile, mkdir, readFile, writeFile } from 'node:fs/promises';
import { randomUUID } from 'node:crypto';
import { dirname, resolve } from 'node:path';

const SAFE_KIND = /^[a-z][a-z0-9_.-]{0,63}$/;
const SAFE_CLASS = /^[A-Za-z][A-Za-z0-9_]{0,99}$/;

export function createRunId(now = new Date()) {
  const stamp = now.toISOString().replace(/[-:TZ.]/g, '').slice(0, 14);
  return `b4-${stamp}-${randomUUID().slice(0, 8)}`;
}

export class FixtureLedger {
  constructor(path, runId) {
    if (!/^b4-[a-zA-Z0-9-]{8,64}$/.test(runId)) throw new Error('Invalid Beta 4 test run ID');
    this.path = resolve(path);
    this.runId = runId;
  }

  async initialize() {
    await mkdir(dirname(this.path), { recursive: true, mode: 0o700 });
    try {
      await writeFile(this.path, '', { flag: 'wx', mode: 0o600 });
    } catch (error) {
      if (error.code !== 'EEXIST') throw error;
    }
    return this;
  }

  async record({ module, kind, objectClass, id, cleanupAction = 'delete' }) {
    if (!SAFE_KIND.test(module) || !SAFE_KIND.test(kind)) throw new Error('Invalid fixture ledger classification');
    if (!SAFE_CLASS.test(objectClass)) throw new Error('Invalid fixture object class');
    if (!Number.isSafeInteger(id) || id < 1) throw new Error('Fixture IDs must be positive integers');
    if (!['delete', 'close', 'restore'].includes(cleanupAction)) throw new Error('Invalid cleanup action');
    const entry = { runId: this.runId, module, kind, objectClass, id, cleanupAction, recordedAt: new Date().toISOString() };
    await appendFile(this.path, `${JSON.stringify(entry)}\n`, { encoding: 'utf8', mode: 0o600 });
    return entry;
  }

  async entries() {
    let text = '';
    try { text = await readFile(this.path, 'utf8'); } catch (error) { if (error.code !== 'ENOENT') throw error; }
    return text.split('\n').filter(Boolean).map(line => JSON.parse(line)).filter(entry => entry.runId === this.runId);
  }

  async cleanupPlan() {
    return (await this.entries()).reverse();
  }
}
