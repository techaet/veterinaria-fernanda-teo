# Atualização validada — identidade e cabeçalhos

Este pacote atualiza localmente a identidade visual aprovada da **Dra. Fernanda Teo** e unifica o cabeçalho da home, da página de Conteúdos e do artigo já existente. Ele foi validado por servidor HTTP local em desktop e em celular antes de ser empacotado.

> **Importante:** extraia o conteúdo deste ZIP na **raiz do site** `veterinaria.aetsolidez.com.br`. Não envie a pasta inteira para dentro de `/blog/`.

| Arquivo ou pasta | Finalidade |
|---|---|
| `index.html` | Cabeçalho comum na home, com o símbolo Cuidado em casa. A hero própria da Dra. Fernanda foi preservada. |
| `style.css` | Estilos comuns de navegação, símbolo e rodapé. |
| `blog/index.html` | Cabeçalho comum e hero editorial fotográfica da área Conteúdos. |
| `blog/blog.css` | Estilos da hero, cards e artigo do Blog. |
| `blog/viagem-internacional-com-pets-planejamento/index.html` | Cabeçalho comum no artigo já publicado. O conteúdo clínico não foi alterado. |
| `images/brand/cuidado-em-casa.webp` | Símbolo aprovado, usado em cabeçalho e rodapé. |
| `blog/assets/` | Hero e capas editoriais usadas na página de Conteúdos e no artigo. |

## Procedimento recomendado

1. Faça um backup dos arquivos atuais que possuem os mesmos nomes.
2. Envie este ZIP pelo gerenciador de arquivos da hospedagem.
3. Extraia o arquivo na raiz do domínio, permitindo substituir somente os arquivos de mesmo nome.
4. Abra em janela anônima: `/`, `/blog/` e `/blog/viagem-internacional-com-pets-planejamento/`.
5. Em celular, confirme que o menu abre e fecha normalmente e que o símbolo aparece junto ao nome.

## Validação realizada

| Verificação | Resultado |
|---|---|
| Símbolo no cabeçalho das três páginas | Aprovado |
| Itens e URLs da navegação nas três páginas | Idênticos e aprovados |
| Caminhos absolutos do símbolo e rotas de imagens | Respondendo corretamente por HTTP local |
| Desktop | Aprovado |
| Celular, 390 × 844 px | Aprovado |
| Menu móvel e atributos de acessibilidade | Aprovados |

Nenhum arquivo foi publicado no servidor durante esta etapa.
