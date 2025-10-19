import tsParser from "@typescript-eslint/parser";
import tsPlugin from "@typescript-eslint/eslint-plugin";

export default [
  {
    files: ["**/*.ts", "**/*.js"],
    ignores: [
      "node_modules/**",
      "playwright-report/**",
      "test-results/**",
      "dist/**",
      "build/**",
      "docs/**",
      "scripts/**",
      "**/*.md",
      "eslint.config.mjs",
    ],
    languageOptions: {
      parser: tsParser,
      ecmaVersion: 2020,
      sourceType: "module",
      globals: {
        console: "readonly",
        process: "readonly",
        Buffer: "readonly",
        __dirname: "readonly",
        __filename: "readonly",
        module: "readonly",
        require: "readonly",
        exports: "readonly",
      },
    },
    plugins: {
      "@typescript-eslint": tsPlugin,
    },
    rules: {
      ...tsPlugin.configs.recommended.rules,
      "no-restricted-syntax": [
        "error",
        {
          selector: "Literal[value=/docker-compose/]",
          message: "Use 'docker compose' (with space) instead of 'docker' + '-compose' (with hyphen). GitHub Actions uses the Docker CLI plugin which requires the space.",
        },
      ],
      "@typescript-eslint/no-explicit-any": "off",
    },
  },
];
