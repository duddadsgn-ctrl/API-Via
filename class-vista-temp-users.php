<?php
/**
 * Temporary users — time-limited accounts bound to specific hidden imóveis.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Vista_Temp_Users {

	const ROLE          = 'vista_temp';
	const META_EXPIRES  = '_vista_temp_expires';
	const META_IMOVEIS  = '_vista_temp_imoveis'; // array of Vista codes

	public function __construct() {
		add_action( 'wp_login', array( $this, 'maybe_block_expired' ), 10, 2 );
	}

	public function create_role() {
		if ( get_role( self::ROLE ) ) {
			return;
		}
		add_role( self::ROLE, __( 'Acesso Temporário (Vista)', 'vista-api' ), array( 'read' => true ) );
	}

	public function create_user( $username, $email, $password, $days, $imovel_codigos ) {
		if ( username_exists( $username ) || email_exists( $email ) ) {
			return new WP_Error( 'vista_user_exists', __( 'Usuário ou e-mail já existe.', 'vista-api' ) );
		}
		$uid = wp_create_user( $username, $password, $email );
		if ( is_wp_error( $uid ) ) {
			return $uid;
		}
		$user = new WP_User( $uid );
		$user->set_role( self::ROLE );

		$expires = time() + max( 1, (int) $days ) * DAY_IN_SECONDS;
		update_user_meta( $uid, self::META_EXPIRES, $expires );
		update_user_meta( $uid, self::META_IMOVEIS, array_map( 'sanitize_text_field', (array) $imovel_codigos ) );

		return $uid;
	}

	public function maybe_block_expired( $user_login, $user ) {
		if ( ! in_array( self::ROLE, (array) $user->roles, true ) ) {
			return;
		}
		$expires = (int) get_user_meta( $user->ID, self::META_EXPIRES, true );
		if ( $expires && time() > $expires ) {
			wp_logout();
			wp_safe_redirect( wp_login_url() . '?vista_expired=1' );
			exit;
		}
	}

	/**
	 * Can the current user see this hidden imóvel?
	 */
	public function current_user_can_view( $post_id ) {
		$user = wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return false;
		}
		if ( user_can( $user, 'edit_posts' ) ) {
			return true;
		}
		if ( ! in_array( self::ROLE, (array) $user->roles, true ) ) {
			return false;
		}
		$expires = (int) get_user_meta( $user->ID, self::META_EXPIRES, true );
		if ( $expires && time() > $expires ) {
			return false;
		}
		$codigos = (array) get_user_meta( $user->ID, self::META_IMOVEIS, true );
		$codigo  = get_post_meta( $post_id, Vista_Importer::META_VISTA_CODE, true );
		return $codigo && in_array( $codigo, $codigos, true );
	}

	public function list_users() {
		return get_users( array( 'role' => self::ROLE, 'number' => 200 ) );
	}

	public function delete_user( $uid ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		return wp_delete_user( (int) $uid );
	}
}
