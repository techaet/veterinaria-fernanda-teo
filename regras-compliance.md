# Regras de conformidade — Dra. Fernanda Teo Veterinária

Documento de referência única para os dados fixos do negócio e as regras de conteúdo clínico que qualquer texto publicado neste site (páginas institucionais, blog, guias) precisa respeitar. Criado em 2026-09-07 a partir de decisões tomadas em revisão de conteúdo — não existia antes um documento formal para isso.

**Como usar:** antes de publicar qualquer página ou artigo novo, ou ao revisar um existente, confira o texto contra este arquivo. Se um fato aqui precisar mudar (novo bairro atendido, novo valor de consulta, etc.), atualize este arquivo primeiro e depois propague a mudança para todas as páginas que citam o fato.

## 1. Dados fixos do negócio

Estes valores devem aparecer **de forma idêntica** em toda página pública (home, blog, guias, meta tags, JSON-LD). Qualquer variação de texto, abreviação ou dado desatualizado é considerado erro de conformidade.

| Fato | Valor canônico |
|---|---|
| CRMV | `CRMV/SC 5669` |
| WhatsApp | `(48) 99969-0448` → link `https://wa.me/5548999690448` |
| Áreas de atendimento | Campeche, Morro das Pedras, Rio Tavares e Lagoa da Conceição |
| Valor da consulta | R$ 250,00 — podendo haver taxa extra dependendo do local e do horário do atendimento |

**Nota sobre as áreas de atendimento:** em 2026-09-07, a home (`index.html`) ainda lista só três bairros ("Campeche, Rio Tavares e Lagoa da Conceição", sem Morro das Pedras) em três lugares — `meta description`, `og:description` e um `<span>` visível perto da linha 639 — enquanto os 8 artigos do blog já foram corrigidos para os quatro bairros. Isso precisa ser alinhado.

**Nota sobre o preço:** até 2026-09-07, nenhuma página do site menciona o valor da consulta. Se/quando um texto passar a citar preço, ele deve usar exatamente o valor acima — não um número aproximado, arredondado ou extraído de memória.

## 2. Regras de conteúdo clínico

Nenhum texto publicado (institucional, blog ou guia) pode conter:

- **Diagnóstico específico** para um caso — o texto pode listar sinais de alerta e explicar quando procurar avaliação, mas não pode afirmar "seu pet tem X".
- **Nome de medicamento ou dose** — nem para recomendar, nem como exemplo. Quando o tema exigir falar de tratamento, o texto deve remeter à avaliação individual pela Dra. Fernanda Teo, sem citar princípio ativo, marca comercial ou posologia.
- **Promessa de cura de alergia** (ou de qualquer condição crônica sem cura definitiva) — o texto pode falar em controle e qualidade de vida, nunca em cura.
- **Triagem de urgência** — o blog não deve orientar o tutor a decidir sozinho se um caso é grave; sempre direcionar para avaliação profissional (presencial ou WhatsApp) quando houver sinal de alerta.
- **Substituição da consulta** — todo artigo de conteúdo clínico deve deixar claro, de forma visível (ex. `aside.article-safety`), que o texto é educativo e não substitui avaliação individual.

## 3. Processo editorial

- Todo artigo novo sobre saúde animal deve ser revisado e aprovado expressamente pela Dra. Fernanda Teo antes de publicação (herdado do `LEIA-ME-BLOG-VETERINARIA.txt` original do projeto).
- **Nenhum texto sobre esse processo de aprovação pode vazar para o HTML público** — nem em título, meta tag, categoria, `article-meta`, aviso de "conteúdo educativo" ou qualquer outro texto visível. Frases como "revisão obrigatória", "pendente de aprovação", "rascunho", "antes de publicação", "aguardando aprovação" (ou variações) já vazaram para o site publicado antes; ficam registradas aqui como erro conhecido a checar sempre.
- Comunicação de status de revisão, quando necessária, deve acontecer fora do arquivo (conversa com o Leonardo/Claude), nunca dentro do código.

## 4. Checklist rápido por artigo/página

- [ ] CRMV, WhatsApp, áreas de atendimento e (se citado) preço batem com a tabela da seção 1, exatamente.
- [ ] Nenhum diagnóstico específico, nome de medicamento, dose ou promessa de cura.
- [ ] Aviso de "conteúdo educativo / não substitui consulta" presente e visível.
- [ ] Nenhum texto de status editorial visível no HTML.
- [ ] Se houver FAQ com schema `FAQPage`, pergunta e resposta batem palavra por palavra com o texto visível.
