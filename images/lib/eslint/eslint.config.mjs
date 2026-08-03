// The CiviKitchen ESLint baseline, shipped in the image rather than copied into
// eleven repos — the same deal as the bundled phpcs standard cklint runs.
//
// It is deliberately NOT a style guide. phpcs has one for PHP because PHP has a
// house style worth enforcing; JavaScript in a CiviCRM extension is a handful of
// files whose formatting nobody argues about, and a formatting gate on them
// costs more in churn than it returns. What this config gates is the class of
// finding no reviewer reliably catches by eye:
//
//   * @eslint/js recommended — the genuine mistakes: a variable that is never
//     read, a `case` that falls through, a duplicate object key, an unreachable
//     branch, `==` against a regex. Every rule in it flags code that is wrong,
//     not code that is unfashionable.
//
//   * eslint-plugin-no-unsanitized (Mozilla's) — `innerHTML = userInput` and its
//     family (outerHTML, insertAdjacentHTML, document.write, jQuery .html()).
//     This is the one that earns the gate on its own: CiviCRM extensions render
//     contact data into the DOM constantly, and an XSS in an extension is an XSS
//     on every site that installs it. It only accepts values it can prove are
//     literal, which is exactly the right bar for a template-shaped assignment.
//
//   * typescript-eslint recommended-type-checked, but ONLY when the repo has a
//     tsconfig.json. Type-aware linting needs a real TypeScript program; without
//     a tsconfig there is nothing to build one from, and the rules would either
//     error out or silently do nothing. The two that matter most are in that
//     preset: no-floating-promises (an async call whose rejection nobody
//     handles — in a browser that is a silently dead feature) and
//     no-misused-promises (a promise passed where a boolean or a void callback
//     was expected, e.g. an async function handed to addEventListener).
//
// A repo that ships its own eslint.config.* wins outright — ckeslint hands over
// to it and never mentions this file. Per-extension policy beats the fleet
// default, same as a project phpcs.xml.dist beats the bundled standard.
import { existsSync } from 'node:fs';
import { join } from 'node:path';

import js from '@eslint/js';
import globals from 'globals';
import noUnsanitized from 'eslint-plugin-no-unsanitized';
import tseslint from 'typescript-eslint';

// The project under test, not this file's directory: ckeslint invokes eslint
// from the extension root with `-c <this file>`, and ESLint resolves relative
// config patterns against the working directory in that setup.
const root = process.cwd();
const hasTsconfig = existsSync(join(root, 'tsconfig.json'));

// Globals a CiviCRM extension's frontend code legitimately reaches for and
// never declares. Without these every one of them reads as no-undef:
//   CRM      — core's JS namespace (CRM.api4, CRM.url, CRM.alert, ...)
//   cj       — core's noConflict jQuery alias
//   ts       — the client-side translation function
//   _        — lodash/underscore, loaded by core
//   angular  — present on every Afform/AngularJS page
// Declared readonly on purpose: assigning to any of them is a bug worth a
// finding, and `writable` would silence exactly that.
const civiGlobals = {
  CRM: 'readonly',
  cj: 'readonly',
  ts: 'readonly',
  _: 'readonly',
  angular: 'readonly',
};

export default [
  {
    // Global ignores (an `ignores`-only object). Three groups, all of them
    // code this repo did not write and will not fix:
    //   * dependency and build trees — node_modules/, vendor/, dist/, build/,
    //     and .civikitchen-siblings/ (the shared CI workflow checks a sibling
    //     extension out INSIDE the workspace; its own CI lints it);
    //   * `*.min.js` and `*.bundle.js` — minified output has no unused
    //     variable a human can act on, and one line of it can produce
    //     thousands of findings;
    //   * the conventional vendored-asset directories. A CiviCRM extension
    //     that vendors a datepicker commits it verbatim, on purpose, and
    //     linting it grades the upstream author.
    ignores: [
      '**/node_modules/**',
      '**/vendor/**',
      '**/dist/**',
      '**/build/**',
      '**/.civikitchen-siblings/**',
      '**/bower_components/**',
      '**/packages/**',
      '**/*.min.js',
      '**/*.bundle.js',
    ],
  },

  {
    files: ['**/*.js', '**/*.mjs', '**/*.cjs', '**/*.ts', '**/*.tsx'],
    languageOptions: {
      ecmaVersion: 'latest',
      globals: {
        ...globals.browser,
        ...civiGlobals,
      },
    },
  },

  // CommonJS-shaped files still exist in extensions (karma.conf.js, a build
  // script). Without `sourceType: commonjs` for them, `require`/`module` are
  // undefined identifiers and every such file reports noise instead of bugs.
  {
    files: ['**/*.cjs', '**/karma.conf.js', '**/*.config.js'],
    languageOptions: {
      sourceType: 'commonjs',
      globals: { ...globals.node },
    },
  },

  js.configs.recommended,
  noUnsanitized.configs.recommended,

  // Type-aware TypeScript, gated on the tsconfig. `projectService` is
  // typescript-eslint's own project resolution: it finds the nearest tsconfig
  // for each file, which is what a repo with a tests/ tsconfig alongside the
  // root one needs. tsconfigRootDir anchors it at the extension, not at
  // whatever directory this config file happens to live in.
  ...(hasTsconfig
    ? [
      ...tseslint.configs.recommendedTypeChecked,
      {
        files: ['**/*.ts', '**/*.tsx'],
        languageOptions: {
          parserOptions: {
            projectService: true,
            tsconfigRootDir: root,
          },
        },
      },
      // The type-checked preset applies to .js too unless it is turned off
      // there, and a plain .js file outside the tsconfig's include list makes
      // the parser throw rather than report. Disabling type-aware rules for
      // JS keeps the JS half on the syntactic ruleset it was on before the
      // repo gained a tsconfig.
      {
        files: ['**/*.js', '**/*.mjs', '**/*.cjs'],
        ...tseslint.configs.disableTypeChecked,
      },
    ]
    : []),
];
