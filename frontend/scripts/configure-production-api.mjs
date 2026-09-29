import { mkdir, writeFile } from 'node:fs/promises';

const lifecycleEvent = process.env.npm_lifecycle_event ?? 'build';
const isAndroidBuild = lifecycleEvent === 'build:android';
const configuredValue = process.env.WHATTOCOOK_API_BASE_URL;
const fallbackValue = isAndroidBuild ? undefined : 'http://127.0.0.1:8001';
const value = configuredValue ?? fallbackValue;

if (!value) {
  throw new Error('WHATTOCOOK_API_BASE_URL is required for an Android or production release build. Set it to a valid HTTPS URL ending in /api.');
}

let apiUrl;
try {
  apiUrl = new URL(value);
} catch {
  throw new Error('WHATTOCOOK_API_BASE_URL must be a valid absolute URL.');
}

if (apiUrl.username || apiUrl.password) {
  throw new Error('WHATTOCOOK_API_BASE_URL must not contain credentials.');
}

if (isAndroidBuild && apiUrl.protocol !== 'https:') {
  throw new Error('Android release builds require WHATTOCOOK_API_BASE_URL to use HTTPS.');
}

if (!isAndroidBuild && apiUrl.protocol !== 'https:' && apiUrl.protocol !== 'http:') {
  throw new Error('WHATTOCOOK_API_BASE_URL must use either HTTP or HTTPS.');
}

apiUrl.pathname = `${apiUrl.pathname.replace(/\/$/, '')}/api`.replace(/\/api\/api$/, '/api');
apiUrl.search = '';
apiUrl.hash = '';

const output = `// Generated at build time. Do not commit this file.\nexport const environment = {\n  production: true,\n  apiBaseUrl: ${JSON.stringify(apiUrl.toString().replace(/\/$/, ''))},\n  androidApiBaseUrl: ${JSON.stringify(apiUrl.toString().replace(/\/$/, ''))}\n};\n`;

await mkdir('src/environments', { recursive: true });
await writeFile('src/environments/environment.production.generated.ts', output, 'utf8');
