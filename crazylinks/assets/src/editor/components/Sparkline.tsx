/**
 * Inline SVG sparkline (no chart library).
 */
interface Series {
	label: string;
	values: number[];
	className: string;
}

export function sparkPath(
	values: number[],
	width: number,
	height: number,
	max: number
): string {
	if ( values.length === 0 ) {
		return '';
	}
	const step = values.length > 1 ? width / ( values.length - 1 ) : 0;
	return values
		.map( ( v, i ) => {
			const x = values.length > 1 ? i * step : width / 2;
			const y =
				height - ( max > 0 ? ( v / max ) * ( height - 2 ) : 0 ) - 1;
			return `${ i === 0 ? 'M' : 'L' }${ x.toFixed( 1 ) },${ y.toFixed( 1 ) }`;
		} )
		.join( ' ' );
}

export default function Sparkline( {
	series,
	label,
}: {
	series: Series[];
	label: string;
} ) {
	const width = 280;
	const height = 56;
	const max = Math.max( 1, ...series.flatMap( ( s ) => s.values ) );
	return (
		<svg
			className="cl-spark"
			viewBox={ `0 0 ${ width } ${ height }` }
			width="100%"
			height={ height }
			role="img"
			aria-label={ label }
			preserveAspectRatio="none"
		>
			{ series.map( ( s ) => (
				<path
					key={ s.label }
					d={ sparkPath( s.values, width, height, max ) }
					className={ s.className }
					fill="none"
					vectorEffect="non-scaling-stroke"
				/>
			) ) }
		</svg>
	);
}
