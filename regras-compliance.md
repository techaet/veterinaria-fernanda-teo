# Regras de conformidade — Dra. Fernanda Teo Veterinária

Documento de referência única para os dados fixos do negócio e as regras de conteúdo clínico que qualquer texto publicado neste site (páginas institucionais, blog, guias) precisa respeitar. Criado em 2026-09-07 a partir de decisões tomadas em revisão de conteúdo — não existia antes um documento formal para isso.

**Como usar:** antes de publicar qualquer página ou artigo novo, ou ao revisar um existente, confira o texto contra este arquivo. Se um fato aqui precisar mudar (novo bairro atendido, novo valor de consulta, etc.), atualize este arquivo primeiro e depois propague a mudança para todas as páginas que citam o fato.

## 1. Dados fixos do negócio

Estes valores devem aparecer **de forma idêntica** em toda página pública (home, blog, guias, meta tags, JSON-LD). Qualquer variação de texto, abreviação ou dado desatualizado é considerado erro de conformidade.

| Fato | Valor canônico |
|---|---|
| CRMV | `CRMV/SC 5669` |
| Agendamento | sempre pelo site `https://www.fernandateo.vet.br/agendar/`, **nunca pelo WhatsApp** |
| WhatsApp (dúvidas e pós-consulta) | `(48) 99969-0448` → link `https://wa.me/5548999690448`; nunca como canal de agendamento |
| Áreas de atendimento | Campeche, Morro das Pedras, Rio Tavares e Lagoa da Conceição |
| Experiência | mais de 14 anos de experiência clínica (atendimento a cães e gatos) |
| Valor da consulta | R$ 250,00 — podendo haver taxa extra dependendo do local e do horário do atendimento |

**Nota sobre as áreas de atendimento:** em 2026-10-04 todas as páginas foram alinhadas aos quatro bairros. O `scripts/verificar.py` acusa erro se alguma página citar só parte da lista.

**Nota sobre o agendamento (2026-10-17):** todo convite a agendar leva ao site (`/agendar/`). O WhatsApp continua para dúvidas e pós-consulta (prescrição, retorno, aviso da visita), mas nenhum texto pode convidar a agendar por ele nem apresentá-lo como canal de agendamento. Quem pedir horário por mensagem recebe o link do site.

**Nota sobre a experiência:** a formulação canônica é "mais de 14 anos de experiência" (já usada nas iscas em `isca/`). Escrever "mais de", nunca só "14 anos", para não envelhecer. Revisar o número quando a Dra. Fernanda completar o próximo ano. **Nas redes (Instagram e Facebook), a frase fixa "Mais de 14 anos atendendo com carinho e propósito." é obrigatória (desde 2026-10-17) em toda legenda e no fechamento (slide ou tela final) de carrossel, reel e post.**

**Nota sobre o preço:** até 2026-09-07, nenhuma página do site menciona o valor da consulta. Se/quando um texto passar a citar preço, ele deve usar exatamente o valor acima — não um número aproximado, arredondado ou extraído de memória.

## 2. Regras de conteúdo clínico

Nenhum texto publicado (institucional, blog ou guia) pode conter:

- **Diagnóstico específico** para um caso — o texto pode listar sinais de alerta e explicar quando procurar avaliação, mas não pode afirmar "seu pet tem X".
- **Nome de medicamento ou dose** — nem para recomendar, nem como exemplo. Quando o tema exigir falar de tratamento, o texto deve remeter à avaliação individual pela Dra. Fernanda Teo, sem citar princípio ativo, marca comercial ou posologia.
- **Promessa de cura de alergia** (ou de qualquer condição crônica sem cura definitiva) — o texto pode falar em controle e qualidade de vida, nunca em cura.
- **Triagem de urgência** — o blog não deve orientar o tutor a decidir sozinho se um caso é grave; sempre direcionar para avaliação profissional (agendar a consulta em www.fernandateo.vet.br/agendar) quando houver sinal de alerta.
- **Substituição da consulta** — todo artigo de conteúdo clínico deve deixar claro, de forma visível (ex. `aside.article-safety`), que o texto é educativo e não substitui avaliação individual.

### Ênfase na experiência (diretriz de tom)

Os 14 anos de atendimento são um diferencial raro: não se conquistam do dia para a noite. Sempre que o texto abrir espaço, mencione a experiência de forma natural, ligada ao que ela traz ao tutor (calma na consulta, olhar clínico, histórico de casos) e não como propaganda:

- **Onde destacar:** home (hero e "Sobre"), página da autora, caixa "Sobre a autora" dos artigos, `agendar/`, CTAs de agendamento, iscas/guias, descrição do WhatsApp Business, Instagram e Reels.
- **Redes:** legenda e fechamento levam a frase fixa da seção 1 ("Mais de 14 anos atendendo com carinho e propósito.").
- **Como escrever:** fatos concretos ("mais de 14 anos atendendo cães e gatos") em vez de adjetivos ("a melhor", "a mais experiente").
- **Limites (CFMV e §2):** não usar superlativo nem comparação com outros profissionais, não prometer resultado e não usar a experiência como argumento para dispensar a avaliação individual.
- **Sem forçar:** em texto técnico curto (ex. resposta de FAQ) a menção pode ficar de fora. Uma vez por página basta; repetir demais enfraquece.

## 3. Processo editorial

- Todo artigo novo sobre saúde animal deve ser revisado e aprovado expressamente pela Dra. Fernanda Teo antes de publicação (herdado do `LEIA-ME-BLOG-VETERINARIA.txt` original do projeto).
- **Nenhum texto sobre esse processo de aprovação pode vazar para o HTML público** — nem em título, meta tag, categoria, `article-meta`, aviso de "conteúdo educativo" ou qualquer outro texto visível. Frases como "revisão obrigatória", "pendente de aprovação", "rascunho", "antes de publicação", "aguardando aprovação" (ou variações) já vazaram para o site publicado antes; ficam registradas aqui como erro conhecido a checar sempre.
- **Exceção permitida:** a linha fixa "Por Dra. Fernanda Teo, médica-veterinária (CRMV/SC 5669)" na byline dos artigos (gerada por `scripts/sincronizar.py`; decisão do cliente em out/2026, com a conformidade com o CFMV conferida por ele). É assinatura, não status de processo. Só entra no `main` o que já foi aprovado.
- Comunicação de status de revisão, quando necessária, deve acontecer fora do arquivo (conversa com o Leonardo/Claude), nunca dentro do código.

## 4. Estrutura de conteúdo (hub de artigos)

- **Temas fixos** em `scripts/site.json`: o 1º item de `article-meta` de todo artigo é um desses temas (o `sincronizar.py` recusa outro). Para criar um tema novo, edite o `site.json` primeiro. Tema só ganha página própria quando tiver 3+ artigos.
- **Cada artigo mostra** byline com credencial, "Publicado em"/"Atualizado em" (do schema `datePublished`/`dateModified`) e caixa "Sobre a autora". Tudo gerado por `sincronizar.py`; não editar à mão.
- **Sem comentários públicos:** conteúdo de saúde atrai pedidos de diagnóstico e posologia, que o §2 proíbe. Dúvidas vão para o WhatsApp; o agendamento, para `/agendar/` (CTA do artigo).
- **FAQ:** continua visível no artigo (ajuda o tutor). O Google aposentou o rich result de FAQ em 7/mai/2026; o schema `FAQPage` é inofensivo, mas não gera retorno. Se existir, segue batendo palavra por palavra.

## 5. Checklist rápido por artigo/página

- [ ] CRMV, WhatsApp, áreas de atendimento e (se citado) preço batem com a tabela da seção 1, exatamente.
- [ ] Nenhum texto convida a agendar pelo WhatsApp; todo convite a agendar leva a `/agendar/`. WhatsApp só como canal de dúvidas e pós-consulta.
- [ ] Peça de rede: a frase "Mais de 14 anos atendendo com carinho e propósito." está na legenda e no fechamento.
- [ ] A experiência ("mais de 14 anos") aparece quando couber, sem superlativo, e bate com a tabela da seção 1.
- [ ] Nenhum diagnóstico específico, nome de medicamento, dose ou promessa de cura.
- [ ] Aviso de "conteúdo educativo / não substitui consulta" presente e visível.
- [ ] Nenhum texto de status editorial visível no HTML.
- [ ] 1º item de `article-meta` = tema de `scripts/site.json`; `python3 scripts/sincronizar.py` rodado.
- [ ] Se houver FAQ com schema `FAQPage`, pergunta e resposta batem palavra por palavra com o texto visível.
