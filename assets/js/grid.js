(function () {
  const form = document.querySelector('form[data-grid]');
  if (!form) return;

  const inputs = Array.from(form.querySelectorAll('input[data-r][data-c]'));
  const grid = [];
  inputs.forEach(function (i) {
    const r = +i.dataset.r, c = +i.dataset.c;
    (grid[r] = grid[r] || [])[c] = i;
  });

  const maks = +(form.dataset.max || 100);
  const desimal = form.dataset.desimal !== '0';
  const pola = desimal ? /^\d{1,3}(\.\d{1,2})?$/ : /^\d{1,3}$/;
  let kotor = false;

  function cek(i) {
    const v = i.value.trim().replace(',', '.');
    const ok = v === '' || (pola.test(v) && parseFloat(v) <= maks);
    i.classList.toggle('border-red-500', !ok);
    i.classList.toggle('bg-red-50', !ok);
  }

  function pindah(r, c) {
    const t = grid[r] && grid[r][c];
    if (t) { t.focus(); t.select(); }
  }

  inputs.forEach(function (i) {
    i.addEventListener('focus', function () { i.select(); });

    i.addEventListener('input', function () { kotor = true; cek(i); });

    i.addEventListener('keydown', function (e) {
      const r = +i.dataset.r, c = +i.dataset.c;
      if (e.key === 'Enter' || e.key === 'ArrowDown') { e.preventDefault(); pindah(r + 1, c); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); pindah(r - 1, c); }
      else if (e.key === 'ArrowLeft' && i.selectionStart === 0) { e.preventDefault(); pindah(r, c - 1); }
      else if (e.key === 'ArrowRight' && i.selectionEnd === i.value.length) { e.preventDefault(); pindah(r, c + 1); }
    });

    // Tempel banyak sel dari Excel: mulai dari sel yang sedang aktif
    i.addEventListener('paste', function (e) {
      const teks = (e.clipboardData || window.clipboardData).getData('text');
      if (!/[\t\r\n]/.test(teks)) return;            // tempel biasa untuk satu sel
      e.preventDefault();
      const baris = teks.replace(/\r/g, '').replace(/\n+$/, '').split('\n');
      const r0 = +i.dataset.r, c0 = +i.dataset.c;
      baris.forEach(function (b, dr) {
        b.split('\t').forEach(function (v, dc) {
          const t = grid[r0 + dr] && grid[r0 + dr][c0 + dc];
          if (t && !t.readOnly) { t.value = v.trim(); cek(t); }
        });
      });
      kotor = true;
    });
  });

  window.addEventListener('beforeunload', function (e) {
    if (kotor) { e.preventDefault(); e.returnValue = ''; }
  });
  form.addEventListener('submit', function () { kotor = false; });

  // Ganti mapel lewat dropdown (halaman nilai)
  const sw = document.getElementById('gantiMapel');
  if (sw) {
    sw.addEventListener('change', function () {
      if (kotor && !confirm('Perubahan yang belum disimpan akan hilang. Lanjutkan?')) {
        sw.value = sw.dataset.sekarang;
        return;
      }
      kotor = false;
      location.href = sw.dataset.base + sw.value;
    });
  }
})();