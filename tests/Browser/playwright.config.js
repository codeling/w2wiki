// SPDX-License-Identifier: MIT

// Browser tests: attempts to inject scripts, which must never run (see tests/README.md).
//
// The wiki is served by tests/bin/serve-app.php (four times: plain, with SVG uploads, with a password, and with git), unless
// W2_BASE_URL (and W2_SVG_URL, W2_LOGIN_URL, W2_GIT_URL) point to servers which are already running.
const { defineConfig, devices } = require('@playwright/test');

const port = 8090;
const svgPort = 8091;
const loginPort = 8092;
const gitPort = 8093;
const external = !!process.env.W2_BASE_URL;

module.exports = defineConfig({
  testDir: '.',
  testMatch: '*.spec.js',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 60000,
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
  use: {
    baseURL: process.env.W2_BASE_URL || `http://127.0.0.1:${port}`,
    trace: 'retain-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: external ? undefined : [
    {
      command: `php ../bin/serve-app.php --port=${port}`,
      url: `http://127.0.0.1:${port}/index.php`,
      timeout: 60000,
      reuseExistingServer: false,
    },
    {
      command: `php ../bin/serve-app.php --port=${svgPort} --svg`,
      url: `http://127.0.0.1:${svgPort}/index.php`,
      timeout: 60000,
      reuseExistingServer: false,
    },
    {
      command: `php ../bin/serve-app.php --port=${loginPort} --login`,
      url: `http://127.0.0.1:${loginPort}/index.php`,
      timeout: 60000,
      reuseExistingServer: false,
    },
    {
      command: `php ../bin/serve-app.php --port=${gitPort} --git`,
      url: `http://127.0.0.1:${gitPort}/index.php`,
      timeout: 60000,
      reuseExistingServer: false,
    },
  ],
});
