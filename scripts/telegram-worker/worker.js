// Cloudflare Worker: recebe o toque nos botões do Telegram e comenta PUBLICAR/EXCLUIR na issue.
// O comentário sai com o token do GitHub (conta LSFcamp), então passa pela checagem do artigo-decisao.yml.
// Variáveis (wrangler secret put): TELEGRAM_TOKEN, WEBHOOK_SECRET, GITHUB_TOKEN, ALLOWED_IDS (ex. "111,222"), REPO (ex. "techaet/veterinaria-fernanda-teo")
export default {
  async fetch(req, env) {
    if (req.method !== 'POST' || req.headers.get('X-Telegram-Bot-Api-Secret-Token') !== env.WEBHOOK_SECRET)
      return new Response('ok');
    const cb = (await req.json()).callback_query;
    if (!cb) return new Response('ok');
    const tg = (m, body) => fetch(`https://api.telegram.org/bot${env.TELEGRAM_TOKEN}/${m}`, {
      method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify(body) });
    const [acao, issue] = (cb.data || '').split(':');
    if (!env.ALLOWED_IDS.split(',').includes(String(cb.from.id)) || !/^\d+$/.test(issue) || !['publicar', 'excluir'].includes(acao)) {
      await tg('answerCallbackQuery', { callback_query_id: cb.id, text: 'Sem permissão.' });
      return new Response('ok');
    }
    const r = await fetch(`https://api.github.com/repos/${env.REPO}/issues/${issue}/comments`, {
      method: 'POST',
      headers: { authorization: `Bearer ${env.GITHUB_TOKEN}`, 'user-agent': 'telegram-aprovacao', accept: 'application/vnd.github+json' },
      body: JSON.stringify({ body: acao.toUpperCase() + `\n\n(via Telegram: ${cb.from.first_name})`, }) });
    if (r.ok) {
      await tg('editMessageReplyMarkup', { chat_id: cb.message.chat.id, message_id: cb.message.message_id, reply_markup: { inline_keyboard: [] } });
      await tg('sendMessage', { chat_id: cb.message.chat.id, text: acao === 'publicar' ? '✅ Publicando (cerca de 1 minuto).' : '🗑️ Rascunho excluído.' });
    }
    await tg('answerCallbackQuery', { callback_query_id: cb.id, text: r.ok ? 'Feito' : 'Falhou, tente de novo' });
    return new Response('ok');
  },
};
