/**
 * The validation message under a control. Renders nothing when there is no message.
 */
interface Props {
	message?: string;
	/** Id the control points at with `aria-describedby`. */
	id?: string;
}

export default function FieldError( { message, id }: Props ) {
	if ( ! message ) {
		return null;
	}
	return (
		<p className="cl-field-error" id={ id }>
			{ message }
		</p>
	);
}
