<?php
require_once '../config/session.php';
require_once '../config/functions.php';
require_once '../config/database.php';
require_once '../config/app_config.php';

// Verificar autenticación
requireAuth();

$perDatabase = function_exists('isPerDatabaseMode') ? isPerDatabaseMode() : false;

header('Location: index.php');
exit();
__halt_compiler();
                    <div class="col-md-3">
                        <div class="card border-0 shadow-sm rounded-4 h-100">
                            <div class="card-body d-flex align-items-center">
                                <div class="rounded-circle bg-warning bg-opacity-10 p-3 me-3">
                                    <i class="fas fa-exclamation-triangle fa-2x text-warning"></i>
                                </div>
                                <div>
                                    <h5 class="fw-bold mb-0"><?php echo number_format($stats['low_stock']); ?></h5>
                                    <small class="text-muted">Stock Bajo</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-0 shadow-sm rounded-4 h-100">
                            <div class="card-body d-flex align-items-center">
                                <div class="rounded-circle bg-danger bg-opacity-10 p-3 me-3">
                                    <i class="fas fa-times-circle fa-2x text-danger"></i>
                                </div>
                                <div>
                                    <h5 class="fw-bold mb-0"><?php echo number_format($stats['zero_stock']); ?></h5>
                                    <small class="text-muted">Sin Stock</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card border-0 shadow-sm rounded-4 h-100">
                            <div class="card-body d-flex align-items-center">
                                <div class="rounded-circle bg-success bg-opacity-10 p-3 me-3">
                                    <i class="fas fa-dollar-sign fa-2x text-success"></i>
                                </div>
                                <div>
                                    <h5 class="fw-bold mb-0">$<?php echo number_format($stats['total_value'], 0, ',', '.'); ?></h5>
                                    <small class="text-muted">Valor Total</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtros -->
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-body py-3">
                        <form method="GET" class="row g-3 align-items-center">
                            <div class="col-md-3">
                                <div class="input-group">
                                    <span class="input-group-text bg-light text-muted border-end-0 rounded-start-pill px-3"><i class="fas fa-search"></i></span>
                                    <input type="text" class="form-control bg-light border-start-0 rounded-end-pill px-3" id="search" name="search" 
                                           value="<?php echo htmlspecialchars($search); ?>" 
                                           placeholder="Buscar producto...">
                                </div>
                            </div>
                            <div class="col-md-2">
                                <select class="form-select rounded-pill bg-light border-0" id="category" name="category">
                                    <option value="">Categoría: Todas</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo $category['id']; ?>" 
                                                <?php echo $category_filter == $category['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($category['name']); ?>
                                        </option>
                                    <?php
endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select class="form-select rounded-pill bg-light border-0" id="brand" name="brand">
                                    <option value="">Marca: Todas</option>
                                    <?php foreach ($brands as $brand): ?>
                                        <option value="<?php echo $brand['id']; ?>" 
                                                <?php echo $brand_filter == $brand['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($brand['name']); ?>
                                        </option>
                                    <?php
endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select class="form-select rounded-pill bg-light border-0" id="stock" name="stock">
                                    <option value="">Stock: Todos</option>
                                    <option value="low" <?php echo $stock_filter === 'low' ? 'selected' : ''; ?>>Stock Bajo</option>
                                    <option value="zero" <?php echo $stock_filter === 'zero' ? 'selected' : ''; ?>>Sin Stock</option>
                                    <option value="available" <?php echo $stock_filter === 'available' ? 'selected' : ''; ?>>Disponible</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select class="form-select rounded-pill bg-light border-0" id="status" name="status">
                                    <option value="">Estado: Todos</option>
                                    <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Activo</option>
                                    <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactivo</option>
                                </select>
                            </div>
                            <div class="col-md-1">
                                <button type="submit" class="btn btn-primary rounded-circle shadow-sm" style="width: 38px; height: 38px; padding: 0;">
                                    <i class="fas fa-search"></i>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Lista de Productos -->
                <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                    <div class="card-body p-0">
                        <?php if (empty($products)): ?>
                            <div class="text-center py-5">
                                <div class="rounded-circle bg-light p-4 d-inline-block mb-3">
                                    <i class="fas fa-boxes fa-3x text-muted"></i>
                                </div>
                                <h5 class="text-muted mb-2">No se encontraron productos</h5>
                                <p class="text-muted mb-3">No hay productos que coincidan con los filtros aplicados.</p>
                                <a href="/core/inventory/new.php" class="btn btn-primary rounded-pill px-4" onclick="window.location.href='/core/inventory/new.php'; return false;">
                                    <i class="fas fa-plus me-2"></i>Crear Primer Producto
                                </a>
                            </div>
                        <?php
else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="bg-light text-muted">
                                        <tr>
                                            <th class="ps-4">Producto</th>
                                            <th>Categoría/Marca</th>
                                            <th>Stock</th>
                                            <th>Precio</th>
                                            <th>Estado</th>
                                            <th class="text-end pe-4">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($products as $product): ?>
                                            <tr>
                                                <td class="ps-4">
                                                    <div class="d-flex align-items-center">
                                                        <div class="avatar-circle me-3 bg-light text-primary no-theme rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                                            <i class="fas fa-box text-primary no-theme"></i>
                                                        </div>
                                                        <div>
                                                            <div class="fw-bold text-dark">
                                                                <?php echo htmlspecialchars($product['name']); ?>
                                                            </div>
                                                            <small class="text-muted font-monospace"><?php echo htmlspecialchars($product['internal_code']); ?></small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="text-dark"><?php echo htmlspecialchars($product['category_name']); ?></div>
                                                    <small class="text-muted"><?php echo htmlspecialchars($product['brand_name'] ?: '-'); ?></small>
                                                </td>
                                                <td>
                                                    <?php
        $stock_class = 'text-success';
        $bg_class = 'bg-success bg-opacity-10';
        if ($product['current_stock'] == 0) {
            $stock_class = 'text-danger';
            $bg_class = 'bg-danger bg-opacity-10';
        }
        elseif ($product['current_stock'] <= $product['min_stock']) {
            $stock_class = 'text-warning';
            $bg_class = 'bg-warning bg-opacity-10';
        }
?>
                                                    <span class="badge rounded-pill <?php echo $bg_class . ' ' . $stock_class; ?>">
                                                        <?php echo number_format($product['current_stock'], 0); ?>
                                                    </span>
                                                    <?php if ($product['min_stock'] > 0): ?>
                                                        <div class="small text-muted mt-1">Mín: <?php echo number_format($product['min_stock'], 0); ?></div>
                                                    <?php
        endif; ?>
                                                </td>
                                                <td>
                                                    <div class="fw-bold text-dark">$<?php echo number_format($product['sale_price'], 0, ',', '.'); ?></div>
                                                    <small class="text-muted">Compra: $<?php echo number_format($product['purchase_price'], 0, ',', '.'); ?></small>
                                                </td>
                                                <td>
                                                    <?php if ($product['status'] === 'active'): ?>
                                                        <span class="badge rounded-pill bg-success bg-opacity-10 text-success">Activo</span>
                                                    <?php
        else: ?>
                                                        <span class="badge rounded-pill bg-secondary bg-opacity-10 text-secondary">Inactivo</span>
                                                    <?php
        endif; ?>
                                                </td>
                                                <td class="text-end pe-4">
                                                    <div class="d-flex justify-content-end gap-2">
                                                        <a href="view.php?id=<?php echo $product['id']; ?>" 
                                                           class="btn btn-sm btn-light text-primary shadow-sm" title="Ver">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <a href="edit.php?id=<?php echo $product['id']; ?>" 
                                                           class="btn btn-sm btn-light text-secondary shadow-sm" title="Editar">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php
    endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php
endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/app.js"></script>
    
</body>
</html>
