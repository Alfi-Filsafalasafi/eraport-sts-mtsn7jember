    </main>
  </div>
</div>
<script>
  (function () {
    const s = document.getElementById('sidebar'),
          o = document.getElementById('overlay'),
          b = document.getElementById('menuBtn');
    function buka(v) { s.classList.toggle('-translate-x-full', !v); o.classList.toggle('hidden', !v); }
    b.addEventListener('click', () => buka(true));
    o.addEventListener('click', () => buka(false));
  })();
</script>
</body>
</html>
