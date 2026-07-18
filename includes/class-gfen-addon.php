<?php
defined( 'ABSPATH' ) || exit;

GFForms::include_addon_framework();

/**
 * Main add-on: a "Normalization" tab in each form's settings, rules detected
 * from BEFORE/AFTER examples, bulk application to existing entries and
 * (optionally) to future submissions.
 */
class GFEN_AddOn extends GFAddOn {

	protected $_version                  = GFEN_VERSION;
	protected $_min_gravityforms_version = '2.5';
	protected $_slug                     = 'entry-normalizer-for-gravity-forms';
	protected $_path                     = 'entry-normalizer-for-gravity-forms/entry-normalizer-for-gravity-forms.php';
	protected $_full_path                = GFEN_PLUGIN_FILE;
	protected $_title                    = 'Entry Normalizer for Gravity Forms';
	protected $_short_title              = 'Normalization';

	protected $_capabilities_settings_page = 'gravityforms_edit_forms';
	protected $_capabilities_form_settings = 'gravityforms_edit_forms';

	/**
	 * Number of entries processed per AJAX request.
	 */
	const BATCH_SIZE = 50;

	/**
	 * @var GFEN_AddOn|null
	 */
	private static $_instance = null;

	/**
	 * @return GFEN_AddOn
	 */
	public static function get_instance() {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Translatable form settings tab label.
	 *
	 * @return string
	 */
	public function get_short_title() {
		return __( 'Normalization', 'entry-normalizer-for-gravity-forms' );
	}

	/**
	 * Menu icon shown next to the add-on in the Gravity Forms settings.
	 *
	 * @return string Dashicon class (an "A" with a color bar, fitting a text-normalization tool).
	 */
	public function get_menu_icon() {
		return 'dashicons-editor-textcolor';
	}

	public function init() {
		parent::init();
		// Normalize future submissions (rules with the "future submissions" option enabled).
		add_filter( 'gform_save_field_value', array( $this, 'filter_save_field_value' ), 10, 5 );

		// Quick "Normalize" control in each field's Advanced tab (form editor).
		add_action( 'gform_field_advanced_settings', array( $this, 'render_field_setting' ), 10, 2 );
		add_action( 'gform_editor_js', array( $this, 'editor_inline_js' ) );
		add_filter( 'gform_tooltips', array( $this, 'editor_tooltips' ) );
		// Mirror the per-field quick choice into the rules list when the form is saved.
		add_action( 'gform_after_save_form', array( $this, 'sync_quick_rules' ), 10, 2 );
	}

	public function init_ajax() {
		parent::init_ajax();
		add_action( 'wp_ajax_gfen_detect', array( $this, 'ajax_detect' ) );
		add_action( 'wp_ajax_gfen_save_rule', array( $this, 'ajax_save_rule' ) );
		add_action( 'wp_ajax_gfen_delete_rule', array( $this, 'ajax_delete_rule' ) );
		add_action( 'wp_ajax_gfen_process', array( $this, 'ajax_process' ) );
	}

	public function scripts() {
		$scripts = array(
			array(
				'handle'    => 'gfen_admin',
				'src'       => GFEN_PLUGIN_URL . 'assets/js/gfen-admin.js',
				'version'   => $this->_version,
				'deps'      => array( 'jquery' ),
				'in_footer' => true,
				'enqueue'   => array(
					array(
						'admin_page' => array( 'form_settings' ),
						'tab'        => $this->_slug,
					),
				),
			),
		);
		return array_merge( parent::scripts(), $scripts );
	}

	public function styles() {
		$styles = array(
			array(
				'handle'  => 'gfen_admin',
				'src'     => GFEN_PLUGIN_URL . 'assets/css/gfen-admin.css',
				'version' => $this->_version,
				'enqueue' => array(
					array(
						'admin_page' => array( 'form_settings' ),
						'tab'        => $this->_slug,
					),
				),
			),
			array(
				'handle'  => 'gfen_field_editor',
				'src'     => GFEN_PLUGIN_URL . 'assets/css/gfen-field-editor.css',
				'version' => $this->_version,
				'enqueue' => array(
					array( 'admin_page' => array( 'form_editor' ) ),
				),
			),
		);
		return array_merge( parent::styles(), $styles );
	}

	/* ---------------------------------------------------------------------
	 * Form settings tab
	 * ------------------------------------------------------------------- */

	/**
	 * Custom rendering of the "Normalization" tab (all interaction goes through AJAX).
	 *
	 * @param array $form
	 */
	public function form_settings( $form ) {
		$rules = $this->get_rules( $form );
		$data  = array(
			'ajaxUrl'               => admin_url( 'admin-ajax.php' ),
			'nonce'                 => wp_create_nonce( 'gfen_ajax' ),
			'formId'                => (int) $form['id'],
			'fields'                => $this->get_target_fields( $form ),
			'fieldLabels'           => $this->get_field_labels( $form ),
			'transforms'            => GFEN_Transforms::all(),
			'transformDescriptions' => GFEN_Transforms::descriptions(),
			'rules'                 => $rules,
			'i18n'                  => $this->get_js_strings(),
		);
		// Static shell for the custom "Field Normalization" card; the rules list,
		// empty state and add/edit form are rendered into the mount points by JS.
		?>
		<div id="gfen-app" class="gfen-app">
			<div class="gform-settings-panel gform-settings-panel--full gfen-card">
				<header class="gform-settings-panel__header gfen-card__head">
					<legend class="gform-settings-panel__title gfen-card__title"><?php esc_html_e( 'Field Normalization', 'entry-normalizer-for-gravity-forms' ); ?></legend>
				</header>
				<div class="gform-settings-panel__content gfen-card__body">
					<p class="gfen-intro">
						<?php esc_html_e( 'Pick a field, provide one or more BEFORE / AFTER examples, and the plugin detects the matching transformation. Preview and apply the fix to existing entries, then enable the rule for future submissions.', 'entry-normalizer-for-gravity-forms' ); ?>
					</p>
					<div id="gfen-rules"></div>
					<div id="gfen-editor" hidden></div>
					<p class="gfen-foot-note"><?php esc_html_e( 'Rules run in the order they appear.', 'entry-normalizer-for-gravity-forms' ); ?></p>
				</div>
			</div>
		</div>
		<script type="text/javascript">
			window.gfenData = <?php echo wp_json_encode( $data ); ?>;
		</script>
		<?php
	}

	/**
	 * Strings used by the admin JavaScript (kept in PHP so they are translatable).
	 *
	 * @return array<string,string>
	 */
	private function get_js_strings() {
		return array(
			'noRules'            => __( 'No rules yet. Click “Add a modification” to get started.', 'entry-normalizer-for-gravity-forms' ),
			'futureBadge'        => __( 'Future submissions', 'entry-normalizer-for-gravity-forms' ),
			'quickBadge'         => __( 'Field editor', 'entry-normalizer-for-gravity-forms' ),
			/* translators: %s: number of examples. */
			'examplesCount'      => __( '%s example(s)', 'entry-normalizer-for-gravity-forms' ),
			'preview'            => __( 'Preview', 'entry-normalizer-for-gravity-forms' ),
			'applyExisting'      => __( 'Apply to existing entries', 'entry-normalizer-for-gravity-forms' ),
			'edit'               => __( 'Edit', 'entry-normalizer-for-gravity-forms' ),
			'delete'             => __( 'Delete', 'entry-normalizer-for-gravity-forms' ),
			'editRule'           => __( 'Edit rule', 'entry-normalizer-for-gravity-forms' ),
			'newRule'            => __( 'New modification', 'entry-normalizer-for-gravity-forms' ),
			'fieldToNormalize'   => __( 'Field to normalize:', 'entry-normalizer-for-gravity-forms' ),
			'examplesIntro'      => __( 'BEFORE / AFTER examples — the more you provide, the more accurate the detection:', 'entry-normalizer-for-gravity-forms' ),
			'beforePlaceholder'  => __( 'BEFORE (e.g. roger)', 'entry-normalizer-for-gravity-forms' ),
			'afterPlaceholder'   => __( 'AFTER (e.g. ROGER)', 'entry-normalizer-for-gravity-forms' ),
			'removeExample'      => __( 'Remove this example', 'entry-normalizer-for-gravity-forms' ),
			'addExample'         => __( 'Add an example', 'entry-normalizer-for-gravity-forms' ),
			'detect'             => __( 'Detect transformation', 'entry-normalizer-for-gravity-forms' ),
			'detecting'          => __( 'Detecting…', 'entry-normalizer-for-gravity-forms' ),
			'candidatesIntro'    => __( 'Detected transformation(s) — choose the one to apply:', 'entry-normalizer-for-gravity-forms' ),
			'noMatch'            => __( 'No known transformation reproduces all your examples. Check the pairs (typos, spaces) or simplify them.', 'entry-normalizer-for-gravity-forms' ),
			'detectError'        => __( 'Error during detection.', 'entry-normalizer-for-gravity-forms' ),
			'networkError'       => __( 'Network error. Please try again.', 'entry-normalizer-for-gravity-forms' ),
			'applyFutureLabel'   => __( 'Also apply automatically to future submissions', 'entry-normalizer-for-gravity-forms' ),
			'saveRule'           => __( 'Save rule', 'entry-normalizer-for-gravity-forms' ),
			'cancel'             => __( 'Cancel', 'entry-normalizer-for-gravity-forms' ),
			'needExample'        => __( 'Provide at least one example (the BEFORE column must not be empty).', 'entry-normalizer-for-gravity-forms' ),
			'needCandidate'      => __( 'Run the detection and choose a transformation.', 'entry-normalizer-for-gravity-forms' ),
			'saveError'          => __( 'Save failed.', 'entry-normalizer-for-gravity-forms' ),
			/* translators: %1$s: number of entries processed so far, %2$s: total number of entries. */
			'applyProgress'      => __( 'Applying: %1$s / %2$s entries…', 'entry-normalizer-for-gravity-forms' ),
			/* translators: %1$s: number of entries processed so far, %2$s: total number of entries. */
			'previewProgress'    => __( 'Analyzing: %1$s / %2$s entries…', 'entry-normalizer-for-gravity-forms' ),
			/* translators: %1$s: number of modified entries, %2$s: number of analyzed entries. */
			'applySummary'       => __( '%1$s entry(ies) modified out of %2$s analyzed.', 'entry-normalizer-for-gravity-forms' ),
			/* translators: %s: number of write errors. */
			'writeErrors'        => __( '%s write error(s).', 'entry-normalizer-for-gravity-forms' ),
			/* translators: %1$s: number of entries that would be modified, %2$s: number of analyzed entries. */
			'previewSummary'     => __( '%1$s entry(ies) would be modified out of %2$s analyzed. Nothing has been written.', 'entry-normalizer-for-gravity-forms' ),
			/* translators: %s: number of unrecognized phone values. */
			'unrecognizedPhones' => __( '%s value(s) not recognized as a French phone number — left unchanged:', 'entry-normalizer-for-gravity-forms' ),
			'entryLabel'         => __( 'Entry', 'entry-normalizer-for-gravity-forms' ),
			'beforeCol'          => __( 'Before', 'entry-normalizer-for-gravity-forms' ),
			'afterApplied'       => __( 'After (applied)', 'entry-normalizer-for-gravity-forms' ),
			'afterProposed'      => __( 'After (proposed)', 'entry-normalizer-for-gravity-forms' ),
			/* translators: %s: number of additional changes not displayed. */
			'moreChanges'        => __( '… and %s more change(s) not shown.', 'entry-normalizer-for-gravity-forms' ),
			'noCompatibleField'  => __( 'This form has no compatible text field.', 'entry-normalizer-for-gravity-forms' ),
			'confirmApply'       => __( "Permanently apply this transformation to the existing entries?\n\nTip: run a preview first. This action modifies stored data.", 'entry-normalizer-for-gravity-forms' ),
			'confirmDelete'      => __( 'Delete this rule? (Entries already modified will not be restored.)', 'entry-normalizer-for-gravity-forms' ),
			'processError'       => __( 'Error during processing.', 'entry-normalizer-for-gravity-forms' ),
			/* translators: separator between chained transformation labels, e.g. "Remove accents, then ALL UPPERCASE". */
			'chainSeparator'     => __( ', then ', 'entry-normalizer-for-gravity-forms' ),
			/* translators: %s: field id. */
			'deletedField'       => __( 'Deleted field (%s)', 'entry-normalizer-for-gravity-forms' ),

			// Redesigned card / form UI.
			'addModification'    => __( 'Add a modification', 'entry-normalizer-for-gravity-forms' ),
			'emptyTitle'         => __( 'No normalization rules yet', 'entry-normalizer-for-gravity-forms' ),
			'emptyDesc'          => __( 'Create your first rule to automatically clean up field values — like forcing uppercase, trimming spaces, or fixing formats.', 'entry-normalizer-for-gravity-forms' ),
			/* translators: %s: number of rules (singular form). */
			'ruleCountOne'       => __( '%s rule', 'entry-normalizer-for-gravity-forms' ),
			/* translators: %s: number of rules (plural form). */
			'ruleCountMany'      => __( '%s rules', 'entry-normalizer-for-gravity-forms' ),
			'notSaved'           => __( 'Not saved', 'entry-normalizer-for-gravity-forms' ),
			'fieldStepLabel'     => __( 'Field to normalize', 'entry-normalizer-for-gravity-forms' ),
			'examplesLabel'      => __( 'BEFORE / AFTER examples', 'entry-normalizer-for-gravity-forms' ),
			'examplesHint'       => __( 'The more you provide, the more accurate the detection.', 'entry-normalizer-for-gravity-forms' ),
			'beforeBadge'        => __( 'BEFORE', 'entry-normalizer-for-gravity-forms' ),
			'afterBadge'         => __( 'AFTER', 'entry-normalizer-for-gravity-forms' ),
			'beforeExample'      => __( 'e.g. roger', 'entry-normalizer-for-gravity-forms' ),
			'afterExample'       => __( 'e.g. ROGER', 'entry-normalizer-for-gravity-forms' ),
			'detectStepLabel'    => __( 'Detect the transformation', 'entry-normalizer-for-gravity-forms' ),
			'exactMatch'         => __( 'Exact match', 'entry-normalizer-for-gravity-forms' ),
			'applyFutureDesc'    => __( 'New entries will be normalized as they come in.', 'entry-normalizer-for-gravity-forms' ),
			'saveHint'           => __( "This won't touch existing entries until you preview & apply.", 'entry-normalizer-for-gravity-forms' ),
		);
	}

	/**
	 * Field/input types whose stored value can be normalized.
	 *
	 * @return string[]
	 */
	private function supported_types() {
		return array(
			'text', 'textarea', 'email', 'phone', 'website', 'hidden', 'number',
			'name', 'address', 'post_title', 'post_content', 'post_excerpt',
			'post_tags', 'post_custom_field',
		);
	}

	/**
	 * Display labels for every normalizable field and input (id => label).
	 *
	 * Includes the parent field id for multi-input fields (e.g. "1" for a Name
	 * field) so that "quick" rules — which target the whole field — can be
	 * labelled in the Normalization tab.
	 *
	 * @param array $form
	 * @return array<string,string>
	 */
	private function get_field_labels( $form ) {
		$labels = array();
		foreach ( $form['fields'] as $field ) {
			if ( ! in_array( $field->type, $this->supported_types(), true ) ) {
				continue;
			}
			$label = '' !== trim( (string) $field->label ) ? $field->label : $field->type;
			$labels[ (string) $field->id ] = $label . ' (' . $field->id . ')';
			$inputs = is_callable( array( $field, 'get_entry_inputs' ) ) ? $field->get_entry_inputs() : $field->inputs;
			if ( is_array( $inputs ) ) {
				foreach ( $inputs as $input ) {
					if ( rgar( $input, 'isHidden' ) ) {
						continue;
					}
					$labels[ (string) $input['id'] ] = $label . ' — ' . rgar( $input, 'label' ) . ' (' . $input['id'] . ')';
				}
			}
		}
		return $labels;
	}

	/**
	 * Target fields/inputs of the form (text-like types only).
	 *
	 * @param array $form
	 * @return array Array of ['id' => string, 'label' => string, 'warning' => string].
	 */
	private function get_target_fields( $form ) {
		$supported = $this->supported_types();
		$targets   = array();
		foreach ( $form['fields'] as $field ) {
			if ( ! in_array( $field->type, $supported, true ) ) {
				continue;
			}
			$label   = '' !== trim( (string) $field->label ) ? $field->label : $field->type;
			$warning = $this->get_field_conflict_warning( $field );
			$inputs  = is_callable( array( $field, 'get_entry_inputs' ) ) ? $field->get_entry_inputs() : $field->inputs;
			if ( is_array( $inputs ) ) {
				// Whole-field target: applies to every sub-field. Used by the quick
				// "Normalize" control and available for manual rules too.
				$targets[] = array(
					'id'      => (string) $field->id,
					'label'   => $label . ' — ' . __( 'whole field', 'entry-normalizer-for-gravity-forms' ) . ' (' . $field->id . ')',
					'warning' => $warning,
				);
				foreach ( $inputs as $input ) {
					if ( rgar( $input, 'isHidden' ) ) {
						continue;
					}
					$targets[] = array(
						'id'      => (string) $input['id'],
						'label'   => $label . ' — ' . rgar( $input, 'label' ) . ' (' . $input['id'] . ')',
						'warning' => $warning,
					);
				}
			} else {
				$targets[] = array(
					'id'      => (string) $field->id,
					'label'   => $label . ' (' . $field->id . ')',
					'warning' => $warning,
				);
			}
		}
		return $targets;
	}

	/**
	 * Warning when another formatting plugin is already configured on this field
	 * (it would run after our rules on future submissions).
	 *
	 * @param GF_Field $field
	 * @return string Warning message, or empty string.
	 */
	private function get_field_conflict_warning( $field ) {
		// GF Auto Formatter (Plugin Owl): active and configured on this field?
		if ( function_exists( 'gfaf_auto_format_field_value' ) ) {
			$gfaf_props = array(
				'gfafuppercaseField', 'gfaflowercaseField', 'gfafucfirstletterField',
				'gfafucfirstlettersField', 'gfafalphanumericonlyField', 'gfafenglishonlyField',
				'gfafbeforeField', 'gfafafterField', 'gfaftextreplaceField',
				'gfaftextreplace2Field', 'gfafremovelinksField', 'gfafremovehtmlField',
			);
			foreach ( $gfaf_props as $prop ) {
				if ( ! empty( $field->{$prop} ) ) {
					return __( 'Warning: this field already has “GF Auto Formatter” formatting configured (in the field’s Advanced tab). On new submissions, both formattings will run in sequence and may contradict each other. The bulk apply, however, always matches the preview.', 'entry-normalizer-for-gravity-forms' );
				}
			}
		}
		return '';
	}

	/* ---------------------------------------------------------------------
	 * Quick "Normalize" control in the field's Advanced tab (form editor)
	 * ------------------------------------------------------------------- */

	/**
	 * The four most-requested casing options offered by the quick control,
	 * mapped to their transformation id ('' = off). Order matches the UI.
	 *
	 * @return array<string,string> value (transform id or '') => label.
	 */
	private function normalize_options() {
		return array(
			'uppercase'     => __( 'UPPER CASE', 'entry-normalizer-for-gravity-forms' ),
			'lowercase'     => __( 'lower case', 'entry-normalizer-for-gravity-forms' ),
			'sentence_case' => __( 'First letter upper case', 'entry-normalizer-for-gravity-forms' ),
			'title_case'    => __( 'First Letters Upper Case', 'entry-normalizer-for-gravity-forms' ),
			''              => __( 'off', 'entry-normalizer-for-gravity-forms' ),
		);
	}

	/**
	 * Render the "Normalize" setting row inside the field's Advanced tab.
	 *
	 * The row is hidden by default and shown by Gravity Forms only for the
	 * supported field types (registered in editor_inline_js()). Its value is
	 * populated from the field's "gfenNormalize" property on field load.
	 *
	 * @param int $position Current insertion slot in the Advanced tab.
	 * @param int $form_id  Form being edited (unused).
	 */
	public function render_field_setting( $position, $form_id ) {
		// The Advanced tab fires this action at several positions; print once, on
		// the first one (near the top), rather than hardcoding a position number
		// that could change between Gravity Forms versions.
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
		<li class="gfen_normalize_setting field_setting">
			<label class="section_label">
				<?php esc_html_e( 'Normalize', 'entry-normalizer-for-gravity-forms' ); ?>
				<?php gform_tooltip( 'gfen_normalize' ); ?>
			</label>
			<div class="gfen-normalize-options">
				<?php
				foreach ( $this->normalize_options() as $value => $label ) :
					// Same native markup as Gravity Forms' own radios (sibling
					// <input> + <label class="inline">) so they render identically.
					$rid = 'gfen_normalize_' . ( '' === $value ? 'off' : $value );
					?>
					<div class="gfen-normalize-option">
						<input type="radio" name="gfen_normalize_option" id="<?php echo esc_attr( $rid ); ?>" value="<?php echo esc_attr( $value ); ?>" />
						<label for="<?php echo esc_attr( $rid ); ?>" class="inline"><?php echo esc_html( $label ); ?></label>
						<?php gform_tooltip( $rid ); ?>
					</div>
				<?php endforeach; ?>
			</div>
			<a href="#" class="gfen-more-options" target="_blank" rel="noopener">
				<?php esc_html_e( 'More options…', 'entry-normalizer-for-gravity-forms' ); ?>
			</a>
		</li>
		<li class="gfen_normalize_setting_multi field_setting">
			<label class="section_label">
				<?php esc_html_e( 'Normalize', 'entry-normalizer-for-gravity-forms' ); ?>
				<?php gform_tooltip( 'gfen_normalize_multi' ); ?>
			</label>
			<p class="gfen-normalize-multi-desc">
				<?php esc_html_e( 'This field has multiple parts (e.g. first/last name). Set up normalization for it from the Normalization tab.', 'entry-normalizer-for-gravity-forms' ); ?>
			</p>
			<a href="#" class="gfen-more-options gfen-more-options-multi" target="_blank" rel="noopener">
				<?php esc_html_e( 'More options…', 'entry-normalizer-for-gravity-forms' ); ?>
			</a>
		</li>
		<?php
	}

	/**
	 * Editor JavaScript: register the setting rows for the supported field
	 * types, load the saved value on field selection, persist changes as a
	 * field property, and point "More options" at the Normalization tab (field
	 * preselected).
	 *
	 * Multi-input fields (Name, Address…) get a link-only row instead of the
	 * quick radios: applying one casing to prefix/first/middle/last/suffix
	 * alike rarely makes sense, so those fields go straight to the full rule
	 * editor (which offers per-sub-field or whole-field targeting).
	 */
	public function editor_inline_js() {
		$form_id      = absint( rgget( 'id' ) );
		$settings_url = admin_url( 'admin.php?page=gf_edit_forms&view=settings&subview=' . $this->_slug . '&id=' . $form_id );
		?>
		<script type="text/javascript">
		( function ( $ ) {
			var GFEN_TYPES = <?php echo wp_json_encode( array_values( $this->supported_types() ) ); ?>;
			var GFEN_SETTINGS_URL = <?php echo wp_json_encode( $settings_url ); ?>;

			// Make both rows candidates for every supported field type; which one
			// actually shows is decided at runtime below, from the loaded field's
			// own inputs (not just its type — e.g. a Name field can be single-input
			// in the "simple" format).
			for ( var i = 0; i < GFEN_TYPES.length; i++ ) {
				var t = GFEN_TYPES[ i ];
				if ( typeof fieldSettings[ t ] !== 'undefined' ) {
					fieldSettings[ t ] += ', .gfen_normalize_setting, .gfen_normalize_setting_multi';
				}
			}

			// Show the matching row, populate the radios, and point "More options"
			// at the Normalization tab with this field preselected.
			$( document ).on( 'gform_load_field_settings', function ( e, field ) {
				var isMulti = !!( field && Array.isArray( field.inputs ) && field.inputs.length > 0 );
				$( '.gfen_normalize_setting' ).toggle( ! isMulti );
				$( '.gfen_normalize_setting_multi' ).toggle( isMulti );

				var val = ( field && field.gfenNormalize ) ? String( field.gfenNormalize ) : '';
				$( 'input[name="gfen_normalize_option"]' ).each( function () {
					this.checked = ( this.value === val );
				} );

				if ( field ) {
					$( '.gfen-more-options' ).attr( 'href', GFEN_SETTINGS_URL + '&gfen_field=' + encodeURIComponent( field.id ) );
				}
			} );

			// Persist on change. Bound directly on the inputs — delegated handlers
			// on document never fire inside the field settings sidebar.
			$( function () {
				$( 'input[name="gfen_normalize_option"]' ).on( 'change', function () {
					SetFieldProperty( 'gfenNormalize', this.value );
				} );
			} );
		}( jQuery ) );
		</script>
		<?php
	}

	/**
	 * Tooltips for the quick "Normalize" control.
	 *
	 * @param array $tooltips
	 * @return array
	 */
	public function editor_tooltips( $tooltips ) {
		$tooltips['gfen_normalize'] = '<h6>' . esc_html__( 'Normalize', 'entry-normalizer-for-gravity-forms' ) . '</h6>'
			. esc_html__( 'Automatically clean this field’s value on new submissions. Existing entries are untouched — use “More options” to also fix them.', 'entry-normalizer-for-gravity-forms' );
		$tooltips['gfen_normalize_multi'] = '<h6>' . esc_html__( 'Normalize', 'entry-normalizer-for-gravity-forms' ) . '</h6>'
			. esc_html__( 'This field has multiple parts, so no single casing fits them all here. Open the Normalization tab to set up per-part (or whole-field) rules.', 'entry-normalizer-for-gravity-forms' );
		$tooltips['gfen_normalize_uppercase']     = esc_html__( 'Example: roger → ROGER', 'entry-normalizer-for-gravity-forms' );
		$tooltips['gfen_normalize_lowercase']     = esc_html__( 'Example: ROGER → roger', 'entry-normalizer-for-gravity-forms' );
		$tooltips['gfen_normalize_sentence_case'] = esc_html__( 'Example: roger → Roger', 'entry-normalizer-for-gravity-forms' );
		$tooltips['gfen_normalize_title_case']    = esc_html__( 'Example: jean dupont → Jean Dupont', 'entry-normalizer-for-gravity-forms' );
		$tooltips['gfen_normalize_off']           = esc_html__( 'No automatic normalization for this field.', 'entry-normalizer-for-gravity-forms' );
		return $tooltips;
	}

	/**
	 * Mirror each field's "gfenNormalize" property into a matching "quick" rule
	 * when the form is saved in the editor. Creates, updates or removes the
	 * quick rule (on "off"); example-based rules are left untouched.
	 *
	 * @param array $form
	 * @param bool  $is_new
	 */
	public function sync_quick_rules( $form, $is_new = false ) {
		$form_id = is_array( $form ) ? absint( rgar( $form, 'id' ) ) : 0;
		if ( ! $form_id ) {
			return;
		}
		// Reload the authoritative, post-save form (with add-on settings + field props).
		$form = GFAPI::get_form( $form_id );
		if ( ! $form ) {
			return;
		}

		// Desired quick choices: field id => transform id.
		$desired = array();
		foreach ( $form['fields'] as $field ) {
			if ( ! in_array( $field->type, $this->supported_types(), true ) ) {
				continue;
			}
			$choice = rgar( $field, 'gfenNormalize' );
			if ( is_string( $choice ) && '' !== $choice && GFEN_Transforms::is_valid( $choice ) ) {
				$desired[ (string) $field->id ] = $choice;
			}
		}

		$rules    = $this->get_rules( $form );
		$new      = array();
		$handled  = array();
		$changed  = false;
		foreach ( $rules as $rule ) {
			if ( empty( $rule['quick'] ) ) {
				$new[] = $rule;
				continue;
			}
			$fid = (string) $rule['field_id'];
			if ( isset( $desired[ $fid ] ) ) {
				$wanted = array( $desired[ $fid ] );
				if ( $rule['chain'] !== $wanted || empty( $rule['apply_future'] ) ) {
					$rule['chain']        = $wanted;
					$rule['apply_future'] = true;
					$changed              = true;
				}
				$new[]           = $rule;
				$handled[ $fid ] = true;
			} else {
				// Field set back to "off": drop its quick rule.
				$changed = true;
			}
		}
		foreach ( $desired as $fid => $transform ) {
			if ( ! empty( $handled[ $fid ] ) ) {
				continue;
			}
			$new[] = array(
				'id'           => uniqid( 'rq' ),
				'field_id'     => $fid,
				'chain'        => array( $transform ),
				'examples'     => array(),
				'apply_future' => true,
				'quick'        => true,
			);
			$changed = true;
		}

		if ( $changed ) {
			$this->save_rules( $form, $new );
		}
	}

	/**
	 * Set the "gfenNormalize" property on a field of the in-memory form (by
	 * object handle, so the change persists on the next save_rules() write).
	 * Used to detach/clear the field's quick control when its rule is edited or
	 * deleted from the Normalization tab.
	 *
	 * @param array  $form
	 * @param string $field_id
	 * @param string $value
	 * @return bool True if a matching field was found.
	 */
	private function set_field_normalize_property( $form, $field_id, $value ) {
		foreach ( $form['fields'] as $field ) {
			if ( (string) $field->id === (string) $field_id ) {
				$field->gfenNormalize = $value;
				return true;
			}
		}
		return false;
	}

	/* ---------------------------------------------------------------------
	 * Rule storage (form meta, under the add-on slug key)
	 * ------------------------------------------------------------------- */

	/**
	 * @param array $form
	 * @return array
	 */
	public function get_rules( $form ) {
		$settings = $this->get_form_settings( $form );
		$rules    = is_array( $settings ) ? rgar( $settings, 'rules' ) : array();
		return is_array( $rules ) ? array_values( $rules ) : array();
	}

	/**
	 * @param array $form
	 * @param array $rules
	 * @return bool
	 */
	private function save_rules( $form, $rules ) {
		$settings = $this->get_form_settings( $form );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings['rules'] = array_values( $rules );
		return $this->save_form_settings( $form, $settings );
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------- */

	/**
	 * Verify nonce + capability and return the requested form.
	 *
	 * @param string $capability
	 * @return array The form.
	 */
	private function verify_ajax( $capability ) {
		check_ajax_referer( 'gfen_ajax', 'nonce' );
		if ( ! GFCommon::current_user_can_any( $capability ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'entry-normalizer-for-gravity-forms' ) ), 403 );
		}
		$form_id = absint( rgpost( 'form_id' ) );
		$form    = $form_id ? GFAPI::get_form( $form_id ) : false;
		if ( ! $form ) {
			wp_send_json_error( array( 'message' => __( 'Form not found.', 'entry-normalizer-for-gravity-forms' ) ), 400 );
		}
		return $form;
	}

	/**
	 * Read and validate the BEFORE/AFTER examples sent as JSON.
	 *
	 * @return array
	 */
	private function read_examples() {
		// rgpost() strips slashes by default; pass false so wp_unslash() removes
		// exactly one level (double-unslashing corrupts JSON-escaped quotes/backslashes).
		$raw   = json_decode( wp_unslash( rgpost( 'examples', false ) ), true );
		$pairs = array();
		if ( is_array( $raw ) ) {
			foreach ( array_slice( $raw, 0, 50 ) as $pair ) {
				if ( is_array( $pair ) && isset( $pair['before'], $pair['after'] )
					&& is_string( $pair['before'] ) && is_string( $pair['after'] )
					&& '' !== $pair['before'] ) {
					$pairs[] = array(
						'before' => $pair['before'],
						'after'  => $pair['after'],
					);
				}
			}
		}
		return $pairs;
	}

	/**
	 * Detect candidate transformations from the examples.
	 */
	public function ajax_detect() {
		$this->verify_ajax( 'gravityforms_edit_forms' );

		$pairs = $this->read_examples();
		if ( empty( $pairs ) ) {
			wp_send_json_error( array( 'message' => __( 'Provide at least one BEFORE/AFTER example.', 'entry-normalizer-for-gravity-forms' ) ) );
		}
		$has_change = false;
		foreach ( $pairs as $pair ) {
			if ( $pair['before'] !== $pair['after'] ) {
				$has_change = true;
				break;
			}
		}
		if ( ! $has_change ) {
			wp_send_json_error( array( 'message' => __( 'All your examples are identical before and after: there is no transformation to detect.', 'entry-normalizer-for-gravity-forms' ) ) );
		}

		$chains     = GFEN_Transforms::detect( $pairs );
		$candidates = array();
		foreach ( $chains as $chain ) {
			$candidates[] = array(
				'chain' => $chain,
				'label' => GFEN_Transforms::chain_label( $chain ),
			);
		}
		wp_send_json_success( array( 'candidates' => $candidates ) );
	}

	/**
	 * Save (create or replace) a rule.
	 */
	public function ajax_save_rule() {
		$form = $this->verify_ajax( 'gravityforms_edit_forms' );

		// See read_examples(): pass false so wp_unslash() unslashes exactly once.
		$raw = json_decode( wp_unslash( rgpost( 'rule', false ) ), true );
		if ( ! is_array( $raw ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid rule.', 'entry-normalizer-for-gravity-forms' ) ) );
		}

		$field_id = isset( $raw['field_id'] ) ? (string) $raw['field_id'] : '';
		if ( ! preg_match( '/^\d+(\.\d+)?$/', $field_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid target field.', 'entry-normalizer-for-gravity-forms' ) ) );
		}

		$chain = array();
		if ( isset( $raw['chain'] ) && is_array( $raw['chain'] ) ) {
			foreach ( array_slice( $raw['chain'], 0, 3 ) as $id ) {
				if ( is_string( $id ) && GFEN_Transforms::is_valid( $id ) ) {
					$chain[] = $id;
				}
			}
		}
		if ( empty( $chain ) ) {
			wp_send_json_error( array( 'message' => __( 'No transformation selected.', 'entry-normalizer-for-gravity-forms' ) ) );
		}

		$examples = array();
		if ( isset( $raw['examples'] ) && is_array( $raw['examples'] ) ) {
			foreach ( array_slice( $raw['examples'], 0, 50 ) as $pair ) {
				if ( is_array( $pair ) && isset( $pair['before'], $pair['after'] )
					&& is_string( $pair['before'] ) && is_string( $pair['after'] ) ) {
					$examples[] = array(
						'before' => sanitize_textarea_field( $pair['before'] ),
						'after'  => sanitize_textarea_field( $pair['after'] ),
					);
				}
			}
		}

		$rule = array(
			'id'           => ( isset( $raw['id'] ) && is_string( $raw['id'] ) && preg_match( '/^r[a-z0-9]+$/', $raw['id'] ) ) ? $raw['id'] : uniqid( 'r' ),
			'field_id'     => $field_id,
			'chain'        => $chain,
			'examples'     => $examples,
			'apply_future' => ! empty( $raw['apply_future'] ),
		);

		$rules    = $this->get_rules( $form );
		$replaced = false;
		foreach ( $rules as $i => $existing ) {
			if ( $existing['id'] === $rule['id'] ) {
				// Editing a "quick" rule graduates it to a normal rule: drop the
				// marker and clear the field's Advanced-tab control so the next
				// form save won't overwrite this edit from the radio value.
				if ( ! empty( $existing['quick'] ) ) {
					$this->set_field_normalize_property( $form, $existing['field_id'], '' );
				}
				$rules[ $i ] = $rule;
				$replaced    = true;
				break;
			}
		}
		if ( ! $replaced ) {
			$rules[] = $rule;
		}

		if ( ! $this->save_rules( $form, $rules ) ) {
			wp_send_json_error( array( 'message' => __( 'Save failed.', 'entry-normalizer-for-gravity-forms' ) ) );
		}
		wp_send_json_success( array( 'rules' => $rules ) );
	}

	/**
	 * Delete a rule.
	 */
	public function ajax_delete_rule() {
		$form    = $this->verify_ajax( 'gravityforms_edit_forms' );
		$rule_id = sanitize_key( rgpost( 'rule_id' ) );

		$rules = array();
		foreach ( $this->get_rules( $form ) as $rule ) {
			if ( $rule['id'] !== $rule_id ) {
				$rules[] = $rule;
				continue;
			}
			// Deleting a "quick" rule also turns off the field's Advanced-tab
			// control, so the next form save won't recreate it.
			if ( ! empty( $rule['quick'] ) ) {
				$this->set_field_normalize_property( $form, $rule['field_id'], '' );
			}
		}
		if ( ! $this->save_rules( $form, $rules ) ) {
			wp_send_json_error( array( 'message' => __( 'Delete failed.', 'entry-normalizer-for-gravity-forms' ) ) );
		}
		wp_send_json_success( array( 'rules' => $rules ) );
	}

	/**
	 * Entry value keys a rule applies to. A rule targeting a specific input id
	 * returns that single key; a rule targeting a whole multi-input field (e.g.
	 * "1" for a Name field) expands to all of its visible sub-inputs.
	 *
	 * @param array $form
	 * @param array $rule
	 * @return string[]
	 */
	private function rule_target_keys( $form, $rule ) {
		$field_id = (string) $rule['field_id'];
		foreach ( $form['fields'] as $field ) {
			if ( (string) $field->id !== $field_id ) {
				continue;
			}
			$inputs = is_callable( array( $field, 'get_entry_inputs' ) ) ? $field->get_entry_inputs() : $field->inputs;
			if ( ! is_array( $inputs ) ) {
				break;
			}
			$keys = array();
			foreach ( $inputs as $input ) {
				if ( rgar( $input, 'isHidden' ) ) {
					continue;
				}
				$keys[] = (string) $input['id'];
			}
			return ! empty( $keys ) ? $keys : array( $field_id );
		}
		return array( $field_id );
	}

	/**
	 * Process a batch of existing entries: "preview" mode (no writes) or "apply".
	 */
	public function ajax_process() {
		$mode = 'apply' === rgpost( 'mode' ) ? 'apply' : 'preview';
		$form = $this->verify_ajax( 'apply' === $mode ? 'gravityforms_edit_entries' : 'gravityforms_view_entries' );

		$rule_id = sanitize_key( rgpost( 'rule_id' ) );
		$rule    = null;
		foreach ( $this->get_rules( $form ) as $r ) {
			if ( $r['id'] === $rule_id ) {
				$rule = $r;
				break;
			}
		}
		if ( ! $rule ) {
			wp_send_json_error( array( 'message' => __( 'Rule not found.', 'entry-normalizer-for-gravity-forms' ) ) );
		}

		$page            = absint( rgpost( 'page' ) );
		$search_criteria = array( 'status' => 'active' );
		$total           = GFAPI::count_entries( $form['id'], $search_criteria );
		$paging          = array(
			'offset'    => $page * self::BATCH_SIZE,
			'page_size' => self::BATCH_SIZE,
		);
		$sorting = array(
			'key'       => 'id',
			'direction' => 'ASC',
		);
		$entries = GFAPI::get_entries( $form['id'], $search_criteria, $sorting, $paging );
		if ( is_wp_error( $entries ) ) {
			wp_send_json_error( array( 'message' => $entries->get_error_message() ) );
		}

		// Gravity Forms also applies gform_save_field_value on GFAPI updates
		// (GFFormsModel::update_lead_field_value). Other plugins hooked there
		// (GF Auto Formatter, our own "future submissions" rules…) would then
		// reformat the value at write time, and the database would no longer
		// match the preview. Suspend the whole filter during the bulk apply,
		// then restore it.
		$suppressed_hook = null;
		if ( 'apply' === $mode ) {
			global $wp_filter;
			if ( isset( $wp_filter['gform_save_field_value'] ) ) {
				$suppressed_hook = $wp_filter['gform_save_field_value'];
				unset( $wp_filter['gform_save_field_value'] );
			}
		}

		// Value keys to process. A "quick" rule on a multi-input field (Name,
		// Address…) expands to every sub-input; every other rule targets one key.
		$keys         = $this->rule_target_keys( $form, $rule );
		$chain        = $rule['chain'];
		$is_phone     = in_array( 'phone_fr', $chain, true );
		$processed    = 0;
		$changed      = 0;
		$errors       = 0;
		$samples      = array();
		$unrecognized = array();

		foreach ( $entries as $entry ) {
			$processed++;
			$entry_changed = false;
			foreach ( $keys as $key ) {
				$old = rgar( $entry, $key );
				if ( ! is_string( $old ) || '' === $old ) {
					continue;
				}
				$new = GFEN_Transforms::apply_chain( $chain, $old );

				if ( $is_phone && $new === $old && ! GFEN_Transforms::is_normalized_phone_fr( $old ) ) {
					if ( count( $unrecognized ) < 20 ) {
						$unrecognized[] = array(
							'entry_id' => (int) $entry['id'],
							'value'    => $old,
						);
					}
					continue;
				}

				if ( $new === $old ) {
					continue;
				}

				$entry_changed = true;
				if ( count( $samples ) < 20 ) {
					$samples[] = array(
						'entry_id' => (int) $entry['id'],
						'before'   => $old,
						'after'    => $new,
					);
				}
				if ( 'apply' === $mode ) {
					$result = GFAPI::update_entry_field( $entry['id'], $key, $new );
					if ( is_wp_error( $result ) || false === $result ) {
						$errors++;
					}
				}
			}
			if ( $entry_changed ) {
				$changed++;
			}
		}

		if ( null !== $suppressed_hook ) {
			global $wp_filter;
			$wp_filter['gform_save_field_value'] = $suppressed_hook;
		}

		wp_send_json_success( array(
			'page'         => $page,
			'total'        => (int) $total,
			'processed'    => $processed,
			'changed'      => $changed,
			'errors'       => $errors,
			'samples'      => $samples,
			'unrecognized' => $unrecognized,
			'done'         => ( $paging['offset'] + count( $entries ) ) >= $total || count( $entries ) < self::BATCH_SIZE,
		) );
	}

	/* ---------------------------------------------------------------------
	 * Future submissions
	 * ------------------------------------------------------------------- */

	/**
	 * Apply the rules flagged "future submissions" when the value is saved.
	 *
	 * @param mixed        $value
	 * @param array        $entry
	 * @param GF_Field     $field
	 * @param array        $form
	 * @param string       $input_id
	 * @return mixed
	 */
	public function filter_save_field_value( $value, $entry, $field, $form, $input_id = '' ) {
		if ( ! is_string( $value ) || '' === $value || ! is_object( $field ) || ! is_array( $form ) ) {
			return $value;
		}
		$rules = $this->get_rules( $form );
		if ( empty( $rules ) ) {
			return $value;
		}
		$target   = (string) ( $input_id ? $input_id : $field->id );
		$field_id = (string) $field->id;
		foreach ( $rules as $rule ) {
			if ( empty( $rule['apply_future'] ) ) {
				continue;
			}
			// A rule matches either a specific input id, or the whole field id (in
			// which case it applies to every sub-field of a multi-input field).
			$rid = (string) $rule['field_id'];
			if ( $rid === $target || $rid === $field_id ) {
				$value = GFEN_Transforms::apply_chain( $rule['chain'], $value );
			}
		}
		return $value;
	}
}
