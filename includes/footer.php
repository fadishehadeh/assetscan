<?php ob_end_flush(); ?>
</main><!-- /.page-wrapper -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="/asset-manager/assets/js/main.js"></script>
<script>
// Global search
(function(){
  const input = document.getElementById('globalSearchInput');
  const dropdown = document.getElementById('searchDropdown');
  const form = document.getElementById('globalSearchForm');
  if (!input) return;

  const icons = { asset: 'bi-box', user: 'bi-person', maintenance: 'bi-wrench' };
  let timer;

  function close() { dropdown.classList.remove('open'); dropdown.innerHTML = ''; }

  input.addEventListener('input', () => {
    clearTimeout(timer);
    const q = input.value.trim();
    if (q.length < 2) { close(); return; }
    timer = setTimeout(() => {
      fetch('/asset-manager/search.php?format=json&q=' + encodeURIComponent(q))
        .then(r => r.json())
        .then(data => {
          if (!data.length) { close(); return; }
          dropdown.innerHTML = data.map(d =>
            `<a class="search-result-item" href="${d.url}">
              <div class="search-result-icon type-${d.type}"><i class="bi ${icons[d.type]||'bi-search'}"></i></div>
              <div class="search-result-text">
                <div class="search-result-label">${d.label}</div>
                <div class="search-result-sub">${d.sub}</div>
              </div>
            </a>`
          ).join('') + `<span class="search-result-all" onclick="document.getElementById('globalSearchForm').submit()">View all results for "${q}" →</span>`;
          dropdown.classList.add('open');
        });
    }, 250);
  });

  document.addEventListener('click', e => { if (!e.target.closest('#topbarSearch')) close(); });
  input.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
})();

// Mobile sidebar toggle
(function(){
  const toggle = document.getElementById('sidebarToggle');
  const overlay = document.getElementById('sidebarOverlay');
  const sidebar = document.getElementById('sidebar');
  if (!toggle) return;
  toggle.addEventListener('click', () => document.body.classList.toggle('sidebar-open'));
  overlay.addEventListener('click', () => document.body.classList.remove('sidebar-open'));
})();
// PWA service worker
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('/asset-manager/assets/js/sw.js').catch(() => {});
}
</script>
</body>
</html>
