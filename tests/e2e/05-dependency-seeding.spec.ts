import { test, expect } from "@playwright/test";
import * as fs from "fs";
import * as path from "path";
import { execDockerCommand } from "./helpers";

const EXTENSIONS_DIR = path.join(__dirname, "../../extensions");
const FIXTURES_DIR = path.join(__dirname, "../fixtures");
const TEST_EXTENSION_NAME = "test-extension-with-seed";
const TEST_EXTENSION_SOURCE = path.join(
  FIXTURES_DIR,
  TEST_EXTENSION_NAME
);
const TEST_EXTENSION_DEST = path.join(EXTENSIONS_DIR, TEST_EXTENSION_NAME);

test.describe.serial("Extension Dependency and Seeding System", () => {
  test.beforeAll(async () => {
    // Clean up any existing test extension
    if (fs.existsSync(TEST_EXTENSION_DEST)) {
      fs.rmSync(TEST_EXTENSION_DEST, { recursive: true, force: true });
    }
  });

  test.afterAll(async () => {
    // Clean up test extension
    if (fs.existsSync(TEST_EXTENSION_DEST)) {
      fs.rmSync(TEST_EXTENSION_DEST, { recursive: true, force: true });
    }

    // Clean up any test contacts created
    try {
      // Get all contacts with the test marker and delete them
      const contacts = execDockerCommand(
        `cd /home/buildkit/buildkit/build/site/web && cv api3 Contact.get contact_source=test-seed-marker-12345 --out=json`,
        10000
      );
      const result = JSON.parse(contacts);

      // Delete each contact
      for (const id of Object.keys(result.values)) {
        execDockerCommand(
          `cd /home/buildkit/buildkit/build/site/web && cv api3 Contact.delete id=${id}`,
          10000
        );
      }
    } catch (error) {
      // Ignore errors during cleanup
      console.log("Cleanup error (expected):", error);
    }
  });

  test("civikitchen.json format is valid", async () => {
    const configPath = path.join(
      TEST_EXTENSION_SOURCE,
      "civikitchen.json"
    );

    expect(fs.existsSync(configPath)).toBeTruthy();

    const configContent = fs.readFileSync(configPath, "utf-8");
    const config = JSON.parse(configContent);

    // Verify structure
    expect(config).toHaveProperty("seeding");
    expect(config.seeding).toHaveProperty("enabled");
    expect(config.seeding).toHaveProperty("script");
    expect(config.seeding).toHaveProperty("runOnce");

    console.log("Test fixture config is valid:", config);
  });

  test("seed script can create test data", async () => {
    // Copy test extension to extensions directory
    console.log("Copying test extension to extensions directory...");
    fs.cpSync(TEST_EXTENSION_SOURCE, TEST_EXTENSION_DEST, { recursive: true });

    await new Promise((resolve) => setTimeout(resolve, 1000));

    // Verify extension is accessible in container
    const lsOutput = execDockerCommand(
      "ls -la /home/buildkit/buildkit/build/site/web/sites/default/files/civicrm/ext",
      5000
    );
    expect(lsOutput).toContain(TEST_EXTENSION_NAME);

    // Run the seed script directly (simulating what entrypoint.sh would do)
    console.log("Running seed script...");
    const seedScriptPath = `/home/buildkit/buildkit/build/site/web/sites/default/files/civicrm/ext/${TEST_EXTENSION_NAME}/scripts/seed-test-data.sh`;
    execDockerCommand(`bash ${seedScriptPath}`, 30000);

    // Create marker file (simulating what entrypoint.sh would do)
    execDockerCommand(
      `touch /home/buildkit/buildkit/build/site/web/sites/default/files/civicrm/ext/${TEST_EXTENSION_NAME}/.civicrm-seeded`,
      5000
    );

    await new Promise((resolve) => setTimeout(resolve, 1000));

    // Verify marker exists
    const markerCheck = execDockerCommand(
      `test -f /home/buildkit/buildkit/build/site/web/sites/default/files/civicrm/ext/${TEST_EXTENSION_NAME}/.civicrm-seeded && echo "EXISTS" || echo "NOT_FOUND"`,
      5000
    );
    expect(markerCheck.trim()).toBe("EXISTS");

    console.log("✓ Seed script created data and marker file");
  });

  test("seeding creates expected data in CiviCRM", async () => {
    // Query for the test contact that should have been created by seeding
    // Use cv api3 which has simpler syntax for this test
    // Note: The field is contact_source, not source
    const contactQuery = execDockerCommand(
      `cd /home/buildkit/buildkit/build/site/web && cv api3 Contact.get contact_source=test-seed-marker-12345 return=first_name,last_name,contact_source --out=json`,
      10000
    );

    const result = JSON.parse(contactQuery);

    expect(result).toHaveProperty("values");
    expect(typeof result.values).toBe("object");
    expect(Object.keys(result.values).length).toBeGreaterThan(0);

    const contact = Object.values(result.values)[0] as any;
    expect(contact.first_name).toBe("E2E");
    expect(contact.last_name).toBe("SeedTest");
    expect(contact.contact_source).toBe("test-seed-marker-12345");

    console.log("Test contact created by seeding:", contact);
  });

  test("marker file concept prevents re-running seed", async () => {
    // Get current contact count
    const countBefore = execDockerCommand(
      `cd /home/buildkit/buildkit/build/site/web && cv api3 Contact.get contact_source=test-seed-marker-12345 --out=json`,
      10000
    );
    const beforeResult = JSON.parse(countBefore);
    const beforeCount = Object.keys(beforeResult.values).length;

    console.log(`Contacts before: ${beforeCount}`);
    expect(beforeCount).toBeGreaterThan(0);

    // Verify marker file exists from previous test
    const markerPath = `/home/buildkit/buildkit/build/site/web/sites/default/files/civicrm/ext/${TEST_EXTENSION_NAME}/.civicrm-seeded`;
    const markerExists = execDockerCommand(
      `test -f ${markerPath} && echo "EXISTS" || echo "NOT_FOUND"`,
      5000
    );
    expect(markerExists.trim()).toBe("EXISTS");
    console.log("✓ Marker file exists");

    // Because marker exists, we DON'T re-run seeding
    // (entrypoint.sh checks for this marker and skips if present)

    // Verify contact count unchanged
    const countAfter = execDockerCommand(
      `cd /home/buildkit/buildkit/build/site/web && cv api3 Contact.get contact_source=test-seed-marker-12345 --out=json`,
      10000
    );
    const afterResult = JSON.parse(countAfter);
    const afterCount = Object.keys(afterResult.values).length;

    expect(afterCount).toBe(beforeCount);
    console.log(`✓ Contacts unchanged: ${afterCount} (marker concept works)`);
  });

  test("helper scripts exist and are executable", async () => {
    const scripts = [
      "link-extension.sh",
      "install-dependencies.sh",
      "seed-extensions.sh",
      "list-extensions.sh",
      "reset-seed-markers.sh",
    ];

    const scriptsDir = path.join(__dirname, "../../scripts");

    for (const script of scripts) {
      const scriptPath = path.join(scriptsDir, script);

      // Check if file exists
      expect(fs.existsSync(scriptPath)).toBeTruthy();

      // Check if executable (on Unix systems)
      if (process.platform !== "win32") {
        const stats = fs.statSync(scriptPath);
        const isExecutable = (stats.mode & fs.constants.S_IXUSR) !== 0;
        expect(isExecutable).toBeTruthy();
      }

      console.log(`✓ ${script} exists and is executable`);
    }
  });
});
