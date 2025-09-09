<?php
/**
 * Plugin Name: NavexWp2
 * Plugin URI: https://ghomsoft.com/navexwp
 * Description: WooCommerce extension for delivery tracking and parcel management via API integration.
 * Version: 1.0.0
 * Author: Abderrazak EROUEL
 * Author URI: https://ghomsoft.com
 * Text Domain: navexwp2
 * Domain Path: /languages
 * Requires at least: 5.6
 * Requires PHP: 7.3
 * WC requires at least: 5.0
 * WC tested up to: 8.5
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});


// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p>NavexWp requires WooCommerce to be installed and active. You can download <a href="https://woocommerce.com/" target="_blank">WooCommerce</a> here.</p></div>';
    });
    return;
}

// Define plugin constants
define('NAVEXWP_VERSION', '1.0.0');
define('NAVEXWP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('NAVEXWP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('NAVEXWP_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main plugin class
 */
class NavexWp {
    /**
     * Instance of this class
     *
     * @var NavexWp
     */
    protected static $instance = null;

    /**
     * API endpoint for tracking
     *
     * @var string
     */
    private $api_endpoint;

    /**
     * API username for tracking
     *
     * @var string
     */
    private $api_username;

    /**
     * API key
     *
     * @var string
     */
    private $api_key;

    /**
     * Class constructor
     */
    public function __construct() {
        $this->init_hooks();
        $this->load_dependencies();
        
        // Get settings
        $options = get_option('navexwp_settings');
        $this->api_endpoint = isset($options['api_endpoint']) ? $options['api_endpoint'] : '';
        $this->api_username = isset($options['api_username']) ? $options['api_username'] : '';
        $this->api_key = isset($options['api_key']) ? $options['api_key'] : '';
        // Register the cron hook and callback function
        add_action('hourly_cron_job_hook', array($this, 'custom_check_and_update_order_status'));

    }

    /**
     * Main plugin instance
     *
     * @return NavexWp
     */
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Admin hooks
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));

        // Order related hooks
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        //old code 
        //add_action('woocommerce_order_status_changed', array($this, 'process_order_status_change'), 11, accepted_args: 3);
        add_action('save_post', array($this, 'save_tracking_info'));
              
        // Order details in admin
        #add_action('woocommerce_admin_order_data_after_shipping_address', array($this, 'display_tracking_info_in_admin'), 10, 1);
                
        // AJAX handlers
        add_action('wp_ajax_navexwp_get_tracking_code', array($this, 'ajax_get_tracking_code'));
        add_action('wp_ajax_navexwp_check_tracking_status', array($this, 'ajax_check_tracking_status'));
        
        
        // Ajoute l'affichage des métadonnées étendues à la page de détails de commande dans l'administration WooCommerce.
        add_action( 'woocommerce_admin_order_data_after_order_details', array($this, 'eomd_display_extended_order_meta') );

        // Register scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // Schedule the event if it's not already scheduled
        if (!wp_next_scheduled('hourly_cron_job_hook')) {
            wp_schedule_event(time(), 'hourly', 'hourly_cron_job_hook');
        }

        add_action('wp_ajax_run_my_cron_job',array($this, 'custom_check_and_update_order_status'));
    }

    /**
     * Load dependencies
     */
    private function load_dependencies() {
        require_once NAVEXWP_PLUGIN_DIR . 'includes/class-navexwp-api.php';
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('NavexWp Settings', 'navexwp'),
            __('NavexWp Settings', 'navexwp'),
            'manage_woocommerce',
            'navexwp-settings',
            array($this, 'settings_page')
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('navexwp_settings_group', 'navexwp_settings');
        
        add_settings_section(
            'navexwp_api_settings',
            __('API Settings', 'navexwp'),
            array($this, 'api_settings_section_callback'),
            'navexwp_settings_page'
        );
        
        add_settings_field(
            'api_endpoint',
            __('API Endpoint', 'navexwp'),
            array($this, 'api_endpoint_callback'),
            'navexwp_settings_page',
            'navexwp_api_settings'
        );

        add_settings_field(
            'api_username',
            __('API Username', 'navexwp'),
            array($this, 'api_username_callback'),
            'navexwp_settings_page',
            'navexwp_api_settings'
        );
        
        add_settings_field(
            'api_key',
            __('API Key', 'navexwp'),
            array($this, 'api_key_callback'),
            'navexwp_settings_page',
            'navexwp_api_settings'
        );

        add_settings_field(
            'api_designation',
            'Designation',
            array($this, 'api_designation_callback'),
            'navexwp_settings_page',
            'navexwp_api_settings'
        );
    }

    /**
     * Settings page
     */
    public function settings_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('NavexWp Settings', 'navexwp'); ?></h1>
            <form method="post" action="options.php">
                <?php
                    settings_fields('navexwp_settings_group');
                    do_settings_sections('navexwp_settings_page');
                    submit_button();
                ?>
            </form>
        </div>
        <!-- Button run Cron job -->
        <button id="run-cron-job" class="button button-primary">Activer la synchronisation automatique</button>
        <div id="cron-response"></div>

        <script type="text/javascript">
            document.getElementById("run-cron-job").addEventListener("click", function() {
                var button = this;
                button.disabled = true;
                button.innerText = "Running...";
                
                fetch(ajaxurl, {
                    method: "POST",
                    headers: { "Content-Type": "application/x-www-form-urlencoded" },
                    body: "action=run_my_cron_job"
                })
                .then(response => response.json())
                .then(data => {
                    document.getElementById("cron-response").innerText = "Synchronization between Navex and WooCommerce order status completed successfully.";
                    button.innerText = "Run Cron Job Now";
                    button.disabled = false;
                })
                .catch(error => {
                    document.getElementById("cron-response").innerText = "Error running cron job.";
                    console.error("Error:", error);
                    button.innerText = "Run Cron Job Now";
                    button.disabled = false;
                });
            });
        </script>
        </div>
        <?php
    }

    /**
     * API settings section callback
     */
    public function api_settings_section_callback() {
        echo '<p>' . esc_html__('Configure your delivery API settings.', 'navexwp') . '</p>';
    }

    /**
     * API endpoint field callback
     */
    public function api_endpoint_callback() {
        $options = get_option('navexwp_settings');
        $endpoint = isset($options['api_endpoint']) ? esc_url($options['api_endpoint']) : '';
        ?>
        <input type="url" id="api_endpoint" name="navexwp_settings[api_endpoint]" value="<?php echo $endpoint; ?>" class="regular-text" />
        <p class="description"><?php echo esc_html__('Enter the full URL of the Navex API endpoint.', 'navexwp'); ?></p>
        <?php
    }

    /**
     * API username field callback
     */
    public function api_username_callback(): void {
        $options = get_option('navexwp_settings');
        $username = isset($options['api_username']) ? esc_attr($options['api_username']) : '';
        ?>
        <input type="text" id="api_username" name="navexwp_settings[api_username]" value="<?php echo $username; ?>" class="regular-text" />
        <p class="description"><?php echo esc_html__('Enter the username for Navex API.', 'navexwp'); ?></p>
        <?php
    }

    /**
     * API key field callback
     */
    public function api_key_callback() {
        $options = get_option('navexwp_settings');
        $api_key = isset($options['api_key']) ? esc_attr($options['api_key']) : '';
        ?>
        <input type="password" id="api_key" name="navexwp_settings[api_key]" value="<?php echo $api_key; ?>" class="regular-text" />
        <p class="description"><?php echo esc_html__('Enter your API key for authentication Navex.', 'navexwp'); ?></p>
        <?php
    }

    /**
     * API designation field callback
     */
    public function api_designation_callback() {
        $options = get_option('navexwp_settings');
        $api_designation = isset($options['api_designation']) ? esc_attr($options['api_designation']) : '';
        ?>
        <textarea id="api_designation" name="navexwp_settings[api_designation]" class="regular-text" ><?php echo $api_designation; ?></textarea>
        <p class="description"><?php echo esc_html__('Enter your API designation for Navex.', 'navexwp'); ?></p>
        <?php
    }

    /**
     * Add meta boxes to order page
     */
    public function add_meta_boxes() {
        add_meta_box(
            'navexwp_tracking',
            __('NavexWp Tracking', 'navexwp'),
            array($this, 'tracking_meta_box_content'),
            'shop_order',
            'side',
            'default'
        );
    }

    /**
     * Tracking meta box content
     */
    public function tracking_meta_box_content($post) {
        $order_id = $post->ID;
        $tracking_code = get_post_meta($order_id, '_navexwp_tracking_code', true);
        $tracking_link = get_post_meta($order_id, '_navexwp_tracking_link', true);
        $tracking_status = get_post_meta($order_id, '_navexwp_tracking_status', true);
        $tracking_updated = get_post_meta($order_id, '_navexwp_tracking_updated', true);
        
        wp_nonce_field('navexwp_save_tracking_data', 'navexwp_tracking_nonce');
        
        ?>
        <div class="navexwp-tracking-container">
            <p>
                <label for="navexwp_tracking_code"><?php echo esc_html__('Tracking Code:', 'navexwp'); ?></label>
                <input type="text" id="navexwp_tracking_code" name="navexwp_tracking_code" value="<?php echo esc_attr($tracking_code); ?>" />
                <button type="button" class="button button-secondary" id="navexwp_get_tracking_code" data-order-id="<?php echo esc_attr($order_id); ?>">
                    <?php echo esc_html__('Get Code', 'navexwp'); ?>
                </button>
            </p>

            <p>
                <label for="navexwp_tracking_code"><?php echo esc_html__('Tracking Link:', 'navexwp'); ?></label>
                <input type="text" id="navexwp_tracking_link" name="navexwp_tracking_link" value="<?php echo esc_attr($tracking_link); ?>" />
                <button type="button" class="button button-secondary" id="navexwp_get_tracking_link" data-order-id="<?php echo esc_attr($order_id); ?>">
                    <?php echo esc_html__('Get Code', 'navexwp'); ?>
                </button>
            </p>

            <p>
                <label for="navexwp_tracking_code"><?php echo esc_html__('Tracking Link:', 'navexwp'); ?></label>
                <input type="text" id="navexwp_tracking_code" name="navexwp_tracking_code" value="<?php echo esc_attr($tracking_code); ?>" />
                <button type="button" class="button button-secondary" id="navexwp_get_tracking_code" data-order-id="<?php echo esc_attr($order_id); ?>">
                    <?php echo esc_html__('Get Code', 'navexwp'); ?>
                </button>
            </p>
            
            <?php if ($tracking_code) : ?>
                <p>
                    <strong><?php echo esc_html__('Status:', 'navexwp'); ?></strong>
                    <span id="navexwp_tracking_status"><?php echo esc_html($tracking_status ? $tracking_status : __('Unknown', 'navexwp')); ?></span>
                    <button type="button" class="button button-secondary" id="navexwp_check_status" data-order-id="<?php echo esc_attr($order_id); ?>">
                        <?php echo esc_html__('Check Status', 'navexwp'); ?>
                    </button>
                </p>
                
                <?php if ($tracking_updated) : ?>
                    <p class="description">
                        <?php 
                        echo sprintf(
                            __('Last updated: %s', 'navexwp'),
                            date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $tracking_updated)
                        ); 
                        ?>
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Save tracking information
     */
    public function save_tracking_info($post_id) {
        // Check if it's an order
        if (get_post_type($post_id) !== 'shop_order') {
            return;
        }
        
        // Verify nonce
        if (!isset($_POST['navexwp_tracking_nonce']) || !wp_verify_nonce($_POST['navexwp_tracking_nonce'], 'navexwp_save_tracking_data')) {
            return;
        }
        
        // Save tracking code if provided
        if (isset($_POST['navexwp_tracking_code'])) {
            $tracking_code = sanitize_text_field($_POST['navexwp_tracking_code']);
            
            if (!empty($tracking_code)) {
                update_post_meta($post_id, '_navexwp_tracking_code', meta_value: $tracking_code);
                
                // Check status via API if we have a new tracking code
                $this->update_tracking_status($post_id, $tracking_code);
            }
        }
    }

    /**
     * Update tracking status via API
     */
    public function update_tracking_status($order_id, $tracking_code) {
        if (empty($tracking_code)) {
            return false;
        }
        
        // Create API instance
        $api = new NavexWp_API($this->api_endpoint, $this->api_key, $this->api_username);
        
        // Get status
        $response = $api->get_tracking_status($tracking_code);
        
        if ($response && !is_wp_error(thing: $response)) {
            $status = isset($response['etat']) ? sanitize_text_field($response['etat']) : '';
            update_post_meta($order_id, '_navexwp_tracking_status', $status);
            update_post_meta($order_id, '_navexwp_tracking_updated', time());
            
            // Save additional tracking details if available
            if (isset($response['details'])) {
                update_post_meta($order_id, '_navexwp_tracking_details', $response['details']);
            }
            
            return $status;
        }
        
        return false;
    }
    /**
     * AJAX handler for getting tracking code
     */
    public function ajax_get_tracking_code() {
        check_ajax_referer('navexwp_ajax_nonce', 'nonce');
        
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        
        if (!$order_id) {
            wp_send_json_error(__('Invalid order ID', 'navexwp'));
        }
        
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_send_json_error(__('Order not found', 'navexwp'));
        }
        
        // Create API instance
        $api = new NavexWp_API($this->api_endpoint, $this->api_key, $this->api_username);
        
        // Prepare order data for API
        $items = $order->get_items();
        $productsList =  "";
        $index = 0;
        foreach ($items as $item_id => $item) {
            if($index!=0){
                $productsList =  $productsList . ",";
            }
            $productsList =  $productsList . "," .$item->get_name();
            $index++;
        }
        $data = array(
            'prix' => floatval($order->get_total()),
            'nom' => sanitize_text_field($order->get_shipping_first_name()." ". $order->get_shipping_last_name() ),
            'gouvernerat' => sanitize_text_field($order->get_shipping_state()),
            'ville' => sanitize_text_field($order->get_billing_city()),
            'adresse' => sanitize_textarea_field($order->get_shipping_address_1()),
            'tel' => sanitize_text_field($order->get_billing_phone()),
            'tel2' => sanitize_text_field($order->get_billing_phone()),
            'designation' => "",
            'nb_article' => intval(count($items)),
            'msg' => sanitize_textarea_field($order->get_customer_note()),
            'echange' => 0,
            'article' => sanitize_text_field($productsList),
            'nb_echange' => intval(0),
            'ouvrir' => "oui",
            'code_suivi' => "",
            'order_id' => $order_id,
            'customer_name' => $order->get_formatted_shipping_full_name(),
            'shipping_address' => $order->get_address('shipping')
        );
        // Get tracking code from API
        $response = $api->request_tracking_code($data);
        
        if ($response && !is_wp_error($response) && isset($response['tracking_code'])) {
            $tracking_code = sanitize_text_field($response['tracking_code']);
            $tracking_link = $response['lien'];
            
            // Save tracking code
            update_post_meta($order_id, '_navexwp_tracking_code', $tracking_code);
            update_post_meta($order_id, '_navexwp_tracking_link', $tracking_link);
            
            // Get initial status
            $this->update_tracking_status($order_id, $tracking_code);
            
            wp_send_json_success([
                'tracking_code' => $tracking_code,
                'status' => get_post_meta($order_id, '_navexwp_tracking_status', true)
            ]);
        } else {
            wp_send_json_error(__('Could not retrieve tracking code from API', 'navexwp'));
        }
        
        wp_die();
    }

    /**
     * AJAX handler for checking tracking status
     */
    public function ajax_check_tracking_status() {
        check_ajax_referer('navexwp_ajax_nonce', 'nonce');
        
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        
        if (!$order_id) {
            wp_send_json_error(__('Invalid order ID', 'navexwp'));
        }
        
        $tracking_code = get_post_meta($order_id, '_navexwp_tracking_code', true);
        
        if (!$tracking_code) {
            wp_send_json_error(__('No tracking code found for this order', 'navexwp'));
        }
        
        $status = $this->update_tracking_status($order_id, $tracking_code);
        
        if ($status) {
            wp_send_json_success([
                'status' => $status,
                'updated' => date_i18n(get_option('date_format') . ' ' . get_option('time_format'), time())
            ]);
        } else {
            wp_send_json_error(__('Could not retrieve tracking status from API', 'navexwp'));
        }
        
        wp_die();
    }

    /**
     * Display tracking info in admin order view
     */
    public function display_tracking_info_in_admin($order) {
        $order_id = $order->get_id();
        $tracking_code = get_post_meta($order_id, '_navexwp_tracking_code', true);
        
        if ($tracking_code) {
            $status = get_post_meta($order_id, '_navexwp_tracking_status', true);
            $updated = get_post_meta($order_id, '_navexwp_tracking_updated', true);
            
            echo '<div class="navexwp-tracking-info">';
            echo '<h4>' . esc_html__('Delivery Tracking', 'navexwp') . '</h4>';
            echo '<p><strong>' . esc_html__('Tracking Code:', 'navexwp') . '</strong> ' . esc_html($tracking_code) . '</p>';
            
            if ($status) {
                echo '<p><strong>' . esc_html__('Status:', 'navexwp') . '</strong> ' . esc_html($status) . '</p>';
            }
            
            if ($updated) {
                echo '<p><strong>' . esc_html__('Last Updated:', 'navexwp') . '</strong> ' . 
                    date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $updated) . '</p>';
            }
            
            echo '</div>';
        }
    }

    /**
     * Display tracking info for customer in order details
     */
    public function display_tracking_info_for_customer($order) {
        $order_id = $order->get_id();
        $tracking_code = get_post_meta($order_id, '_navexwp_tracking_code', true);
        $tracking_link = get_post_meta($order_id, '_navexwp_tracking_link', true);
        if ($tracking_code) {
            $status = get_post_meta($order_id, '_navexwp_tracking_status', true);
            
            echo '<section class="woocommerce-order-tracking">';
            echo '<h2>' . esc_html__('Delivery Tracking', 'navexwp') . '</h2>';
            echo '<p><strong>' . esc_html__('Tracking Code:', 'navexwp') . '</strong> ' . esc_html($tracking_code) . '</p>';
            echo '<p><strong>' . esc_html__('Tracking link:', 'navexwp') . '</strong> <a href="'.esc_html($tracking_link).'">'.esc_html__('Track', 'navexwp').'</a></p>';
            
            if ($status) {
                echo '<p><strong>' . esc_html__('Status:', 'navexwp') . '</strong> ' . esc_html($status) . '</p>';
            }
            
            echo '</section>';
        }
    }

    public function mon_plugin_log_var( $var ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( print_r( $var, true ) );
        }
    }

    /**
     * Process order status change
     */
    public function process_order_status_change($order_id, $old_status, $new_status) {
        // If order is marked as completed and no tracking code yet, try to get one
        if ($new_status === 'processing') {
            $tracking_code = get_post_meta($order_id, '_navexwp_tracking_code', true);
            
            if (!$tracking_code) {
                // Create API instance
                $api = new NavexWp_API($this->api_endpoint, $this->api_key,$this->api_username);
                $order = wc_get_order($order_id);

                // Prepare order data for API
                $items = $order->get_items();
                $productsList =  "";
                $index = 0;
                foreach ($items as $item_id => $item) {
                    if($index!=0){
                        $productsList =  $productsList . ",";
                    }
                    $productsList =  $productsList . "," .$item->get_name();
                    $index++;
                }
                $data = [
                    'prix' => floatval($order->get_total()),
                    'nom' => sanitize_text_field($order->get_shipping_first_name()." ". $order->get_shipping_last_name() ),
                    'gouvernerat' => sanitize_text_field($order->get_shipping_state()),
                    'ville' => sanitize_text_field($order->get_billing_city()),
                    'adresse' => sanitize_textarea_field($order->get_shipping_address_1()),
                    'tel' => sanitize_text_field($order->get_billing_phone()),
                    'tel2' => sanitize_text_field($order->get_billing_phone()),
                    'designation' => sanitize_textarea_field($this->navex_designation),
                    'nb_article' => intval(count($items)),
                    'msg' => sanitize_textarea_field($order->get_customer_note()),
                    'echange' => 0,
                    'article' => sanitize_text_field($productsList),
                    'nb_echange' => intval(0),
                    'ouvrir' =>'oui',
                    'code_suivi' => "",
                    'order_id' => $order_id,
                    'customer_name' => $order->get_formatted_shipping_full_name(),
                    'shipping_address' => $order->get_address('shipping'),
                ];
                // Get tracking code from API
                $response = $api->request_tracking_code($data);
                if ($response && !is_wp_error($response) && isset($response['status_message'])) {
                    $tracking_code = sanitize_text_field($response['status_message']);
                    $tracking_link = $response['lien'];
                    
                    // Save tracking code
                    update_post_meta($order_id, '_navexwp_tracking_code', meta_value: $tracking_code);
                    update_post_meta($order_id, '_navexwp_tracking_link', meta_value: $tracking_link);
                    
                    // Get initial status
                    $this->update_tracking_status($order_id, $tracking_code);
                }
            }
        }
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        $screen = get_current_screen();
        
        // Only load on order edit screens
        if ($screen->id === 'shop_order') {
            wp_enqueue_script(
                'navexwp-admin-js',
                NAVEXWP_PLUGIN_URL . 'assets/js/admin.js',
                array('jquery'),
                NAVEXWP_VERSION,
                true
            );
            
            wp_localize_script('navexwp-admin-js', 'navexwp_vars', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('navexwp_ajax_nonce'),
                'getting_code' => __('Getting tracking code...', 'navexwp'),
                'checking_status' => __('Checking status...', 'navexwp'),
                'error_message' => __('Error: ', 'navexwp')
            ));
            
            wp_enqueue_style(
                'navexwp-admin-css',
                NAVEXWP_PLUGIN_URL . 'assets/css/admin.css',
                array(),
                NAVEXWP_VERSION
            );
        }
    }

    /**
     * Affiche les métadonnées étendues dans la page de détails d'une commande dans l'administration WooCommerce.
     *
     * @param WC_Order $order La commande WooCommerce.
     */
    public function eomd_display_extended_order_meta( $order ) {
        // Récupère l'ID de la commande.
        $order_id = $order->get_id();

        // Récupère toutes les métadonnées de la commande.
        $all_meta = get_post_meta( $order_id );
        // Démarre l'affichage
        echo '<p class="form-field form-field-wide">';
        echo '<h3>' . esc_html__( 'Navex delivery', 'navexwp' ) . '</h3>';
        echo '<table class="widefat striped">';
        echo '<thead>';
        echo '<tr><th>' . esc_html__( 'Clé', 'navexwp' ) . '</th><th>' . esc_html__( 'Valeur', 'extended-order-meta-display' ) . '</th></tr>';
        echo '</thead>';
        echo '<tbody>';

        // Boucle sur les métadonnées pour les afficher
        foreach ( $all_meta as $meta_key => $meta_values ) {
            // Chaque meta peut contenir un tableau de valeurs
            echo '<tr>';
            $linkTracking =false;
            $formatDate= false;
            if($meta_key == "_navexwp_tracking_link"){
                $linkTracking = true;
                echo '<td>' . esc_html__('Tracking Link:', 'navexwp')  . '</td>';
            }elseif($meta_key == "_navexwp_tracking_code"){
                echo '<td>' . esc_html__('Tracking Code:', 'navexwp')  . '</td>';
            }elseif($meta_key == "_navexwp_tracking_status"){
                echo '<td>' . esc_html__('Status:', 'navexwp')  . '</td>';
            } elseif($meta_key == "_navexwp_tracking_updated"){
                $formatDate = true;
                echo '<td>' . esc_html__('Update Date:', 'navexwp')  . '</td>';
            } else{
                echo '<td>' . esc_html( $meta_key ) . '</td>';
            }
            
            // Si la valeur est sérialisée, la désérialiser, sinon l'afficher directement.
            $value = maybe_unserialize( $meta_values[0] );
            // Si c'est un tableau, on l'affiche au format print_r pour plus de clarté.
            if ( is_array( $value ) ) {
                $value = print_r( $value, true );
            }
            if($linkTracking){
                echo '<td><a href="'.$value.'" target="_blank">' . esc_html__('Track', 'navexwp') . '</a></td>';

            } elseif($formatDate){
                $date_format = get_option('date_format');
                $time_format = get_option('time_format');
                // Formatte la date en respectant les réglages WordPress et la localisation
                echo('<td>'.date_i18n("$date_format $time_format", $value).'</td>');
            }else{
                echo '<td>' . esc_html( $value ) . '</td>';

            }
            echo '</tr>';
        
        }

        echo '</tbody>';
        echo '</table>';
        echo '</p>';
    }
    /**
     */
    public function custom_check_and_update_order_status() {
        if (!class_exists('WooCommerce')) {
            return;
        }
    
        $args = array(
            'status' => 'on-hold', // Change this to your custom "delivered" status
            'limit'  => -1,          // Get all orders
        );
        $orders = wc_get_orders($args);
        $api_endpoint = isset($this->api_endpoint) ? $this->api_endpoint : '';
        $api_username = isset($this->api_username) ? $this->api_username : '';
        $api_key = isset($this->api_key) ? $this->api_key : '';
        if(!empty($api_endpoint) && !empty($api_username) && !empty($api_key)){
            foreach ($orders as $order) {
                // Change order status to 'completed' if it's delivered
                $tracking_code = get_post_meta($order->get_id(), "_navexwp_tracking_code", true);
                if(!empty($tracking_code)){
                    $api = new NavexWp_API($api_endpoint, $api_key, $api_username);
                    $response = $api->get_tracking_status($tracking_code);
                    if ($response && !is_wp_error(thing: $response)) {
                        $status = isset($response['etat']) ? sanitize_text_field($response['etat']) : '';
                        update_post_meta($order->get_id(), '_navexwp_tracking_status', $status);
                        update_post_meta($order->get_id(), '_navexwp_tracking_updated', time());
                        
                        // Save additional tracking details if available
                        if (isset($response['details'])) {
                            update_post_meta($order->get_id(), '_navexwp_tracking_details', $response['details']);
                        }
                        if(($status == 'Livrer') || ($status == 'Livrer Paye')){
                            $order->update_status('completed', __('Order automatically marked as completed.', 'your-plugin-textdomain'));
                        }

                   }
            
                }
            }
        }else{
            return true;
        }
   }

   //add status awaiting admin validation
   public function register_awaiting_admin_validation_status() {
        register_post_status( 'wc-awaiting-admin', array(
            'label'                     => _x( 'Awaiting Admin', 'Order status', 'woocommerce' ),
            'public'                    => true,
            'show_in_admin_status_list'  => true,
            'show_in_admin_all_list'     => true,
            'exclude_from_search'        => false,
            'label_count'                => _n_noop( 'Awaiting Admin (%s)', 'Awaiting Admin (%s)', 'woocommerce' )
        ) );
    }

    public function add_awaiting_admin_validation_status( $order_statuses ) {
        $order_statuses['wc-awaiting-admin'] = _x( 'Awaiting Admin', 'Order status', 'woocommerce' );
        return $order_statuses;
    }

    public function set_default_order_status_to_admin_validation( $order ,  $data_store ) {
        $admin_validation = get_post_meta($order->get_id(), '_navexwp_admin_validation', true);
        
        if($admin_validation && ($admin_validation==1)){
            return;
        }else{
            if ( 'processing' === $order->get_status() ) {
                update_post_meta($order->get_id(), '_navexwp_admin_validation', '1');
                $order->set_status( 'wc-awaiting-admin');
            }
        }
    }

        /**
     * Register Custom Bulk Actions
     * 
     * @param array $bulk_actions Existing bulk actions
     * @return array Modified bulk actions
     */
    public function register_custom_bulk_actions($bulk_actions) {
        // Add custom bulk actions
        $bulk_actions['custom_export'] = __('Export Orders', 'your-textdomain');
        $bulk_actions['custom_process'] = __('Custom Process', 'your-textdomain');
        
        return $bulk_actions;
    }
    public function custom_bulk_action_js() {
        global $post_type;
        if ($post_type == 'shop_order') {
            ?>
            <script type="text/javascript">
                jQuery(function($) {
                    $('select[name="action"], select[name="action2"]').change(function() {
                        if ($(this).val() == 'create_parcel') {
                            let orderIds = [];
                            let element = document.getElementById("post_ID");
                            if (element && element.value) {
                                orderIds.push(element.value);
                            }else{
                                $('tbody th.check-column input[type="checkbox"]:checked').each(function() {
                                    let orderId = $(this).val();
                                    if(orderId !== "on") orderIds.push(orderId);
                                });
                            }
                            if (orderIds.length > 0) {
                                $('#customParcelModal').remove();
                                $('body').append(`
                                    <div id="customParcelModal" style="position:fixed;top:20%;left:50%;transform:translate(-50%,0);background:#fff;padding:20px;border:1px solid #ccc;z-index:10000">
                                        <h3>Enter Shipping Details</h3>
                                        <input type="text" id="shipping_weight" placeholder="Weight (kg)">
                                        <input type="text" id="shipping_dimensions" placeholder="Dimensions (LxWxH)">
                                        <button id="submitParcel">Submit</button>
                                        <button id="closeParcelModal">Close</button>
                                    </div>
                                `);
    
                                $('#closeParcelModal').click(function() {
                                    $('#customParcelModal').remove();
                                });
    
                                $('#submitParcel').click(function() {
                                    let weight = $('#shipping_weight').val();
                                    let dimensions = $('#shipping_dimensions').val();
    
                                    $.ajax({
                                        url: ajaxurl,
                                        type: 'POST',
                                        data: {
                                            action: 'send_parcel_data',
                                            orders: orderIds,
                                            weight: weight,
                                            dimensions: dimensions
                                        },
                                        success: function(response) {
                                            alert('Parcel created successfully!');
                                            $('#customParcelModal').remove();
                                        }
                                    });
                                });
                            } else {
                                alert('Please select at least one order.');
                            }
    
                            $(this).val('');
                        }
                    });
                });
            </script>
            <?php
        }
    }
    public function ts_custom_bulk_action_mark_shipped() {
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                if ($('select[name="action"]').length > 0) {
                    $('<option>')
                        .val('mark_shipped')
                        .text('<?php _e('Mark Shipped', 'text-domain'); ?>')
                        .appendTo('select[name="action"]');
                }
                if ($('select[name="action2"]').length > 0) {
                    $('<option>')
                        .val('mark_shipped')
                        .text('<?php _e('Mark Shipped', 'text-domain'); ?>')
                        .appendTo('select[name="action2"]');
                }

                $('form#posts-filter').on('submit', function(e) {
                    e.preventDefault();
                    alert("before ajax");
                    // The bulk action can appear in two places (top and bottom).
                    var bulkAction = $('select[name="action"], select[name="action2"]').filter(function() {
                        return $(this).val() === 'export_order_csv';
                    });

                    // Only proceed if the export action is selected.
                    if (bulkAction.length) {
                        e.preventDefault(); // Prevent the default form submission.

                        // Get the selected order IDs from the checkboxes.
                        var orderIds = [];
                        $('input[name="post[]"]:checked').each(function() {
                            orderIds.push($(this).val());
                        });

                        if ( orderIds.length === 0 ) {
                            alert('No orders selected.');
                            return;
                        }

                        // Send the list of order IDs to our AJAX endpoint.
                        $.ajax({
                            url: wcExportBulk.ajax_url,
                            type: 'POST',
                            dataType: 'json',
                            data: {
                                action: 'navex_orders_create',
                                order_ids: orderIds,
                                _ajax_nonce: wcExportBulk.nonce
                            },
                            success: function(response) {
                                if ( response.success ) {
                                    // Generate a Blob from the CSV text.
                                    var csv = response.data.csv;
                                    var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
                                    var url = URL.createObjectURL(blob);

                                    // Create a temporary link element to trigger the download.
                                    var a = document.createElement('a');
                                    a.href = url;
                                    a.download = 'orders_export.csv';
                                    document.body.appendChild(a);
                                    a.click();
                                    document.body.removeChild(a);
                                } else {
                                    alert('Error: ' + response.data);
                                }
                            },
                            error: function(xhr, status, error) {
                                alert('AJAX error: ' + error);
                            }
                        });
                    }
                });
            });
        </script>
        <?php
    }
    
    
}

// Admin assets directory
if (!file_exists(NAVEXWP_PLUGIN_DIR . 'assets/js')) {
    mkdir(NAVEXWP_PLUGIN_DIR . 'assets/js', 0755, true);
}

// CSS directory
if (!file_exists(NAVEXWP_PLUGIN_DIR . 'assets/css')) {
    mkdir(NAVEXWP_PLUGIN_DIR . 'assets/css', 0755, true);
}

// Create admin css file if it doesn't exist

// Includes directory
if (!file_exists(NAVEXWP_PLUGIN_DIR . 'includes')) {
    mkdir(NAVEXWP_PLUGIN_DIR . 'includes', 0755, true);
}

// Initialize plugin
function navexwp_init() {
    return NavexWp::instance();
}

add_action('plugins_loaded', 'navexwp_init');


function navexwp_activate() {
    // Create database tables if needed
    global $wpdb;
    
    $charset_collate = $wpdb->get_charset_collate();
    $table_name = $wpdb->prefix . 'navexwp_tracking_history';
    
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        order_id bigint(20) NOT NULL,
        tracking_code varchar(50) NOT NULL,
        status varchar(50) NOT NULL,
        status_details text NULL,
        timestamp datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id),
        KEY order_track (order_id, tracking_code)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    // Add capabilities
    $role = get_role('administrator');
    if ($role) {
        $role->add_cap('manage_navexwp');
    }
    
    $role = get_role('shop_manager');
    if ($role) {
        $role->add_cap('manage_navexwp');
    }
}

if ( is_admin() ) {
    wp_localize_script('my-custom-script', 'ajax_object', array(
            'ajaxurl' => admin_url('admin-ajax.php')
    ));
    // Enqueue custom admin script and styles on the WooCommerce orders list page.
    add_action( 'admin_enqueue_scripts', function( $hook ) {
        if ($hook === 'woocommerce_page_wc-orders') {
            wp_enqueue_script( 'woo-order-exporter-js', plugin_dir_url( __FILE__ ) . 'assets/js/woo-order-exporter.js', array( 'jquery' ), '1.0', true );
            wp_enqueue_style( 'woo-order-exporter-css', plugin_dir_url( file: __FILE__ ) . 'assets/css/woo-order-exporter.css' );
            wp_localize_script('wobu-custom', 'wobu_ajax_obj', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('wobu_nonce')
            ));
        }
    } );

    // Add the export button in the orders page header.
    add_action( 'restrict_manage_posts', function() {
        $screen = get_current_screen();
        //if ( isset( $screen->id ) && 'edit-shop_order' === $screen->id ) {
            echo '<button type="button" id="woo-order-exporter-button" class="button">Export Orders CSV</button>';
        
    } );
    

    // Output modal HTML in the footer of the orders page.
    
    add_action( 'admin_footer', function( $hook ) {
        //if ($hook === 'woocommerce_page_wc-orders') { ?>
            <script>
                window.url_ajax_admin = "<?= admin_url( 'admin-ajax.php' ) ?>";
                window.url_ajax_admin_none = "<?= wp_create_nonce('wobu_nonce') ?>";
            </script>
        <?php //}
        });

    // Process the Navex shipping

    function update_tracking_status($order_id, $tracking_code) {
        if (empty($tracking_code)) {
            return false;
        }
        //options navex API
        $options = get_option('navexwp_settings');
        $api_endpoint = isset($options['api_endpoint']) ? $options['api_endpoint'] : '';
        $api_username = isset($options['api_username']) ? $options['api_username'] : '';
        $api_key = isset($options['api_key']) ? $options['api_key'] : '';
        // Create API instance
        $api = new NavexWp_API($api_endpoint, $api_key, $api_username);
        
        // Get status
        $response = $api->get_tracking_status($tracking_code);
        
        if ($response && !is_wp_error(thing: $response)) {
            $status = isset($response['etat']) ? sanitize_text_field($response['etat']) : '';
            update_post_meta($order_id, '_navexwp_tracking_status', $status);
            update_post_meta($order_id, '_navexwp_tracking_updated', time());
            
            // Save additional tracking details if available
            if (isset($response['details'])) {
                update_post_meta($order_id, '_navexwp_tracking_details', $response['details']);
            }
            
            return $status;
        }
        
        return false;
    }
    add_action( 'wp_ajax_wobu_update_status', 'woo_order_exporter_process_export' );
    function woo_order_exporter_process_export() {
          // Get designation and order IDs from submitted form.
        $designation = sanitize_text_field( $_POST['designation'] );
        $order_ids = $_POST['order_ids'];
        $totalNumber = count($order_ids);
        $totalError = 0;
        $listIdOrder = [];
        
        if ( empty( $order_ids ) ) {
            wp_die( 'No orders selected.' );
        }

        //options navex API
        $options = get_option('navexwp_settings');
        $api_endpoint = isset($options['api_endpoint']) ? $options['api_endpoint'] : '';
        $api_username = isset($options['api_username']) ? $options['api_username'] : '';
        $api_key = isset($options['api_key']) ? $options['api_key'] : '';
        $api_designation = isset($options['api_designation']) ? $options['api_designation'] : '';

        foreach($order_ids as $order_id){
            $tracking_code = get_post_meta($order_id, '_navexwp_tracking_code', true);
            
            //if (!$tracking_code) {
                // Create API instance
                $api = new NavexWp_API($api_endpoint, $api_key,$api_username);
                $order = wc_get_order($order_id);
                
                // Prepare order data for API
                $items = $order->get_items();
                $productsList =  "";
                $index = 0;
                foreach ($items as $item_id => $item) {
                    if($index!=0){
                        $productsList =  $productsList . ",";
                    }
                    $productsList =  $productsList . "," .$item->get_name();
                    $index++;
                }
                $data = [
                    'prix' => floatval($order->get_total()),
                    'nom' => sanitize_text_field($order->get_shipping_first_name()." ". $order->get_shipping_last_name() ),
                    'gouvernerat' => ($order->get_shipping_city())? $order->get_shipping_city() : $order->get_billing_city(), //($order->get_shipping_state())? $order->get_shipping_state() : $order->get_billing_state(),
                    'ville' => ($order->get_shipping_city())? $order->get_shipping_city() : $order->get_billing_city(),
                    'adresse' => ($order->get_shipping_address_1()) ? $order->get_shipping_address_1() : $order->get_billing_address_1(),
                    'tel' => ($order->get_shipping_phone()) ? $order->get_shipping_phone() : $order->get_billing_phone(),
                    'tel2' => ($order->get_shipping_phone()) ? $order->get_shipping_phone() : $order->get_billing_phone(),
                    'designation' => sanitize_textarea_field($api_designation),
                    'nb_article' => intval(count($items)),
                    'msg' => sanitize_textarea_field($order->get_customer_note()),
                    'echange' => 0,
                    'article' => sanitize_text_field($productsList),
                    'nb_echange' => intval(0),
                    'ouvrir' =>'oui',
                    'code_suivi' => "",
                    'order_id' => $order_id,
                    'customer_name' => $order->get_formatted_shipping_full_name(),
                    'shipping_address' => $order->get_address('shipping'),
                ];
                error_log(print_r($data, true));
                // Get tracking code from API
                $response = $api->request_tracking_code($data);
                if ($response && !is_wp_error($response) && isset($response['status_message'])) {
                    $tracking_code = sanitize_text_field($response['status_message']);
                    $tracking_link = $response['lien'];
                    // Save tracking code
                    update_post_meta($order_id, '_navexwp_tracking_code', meta_value: $tracking_code);
                    update_post_meta($order_id, '_navexwp_tracking_link', meta_value: $tracking_link);
                    
                    // Get initial status
                    update_tracking_status($order_id, $tracking_code);
                    $order->update_status("on-hold", 'Status updated programmatically.');
                }else{
                    $totalError++;
                    $listIdOrder[] = $order_id;
                }
            //}
        }
        if($totalError == 0){
            wp_send_json_success(array('navex' => "ok"));
        }else{
            wp_send_json_success(array('navex' => "Some order not shipped with Navex","ErrorId"=>implode(', ', $listIdOrder)));
        }
        
    }
}