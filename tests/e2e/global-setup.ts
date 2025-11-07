import { execDockerCommand } from "./helpers";

/**
 * Global setup that runs once before all tests
 * Flushes CiviCRM cache to prevent stale extension references
 * from interfering with test execution
 */
export default async function globalSetup() {
  console.log("Global setup: Flushing CiviCRM cache...");

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
}
