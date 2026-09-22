</div>
<footer class="text-center text-muted small py-3">
  MRP Elettronica &copy; <?= date('Y') ?>
  <?php $u = current_user(); if (!empty($u['azienda_nome'])): ?>
    &middot; <?= h($u['azienda_nome']) ?>
  <?php endif; ?>
  &middot; v<?= h(APP_VERSION) ?>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
