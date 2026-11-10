{{-- Aplica el tema (claro / oscuro / sistema) antes de pintar la página,
     para que no parpadee en blanco. La preferencia vive en localStorage
     ("tema"); sin ella se sigue la del sistema. El selector está en
     resources/js/tema.js (Alpine.data('selectorTema')). --}}
<script>
    (function () {
        var tema = null;
        try { tema = localStorage.getItem('tema'); } catch (e) {}
        var oscuro = tema === 'oscuro' || (tema !== 'claro' && window.matchMedia('(prefers-color-scheme: dark)').matches);
        document.documentElement.classList.toggle('dark', oscuro);
    })();
</script>
