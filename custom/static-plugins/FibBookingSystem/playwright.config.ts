import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './tests/E2E',
  use: {
    baseURL: process.env.BASE_URL ?? 'http://127.0.0.1',
  },
});
