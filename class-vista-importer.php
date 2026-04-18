<?php
/**
 * Vista Importer — the core of the fix.
 *
 * Responsibilities:
 *   1. Pull property list from Vista (paginated).
 *   2. Upsert each imóvel as a `imoveis` post.
 *   3. Download each Foto and ATTACH it to the imóvel post:
 *        - wp_insert_attachment with post_parent = $post_id
 *        - dedup by URL hash stored in _vista_source_url
 *        - first "Destaque=Sim" photo becomes _thumbnail_id
 *        - full ordered array of IDs saved as post meta `galeria` (JetEngine-ready)
 *
 * The previous plugin saved images to the Media Library but never set
 * post_parent / _thumbnail_id / galeria — that's why imagens apareciam em Mídia
 * mas nunca na ficha do imóvel.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Vista_Importer {

	const META_SOURCE_URL   = '_vista_source_url';
	const META_SOURCE_HASH  = '_vista_source_hash';
	const META_VISTA_CODE   = '_vista_codigo';
	const META_LAST_SYNC    = '_vista_last_sync';
	const META_DATA_VISTA   = '_vista_data';
	const META_GALLERY      = 'galeria';
	const META_HIDDEN       = '_vista_hidden';
	const META_CONCEITUADO  = '_vista_conceituado';
	const META_LANCAMENTO   = '_vista_lancamento';

	/** @var Vista_API_Client */
	protected $api;

	/** @var Vista_Logger */
	protected $logger;

	public function __construct( Vista_API_Client $api, Vista_Logger $logger ) {
		$this->api    = $api;
		$this->logger = $logger;
	}

	/**
	 * Format a quantity value for taxonomy terms.
	 * Values >= 6 become "6 ou +" (matching the old plugin convention).
	 */
	protected function formatar_quantidade( $value ) {
		$n = (int) $value;
		return $n >= 6 ? '6 ou +' : (string) $n;
	}

	/**
	 * Ensure a plugin-owned writable temp directory exists inside wp-content/uploads.
	 * This avoids the "Missing a temporary folder" error on hosts where
	 * sys_get_temp_dir() / /tmp is not writable by the WP user.
	 * Falls back to WP's default temp dir if uploads is not writable.
	 *
	 * @return string  Directory path WITH trailing slash.
	 */
	protected function ensure_temp_dir() {
		$upload = wp_upload_dir();
		if ( empty( $upload['basedir'] ) ) {
			return trailingslashit( get_temp_dir() );
		}
		$dir = trailingslashit( $upload['basedir'] ) . 'vista-tmp/';
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		// Protect against directory listing & direct access.
		if ( is_dir( $dir ) && ! file_exists( $dir . '.htaccess' ) ) {
			@file_put_contents( $dir . '.htaccess', "Options -Indexes\nDeny from all\n" );
		}
		if ( is_dir( $dir ) && ! file_exists( $dir . 'index.html' ) ) {
			@file_put_contents( $dir . 'index.html', '' );
		}
		return ( is_dir( $dir ) && wp_is_writable( $dir ) )
			? $dir
			: trailingslashit( get_temp_dir() );
	}

	/**
	 * Normalize the Vista `Imediacoes` field into a flat list of region names.
	 *
	 * Vista's `Imediacoes` is a free-text field in the CRM where the corretor types
	 * the region name (e.g. "Serra", "Búzios", "Costa Verde"). The API may return
	 * it in several shapes depending on the account configuration:
	 *   - plain string:            "Serra"
	 *   - comma-separated string:  "Serra, Costa Verde"
	 *   - associative Sim/Nao map: { "Serra": "Sim", "Buzios": "Nao" }
	 *   - indexed array of names:  [ "Serra", "Costa Verde" ]
	 *
	 * @return array  Flat list of region name strings.
	 */
	protected function extract_imediacoes_values( $raw ) {
		if ( is_string( $raw ) ) {
			$parts = array_map( 'trim', explode( ',', $raw ) );
			return array_values( array_filter( $parts, 'strlen' ) );
		}
		if ( is_array( $raw ) ) {
			$out = array();
			foreach ( $raw as $k => $v ) {
				if ( is_string( $k ) && ! is_numeric( $k ) ) {
					// Associative (Sim/Nao map): use key when enabled.
					if ( 'Sim' === $v || true === $v || 1 === (int) $v ) {
						$out[] = trim( (string) $k );
					}
				} elseif ( is_string( $v ) && '' !== trim( $v ) ) {
					// Indexed list of names.
					$out[] = trim( $v );
				}
			}
			return array_values( array_unique( array_filter( $out, 'strlen' ) ) );
		}
		return array();
	}

	/**
	 * Run a full import across all pages.
	 *
	 * @param int $max_pages Safety cap.
	 * @return array stats
	 */
	public function run_full( $max_pages = 100 ) {
		// Extend PHP execution time — shared hosts default to 30-60s which is not
		// enough for large catalogs. @-suppressed because some hosts disallow it.
		@set_time_limit( 0 );
		@ini_set( 'max_execution_time', 0 );

		// Guarantee the plugin temp dir exists before any download attempt.
		$this->ensure_temp_dir();

		$this->logger->log( 'Iniciando importação completa.' );
		$settings = get_option( 'vista_api_settings', array() );
		$per_page = isset( $settings['per_page'] ) ? (int) $settings['per_page'] : 10;

		$stats = array( 'created' => 0, 'updated' => 0, 'photos' => 0, 'skipped' => 0, 'archived' => 0, 'errors' => 0 );

		// Track every Codigo returned by the API so we can reconcile at the end.
		$active_codigos = array();

		// Initialise progress transient so the AJAX progress endpoint can read it.
		set_transient( 'vista_import_progress', array(
			'status'  => 'running',
			'pct'     => 0,
			'message' => __( 'Iniciando...', 'vista-api' ),
		), HOUR_IN_SECONDS );

		$page        = 1;
		$pages_total = 1; // Updated after first request.
		do {
			$result = $this->api->list_imoveis( $page, $per_page );
			if ( is_wp_error( $result ) ) {
				$this->logger->log( 'Erro API página ' . $page . ': ' . $result->get_error_message(), 'error' );
				$stats['errors']++;
				break;
			}
			$imoveis = $result['imoveis'];
			$total   = $result['total'];
			if ( empty( $imoveis ) ) {
				break;
			}

			$pages_total = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;

			foreach ( $imoveis as $imovel ) {
				// The /imoveis/listar endpoint does not return the Foto block.
				// We must call /imoveis/detalhes for each imóvel to get photos.
				$codigo = isset( $imovel['Codigo'] ) ? (string) $imovel['Codigo'] : '';
				if ( '' !== $codigo ) {
					$active_codigos[] = $codigo;
					$detail = $this->api->get_imovel( $codigo );
					if ( is_wp_error( $detail ) ) {
						$this->logger->log( "Erro detalhes $codigo: " . $detail->get_error_message(), 'error' );
					} elseif ( is_array( $detail ) ) {
						// Merge: scalar values from listar + Foto block from detalhes.
						// detalhes takes precedence for fields it returns.
						$imovel = array_merge( $imovel, $detail );
					}
				}

				$r = $this->upsert_imovel( $imovel );
				if ( is_wp_error( $r ) ) {
					$stats['errors']++;
					continue;
				}
				$stats[ $r['action'] ]++;
				$stats['photos'] += $r['photos'];
			}

			$pct = $pages_total > 0 ? (int) round( ( $page / $pages_total ) * 100 ) : 100;
			$this->logger->log( sprintf( 'Página %d/%d processada.', $page, $pages_total ) );
			set_transient( 'vista_import_progress', array(
				'status'  => 'running',
				'pct'     => $pct,
				'message' => sprintf( __( 'Página %d de %d — criados: %d, atualizados: %d, fotos: %d', 'vista-api' ), $page, $pages_total, $stats['created'], $stats['updated'], $stats['photos'] ),
			), HOUR_IN_SECONDS );

			if ( $page >= $pages_total ) {
				break;
			}
			$page++;
		} while ( $page <= $max_pages );

		// Archive WP properties that no longer appear in the API.
		if ( ! empty( $active_codigos ) ) {
			$stats['archived'] = $this->reconciliar_imoveis_inativos( $active_codigos );
		}

		update_option( 'vista_api_last_run', array(
			'time'  => current_time( 'mysql' ),
			'stats' => $stats,
		), false );

		$this->logger->log( 'Importação concluída: ' . wp_json_encode( $stats ) );

		set_transient( 'vista_import_progress', array(
			'status'  => 'done',
			'pct'     => 100,
			'message' => sprintf(
				__( 'Concluído — criados: %d, atualizados: %d, fotos: %d, arquivados: %d, erros: %d', 'vista-api' ),
				$stats['created'], $stats['updated'], $stats['photos'], $stats['archived'], $stats['errors']
			),
			'stats'   => $stats,
		), HOUR_IN_SECONDS );

		return $stats;
	}

	/**
	 * Upsert a single imóvel.
	 *
	 * @return array|WP_Error  [ action => created|updated|skipped, photos => int ]
	 */
	public function upsert_imovel( array $imovel ) {
		$codigo = isset( $imovel['Codigo'] ) ? sanitize_text_field( $imovel['Codigo'] ) : '';
		if ( '' === $codigo ) {
			return new WP_Error( 'vista_no_codigo', 'Imóvel sem Codigo.' );
		}

		$existing = $this->find_post_by_codigo( $codigo );
		$title    = $this->build_title( $imovel );
		$content  = isset( $imovel['DescricaoWeb'] ) ? wp_kses_post( $imovel['DescricaoWeb'] ) : '';
		if ( '' === $content && ! empty( $imovel['DescricaoEmpreendimento'] ) ) {
			$content = wp_kses_post( $imovel['DescricaoEmpreendimento'] );
		}

		$postarr = array(
			'post_type'    => VISTA_API_CPT,
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => $content,
		);

		if ( $existing ) {
			// Skip-update optimisation: if timestamp + prices are unchanged AND core
			// content fields are already populated, skip the heavy re-save.
			$stored_ts = get_post_meta( $existing, 'data_hora_atualizacao', true );
			$api_ts    = isset( $imovel['DataHoraAtualizacao'] ) ? (string) $imovel['DataHoraAtualizacao'] : '';
			$stored_vv = (float) get_post_meta( $existing, 'valor_venda', true );
			$api_vv    = isset( $imovel['ValorVenda'] ) ? (float) $imovel['ValorVenda'] : 0.0;
			$stored_vl = (float) get_post_meta( $existing, 'valor_locacao', true );
			$api_vl    = isset( $imovel['ValorLocacao'] ) ? (float) $imovel['ValorLocacao'] : 0.0;
			if ( $api_ts && $stored_ts === $api_ts && $stored_vv === $api_vv && $stored_vl === $api_vl ) {
				// Don't skip if core content fields are missing — e.g. first import used
				// /listar only (no Características), or code was updated after initial sync.
				$is_content_missing = (
					( '' === (string) get_post_meta( $existing, 'caracteristicas', true ) &&
					  isset( $imovel['Caracteristicas'] ) && ! empty( $imovel['Caracteristicas'] ) )
					||
					( '' === (string) get_post_meta( $existing, 'infraestrutura', true ) &&
					  isset( $imovel['InfraEstrutura'] ) && ! empty( $imovel['InfraEstrutura'] ) )
					||
					( '' === (string) get_post_meta( $existing, 'descricao_completa', true ) &&
					  ! empty( $imovel['DescricaoWeb'] ) )
					||
					( '' === (string) get_post_meta( $existing, 'tipo_negocio', true ) )
					||
					( '' === (string) get_post_meta( $existing, 'codigo_referencia', true ) )
					||
					( '' === (string) get_post_meta( $existing, 'descricao_empreendimento', true ) &&
					  ! empty( $imovel['DescricaoEmpreendimento'] ) )
				);
				if ( ! $is_content_missing ) {
					return array( 'action' => 'skipped', 'photos' => 0 );
				}
			}

			$postarr['ID'] = $existing;
			$post_id       = wp_update_post( $postarr, true );
			$action        = 'updated';
		} else {
			$post_id = wp_insert_post( $postarr, true );
			$action  = 'created';
		}

		if ( is_wp_error( $post_id ) ) {
			$this->logger->log( "Erro ao salvar imóvel $codigo: " . $post_id->get_error_message(), 'error' );
			return $post_id;
		}

		$this->save_meta( $post_id, $imovel );
		$this->assign_taxonomies( $post_id, $imovel );

		$photos_imported = $this->import_photos( $post_id, $imovel );

		update_post_meta( $post_id, self::META_LAST_SYNC, current_time( 'mysql' ) );

		return array( 'action' => $action, 'photos' => $photos_imported );
	}

	protected function build_title( array $imovel ) {
		$cidade = isset( $imovel['Cidade'] ) ? $imovel['Cidade'] : '';
		$bairro = isset( $imovel['Bairro'] ) ? $imovel['Bairro'] : '';
		if ( ! empty( $imovel['TituloSite'] ) ) {
			return sanitize_text_field( $imovel['TituloSite'] );
		}
		if ( $cidade && $bairro ) {
			return sanitize_text_field( "$cidade - $bairro" );
		}
		return sanitize_text_field( $cidade ?: $bairro ?: ( 'Imóvel ' . $imovel['Codigo'] ) );
	}

	protected function find_post_by_codigo( $codigo ) {
		$q = new WP_Query( array(
			'post_type'      => VISTA_API_CPT,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array(
					'key'   => self::META_VISTA_CODE,
					'value' => $codigo,
				),
			),
		) );
		return $q->have_posts() ? (int) $q->posts[0] : 0;
	}

	protected function save_meta( $post_id, array $imovel ) {
		// Save every scalar field that came back — Vista accounts expose different
		// field sets, so we can't hardcode an allowlist. `Foto` is handled separately.
		foreach ( $imovel as $field => $value ) {
			if ( 'Foto' === $field || 'FotoEmpreendimento' === $field ) {
				continue;
			}
			if ( is_scalar( $value ) ) {
				update_post_meta( $post_id, $field, sanitize_text_field( (string) $value ) );
				continue;
			}
			if ( is_array( $value ) && in_array( $field, array( 'Caracteristicas', 'InfraEstrutura', 'Proximidades' ), true ) ) {
				update_post_meta( $post_id, $field, $this->extract_imediacoes_values( $value ) );
				continue;
			}
			if ( is_array( $value ) ) {
				update_post_meta( $post_id, $field, $value );
			}
		}
		// --- Snake_case aliases so JetEngine templates can find values ---
		// IMPORTANT: Vista API field names confirmed against the legacy plugin:
		//   BanheiroSocialQtd (not BanheiroSocial), DataHoraAtualizacao (not DataAtualizacao),
		//   FotoDestaque / FotoDestaquePequena are direct scalar URL fields.
		$field_aliases = array(
			'ValorVenda'          => 'valor_venda',
			'ValorLocacao'        => 'valor_locacao',
			'ValorIptu'           => 'valor_iptu',
			'ValorCondominio'     => 'valor_condominio',
			'AreaTotal'           => 'area_total',
			'AreaPrivativa'       => 'area_privativa',
			'Dormitorios'         => 'dormitorios',
			'Suites'              => 'suites',
			'BanheiroSocialQtd'   => 'banheiros',
			'BanheiroSocial'      => 'banheiros',   // legacy: some accounts use this key
			'Vagas'               => 'vagas',
			'Bairro'              => 'bairro',
			'Cidade'              => 'cidade',
			'UF'                  => 'uf',
			'Categoria'           => 'categoria',
			'Finalidade'          => 'finalidade',
			'TituloSite'          => 'titulo_site',
			'DescricaoWeb'        => 'descricao_completa',
			'Moeda'               => 'moeda',
			'Lancamento'          => 'lancamento',
			'DataHoraAtualizacao' => 'data_hora_atualizacao',
			'DataAtualizacao'     => 'data_hora_atualizacao',  // legacy key fallback
			'Status'              => 'status',
			'Codigo'              => 'codigo',
			'Latitude'            => 'latitude',
			'Longitude'           => 'longitude',
			'CodigoCorretor'      => 'codigo_corretor',
			// Direct CDN photo URLs returned as scalar fields by Vista API.
			'FotoDestaque'           => 'foto_destaque',
			'FotoDestaquePequena'    => 'foto_destaque_pequena',
			// Secondary description for empreendimento/development properties.
			'DescricaoEmpreendimento' => 'descricao_empreendimento',
		);
		foreach ( $field_aliases as $vista_key => $alias ) {
			if ( isset( $imovel[ $vista_key ] ) && is_scalar( $imovel[ $vista_key ] ) ) {
				update_post_meta( $post_id, $alias, sanitize_text_field( (string) $imovel[ $vista_key ] ) );
			}
		}

		// Oculto/exclusivo: Exclusivo === 'Sim' means exclusive/hidden listing.
		$oculto = ( isset( $imovel['Exclusivo'] ) && 'Sim' === (string) $imovel['Exclusivo'] ) ? 1 : 0;
		update_post_meta( $post_id, 'oculto', $oculto );
		if ( $oculto ) {
			update_post_meta( $post_id, self::META_HIDDEN, 1 );
		} else {
			delete_post_meta( $post_id, self::META_HIDDEN );
		}

		// Conceituado: DestaqueWeb === 'Sim'.
		$conceituado = ( isset( $imovel['DestaqueWeb'] ) && 'Sim' === (string) $imovel['DestaqueWeb'] ) ? 1 : 0;
		update_post_meta( $post_id, 'conceituado', $conceituado );
		if ( $conceituado ) {
			update_post_meta( $post_id, self::META_CONCEITUADO, 1 );
		} else {
			delete_post_meta( $post_id, self::META_CONCEITUADO );
		}

		// Lancamento: the raw Vista API field 'Lancamento' returns "Sim"/"Nao".
		// Overwrite the raw string alias set above with a proper 0/1 integer.
		$lancamento = ( isset( $imovel['Lancamento'] ) && 'Sim' === (string) $imovel['Lancamento'] ) ? 1 : 0;
		update_post_meta( $post_id, 'lancamento', $lancamento );
		if ( $lancamento ) {
			update_post_meta( $post_id, self::META_LANCAMENTO, 1 );
		} else {
			delete_post_meta( $post_id, self::META_LANCAMENTO );
		}

		// Also save DescricaoWeb to 'descricao' for backward compatibility.
		if ( isset( $imovel['DescricaoWeb'] ) ) {
			update_post_meta( $post_id, 'descricao', sanitize_text_field( (string) $imovel['DescricaoWeb'] ) );
		}

		// Fallback: if DescricaoWeb is empty, use DescricaoEmpreendimento for the display fields.
		if ( empty( $imovel['DescricaoWeb'] ) && ! empty( $imovel['DescricaoEmpreendimento'] ) ) {
			update_post_meta( $post_id, 'descricao_completa', wp_kses_post( $imovel['DescricaoEmpreendimento'] ) );
			update_post_meta( $post_id, 'descricao', sanitize_text_field( (string) $imovel['DescricaoEmpreendimento'] ) );
		}

		// tipo_negocio: single readable field for templates — Venda / Aluguel / Ambos / Consulta.
		$has_venda   = ! empty( $imovel['ValorVenda'] )   && (float) $imovel['ValorVenda']   > 0;
		$has_locacao = ! empty( $imovel['ValorLocacao'] ) && (float) $imovel['ValorLocacao'] > 0;
		if ( $has_venda && $has_locacao ) {
			$tipo_negocio = 'Ambos';
		} elseif ( $has_venda ) {
			$tipo_negocio = 'Venda';
		} elseif ( $has_locacao ) {
			$tipo_negocio = 'Aluguel';
		} else {
			$tipo_negocio = 'Consulta';
		}
		update_post_meta( $post_id, 'tipo_negocio', $tipo_negocio );

		// Formatted currency values (Brazilian Real: R$ X.XXX,XX).
		$moeda_prefix     = ( ! empty( $imovel['Moeda'] ) ) ? sanitize_text_field( (string) $imovel['Moeda'] ) : 'R$';
		$price_format_map = array(
			'ValorVenda'      => 'valor_venda_formatado',
			'ValorLocacao'    => 'valor_locacao_formatado',
			'ValorIptu'       => 'valor_iptu_formatado',
			'ValorCondominio' => 'valor_condominio_formatado',
		);
		foreach ( $price_format_map as $vista_key => $formatted_key ) {
			$num = isset( $imovel[ $vista_key ] ) ? (float) $imovel[ $vista_key ] : 0;
			update_post_meta( $post_id, $formatted_key, $num > 0 ? $moeda_prefix . ' ' . number_format( $num, 2, ',', '.' ) : '' );
		}

		// mapa field: JetEngine Map widget expects "lat,lng" plain-text coordinates.
		// We always derive this from Latitude/Longitude (there is no 'Mapa' API field).
		if ( ! empty( $imovel['Latitude'] ) && ! empty( $imovel['Longitude'] ) ) {
			$lat = (float) $imovel['Latitude'];
			$lng = (float) $imovel['Longitude'];
			if ( $lat && $lng ) {
				update_post_meta( $post_id, 'mapa', $lat . ',' . $lng );
			}
		}

		// --- Human-readable comma-separated strings for Características / InfraEstrutura ---
		// Vista API returns feature keys WITH SPACES (e.g. 'Aceita Pet', 'Ar Condicionado').
		// The label map below handles known keys; unknowns fall through to the ucwords fallback.
		$labels = array(
			// Unit features
			'Aceita Pet'                    => 'Aceita Pet',
			'Adega'                         => 'Adega',
			'Agua Quente'                   => 'Água Quente',
			'Ar Central'                    => 'Ar Central',
			'Ar Condicionado'               => 'Ar Condicionado',
			'Area Servico'                  => 'Área de Serviço',
			'Armario Embutido'              => 'Armário Embutido',
			'Banheiro Social'               => 'Banheiro Social',
			'Bar'                           => 'Bar',
			'Churrasqueira'                 => 'Churrasqueira',
			'Copa'                          => 'Copa',
			'Copa Cozinha'                  => 'Copa Cozinha',
			'Cozinha'                       => 'Cozinha',
			'Cozinha Americana'             => 'Cozinha Americana',
			'Cozinha Planejada'             => 'Cozinha Planejada',
			'Deck'                          => 'Deck',
			'Dependenciade Empregada'       => 'Dependência de Empregada',
			'Despensa'                      => 'Despensa',
			'Dormitorio Com Armario'        => 'Dormitório com Armário',
			'Edicula'                       => 'Edícula',
			'Escritorio'                    => 'Escritório',
			'Espera Split'                  => 'Espera Split',
			'Estar Intimo'                  => 'Estar Íntimo',
			'Frente Mar'                    => 'Frente Mar',
			'Gradeado'                      => 'Gradeado',
			'Hidromassagem'                 => 'Hidromassagem',
			'Home Theater'                  => 'Home Theater',
			'Jardim Inverno'                => 'Jardim de Inverno',
			'Lareira'                       => 'Lareira',
			'Lavabo'                        => 'Lavabo',
			'Living Hall'                   => 'Living Hall',
			'Mobiliado'                     => 'Mobiliado',
			'Piscina'                       => 'Piscina',
			'Piso Elevado'                  => 'Piso Elevado',
			'Quintal'                       => 'Quintal',
			'Reformado'                     => 'Reformado',
			'Sacada'                        => 'Sacada',
			'Sacada Com Churrasqueira'      => 'Sacada com Churrasqueira',
			'Sala Armarios'                 => 'Sala com Armários',
			'Sala Jantar'                   => 'Sala de Jantar',
			'Sala T V'                      => 'Sala de TV',
			'Sauna'                         => 'Sauna',
			'Split'                         => 'Split',
			'Suite Master'                  => 'Suíte Master',
			'Terraco'                       => 'Terraço',
			'Vista Mar'                     => 'Vista Mar',
			'Vista Panoramica'              => 'Vista Panorâmica',
			'W C Empregada'                 => 'WC Empregada',
			// Building infrastructure
			'Aquecedor Solar'               => 'Aquecedor Solar',
			'Bicicletario'                  => 'Bicicletário',
			'Brinquedoteca'                 => 'Brinquedoteca',
			'Churrasqueira Condominio'      => 'Churrasqueira do Condomínio',
			'Circuito Fechado T V'          => 'Circuito Fechado de TV',
			'Condominio Fechado'            => 'Condomínio Fechado',
			'Deposito'                      => 'Depósito',
			'Elevador'                      => 'Elevador',
			'Elevador Servico'              => 'Elevador de Serviço',
			'Empresa De Monitoramento'      => 'Empresa de Monitoramento',
			'Energia Trifasica'             => 'Energia Trifásica',
			'Entrada Servico Independente'  => 'Entrada de Serviço Independente',
			'Espaco Gourmet'                => 'Espaço Gourmet',
			'Espaco Zen'                    => 'Espaço Zen',
			'Estacionamento'                => 'Estacionamento',
			'Estacionamento Visitantes'     => 'Estacionamento para Visitantes',
			'Gas Central'                   => 'Gás Central',
			'Guarita'                       => 'Guarita',
			'Heliponto'                     => 'Heliponto',
			'Home Market'                   => 'Home Market',
			'Interfone'                     => 'Interfone',
			'Jardim'                        => 'Jardim',
			'Lavanderia'                    => 'Lavanderia',
			'Painel Solar'                  => 'Painel Solar',
			'Parque'                        => 'Parque',
			'Pet Place'                     => 'Pet Place',
			'Pilotis'                       => 'Pilotis',
			'Piscina Aquecida'              => 'Piscina Aquecida',
			'Piscina Coletiva'              => 'Piscina Coletiva',
			'Piscina Infantil'              => 'Piscina Infantil',
			'Playground'                    => 'Playground',
			'Portao Eletronico'             => 'Portão Eletrônico',
			'Portaria'                      => 'Portaria',
			'Portaria24 Hrs'                => 'Portaria 24 Horas',
			'Porteiro Eletronico'           => 'Porteiro Eletrônico',
			'Quadra Esportes'               => 'Quadra de Esportes',
			'Quadra Tenis'                  => 'Quadra de Tênis',
			'Sala De Recepcao'              => 'Sala de Recepção',
			'Sala Fitness'                  => 'Sala Fitness',
			'Salao Festas'                  => 'Salão de Festas',
			'Salao Jogos'                   => 'Salão de Jogos',
			'Sauna Condominio'              => 'Sauna do Condomínio',
			'Seguranca Patrimonial'         => 'Segurança Patrimonial',
			'Spa'                           => 'Spa',
			'Terraco Coletivo'              => 'Terraço Coletivo',
			'Vigilancia24 Horas'            => 'Vigilância 24 Horas',
			'Zelador'                       => 'Zelador',
			'Shaft'                         => 'Shaft',
		);
		// 'Infraestrutura' (capital I) matches the JetEngine field key in the client's settings.
		// Also save to lowercase 'infraestrutura' as a fallback.
		foreach ( array( 'Caracteristicas' => 'caracteristicas', 'InfraEstrutura' => 'Infraestrutura' ) as $api_key => $display_key ) {
			if ( ! isset( $imovel[ $api_key ] ) ) {
				continue;
			}
			$raw_keys = $this->extract_imediacoes_values( $imovel[ $api_key ] );
			$enabled  = array();
			foreach ( $raw_keys as $k ) {
				$enabled[] = isset( $labels[ $k ] ) ? $labels[ $k ] : ucwords( mb_strtolower( (string) $k ) );
			}
			$value = implode( ', ', $enabled );
			update_post_meta( $post_id, $display_key, $value );
			if ( 'Infraestrutura' === $display_key ) {
				update_post_meta( $post_id, 'infraestrutura', $value );
			}
		}

		// Imediacoes: free-text field in Vista CRM — normalised to a flat list here.
		// Save the raw normalised list as a comma-separated string for JetEngine display.
		if ( isset( $imovel['Imediacoes'] ) ) {
			$regions = $this->extract_imediacoes_values( $imovel['Imediacoes'] );
			update_post_meta( $post_id, 'imediacoes', implode( ', ', $regions ) );
		}

		// Código de referência aliases — templates may look for any of these keys.
		$codigo_value = sanitize_text_field( (string) $imovel['Codigo'] );
		foreach ( array( 'codigo_imovel', 'codigo_referencia', 'referencia', 'ref_imovel' ) as $alias ) {
			update_post_meta( $post_id, $alias, $codigo_value );
		}

		update_post_meta( $post_id, self::META_VISTA_CODE, $codigo_value );
		update_post_meta( $post_id, self::META_DATA_VISTA, $imovel );
	}

	/**
	 * THE FIX: import photos and ATTACH them to the imóvel post.
	 *
	 * @return int Number of photos imported on this run (new downloads only).
	 */
	public function import_photos( $post_id, array $imovel ) {
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$fotos = $this->extract_photo_urls( $imovel );

		// Fallback: if the Foto array is empty but FotoDestaque is present as a
		// direct scalar field, use it as the single (destaque) photo.
		if ( empty( $fotos ) && ! empty( $imovel['FotoDestaque'] ) ) {
			$fotos[] = array(
				'url'      => esc_url_raw( $imovel['FotoDestaque'] ),
				'destaque' => true,
			);
		}

		if ( empty( $fotos ) ) {
			return 0;
		}

		$gallery_ids      = array();
		$thumbnail_id     = 0;
		$downloaded_count = 0;

		foreach ( $fotos as $foto ) {
			$url      = $foto['url'];
			$destaque = $foto['destaque'];

			$attachment_id = $this->get_attachment_by_source_url( $url );
			if ( ! $attachment_id ) {
				$attachment_id = $this->sideload_attachment( $url, $post_id );
				if ( is_wp_error( $attachment_id ) ) {
					$this->logger->log( "Falha ao baixar foto $url: " . $attachment_id->get_error_message(), 'error' );
					continue;
				}
				$downloaded_count++;
			} else {
				// Ensure even pre-existing attachments get reparented to this imóvel.
				$current_parent = (int) wp_get_post_parent_id( $attachment_id );
				if ( $current_parent !== (int) $post_id ) {
					wp_update_post( array(
						'ID'          => $attachment_id,
						'post_parent' => (int) $post_id,
					) );
				}
			}

			$gallery_ids[] = (int) $attachment_id;
			if ( $destaque && ! $thumbnail_id ) {
				$thumbnail_id = (int) $attachment_id;
			}
		}

		$gallery_ids = array_values( array_unique( array_filter( $gallery_ids ) ) );

		if ( ! $thumbnail_id && ! empty( $gallery_ids ) ) {
			$thumbnail_id = $gallery_ids[0];
		}
		if ( $thumbnail_id ) {
			set_post_thumbnail( $post_id, $thumbnail_id );
			// Save featured photo URLs so JetEngine Image/URL fields display correctly.
			update_post_meta( $post_id, 'foto_destaque', wp_get_attachment_url( $thumbnail_id ) );
			update_post_meta( $post_id, 'foto_destaque_pequena', wp_get_attachment_image_url( $thumbnail_id, 'medium' ) );
		}

		// Save gallery in multiple formats so whatever front-end reads it works:
		//   - galeria_imagens (array of IDs) — the JetEngine Gallery field key from client settings
		//   - galeria (array of IDs)          — JetEngine gallery field native format (legacy)
		//   - galeria_ids (CSV)               — some templates expect comma-separated IDs
		//   - _vista_galeria (array)           — canonical, namespaced
		update_post_meta( $post_id, 'galeria_imagens', $gallery_ids );
		update_post_meta( $post_id, self::META_GALLERY, $gallery_ids );
		update_post_meta( $post_id, 'galeria_ids', implode( ',', $gallery_ids ) );
		update_post_meta( $post_id, '_vista_galeria', $gallery_ids );

		// Save to every other key a JetEngine Gallery widget might be configured to read from.
		foreach ( array( 'fotos', 'galeria_fotos', 'imagens', 'album' ) as $extra_key ) {
			update_post_meta( $post_id, $extra_key, $gallery_ids );
		}

		return $downloaded_count;
	}

	/**
	 * Normalize Vista's photo payload into [ [url, destaque], ... ] ordered by Ordem (Destaque first).
	 */
	protected function extract_photo_urls( array $imovel ) {
		$fotos = array();
		foreach ( array( 'Foto', 'FotoEmpreendimento' ) as $block ) {
			if ( ! isset( $imovel[ $block ] ) || ! is_array( $imovel[ $block ] ) ) {
				continue;
			}
			foreach ( $imovel[ $block ] as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$url = '';
				foreach ( array( 'FotoGrande', 'Foto', 'URLFoto', 'URL' ) as $k ) {
					if ( ! empty( $item[ $k ] ) ) {
						$url = $item[ $k ];
						break;
					}
				}
				if ( ! $url ) {
					continue;
				}
				$fotos[] = array(
					'url'      => esc_url_raw( $url ),
					'destaque' => ( isset( $item['Destaque'] ) && 'Sim' === $item['Destaque'] ),
				);
			}
		}
		// Sort: destaque first, stable otherwise.
		usort( $fotos, function ( $a, $b ) {
			return ( $b['destaque'] ? 1 : 0 ) - ( $a['destaque'] ? 1 : 0 );
		} );
		return $fotos;
	}

	protected function get_attachment_by_source_url( $url ) {
		$hash = md5( $url );
		$q    = new WP_Query( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array( 'key' => self::META_SOURCE_HASH, 'value' => $hash ),
			),
		) );
		return $q->have_posts() ? (int) $q->posts[0] : 0;
	}

	/**
	 * Download a remote URL to WP uploads and create an attachment ATTACHED to $post_id.
	 *
	 * WHY we avoid download_url() / wp_safe_remote_get():
	 *   WordPress's download_url() internally calls wp_safe_remote_get(), which
	 *   sets reject_unsafe_urls=true and runs wp_http_validate_url(). That function
	 *   resolves the hostname via gethostbyname() and blocks IPs it considers "unsafe".
	 *   On many hosting environments the Vista CDN IP fails this check, returning
	 *   "A valid URL was not provided." even though the URL is perfectly reachable.
	 *   wp_remote_get() does NOT set reject_unsafe_urls by default, so it skips that
	 *   validation and lets the actual HTTP request proceed normally.
	 *
	 * @return int|WP_Error  attachment ID.
	 */
	protected function sideload_attachment( $url, $post_id ) {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// Build a safe temp filename with the original extension.
		$url_path    = wp_parse_url( $url, PHP_URL_PATH );
		$basename    = $url_path ? basename( $url_path ) : '';
		$ext         = pathinfo( $basename, PATHINFO_EXTENSION ) ?: 'jpg';
		// Use our own temp dir inside wp-content/uploads — bypasses hosts
		// where /tmp or sys_get_temp_dir() is not writable.
		$tmp_dir     = $this->ensure_temp_dir();
		$tmpfname    = wp_tempnam( 'vista-img.' . $ext, $tmp_dir );

		// Use wp_remote_get() — bypasses wp_safe_remote_get() and its URL validation.
		$response = wp_remote_get( $url, array(
			'timeout'    => 90,
			'stream'     => true,
			'filename'   => $tmpfname,
			'sslverify'  => false,
			'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
		) );

		if ( is_wp_error( $response ) ) {
			@unlink( $tmpfname );
			$this->logger->log( "wp_remote_get falhou [$url]: " . $response->get_error_message(), 'error' );
			return $response;
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $http_code ) {
			@unlink( $tmpfname );
			return new WP_Error( 'vista_cdn_http_' . $http_code, "CDN retornou HTTP {$http_code} para {$url}" );
		}

		// Verify we actually got file content.
		if ( ! file_exists( $tmpfname ) || 0 === filesize( $tmpfname ) ) {
			@unlink( $tmpfname );
			return new WP_Error( 'vista_empty_file', "Arquivo vazio baixado de {$url}" );
		}

		$filename = $basename ? sanitize_file_name( $basename ) : ( 'vista-' . md5( $url ) . '.' . $ext );
		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmpfname,
		);

		$attachment_id = media_handle_sideload( $file_array, (int) $post_id );
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmpfname );
			$this->logger->log( "media_handle_sideload falhou [$url]: " . $attachment_id->get_error_message(), 'error' );
			return $attachment_id;
		}

		update_post_meta( $attachment_id, self::META_SOURCE_URL, esc_url_raw( $url ) );
		update_post_meta( $attachment_id, self::META_SOURCE_HASH, md5( $url ) );

		return (int) $attachment_id;
	}

	/**
	 * Assign WordPress taxonomy terms from the Vista API data.
	 *
	 * Scalar fields map 1-to-1 to a taxonomy term.
	 * Array fields (Caracteristicas, InfraEstrutura) expand to multiple terms.
	 * Finalidade is derived: Venda when ValorVenda > 0, Locação when ValorLocacao > 0.
	 */
	protected function assign_taxonomies( $post_id, array $imovel ) {
		// Simple scalar 1-to-1 mappings (string values passed as-is).
		$scalar_map = array(
			'Categoria' => 'categoria_imovel',
			'Status'    => 'status_imovel',
			'Bairro'    => 'bairro_imovel',
			'Cidade'    => 'cidade_imovel',
			'UF'        => 'estado_imovel',
		);
		foreach ( $scalar_map as $field => $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) && ! empty( $imovel[ $field ] ) ) {
				wp_set_object_terms( $post_id, sanitize_text_field( (string) $imovel[ $field ] ), $taxonomy );
			}
		}

		// Quantity fields: values >= 6 become "6 ou +" (matches old plugin convention).
		$qty_map = array(
			'Dormitorios' => 'dormitorios_imovel',
			'Suites'      => 'suites_imovel',
			'Vagas'       => 'vagas_imovel',
		);
		foreach ( $qty_map as $field => $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) && isset( $imovel[ $field ] ) && '' !== (string) $imovel[ $field ] ) {
				wp_set_object_terms( $post_id, $this->formatar_quantidade( $imovel[ $field ] ), $taxonomy );
			}
		}

		// Banheiros: try BanheiroSocialQtd first (current API field name), then BanheiroSocial (legacy).
		if ( taxonomy_exists( 'banheiros_imovel' ) ) {
			$banheiros_val = ! empty( $imovel['BanheiroSocialQtd'] ) ? $imovel['BanheiroSocialQtd']
				: ( ! empty( $imovel['BanheiroSocial'] ) ? $imovel['BanheiroSocial'] : '' );
			if ( $banheiros_val ) {
				wp_set_object_terms( $post_id, $this->formatar_quantidade( $banheiros_val ), 'banheiros_imovel' );
			}
		}

		// Finalidade: derive from which price field is populated.
		if ( taxonomy_exists( 'finalidade_imovel' ) ) {
			$finalidades = array();
			if ( ! empty( $imovel['ValorVenda'] ) && (float) $imovel['ValorVenda'] > 0 ) {
				$finalidades[] = 'Venda';
			}
			if ( ! empty( $imovel['ValorLocacao'] ) && (float) $imovel['ValorLocacao'] > 0 ) {
				$finalidades[] = 'Locação';
			}
			if ( ! empty( $imovel['Finalidade'] ) ) {
				// Use API field if present (some accounts expose it directly).
				$finalidades = array( sanitize_text_field( $imovel['Finalidade'] ) );
			}
			if ( $finalidades ) {
				wp_set_object_terms( $post_id, $finalidades, 'finalidade_imovel' );
			}
		}

		// Caracteristicas: array of feature => Sim/Nao.
		if ( taxonomy_exists( 'caracteristicas_imovel' ) && isset( $imovel['Caracteristicas'] ) ) {
			$terms = array_map( 'sanitize_text_field', $this->extract_imediacoes_values( $imovel['Caracteristicas'] ) );
			if ( $terms ) {
				wp_set_object_terms( $post_id, $terms, 'caracteristicas_imovel' );
			}
		}

		// InfraEstrutura: same pattern.
		if ( taxonomy_exists( 'infraestrutura_imovel' ) && isset( $imovel['InfraEstrutura'] ) ) {
			$terms = array_map( 'sanitize_text_field', $this->extract_imediacoes_values( $imovel['InfraEstrutura'] ) );
			if ( $terms ) {
				wp_set_object_terms( $post_id, $terms, 'infraestrutura_imovel' );
			}
		}

		// Imediacoes → imediacoes_imovel taxonomy.
		// The Vista CRM `Imediações` field is free-text (e.g. "Serra", "Búzios", "Costa Verde").
		// We normalise whatever the API returns (string, CSV, array) into a list of terms.
		// Terms are auto-created on first use since the taxonomy is non-hierarchical.
		if ( taxonomy_exists( 'imediacoes_imovel' ) && isset( $imovel['Imediacoes'] ) ) {
			$regions = $this->extract_imediacoes_values( $imovel['Imediacoes'] );
			$terms   = array_map( 'sanitize_text_field', $regions );
			if ( ! empty( $terms ) ) {
				wp_set_object_terms( $post_id, $terms, 'imediacoes_imovel' );
			}
		}

		// Moeda.
		if ( taxonomy_exists( 'moeda_imovel' ) && ! empty( $imovel['Moeda'] ) ) {
			wp_set_object_terms( $post_id, sanitize_text_field( (string) $imovel['Moeda'] ), 'moeda_imovel' );
		}

		// Corretor.
		if ( taxonomy_exists( 'codigo_corretor_imovel' ) && ! empty( $imovel['CodigoCorretor'] ) ) {
			wp_set_object_terms( $post_id, sanitize_text_field( (string) $imovel['CodigoCorretor'] ), 'codigo_corretor_imovel' );
		}

		// Visibilidade: Exclusivo=Sim → Oculto, otherwise Visível.
		if ( taxonomy_exists( 'visibilidade_imovel' ) ) {
			$oculto = ( isset( $imovel['Exclusivo'] ) && 'Sim' === (string) $imovel['Exclusivo'] );
			wp_set_object_terms( $post_id, $oculto ? 'Oculto' : 'Visível', 'visibilidade_imovel' );
		}

		// Destaque: DestaqueWeb=Sim → Conceituado; Lancamento=Sim → Lançamento.
		if ( taxonomy_exists( 'destaque_imovel' ) ) {
			$destaques   = array();
			if ( isset( $imovel['DestaqueWeb'] ) && 'Sim' === (string) $imovel['DestaqueWeb'] ) {
				$destaques[] = 'Conceituado';
			}
			if ( isset( $imovel['Lancamento'] ) && 'Sim' === (string) $imovel['Lancamento'] ) {
				$destaques[] = 'Lançamento';
			}
			if ( $destaques ) {
				wp_set_object_terms( $post_id, $destaques, 'destaque_imovel' );
			}
		}
	}


	/**
	 * Re-assign taxonomy terms for ALL imported imóveis WITHOUT making API calls.
	 *
	 * Uses the `_vista_data` snapshot saved during the last full import. This is
	 * fast (no HTTP) and fixes properties imported before taxonomy code was added.
	 *
	 * @return int Number of properties updated.
	 */
	public function sync_taxonomies_all() {
		@set_time_limit( 0 );
		$q = new WP_Query( array(
			'post_type'      => VISTA_API_CPT,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array( 'key' => self::META_VISTA_CODE, 'compare' => 'EXISTS' ),
			),
		) );

		$count = 0;
		foreach ( $q->posts as $post_id ) {
			$data = get_post_meta( $post_id, self::META_DATA_VISTA, true );
			if ( ! is_array( $data ) || empty( $data ) ) {
				continue;
			}
			$this->save_meta( $post_id, $data );
			$this->assign_taxonomies( $post_id, $data );
			$count++;
		}
		$this->logger->log( "Sincronização de taxonomias concluída: $count imóveis atualizados." );
		return $count;
	}

	/**
	 * Archive (set to draft) WP properties whose Codigo did NOT appear in the
	 * API during the most recent full import or verification pass.
	 *
	 * @param array $active_codigos  All Codigo values returned by the API.
	 * @return int  Number of posts set to draft.
	 */
	public function reconciliar_imoveis_inativos( array $active_codigos ) {
		$q = new WP_Query( array(
			'post_type'      => VISTA_API_CPT,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array( 'key' => self::META_VISTA_CODE, 'compare' => 'EXISTS' ),
			),
		) );
		$archived = 0;
		foreach ( $q->posts as $post_id ) {
			$codigo = (string) get_post_meta( $post_id, self::META_VISTA_CODE, true );
			if ( $codigo && ! in_array( $codigo, $active_codigos, true ) ) {
				wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
				$this->logger->log( "Imóvel $codigo arquivado (não retornou pela API).", 'warning' );
				$archived++;
			}
		}
		if ( $archived ) {
			$this->logger->log( "Reconciliação: $archived imóveis arquivados." );
		}
		return $archived;
	}

	/**
	 * "Verificação Completa" — compare every WP imóvel against the current CRM data
	 * field-by-field. Update any mismatches in-place. Archive posts no longer in CRM.
	 *
	 * @return array stats { verified, updated, archived, errors, mismatches[] }
	 */
	public function verify_all() {
		@set_time_limit( 0 );
		@ini_set( 'max_execution_time', 0 );

		// Guarantee the plugin temp dir exists before any download attempt.
		$this->ensure_temp_dir();

		$settings       = get_option( 'vista_api_settings', array() );
		$per_page       = isset( $settings['per_page'] ) ? (int) $settings['per_page'] : 10;
		$stats          = array( 'verified' => 0, 'updated' => 0, 'archived' => 0, 'errors' => 0, 'mismatches' => array() );
		$active_codigos = array();

		// Fields to compare API value vs. stored WP meta value.
		$compare_fields = array(
			'DataHoraAtualizacao' => 'data_hora_atualizacao',
			'ValorVenda'          => 'valor_venda',
			'ValorLocacao'        => 'valor_locacao',
			'Dormitorios'         => 'dormitorios',
			'Cidade'              => 'cidade',
			'Status'              => 'status',
		);

		$page        = 1;
		$pages_total = 1;
		do {
			$result = $this->api->list_imoveis( $page, $per_page );
			if ( is_wp_error( $result ) ) {
				$this->logger->log( 'Verificação: erro API página ' . $page . ': ' . $result->get_error_message(), 'error' );
				$stats['errors']++;
				break;
			}
			if ( empty( $result['imoveis'] ) ) {
				break;
			}
			$pages_total = $per_page > 0 ? (int) ceil( $result['total'] / $per_page ) : 1;

			foreach ( $result['imoveis'] as $imovel ) {
				$codigo = isset( $imovel['Codigo'] ) ? (string) $imovel['Codigo'] : '';
				if ( ! $codigo ) {
					continue;
				}
				$active_codigos[] = $codigo;

				// Fetch details to get full field set including photos.
				$detail = $this->api->get_imovel( $codigo );
				if ( is_wp_error( $detail ) ) {
					$stats['errors']++;
					continue;
				}
				if ( is_array( $detail ) ) {
					$imovel = array_merge( $imovel, $detail );
				}

				$post_id = $this->find_post_by_codigo( $codigo );
				$stats['verified']++;

				$needs_update = ( ! $post_id ); // New property always needs upsert.
				if ( $post_id ) {
					foreach ( $compare_fields as $api_key => $meta_key ) {
						$api_val = isset( $imovel[ $api_key ] ) ? (string) $imovel[ $api_key ] : '';
						$wp_val  = (string) get_post_meta( $post_id, $meta_key, true );
						if ( $api_val !== $wp_val ) {
							$stats['mismatches'][] = array(
								'codigo' => $codigo,
								'field'  => $meta_key,
								'wp'     => $wp_val,
								'api'    => $api_val,
							);
							$needs_update = true;
						}
					}
					// Also force update if core content fields are empty — catches properties
					// imported before the current code (e.g. /listar only, no Características).
					if ( ! $needs_update ) {
						if (
							( '' === (string) get_post_meta( $post_id, 'caracteristicas', true ) && ! empty( $imovel['Caracteristicas'] ) ) ||
							( '' === (string) get_post_meta( $post_id, 'infraestrutura', true )   && ! empty( $imovel['InfraEstrutura'] ) ) ||
							( '' === (string) get_post_meta( $post_id, 'descricao_completa', true ) && ! empty( $imovel['DescricaoWeb'] ) )
						) {
							$needs_update = true;
						}
					}
				}

				if ( $needs_update ) {
					// Force update by clearing the DataHoraAtualizacao so skip-update won't fire.
					if ( $post_id ) {
						delete_post_meta( $post_id, 'data_hora_atualizacao' );
					}
					$r = $this->upsert_imovel( $imovel );
					if ( ! is_wp_error( $r ) && 'skipped' !== $r['action'] ) {
						$stats['updated']++;
					}
				}
			}
			$page++;
		} while ( $page <= $pages_total );

		// Archive WP properties that no longer appear in the API.
		if ( ! empty( $active_codigos ) ) {
			$stats['archived'] = $this->reconciliar_imoveis_inativos( $active_codigos );
		}

		$this->logger->log( 'Verificação completa concluída: ' . wp_json_encode( array(
			'verified' => $stats['verified'],
			'updated'  => $stats['updated'],
			'archived' => $stats['archived'],
			'errors'   => $stats['errors'],
			'mismatches_count' => count( $stats['mismatches'] ),
		) ) );
		return $stats;
	}

	/**
	 * Delete every imported imóvel (used by "Apagar Todos os Imóveis" button).
	 */
	public function delete_all() {
		$deleted = 0;
		$q = new WP_Query( array(
			'post_type'      => VISTA_API_CPT,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array( 'key' => self::META_VISTA_CODE, 'compare' => 'EXISTS' ),
			),
		) );
		foreach ( $q->posts as $id ) {
			wp_delete_post( $id, true );
			$deleted++;
		}
		$this->logger->log( "Apagados $deleted imóveis importados." );
		return $deleted;
	}
}
