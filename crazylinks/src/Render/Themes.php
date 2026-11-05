<?php
/**
 * Bundled design presets.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Render;

defined( 'ABSPATH' ) || exit;

/**
 * Named token presets (SPEC §5). Page tokens are merged over the chosen preset.
 *
 * Every preset is pure token data. `group` is a mood label for pickers; `dark` holds the overrides used when
 * the visitor prefers a dark colour scheme. tests/integration/ThemeContrastTest.php enforces WCAG AA for every pair.
 */
final class Themes {

	/**
	 * All presets keyed by slug.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		$themes = [
			'default'    => [
				'label'      => __( 'Default', 'crazylinks' ),
				'group'      => 'light',
				'background' => [
					'type'  => 'solid',
					'color' => '#f5f5f4',
				],
				'text'       => [
					'color' => '#1c1917',
					'muted' => '#57534e',
					'font'  => 'system-sans',
					'size'  => 'md',
				],
				'button'     => [
					'shape'        => 'rounded',
					'fill'         => 'solid',
					'color'        => '#1c1917',
					'text_color'   => '#fafaf9',
					'shadow'       => 'none',
					'border_width' => 0,
					'hover'        => 'lift',
				],
				'accent'     => '#4f46e5',
				'radius'     => 14,
				'dark'       => [
					'background' => [
						'type'  => 'solid',
						'color' => '#0c0a09',
					],
					'text'       => [
						'color' => '#fafaf9',
						'muted' => '#a8a29e',
					],
					'button'     => [
						'color'      => '#fafaf9',
						'text_color' => '#0c0a09',
					],
					'accent'     => '#a5b4fc',
				],
			],
			'midnight'   => [
				'label'      => __( 'Midnight', 'crazylinks' ),
				'group'      => 'dark',
				'background' => [
					'type'  => 'gradient',
					'from'  => '#0f172a',
					'to'    => '#312e81',
					'angle' => 160,
				],
				'text'       => [
					'color' => '#f8fafc',
					'muted' => '#cbd5e1',
					'font'  => 'system-sans',
					'size'  => 'md',
				],
				'button'     => [
					'shape'        => 'pill',
					'fill'         => 'soft',
					'color'        => '#ffffff',
					'text_color'   => '#0f172a',
					'shadow'       => 'none',
					'border_width' => 0,
					'hover'        => 'lift',
				],
				'accent'     => '#c4b5fd',
				'radius'     => 16,
			],
			'sunrise'    => [
				'label'      => __( 'Sunrise', 'crazylinks' ),
				'group'      => 'gradient',
				'background' => [
					'type'  => 'gradient',
					'from'  => '#fde68a',
					'to'    => '#f9a8d4',
					'angle' => 160,
				],
				'text'       => [
					'color' => '#1f2937',
					'muted' => '#1f2937',
					'font'  => 'system-rounded',
					'size'  => 'md',
				],
				'button'     => [
					'shape'        => 'pill',
					'fill'         => 'solid',
					'color'        => '#ffffff',
					'text_color'   => '#1f2937',
					'shadow'       => 'hard',
					'border_width' => 2,
					'hover'        => 'lift',
				],
				'accent'     => '#831843',
				'radius'     => 20,
			],
			'paper'      => [
				'label'      => __( 'Paper', 'crazylinks' ),
				'group'      => 'light',
				'background' => [
					'type'  => 'solid',
					'color' => '#fffdf7',
				],
				'text'       => [
					'color' => '#292524',
					'muted' => '#57534e',
					'font'  => 'system-serif',
					'size'  => 'md',
				],
				'button'     => [
					'shape'        => 'square',
					'fill'         => 'outline',
					'color'        => '#292524',
					'text_color'   => '#fffdf7',
					'shadow'       => 'none',
					'border_width' => 2,
					'hover'        => 'darken',
				],
				'accent'     => '#b45309',
				'radius'     => 4,
				'dark'       => [
					'background' => [
						'type'  => 'solid',
						'color' => '#1c1917',
					],
					'text'       => [
						'color' => '#f5f1e8',
						'muted' => '#c9c2b4',
					],
					'button'     => [
						'color'      => '#f5f1e8',
						'text_color' => '#1c1917',
					],
					'accent'     => '#fbbf24',
				],
			],
			'brutalist'  => [
				'label'      => __( 'Brutalist', 'crazylinks' ),
				'group'      => 'bold',
				'background' => [
					'type'  => 'solid',
					'color' => '#ffe600',
				],
				'text'       => [
					'color' => '#000000',
					'muted' => '#1a1a1a',
					'font'  => 'system-mono',
					'size'  => 'md',
				],
				'button'     => [
					'shape'        => 'square',
					'fill'         => 'solid',
					'color'        => '#000000',
					'text_color'   => '#ffe600',
					'shadow'       => 'hard',
					'border_width' => 3,
					'hover'        => 'lift',
				],
				'accent'     => '#b00020',
				'radius'     => 0,
			],
			'pastel'     => [
				'label'      => __( 'Pastel', 'crazylinks' ),
				'group'      => 'pastel',
				'background' => [
					'type'  => 'gradient',
					'from'  => '#fde2e4',
					'to'    => '#dbeafe',
					'angle' => 160,
				],
				'text'       => [
					'color' => '#3b3454',
					'muted' => '#5b5473',
					'font'  => 'system-rounded',
					'size'  => 'md',
				],
				'button'     => [
					'shape'        => 'pill',
					'fill'         => 'solid',
					'color'        => '#ffffff',
					'text_color'   => '#3b3454',
					'shadow'       => 'soft',
					'border_width' => 0,
					'hover'        => 'lift',
				],
				'accent'     => '#6d28d9',
				'radius'     => 20,
				'dark'       => [
					'background' => [
						'type'  => 'gradient',
						'from'  => '#2a2540',
						'to'    => '#1e2a44',
						'angle' => 160,
					],
					'text'       => [
						'color' => '#f1eefc',
						'muted' => '#c9c3e6',
					],
					'button'     => [
						'color'      => '#3d3560',
						'text_color' => '#f1eefc',
					],
					'accent'     => '#c4b5fd',
				],
			],
			'mono'       => [
				'label'      => __( 'Mono', 'crazylinks' ),
				'group'      => 'mono',
				'background' => [
					'type'  => 'solid',
					'color' => '#ffffff',
				],
				'text'       => [
					'color' => '#000000',
					'muted' => '#404040',
					'font'  => 'system-sans',
					'size'  => 'md',
				],
				'button'     => [
					'shape'        => 'square',
					'fill'         => 'outline',
					'color'        => '#000000',
					'text_color'   => '#ffffff',
					'shadow'       => 'none',
					'border_width' => 2,
					'hover'        => 'lift',
				],
				'accent'     => '#1d4ed8',
				'radius'     => 2,
				'dark'       => [
					'background' => [
						'type'  => 'solid',
						'color' => '#000000',
					],
					'text'       => [
						'color' => '#ffffff',
						'muted' => '#bdbdbd',
					],
					'button'     => [
						'color'      => '#ffffff',
						'text_color' => '#000000',
					],
					'accent'     => '#93c5fd',
				],
			],
			'neon'       => [
				'label'      => __( 'Neon', 'crazylinks' ),
				'group'      => 'dark',
				'background' => [
					'type'  => 'gradient',
					'from'  => '#0b0b14',
					'to'    => '#1a0b2e',
					'angle' => 180,
				],
				'text'       => [
					'color' => '#f5f5ff',
					'muted' => '#b8b8d8',
					'font'  => 'system-mono',
					'size'  => 'md',
				],
				'button'     => [
					'shape'        => 'pill',
					'fill'         => 'outline',
					'color'        => '#39ff14',
					'text_color'   => '#0b0b14',
					'shadow'       => 'soft',
					'border_width' => 2,
					'hover'        => 'lift',
				],
				'accent'     => '#ff2bd6',
				'radius'     => 12,
			],
			'forest'     => [
				'label'      => __( 'Forest', 'crazylinks' ),
				'group'      => 'earthy',
				'background' => [
					'type'  => 'gradient',
					'from'  => '#14231a',
					'to'    => '#2f3d24',
					'angle' => 170,
				],
				'text'       => [
					'color' => '#f1efe3',
					'muted' => '#cfd3bd',
					'font'  => 'system-serif',
					'size'  => 'md',
				],
				'button'     => [
					'shape'        => 'rounded',
					'fill'         => 'soft',
					'color'        => '#e9e4cf',
					'text_color'   => '#1c2a1e',
					'shadow'       => 'soft',
					'border_width' => 0,
					'hover'        => 'lift',
				],
				'accent'     => '#e0c27a',
				'radius'     => 12,
			],
			'terracotta' => [
				'label'      => __( 'Terracotta', 'crazylinks' ),
				'group'      => 'earthy',
				'background' => [
					'type'  => 'solid',
					'color' => '#f6e9dc',
				],
				'text'       => [
					'color' => '#3b2418',
					'muted' => '#5c4033',
					'font'  => 'system-serif',
					'size'  => 'md',
				],
				'button'     => [
					'shape'        => 'rounded',
					'fill'         => 'solid',
					'color'        => '#a8431f',
					'text_color'   => '#fff7ee',
					'shadow'       => 'none',
					'border_width' => 0,
					'hover'        => 'lift',
				],
				'accent'     => '#8a3412',
				'radius'     => 10,
				'dark'       => [
					'background' => [
						'type'  => 'solid',
						'color' => '#2a1810',
					],
					'text'       => [
						'color' => '#f6e9dc',
						'muted' => '#d9c3b0',
					],
					'button'     => [
						'color'      => '#e9b08e',
						'text_color' => '#2a1810',
					],
					'accent'     => '#f0a679',
				],
			],
			'glass'      => [
				'label'      => __( 'Glass', 'crazylinks' ),
				'group'      => 'glass',
				'background' => [
					'type'  => 'gradient',
					'from'  => '#1e1b4b',
					'to'    => '#0f3d4a',
					'angle' => 150,
				],
				'text'       => [
					'color' => '#ffffff',
					'muted' => '#dbe4ee',
					'font'  => 'system-sans',
					'size'  => 'md',
				],
				'button'     => [
					'shape'        => 'rounded',
					'fill'         => 'glass',
					'color'        => '#ffffff',
					'text_color'   => '#0f172a',
					'shadow'       => 'soft',
					'border_width' => 1,
					'hover'        => 'lift',
				],
				'accent'     => '#7dd3fc',
				'radius'     => 18,
			],
			'aurora'     => [
				'label'      => __( 'Aurora', 'crazylinks' ),
				'group'      => 'gradient',
				'background' => [
					'type'  => 'gradient',
					'from'  => '#115e59',
					'to'    => '#1e3a8a',
					'angle' => 135,
				],
				'text'       => [
					'color' => '#ffffff',
					'muted' => '#f0f9ff',
					'font'  => 'system-sans',
					'size'  => 'md',
				],
				'button'     => [
					'shape'        => 'pill',
					'fill'         => 'soft',
					'color'        => '#ffffff',
					'text_color'   => '#0b2a4a',
					'shadow'       => 'none',
					'border_width' => 0,
					'hover'        => 'lift',
				],
				'accent'     => '#bbf7d0',
				'radius'     => 16,
			],
			'retro'      => [
				'label'      => __( 'Retro', 'crazylinks' ),
				'group'      => 'pattern',
				'background' => [
					'type'         => 'pattern',
					'color'        => '#fff1cf',
					'pattern'      => 'dots',
					'pattern_size' => 20,
				],
				'text'       => [
					'color' => '#2b1d12',
					'muted' => '#5a4632',
					'font'  => 'system-serif',
					'size'  => 'md',
				],
				'button'     => [
					'shape'        => 'rounded',
					'fill'         => 'solid',
					'color'        => '#c2410c',
					'text_color'   => '#fffaf0',
					'shadow'       => 'hard',
					'border_width' => 2,
					'hover'        => 'lift',
				],
				'accent'     => '#9a3412',
				'radius'     => 8,
			],
			'blueprint'  => [
				'label'      => __( 'Blueprint', 'crazylinks' ),
				'group'      => 'pattern',
				'background' => [
					'type'         => 'pattern',
					'color'        => '#0b3b8c',
					'pattern'      => 'grid',
					'pattern_size' => 28,
				],
				'text'       => [
					'color' => '#e8f1ff',
					'muted' => '#bcd3f5',
					'font'  => 'system-mono',
					'size'  => 'md',
				],
				'button'     => [
					'shape'        => 'square',
					'fill'         => 'outline',
					'color'        => '#e8f1ff',
					'text_color'   => '#0b3b8c',
					'shadow'       => 'none',
					'border_width' => 2,
					'hover'        => 'lift',
				],
				'accent'     => '#ffd166',
				'radius'     => 0,
			],
		];
		/**
		 * Filters the bundled design presets.
		 *
		 * @param array<string, array<string, mixed>> $themes Presets keyed by slug.
		 */
		return (array) apply_filters( 'crazylinks_themes', $themes );
	}

	/**
	 * One preset, falling back to default.
	 *
	 * @param string $slug Slug.
	 * @return array<string, mixed>
	 */
	public static function get( string $slug ): array {
		$all = self::all();
		return (array) ( $all[ $slug ] ?? $all['default'] );
	}
}
