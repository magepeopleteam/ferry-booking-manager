# MagePeople Ferry Booking System

Ferry booking and operations management for WordPress. The user documentation is
in `readme.txt`; this file covers the build.

## Building the compiled assets

Everything this plugin ships in `assets/` is built from source included in the
plugin. Nothing is minified-only, and nothing is fetched from a remote server at
build time beyond the public npm registry.

The same source is published at
<https://github.com/magepeopleteam/ferry-booking-manager>.

### What is compiled, and from where

| Shipped file                      | Built from      | Tooling                     |
| --------------------------------- | --------------- | --------------------------- |
| `assets/admin/app/` (admin app, including `_next/static/chunks/*.js`) | `apps/admin/src/` | Next.js (Turbopack), React, TypeScript |
| `assets/frontend/` (booking form) | `apps/booking/src/` | Vite, Preact, TypeScript |

`assets/admin/css/`, `assets/admin/js/` and all PHP in `src/` are written by
hand. They are neither compiled nor minified.

The chunk files under `assets/admin/app/_next/static/chunks/` contain our own
compiled TypeScript together with the library code that Next.js bundles in: its
Turbopack runtime and React. The source for our part is `apps/admin/src/`; the
source for the libraries is linked under "Third-party libraries" below.

### Requirements

- Node.js 20 or newer
- npm 10 or newer

### Build the admin dashboard

```sh
cd apps/admin
npm ci
npm run build
```

`npm run build` checks that every interface string has a translation, runs the
Next.js static export, and copies the result into `assets/admin/app/`.

### Build the customer booking form

```sh
cd apps/booking
npm ci
npm run build
```

`npm run build` checks the translation dictionary, runs the Vite build, and
writes the bundle and its manifest into `assets/frontend/`.

### Other useful commands

- `npm run typecheck` in either app type-checks without building.
- `npm run dev` rebuilds on change (`next dev` for the admin app, `vite build
  --watch` for the booking form).
- `./build-zip.sh [output-dir]` in the plugin root produces an installable zip.

`npm ci` installs the exact versions pinned in each `package-lock.json`, both of
which ship with the plugin, so a build can be reproduced exactly.

### Third-party libraries

Compiled into the bundles above. All are MIT licensed, which is GPL compatible.
Exact versions are pinned in the lock files.

- Next.js, including its Turbopack bundler runtime — <https://github.com/vercel/next.js>
- React and React DOM — <https://github.com/facebook/react>
- Preact — <https://github.com/preactjs/preact>

Build-only dependencies, not shipped in the bundles:

- TypeScript — <https://github.com/microsoft/TypeScript>
- Vite — <https://github.com/vitejs/vite>
