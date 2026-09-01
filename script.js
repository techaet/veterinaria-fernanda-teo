/* ============================================================
   LANDING PAGE - DRA. FERNANDA TEO - VETERINÁRIA
   JavaScript de Interatividade
   Desenvolvido por AET Solidez
   ============================================================ */

document.addEventListener('DOMContentLoaded', function () {

  /* ----------------------------------------------------------
     NAVBAR: sombra ao rolar a página
     ---------------------------------------------------------- */
  const navbar = document.getElementById('navbar');

  window.addEventListener('scroll', function () {
    if (window.scrollY > 20) {
      navbar.classList.add('scrolled');
    } else {
      navbar.classList.remove('scrolled');
    }
  });

  /* ----------------------------------------------------------
     NAVBAR: menu hamburguer (mobile)
     ---------------------------------------------------------- */
  const toggle = document.getElementById('navbar-toggle');
  const menu = document.getElementById('navbar-menu');

  if (toggle && menu) {
    toggle.addEventListener('click', function () {
      const aberto = menu.classList.toggle('aberto');
      toggle.setAttribute('aria-expanded', aberto.toString());
      toggle.setAttribute('aria-label', aberto ? 'Fechar menu' : 'Abrir menu');
    });

    // Fecha o menu ao clicar em um link
    menu.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', function () {
        menu.classList.remove('aberto');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-label', 'Abrir menu');
      });
    });

    // Fecha o menu ao clicar fora
    document.addEventListener('click', function (e) {
      if (!navbar.contains(e.target)) {
        menu.classList.remove('aberto');
        toggle.setAttribute('aria-expanded', 'false');
      }
    });
  }

  /* ----------------------------------------------------------
     SCROLL SUAVE: destaque do link ativo na navbar
     ---------------------------------------------------------- */
  const secoes = document.querySelectorAll('section[id]');
  const linksMenu = document.querySelectorAll('.navbar-menu a[href^="#"]');

  function destacarLinkAtivo() {
    let posicaoAtual = window.scrollY + 100;

    secoes.forEach(function (secao) {
      const topo = secao.offsetTop;
      const altura = secao.offsetHeight;
      const id = secao.getAttribute('id');

      if (posicaoAtual >= topo && posicaoAtual < topo + altura) {
        linksMenu.forEach(function (link) {
          link.style.color = '';
          link.style.fontWeight = '';
        });
        const linkAtivo = document.querySelector('.navbar-menu a[href="#' + id + '"]');
        if (linkAtivo) {
          linkAtivo.style.color = 'var(--cor-primaria)';
          linkAtivo.style.fontWeight = '700';
        }
      }
    });
  }

  window.addEventListener('scroll', destacarLinkAtivo);

  /* ----------------------------------------------------------
     ANIMAÇÃO DE ENTRADA: elementos aparecem ao rolar (fade-in)
     ---------------------------------------------------------- */
  const elementosAnimados = document.querySelectorAll(
    '.servico-card, .depoimento-card, .diferencial-item, .formacao-item, .credencial-card'
  );

  // Adiciona classe inicial de invisibilidade
  elementosAnimados.forEach(function (el) {
    el.style.opacity = '0';
    el.style.transform = 'translateY(24px)';
    el.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
  });

  // Observador de interseção para animar ao entrar na tela
  if ('IntersectionObserver' in window) {
    const observador = new IntersectionObserver(function (entradas) {
      entradas.forEach(function (entrada) {
        if (entrada.isIntersecting) {
          entrada.target.style.opacity = '1';
          entrada.target.style.transform = 'translateY(0)';
          observador.unobserve(entrada.target);
        }
      });
    }, {
      threshold: 0.1,
      rootMargin: '0px 0px -40px 0px'
    });

    elementosAnimados.forEach(function (el) {
      observador.observe(el);
    });
  } else {
    // Fallback para navegadores sem suporte
    elementosAnimados.forEach(function (el) {
      el.style.opacity = '1';
      el.style.transform = 'translateY(0)';
    });
  }

  /* ----------------------------------------------------------
     ANIMAÇÃO: delay escalonado nos cards de serviços
     ---------------------------------------------------------- */
  document.querySelectorAll('.servicos-grid .servico-card').forEach(function (card, i) {
    card.style.transitionDelay = (i * 0.1) + 's';
  });

  document.querySelectorAll('.depoimentos-grid .depoimento-card').forEach(function (card, i) {
    card.style.transitionDelay = (i * 0.1) + 's';
  });

  document.querySelectorAll('.diferenciais-grid .diferencial-item').forEach(function (item, i) {
    item.style.transitionDelay = (i * 0.08) + 's';
  });

});
