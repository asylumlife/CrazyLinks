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
import ThemePicker from './ThemePicker';
import { boot } from '../boot';
import { PROFILE_BIO_MAX, PROFILE_NAME_MAX } from '../lib/limits';
import { parseSocialList } from '../lib/social';
import { DEFAULT_THEME } from '../lib/theme';
import type { PageDoc } from '../types';
import { buildDocument, type WizardResult } from '../lib/wizard';

const LAST_STEP = 2;

export default function Wizard( {
	onDone,
	onSkip,
}: {
	onDone: ( doc: PageDoc, title: string ) => void;
	onSkip: () => void;
} ) {
	const [ step, setStep ] = useState( 0 );
	const [ answers, setAnswers ] = useState< WizardResult >( {
		name: '',
		bio: '',
		theme: DEFAULT_THEME,
		socials: '',
	} );
	const answer = ( patch: Partial< WizardResult > ) =>
		setAnswers( { ...answers, ...patch } );
	const parsed = parseSocialList( answers.socials );
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
						value={ answers.name }
						maxLength={ PROFILE_NAME_MAX }
						onChange={ ( name: string ) => answer( { name } ) }
					/>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Short bio', 'crazylinks' ) }
						value={ answers.bio }
						rows={ 3 }
						help={ `${ answers.bio.length } / ${ PROFILE_BIO_MAX }` }
						onChange={ ( bio: string ) => answer( { bio } ) }
					/>
					<MediaField
						label={ __( 'Avatar', 'crazylinks' ) }
						value={ answers.avatar }
						onChange={ ( avatar ) => answer( { avatar } ) }
					/>
				</div>
			) }
			{ step === 1 && (
				<ThemePicker
					className="cl-wizard__step"
					current={ answers.theme }
					onPick={ ( theme ) => answer( { theme } ) }
				/>
			) }
			{ step === LAST_STEP && (
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
						value={ answers.socials }
						rows={ 6 }
						onChange={ ( socials: string ) =>
							answer( { socials } )
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
							{ parsed.items.map( ( item ) => (
								<li key={ item.url }>
									<strong>
										{ networks[ item.network ] ??
											item.network }
									</strong>{ ' ' }
									{ item.url }
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
				{ step < LAST_STEP ? (
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
							onDone(
								buildDocument( answers ),
								answers.name.trim()
							)
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
