import { execDockerCommand } from "./helpers";

/**
 * Sleep helper for async wait loops
 */
function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * Check if critical extensions are installed and enabled
 * This helps ensure the container is fully ready before running tests
 */
async function waitForExtensionsReady(maxAttempts = 30): Promise<boolean> {
  console.log("Checking if extensions are ready...");

  for (let attempt = 1; attempt <= maxAttempts; attempt++) {
    try {
      // Try to get extension list - if this works, extensions are ready
      const output = execDockerCommand(
        "cd /home/buildkit/buildkit/build/site/web && cv ext:list --local 2>/dev/null",
        10000
      );

      // Check if we got valid output with extensions
      if (output && (output.includes("installed") || output.includes("enabled"))) {
        console.log("✓ Extensions are ready!");
        return true;
      }
    } catch {
      // Extension system not ready yet, continue waiting
    }

    if (attempt < maxAttempts) {
      console.log(`  Waiting for extensions... (attempt ${attempt}/${maxAttempts})`);
      await sleep(2000); // Wait 2 seconds between attempts
    }
  }

  console.warn("⚠ Warning: Extensions may not be fully ready, but proceeding with tests");
  return false;
}

/**
 * Global setup that runs once before all tests
 * 1. Waits for extensions to be ready
 * 2. Flushes CiviCRM cache to prevent stale extension references
 *    from interfering with test execution
 */
export default async function globalSetup() {
  console.log("Global setup: Preparing test environment...");
  console.log("");

  // Wait for extensions to be ready (important for timing-sensitive tests)
  await waitForExtensionsReady();
  console.log("");

  // Flush cache to ensure clean state
  console.log("Flushing CiviCRM cache...");
  try {
    execDockerCommand(
      "cd /home/buildkit/buildkit/build/site/web && cv flush",
      30000
    );
    console.log("✓ CiviCRM cache flushed successfully");
  } catch (error) {
    console.error("Warning: Failed to flush CiviCRM cache:", error);
    // Don't fail the entire test suite if cache flush fails
    // Tests may still pass if cache is clean
  }

  console.log("");
  console.log("✓ Global setup complete - ready to run tests");
  console.log("");
}
