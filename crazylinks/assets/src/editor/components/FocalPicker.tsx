/**
 * Focal point picker: click the image (or use the two sliders) to choose the part that stays in view
 * when the image is cropped. Stores `{ x, y }` between 0 and 1; the centre stores nothing.
 */
import { __ } from '@wordpress/i18n';
import { BaseControl, Button, RangeControl } from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import type { MouseEvent } from 'react';
import type { JsonValue } from '../types';
import { useEditor } from '../hooks';
import { focalFromPoint, normalizeFocal, readFocal } from '../lib/focal';

interface Props {
	label: string;
	imageId: number | undefined;
	value: JsonValue | undefined;
	onChange: ( value: JsonValue | undefined ) => void;
	help?: string;
}

/** Slider position (percent) used when a slider is reset. */
const CENTRE_PERCENT = 50;

export default function FocalPicker( {
	label,
	imageId,
	value,
	onChange,
	help,
}: Props ) {
	const labelId = useInstanceId( FocalPicker, 'cl-focal-label' ) as string;
	const media = useEditor(
		( s ) => ( imageId ? s.getMedia( imageId ) : undefined ),
		[ imageId ]
	);
	if ( ! imageId ) {
		return null;
	}
	const focal = readFocal( value );
	const pick = ( e: MouseEvent< HTMLDivElement > ) => {
		const box = e.currentTarget.getBoundingClientRect();
		onChange(
			focalFromPoint(
				e.clientX - box.left,
				e.clientY - box.top,
				box.width,
				box.height
			)
		);
	};
	return (
		<BaseControl help={ help } __nextHasNoMarginBottom>
			{ /* The control is a group of sliders, so it is named as a group, not through a label. */ }
			<BaseControl.VisualLabel id={ labelId }>
				{ label }
			</BaseControl.VisualLabel>
			<div className="cl-focal" role="group" aria-labelledby={ labelId }>
				{ media && media.mime.startsWith( 'image' ) && (
					// The sliders below are the keyboard equivalent of clicking the picture.
					// eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions
					<div className="cl-focal__image" onClick={ pick }>
						<img src={ media.thumb } alt="" />
						<span
							className="cl-focal__dot"
							style={ {
								left: `${ focal.x * 100 }%`,
								top: `${ focal.y * 100 }%`,
							} }
						/>
					</div>
				) }
				<RangeControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Horizontal position (%)', 'crazylinks' ) }
					min={ 0 }
					max={ 100 }
					value={ Math.round( focal.x * 100 ) }
					onChange={ ( v?: number ) =>
						onChange(
							normalizeFocal( {
								...focal,
								x: ( v ?? CENTRE_PERCENT ) / 100,
							} )
						)
					}
				/>
				<RangeControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Vertical position (%)', 'crazylinks' ) }
					min={ 0 }
					max={ 100 }
					value={ Math.round( focal.y * 100 ) }
					onChange={ ( v?: number ) =>
						onChange(
							normalizeFocal( {
								...focal,
								y: ( v ?? CENTRE_PERCENT ) / 100,
							} )
						)
					}
				/>
				<Button
					variant="tertiary"
					size="compact"
					disabled={ value === undefined }
					onClick={ () => onChange( undefined ) }
				>
					{ __( 'Reset to centre', 'crazylinks' ) }
				</Button>
			</div>
		</BaseControl>
	);
}
