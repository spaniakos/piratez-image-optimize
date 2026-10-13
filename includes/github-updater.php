<?php
namespace PirateZImageOptimize;
/** Public GitHub releases through native WordPress update/upgrade APIs. */
if (!defined('ABSPATH')) { exit; }

class GitHubUpdater {
    public $config;
    private $verified = array();

    public function __construct(array $config) {
        $this->config = $config;
        add_filter('update_' . ($config['type'] === 'theme' ? 'themes' : 'plugins') . '_github.com', array($this, 'update'), 10, 4);
        add_filter('upgrader_pre_download', array($this, 'download'), 10, 4);
        add_filter('upgrader_source_selection', array($this, 'source'), 20, 4);
        add_action('upgrader_process_complete', array($this, 'clear'), 10, 0);
        if ($config['type'] === 'plugin') { add_filter('plugins_api', array($this, 'info'), 20, 3); }
    }

    public function cache_key() { return 'piratez_release_' . md5($this->config['repo']); }
    public function clear() { delete_site_transient($this->cache_key()); }
    public function uri() { return 'https://github.com/' . $this->config['repo']; }

    private function json($url) {
        $response = wp_safe_remote_get($url, array('timeout' => 15, 'limit_response_size' => 262144, 'headers' => array('Accept' => 'application/json', 'User-Agent' => 'PirateZ-WordPress-Updater')));
        if (is_wp_error($response)) { return $response; }
        $status = wp_remote_retrieve_response_code($response);
        if ($status !== 200) { return new \WP_Error('piratez_release_http', $status === 404 ? 'No stable GitHub release has been published yet.' : 'GitHub release check failed (HTTP ' . absint($status) . ').'); }
        $json = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($json) ? $json : new \WP_Error('piratez_release_json', 'Invalid release metadata.');
    }

    /** Exact repository/tag/asset URLs only; no arbitrary administrator URLs. */
    private function asset($release, $name, $tag) {
        $expected = $this->uri() . '/releases/download/' . rawurlencode($tag) . '/' . rawurlencode($name);
        if (!isset($release['assets']) || !is_array($release['assets'])) { return false; }
        foreach ($release['assets'] as $asset) {
            if (!is_array($asset)) { continue; }
            if (($asset['name'] ?? '') === $name && ($asset['browser_download_url'] ?? '') === $expected && ($asset['state'] ?? 'uploaded') === 'uploaded') { return $asset; }
        }
        return false;
    }

    public function release($force = false) {
        if ($force) { $this->clear(); }
        $cached = get_site_transient($this->cache_key());
        if ($cached !== false) { return $cached; }
        $result = $this->fetch_release();
        set_site_transient($this->cache_key(), $result, is_wp_error($result) ? 15 * MINUTE_IN_SECONDS : 6 * HOUR_IN_SECONDS);
        return $result;
    }

    private function fetch_release() {
        $release = $this->json('https://api.github.com/repos/' . $this->config['repo'] . '/releases/latest');
        if (is_wp_error($release)) { return $release; }
        $tag = $release['tag_name'] ?? '';
        if (!empty($release['draft']) || !empty($release['prerelease']) || !is_string($tag) || !preg_match('/^v?(\d+\.\d+\.\d+)$/D', $tag, $match)) { return new \WP_Error('piratez_release_version', 'Only stable semantic-version releases are supported.'); }
        $package = $this->asset($release, $this->config['slug'] . '.zip', $tag);
        $manifest = $this->asset($release, 'release.json', $tag);
        if (!$package || !$manifest) { return new \WP_Error('piratez_release_assets', 'The release is missing its installable ZIP or release manifest.'); }
        $data = $this->json($manifest['browser_download_url']);
        if (is_wp_error($data)) { return $data; }
        if (($data['schema_version'] ?? 0) !== 1 || ($data['slug'] ?? '') !== $this->config['slug'] || ($data['type'] ?? '') !== $this->config['type'] || ($data['version'] ?? '') !== $match[1] || ($data['asset'] ?? '') !== $this->config['slug'] . '.zip' || !is_string($data['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $data['sha256'])) { return new \WP_Error('piratez_release_manifest', 'The release manifest does not match this component.'); }
        foreach (array('requires', 'requires_php', 'tested') as $field) {
            if (!is_string($data[$field] ?? null) || !preg_match('/^\d+\.\d+(?:\.\d+)?$/D', $data[$field])) { return new \WP_Error('piratez_release_requirements', 'Invalid release requirements.'); }
        }
        if (!empty($package['digest']) && $package['digest'] !== 'sha256:' . $data['sha256']) { return new \WP_Error('piratez_release_digest', 'GitHub and manifest checksums disagree.'); }
        $data['package'] = $package['browser_download_url'];
        $data['url'] = $this->uri() . '/releases/tag/' . rawurlencode($tag);
        $data['notes'] = is_string($release['body'] ?? null) ? $release['body'] : '';
        return $data;
    }

    public function compatible($release) {
        global $wp_version;
        return !is_wp_error($release) && version_compare($wp_version, $release['requires'], '>=') && version_compare(PHP_VERSION, $release['requires_php'], '>=');
    }

    public function update($original, $headers, $key, $locales) {
        if (rtrim($headers['UpdateURI'] ?? '', '/') !== $this->uri()) { return $original; }
        if ($this->config['type'] === 'theme' && $key !== $this->config['key']) { return $original; }
        $this->config['key'] = $key; // Also support an existing renamed plugin folder.
        $release = $this->release();
        if (is_wp_error($release)) { return $original; }
        return array('id' => $this->uri(), 'slug' => $this->config['slug'], 'theme' => $key, 'version' => $release['version'], 'url' => $release['url'], 'package' => $release['package'], 'requires' => $release['requires'], 'requires_php' => $release['requires_php'], 'tested' => $release['tested']);
    }

    /** Verify a trusted release ZIP before allowing native installation/update. */
    public function download($reply, $package, $upgrader, $extra) {
        if (is_wp_error($reply)) { return $reply; }
        $prefix = $this->uri() . '/releases/download/';
        $ours = ($extra[$this->config['type']] ?? '') === $this->config['key'];
        if (strpos((string) $package, $prefix) !== 0) {
            return $ours ? new \WP_Error('piratez_package_origin', 'This component must be updated from its own GitHub release.') : $reply;
        }
        $release = $this->release();
        if (is_wp_error($release)) { return $release; }
        if ($package !== $release['package'] || !$this->compatible($release)) { return new \WP_Error('piratez_package_unsupported', 'The selected release is unavailable or incompatible with this WordPress/PHP version.'); }
        $identity = spl_object_hash($upgrader);
        if (is_string($reply) && isset($GLOBALS['piratez_verified_downloads'][$identity][$this->config['repo']]) && $GLOBALS['piratez_verified_downloads'][$identity][$this->config['repo']] === $reply) { return $reply; }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $owned = $reply === false;
        $file = $owned ? download_url($package, 120) : $reply;
        if (!is_string($file) && !is_wp_error($file)) { return new \WP_Error('piratez_package_download', 'Invalid package download result.'); }
        if (is_wp_error($file)) { return $file; }
        if (!is_file($file) || !is_readable($file) || !hash_equals($release['sha256'], (string) hash_file('sha256', $file))) { if ($owned) { @unlink($file); } return new \WP_Error('piratez_package_checksum', 'The downloaded ZIP failed its SHA-256 check.'); }
        $this->verified[$identity] = $release;
        $GLOBALS['piratez_verified_downloads'][$identity][$this->config['repo']] = $file;
        return $file;
    }

    public function source($source, $remote, $upgrader, $extra) {
        $identity = spl_object_hash($upgrader);
        if (!isset($this->verified[$identity]) || is_wp_error($source)) { return $source; }
        $release = $this->verified[$identity]; unset($this->verified[$identity]);
        $entry = $this->config['type'] === 'theme' ? 'style.css' : $this->config['entry'];
        global $wp_filesystem;
        if (basename(untrailingslashit($source)) !== $this->config['slug'] || !$wp_filesystem->exists($source . $entry)) { return new \WP_Error('piratez_package_structure', 'The release ZIP has an unexpected directory or entry file.'); }
        $contents = $wp_filesystem->get_contents($source . $entry);
        $headers = array();
        foreach (array('Version' => 'Version', 'UpdateURI' => 'Update URI') as $key => $label) {
            preg_match('/^[ \t\/*#@]*' . preg_quote($label, '/') . ':[ \t]*(.+)$/mi', str_replace("\r", "\n", (string) $contents), $match);
            $headers[$key] = isset($match[1]) ? trim(preg_replace('/\s*(?:\*\/|\?>).*/', '', $match[1])) : '';
        }
        if ($headers['Version'] !== $release['version'] || rtrim($headers['UpdateURI'], '/') !== $this->uri()) { return new \WP_Error('piratez_package_identity', 'Package headers do not match the release.'); }
        $updating = ($extra['action'] ?? '') === 'update' || ($extra[$this->config['type']] ?? '') === $this->config['key'];
        $target = $this->config['type'] === 'theme' ? $this->config['key'] : dirname($this->config['key']);
        if ($updating && $target !== $this->config['slug']) {
            global $wp_filesystem;
            $destination = trailingslashit($remote) . $target;
            if (!$wp_filesystem->move(untrailingslashit($source), $destination, false)) { return new \WP_Error('piratez_package_directory', 'Unable to preserve the existing installation directory.'); }
            return trailingslashit($destination);
        }
        return $source;
    }

    public function info($original, $action, $args) {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== $this->config['slug']) { return $original; }
        $release = $this->release(); if (is_wp_error($release)) { return $release; }
        return (object) array('name' => $this->config['name'], 'slug' => $this->config['slug'], 'version' => $release['version'], 'author' => 'spaniakos', 'homepage' => $this->uri(), 'requires' => $release['requires'], 'requires_php' => $release['requires_php'], 'tested' => $release['tested'], 'download_link' => $release['package'], 'sections' => array('description' => esc_html($this->config['name']), 'changelog' => '<pre>' . esc_html($release['notes']) . '</pre>'));
    }
}
