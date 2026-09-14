/**
 * Passenger and vehicle configuration screens.
 *
 * Each of these destinations owns two related jobs: the catalogue of types an
 * operator sells, and the details a booking collects about each one. They are
 * tabs rather than separate menu entries because an operator changing what a
 * child costs and what a child's booking must record is doing one task.
 */

import { useCallback, type JSX } from 'react';

import { FieldConfigPanel } from '../components/FieldConfigPanel';
import { Tabs } from '../components/Tabs';
import { fbmNavigate } from '../lib/router';
import { ResourceScreen } from './ResourceScreen';
import { FBM_PASSENGER_TYPE_RESOURCE, FBM_VEHICLE_TYPE_RESOURCE } from '../config/resources';
import type { ResourceConfig } from '../config/resources';

export interface TypeConfigScreenProps {
	/** Route base, e.g. "passengers". */
	base: string;
	/** Second path segment selecting the tab, if any. */
	tab: string;
	config: ResourceConfig;
	group: 'passenger' | 'vehicle';
	fieldsTitle: string;
	fieldsDescription: string;
	typesTabLabel: string;
}

/**
 * Renders a catalogue tab and a capture-field tab for one group.
 */
export function TypeConfigScreen( {
	base,
	tab,
	config,
	group,
	fieldsTitle,
	fieldsDescription,
	typesTabLabel,
}: TypeConfigScreenProps ): JSX.Element {
	const active = tab === 'fields' ? 'fields' : 'types';

	const select = useCallback(
		( id: string ) => {
			fbmNavigate( id === 'types' ? `/${ base }` : `/${ base }/${ id }` );
		},
		[ base ]
	);

	return (
		<>
			<Tabs
				label={ config.label }
				active={ active }
				onSelect={ select }
				tabs={ [
					{ id: 'types', label: typesTabLabel },
					{ id: 'fields', label: 'Booking form' },
				] }
			/>

			<div id={ `fbm-tabpanel-${ active }` } role="tabpanel" aria-labelledby={ `fbm-tab-${ active }` }>
				{ active === 'types' ? (
					<ResourceScreen key={ `${ base }-types` } config={ config } />
				) : (
					<FieldConfigPanel group={ group } title={ fieldsTitle } description={ fieldsDescription } />
				) }
			</div>
		</>
	);
}

/**
 * Passenger types and the details collected about each traveller.
 */
export function PassengersScreen( { tab }: { tab: string } ): JSX.Element {
	return (
		<TypeConfigScreen
			base="passengers"
			tab={ tab }
			config={ FBM_PASSENGER_TYPE_RESOURCE }
			group="passenger"
			typesTabLabel="Passenger types"
			fieldsTitle="Passenger details"
			fieldsDescription="Choose what a booking records about each person travelling. Required fields must be filled in before a booking can be paid for."
		/>
	);
}

/**
 * Vehicle types and the details collected about each vehicle.
 */
export function VehiclesScreen( { tab }: { tab: string } ): JSX.Element {
	return (
		<TypeConfigScreen
			base="vehicles"
			tab={ tab }
			config={ FBM_VEHICLE_TYPE_RESOURCE }
			group="vehicle"
			typesTabLabel="Vehicle types"
			fieldsTitle="Vehicle details"
			fieldsDescription="Choose what a booking records about each vehicle carried. Individual vehicle types can demand a registration or driver on top of this."
		/>
	);
}
