// Makes sure the helpers of the other tests really notice scripts: if the browser or the helpers were
// broken, the other tests would pass without testing anything.
const { test, expect } = require('@playwright/test');
const { watchDialogs, interact, activeContent } = require('./helpers');

const vulnerablePages = {
  'script element': '<script>alert("script")</script>',
  'onerror handler': '<img src="x" onerror="alert(\'onerror\')">',
  'onmouseover handler': '<p onmouseover="alert(\'mouseover\')">hover</p>',
  'onclick handler': '<button onclick="alert(\'click\')">x</button>',
  'onfocus handler': '<input onfocus="alert(\'focus\')">',
  'javascript link': '<a href="javascript:alert(\'link\')">x</a>',
};

for (const [name, html] of Object.entries(vulnerablePages)) {
  test(`detects a ${name}`, async ({ page }) => {
    const dialogs = watchDialogs(page);
    await page.route('**/canary', (route) => route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><body>${html}</body></html>` }));
    await page.goto('/canary');
    await interact(page);
    expect(dialogs.length).toBeGreaterThan(0);
  });
}

test('lists event handlers and scripts in a document', async ({ page }) => {
  await page.route('**/canary', (route) => route.fulfill({ contentType: 'text/html', body: '<!doctype html><p onclick="x()"></p><script>1</script>' }));
  await page.goto('/canary');
  expect(await activeContent(page)).toEqual(['onclick on <p>', 'inline <script>']);
});
