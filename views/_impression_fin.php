    </div>
<?php // « Imprimer / PDF » : data-print n'est traité que dans assets/app.js, et
      // une page d'impression ne charge pas tout le script de l'application pour
      // un appel. Sans cette ligne, le bouton ne fait rien.
      //
      // Échap ferme l'onglet : ces documents s'ouvrent dans une fenêtre à part,
      // et c'est le geste qu'on fait pour en sortir. ?>
<script nonce="<?= e(csp_nonce()) ?>">
document.querySelector('[data-print]')?.addEventListener('click', () => window.print());
document.addEventListener('keydown', e => { if (e.key === 'Escape') window.close(); });
</script>
</body>
</html>
