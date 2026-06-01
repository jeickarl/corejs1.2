<?php
$invFile = basename($_SERVER['PHP_SELF']);
$invIsActive = function (array $files) use ($invFile) {
    return in_array($invFile, $files, true) ? 'active' : '';
};
$invCanManage = function_exists('hasRole') && hasRole(['admin', 'inventory']);
?>

<div class="inventory-subnav d-flex gap-2 flex-wrap">
    <a href="index.php" class="btn btn-sm btn-outline-primary rounded-pill px-3 <?php echo $invIsActive(['index.php', 'view.php', 'edit.php', 'new.php']); ?>">
        <i class="fas fa-boxes me-2"></i>Productos
    </a>

    <?php if ($invCanManage): ?>
        <a href="movements.php" class="btn btn-sm btn-outline-info rounded-pill px-3 <?php echo $invIsActive(['movements.php', 'movement.php']); ?>">
            <i class="fas fa-exchange-alt me-2"></i>Movimientos
        </a>
        <a href="categories.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3 <?php echo $invIsActive(['categories.php']); ?>">
            <i class="fas fa-tags me-2"></i>Categorías
        </a>
        <a href="brands.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3 <?php echo $invIsActive(['brands.php']); ?>">
            <i class="fas fa-copyright me-2"></i>Marcas
        </a>
    <?php endif; ?>
</div>
