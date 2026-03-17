    <!-- Sidebar -->
    <div class="sidebar">
        <!-- Sidebar Header -->
        <div class="sidebar-header">
            <h4><i class="bi bi-mortarboard-fill"></i> Denizci Eğitim</h4>
        </div>

        <!-- Navigation -->
        <nav class="nav flex-column mt-3" style="padding-bottom: 180px;">
            <a class="nav-link <?= ($current_page ?? '') === 'dashboard' ? 'active' : '' ?>" href="/dashboard.php">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>

            <hr class="my-2 mx-3">

            <a class="nav-link <?= ($current_page ?? '') === 'qualifications' ? 'active' : '' ?>" href="/pages/qualifications.php">
                <i class="bi bi-award"></i> Yeterlilikler
            </a>

            <a class="nav-link <?= ($current_page ?? '') === 'courses' ? 'active' : '' ?>" href="/pages/courses.php">
                <i class="bi bi-book"></i> Dersler
            </a>

            <a class="nav-link <?= ($current_page ?? '') === 'questions' ? 'active' : '' ?>" href="/pages/questions.php">
                <i class="bi bi-question-circle"></i> Sorular
            </a>

            <hr class="my-2 mx-3">

            <a class="nav-link <?= ($current_page ?? '') === 'maritime-english' ? 'active' : '' ?>" href="/pages/maritime-english.php">
                <i class="bi bi-translate"></i> Maritime English
            </a>

            <a class="nav-link <?= ($current_page ?? '') === 'me-questions' ? 'active' : '' ?>" href="/pages/me-questions.php">
                <i class="bi bi-chat-square-text"></i> ME Sorular
            </a>

            <a class="nav-link <?= ($current_page ?? '') === 'maritime-signals' ? 'active' : '' ?>" href="/pages/maritime-signals.php">
                <i class="bi bi-flag"></i> İşaretler
            </a>

            <hr class="my-2 mx-3">

            <a class="nav-link <?= ($current_page ?? '') === 'users' ? 'active' : '' ?>" href="/pages/users.php">
                <i class="bi bi-people"></i> Kullanıcılar
            </a>

            <a class="nav-link <?= ($current_page ?? '') === 'settings' ? 'active' : '' ?>" href="/pages/settings.php">
                <i class="bi bi-gear"></i> Ayarlar
            </a>
        </nav>

        <!-- Sidebar Footer -->
        <div class="sidebar-footer">
            <div class="user-info">
                <i class="bi bi-person-circle"></i>
                <small class="d-block"><?= htmlspecialchars($user['email']) ?></small>
            </div>
            <a href="/logout.php" class="btn btn-danger w-100 btn-sm">
                <i class="bi bi-box-arrow-left"></i> Çıkış Yap
            </a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">



