<?php

require_once dirname(__DIR__) . '/api_bootstrap.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__, 3) . '/includes/user_lifecycle_helper.php';
require_once dirname(__DIR__, 3) . '/includes/qualification_change_credit_helper.php';

api_require_method('PUT');

try {
    $auth = api_require_auth($pdo);
    $userId = (string)$auth['user']['id'];

    $payload = api_get_request_data();
    $qualificationId = trim((string)($payload['current_qualification_id'] ?? ''));

    if ($qualificationId === '') {
        api_error('current_qualification_id alanı zorunludur.', 422);
    }

    if (!api_qualification_exists($pdo, $qualificationId)) {
        api_error('Geçersiz qualification id.', 422);
    }

    $profileSchema = api_get_profile_schema($pdo);
    if (!$profileSchema['current_qualification_id']) {
        api_error('current_qualification_id alanı bu sistemde desteklenmiyor.', 400);
    }

    $beforeProfile = api_find_profile_by_user_id($pdo, $userId);
    $oldQualificationId = (string)($beforeProfile['current_qualification_id'] ?? '');

    if ($oldQualificationId !== '' && $oldQualificationId !== $qualificationId) {
        qualification_change_apply_annual_grant($pdo, $userId);
        $status = qualification_change_get_status($pdo, $userId);
        if ((int)($status['credits'] ?? 0) <= 0) {
            api_send_json([
                'success' => false,
                'message' => 'Yeterlilik değiştirme hakkınız bulunmuyor. Değişiklik için destek talebi oluşturabilirsiniz.',
                'data' => [
                    'code' => 'QUALIFICATION_CHANGE_CREDIT_REQUIRED',
                    'qualification_change_status' => $status,
                ],
            ], 403);
        }
    }

    $updates = [
        $profileSchema['current_qualification_id'] => $qualificationId,
    ];

    if ($profileSchema['onboarding_completed']) {
        $updates[$profileSchema['onboarding_completed']] = 1;
    }

    if ($oldQualificationId === $qualificationId) {
        $profile = api_find_profile_by_user_id($pdo, $userId);
        if (!$profile) {
            api_error('Profil bulunamadı.', 404);
        }
        $profile['qualification_change_status'] = qualification_change_get_status($pdo, $userId);
        api_success('Current qualification güncellendi.', ['profile' => $profile]);
    }

    if ($oldQualificationId === '') {
        api_update_profile_fields($pdo, $userId, $updates);
    } else {
        $pdo->beginTransaction();
        try {
            qualification_change_consume($pdo, $userId, $oldQualificationId, $qualificationId, 'profile.current-qualification');
            api_update_profile_fields($pdo, $userId, $updates);
            user_lifecycle_log_event(
                $pdo,
                $userId,
                'qualification_changed',
                'Mevcut yeterlilik değişti',
                'profile.current_qualification',
                ($oldQualificationId !== '' ? $oldQualificationId : null),
                $qualificationId,
                ['context' => 'profile.current-qualification'],
                0
            );
            $pdo->commit();
        } catch (Throwable $txe) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $txe;
        }
    }

    $resolvedQualificationId = get_current_user_qualification_id($pdo, $userId);
    api_qualification_access_log('profile current qualification updated to', [
        'context' => 'profile.current-qualification',
        'user_id' => $userId,
        'profile current qualification updated to' => $resolvedQualificationId,
    ]);

    $profile = api_find_profile_by_user_id($pdo, $userId);
    if (!$profile) {
        api_error('Profil bulunamadı.', 404);
    }

    $profile['qualification_change_status'] = qualification_change_get_status($pdo, $userId);

    api_success('Current qualification güncellendi.', [
        'profile' => $profile,
    ]);
} catch (Throwable $e) {
    api_error('İşlem sırasında bir sunucu hatası oluştu.', 500);
}
