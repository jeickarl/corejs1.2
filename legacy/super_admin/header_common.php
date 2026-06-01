<?php
if (!isset($sa_title)) { $sa_title = 'Super Admin'; }
?>
<header class="top-header">
    <div class="header-left">
        <h4 class="page-title mb-0"><?php echo htmlspecialchars($sa_title); ?></h4>
    </div>
    <div class="header-right">
        <div class="user-profile">
            <a href="profile.php" class="text-decoration-none text-dark d-flex align-items-center">
                <div class="avatar-initial bg-dark text-white">SA</div>
                <span class="d-none d-md-block ms-2 fw-medium">Super Admin</span>
            </a>
        </div>
    </div>
</header>
