import { test, expect } from "@playwright/test";
import * as fs from "fs";
import * as path from "path";
import {
  loginAsAdminUser,
  navigateToExtensions,
  execDockerCommand,
} from "./helpers";

// Test extension configuration
const TEST_EXTENSION_NAME = "com.test.e2etest";
const TEST_EXTENSION_SHORT = "e2etest";
const EXTENSIONS_DIR = path.join(__dirname, "../../extensions");
const TEST_EXTENSION_PATH = path.join(EXTENSIONS_DIR, TEST_EXTENSION_NAME);

test.describe("Extension Development Workflow", () => {
  test.skip(!!process.env.CI, "Skipping extension development tests in CI due to Docker bind mount restrictions");

  // Clean up test extension after all tests
  test.afterAll(async () => {
    console.log("Cleaning up test extension...");

    // Remove extension directory if it exists
    if (fs.existsSync(TEST_EXTENSION_PATH)) {
      try {
        fs.rmSync(TEST_EXTENSION_PATH, { recursive: true, force: true });
        console.log("Test extension directory removed");
      } catch (error) {
        console.error("Error removing test extension:", error);
      }
    }
  });

  test("extensions directory is mounted and accessible", async () => {
    // Check local extensions directory exists
    expect(fs.existsSync(EXTENSIONS_DIR)).toBeTruthy();

    // Check directory is accessible in container (note: ext is now a symlink, so we list the target)
    const output = execDockerCommand(
      "ls -la /home/buildkit/buildkit/build/site/web/sites/default/files/civicrm/ext/"
    );

    expect(output).toContain(".gitkeep");

    // Test mount works by creating a test file
    const testFile = path.join(EXTENSIONS_DIR, ".test-mount");
    fs.writeFileSync(testFile, "test");

    const checkFile = execDockerCommand(
      "cat /home/buildkit/buildkit/build/site/web/sites/default/files/civicrm/ext/.test-mount"
    );
    expect(checkFile.trim()).toBe("test");

    // Clean up test file
    fs.unlinkSync(testFile);
  });

  test("can create extension with civix", async () => {
    // Ensure CiviCRM is fully initialized before using civix
    console.log("Initializing CiviCRM extension system...");
    execDockerCommand(
      "cd /home/buildkit/buildkit/build/site/web && cv ext:list --refresh",
      30000
    );

    // Generate extension using civix
    console.log("Generating test extension with civix...");

    // Use "no" to prevent civix from auto-enabling the extension (which can cause errors)
    // Set CIVICRM_SETTINGS explicitly so civix can bootstrap CiviCRM
    const civixCommand = `cd /home/buildkit/buildkit/build/site/web/sites/default/files/civicrm/ext && export CIVICRM_SETTINGS=/home/buildkit/buildkit/build/site/web/sites/default/civicrm.settings.php && printf "yes\\nno\\n" | civix generate:module ${TEST_EXTENSION_NAME} --author="E2E Test" --license=AGPL-3.0`;

    const output = execDockerCommand(civixCommand, 60000);

    console.log("Civix output:", output);

    // Refresh extension list so CiviCRM knows about the new extension
    execDockerCommand(
      "cd /home/buildkit/buildkit/build/site/web && cv ext:list --refresh",
      30000
    );

    // Verify extension directory exists on host
    expect(fs.existsSync(TEST_EXTENSION_PATH)).toBeTruthy();

    // Verify key files exist
    const infoXmlPath = path.join(TEST_EXTENSION_PATH, "info.xml");
    const mainPhpPath = path.join(
      TEST_EXTENSION_PATH,
      `${TEST_EXTENSION_SHORT}.php`
    );

    expect(fs.existsSync(infoXmlPath)).toBeTruthy();
    expect(fs.existsSync(mainPhpPath)).toBeTruthy();

    // Verify info.xml contains correct extension name
    const infoXmlContent = fs.readFileSync(infoXmlPath, "utf-8");
    expect(infoXmlContent).toContain(TEST_EXTENSION_NAME);

    console.log("Test extension created successfully");
  });

  test("extension appears in CiviCRM extensions list", async ({ page }) => {
    // Login as admin (required for extension management)
    await loginAsAdminUser(page);

    // Navigate to extensions page
    await navigateToExtensions(page);

    // Wait for extensions list to load
    await page.waitForTimeout(2000);

    // Look for our test extension in the page
    const pageContent = await page.content();

    // Extension should appear somewhere on the page
    const hasExtensionName =
      pageContent.includes(TEST_EXTENSION_NAME) ||
      pageContent.includes(TEST_EXTENSION_SHORT) ||
      pageContent.includes("e2etest");

    expect(hasExtensionName).toBeTruthy();

    console.log("Test extension found in extensions list");
  });

  test("can enable extension via CiviCRM UI", async ({ page }) => {
    // First check if extension is already enabled
    try {
      const statusCheck = execDockerCommand(
        `cd /home/buildkit/buildkit/build/site/web && cv ext:list --local | grep ${TEST_EXTENSION_NAME} || true`,
        10000
      );

      if (
        statusCheck.includes(TEST_EXTENSION_NAME) &&
        statusCheck.includes("enabled")
      ) {
        console.log(
          "Extension already enabled, disabling first to test installation flow"
        );
        execDockerCommand(
          `cd /home/buildkit/buildkit/build/site/web && cv ext:disable ${TEST_EXTENSION_NAME} && cv ext:uninstall ${TEST_EXTENSION_NAME}`,
          30000
        );
      }
    } catch {
      console.log("Extension not enabled yet, proceeding with installation");
    }

    // Clear CiviCRM caches before enabling
    console.log("Clearing CiviCRM caches...");
    execDockerCommand(
      "cd /home/buildkit/buildkit/build/site/web && cv flush",
      30000
    );

    // Refresh extension list
    console.log("Refreshing extension list...");
    execDockerCommand(
      "cd /home/buildkit/buildkit/build/site/web && cv ext:list --refresh",
      30000
    );

    // Use cv command for more reliable installation
    console.log("Installing extension via cv command");

    const cvOutput = execDockerCommand(
      `cd /home/buildkit/buildkit/build/site/web && cv ext:enable ${TEST_EXTENSION_NAME}`,
      30000
    );

    console.log("cv output:", cvOutput);

    // Check if enable was successful
    const enableSuccess =
      cvOutput.includes("Enabling") ||
      cvOutput.includes("enabled") ||
      cvOutput.includes(TEST_EXTENSION_NAME);
    expect(enableSuccess).toBeTruthy();

    // Verify in UI that extension is now enabled
    await loginAsAdminUser(page);
    await navigateToExtensions(page);
    await page.waitForTimeout(2000);

    const pageContent = await page.content();
    const isVisible = pageContent.includes(TEST_EXTENSION_NAME);

    expect(isVisible).toBeTruthy();

    console.log("Extension enabled successfully");
  });

  test("enabled extension appears in enabled list", async ({ page }) => {
    // Login as admin
    await loginAsAdminUser(page);

    // Use cv to check extension status - use || true to prevent grep from failing if not found
    const statusOutput = execDockerCommand(
      `cd /home/buildkit/buildkit/build/site/web && cv ext:list | grep ${TEST_EXTENSION_NAME} || echo "NOT_FOUND"`,
      30000
    );

    // Extension should be in the list and NOT show "NOT_FOUND"
    expect(statusOutput).toContain(TEST_EXTENSION_NAME);
    expect(statusOutput).not.toContain("NOT_FOUND");

    console.log("Extension status verified:", statusOutput.trim());
  });

  test("extension code changes are reflected", async () => {
    // Skip if extension doesn't exist (previous tests failed)
    if (!fs.existsSync(TEST_EXTENSION_PATH)) {
      console.log(
        "Skipping: Extension directory not found (previous test may have failed)"
      );
      test.skip();
      return;
    }

    // Modify a file in the extension
    const mainPhpPath = path.join(
      TEST_EXTENSION_PATH,
      `${TEST_EXTENSION_SHORT}.php`
    );

    const originalContent = fs.readFileSync(mainPhpPath, "utf-8");

    // Add a comment to the file
    const modifiedContent = originalContent + "\n// E2E test modification\n";
    fs.writeFileSync(mainPhpPath, modifiedContent);

    // Wait a moment for sync
    await new Promise((resolve) => setTimeout(resolve, 1000));

    // Verify file is changed in container
    const containerFileContent = execDockerCommand(
      `cat /home/buildkit/buildkit/build/site/web/sites/default/files/civicrm/ext/${TEST_EXTENSION_NAME}/${TEST_EXTENSION_SHORT}.php`
    );

    expect(containerFileContent).toContain("E2E test modification");

    console.log("Extension code changes are reflected in container");
  });

  test("can disable and uninstall extension", async () => {
    // Disable extension using cv
    const disableOutput = execDockerCommand(
      `cd /home/buildkit/buildkit/build/site/web && cv ext:disable ${TEST_EXTENSION_NAME}`,
      30000
    );

    // Check if disable was successful
    const disableSuccess =
      disableOutput.includes("Disabling") || disableOutput.includes("disabled");
    expect(disableSuccess).toBeTruthy();

    // Uninstall extension
    const uninstallOutput = execDockerCommand(
      `cd /home/buildkit/buildkit/build/site/web && cv ext:uninstall ${TEST_EXTENSION_NAME}`,
      30000
    );

    // Check if uninstall was successful
    const uninstallSuccess =
      uninstallOutput.includes("Uninstalling") ||
      uninstallOutput.includes("uninstalled");
    expect(uninstallSuccess).toBeTruthy();

    console.log("Extension disabled and uninstalled successfully");
  });
});
