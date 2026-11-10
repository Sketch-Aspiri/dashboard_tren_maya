{{-- Aplica el tema (claro / oscuro / sistema) antes de pintar la página,
     para que no parpadee en blanco. La preferencia vive en la cookie "tema"
     del dominio común (compartida con Tickets) y en localStorage; sin ella se
     sigue la del sistema. El selector está en
     resources/js/tema.js (Alpine.data('selectorTema')). --}}
<script>
    (function () {
        // La cookie "tema" (dominio común del ecosistema) manda: así el Dashboard y
        // Tickets comparten la elección. Si no existe, se usa localStorage.
        var tema = null;
        var cookie = document.cookie.match(/(?:^|; )tema=(sistema|claro|oscuro)(?:;|$)/);
        if (cookie) { tema = cookie[1]; } else { try { tema = localStorage.getItem('tema'); } catch (e) {} }
        var oscuro = tema === 'oscuro' || (tema !== 'claro' && window.matchMedia('(prefers-color-scheme: dark)').matches);
        document.documentElement.classList.toggle('dark', oscuro);
    })();
</script>
