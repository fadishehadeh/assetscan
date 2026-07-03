document.addEventListener('DOMContentLoaded', () => {

  // ── Auto-dismiss alerts after 4s ──────────────────────────────────────
  document.querySelectorAll('.alert-dismissible').forEach(el => {
    setTimeout(() => bootstrap.Alert.getOrCreateInstance(el).close(), 4500);
  });

  // ── Animate stat cards in with stagger ───────────────────────────────
  document.querySelectorAll('.stat-card').forEach((el, i) => {
    el.style.opacity = '0';
    el.style.transform = 'translateY(16px)';
    el.style.transition = 'opacity .35s ease, transform .35s cubic-bezier(.22,.68,0,1.2)';
    setTimeout(() => {
      el.style.opacity = '1';
      el.style.transform = 'translateY(0)';
    }, 60 + i * 70);
  });

  // ── Animate table rows in ─────────────────────────────────────────────
  document.querySelectorAll('tbody tr').forEach((tr, i) => {
    tr.style.opacity = '0';
    tr.style.transform = 'translateX(-6px)';
    tr.style.transition = `opacity .28s ease ${i * 28}ms, transform .28s ease ${i * 28}ms`;
    requestAnimationFrame(() => {
      tr.style.opacity = '1';
      tr.style.transform = 'translateX(0)';
    });
  });

  // ── Animate cards in ─────────────────────────────────────────────────
  document.querySelectorAll('.card').forEach((el, i) => {
    el.style.opacity = '0';
    el.style.transform = 'translateY(10px)';
    el.style.transition = `opacity .3s ease ${i * 40}ms, transform .3s cubic-bezier(.22,.68,0,1.2) ${i * 40}ms`;
    requestAnimationFrame(() => {
      el.style.opacity = '1';
      el.style.transform = 'translateY(0)';
    });
  });

  // ── Button ripple effect ──────────────────────────────────────────────
  document.querySelectorAll('.btn').forEach(btn => {
    btn.addEventListener('click', function(e) {
      const rect = this.getBoundingClientRect();
      const ripple = document.createElement('span');
      const size = Math.max(rect.width, rect.height);
      ripple.style.cssText = `
        position:absolute;width:${size}px;height:${size}px;
        left:${e.clientX - rect.left - size/2}px;
        top:${e.clientY - rect.top - size/2}px;
        background:rgba(255,255,255,.25);border-radius:50%;
        transform:scale(0);animation:ripple .5s ease-out forwards;
        pointer-events:none;
      `;
      if (getComputedStyle(this).position === 'static') this.style.position = 'relative';
      this.style.overflow = 'hidden';
      this.appendChild(ripple);
      setTimeout(() => ripple.remove(), 500);
    });
  });

  // ── Inject ripple keyframe once ───────────────────────────────────────
  if (!document.getElementById('ripple-style')) {
    const s = document.createElement('style');
    s.id = 'ripple-style';
    s.textContent = '@keyframes ripple{to{transform:scale(2.5);opacity:0}}';
    document.head.appendChild(s);
  }

  // ── Progress bars animate in ──────────────────────────────────────────
  document.querySelectorAll('.progress-bar').forEach(bar => {
    const target = bar.style.width || bar.getAttribute('aria-valuenow') + '%';
    bar.style.width = '0';
    setTimeout(() => { bar.style.width = target; }, 200);
  });

  // ── Sidebar active link scroll into view ──────────────────────────────
  const activeLink = document.querySelector('.sidebar .nav-link.active');
  if (activeLink) activeLink.scrollIntoView({ block: 'nearest', behavior: 'smooth' });

  // ── Tooltip init ──────────────────────────────────────────────────────
  document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
    new bootstrap.Tooltip(el, { trigger: 'hover' });
  });

});
