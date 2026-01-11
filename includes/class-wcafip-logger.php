<?php
/**
 * Clase de Logging
 * 
 * @package WC_AFIP_Facturacion
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCAFIP_Logger {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {}
    
    /**
     * Registrar log en base de datos
     */
    public function log($tipo, $mensaje, $order_id = null, $datos = null) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'afip_log';
        
        $wpdb->insert($table, array(
            'order_id' => $order_id,
            'tipo' => $tipo,
            'mensaje' => $mensaje,
            'datos' => $datos ? json_encode($datos) : null,
            'created_at' => current_time('mysql')
        ), array('%d', '%s', '%s', '%s', '%s'));
        
        // También log en WooCommerce
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $context = array('source' => 'wc-afip-facturacion');
            
            switch ($tipo) {
                case 'error':
                    $logger->error($mensaje, $context);
                    break;
                case 'warning':
                    $logger->warning($mensaje, $context);
                    break;
                default:
                    $logger->info($mensaje, $context);
            }
        }
    }
    
    /**
     * Log de información
     */
    public function info($mensaje, $order_id = null, $datos = null) {
        $this->log('info', $mensaje, $order_id, $datos);
    }
    
    /**
     * Log de error
     */
    public function error($mensaje, $order_id = null, $datos = null) {
        $this->log('error', $mensaje, $order_id, $datos);
    }
    
    /**
     * Log de warning
     */
    public function warning($mensaje, $order_id = null, $datos = null) {
        $this->log('warning', $mensaje, $order_id, $datos);
    }
    
    /**
     * Obtener logs
     */
    public function get_logs($args = array()) {
        global $wpdb;
        
        $defaults = array(
            'order_id' => null,
            'tipo' => null,
            'limit' => 100,
            'offset' => 0,
            'orderby' => 'created_at',
            'order' => 'DESC'
        );
        
        $args = wp_parse_args($args, $defaults);
        $table = $wpdb->prefix . 'afip_log';
        
        $where = array('1=1');
        $values = array();
        
        if ($args['order_id']) {
            $where[] = 'order_id = %d';
            $values[] = $args['order_id'];
        }
        
        if ($args['tipo']) {
            $where[] = 'tipo = %s';
            $values[] = $args['tipo'];
        }
        
        $sql = "SELECT * FROM $table WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY {$args['orderby']} {$args['order']}";
        $sql .= " LIMIT %d OFFSET %d";
        
        $values[] = $args['limit'];
        $values[] = $args['offset'];
        
        return $wpdb->get_results($wpdb->prepare($sql, $values));
    }
    
    /**
     * Limpiar logs antiguos
     */
    public function clean_old_logs($days = 30) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'afip_log';
        $date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $table WHERE created_at < %s",
            $date
        ));
    }
}
