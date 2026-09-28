(function (Drupal, once) {
  Drupal.behaviors.personalSecretaryProductNavigation = {
    attach(context) {
      once('personal-secretary-product-navigation', '.ps-product-header', context)
        .forEach((header) => {
          const toggle = header.querySelector('[data-ps-menu-toggle]');
          const navigation = header.querySelector('[data-ps-menu]');
          if (!toggle || !navigation) return;
          header.dataset.navigationEnhanced = 'true';
          navigation.dataset.open = 'false';
          toggle.setAttribute('aria-expanded', 'false');
          const setOpen = (open) => {
            navigation.dataset.open = open ? 'true' : 'false';
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
          };
          toggle.addEventListener('click', () => setOpen(toggle.getAttribute('aria-expanded') !== 'true'));
          navigation.addEventListener('click', (event) => {
            if (event.target.closest('a')) setOpen(false);
          });
          header.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
              setOpen(false);
              toggle.focus();
            }
          });
        });
    },
  };
})(Drupal, once);
