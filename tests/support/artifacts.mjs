import { mkdir, rename, writeFile } from 'node:fs/promises';
import { dirname, resolve, sep } from 'node:path';
import { randomUUID } from 'node:crypto';
import { sanitize } from './redact.mjs';

export function artifactRoot() {
  return resolve(process.env.PROJEQTOR_TEST_ARTIFACT_DIR ?? new URL('../.runtime/artifacts', import.meta.url).pathname);
}

export async function writeSanitizedArtifact(relativePath, value) {
  const root = artifactRoot();
  const target = resolve(root, relativePath);
  if (target !== root && !target.startsWith(`${root}${sep}`)) throw new Error('Artifact path escapes the configured root');
  await mkdir(dirname(target), { recursive: true, mode: 0o700 });
  const temporary = `${target}.${randomUUID()}.tmp`;
  const document = `${JSON.stringify(sanitize(value), null, 2)}\n`;
  await writeFile(temporary, document, { mode: 0o600 });
  await rename(temporary, target);
  return target;
}
