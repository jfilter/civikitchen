import { readdirSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// Every page of every language, in light and dark mode.
const site = process.env.SITE_DIR ?? '';
const pages = (function walk(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name);
    if (statSync(path).isDirectory()) return name === 'assets' ? [] : walk(path);
    return name === 'index.html' ? [`/${relative(site, dir)}/`.replace(/^\/\/$/, '/').replace(/\/+/g, '/')] : [];
  });
})(site);
const SCHEMES = { light: 'default', dark: 'slate' } as const;

test('the site has pages', () => expect(pages.length).toBeGreaterThan(0));

for (const [colorScheme, theme] of Object.entries(SCHEMES) as [keyof typeof SCHEMES, string][]) {
  for (const path of pages) {
    test(`${path} passes WCAG 2.1 AA in ${colorScheme} mode`, async ({ page }) => {
      await page.emulateMedia({ colorScheme });
      await page.goto(path);
      // Without this the run would pass on whichever scheme happened to load.
      await expect(page.locator('body')).toHaveAttribute('data-md-color-scheme', theme);
      const { violations } = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
      expect(violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
    });
  }
}
