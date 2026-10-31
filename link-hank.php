<?php
/**
 * Plugin Name: 链接通讯接口
 * Description: 为您方便的提供WordPress中您保存的链接信息，此插件由Hank[谶航辰]开发，不收费！不收费！如遇到收费被骗请自行负责！
 * Version: 1.3
 * Author: Hank[谶航辰]
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

class LinkCommInterface {

    private static $instance = null;
    private $option_key = 'link_comm_interface_settings';
    private $redis = null;
    private $cache_key_prefix = 'link_comm_interface_';

    private function __construct() {
        add_action('rest_api_init', [$this, 'register_interface_endpoint']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        
        register_activation_hook(__FILE__, [$this, 'activate_plugin']);
        
        add_action('wp_ajax_clear_redis_cache', [$this, 'ajax_clear_redis_cache']);
        add_action('wp_ajax_export_config', [$this, 'ajax_export_config']);
        add_action('wp_ajax_import_config', [$this, 'ajax_import_config']);
        
        $this->init_redis();
    }

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 初始化Redis连接
     */
    private function init_redis() {
        $settings = get_option($this->option_key, []);
        if (!empty($settings['redis_enabled']) && $settings['redis_enabled'] === 'on' && class_exists('Redis')) {
            try {
                $this->redis = new Redis();
                $this->redis->connect('127.0.0.1', 6379);
            } catch (Exception $e) {
                $this->redis = null;
            }
        }
    }

    /**
     * 加载页面资源
     */
    public function enqueue_admin_assets($hook) {
        if ($hook !== 'settings_page_link-comm-interface-settings') {
            return;
        }
        
        wp_enqueue_style(
            'link-comm-interface-admin',
            plugin_dir_url(__FILE__) . 'material/index/css/index.css',
            [],
            '1.3'
        );
        
        wp_enqueue_script(
            'link-comm-interface-admin',
            plugin_dir_url(__FILE__) . 'material/index/js/index.js',
            ['jquery'],
            '1.3',
            true
        );
        
        wp_localize_script('link-comm-interface-admin', 'linkCommAjax', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('link_comm_interface_nonce')
        ]);
    }
    
    public function activate_plugin() {
        $settings = get_option($this->option_key, []);
        if (empty($settings['comm_key'])) {
            $settings['comm_key'] = $this->generate_comm_key();
            $settings['status'] = 'on';
            $settings['selected_categories'] = [];
            $settings['tdk_enabled'] = 'off';
            $settings['whitelist_enabled'] = 'off';
            $settings['whitelist_ips'] = '';
            $settings['redis_enabled'] = 'off';
            $settings['redis_ttl'] = 10;
            update_option($this->option_key, $settings);
        }
    }

    private function generate_comm_key($length = 32) {
        return bin2hex(random_bytes($length));
    }

    public function register_interface_endpoint() {
        register_rest_route('linkinterface/v1', '/get-links', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_interface_request'],
            'permission_callback' => [$this, 'verify_comm_permission']
        ]);
    }

    /**
     * 验证IP白名单
     */
    private function verify_ip_whitelist($client_ip) {
        $settings = get_option($this->option_key, []);
        
        if (empty($settings['whitelist_enabled']) || $settings['whitelist_enabled'] !== 'on') {
            return true;
        }
        
        $whitelist_ips = isset($settings['whitelist_ips']) ? trim($settings['whitelist_ips']) : '';
        if (empty($whitelist_ips)) {
            return false;
        }
        
        $allowed_ips = explode("\n", str_replace("\r", "", $whitelist_ips));
        $allowed_ips = array_map('trim', $allowed_ips);
        $allowed_ips = array_filter($allowed_ips);
        
        foreach ($allowed_ips as $allowed_ip) {
            if ($this->ip_in_range($client_ip, $allowed_ip)) {
                return true;
            }
        }
        
        return false;
    }

    private function ip_in_range($ip, $range) {
    
        if ($ip === $range) {
            return true;
        }
        
        if (strpos($range, '/') !== false) {
            list($subnet, $bits) = explode('/', $range);
            
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ip_long = ip2long($ip);
                $subnet_long = ip2long($subnet);
                if ($ip_long === false || $subnet_long === false) {
                    return false;
                }
                
                $mask = -1 << (32 - $bits);
                $subnet_long &= $mask;
                
                return ($ip_long & $mask) === $subnet_long;
            }
            
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $ip_bin = inet_pton($ip);
                $subnet_bin = inet_pton($subnet);
                if ($ip_bin === false || $subnet_bin === false) {
                    return false;
                }
                
                $bytes = (int)($bits / 8);
                $bits_remainder = $bits % 8;
                
                if ($bytes > 0) {
                    if (substr($ip_bin, 0, $bytes) !== substr($subnet_bin, 0, $bytes)) {
                        return false;
                    }
                }
                
                if ($bits_remainder > 0) {
                    $mask = 0xFF << (8 - $bits_remainder);
                    if ((ord($ip_bin[$bytes]) & $mask) !== (ord($subnet_bin[$bytes]) & $mask)) {
                        return false;
                    }
                }
                
                return true;
            }
        }
        
        return false;
    }

    private function get_client_ip() {
        $headers = [
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CF_CONNECTING_IP',
            'REMOTE_ADDR'
        ];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public function verify_comm_permission($request) {
        $settings = get_option($this->option_key, []);
        
        // 是否启用
        if (empty($settings['status']) || $settings['status'] !== 'on') {
            return new WP_Error('interface_disabled', '通讯接口已停用', ['status' => 403]);
        }
        
        // 验证IP白名单
        $client_ip = $this->get_client_ip();
        if (!$this->verify_ip_whitelist($client_ip)) {
            return new WP_Error('ip_not_allowed', 'IP地址不在白名单中', ['status' => 403]);
        }
        
        $comm_key = $request->get_header('X-Comm-Key');
        if (empty($comm_key) || $comm_key !== $settings['comm_key']) {
            return new WP_Error('invalid_comm_key', '无效的通讯密钥', ['status' => 401]);
        }
        
        return true;
    }

    private function get_cache_key($selected_categories, $tdk_enabled) {
        $key = $this->cache_key_prefix . 'links_' . md5(serialize($selected_categories) . '_' . $tdk_enabled);
        return $key;
    }

    private function get_cached_links($cache_key) {
        if ($this->redis === null) {
            return false;
        }
        
        try {
            $cached = $this->redis->get($cache_key);
            if ($cached !== false) {
                return json_decode($cached, true);
            }
        } catch (Exception $e) {
        
        }
        
        return false;
    }

    private function set_cached_links($cache_key, $data, $ttl) {
        if ($this->redis === null) {
            return false;
        }
        
        try {
            $this->redis->setex($cache_key, $ttl * 60, json_encode($data));
            return true;
        } catch (Exception $e) {
         
        }
        
        return false;
    }
    
    public function handle_interface_request($request) {
        $settings = get_option($this->option_key, []);
        $selected_categories = isset($settings['selected_categories']) ? $settings['selected_categories'] : [];
        $tdk_enabled = isset($settings['tdk_enabled']) && $settings['tdk_enabled'] === 'on';
        $redis_enabled = isset($settings['redis_enabled']) && $settings['redis_enabled'] === 'on' && $this->redis !== null;
        
        if ($redis_enabled) {
            $cache_key = $this->get_cache_key($selected_categories, $tdk_enabled);
            $cached_data = $this->get_cached_links($cache_key);
            if ($cached_data !== false) {
                return rest_ensure_response($cached_data);
            }
        }
        
        $args = [
            'orderby' => 'name',
            'order' => 'ASC',
            'hide_invisible' => true,
        ];
        
        if (!empty($selected_categories)) {
            $args['category'] = implode(',', $selected_categories);
        }
        
        $bookmarks = get_bookmarks($args);
        
        $links = [];
        if (!empty($bookmarks)) {
            foreach ($bookmarks as $bookmark) {
                $link_data = [];
                
                // 站点链接
                $link_data['link_url'] = !empty($bookmark->link_url) ? $bookmark->link_url : null;
                
                // RSS订阅链接
                $link_data['rss_url'] = !empty($bookmark->link_rss) ? $bookmark->link_rss : null;
                
                // TDK信息
                if ($tdk_enabled) {
                    $link_data['title'] = !empty($bookmark->link_name) ? $bookmark->link_name : null;
                    $link_data['description'] = !empty($bookmark->link_description) ? $bookmark->link_description : null;
                    $link_data['image_url'] = !empty($bookmark->link_image) ? $bookmark->link_image : null;
                }
                
                if (!empty($link_data['link_url'])) {
                    $links[] = $link_data;
                }
            }
        }
        
        $response_data = [
            'success' => true,
            'count' => count($links),
            'links' => $links
        ];
       
        if ($redis_enabled) {
            $ttl = isset($settings['redis_ttl']) ? intval($settings['redis_ttl']) : 10;
            $this->set_cached_links($cache_key, $response_data, $ttl);
        }
        
        return rest_ensure_response($response_data);
    }

    private function get_link_categories() {
        $categories = get_terms([
            'taxonomy' => 'link_category',
            'hide_empty' => false,
        ]);
        
        if (is_wp_error($categories)) {
            return [];
        }
        
        return $categories;
    }

    public function add_admin_menu() {
        add_options_page(
            '链接通讯接口设置',
            '链接通讯接口',
            'manage_options',
            'link-comm-interface-settings',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting($this->option_key, $this->option_key, [
            'sanitize_callback' => [$this, 'sanitize_settings']
        ]);
        
        add_settings_section(
            'link_comm_interface_main',
            '通讯配置',
            null,
            'link-comm-interface-settings'
        );
        
        add_settings_field(
            'interface_status',
            '通讯状态',
            [$this, 'render_status_field'],
            'link-comm-interface-settings',
            'link_comm_interface_main'
        );
        
        add_settings_field(
            'comm_key',
            '通讯密钥',
            [$this, 'render_key_field'],
            'link-comm-interface-settings',
            'link_comm_interface_main'
        );
        
        add_settings_field(
            'selected_categories',
            '链接分类筛选',
            [$this, 'render_categories_field'],
            'link-comm-interface-settings',
            'link_comm_interface_main'
        );
        
        add_settings_section(
            'link_comm_interface_features',
            '扩展功能',
            null,
            'link-comm-interface-settings'
        );
        
        add_settings_field(
            'tdk_enabled',
            'TDK信息返回',
            [$this, 'render_tdk_field'],
            'link-comm-interface-settings',
            'link_comm_interface_features'
        );
        
        add_settings_field(
            'whitelist_enabled',
            'IP白名单',
            [$this, 'render_whitelist_field'],
            'link-comm-interface-settings',
            'link_comm_interface_features'
        );
        
        add_settings_field(
            'redis_enabled',
            'Redis缓存',
            [$this, 'render_redis_field'],
            'link-comm-interface-settings',
            'link_comm_interface_features'
        );
    }

    public function render_status_field() {
        $settings = get_option($this->option_key, []);
        $status = isset($settings['status']) ? $settings['status'] : 'on';
        ?>
        <div class="lci-switch-group">
            <label class="lci-switch">
                <input type="radio" name="<?php echo esc_attr($this->option_key); ?>[status]" value="on" <?php checked($status, 'on'); ?>>
                <span class="lci-switch-label">启用</span>
            </label>
            <label class="lci-switch" style="margin-left: 20px;">
                <input type="radio" name="<?php echo esc_attr($this->option_key); ?>[status]" value="off" <?php checked($status, 'off'); ?>>
                <span class="lci-switch-label">停用</span>
            </label>
        </div>
        <p class="lci-description">停用后将中断所使用的请求业务，外部将无法请求.</p>
        <?php
    }

    public function render_key_field() {
        $settings = get_option($this->option_key, []);
        $comm_key = isset($settings['comm_key']) ? $settings['comm_key'] : '';
        ?>
        <div class="lci-key-field">
            <input type="text" 
                   name="<?php echo esc_attr($this->option_key); ?>[comm_key]" 
                   value="<?php echo esc_attr($comm_key); ?>" 
                   class="lci-input"
                   style="width: 300px;"
                   id="lci-comm-key">
            <button type="button" id="regenerate-comm-key" class="lci-btn lci-btn-secondary">重新生成</button>
        </div>
        <p class="lci-description">外部请求时需要在请求头中携带 X-Comm-Key 字段</p>
        <?php
    }

    public function render_categories_field() {
        $settings = get_option($this->option_key, []);
        $selected_categories = isset($settings['selected_categories']) ? $settings['selected_categories'] : [];
        $categories = $this->get_link_categories();
        
        if (empty($categories)) {
            echo '<p class="lci-description">暂无链接分类，请先在「链接 → 链接分类」中创建分类.</p>';
            return;
        }
        ?>
        <div class="lci-category-list">
            <label class="lci-checkbox lci-checkbox-all">
                <input type="checkbox" id="select-all-categories" <?php echo count($selected_categories) === count($categories) ? 'checked' : ''; ?>>
                <span class="lci-checkbox-label">全选/全不选</span>
            </label>
            <div class="lci-divider"></div>
            <?php foreach ($categories as $category): ?>
                <label class="lci-checkbox">
                    <input type="checkbox" 
                           name="<?php echo esc_attr($this->option_key); ?>[selected_categories][]" 
                           value="<?php echo esc_attr($category->term_id); ?>"
                           <?php echo in_array($category->term_id, $selected_categories) ? 'checked' : ''; ?>>
                    <span class="lci-checkbox-label">
                        <?php echo esc_html($category->name); ?>
                        <span class="lci-muted">(ID: <?php echo $category->term_id; ?>)</span>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>
        <p class="lci-description">勾选需要返回的链接分类，不勾选则返回所有分类下的链接.</p>
        <?php
    }

    public function render_tdk_field() {
        $settings = get_option($this->option_key, []);
        $tdk_enabled = isset($settings['tdk_enabled']) ? $settings['tdk_enabled'] : 'off';
        ?>
        <label class="lci-toggle">
            <input type="checkbox" 
                   name="<?php echo esc_attr($this->option_key); ?>[tdk_enabled]" 
                   value="on" 
                   <?php checked($tdk_enabled, 'on'); ?>>
            <span class="lci-toggle-slider"></span>
            <span class="lci-toggle-label">开启后将返回站点名称、描述、图片地址(TDK)</span>
        </label>
        <?php
    }

    public function render_whitelist_field() {
        $settings = get_option($this->option_key, []);
        $whitelist_enabled = isset($settings['whitelist_enabled']) ? $settings['whitelist_enabled'] : 'off';
        $whitelist_ips = isset($settings['whitelist_ips']) ? $settings['whitelist_ips'] : '';
        ?>
        <div class="lci-whitelist-wrapper">
            <label class="lci-toggle">
                <input type="checkbox" 
                       name="<?php echo esc_attr($this->option_key); ?>[whitelist_enabled]" 
                       value="on" 
                       <?php checked($whitelist_enabled, 'on'); ?>
                       id="whitelist-toggle">
                <span class="lci-toggle-slider"></span>
                <span class="lci-toggle-label">开启IP白名单验证</span>
            </label>
            <div class="lci-whitelist-content" id="whitelist-content" style="display: <?php echo $whitelist_enabled === 'on' ? 'block' : 'none'; ?>;">
                <textarea name="<?php echo esc_attr($this->option_key); ?>[whitelist_ips]" 
                          class="lci-textarea"
                          placeholder="每行一个IP，支持IPv4/IPv6格式，支持CIDR格式，例如：&#10;192.168.0.1&#10;192.168.1.0/24&#10;2001:db8::1&#10;2001:db8::/32"
                          rows="6"><?php echo esc_textarea($whitelist_ips); ?></textarea>
                <p class="lci-description">支持IPv4/IPv6格式，支持CIDR格式(如192.168.0.1/24)，非白名单请求: 403</p>
            </div>
        </div>
        <?php
    }

    public function render_redis_field() {
        $settings = get_option($this->option_key, []);
        $redis_enabled = isset($settings['redis_enabled']) ? $settings['redis_enabled'] : 'off';
        $redis_ttl = isset($settings['redis_ttl']) ? intval($settings['redis_ttl']) : 10;
        $redis_available = class_exists('Redis');
        ?>
        <div class="lci-redis-wrapper">
            <label class="lci-toggle">
                <input type="checkbox" 
                       name="<?php echo esc_attr($this->option_key); ?>[redis_enabled]" 
                       value="on" 
                       <?php checked($redis_enabled, 'on'); ?>
                       <?php disabled(!$redis_available); ?>
                       id="redis-toggle">
                <span class="lci-toggle-slider"></span>
                <span class="lci-toggle-label">
                    开启Redis缓存
                    <?php if (!$redis_available): ?>
                        <span class="lci-warning">(需安装php-redis扩展)</span>
                    <?php endif; ?>
                </span>
            </label>
            <div class="lci-redis-content" id="redis-content" style="display: <?php echo $redis_enabled === 'on' ? 'flex' : 'none'; ?>;">
                <div class="lci-input-group">
                    <input type="number" 
                           name="<?php echo esc_attr($this->option_key); ?>[redis_ttl]" 
                           value="<?php echo esc_attr($redis_ttl); ?>"
                           class="lci-input"
                           style="width: 100px;"
                           min="1"
                           max="60">
                    <span class="lci-input-suffix">分钟</span>
                </div>
                <button type="button" class="lci-btn lci-btn-secondary" id="clear-redis-cache">清除缓存</button>
            </div>
            <p class="lci-description">开启此选项后可减少API端点每次请求时的性能开销，缓存时间范围1-60分钟.</p>
        </div>
        <?php
    }

    public function sanitize_settings($input) {
        $output = [];
        $output['status'] = isset($input['status']) && $input['status'] === 'on' ? 'on' : 'off';
        $output['comm_key'] = isset($input['comm_key']) ? sanitize_text_field($input['comm_key']) : '';
        $output['selected_categories'] = isset($input['selected_categories']) && is_array($input['selected_categories']) 
            ? array_map('intval', $input['selected_categories']) 
            : [];
        $output['tdk_enabled'] = isset($input['tdk_enabled']) && $input['tdk_enabled'] === 'on' ? 'on' : 'off';
        $output['whitelist_enabled'] = isset($input['whitelist_enabled']) && $input['whitelist_enabled'] === 'on' ? 'on' : 'off';
        $output['whitelist_ips'] = isset($input['whitelist_ips']) ? sanitize_textarea_field($input['whitelist_ips']) : '';
        $output['redis_enabled'] = isset($input['redis_enabled']) && $input['redis_enabled'] === 'on' ? 'on' : 'off';
        $output['redis_ttl'] = isset($input['redis_ttl']) ? max(1, min(60, intval($input['redis_ttl']))) : 10;
        
        if (empty($output['comm_key'])) {
            $output['comm_key'] = $this->generate_comm_key();
        }
        
        $this->clear_all_cache();
        
        return $output;
    }

    private function clear_all_cache() {
        if ($this->redis !== null) {
            try {
                $keys = $this->redis->keys($this->cache_key_prefix . '*');
                if (!empty($keys)) {
                    $this->redis->del($keys);
                }
            } catch (Exception $e) {
           
            }
        }
    }

    public function ajax_clear_redis_cache() {
        check_ajax_referer('link_comm_interface_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('权限不足');
        }
        
        $this->clear_all_cache();
        
        wp_send_json_success(['message' => '缓存已清除']);
    }

    public function ajax_export_config() {
        check_ajax_referer('link_comm_interface_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('权限不足');
        }
        
        $settings = get_option($this->option_key, []);
        $export_data = [
            'version' => '1.3',
            'timestamp' => current_time('mysql'),
            'settings' => $settings
        ];
        
        wp_send_json_success($export_data);
    }

    public function ajax_import_config() {
        check_ajax_referer('link_comm_interface_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('权限不足');
        }
        
        $config_data = isset($_POST['config']) ? trim($_POST['config']) : '';
        if (empty($config_data)) {
            wp_send_json_error(['message' => '配置数据不能为空']);
        }
        
        $imported = json_decode(stripslashes($config_data), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(['message' => '配置格式无效']);
        }
        
        if (!isset($imported['settings']) || !is_array($imported['settings'])) {
            wp_send_json_error(['message' => '配置数据结构无效']);
        }
        
        $new_settings = $this->sanitize_settings($imported['settings']);
        update_option($this->option_key, $new_settings);
        
        wp_send_json_success(['message' => '配置导入成功']);
    }

    public function render_settings_page() {
        ?>
        <div class="lci-wrap">
            <div class="lci-header">
                <h1 class="lci-title">链接通讯接口设置</h1>
                <div class="lci-header-actions">
                    <button type="button" class="lci-btn lci-btn-outline" id="export-config-btn">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                            <polyline points="7 10 12 15 17 10"/>
                            <line x1="12" y1="15" x2="12" y2="3"/>
                        </svg>
                        导出配置
                    </button>
                    <button type="button" class="lci-btn lci-btn-outline" id="import-config-btn">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                            <polyline points="7 10 12 15 17 10"/>
                            <line x1="12" y1="15" x2="12" y2="3"/>
                        </svg>
                        导入配置
                    </button>
                </div>
            </div>
            
            <div class="lci-card">
                <form method="post" action="options.php" id="lci-settings-form">
                    <?php
                    settings_fields($this->option_key);
                    do_settings_sections('link-comm-interface-settings');
                    ?>
                    <div class="lci-form-actions">
                        <button type="submit" class="lci-btn lci-btn-primary">保存设置</button>
                    </div>
                </form>
            </div>
            
            <div class="lci-card">
                <h2 class="lci-card-title">使用说明</h2>
                <div class="lci-info-grid">
                    <div class="lci-info-item">
                        <span class="lci-info-label">请求地址</span>
                        <code class="lci-code"><?php echo esc_url(rest_url('linkinterface/v1/get-links')); ?></code>
                    </div>
                    <div class="lci-info-item">
                        <span class="lci-info-label">请求方式</span>
                        <span class="lci-badge">POST</span>
                    </div>
                    <div class="lci-info-item">
                        <span class="lci-info-label">请求头</span>
                        <code class="lci-code">X-Comm-Key: 您的通讯密钥</code>
                    </div>
                    <div class="lci-info-item">
                        <span class="lci-info-label">返回格式</span>
                        <span class="lci-badge">JSON</span>
                    </div>
                </div>
                
                <h3 class="lci-section-title">返回示例</h3>
                <pre class="lci-pre">{
    "success": true,
    "count": 3,
    "links": [
        {
            "link_url": "https://example1.com",
            "rss_url": "https://example1.com/feed"<?php $settings = get_option($this->option_key, []); if (isset($settings['tdk_enabled']) && $settings['tdk_enabled'] === 'on'): ?>,
            "title": "示例站点",
            "description": "这是一个示例站点的描述",
            "image_url": "https://example1.com/logo.png"<?php endif; ?>

        },
        {
            "link_url": "https://example2.com",
            "rss_url": null<?php if (isset($settings['tdk_enabled']) && $settings['tdk_enabled'] === 'on'): ?>,
            "title": "另一个站点",
            "description": null,
            "image_url": null<?php endif; ?>

        }
    ]
}</pre>
                
                <h3 class="lci-section-title">cURL调用示例</h3>
                <pre class="lci-pre">curl -X POST <?php echo esc_url(rest_url('linkinterface/v1/get-links')); ?> \
  -H "X-Comm-Key: 您的通讯密钥"</pre>
                
                <p class="lci-description">注意：需要在WordPress后台的[链接]管理中为每个链接填写相关信息。例: "名称"、"描述"、"图片地址"</p>
            </div>
        </div>
        
        <div class="lci-modal" id="export-modal">
            <div class="lci-modal-container">
                <div class="lci-modal-header">
                    <h3 class="lci-modal-title">导出配置</h3>
                    <button type="button" class="lci-modal-close" data-close-modal>&times;</button>
                </div>
                <div class="lci-modal-body">
                    <p class="lci-modal-desc">复制下方配置数据以备份或迁移</p>
                    <textarea id="export-config-textarea" class="lci-textarea" rows="10" readonly></textarea>
                </div>
                <div class="lci-modal-footer">
                    <button type="button" class="lci-btn lci-btn-primary" id="copy-export-config">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
                            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                        </svg>
                        复制配置
                    </button>
                </div>
            </div>
        </div>
        
        <div class="lci-modal" id="import-modal">
            <div class="lci-modal-container">
                <div class="lci-modal-header">
                    <h3 class="lci-modal-title">导入配置</h3>
                    <button type="button" class="lci-modal-close" data-close-modal>&times;</button>
                </div>
                <div class="lci-modal-body">
                    <p class="lci-modal-desc">粘贴之前导出的配置数据</p>
                    <textarea id="import-config-textarea" class="lci-textarea" rows="10" placeholder="粘贴配置数据..."></textarea>
                </div>
                <div class="lci-modal-footer">
                    <button type="button" class="lci-btn lci-btn-primary" id="apply-import-config">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="20 6 9 17 4 12"/>
                        </svg>
                        应用配置
                    </button>
                </div>
            </div>
        </div>
        
        <div class="lci-toast" id="lci-toast"></div>
        <?php
    }
}

LinkCommInterface::get_instance();