document.addEventListener("DOMContentLoaded", () => {
  const navs = Array.from(document.querySelectorAll(".hamburger-nav"));
  if (!navs.length) {
    return;
  }

  navs.forEach((nav, index) => {
    const toggleButton = nav.querySelector(".hamburger-toggle");
    const menuPanel = nav.querySelector(".hamburger-panel");

    if (!toggleButton || !menuPanel) {
      return;
    }

    if (!menuPanel.id) {
      menuPanel.id = `hamburger-menu-${index + 1}`;
    }
    toggleButton.setAttribute("aria-controls", menuPanel.id);

    const setOpen = (isOpen) => {
      toggleButton.setAttribute("aria-expanded", isOpen ? "true" : "false");
      menuPanel.hidden = !isOpen;
      nav.classList.toggle("is-open", isOpen);
    };

    setOpen(false);

    toggleButton.addEventListener("click", () => {
      const currentlyOpen = !menuPanel.hidden;
      setOpen(!currentlyOpen);
    });

    menuPanel.addEventListener("click", (event) => {
      const link = event.target.closest("a");
      if (link) {
        setOpen(false);
      }
    });

    document.addEventListener("click", (event) => {
      if (menuPanel.hidden) {
        return;
      }

      if (!nav.contains(event.target)) {
        setOpen(false);
      }
    });

    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape") {
        setOpen(false);
      }
    });
  });
});
