/**
 * Inline icon set.
 *
 * Icons ship as inline SVG so the dashboard has no icon-font request and no
 * third-party dependency. Every icon is decorative unless a label is supplied.
 */

import type { JSX } from 'react';

export type IconName =
	| 'dashboard'
	| 'ticket'
	| 'calendar'
	| 'route'
	| 'compass'
	| 'anchor'
	| 'ship'
	| 'tag'
	| 'users'
	| 'car'
	| 'scan'
	| 'list'
	| 'briefcase'
	| 'chart'
	| 'mail'
	| 'card'
	| 'settings'
	| 'menu'
	| 'search'
	| 'check'
	| 'alert'
	| 'lock'
	| 'clock'
	| 'close'
	| 'plus'
	| 'copy'
	| 'trash'
	| 'calendarPlus'
	| 'back'
	| 'chevron'
	| 'minus';

const PATHS: Record< IconName, JSX.Element > = {
	dashboard: (
		<>
			<rect x="3" y="3" width="7" height="8" rx="1.5" />
			<rect x="14" y="3" width="7" height="5" rx="1.5" />
			<rect x="14" y="11" width="7" height="10" rx="1.5" />
			<rect x="3" y="14" width="7" height="7" rx="1.5" />
		</>
	),
	ticket: (
		<>
			<path d="M4 7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2a2 2 0 0 0 0 6v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-6V7Z" />
			<path d="M12 8v1M12 12v1M12 16v1" />
		</>
	),
	calendar: (
		<>
			<rect x="3" y="5" width="18" height="16" rx="2" />
			<path d="M3 10h18M8 3v4M16 3v4" />
		</>
	),
	route: (
		<>
			<circle cx="6" cy="6" r="2.5" />
			<circle cx="18" cy="18" r="2.5" />
			<path d="M8.5 6H15a3 3 0 0 1 0 6H9a3 3 0 0 0 0 6h6.5" />
		</>
	),
	compass: (
		<>
			<circle cx="12" cy="12" r="9" />
			<path d="m15.5 8.5-2 5-5 2 2-5 5-2Z" />
		</>
	),
	anchor: (
		<>
			<circle cx="12" cy="5" r="2" />
			<path d="M12 7v14M5 13a7 7 0 0 0 14 0M8 11H5M19 11h-3" />
		</>
	),
	ship: (
		<>
			<path d="M3 17.5 4.5 12 12 9.5 19.5 12 21 17.5" />
			<path d="M12 9.5V5H8" />
			<path d="M2.5 18.5a3 3 0 0 0 4.75 1.2A3 3 0 0 0 12 20a3 3 0 0 0 4.75-.3 3 3 0 0 0 4.75-1.2" />
		</>
	),
	tag: (
		<>
			<path d="M3 11.5V4h7.5l9.5 9.5-7.5 7.5L3 11.5Z" />
			<circle cx="7.5" cy="7.5" r="1.2" />
		</>
	),
	users: (
		<>
			<circle cx="9" cy="8" r="3.2" />
			<path d="M3.5 19a5.5 5.5 0 0 1 11 0" />
			<path d="M16 5.5a3 3 0 0 1 0 5.6M17 14a5.5 5.5 0 0 1 3.5 5" />
		</>
	),
	car: (
		<>
			<path d="M4 16v2.5M20 16v2.5" />
			<path d="M3 16v-3l2-5h14l2 5v3H3Z" />
			<circle cx="7.5" cy="16" r="1.3" />
			<circle cx="16.5" cy="16" r="1.3" />
		</>
	),
	scan: (
		<>
			<path d="M4 8V6a2 2 0 0 1 2-2h2M16 4h2a2 2 0 0 1 2 2v2M20 16v2a2 2 0 0 1-2 2h-2M8 20H6a2 2 0 0 1-2-2v-2" />
			<path d="M4 12h16" />
		</>
	),
	list: (
		<>
			<path d="M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01" />
		</>
	),
	briefcase: (
		<>
			<rect x="3" y="7" width="18" height="13" rx="2" />
			<path d="M9 7V5.5A1.5 1.5 0 0 1 10.5 4h3A1.5 1.5 0 0 1 15 5.5V7M3 12h18" />
		</>
	),
	chart: (
		<>
			<path d="M4 20V4" />
			<path d="M4 20h16" />
			<path d="M8 16v-4M12.5 16V8M17 16v-6" />
		</>
	),
	mail: (
		<>
			<rect x="3" y="5" width="18" height="14" rx="2" />
			<path d="m3.5 7 8.5 6 8.5-6" />
		</>
	),
	card: (
		<>
			<rect x="3" y="5" width="18" height="14" rx="2" />
			<path d="M3 10h18M7 15h3" />
		</>
	),
	settings: (
		<>
			<circle cx="12" cy="12" r="3" />
			<path d="M12 3v2.2M12 18.8V21M4.2 7.5l1.9 1.1M17.9 15.4l1.9 1.1M4.2 16.5l1.9-1.1M17.9 8.6l1.9-1.1" />
		</>
	),
	trash: (
		<>
			<path d="M4 7h16M10 4h4M9 7v12M15 7v12" />
			<path d="M6 7l1 13h10l1-13" />
		</>
	),
	menu: <path d="M4 7h16M4 12h16M4 17h16" />,
	search: (
		<>
			<circle cx="11" cy="11" r="6" />
			<path d="m15.5 15.5 4 4" />
		</>
	),
	check: <path d="m5 12.5 4.5 4.5L19 7.5" />,
	alert: (
		<>
			<path d="M12 4.5 21 19H3l9-14.5Z" />
			<path d="M12 10v4M12 16.5h.01" />
		</>
	),
	lock: (
		<>
			<rect x="4.5" y="10" width="15" height="10" rx="2" />
			<path d="M8 10V7.5a4 4 0 0 1 8 0V10" />
		</>
	),
	clock: (
		<>
			<circle cx="12" cy="12" r="8.5" />
			<path d="M12 7.5V12l3 2" />
		</>
	),
	close: <path d="m6 6 12 12M18 6 6 18" />,
	plus: <path d="M12 5v14M5 12h14" />,
	copy: (
		<>
			<rect x="9" y="9" width="11" height="11" rx="2" />
			<path d="M5 15V6a2 2 0 0 1 2-2h9" />
		</>
	),
	calendarPlus: (
		<>
			<rect x="3" y="5" width="18" height="16" rx="2" />
			<path d="M3 10h18M8 3v4M16 3v4M12 13v5M9.5 15.5h5" />
		</>
	),
	back: <path d="M19 12H5m6-6-6 6 6 6" />,
	chevron: <path d="m6 9 6 6 6-6" />,
	minus: <path d="M5 12h14" />,
};

export interface IconProps {
	name: IconName;
	/** Accessible label. Omit to render the icon as decorative. */
	label?: string;
	size?: number;
	className?: string;
}

/**
 * Renders one icon from the built-in set.
 */
export function Icon( { name, label, size = 18, className }: IconProps ): JSX.Element {
	return (
		<svg
			className={ className ? `fbm-icon ${ className }` : 'fbm-icon' }
			width={ size }
			height={ size }
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="1.6"
			strokeLinecap="round"
			strokeLinejoin="round"
			role={ label ? 'img' : undefined }
			aria-label={ label }
			aria-hidden={ label ? undefined : true }
			focusable="false"
		>
			{ PATHS[ name ] }
		</svg>
	);
}
