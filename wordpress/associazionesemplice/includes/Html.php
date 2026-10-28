<?php
namespace AssociazioneSemplice;

defined( 'ABSPATH' ) || exit;

/**
 * L'HTML che il plugin stampa passa sempre da wp_kses() con questo elenco di tag e attributi: le pagine di amministrazione e
 * dell'area soci hanno moduli, tabelle, QR in SVG… che wp_kses_post() toglierebbe. Mai attributi di evento (onclick…) né script.
 *
 * Uso: echo wp_kses( $html, Html::allowed() );
 */
final class Html {

	/** Attributi ammessi su ogni tag. */
	const GLOBAL_ATTRS = array( 'class', 'id', 'style', 'title', 'role', 'hidden', 'tabindex', 'lang', 'dir', 'aria-label', 'aria-hidden', 'aria-expanded', 'aria-controls', 'aria-describedby', 'aria-labelledby', 'aria-live', 'aria-current', 'aria-pressed', 'aria-selected', 'aria-haspopup', 'aria-checked', 'aria-disabled', 'aria-invalid', 'aria-required', 'aria-busy', 'aria-modal', 'aria-valuenow', 'aria-valuemin', 'aria-valuemax' );

	/** Tag ammessi con i loro attributi propri (oltre a quelli globali e ai data-*). */
	const TAGS = array(
		'a'          => array( 'href', 'target', 'rel', 'download', 'name', 'hreflang', 'type' ),
		'abbr'       => array(),
		'article'    => array(),
		'aside'      => array(),
		'b'          => array(),
		'blockquote' => array( 'cite' ),
		'br'         => array(),
		'button'     => array( 'type', 'name', 'value', 'disabled', 'form', 'formaction', 'formmethod', 'formnovalidate', 'formtarget', 'autofocus' ),
		'caption'    => array(),
		'circle'     => array( 'cx', 'cy', 'r', 'fill', 'stroke', 'stroke-width' ),
		'code'       => array(),
		'col'        => array( 'span', 'width' ),
		'colgroup'   => array( 'span' ),
		'datalist'   => array(),
		'dd'         => array(),
		'del'        => array( 'datetime' ),
		'details'    => array( 'open', 'name' ),
		'div'        => array(),
		'dl'         => array(),
		'dt'         => array(),
		'em'         => array(),
		'fieldset'   => array( 'disabled', 'form', 'name' ),
		'figcaption' => array(),
		'figure'     => array(),
		'footer'     => array(),
		'form'       => array( 'action', 'method', 'enctype', 'target', 'novalidate', 'accept-charset', 'autocomplete', 'name' ),
		'g'          => array( 'fill', 'stroke', 'transform' ),
		'h1'         => array(),
		'h2'         => array(),
		'h3'         => array(),
		'h4'         => array(),
		'h5'         => array(),
		'h6'         => array(),
		'header'     => array(),
		'hr'         => array(),
		'i'          => array(),
		'img'        => array( 'src', 'alt', 'width', 'height', 'loading', 'decoding', 'srcset', 'sizes' ),
		'input'      => array( 'type', 'name', 'value', 'checked', 'disabled', 'readonly', 'required', 'placeholder', 'min', 'max', 'step', 'maxlength', 'minlength', 'size', 'pattern', 'multiple', 'accept', 'autocomplete', 'autofocus', 'inputmode', 'list', 'form', 'capture', 'enterkeyhint', 'spellcheck' ),
		'ins'        => array( 'datetime' ),
		'kbd'        => array(),
		'label'      => array( 'for', 'form' ),
		'legend'     => array(),
		'li'         => array( 'value' ),
		'line'       => array( 'x1', 'y1', 'x2', 'y2', 'stroke', 'stroke-width' ),
		'main'       => array(),
		'mark'       => array(),
		'meter'      => array( 'value', 'min', 'max', 'low', 'high', 'optimum' ),
		'nav'        => array(),
		'ol'         => array( 'start', 'reversed', 'type' ),
		'optgroup'   => array( 'label', 'disabled' ),
		'option'     => array( 'value', 'selected', 'disabled', 'label' ),
		'output'     => array( 'for', 'form', 'name' ),
		'p'          => array(),
		'path'       => array( 'd', 'fill', 'stroke', 'stroke-width', 'fill-rule', 'clip-rule', 'transform' ),
		'polygon'    => array( 'points', 'fill', 'stroke', 'stroke-width' ),
		'polyline'   => array( 'points', 'fill', 'stroke', 'stroke-width' ),
		'pre'        => array(),
		'progress'   => array( 'value', 'max' ),
		'q'          => array( 'cite' ),
		'rect'       => array( 'x', 'y', 'width', 'height', 'rx', 'ry', 'fill', 'stroke', 'stroke-width' ),
		's'          => array(),
		'section'    => array(),
		'select'     => array( 'name', 'multiple', 'required', 'disabled', 'size', 'form', 'autocomplete', 'autofocus' ),
		'small'      => array(),
		'span'       => array(),
		'strong'     => array(),
		'sub'        => array(),
		'summary'    => array(),
		'sup'        => array(),
		'svg'        => array( 'xmlns', 'viewbox', 'width', 'height', 'fill', 'stroke', 'preserveaspectratio', 'shape-rendering', 'focusable', 'version' ),
		'table'      => array( 'cellpadding', 'cellspacing', 'border', 'width', 'summary' ),
		'tbody'      => array(),
		'td'         => array( 'colspan', 'rowspan', 'headers', 'width', 'align', 'valign' ),
		'text'       => array( 'x', 'y', 'dx', 'dy', 'fill', 'font-size', 'font-family', 'font-weight', 'text-anchor', 'dominant-baseline' ),
		'textarea'   => array( 'name', 'rows', 'cols', 'maxlength', 'minlength', 'required', 'placeholder', 'readonly', 'disabled', 'form', 'autocomplete', 'autofocus', 'wrap', 'spellcheck' ),
		'tfoot'      => array(),
		'th'         => array( 'colspan', 'rowspan', 'headers', 'scope', 'abbr', 'width', 'align', 'valign' ),
		'thead'      => array(),
		'time'       => array( 'datetime' ),
		'tr'         => array(),
		'u'          => array(),
		'ul'         => array( 'type' ),
		'video'      => array( 'playsinline', 'muted', 'autoplay', 'loop', 'controls', 'poster', 'preload', 'width', 'height', 'src' ),
	);

	/** Tag in più per le pagine complete fuori dal tema (verifica della tessera, attivazione…): il doctype lo stampa chi le invia. */
	const DOCUMENT_TAGS = array(
		'html'  => array( 'lang' ),
		'head'  => array(),
		'meta'  => array( 'charset', 'name', 'content', 'http-equiv' ),
		'title' => array(),
		'link'  => array( 'rel', 'href', 'media', 'type', 'sizes' ),
		'body'  => array(),
	);

	/** Proprietà CSS usate negli attributi style del plugin che non tutte le versioni di WordPress ammettono. */
	const EXTRA_CSS = array( 'opacity', 'white-space', 'outline', 'pointer-events', 'overflow-x', 'overflow-y', 'inset', 'box-sizing', 'table-layout', 'container-type', 'aspect-ratio', 'object-fit', 'position', 'top', 'right', 'bottom', 'left', 'z-index', 'list-style', 'gap', 'align-self', 'align-items', 'justify-content', 'flex-wrap', 'display', 'cursor', '--*', '--a', '--c', '--asemf-accent', '--asemf-accent-2', '--asemf-accent-text', '--asemf-card-w', '--asemf-card-px' );

	/** @var array<string,array<string,bool>>|null */
	private static $allowed = null;

	/** @var array<string,array<string,bool>>|null */
	private static $document = null;

	/**
	 * Tag e attributi ammessi, per wp_kses().
	 *
	 * @return array<string,array<string,bool>>
	 */
	public static function allowed(): array {
		if ( null === self::$allowed ) {
			self::$allowed = self::build( self::TAGS );
			self::css_filters();
		}
		return self::$allowed;
	}

	/**
	 * Come {@see Html::allowed()} più html, head, meta, title, link e body: per le pagine complete stampate senza il tema.
	 *
	 * @return array<string,array<string,bool>>
	 */
	public static function document(): array {
		if ( null === self::$document ) {
			self::$document = self::allowed() + self::build( self::DOCUMENT_TAGS );
		}
		return self::$document;
	}

	/**
	 * Come {@see Html::allowed()} più tutto quello che WordPress ammette nel contenuto di un articolo (wp_kses_post): per i
	 * contenuti scritti dal gestore nell'editor (per esempio il blocco riservato di Elementor) insieme all'HTML del plugin.
	 *
	 * @return array<string,array<string,bool>>
	 */
	public static function with_post(): array {
		$out = self::allowed();
		foreach ( wp_kses_allowed_html( 'post' ) as $tag => $attrs ) {
			$out[ $tag ] = array_merge( is_array( $attrs ) ? $attrs : array(), $out[ $tag ] ?? array() );
		}
		return $out;
	}

	/**
	 * @param array<string,string[]> $tags
	 * @return array<string,array<string,bool>>
	 */
	private static function build( array $tags ): array {
		$out = array();
		foreach ( $tags as $tag => $attrs ) {
			$out[ $tag ] = array_fill_keys( array_merge( self::GLOBAL_ATTRS, $attrs ), true ) + array( 'data-*' => true );
		}
		return $out;
	}

	/** wp_kses() filtra anche gli stili in linea: ammette le poche proprietà e i colori rgb()/hsl() che il plugin usa. */
	private static function css_filters(): void {
		add_filter(
			'safe_style_css',
			static function ( $props ) {
				return array_values( array_unique( array_merge( (array) $props, self::EXTRA_CSS ) ) );
			}
		);
		add_filter(
			'safecss_filter_attr_allow_css',
			static function ( $allow, $css ) {
				if ( $allow ) {
					return $allow;
				}
				$rest = preg_replace( '/\b(?:rgba?|hsla?)\(\s*[0-9.,%\s\/]+\)/i', '', (string) $css ); // solo colori con numeri
				return ! preg_match( '%[\\\\(&=}]|/\*%', (string) $rest );
			},
			10,
			2
		);
	}
}
