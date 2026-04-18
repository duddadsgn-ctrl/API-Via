<?php
/**
 * Admin UI — mirrors the screens the client already uses:
 *   Vista API › Configurações | Log | Guia Rápido | Imóveis Importados | Usuários Temporários
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Vista_Admin {

	const SLUG = 'vista-api';

	/** @var Vista_API_Plugin */
	protected $plugin;

	public function __construct( Vista_API_Plugin $plugin ) {
		$this->plugin = $plugin;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_post_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'manage_' . VISTA_API_CPT . '_posts_columns', array( $this, 'admin_columns' ) );
		add_action( 'manage_' . VISTA_API_CPT . '_posts_custom_column', array( $this, 'render_column' ), 10, 2 );

		// AJAX: progress polling (logged-in admins only).
		add_action( 'wp_ajax_vista_import_progress', array( $this, 'ajax_import_progress' ) );
	}

	public function register_menu() {
		add_menu_page(
			__( 'Vista API', 'vista-api' ),
			__( 'Vista API', 'vista-api' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render_main_page' ),
			'dashicons-admin-generic',
			58
		);
		add_submenu_page( self::SLUG, __( 'Vista API', 'vista-api' ), __( 'Vista API', 'vista-api' ), 'manage_options', self::SLUG, array( $this, 'render_main_page' ) );
		add_submenu_page( self::SLUG, __( 'Imóveis Importados', 'vista-api' ), __( 'Imóveis Importados', 'vista-api' ), 'manage_options', self::SLUG . '-imoveis', array( $this, 'render_imoveis_page' ) );
		add_submenu_page( self::SLUG, __( 'Usuários Temporários', 'vista-api' ), __( 'Usuários Temporários', 'vista-api' ), 'manage_options', self::SLUG . '-temp', array( $this, 'render_temp_users_page' ) );
	}

	public function register_settings() {
		register_setting( 'vista_api_settings_group', 'vista_api_settings', array(
			'sanitize_callback' => array( $this, 'sanitize_settings' ),
		) );
	}

	public function sanitize_settings( $input ) {
		$out = array(
			'api_key'         => isset( $input['api_key'] ) ? sanitize_text_field( $input['api_key'] ) : '',
			'api_url'         => isset( $input['api_url'] ) ? esc_url_raw( $input['api_url'] ) : '',
			'per_page'        => isset( $input['per_page'] ) ? max( 1, min( 50, (int) $input['per_page'] ) ) : 10,
			'auto_import'     => ! empty( $input['auto_import'] ) ? 1 : 0,
			'interval'        => isset( $input['interval'] ) ? sanitize_text_field( $input['interval'] ) : 'hourly',
			'hidden_redirect' => isset( $input['hidden_redirect'] ) ? esc_url_raw( $input['hidden_redirect'] ) : home_url( '/' ),
		);
		return $out;
	}

	public function handle_post_actions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['vista_action'] ) && check_admin_referer( 'vista_api_action', 'vista_nonce' ) ) {
			$action = sanitize_key( $_POST['vista_action'] );

			if ( 'import_now' === $action ) {
				$stats = $this->plugin->importer->run_full();
				add_settings_error( 'vista_api', 'imported', sprintf(
					__( 'Importação concluída. Criados: %d, atualizados: %d, ignorados: %d, fotos baixadas: %d, arquivados: %d, erros: %d', 'vista-api' ),
					$stats['created'], $stats['updated'], $stats['skipped'], $stats['photos'], $stats['archived'], $stats['errors']
				), 'updated' );
			}

			if ( 'delete_all' === $action ) {
				$n = $this->plugin->importer->delete_all();
				add_settings_error( 'vista_api', 'deleted', sprintf( __( 'Apagados %d imóveis.', 'vista-api' ), $n ), 'updated' );
			}

			if ( 'clear_log' === $action ) {
				$this->plugin->logger->clear();
				add_settings_error( 'vista_api', 'log_cleared', __( 'Log limpo.', 'vista-api' ), 'updated' );
			}

			if ( 'reset_unavailable' === $action ) {
				$this->plugin->api->reset_unavailable_fields();
				add_settings_error( 'vista_api', 'reset_fields', __( 'Cache de campos indisponíveis limpo. Próxima importação vai testar todos os campos de novo.', 'vista-api' ), 'updated' );
			}

			if ( 'sync_taxonomies' === $action ) {
				$n = $this->plugin->importer->sync_taxonomies_all();
				add_settings_error( 'vista_api', 'sync_tax', sprintf(
					__( 'Taxonomias sincronizadas: %d imóveis atualizados com categorias, bairros, cidades e outras classificações.', 'vista-api' ),
					$n
				), 'updated' );
			}

			if ( 'verify_all' === $action ) {
				$stats = $this->plugin->importer->verify_all();
				$msg   = sprintf(
					__( 'Verificação concluída. Verificados: %d, atualizados: %d, arquivados: %d, erros: %d.', 'vista-api' ),
					$stats['verified'], $stats['updated'], $stats['archived'], $stats['errors']
				);
				if ( ! empty( $stats['mismatches'] ) ) {
					$lines = array();
					foreach ( array_slice( $stats['mismatches'], 0, 20 ) as $m ) {
						$lines[] = sprintf( 'Cód.%s — %s: "%s" → "%s"', $m['codigo'], $m['field'], $m['wp'], $m['api'] );
					}
					$msg .= ' | Diferenças: ' . implode( '; ', $lines );
					if ( count( $stats['mismatches'] ) > 20 ) {
						$msg .= sprintf( ' ... e mais %d', count( $stats['mismatches'] ) - 20 );
					}
				}
				add_settings_error( 'vista_api', 'verify_done', $msg, 'updated' );
			}

			if ( 'create_temp_user' === $action ) {
				$result = $this->plugin->temp_users->create_user(
					isset( $_POST['username'] ) ? sanitize_user( $_POST['username'] ) : '',
					isset( $_POST['email'] ) ? sanitize_email( $_POST['email'] ) : '',
					isset( $_POST['password'] ) ? (string) $_POST['password'] : wp_generate_password( 12 ),
					isset( $_POST['days'] ) ? (int) $_POST['days'] : 7,
					isset( $_POST['imoveis'] ) ? array_filter( array_map( 'trim', explode( ',', (string) $_POST['imoveis'] ) ) ) : array()
				);
				if ( is_wp_error( $result ) ) {
					add_settings_error( 'vista_api', 'temp_err', $result->get_error_message() );
				} else {
					add_settings_error( 'vista_api', 'temp_ok', __( 'Usuário temporário criado.', 'vista-api' ), 'updated' );
				}
			}

			if ( 'delete_temp_user' === $action && isset( $_POST['user_id'] ) ) {
				$this->plugin->temp_users->delete_user( (int) $_POST['user_id'] );
				add_settings_error( 'vista_api', 'temp_del', __( 'Usuário removido.', 'vista-api' ), 'updated' );
			}

			if ( 'bulk_imoveis' === $action && ! empty( $_POST['imovel_ids'] ) ) {
				$ids    = array_map( 'intval', (array) $_POST['imovel_ids'] );
				$bulk   = sanitize_key( $_POST['bulk_action'] ?? '' );
				$counts = 0;
				foreach ( $ids as $id ) {
					switch ( $bulk ) {
						case 'mark_hidden':
							update_post_meta( $id, Vista_Importer::META_HIDDEN, 1 );
							$counts++;
							break;
						case 'unmark_hidden':
							delete_post_meta( $id, Vista_Importer::META_HIDDEN );
							$counts++;
							break;
						case 'mark_conceituado':
							update_post_meta( $id, Vista_Importer::META_CONCEITUADO, 1 );
							$counts++;
							break;
						case 'unmark_conceituado':
							delete_post_meta( $id, Vista_Importer::META_CONCEITUADO );
							$counts++;
							break;
						case 'mark_lancamento':
							update_post_meta( $id, Vista_Importer::META_LANCAMENTO, 1 );
							$counts++;
							break;
						case 'unmark_lancamento':
							delete_post_meta( $id, Vista_Importer::META_LANCAMENTO );
							$counts++;
							break;
						case 'delete':
							wp_delete_post( $id, true );
							$counts++;
							break;
					}
				}
				add_settings_error( 'vista_api', 'bulk_done', sprintf( __( '%d imóveis processados.', 'vista-api' ), $counts ), 'updated' );
			}
		}
	}

	public function enqueue_assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'vista-api-admin', VISTA_API_URL . 'assets/admin.css', array(), VISTA_API_VERSION );

		wp_enqueue_script( 'vista-api-admin', VISTA_API_URL . 'assets/admin.js', array( 'jquery' ), VISTA_API_VERSION, true );
		wp_localize_script( 'vista-api-admin', 'vistaAdmin', array(
			'ajaxurl'       => admin_url( 'admin-ajax.php' ),
			'nonce_progress' => wp_create_nonce( 'vista_import_progress' ),
			'i18n'          => array(
				'importing'  => __( 'Importando...', 'vista-api' ),
				'done'       => __( 'Concluído!', 'vista-api' ),
				'error'      => __( 'Erro ao verificar progresso.', 'vista-api' ),
			),
		) );
	}

	/**
	 * AJAX: return current import progress from transient.
	 * Used by admin.js to update the progress bar without reloading.
	 */
	public function ajax_import_progress() {
		check_ajax_referer( 'vista_import_progress', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}
		$progress = get_transient( 'vista_import_progress' );
		if ( false === $progress ) {
			$progress = array( 'status' => 'idle', 'pct' => 0, 'message' => '' );
		}
		wp_send_json_success( $progress );
	}

	public function render_main_page() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings';
		settings_errors( 'vista_api' );
		?>
		<div class="wrap vista-api-wrap">
			<h1><?php esc_html_e( 'Vista API', 'vista-api' ); ?></h1>
			<h2 class="nav-tab-wrapper">
				<?php foreach ( array(
					'settings' => __( 'Configurações', 'vista-api' ),
					'log'      => __( 'Log', 'vista-api' ),
					'guide'    => __( 'Guia Rápido', 'vista-api' ),
				) as $slug => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $slug ) ); ?>" class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</h2>
			<?php
			switch ( $tab ) {
				case 'log':
					$this->render_log_tab();
					break;
				case 'guide':
					$this->render_guide_tab();
					break;
				default:
					$this->render_settings_tab();
			}
			?>
		</div>
		<?php
	}

	protected function render_settings_tab() {
		$settings = wp_parse_args( get_option( 'vista_api_settings', array() ), array(
			'api_key' => '', 'api_url' => '', 'per_page' => 10, 'auto_import' => 0, 'interval' => 'hourly', 'hidden_redirect' => home_url( '/' ),
		) );
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'vista_api_settings_group' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="api_key"><?php esc_html_e( 'Chave da API', 'vista-api' ); ?></label></th>
					<td><input type="text" id="api_key" name="vista_api_settings[api_key]" value="<?php echo esc_attr( $settings['api_key'] ); ?>" class="regular-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="api_url"><?php esc_html_e( 'URL da API', 'vista-api' ); ?></label></th>
					<td><input type="url" id="api_url" name="vista_api_settings[api_url]" value="<?php echo esc_attr( $settings['api_url'] ); ?>" class="regular-text" placeholder="https://cli41034-rest.vistahost.com.br" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="per_page"><?php esc_html_e( 'Itens por página', 'vista-api' ); ?></label></th>
					<td><input type="number" id="per_page" name="vista_api_settings[per_page]" value="<?php echo esc_attr( (int) $settings['per_page'] ); ?>" min="1" max="50" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Importação Automática', 'vista-api' ); ?></th>
					<td><label><input type="checkbox" name="vista_api_settings[auto_import]" value="1" <?php checked( $settings['auto_import'], 1 ); ?> /> <?php esc_html_e( 'Buscar novos imóveis e atualizações automaticamente', 'vista-api' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="interval"><?php esc_html_e( 'Intervalo de Importação', 'vista-api' ); ?></label></th>
					<td>
						<select id="interval" name="vista_api_settings[interval]">
							<?php foreach ( array(
								'hourly'  => __( 'Hora em Hora', 'vista-api' ),
								'daily'   => __( 'Diariamente', 'vista-api' ),
								'weekly'  => __( 'Semanalmente', 'vista-api' ),
								'monthly' => __( 'Mensalmente', 'vista-api' ),
							) as $k => $label ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $settings['interval'], $k ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="hidden_redirect"><?php esc_html_e( 'URL de Redirecionamento (Imóveis Ocultos)', 'vista-api' ); ?></label></th>
					<td><input type="url" id="hidden_redirect" name="vista_api_settings[hidden_redirect]" value="<?php echo esc_attr( $settings['hidden_redirect'] ); ?>" class="regular-text" /></td>
				</tr>
			</table>
			<?php submit_button( __( 'Salvar Configurações', 'vista-api' ) ); ?>
		</form>

		<form method="post" style="display:inline-block;margin-right:8px;" id="vista-import-form">
			<?php wp_nonce_field( 'vista_api_action', 'vista_nonce' ); ?>
			<input type="hidden" name="vista_action" value="import_now" />
			<button type="submit" id="vista-import-btn" class="button button-primary"><?php esc_html_e( 'Importar Imóveis Agora', 'vista-api' ); ?></button>
		</form>

		<div id="vista-progress-wrap" style="display:none;margin-top:12px;">
			<div style="background:#e0e0e0;border-radius:3px;height:20px;width:100%;max-width:500px;">
				<div id="vista-progress-bar" style="background:#2271b1;height:20px;border-radius:3px;width:0%;transition:width .3s;"></div>
			</div>
			<p id="vista-progress-msg" style="margin:4px 0 0;"></p>
		</div>

		<form method="post" style="display:inline-block;margin-right:8px;">
			<?php wp_nonce_field( 'vista_api_action', 'vista_nonce' ); ?>
			<input type="hidden" name="vista_action" value="sync_taxonomies" />
			<button type="submit" class="button button-secondary"><?php esc_html_e( 'Sincronizar Taxonomias (Rápido)', 'vista-api' ); ?></button>
		</form>

		<form method="post" style="display:inline-block;margin-right:8px;" id="vista-verify-form">
			<?php wp_nonce_field( 'vista_api_action', 'vista_nonce' ); ?>
			<input type="hidden" name="vista_action" value="verify_all" />
			<button type="submit" class="button button-secondary"><?php esc_html_e( 'Verificação Completa', 'vista-api' ); ?></button>
		</form>

		<form method="post" style="display:inline-block;" onsubmit="return confirm('<?php echo esc_js( __( 'Tem certeza? Isso vai apagar todos os imóveis importados.', 'vista-api' ) ); ?>');">
			<?php wp_nonce_field( 'vista_api_action', 'vista_nonce' ); ?>
			<input type="hidden" name="vista_action" value="delete_all" />
			<button type="submit" class="button"><?php esc_html_e( 'Apagar Todos os Imóveis', 'vista-api' ); ?></button>
		</form>

		<p style="margin-top:8px;color:#666;font-size:12px;">
			<?php esc_html_e( '→ "Importar Imóveis Agora": baixa fotos e cria/atualiza tudo (lento). "Sincronizar Taxonomias": re-aplica categorias/bairros/cidades usando dados já salvos (rápido, sem API). "Verificação Completa": consulta o CRM campo a campo, corrige diferenças, arquiva imóveis removidos do CRM (lento — faz uma chamada de API por imóvel).', 'vista-api' ); ?>
		</p>

		<?php
		$unavailable = $this->plugin->api->get_unavailable_fields();
		if ( ! empty( $unavailable ) ) :
		?>
			<div class="notice notice-info" style="margin-top:16px;">
				<p>
					<strong><?php esc_html_e( 'Campos ignorados para esta conta Vista:', 'vista-api' ); ?></strong>
					<code><?php echo esc_html( implode( ', ', $unavailable ) ); ?></code>
				</p>
				<p><?php esc_html_e( 'O plugin detectou automaticamente que estes campos não estão disponíveis na sua conta Vista e parou de pedir. Isso é esperado — cada conta Vista expõe um subset diferente de campos. Se você habilitou esses campos no painel Vista, clique abaixo para limpar o cache.', 'vista-api' ); ?></p>
				<form method="post" style="margin:0;">
					<?php wp_nonce_field( 'vista_api_action', 'vista_nonce' ); ?>
					<input type="hidden" name="vista_action" value="reset_unavailable" />
					<button type="submit" class="button"><?php esc_html_e( 'Redetectar Campos Disponíveis', 'vista-api' ); ?></button>
				</form>
			</div>
		<?php endif; ?>
		<?php
	}

	protected function render_log_tab() {
		$lines = $this->plugin->logger->get_lines();
		?>
		<form method="post" style="margin:16px 0;">
			<?php wp_nonce_field( 'vista_api_action', 'vista_nonce' ); ?>
			<input type="hidden" name="vista_action" value="clear_log" />
			<button type="submit" class="button"><?php esc_html_e( 'Limpar Log', 'vista-api' ); ?></button>
		</form>
		<pre class="vista-log"><?php echo esc_html( implode( "\n", array_reverse( $lines ) ) ); ?></pre>
		<?php
	}

	protected function render_guide_tab() {
		include VISTA_API_PATH . 'templates/guide.php';
	}

	public function render_imoveis_page() {
		settings_errors( 'vista_api' );

		$vis      = isset( $_GET['vis'] ) ? sanitize_key( $_GET['vis'] ) : 'all';
		$dest     = isset( $_GET['dest'] ) ? sanitize_key( $_GET['dest'] ) : 'all';
		$paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );

		$meta_query = array( array( 'key' => Vista_Importer::META_VISTA_CODE, 'compare' => 'EXISTS' ) );
		if ( 'visible' === $vis ) {
			$meta_query[] = array( 'key' => Vista_Importer::META_HIDDEN, 'compare' => 'NOT EXISTS' );
		} elseif ( 'hidden' === $vis ) {
			$meta_query[] = array( 'key' => Vista_Importer::META_HIDDEN, 'value' => 1 );
		}
		if ( 'conceituado' === $dest ) {
			$meta_query[] = array( 'key' => Vista_Importer::META_CONCEITUADO, 'value' => 1 );
		} elseif ( 'lancamento' === $dest ) {
			$meta_query[] = array( 'key' => Vista_Importer::META_LANCAMENTO, 'value' => 1 );
		}

		$q = new WP_Query( array(
			'post_type'      => VISTA_API_CPT,
			'post_status'    => 'any',
			'posts_per_page' => 20,
			'paged'          => $paged,
			'meta_query'     => $meta_query,
		) );

		$total = (int) $q->found_posts;
		?>
		<div class="wrap">
			<h1><?php printf( esc_html__( 'Imóveis Importados (%d)', 'vista-api' ), $total ); ?></h1>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG . '-imoveis' ); ?>" />
				<label><?php esc_html_e( 'Filtrar por visibilidade:', 'vista-api' ); ?>
					<select name="vis">
						<option value="all" <?php selected( $vis, 'all' ); ?>><?php esc_html_e( 'Todos', 'vista-api' ); ?></option>
						<option value="visible" <?php selected( $vis, 'visible' ); ?>><?php esc_html_e( 'Visíveis', 'vista-api' ); ?></option>
						<option value="hidden" <?php selected( $vis, 'hidden' ); ?>><?php esc_html_e( 'Ocultos', 'vista-api' ); ?></option>
					</select>
				</label>
				<label><?php esc_html_e( 'Filtrar por destaque:', 'vista-api' ); ?>
					<select name="dest">
						<option value="all" <?php selected( $dest, 'all' ); ?>><?php esc_html_e( 'Todos', 'vista-api' ); ?></option>
						<option value="conceituado" <?php selected( $dest, 'conceituado' ); ?>><?php esc_html_e( 'Conceituados', 'vista-api' ); ?></option>
						<option value="lancamento" <?php selected( $dest, 'lancamento' ); ?>><?php esc_html_e( 'Lançamentos', 'vista-api' ); ?></option>
					</select>
				</label>
				<button type="submit" class="button"><?php esc_html_e( 'Filtrar', 'vista-api' ); ?></button>
			</form>

			<form method="post">
				<?php wp_nonce_field( 'vista_api_action', 'vista_nonce' ); ?>
				<input type="hidden" name="vista_action" value="bulk_imoveis" />
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<td class="check-column"><input type="checkbox" onclick="document.querySelectorAll('input[name=\'imovel_ids[]\']').forEach(c=>c.checked=this.checked);" /></td>
							<th><?php esc_html_e( 'Título', 'vista-api' ); ?></th>
							<th><?php esc_html_e( 'Código', 'vista-api' ); ?></th>
							<th><?php esc_html_e( 'Capa', 'vista-api' ); ?></th>
							<th><?php esc_html_e( 'Fotos', 'vista-api' ); ?></th>
							<th><?php esc_html_e( 'Oculto', 'vista-api' ); ?></th>
							<th><?php esc_html_e( 'Conceituado', 'vista-api' ); ?></th>
							<th><?php esc_html_e( 'Lançamento', 'vista-api' ); ?></th>
							<th><?php esc_html_e( 'Ações', 'vista-api' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( ! $q->have_posts() ) : ?>
							<tr><td colspan="9"><?php esc_html_e( 'Nenhum imóvel encontrado.', 'vista-api' ); ?></td></tr>
						<?php endif; ?>
						<?php while ( $q->have_posts() ) : $q->the_post();
							$id     = get_the_ID();
							$codigo = get_post_meta( $id, Vista_Importer::META_VISTA_CODE, true );
							$gal    = (array) get_post_meta( $id, Vista_Importer::META_GALLERY, true );
							$hidden = (bool) get_post_meta( $id, Vista_Importer::META_HIDDEN, true );
							$conc   = (bool) get_post_meta( $id, Vista_Importer::META_CONCEITUADO, true );
							$lan    = (bool) get_post_meta( $id, Vista_Importer::META_LANCAMENTO, true );
							$thumb  = get_the_post_thumbnail_url( $id, 'thumbnail' );
						?>
							<tr>
								<th class="check-column"><input type="checkbox" name="imovel_ids[]" value="<?php echo esc_attr( $id ); ?>" /></th>
								<td><strong><?php the_title(); ?></strong></td>
								<td><?php echo esc_html( $codigo ); ?></td>
								<td><?php echo $thumb ? '<img src="' . esc_url( $thumb ) . '" style="width:40px;height:40px;object-fit:cover;"/>' : '—'; ?></td>
								<td><?php echo (int) count( $gal ); ?></td>
								<td><?php echo $hidden ? __( 'Sim', 'vista-api' ) : __( 'Não', 'vista-api' ); ?></td>
								<td><?php echo $conc ? __( 'Sim', 'vista-api' ) : __( 'Não', 'vista-api' ); ?></td>
								<td><?php echo $lan ? __( 'Sim', 'vista-api' ) : __( 'Não', 'vista-api' ); ?></td>
								<td>
									<a class="button button-small" href="<?php the_permalink(); ?>" target="_blank"><?php esc_html_e( 'Visualizar', 'vista-api' ); ?></a>
									<a class="button button-small" href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>"><?php esc_html_e( 'Editar', 'vista-api' ); ?></a>
								</td>
							</tr>
						<?php endwhile; wp_reset_postdata(); ?>
					</tbody>
				</table>

				<div style="margin-top:12px;">
					<select name="bulk_action">
						<option value=""><?php esc_html_e( 'Ações em massa', 'vista-api' ); ?></option>
						<option value="mark_hidden"><?php esc_html_e( 'Marcar como Oculto', 'vista-api' ); ?></option>
						<option value="unmark_hidden"><?php esc_html_e( 'Marcar como Visível', 'vista-api' ); ?></option>
						<option value="mark_conceituado"><?php esc_html_e( 'Marcar como Conceituado', 'vista-api' ); ?></option>
						<option value="unmark_conceituado"><?php esc_html_e( 'Desmarcar Conceituado', 'vista-api' ); ?></option>
						<option value="mark_lancamento"><?php esc_html_e( 'Marcar como Lançamento', 'vista-api' ); ?></option>
						<option value="unmark_lancamento"><?php esc_html_e( 'Desmarcar Lançamento', 'vista-api' ); ?></option>
						<option value="delete"><?php esc_html_e( 'Apagar', 'vista-api' ); ?></option>
					</select>
					<button type="submit" class="button"><?php esc_html_e( 'Aplicar', 'vista-api' ); ?></button>
				</div>
			</form>

			<?php
			$pages = max( 1, (int) ceil( $total / 20 ) );
			if ( $pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">';
				echo paginate_links( array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => $paged,
					'total'   => $pages,
				) );
				echo '</div></div>';
			}
			?>
		</div>
		<?php
	}

	public function render_temp_users_page() {
		settings_errors( 'vista_api' );
		$users = $this->plugin->temp_users->list_users();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Usuários Temporários', 'vista-api' ); ?></h1>
			<h2><?php esc_html_e( 'Criar Novo Usuário Temporário', 'vista-api' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'vista_api_action', 'vista_nonce' ); ?>
				<input type="hidden" name="vista_action" value="create_temp_user" />
				<table class="form-table">
					<tr><th><label><?php esc_html_e( 'Usuário', 'vista-api' ); ?></label></th><td><input type="text" name="username" class="regular-text" required /></td></tr>
					<tr><th><label><?php esc_html_e( 'E-mail', 'vista-api' ); ?></label></th><td><input type="email" name="email" class="regular-text" required /></td></tr>
					<tr><th><label><?php esc_html_e( 'Senha', 'vista-api' ); ?></label></th><td><input type="text" name="password" class="regular-text" required /></td></tr>
					<tr><th><label><?php esc_html_e( 'Válido por (dias)', 'vista-api' ); ?></label></th><td><input type="number" name="days" value="7" min="1" /></td></tr>
					<tr><th><label><?php esc_html_e( 'Códigos de imóveis (separados por vírgula)', 'vista-api' ); ?></label></th><td><input type="text" name="imoveis" class="regular-text" placeholder="1667, 1664" /></td></tr>
				</table>
				<?php submit_button( __( 'Criar Usuário', 'vista-api' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Usuários Ativos', 'vista-api' ); ?></h2>
			<table class="wp-list-table widefat fixed striped">
				<thead><tr>
					<th><?php esc_html_e( 'Usuário', 'vista-api' ); ?></th>
					<th><?php esc_html_e( 'E-mail', 'vista-api' ); ?></th>
					<th><?php esc_html_e( 'Expira em', 'vista-api' ); ?></th>
					<th><?php esc_html_e( 'Imóveis', 'vista-api' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
				<?php if ( empty( $users ) ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'Nenhum usuário temporário.', 'vista-api' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $users as $u ) :
					$exp = (int) get_user_meta( $u->ID, Vista_Temp_Users::META_EXPIRES, true );
					$ims = (array) get_user_meta( $u->ID, Vista_Temp_Users::META_IMOVEIS, true );
				?>
					<tr>
						<td><?php echo esc_html( $u->user_login ); ?></td>
						<td><?php echo esc_html( $u->user_email ); ?></td>
						<td><?php echo $exp ? esc_html( wp_date( 'd/m/Y H:i', $exp ) ) : '—'; ?></td>
						<td><?php echo esc_html( implode( ', ', $ims ) ); ?></td>
						<td>
							<form method="post" style="display:inline;">
								<?php wp_nonce_field( 'vista_api_action', 'vista_nonce' ); ?>
								<input type="hidden" name="vista_action" value="delete_temp_user" />
								<input type="hidden" name="user_id" value="<?php echo esc_attr( $u->ID ); ?>" />
								<button type="submit" class="button button-small"><?php esc_html_e( 'Remover', 'vista-api' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public function admin_columns( $cols ) {
		$new = array();
		foreach ( $cols as $k => $v ) {
			$new[ $k ] = $v;
			if ( 'title' === $k ) {
				$new['vista_thumb']  = __( 'Capa', 'vista-api' );
				$new['vista_codigo'] = __( 'Código', 'vista-api' );
				$new['vista_fotos']  = __( 'Fotos', 'vista-api' );
			}
		}
		return $new;
	}

	public function render_column( $col, $post_id ) {
		if ( 'vista_thumb' === $col ) {
			$thumb = get_the_post_thumbnail( $post_id, array( 60, 60 ) );
			echo $thumb ?: '—';
		} elseif ( 'vista_codigo' === $col ) {
			echo esc_html( (string) get_post_meta( $post_id, Vista_Importer::META_VISTA_CODE, true ) );
		} elseif ( 'vista_fotos' === $col ) {
			$gal = (array) get_post_meta( $post_id, Vista_Importer::META_GALLERY, true );
			echo (int) count( $gal );
		}
	}
}
