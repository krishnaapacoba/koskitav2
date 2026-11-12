(function () {
  // Toggle panel QRIS / Tunai
  document.querySelectorAll('[data-pay-form]').forEach(function (form) {
    function sync() {
      var m = form.querySelector('input[name=method]:checked');
      var v = m ? m.value : 'QRIS';
      form.querySelectorAll('[data-panel]').forEach(function (p) {
        var on = p.getAttribute('data-panel') === v;
        p.hidden = !on;
        p.querySelectorAll('input[type=file]').forEach(function (i) { i.disabled = !on; });
      });
      var btn = form.querySelector('button[type=submit]');
      if (btn) btn.textContent = v === 'CASH' ? 'Ajukan pembayaran tunai' : 'Kirim bukti pembayaran';
    }
    form.querySelectorAll('input[name=method]').forEach(function (r) { r.addEventListener('change', sync); });
    sync();
  });
  // Tutup popup (tombol OK)
  document.querySelectorAll('.modal [data-close]').forEach(function (b) {
    b.addEventListener('click', function () { b.closest('.modal').remove(); });
  });
  // Konfirmasi aksi berbahaya
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) { if (!confirm(f.getAttribute('data-confirm'))) e.preventDefault(); });
  });
  // Pratinjau tombol cetak nota
  document.querySelectorAll('[data-print]').forEach(function (b) { b.addEventListener('click', function () { window.print(); }); });
})();
