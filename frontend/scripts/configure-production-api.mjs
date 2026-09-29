import { mkdir, writeFile } from 'node:fs/promises';

const configuredValue = process.env.WHATTOCOOK_API_BASE_URL;

if (!configuredValue) {
  throw new Error('WHATTOCOOK_API_BASE_URL is required for production and Android release builds. Set it to a valid HTTPS URL ending in /api.');
}

let apiUrl;
try {
  apiUrl = new URL(configuredValue);
} catch {
  throw new Error('WHATTOCOOK_API_BASE_URL must be a valid absolute URL.');
}

if (apiUrl.username || apiUrl.password) {
  throw new Error('WHATTOCOOK_API_BASE_URL must not contain credentials.');
}

if (apiUrl.protocol !== 'https:') {
  throw new Error('Production and Android release builds require WHATTOCOOK_API_BASE_URL to use HTTPS.');
}

apiUrl.pathname = `${apiUrl.pathname.replace(/\/$/, '')}/api`.replace(/\/api\/api$/, '/api');
apiUrl.search = '';
apiUrl.hash = '';

const output = `// Generated at build time. Do not commit this file.\nexport const environment = {\n  production: true,\n  apiBaseUrl: ${JSON.stringify(apiUrl.toString().replace(/\/$/, ''))},\n  androidApiBaseUrl: ${JSON.stringify(apiUrl.toString().replace(/\/$/, ''))}\n};\n`;

await mkdir('src/environments', { recursive: true });
await writeFile('src/environments/environment.production.generated.ts', output, 'utf8');
