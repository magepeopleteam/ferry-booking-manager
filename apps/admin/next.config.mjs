/**
 * Next.js configuration for the Ferry Booking Manager dashboard.
 *
 * The dashboard is exported to static HTML/JS at build time and served by
 * WordPress from the plugin's assets directory. No Node process ever runs on
 * the customer's host.
 *
 * `assetPrefix` is intentionally left empty here: the plugin injects the real
 * prefix into `__NEXT_DATA__` at render time, so the same build works on any
 * site regardless of where wp-content lives.
 *
 * @type {import('next').NextConfig}
 */
const nextConfig = {
	output: 'export',
	distDir: '.next',
	reactStrictMode: true,
	trailingSlash: false,
	poweredByHeader: false,
	generateBuildId: async () => 'fbm',
	images: {
		unoptimized: true,
	},
	turbopack: {},
};

export default nextConfig;
