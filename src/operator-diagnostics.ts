export interface OperatorDiagnosticsRow {
	label: string;
	value: string;
}

export interface OperatorDiagnosticsPanel {
	title: string;
	rows: OperatorDiagnosticsRow[];
}

const DIAGNOSTICS_KEYS = [
	'source_diagnostics',
	'sourceDiagnostics',
	'retrieval_diagnostics',
	'retrievalDiagnostics',
	'operator_diagnostics',
	'operatorDiagnostics',
	'diagnostics',
];

function asRecord( value: unknown ): Record< string, unknown > | null {
	return value && typeof value === 'object' && ! Array.isArray( value )
		? value as Record< string, unknown >
		: null;
}

function labelFromKey( key: string ): string {
	return key
		.replace( /([a-z0-9])([A-Z])/g, '$1 $2' )
		.replace( /[-_]+/g, ' ' )
		.replace( /\s+/g, ' ' )
		.trim()
		.replace( /^./, ( match ) => match.toUpperCase() );
}

function stringifyValue( value: unknown ): string {
	if ( typeof value === 'string' ) {
		return value.trim();
	}

	if ( typeof value === 'number' || typeof value === 'boolean' ) {
		return String( value );
	}

	if ( Array.isArray( value ) ) {
		return value
			.map( stringifyValue )
			.filter( Boolean )
			.join( ', ' );
	}

	if ( value && typeof value === 'object' ) {
		try {
			return JSON.stringify( value );
		} catch {
			return '';
		}
	}

	return '';
}

function rowFromValue( value: unknown ): OperatorDiagnosticsRow | null {
	const record = asRecord( value );
	if ( ! record ) {
		return null;
	}

	const label = stringifyValue( record.label ?? record.name ?? record.key );
	const rowValue = stringifyValue( record.value ?? record.message ?? record.detail );
	if ( ! label || ! rowValue ) {
		return null;
	}

	return { label, value: rowValue };
}

function rowsFromRecord( source: Record< string, unknown > ): OperatorDiagnosticsRow[] {
	const explicitRows = source.rows ?? source.items;
	if ( Array.isArray( explicitRows ) ) {
		return explicitRows.map( rowFromValue ).filter( ( row ): row is OperatorDiagnosticsRow => !! row );
	}

	return Object.entries( source )
		.filter( ( [ key ] ) => ! [ 'title', 'label', 'rows', 'items' ].includes( key ) )
		.map( ( [ key, value ] ) => ( {
			label: labelFromKey( key ),
			value: stringifyValue( value ),
		} ) )
		.filter( ( row ) => row.value !== '' );
}

function findDiagnosticsSource( metadata: Record< string, unknown > ): Record< string, unknown > | null {
	for ( const key of DIAGNOSTICS_KEYS ) {
		const source = asRecord( metadata[ key ] );
		if ( source ) {
			return source;
		}
	}

	return null;
}

export function getOperatorDiagnosticsRows( metadata: Record< string, unknown > ): OperatorDiagnosticsRow[] {
	const source = findDiagnosticsSource( metadata );
	return source ? rowsFromRecord( source ) : [];
}

export function getOperatorDiagnosticsPanel( metadata: Record< string, unknown > ): OperatorDiagnosticsPanel | null {
	const source = findDiagnosticsSource( metadata );
	if ( ! source ) {
		return null;
	}

	const rows = rowsFromRecord( source );
	if ( rows.length === 0 ) {
		return null;
	}

	const title = stringifyValue( source.title ?? source.label );
	return {
		title: title || 'Source diagnostics',
		rows,
	};
}

export function shouldRenderOperatorDiagnostics( enabled: boolean, metadata: Record< string, unknown > | null | undefined ): boolean {
	return enabled && !! metadata && getOperatorDiagnosticsRows( metadata ).length > 0;
}
