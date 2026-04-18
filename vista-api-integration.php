<?php
/**
 * Plugin Name: Vista API Integration
 * Plugin URI:  https://conceitocarioca.com
 * Description: Integra o CRM Vista (vistahost) ao WordPress importando imóveis com fotos anexadas ao post, capa (_thumbnail_id) e galeria em meta compatível com JetEngine/Elementor. Corrige o problema onde as imagens chegavam à Biblioteca de Mídia mas não eram vinculadas ao imóvel.
 * Version:     1.0.0
 * Author:      Conceito Carioca
 * Text Domain: vista-api
 * License:     GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VISTA_API_VERSION', '1.0.0' );
define( 'VISTA_API_FILE', __FILE__ );
define( 'VISTA_API_PATH', plugin_dir_path( __FILE__ ) );
define( 'VISTA_API_URL', plugin_dir_url( __FILE__ ) );
define( 'VISTA_API_CPT', 'imoveis' );

require_once VISTA_API_PATH . 'includes/class-vista-logger.php';
require_once VISTA_API_PATH . 'includes/class-vista-api.php';
require_once VISTA_API_PATH . 'includes/class-vista-importer.php';
require_once VISTA_API_PATH . 'includes/class-vista-cron.php';
require_once VISTA_API_PATH . 'includes/class-vista-temp-users.php';
require_once VISTA_API_PATH . 'includes/class-vista-admin.php';

/**
 * Main plugin bootstrap.
 */
final class Vista_API_Plugin {

	private static $instance = null;

	/** @var Vista_API_Client */
	public $api;

	/** @var Vista_Importer */
	public $importer;

	/** @var Vista_Logger */
	public $logger;

	/** @var Vista_Cron */
	public $cron;

	/** @var Vista_Admin */
	public $admin;

	/** @var Vista_Temp_Users */
	public $temp_users;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->logger     = new Vista_Logger();
		$this->api        = new Vista_API_Client( $this->logger );
		$this->importer   = new Vista_Importer( $this->api, $this->logger );
		$this->cron       = new Vista_Cron( $this->importer );
		$this->temp_users = new Vista_Temp_Users();
		$this->admin      = new Vista_Admin( $this );

		register_activation_hook( VISTA_API_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( VISTA_API_FILE, array( $this, 'deactivate' ) );

		// Define WP_TEMP_DIR early to dodge "Missing a temporary folder" errors
		// on hosts where /tmp is not writable by the WP user.
		add_action( 'plugins_loaded', array( $this, 'maybe_define_temp_dir' ), 1 );

		add_action( 'init', array( $this, 'maybe_register_cpt' ), 5 );
		add_action( 'template_redirect', array( $this, 'maybe_redirect_hidden' ) );
	}

	/**
	 * Ensure wp-content/uploads/vista-tmp/ exists and, if WP_TEMP_DIR is not yet
	 * defined, point WordPress at it. Provides a writable fallback on hosts
	 * whose system temp dir (/tmp) is inaccessible to the PHP user.
	 */
	public function maybe_define_temp_dir() {
		if ( defined( 'WP_TEMP_DIR' ) ) {
			return;
		}
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return;
		}
		$upload = wp_upload_dir();
		if ( empty( $upload['basedir'] ) ) {
			return;
		}
		$tmp = trailingslashit( $upload['basedir'] ) . 'vista-tmp';
		if ( ! file_exists( $tmp ) ) {
			wp_mkdir_p( $tmp );
		}
		if ( is_dir( $tmp ) && wp_is_writable( $tmp ) ) {
			define( 'WP_TEMP_DIR', trailingslashit( $tmp ) );
		}
	}

	public function activate() {
		$this->cron->schedule();
		$this->temp_users->create_role();

		// Pre-create our writable temp dir so the first import never hits the
		// "Missing a temporary folder" error.
		$upload = wp_upload_dir();
		if ( ! empty( $upload['basedir'] ) ) {
			$tmp = trailingslashit( $upload['basedir'] ) . 'vista-tmp';
			if ( ! file_exists( $tmp ) ) {
				wp_mkdir_p( $tmp );
			}
		}

		if ( ! get_option( 'vista_api_settings' ) ) {
			update_option( 'vista_api_settings', array(
				'api_key'          => '',
				'api_url'          => '',
				'per_page'         => 10,
				'auto_import'      => 0,
				'interval'         => 'hourly',
				'hidden_redirect'  => home_url( '/' ),
			) );
		}
	}

	public function deactivate() {
		$this->cron->unschedule();
	}

	/**
	 * Register the `imoveis` CPT only if no other plugin/theme has already done so.
	 * The client site registers this CPT elsewhere (JetEngine / CrocoBlock); we defer
	 * to that registration when present to avoid double registration.
	 * Taxonomies are always registered regardless of CPT origin.
	 */
	public function maybe_register_cpt() {
		if ( ! post_type_exists( VISTA_API_CPT ) ) {
			register_post_type( VISTA_API_CPT, array(
				'label'       => __( 'Imóveis', 'vista-api' ),
				'public'      => true,
				'has_archive' => true,
				'supports'    => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
				'menu_icon'   => 'dashicons-admin-home',
				'rewrite'     => array( 'slug' => 'imoveis' ),
			) );
		}

		$this->register_taxonomies();
	}

	/**
	 * Register all taxonomies used by the Vista importer.
	 *
	 * Always called — even when the CPT is registered by JetEngine — so that
	 * wp_set_object_terms() works and front-end filters have populated terms.
	 */
	private function register_taxonomies() {
		$cpt = VISTA_API_CPT;

		$taxonomies = array(
			'finalidade_imovel' => array(
				'label'        => __( 'Finalidade', 'vista-api' ),
				'rewrite_slug' => 'finalidade-imovel',
			),
			'categoria_imovel' => array(
				'label'        => __( 'Tipo de Imóvel', 'vista-api' ),
				'rewrite_slug' => 'categoria-imovel',
			),
			'status_imovel' => array(
				'label'        => __( 'Status', 'vista-api' ),
				'rewrite_slug' => 'status-imovel',
			),
			'cidade_imovel' => array(
				'label'        => __( 'Cidade', 'vista-api' ),
				'rewrite_slug' => 'cidade-imovel',
			),
			'estado_imovel' => array(
				'label'        => __( 'UF', 'vista-api' ),
				'rewrite_slug' => 'estado-imovel',
			),
			'bairro_imovel' => array(
				'label'        => __( 'Bairro', 'vista-api' ),
				'rewrite_slug' => 'bairro-imovel',
			),
			'dormitorios_imovel' => array(
				'label'        => __( 'Quartos', 'vista-api' ),
				'rewrite_slug' => 'dormitorios-imovel',
			),
			'suites_imovel' => array(
				'label'        => __( 'Suítes', 'vista-api' ),
				'rewrite_slug' => 'suites-imovel',
			),
			'banheiros_imovel' => array(
				'label'        => __( 'Banheiros', 'vista-api' ),
				'rewrite_slug' => 'banheiros-imovel',
			),
			'vagas_imovel' => array(
				'label'        => __( 'Vagas', 'vista-api' ),
				'rewrite_slug' => 'vagas-imovel',
			),
			'caracteristicas_imovel' => array(
				'label'        => __( 'Características', 'vista-api' ),
				'rewrite_slug' => 'caracteristicas-imovel',
			),
			'infraestrutura_imovel' => array(
				'label'        => __( 'Infraestrutura', 'vista-api' ),
				'rewrite_slug' => 'infraestrutura-imovel',
			),
			'imediacoes_imovel' => array(
				'label'        => __( 'Imediações', 'vista-api' ),
				'rewrite_slug' => 'imediacoes-imovel',
			),
			'visibilidade_imovel' => array(
				'label'        => __( 'Visibilidade', 'vista-api' ),
				'rewrite_slug' => 'visibilidade-imovel',
			),
			'destaque_imovel' => array(
				'label'        => __( 'Destaque', 'vista-api' ),
				'rewrite_slug' => 'destaque-imovel',
			),
			'moeda_imovel' => array(
				'label'        => __( 'Moeda', 'vista-api' ),
				'rewrite_slug' => 'moeda-imovel',
			),
			'codigo_corretor_imovel' => array(
				'label'        => __( 'Corretor', 'vista-api' ),
				'rewrite_slug' => 'codigo-corretor-imovel',
			),
		);

		foreach ( $taxonomies as $slug => $config ) {
			if ( taxonomy_exists( $slug ) ) {
				// Already registered by JetEngine or another source — just ensure
				// it is associated with our CPT.
				register_taxonomy_for_object_type( $slug, $cpt );
				continue;
			}
			register_taxonomy( $slug, $cpt, array(
				'label'        => $config['label'],
				'hierarchical' => false,
				'public'       => true,
				'show_in_rest' => true,
				'rewrite'      => array( 'slug' => $config['rewrite_slug'] ),
				'show_admin_column' => false,
			) );
		}
	}

	/**
	 * Redirect front-end visitors away from imoveis flagged as "oculto",
	 * unless they are logged-in as a temporary user with access to that imóvel.
	 */
	public function maybe_redirect_hidden() {
		if ( ! is_singular( VISTA_API_CPT ) ) {
			return;
		}
		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return;
		}
		$is_hidden = (bool) get_post_meta( $post_id, '_vista_hidden', true );
		if ( ! $is_hidden ) {
			return;
		}
		if ( is_user_logged_in() && $this->temp_users->current_user_can_view( $post_id ) ) {
			return;
		}
		$settings = get_option( 'vista_api_settings', array() );
		$target   = ! empty( $settings['hidden_redirect'] ) ? $settings['hidden_redirect'] : home_url( '/' );
		wp_safe_redirect( $target, 302 );
		exit;
	}
}

Vista_API_Plugin::instance();
