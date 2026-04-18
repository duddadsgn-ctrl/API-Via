# Vista API Integration — WordPress plugin

Integração do CRM **Vista (vistahost)** com WordPress para o site
[conceitocarioca.com](https://conceitocarioca.com). Importa imóveis do endpoint
REST Vista e publica como posts do CPT `imoveis`, com fotos anexadas.

## O que este plugin resolve

Diagnóstico do problema reportado (imagens na Biblioteca de Mídia, mas ausentes
nos imóveis):

1. **A API Vista só retorna `Foto` se você pedir explicitamente.** O campo precisa
   ir no `pesquisa.fields` como objeto aninhado:
   `{ "Foto": ["Foto","FotoPequena","Destaque"] }`. Este plugin já envia assim —
   veja `Vista_API_Client::default_fields()`.

2. **As fotos precisam ser vinculadas ao post.** Ao fazer `media_handle_sideload`
   o plugin passa `post_parent = $post_id`, seta `_thumbnail_id` (capa) e grava
   a galeria no meta `galeria` (array de IDs — formato JetEngine). Veja
   `Vista_Importer::import_photos()`.

3. **Deduplicação:** cada attachment guarda `_vista_source_url` e
   `_vista_source_hash`, então reimportar não gera duplicatas nem re-baixa fotos.

## Instalação

1. Faça o upload da pasta para `wp-content/plugins/vista-api-integration/`.
2. Ative **Vista API Integration** em Plugins.
3. Vá em **Vista API › Configurações** e preencha:
   - Chave da API
   - URL da API (ex.: `https://cli41034-rest.vistahost.com.br`)
   - Itens por página (30 recomendado)
   - Importação Automática + Intervalo (Hora em Hora)
   - URL de Redirecionamento para imóveis ocultos
4. Clique **Importar Imóveis Agora**.

## Recuperação do estado atual

No site hoje há 109 imóveis sem capa. Para corrigir:

1. Ative este plugin (substituindo o anterior).
2. Em Configurações, clique **Apagar Todos os Imóveis** (remove só os marcados
   com `_vista_codigo`, preserva posts nativos).
3. Clique **Importar Imóveis Agora**. Desta vez `_thumbnail_id` e `galeria` são
   gravados corretamente.

## Campos meta gravados por imóvel

| Meta | Formato | Usado por |
| --- | --- | --- |
| `_thumbnail_id` | int | Tema, Elementor, admin list thumbnails |
| `galeria` | array de IDs | JetEngine Gallery field |
| `galeria_ids` | CSV de IDs | Templates legados |
| `_vista_galeria` | array de IDs | Canônico (namespaced) |
| `_vista_codigo` | string | Chave de upsert |
| `_vista_data` | array | Payload Vista original |
| `_vista_hidden` / `_vista_conceituado` / `_vista_lancamento` | bool | Flags locais, não sobrescritos pela API |

## Cron

Usa `wp_schedule_event` com intervalos `hourly`, `daily`, `weekly`, `monthly`.
Se o TurboCloud tiver WP-Cron desativado, configure um cron real:

```
*/15 * * * * curl -s https://conceitocarioca.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
```
