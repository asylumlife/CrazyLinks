/**
 * Colour control: swatch button opening a picker, a hex text field and "reset".
 */
import { __, sprintf } from '@wordpress/i18n';
import {
	BaseControl,
	Button,
	ColorPicker,
	Dropdown,
} from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';

interface Props {
	label: string;
	value: string | undefined;
	/** Value the page falls back to when unset (theme value). */
	fallback?: string;
	onChange: ( value: string | undefined ) => void;
	/** Allow an empty string (schema color_or_empty) instead of removing the key. */
	allowEmpty?: boolean;
	help?: string;
	error?: string;
}

export default function ColorField( {
	label,
	value,
	fallback,
	onChange,
	help,
	error,
}: Props ) {
	const id = useInstanceId( ColorField, 'cl-color' ) as string;
	const shown = value || fallback || '';
	return (
		<BaseControl
			id={ id }
			label={ label }
			help={ help }
			__nextHasNoMarginBottom
		>
			<div className="cl-color">
				<Dropdown
					popoverProps={ { placement: 'bottom-start' } }
					renderToggle={ ( { isOpen, onToggle } ) => (
						<button
							type="button"
							className="cl-color__swatch"
							onClick={ onToggle }
							aria-expanded={ isOpen }
							aria-label={ sprintf(
								/* translators: %s: control label. */ __(
									'Pick %s',
									'crazylinks'
								),
								label
							) }
							style={ { background: shown || 'transparent' } }
						/>
					) }
					renderContent={ () => (
						<ColorPicker
							color={ shown || '#000000' }
							onChange={ ( c: string ) => onChange( c ) }
							enableAlpha
						/>
					) }
				/>
				<input
					id={ id }
					className="cl-color__text"
					type="text"
					value={ value ?? '' }
					placeholder={ fallback ?? '' }
					aria-invalid={ !! error }
					aria-describedby={ error ? `${ id }-error` : undefined }
					onChange={ ( e ) => {
						const v = e.target.value.trim();
						onChange( v === '' ? undefined : v );
					} }
					spellCheck={ false }
				/>
				{ value !== undefined && value !== '' && (
					<Button
						variant="tertiary"
						size="small"
						onClick={ () => onChange( undefined ) }
					>
						{ fallback
							? __( 'Reset', 'crazylinks' )
							: __( 'Clear', 'crazylinks' ) }
					</Button>
				) }
			</div>
			{ error && (
				<p className="cl-field-error" id={ `${ id }-error` }>
					{ error }
				</p>
			) }
		</BaseControl>
	);
}
