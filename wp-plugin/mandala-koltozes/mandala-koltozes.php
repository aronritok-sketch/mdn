<?php
/**
 * Plugin Name: Mandala költöztető segéd (a RÉGI boltba)
 * Description: Csak a költözés idejére, a régi webáruházba. A „Régi bolt adatai” exportnak kiadja a felhasználók jelszavának titkosított lenyomatát (hash), így a vásárlók az új boltban is a régi jelszavukkal léphetnek be. Csak adminisztrátor érheti el, semmit nem módosít. A költözés után kapcsold ki és töröld.
 * Version: 1.0.0
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * Author: HelloProVision
 * Text Domain: mandala-koltozes
 */

defined('ABSPATH') || exit;

add_action('rest_api_init', function () {
    register_rest_route('mandala-migrate/v1', '/users', [
        'methods' => 'GET',
        // Csak adminisztrátor (a boltkezelő nem): a jelszó-lenyomat érzékeny adat
        'permission_callback' => function () {
            return current_user_can('manage_options') && current_user_can('list_users');
        },
        'args' => [
            'page' => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
            'per_page' => ['type' => 'integer', 'default' => 200, 'minimum' => 1, 'maximum' => 500],
        ],
        'callback' => function (WP_REST_Request $request) {
            global $wpdb;
            $per = (int) $request['per_page'];
            $page = (int) $request['page'];
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}");
            $rows = $wpdb->get_results($wpdb->prepare("SELECT ID, user_email, user_pass FROM {$wpdb->users} ORDER BY ID LIMIT %d OFFSET %d", $per, ($page - 1) * $per), ARRAY_A);
            $response = rest_ensure_response(array_map(function ($u) {
                return ['id' => (int) $u['ID'], 'email' => (string) $u['user_email'], 'pass' => (string) $u['user_pass']];
            }, (array) $rows));
            $response->header('X-WP-Total', (string) $total);
            $response->header('X-WP-TotalPages', (string) max(1, (int) ceil($total / $per)));
            $response->header('Cache-Control', 'no-store, private');
            return $response;
        },
    ]);
});

add_action('admin_notices', function () {
    if (current_user_can('manage_options')) {
        echo '<div class="notice notice-warning"><p><strong>Mandala költöztető segéd aktív.</strong> Az adat-export a vásárlók jelszó-lenyomatát is átviszi az új boltba. A költözés után kapcsold ki és töröld ezt a bővítményt (Bővítmények).</p></div>';
    }
});
