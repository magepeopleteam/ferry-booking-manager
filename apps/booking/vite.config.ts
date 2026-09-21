import { defineConfig } from 'vite';

/**
 * Build configuration for the customer booking application.
 *
 * The output is a plain script and stylesheet that WordPress enqueues — no
 * module graph fetched at runtime, no import maps, no Node on the customer's
 * host. Filenames carry a content hash so they can be cached forever and a
 * deploy cannot serve a stale bundle.
 */
export default defineConfig( {
	root: __dirname,
	build: {
		outDir: '../../assets/frontend',
		emptyOutDir: true,
		target: 'es2019',
		cssCodeSplit: false,
		manifest: true,
		rollupOptions: {
			input: 'src/main.tsx',
			output: {
				entryFileNames: 'mpfbs-booking.[hash].js',
				chunkFileNames: 'mpfbs-booking.[hash].chunk.js',
				assetFileNames: 'mpfbs-booking.[hash][extname]',
			},
		},
	},
	esbuild: {
		jsx: 'automatic',
		jsxImportSource: 'preact',
	},
} );
