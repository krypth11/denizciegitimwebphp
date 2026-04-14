<?php
$menuGroups = [
    [
        ['slug' => 'dashboard', 'url' => '/dashboard.php', 'icon' => 'bi-speedometer2', 'label' => 'Dashboard'],
    ],
    [
        ['slug' => 'qualification-headings', 'url' => '/pages/qualification-headings.php', 'icon' => 'bi-diagram-3', 'label' => 'Yeterlilik Başlıkları'],
        ['slug' => 'qualifications', 'url' => '/pages/qualifications.php', 'icon' => 'bi-award', 'label' => 'Yeterlilikler'],
        ['slug' => 'courses', 'url' => '/pages/courses.php', 'icon' => 'bi-book', 'label' => 'Dersler'],
        ['slug' => 'topics', 'url' => '/pages/topics.php', 'icon' => 'bi-diagram-3', 'label' => 'Konular'],
        ['slug' => 'questions', 'url' => '/pages/questions.php', 'icon' => 'bi-question-circle', 'label' => 'Sorular'],
        ['slug' => 'questions-export', 'url' => '/pages/questions-export.php', 'icon' => 'bi-file-earmark-arrow-down', 'label' => 'Soru Dışa Aktar'],
        ['slug' => 'word-game-questions', 'url' => '/pages/word-game-questions.php', 'icon' => 'bi-controller', 'label' => 'Kelime Oyunu'],
        ['slug' => 'ai-question-review', 'url' => '/pages/ai-question-review.php', 'icon' => 'bi-robot', 'label' => 'AI Soru Kontrol'],
    ],
    [
        ['slug' => 'maritime-english', 'url' => '/pages/maritime-english.php', 'icon' => 'bi-translate', 'label' => 'Maritime English'],
        ['slug' => 'me-questions', 'url' => '/pages/me-questions.php', 'icon' => 'bi-chat-square-text', 'label' => 'ME Sorular'],
        ['slug' => 'maritime-signals', 'url' => '/pages/maritime-signals.php', 'icon' => 'bi-flag', 'label' => 'İşaretler'],
    ],
    [
        ['slug' => 'users', 'url' => '/pages/users.php', 'icon' => 'bi-people', 'label' => 'Kullanıcılar'],
        ['slug' => 'stories', 'url' => '/pages/stories.php', 'icon' => 'bi-images', 'label' => 'Dashboard Hikayeleri'],
        ['slug' => 'notifications-create', 'url' => '/pages/notifications-create.php', 'icon' => 'bi-bell', 'label' => 'Yeni Bildirim'],
        ['slug' => 'notifications-history', 'url' => '/pages/notifications-history.php', 'icon' => 'bi-clock-history', 'label' => 'Gönderim Geçmişi'],
        ['slug' => 'notifications-tokens', 'url' => '/pages/notifications-tokens.php', 'icon' => 'bi-phone', 'label' => 'Tokenlar'],
        ['slug' => 'notifications-rules', 'url' => '/pages/notifications-rules.php', 'icon' => 'bi-sliders', 'label' => 'Kurallar'],
        ['slug' => 'notifications-stats', 'url' => '/pages/notifications-stats.php', 'icon' => 'bi-graph-up-arrow', 'label' => 'İstatistikler'],
        ['slug' => 'community-rooms', 'url' => '/pages/community-rooms.php', 'icon' => 'bi-chat-dots', 'label' => 'Topluluk Odaları'],
        ['slug' => 'community-messages', 'url' => '/pages/community-messages.php', 'icon' => 'bi-chat-left-text', 'label' => 'Topluluk Mesajları'],
        ['slug' => 'community-reports', 'url' => '/pages/community-reports.php', 'icon' => 'bi-flag', 'label' => 'Raporlanan Mesajlar'],
        ['slug' => 'community-blacklist', 'url' => '/pages/community-blacklist.php', 'icon' => 'bi-slash-circle', 'label' => 'Blacklist Kelimeler'],
        ['slug' => 'settings', 'url' => '/pages/settings.php', 'icon' => 'bi-gear', 'label' => 'Ayarlar'],
    ],
];

function is_notification_menu_item(array $item): bool
{
    return str_starts_with((string)($item['slug'] ?? ''), 'notifications-');
}

function render_sidebar_link(array $item, string $current_page, string $extraClass = ''): void
{
    $isActive = $current_page === (string)$item['slug'] ? 'active' : '';
    echo '<a class="nav-link ' . $isActive . $extraClass . '" href="' . $item['url'] . '">';
    echo '<i class="bi ' . $item['icon'] . '"></i><span>' . htmlspecialchars($item['label']) . '</span>';
    echo '</a>';
}

function render_notification_submenu(array $notificationItems, string $current_page, string $instanceKey): void
{
    if (empty($notificationItems)) {
        return;
    }

    $submenuId = 'notificationsSubmenu-' . $instanceKey;
    $isOpen = str_starts_with($current_page, 'notifications-');
    $toggleClass = $isOpen ? '' : ' collapsed';
    $showClass = $isOpen ? ' show' : '';
    $parentActiveClass = $isOpen ? ' active' : '';

    echo '<a class="nav-link submenu-toggle' . $toggleClass . $parentActiveClass . '" data-bs-toggle="collapse" href="#' . $submenuId . '" role="button" aria-expanded="' . ($isOpen ? 'true' : 'false') . '" aria-controls="' . $submenuId . '">';
    echo '<i class="bi bi-bell"></i><span>Bildirim Yönetimi</span><i class="bi bi-chevron-down submenu-chevron ms-auto"></i>';
    echo '</a>';

    echo '<div class="collapse submenu-container' . $showClass . '" id="' . $submenuId . '">';
    foreach ($notificationItems as $item) {
        render_sidebar_link($item, $current_page, ' submenu-item');
    }
    echo '</div>';
}

function render_sidebar_menu($menuGroups, $current_page, $instanceKey = 'default')
{
    foreach ($menuGroups as $groupIndex => $group) {
        $notificationItems = array_values(array_filter($group, 'is_notification_menu_item'));
        $notificationRendered = false;

        foreach ($group as $item) {
            if (is_notification_menu_item($item)) {
                if (!$notificationRendered) {
                    render_notification_submenu($notificationItems, (string)$current_page, (string)$instanceKey . '-' . $groupIndex);
                    $notificationRendered = true;
                }
                continue;
            }

            render_sidebar_link($item, (string)$current_page);
        }

        if ($groupIndex < count($menuGroups) - 1) {
            echo '<hr class="my-2 mx-2">';
        }
    }
}
?>

<aside class="sidebar d-none d-lg-flex">
    <div class="sidebar-header">
        <h4><i class="bi bi-mortarboard-fill"></i> Denizci Eğitim</h4>
    </div>
    <nav class="nav flex-column">
        <?php render_sidebar_menu($menuGroups, $current_page ?? '', 'desktop'); ?>
    </nav>
    <div class="sidebar-footer">
        <div class="user-info">
            <i class="bi bi-person-circle"></i>
            <small class="d-block"><?= htmlspecialchars($user['email']) ?></small>
        </div>
        <a href="/logout.php" class="btn btn-danger w-100 btn-sm">
            <i class="bi bi-box-arrow-left"></i> Çıkış Yap
        </a>
    </div>
</aside>

<div class="offcanvas offcanvas-start d-lg-none" tabindex="-1" id="adminSidebar" aria-labelledby="adminSidebarLabel">
    <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title" id="adminSidebarLabel">Denizci Eğitim</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body p-0">
        <div class="sidebar-header py-3">
            <h4><i class="bi bi-mortarboard-fill"></i> Menü</h4>
        </div>
        <nav class="nav flex-column">
            <?php render_sidebar_menu($menuGroups, $current_page ?? '', 'mobile'); ?>
        </nav>
        <div class="sidebar-footer">
            <a href="/logout.php" class="btn btn-danger w-100 btn-sm">
                <i class="bi bi-box-arrow-left"></i> Çıkış Yap
            </a>
        </div>
    </div>
</div>

<main class="main-content">
    <div class="mobile-menu-row d-lg-none mb-3">
        <button class="btn btn-secondary btn-sm" type="button" data-bs-toggle="offcanvas" data-bs-target="#adminSidebar" aria-controls="adminSidebar">
            <i class="bi bi-list"></i> Menü
        </button>
    </div>















