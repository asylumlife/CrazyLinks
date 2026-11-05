/**
 * First-run wizard for a brand-new page: profile → theme → socials → document.
 */
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Modal,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import MediaField from './MediaField';
import { boot } from '../boot';
import { backgroundCss } from '../lib/theme';
import { parseSocialList } from '../lib/social';
import type { PageDoc } from '../types';
import { buildDocument, type WizardResult } from '../lib/wizard';

export default function Wizard( {
	onDone,
	onSkip,
}: {
	onDone: ( doc: PageDoc, title: string ) => void;
	onSkip: () => void;
} ) {
	const [ step, setStep ] = useState( 0 );
	const [ r, setR ] = useState< WizardResult >( {
		name: '',
		bio: '',
		theme: 'default',
		socials: '',
	} );
	const themes = boot().themes;
	const parsed = parseSocialList( r.socials );
	const networks = boot().networks;
	const titles = [
		__( 'About you', 'crazylinks' ),
		__( 'Pick a look', 'crazylinks' ),
		__( 'Your profiles', 'crazylinks' ),
	];

	return (
		<Modal
			title={ sprintf(
				/* translators: 1: step number, 2: step title. */ __(
					'Step %1$d of 3: %2$s',
					'crazylinks'
				),
				step + 1,
				titles[ step ]
			) }
			onRequestClose={ onSkip }
			className="cl-wizard"
			shouldCloseOnClickOutside={ false }
		>
			{ step === 0 && (
				<div className="cl-wizard__step">
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Name', 'crazylinks' ) }
						value={ r.name }
						maxLength={ 80 }
						onChange={ ( name: string ) => setR( { ...r, name } ) }
					/>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Short bio', 'crazylinks' ) }
						value={ r.bio }
						rows={ 3 }
						help={ `${ r.bio.length } / 280` }
						onChange={ ( bio: string ) => setR( { ...r, bio } ) }
					/>
					<MediaField
						label={ __( 'Avatar', 'crazylinks' ) }
						value={ r.avatar }
						onChange={ ( avatar ) => setR( { ...r, avatar } ) }
					/>
				</div>
			) }
			{ step === 1 && (
				<div
					className="cl-wizard__step cl-themes"
					role="radiogroup"
					aria-label={ __( 'Theme', 'crazylinks' ) }
				>
					{ Object.entries( themes ).map( ( [ slug, t ] ) => (
						<button
							type="button"
							role="radio"
							aria-checked={ r.theme === slug }
							key={ slug }
							className={
								r.theme === slug
									? 'cl-theme is-current'
									: 'cl-theme'
							}
							onClick={ () => setR( { ...r, theme: slug } ) }
						>
							<span
								className="cl-theme__swatch"
								style={ {
									background: backgroundCss( t.background ),
								} }
								aria-hidden="true"
							>
								<span
									className="cl-theme__btn"
									style={ {
										background: String(
											t.button?.color ?? '#111'
										),
										borderRadius:
											t.button?.shape === 'pill'
												? 999
												: 6,
									} }
								/>
							</span>
							<span className="cl-theme__label">{ t.label }</span>
						</button>
					) ) }
				</div>
			) }
			{ step === 2 && (
				<div className="cl-wizard__step">
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __(
							'Paste links to your profiles, one per line',
							'crazylinks'
						) }
						help={ __(
							'Instagram, TikTok, YouTube, X, email addresses and more are detected automatically.',
							'crazylinks'
						) }
						value={ r.socials }
						rows={ 6 }
						onChange={ ( socials: string ) =>
							setR( { ...r, socials } )
						}
					/>
					{ parsed.items.length > 0 && (
						<ul
							className="cl-wizard__detected"
							aria-label={ __(
								'Detected profiles',
								'crazylinks'
							) }
						>
							{ parsed.items.map( ( i ) => (
								<li key={ i.url }>
									<strong>
										{ networks[ i.network ] ?? i.network }
									</strong>{ ' ' }
									{ i.url }
								</li>
							) ) }
						</ul>
					) }
					{ parsed.rejected.length > 0 && (
						<p className="cl-field-error">
							{ sprintf(
								/* translators: %s: entries. */ __(
									'Not recognised: %s',
									'crazylinks'
								),
								parsed.rejected.join( ', ' )
							) }
						</p>
					) }
				</div>
			) }
			<div className="cl-modal-actions">
				{ step > 0 && (
					<Button
						variant="secondary"
						onClick={ () => setStep( step - 1 ) }
					>
						{ __( 'Back', 'crazylinks' ) }
					</Button>
				) }
				{ step < 2 ? (
					<Button
						variant="primary"
						onClick={ () => setStep( step + 1 ) }
					>
						{ __( 'Next', 'crazylinks' ) }
					</Button>
				) : (
					<Button
						variant="primary"
						onClick={ () =>
							onDone( buildDocument( r ), r.name.trim() )
						}
					>
						{ __( 'Create my page', 'crazylinks' ) }
					</Button>
				) }
				<Button variant="tertiary" onClick={ onSkip }>
					{ __( 'Skip and start blank', 'crazylinks' ) }
				</Button>
			</div>
		</Modal>
	);
}
