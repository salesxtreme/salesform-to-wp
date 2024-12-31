/**
 * 1) Napi időzített esemény (cron) regisztrálása
 */
function mywebhook_schedule_daily_event() {
    // Ha még nincs ütemezve a napi futás, hozzuk létre
    if ( ! wp_next_scheduled( 'mywebhook_daily_event' ) ) {
        wp_schedule_event( time(), 'daily', 'mywebhook_daily_event' );
    }
}

/**
 * 2) A napi eseményhez tartozó callback, amely inaktiválja a lejárt előfizetésű felhasználókat
 */
function mywebhook_cron_deactivate_expired_users() {
    // Tegyük fel, a lejárati dátumot a "mywebhook_expiration" user_meta mezőben tároljuk (pl. "YYYY-MM-DD" formátumban)
    $today = current_time( 'Y-m-d' );

    // Keressük azokat a felhasználókat, akiknek "mywebhook_expiration" korábbi, mint a mai nap
    $args = [
        'meta_key'     => 'mywebhook_expiration',
        'meta_value'   => $today,
        'meta_compare' => '<',
        'fields'       => 'ID',
        'number'       => -1,
    ];

    $query = new WP_User_Query( $args );
    $users = $query->get_results();

    if ( ! empty( $users ) ) {
        foreach ( $users as $user_id ) {
            // Korábban definiált függvény, amely kiveszi a szerepköröket, ezzel inaktiválva a felhasználót
            mywebhook_set_user_inactive( $user_id );
        }
    }
}
add_action( 'mywebhook_daily_event', 'mywebhook_cron_deactivate_expired_users' );

/**
 * 3) Aktiváláskor létrehozzuk a napi cron ütemezést
 */
register_activation_hook( __FILE__, 'mywebhook_schedule_daily_event' );

/**
 * 4) Kikapcsoláskor töröljük a napi cron ütemezést
 */
function mywebhook_unschedule_daily_event() {
    $timestamp = wp_next_scheduled( 'mywebhook_daily_event' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'mywebhook_daily_event' );
    }
}
register_deactivation_hook( __FILE__, 'mywebhook_unschedule_daily_event' );
