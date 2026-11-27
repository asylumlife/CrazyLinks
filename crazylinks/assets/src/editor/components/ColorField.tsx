/**
 * Colour control: swatch button opening a picker, a hex text field and a "reset" or "clear" button.
 */
import { __, sprintf } from '@wordpress/i18n';
import {
	BaseControl,
	Button,
	ColorPicker,
	Dropdown,
} from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import FieldError from './FieldError';

interface Props {
	label: string;
	value: string | undefined;
	/** Value the page falls back to when unset (theme value). */
	fallback?: string;
	onChange: ( value: string | undefined ) => void;
	/**
	 * The schema accepts an empty string (color_or_empty), which means "no colour" and overrides the
	 * fallback, while removing the key follows the fallback again.
	 */
	allowEmpty?: boolean;
	help?: string;
	error?: string;
}

/** Colour the picker opens on when nothing is set. */
const PICKER_DEFAULT = '#000000';

/**
 * The value to store for what was typed in the text field.
 *
 * @param raw        Text field content.
 * @param allowEmpty Whether an empty string is a value of its own.
 */
export function typedColor(
	raw: string,
	allowEmpty: boolean
): string | undefined {
	const typed = raw.trim();
	if ( typed !== '' ) {
		return typed;
	}
	return allowEmpty ? '' : undefined;
}

/**
 * The colour the control shows: the value, else the fallback, or nothing when the value is an
 * explicit "no colour".
 *
 * @param value      Stored value.
 * @param fallback   Theme value.
 * @param allowEmpty Whether an empty string is a value of its own.
 */
export function shownColor(
	value: string | undefined,
	fallback: string | undefined,
	allowEmpty: boolean
): string {
	if ( allowEmpty && value === '' ) {
		return '';
	}
	return value || fallback || '';
}

/**
 * What the button next to the text field does, or null when there is nothing to undo.
 *
 * - `reset` removes the value, so the fallback applies again.
 * - `clear` removes the colour: where an empty string is a value it stores '', which also overrides
 *   the fallback; elsewhere it removes the value.
 *
 * @param value      Stored value.
 * @param fallback   Theme value.
 * @param allowEmpty Whether an empty string is a value of its own.
 */
export function colorAction(
	value: string | undefined,
	fallback: string | undefined,
	allowEmpty: boolean
): { kind: 'reset' | 'clear'; next: string | undefined } | null {
	if ( allowEmpty ) {
		if ( value === '' ) {
			return { kind: 'reset', next: undefined };
		}
		return value || fallback ? { kind: 'clear', next: '' } : null;
	}
	if ( ! value ) {
		return null;
	}
	return { kind: fallback ? 'reset' : 'clear', next: undefined };
}

export default function ColorField( {
	label,
	value,
	fallback,
	onChange,
	allowEmpty = false,
	help,
	error,
}: Props ) {
	const id = useInstanceId( ColorField, 'cl-color' ) as string;
	const errorId = `${ id }-error`;
	const shown = shownColor( value, fallback, allowEmpty );
	const action = colorAction( value, fallback, allowEmpty );
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
							color={ shown || PICKER_DEFAULT }
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
					placeholder={ shown === '' ? '' : ( fallback ?? '' ) }
					aria-invalid={ !! error }
					aria-describedby={ error ? errorId : undefined }
					onChange={ ( e ) =>
						onChange( typedColor( e.target.value, allowEmpty ) )
					}
					spellCheck={ false }
				/>
				{ action && (
					<Button
						variant="tertiary"
						size="small"
						onClick={ () => onChange( action.next ) }
					>
						{ action.kind === 'reset'
							? __( 'Reset', 'crazylinks' )
							: __( 'Clear', 'crazylinks' ) }
					</Button>
				) }
			</div>
			<FieldError message={ error } id={ errorId } />
		</BaseControl>
	);
}
