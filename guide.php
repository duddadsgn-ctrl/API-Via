<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<div class="vista-guide">
	<h2><?php esc_html_e( 'Guia Rápido do Plugin Vista API Integration', 'vista-api' ); ?></h2>

	<h3><?php esc_html_e( '1. Configuração Inicial', 'vista-api' ); ?></h3>
	<ol>
		<li><?php esc_html_e( 'Vá para a aba Configurações.', 'vista-api' ); ?></li>
		<li><?php esc_html_e( 'Insira sua Chave da API e a URL da API fornecidas pela Vista.', 'vista-api' ); ?></li>
		<li><?php esc_html_e( 'Defina quantos itens por página serão buscados (até 50).', 'vista-api' ); ?></li>
		<li><?php esc_html_e( 'Marque Importação Automática e escolha o intervalo.', 'vista-api' ); ?></li>
		<li><?php esc_html_e( 'Configure a URL de Redirecionamento para imóveis ocultos.', 'vista-api' ); ?></li>
		<li><?php esc_html_e( 'Clique em Salvar Configurações.', 'vista-api' ); ?></li>
	</ol>

	<h3><?php esc_html_e( '2. Importando Imóveis', 'vista-api' ); ?></h3>
	<p><?php esc_html_e( 'Na aba Configurações clique em "Importar Imóveis Agora". As fotos são baixadas e anexadas ao post do imóvel (capa e galeria).', 'vista-api' ); ?></p>

	<h3><?php esc_html_e( '3. Como as fotos aparecem no imóvel', 'vista-api' ); ?></h3>
	<ul>
		<li><?php esc_html_e( 'Capa (imagem destacada): salva em _thumbnail_id (usada pelo Elementor/tema).', 'vista-api' ); ?></li>
		<li><?php esc_html_e( 'Galeria: salva no campo meta "galeria" como array de IDs (compatível com JetEngine Gallery field).', 'vista-api' ); ?></li>
		<li><?php esc_html_e( 'Cada foto fica anexada ao post (post_parent), então aparece na aba "Anexado a" da Biblioteca de Mídia.', 'vista-api' ); ?></li>
	</ul>

	<h3><?php esc_html_e( '4. Gerenciando Imóveis Importados', 'vista-api' ); ?></h3>
	<p><?php esc_html_e( 'Use a aba "Imóveis Importados" para marcar como Oculto, Conceituado ou Lançamento. Estes flags NÃO são sobrescritos pela API.', 'vista-api' ); ?></p>

	<h3><?php esc_html_e( '5. Usuários Temporários', 'vista-api' ); ?></h3>
	<p><?php esc_html_e( 'Crie acessos com prazo para que clientes vejam imóveis ocultos específicos.', 'vista-api' ); ?></p>

	<h3><?php esc_html_e( 'Solução de Problemas', 'vista-api' ); ?></h3>
	<ul>
		<li><strong><?php esc_html_e( 'Imagens aparecem na Biblioteca mas não no imóvel:', 'vista-api' ); ?></strong>
			<?php esc_html_e( 'versão anterior não gravava _thumbnail_id nem o meta de galeria. Esta versão corrige. Rode "Apagar Todos os Imóveis" e depois "Importar Imóveis Agora" para reimportar com os metas corretos.', 'vista-api' ); ?>
		</li>
		<li><strong><?php esc_html_e( 'A API não retorna fotos:', 'vista-api' ); ?></strong>
			<?php esc_html_e( 'o plugin já inclui o campo Foto com subcampos (Foto, FotoPequena, Destaque) no parâmetro pesquisa — se ainda assim vier vazio, verifique se a conta Vista tem permissão para retornar fotos na API REST.', 'vista-api' ); ?>
		</li>
		<li><strong><?php esc_html_e( 'Timeout no download:', 'vista-api' ); ?></strong>
			<?php esc_html_e( 'aumente o limite de execução do PHP (max_execution_time) no TurboCloud ou reduza "Itens por página".', 'vista-api' ); ?>
		</li>
	</ul>
</div>
