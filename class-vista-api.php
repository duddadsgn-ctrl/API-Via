<?php
/**
 * Vista REST API client.
 *
 * CRITICAL LEARNING from live testing against cli41034-rest.vistahost.com.br:
 *
 *   Vista's /imoveis/listar endpoint does NOT support the `Foto` nested block.
 *   It responds with:
 *     "A tabela Foto não está disponível para este método."
 *
 *   Photos are ONLY available via /imoveis/detalhes?imovel=CODIGO.
 *
 * So the proper Vista import pattern is:
 *   1. /imoveis/listar  (scalar fields only)            → get Codigos
 *   2. /imoveis/detalhes (with Foto block) for each     → get photos
 *
 * Additionally, each Vista account exposes a different subset of scalar
 * fields and tables. The client parses 400 responses:
 *   - "Campo X não está disponível"  → drops scalar field X
 *   - "A tabela Y não está disponível para este método"
 *       → drops nested table Y (e.g. Foto for the listing endpoint)
 * and caches the learnings in the `vista_api_unavailable_fields` option
 * (scoped per endpoint path).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Vista_API_Client {

	const UNAVAILABLE_OPTION = 'vista_api_unavailable_fields';
	const MAX_RETRIES        = 5;

	/** @var Vista_Logger */
	protected $logger;

	public function __construct( Vista_Logger $logger ) {
		$this->logger = $logger;
	}

	protected function settings() {
		return wp_parse_args( get_option( 'vista_api_settings', array() ), array(
			'api_key'  => '',
			'api_url'  => '',
			'per_page' => 30,
		) );
	}

	protected function base_url() {
		$s = $this->settings();
		return untrailingslashit( $s['api_url'] );
	}

	protected function key() {
		$s = $this->settings();
		return $s['api_key'];
	}

	/**
	 * Scalar fields for /imoveis/listar. NO nested tables here.
	 */
	public function list_fields() {
		return array(
			'Codigo',
			'Finalidade',
			'Categoria',
			'Status',
			'Bairro',
			'Cidade',
			'UF',
			'Dormitorios',
			'Suites',
			'BanheiroSocialQtd',
			'Vagas',
			'AreaTotal',
			'AreaPrivativa',
			'ValorVenda',
			'ValorLocacao',
			'ValorIptu',
			'ValorCondominio',
			'Caracteristicas',
			'InfraEstrutura',
			'Imediacoes',
			'Moeda',
			'CodigoCorretor',
			'Lancamento',
			'Exclusivo',
			'DestaqueWeb',
			'DescricaoWeb',
			'TituloSite',
			'DataHoraAtualizacao',
			'Latitude',
			'Longitude',
			'FotoDestaque',
			'FotoDestaquePequena',
		);
	}

	/**
	 * Fields for /imoveis/detalhes. Includes the Foto nested block — this is
	 * where photos actually come from.
	 * FotoGrande returns the full-resolution URL; Ordem is used for sort order.
	 */
	public function detail_fields() {
		$fields   = $this->list_fields();
		$fields[] = 'DescricaoEmpreendimento';
		$fields[] = array( 'Foto' => array( 'Foto', 'FotoGrande', 'FotoPequena', 'Destaque', 'Ordem' ) );
		$fields[] = array( 'FotoEmpreendimento' => array( 'Foto', 'FotoGrande', 'FotoPequena', 'Destaque', 'Ordem' ) );
		return $fields;
	}

	protected function fields_for_path( $path ) {
		$fields      = ( false !== strpos( $path, '/detalhes' ) ) ? $this->detail_fields() : $this->list_fields();
		$unavailable = $this->get_unavailable_for_path( $path );
		if ( ! empty( $unavailable ) ) {
			$fields = $this->strip_fields( $fields, $unavailable );
		}
		return $fields;
	}

	/**
	 * Remove unavailable fields from a fields array.
	 * Handles both scalar strings and nested arrays like { "Foto": [...] }.
	 */
	protected function strip_fields( array $fields, array $unavailable ) {
		$out = array();
		foreach ( $fields as $f ) {
			if ( is_array( $f ) ) {
				$key = array_keys( $f )[0] ?? null;
				if ( $key && in_array( $key, $unavailable, true ) ) {
					continue;
				}
				$out[] = $f;
				continue;
			}
			if ( in_array( $f, $unavailable, true ) ) {
				continue;
			}
			$out[] = $f;
		}
		return $out;
	}

	/**
	 * GET /imoveis/listar — paginated list of properties.
	 * Scalar fields only; photos come from /imoveis/detalhes.
	 *
	 * @return array|WP_Error  [ 'total' => int, 'imoveis' => array ]
	 */
	public function list_imoveis( $page = 1, $per_page = null, $filter = array() ) {
		$s        = $this->settings();
		$per_page = $per_page ? (int) $per_page : (int) $s['per_page'];

		$response = $this->request_with_retry(
			'/imoveis/listar',
			array(
				'paginacao' => array(
					'pagina'     => max( 1, (int) $page ),
					'quantidade' => max( 1, (int) $per_page ),
				),
			),
			array(
				'key'       => $this->key(),
				'showtotal' => 1,
			),
			$filter
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$total   = isset( $response['total'] ) ? (int) $response['total'] : 0;
		$imoveis = array();
		foreach ( $response as $key => $value ) {
			if ( is_numeric( $key ) ) {
				$imoveis[] = $value;
			}
		}
		return array(
			'total'   => $total,
			'imoveis' => $imoveis,
		);
	}

	/**
	 * GET /imoveis/detalhes — full detail of a single property, including Fotos.
	 */
	public function get_imovel( $codigo ) {
		return $this->request_with_retry(
			'/imoveis/detalhes',
			array(),
			array(
				'key'    => $this->key(),
				'imovel' => $codigo,
			)
		);
	}

	/**
	 * Wrapper that retries on "Campo X" / "tabela Y" errors by learning
	 * which fields to skip (scoped per endpoint path).
	 */
	protected function request_with_retry( $path, $pesquisa_extra, $query_extra, $filter = array() ) {
		$attempts = 0;
		while ( true ) {
			$attempts++;

			$pesquisa = array_merge(
				array( 'fields' => $this->fields_for_path( $path ) ),
				$pesquisa_extra
			);
			if ( ! empty( $filter ) ) {
				$pesquisa['filter'] = $filter;
			}

			$query = array_merge( $query_extra, array( 'pesquisa' => wp_json_encode( $pesquisa ) ) );

			$response = $this->request( $path, $query );

			if ( ! is_wp_error( $response ) ) {
				return $response;
			}

			$bad = $this->extract_unavailable_fields( $response );
			if ( empty( $bad ) || $attempts >= self::MAX_RETRIES ) {
				return $response;
			}

			$this->remember_unavailable_fields( $path, $bad );
			$this->logger->log( sprintf(
				'Vista marcou campos como indisponíveis em %s e tentará de novo: %s',
				$path,
				implode( ', ', $bad )
			), 'warning' );
		}
	}

	/**
	 * Parse 400 body for unavailable-field messages.
	 * Matches both:
	 *   "Campo X não está disponível."
	 *   "A tabela Y não está disponível para este método."
	 */
	protected function extract_unavailable_fields( WP_Error $error ) {
		$data = $error->get_error_data();
		$body = is_array( $data ) && isset( $data['body'] ) ? $data['body'] : '';
		if ( ! $body ) {
			return array();
		}
		$decoded  = json_decode( $body, true );
		$messages = array();
		if ( is_array( $decoded ) && isset( $decoded['message'] ) ) {
			$messages = (array) $decoded['message'];
		} else {
			$messages = array( $body );
		}

		$bad = array();
		foreach ( $messages as $m ) {
			// "Campo X não está disponível"
			if ( preg_match( '/Campo\s+([A-Za-z0-9_]+)\s+n\S+o\s+est\S+\s+dispon/u', $m, $match ) ) {
				$bad[] = $match[1];
				continue;
			}
			// "A tabela Y não está disponível para este método"
			if ( preg_match( '/tabela\s+([A-Za-z0-9_]+)\s+n\S+o\s+est\S+\s+dispon/u', $m, $match ) ) {
				$bad[] = $match[1];
				continue;
			}
		}
		return array_values( array_unique( $bad ) );
	}

	protected function get_all_unavailable() {
		return (array) get_option( self::UNAVAILABLE_OPTION, array() );
	}

	protected function get_unavailable_for_path( $path ) {
		$all = $this->get_all_unavailable();
		$key = $this->path_key( $path );
		return isset( $all[ $key ] ) ? (array) $all[ $key ] : array();
	}

	protected function remember_unavailable_fields( $path, array $fields ) {
		$all = $this->get_all_unavailable();
		$key = $this->path_key( $path );
		$all[ $key ] = array_values( array_unique( array_merge(
			isset( $all[ $key ] ) ? (array) $all[ $key ] : array(),
			$fields
		) ) );
		update_option( self::UNAVAILABLE_OPTION, $all, false );
	}

	protected function path_key( $path ) {
		return trim( $path, '/' );
	}

	public function reset_unavailable_fields() {
		delete_option( self::UNAVAILABLE_OPTION );
	}

	/**
	 * Flat list of learned-unavailable fields for admin display.
	 */
	public function get_unavailable_fields() {
		$all = $this->get_all_unavailable();
		$out = array();
		foreach ( $all as $path => $fields ) {
			foreach ( (array) $fields as $f ) {
				$out[] = $path . ':' . $f;
			}
		}
		return $out;
	}

	protected function request( $path, $args ) {
		$base = $this->base_url();
		if ( ! $base || ! $this->key() ) {
			return new WP_Error( 'vista_not_configured', __( 'API Vista não configurada.', 'vista-api' ) );
		}

		$url      = $base . $path . '?' . http_build_query( $args );
		$response = wp_remote_get( $url, array(
			'timeout' => 45,
			'headers' => array( 'Accept' => 'application/json' ),
		) );

		if ( is_wp_error( $response ) ) {
			$this->logger->log( 'Vista request failed: ' . $response->get_error_message(), 'error' );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 ) {
			$this->logger->log( "Vista HTTP $code on $path: " . substr( $body, 0, 400 ), 'error' );
			return new WP_Error( 'vista_http_error', "Vista HTTP $code", array( 'body' => $body, 'status' => $code ) );
		}

		$data = json_decode( $body, true );
		if ( null === $data ) {
			$this->logger->log( 'Vista JSON decode failed: ' . substr( $body, 0, 400 ), 'error' );
			return new WP_Error( 'vista_json_error', 'JSON inválido da API Vista.' );
		}
		return $data;
	}
}
