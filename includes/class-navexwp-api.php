<?php
/**
 * NavexWp API Class
 *
 * Handles all API interactions for the NavexWp plugin
 *
 * @package NavexWp
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class NavexWp_API {
    /**
     * API endpoint
     *
     * @var string
     */
    private $endpoint;
    
    /**
     * API username
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
     * Constructor
     */
    public function __construct($endpoint, $api_key, $api_username) {
        $this->endpoint = $endpoint;
        $this->api_key = $api_key;
        $this->api_username = $api_username;
    }
    
    /**
     * Make API request
     */
    private function make_request($path, $method = 'GET', $data = array()) {
        $url = trailingslashit($this->endpoint) . $path;
        
        $args = array(
            'method'    => $method,
            'timeout'   => 30,
            'headers'   => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json'
            )
        );
        
        if (!empty($data) && in_array($method, array('POST', 'PUT'))) {
            $args['body'] = json_encode($data);
        }
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $code = wp_remote_retrieve_response_code($response);
        
        if ($code < 200 || $code >= 300) {
            return new WP_Error('api_error', sprintf(__('API request failed with code %d', 'navexwp'), $code));
        }
        
        return json_decode($body, true);
    }
    
    /**
     * Request tracking code
     */
    public function request_tracking_code($order_data) {
        
        if (empty($this->endpoint)) {
            return new WP_Error('api_not_configured', __('API URL is not configured.', 'navexwp'));
        }
        $api_url = trailingslashit($this->endpoint) .'/'. $this->api_username .'-'. $this->api_key .'/v1/post.php';
        
        $response = wp_remote_post($api_url, array(
            'method'    => 'POST',
            'body'      => $order_data,
            'timeout'   => 45, // Ajustable selon les besoins
            'headers'   => array(
                'Content-Type' => 'application/x-www-form-urlencoded' // Format classique pour formulaire
            )
        ));
        
        if (is_wp_error($response)) {
            // Gestion d'erreur
            $error_message = $response->get_error_message();
            echo "Erreur : $error_message";
        } else {
            // Récupérer la réponse
            $body = wp_remote_retrieve_body($response);
            return json_decode($body, true);
        }
    }
    
    /**
     * Get tracking status
     */
    public function get_tracking_status($tracking_code) {
        
        if (empty($this->endpoint)) {
            return new WP_Error('api_not_configured', __('API URL is not configured.', 'navexwp'));
        }
        $status_endpoint = trailingslashit($this->endpoint) .'/'. $this->api_username . "-etat-" . $this->api_key .'/v1/post.php';
    
        $body = array(
            'code' => $tracking_code
        );
        $response = wp_remote_post($status_endpoint, array(
            'method'    => 'POST',
            'body'      => $body,
            'timeout'   => 45, // Adjust as needed
            'headers'   => array(
                'Content-Type' => 'application/x-www-form-urlencoded'
            )
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);
        
        if ($response_code < 200 || $response_code >= 300) {
            $error_message = !empty($response_data['message']) 
                ? $response_data['message'] 
                : __('Unknown API error.', 'navexwp');
                
            return new WP_Error('api_error', $error_message);
        }
        return $response_data;
    }
}