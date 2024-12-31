<?php
/**
 * Plugin Name: My Webhook User Creator
 * Description: Webhook figyelő plugin, amely új vagy meglévő WordPress felhasználót frissít a bejövő (SalesForm) adatok alapján. Admin felületet is biztosít a statisztikához.
 * Version: 1.0
 * Author: A Te Neved
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Közvetlen hívás megakadályozása
}

/**
 * 0. GLOBÁLIS: létrehozhatunk egy konstanst, ami a plugin végpont URL-jét tárolja.
 *    (A tényleges URL ugyanis domain-alapú, de a WP REST API prefix és route mindig ugyanaz marad.)
 */
define( 'MYWEBHOOK_REST_NAMESPACE', 'mywebhook/v1' );
define( 'MYWEBHOOK_REST_ROUTE', 'create-user' );

/**
 * 1. Létrehozunk egy endpointot (webhook URL):
 *    Pl. domain.tld/wp-json/mywebhook/v1/create-user
 */
add_action( 'rest_api_init', function () {
    register_rest_route( MYWEBHOOK_REST_NAMESPACE, '/' . MYWEBHOOK_REST_ROUTE, [
        'methods'  => 'POST',
        'callback' => 'mywebhook_create_user_callback',
    ] );
} );

/**
 * 2. A callback függvény, mely feldolgozza a bejövő JSON-t,
 *    és elvégzi a felhasználó létrehozását / frissítését.
 */
function mywebhook_create_user_callback( WP_REST_Request $request ) {

    // 2.1. Paraméter kiolvasás:
    $json_data = $request->get_param('data'); 

    if ( empty( $json_data ) ) {
        // Ha nincs "data" paraméter, hibás requestnek tekintjük
        mywebhook_increase_invalid_requests_count();
        return new WP_REST_Response( [ 'error' => 'Missing data parameter.' ], 400 );
    }

    // 2.2. JSON dekódolás
    $data = json_decode( $json_data, true );
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        // Ha nem érvényes JSON
        mywebhook_increase_invalid_requests_count();
        return new WP_REST_Response( [ 'error' => 'Invalid JSON data.' ], 400 );
    }

    // 2.3. status kinyerése
    $status = isset( $data['status'] ) ? $data['status'] : null;
    if ( $status === null ) {
        mywebhook_increase_invalid_requests_count();
        return new WP_REST_Response( [ 'error' => 'Status is missing.' ], 400 );
    }

    // 2.4. email kinyerése
    $email = isset( $data['email'] ) ? sanitize_email( $data['email'] ) : '';
    if ( empty( $email ) ) {
        mywebhook_increase_invalid_requests_count();
        return new WP_REST_Response( [ 'error' => 'Email is missing.' ], 400 );
    }

    // 2.5. név
    $full_name = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';

    // 2.6. következő terhelés időpontja (recdate)
    $next_charge = isset( $data['recdate'] ) ? sanitize_text_field( $data['recdate'] ) : '';

    // 2.7. Megnézzük, van-e már ilyen felhasználó
    $user_id = email_exists( $email );

    // ~~~~~ Logika: status alapján ~~~~~

    // a) Lemondás -> inaktiválás
    if ( $status === 'cancel' ) {
        if ( $user_id ) {
            mywebhook_set_user_inactive( $user_id );
            return new WP_REST_Response( [ 'message' => 'User canceled and set to inactive.' ], 200 );
        } else {
            // Nincs mit inaktiválni
            return new WP_REST_Response( [ 'message' => 'User does not exist, no action taken.' ], 200 );
        }
    }

    // b) Sikertelen fizetés
    if ( $status === false ) {
        // Dönthetjük úgy, hogy nem csinálunk semmit, vagy inaktiválunk, stb.
        if ( $user_id ) {
            // pl. mywebhook_set_user_inactive( $user_id );
        }
        return new WP_REST_Response( [ 'message' => 'Payment not successful or pending.' ], 200 );
    }

    // c) Sikeres fizetés
    if ( $status === true ) {
        
        // Ha nincs user -> létrehozzuk
        if ( ! $user_id ) {
            $random_password = wp_generate_password( 12, false );
            $user_id = wp_create_user( $email, $random_password, $email );
            if ( is_wp_error( $user_id ) ) {
                mywebhook_increase_invalid_requests_count();
                return new WP_REST_Response( [ 'error' => $user_id->get_error_message() ], 400 );
            }

            // Mentjük, hogy ezt a plugintől jött létre
            update_user_meta( $user_id, 'mywebhook_created_by_plugin', 1 );

            // Display name
            wp_update_user( [
                'ID'           => $user_id,
                'display_name' => $full_name,
                'nickname'     => $full_name,
            ] );
        } else {
            // Ha létezik, frissítjük
            wp_update_user( [
                'ID'           => $user_id,
                'display_name' => $full_name ?: $email,
                'nickname'     => $full_name ?: $email,
            ] );
        }

        // Mentjük az előfizetés lejárati dátumát
        if ( $next_charge ) {
            update_user_meta( $user_id, 'mywebhook_expiration', $next_charge );
        }

        // Aktívra állítjuk
        mywebhook_set_user_active( $user_id );

        return new WP_REST_Response( [ 'message' => 'User created/updated successfully.', 'user_id' => $user_id ], 200 );
    }

    // Ha a status valami teljesen más (pl. átutalás?), azt is kezelhetjük:
    mywebhook_increase_invalid_requests_count();
    return new WP_REST_Response( [ 'error' => 'Unknown status value.' ], 400 );
}

/**
 * 3. Felhasználó inaktiválását segítő függvény
 */
function mywebhook_set_user_inactive( $user_id ) {
    $user = get_user_by( 'ID', $user_id );
    if ( $user ) {
        // Minden szerepet törlünk
        foreach ( $user->roles as $role ) {
            $user->remove_role( $role );
        }
        // Opcionálisan hozzáadhatunk egy 'inaktiv' szerepet, ha előtte beregisztráljuk
        // $user->add_role( 'inaktiv' );
    }
}

/**
 * 4. Felhasználó aktiválása
 */
function mywebhook_set_user_active( $user_id ) {
    $user = get_user_by( 'ID', $user_id );
    if ( $user ) {
        // Első körben törlünk minden szerepet
        foreach ( $user->roles as $role ) {
            $user->remove_role( $role );
        }
        // Majd hozzárendeljük pl. a 'subscriber' szerepet
        $user->add_role( 'subscriber' );
    }
}

/**
 * 5. Hibás/érvénytelen requestek számát egy plugin-option-ban tároljuk.
 *    Növeljük a számlálót, ha valami gond van (pl. hiányzó paraméter, hibás JSON).
 */
function mywebhook_increase_invalid_requests_count() {
    $option_key = 'mywebhook_invalid_requests_count';
    $count = (int) get_option( $option_key, 0 );
    $count++;
    update_option( $option_key, $count );
}

/**
 * 6. ADMIN FELÜLET LÉTREHOZÁSA
 *    - Létrehozunk egy menüpontot a vezérlőpultban
 *    - Két fő dolgot jelenítünk meg:
 *      a) A webhook URL kimásolásához egy mezőt.
 *      b) Statisztika (új / aktív / inaktív / hibás).
 */
add_action( 'admin_menu', 'mywebhook_plugin_add_admin_menu' );

function mywebhook_plugin_add_admin_menu() {
    add_menu_page(
        __( 'Webhook Beállítások', 'mywebhook' ),
        __( 'Webhook Plugin', 'mywebhook' ),
        'manage_options',
        'mywebhook-plugin-admin',
        'mywebhook_plugin_admin_page_contents',
        'dashicons-feedback', // vagy valamilyen más dashicon
        99 // Menü pozíció
    );
}

/**
 * 7. Az admin oldal HTML megjelenítése
 */
function mywebhook_plugin_admin_page_contents() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // a) Webhook URL összerakása
    $rest_url = get_site_url( null, 'wp-json/' . MYWEBHOOK_REST_NAMESPACE . '/' . MYWEBHOOK_REST_ROUTE );

    // b) Statisztika:
    //    - Összes olyan user, akinél "mywebhook_created_by_plugin" = 1 -> új felhasználó
    //    - Közülük akiknek van subscriber szerepe -> aktív
    //    - Akiknek nincs szerepe (vagy inaktiv szerepe) -> inaktív
    //    - Hibás requestek száma az options-ben

    $new_users_count  = mywebhook_count_plugin_users();        // összes plugin user
    $active_count     = mywebhook_count_plugin_users( 'active' );
    $inactive_count   = mywebhook_count_plugin_users( 'inactive' );
    $invalid_requests = (int) get_option( 'mywebhook_invalid_requests_count', 0 );

    ?>
    <div class="wrap">
        <h1><?php _e( 'Webhook Plugin Beállítások', 'mywebhook' ); ?></h1>

        <h2><?php _e( 'Webhook URL', 'mywebhook' ); ?></h2>
        <p>
            <?php _e( 'Másold ki ezt az URL-t és illeszd be a SalesForm beállításaiba, hogy a fizetési értesítések ide érkezzenek.', 'mywebhook' ); ?>
        </p>
        <input type="text" readonly style="width: 100%;" value="<?php echo esc_attr( $rest_url ); ?>" />

        <hr />

        <h2><?php _e( 'Statisztika', 'mywebhook' ); ?></h2>
        <table class="widefat" style="max-width:500px;">
            <tbody>
                <tr>
                    <td><strong><?php _e( 'Új felhasználók (akiket a plugin hozott létre)', 'mywebhook' ); ?>:</strong></td>
                    <td><?php echo (int) $new_users_count; ?></td>
                </tr>
                <tr>
                    <td><strong><?php _e( 'Aktív felhasználók (subscriber)', 'mywebhook' ); ?>:</strong></td>
                    <td><?php echo (int) $active_count; ?></td>
                </tr>
                <tr>
                    <td><strong><?php _e( 'Inaktív felhasználók', 'mywebhook' ); ?>:</strong></td>
                    <td><?php echo (int) $inactive_count; ?></td>
                </tr>
                <tr>
                    <td><strong><?php _e( 'Hibás requestek (pl. hiányzó paraméter, hibás JSON)', 'mywebhook' ); ?>:</strong></td>
                    <td><?php echo (int) $invalid_requests; ?></td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php
}

/**
 * 8. Statisztikát segítő függvény
 *    $mode = null => összes plugin user
 *    $mode = 'active' => csak az aktív
 *    $mode = 'inactive' => csak az inaktív
 */
function mywebhook_count_plugin_users( $mode = null ) {
    // WP_User_Query használata
    $args = [
        'meta_key'   => 'mywebhook_created_by_plugin',
        'meta_value' => 1,
        'fields'     => 'ID',
        'number'     => -1,
    ];

    // Először lekérjük az összes plugin-létrehozta usert
    $query = new WP_User_Query( $args );
    $users = $query->get_results();

    if ( empty( $users ) ) {
        return 0;
    }

    // Ha nincs mode, akkor csak visszaadjuk a teljes számot
    if ( $mode === null ) {
        return count( $users );
    }

    $count = 0;
    foreach ( $users as $user_id ) {
        $user = get_user_by( 'ID', $user_id );
        if ( ! $user ) {
            continue;
        }

        $roles = (array) $user->roles;

        if ( $mode === 'active' ) {
            // aktív = van subscriber szerepe
            // (vagy tetszőleges logika: pl. érvényes recdate, stb.)
            if ( in_array( 'subscriber', $roles, true ) ) {
                $count++;
            }
        } elseif ( $mode === 'inactive' ) {
            // inaktív = nincs szerepe, vagy inaktiv szerepe (ha azt használnánk)
            if ( empty( $roles ) || in_array( 'inaktiv', $roles, true ) ) {
                $count++;
            }
        }
    }

    return $count;
}
