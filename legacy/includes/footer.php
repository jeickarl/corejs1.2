    </div> <!-- End main-content -->
</div> <!-- End main-content-wrapper -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var triggerTabList = [].slice.call(document.querySelectorAll('#configTabs button'))
    triggerTabList.forEach(function (triggerEl) {
        new bootstrap.Tab(triggerEl)
    })
});
</script>
<?php if (file_exists(__DIR__ . '/../config/app_config.php')) { require_once __DIR__ . '/../config/app_config.php'; } ?>
<script src="<?php echo htmlspecialchars(($APP_CONFIG['assets_path'] ?? '/assets')); ?>/js/utils.js?v=<?php echo time(); ?>"></script>
<script src="<?php echo htmlspecialchars(($APP_CONFIG['assets_path'] ?? '/assets')); ?>/js/app.js?v=<?php echo time(); ?>"></script>
<script src="<?php echo htmlspecialchars(($APP_CONFIG['assets_path'] ?? '/assets')); ?>/js/orders.js?v=<?php echo time(); ?>"></script>
<script src="<?php echo htmlspecialchars(($APP_CONFIG['assets_path'] ?? '/assets')); ?>/js/clients.js?v=<?php echo time(); ?>"></script>
<script src="<?php echo htmlspecialchars(($APP_CONFIG['assets_path'] ?? '/assets')); ?>/js/modal-handlers.js?v=<?php echo time(); ?>"></script>
</body>
</html>
