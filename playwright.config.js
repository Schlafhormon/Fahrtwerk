const { defineConfig } = require('@playwright/test');
module.exports = defineConfig({
    testDir: './tests/Browser',
    workers: 1,
    timeout: 180000,
    reporter: [['list']],
    use: {
        baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080',
        channel: process.env.E2E_CHROMIUM_PATH ? undefined : process.env.E2E_BROWSER || 'msedge',
        launchOptions: process.env.E2E_CHROMIUM_PATH ? { executablePath: process.env.E2E_CHROMIUM_PATH } : {},
        headless: true,
        reducedMotion: 'reduce',
        viewport: { width: 1440, height: 1000 },
        screenshot: 'only-on-failure',
        trace: 'off'
    }
});
