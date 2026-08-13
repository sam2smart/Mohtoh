<?php // rider/includes/footer.php ?>
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php if (isset($extra_js) && is_array($extra_js)): ?>
    <?php foreach ($extra_js as $js_file): ?>
        <script src="<?= htmlspecialchars($js_file) ?>"></script>
    <?php endforeach; ?>
<?php endif; ?>
<script src="js/main.js"></script>
</body>
</html>