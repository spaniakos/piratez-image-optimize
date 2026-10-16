<?php
/** Bundled menu integration. No required Manager/theme/shared-plugin dependency. */
namespace PirateZ;
if (!defined('ABSPATH')) { exit; }
if (!class_exists(__NAMESPACE__ . '\\AdminMenu', false)) {
    class AdminMenu {
        const SLUG = 'piratez-manager';
        private static $booted = false;
        private static $manager_parent = false;
        public static function boot() {
            if (self::$booted) { return; }
            self::$booted = true;
            // Manager 1.0 registers its parent at priority 10. Reuse it if present.
            add_action('admin_menu', array(__CLASS__, 'ensure'), 15);
            add_action('admin_menu', array(__CLASS__, 'label'), 99);
        }
        public static function ensure() {
            global $menu;
            foreach ((array) $menu as $item) {
                if (($item[2] ?? '') === self::SLUG) { self::$manager_parent = true; return; }
            }
            add_menu_page('PirateZ', 'PirateZ', 'manage_options', self::SLUG, array(__CLASS__, 'render'), 'dashicons-admin-plugins');
        }
        public static function label() {
            global $submenu;
            if (!isset($submenu[self::SLUG])) { return; }
            foreach ($submenu[self::SLUG] as &$item) {
                if (($item[2] ?? '') === self::SLUG) { $item[0] = self::$manager_parent ? 'Modules' : 'Overview'; }
            }
            unset($item);
        }
        public static function render() {
            if (!current_user_can('manage_options')) { return; }
            global $submenu;
            echo '<div class="wrap"><h1>PirateZ</h1><p>Choose an installed component below. Each component works and updates independently.</p><ul>';
            foreach (($submenu[self::SLUG] ?? array()) as $item) {
                if ($item[2] === self::SLUG || !current_user_can($item[1])) { continue; }
                echo '<li><a href="' . esc_url(admin_url('admin.php?page=' . $item[2])) . '">' . esc_html(wp_strip_all_tags($item[0])) . '</a></li>';
            }
            echo '</ul></div>';
        }
        public static function redirect_legacy($slug) {
            if (($GLOBALS['pagenow'] ?? '') === 'tools.php' && ($_GET['page'] ?? '') === $slug && current_user_can('manage_options')) {
                wp_safe_redirect(admin_url('admin.php?page=' . $slug)); exit;
            }
        }
    }
}
AdminMenu::boot();
