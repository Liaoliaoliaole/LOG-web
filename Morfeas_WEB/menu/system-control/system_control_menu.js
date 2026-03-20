(() => {
  const menuBtn = document.getElementById('menuBtn');
  const dropdown = document.getElementById('mainMenu');
  if (!menuBtn || !dropdown) return;

  const openMenu = () => {
    dropdown.classList.add('open');
    menuBtn.setAttribute('aria-expanded', 'true');
  };

  const closeMenu = () => {
    dropdown.classList.remove('open');
    menuBtn.setAttribute('aria-expanded', 'false');
  };

  menuBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    if (dropdown.classList.contains('open')) {
      closeMenu();
    } else {
      openMenu();
    }
  });

  document.addEventListener('click', (e) => {
    if (!dropdown.contains(e.target) && e.target !== menuBtn) {
      closeMenu();
    }
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeMenu();
  });
})();
