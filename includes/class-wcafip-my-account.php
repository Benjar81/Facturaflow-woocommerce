<?php
/**
 * Clase My Account - Facturas en Mi Cuenta
 * 
 * @package WC_AFIP_Facturacion
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCAFIP_My_Account {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init_hooks();
    }
    
    private function init_hooks() {
        // Agregar endpoint
        add_action('init', array($this, 'add_endpoints'));
        add_filter('query_vars', array($this, 'add_query_vars'));
        
        // Agregar menú en Mi Cuenta
        add_filter('woocommerce_account_menu_items', array($this, 'add_menu_item'));
        
        // Contenido del endpoint
        add_action('woocommerce_account_facturas_endpoint', array($this, 'facturas_content'));
        
        // Mostrar factura en detalle de pedido
        add_action('woocommerce_order_details_after_order_table', array($this, 'mostrar_factura_pedido'));
        
        // Descargar factura
        add_action('init', array($this, 'handle_download'));
    }
    
    /**
     * Agregar endpoint
     */
    public function add_endpoints() {
        add_rewrite_endpoint('facturas', EP_ROOT | EP_PAGES);
    }
    
    /**
     * Agregar query vars
     */
    public function add_query_vars($vars) {
        $vars[] = 'facturas';
        return $vars;
    }
    
    /**
     * Agregar item al menú
     */
    public function add_menu_item($items) {
        $new_items = array();
        
        foreach ($items as $key => $value) {
            $new_items[$key] = $value;
            
            // Insertar después de pedidos
            if ($key === 'orders') {
                $new_items['facturas'] = __('Facturas', 'wc-afip-facturacion');
            }
        }
        
        return $new_items;
    }
    
    /**
     * Contenido de facturas
     */
    public function facturas_content() {
        global $wpdb;
        
        $user_id = get_current_user_id();
        
        // Obtener pedidos del usuario
        $orders = wc_get_orders(array(
            'customer_id' => $user_id,
            'limit' => -1,
            'return' => 'ids'
        ));
        
        if (empty($orders)) {
            echo '<p>' . __('No tienes facturas.', 'wc-afip-facturacion') . '</p>';
            return;
        }
        
        // Obtener facturas de esos pedidos
        $placeholders = implode(',', array_fill(0, count($orders), '%d'));
        $facturas = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}afip_facturas 
             WHERE order_id IN ($placeholders) AND estado = 'emitida' 
             ORDER BY fecha_emision DESC",
            $orders
        ));
        
        if (empty($facturas)) {
            echo '<p>' . __('No tienes facturas emitidas.', 'wc-afip-facturacion') . '</p>';
            return;
        }
        
        ?>
        <h3><?php _e('Mis Facturas', 'wc-afip-facturacion'); ?></h3>
        
        <table class="woocommerce-orders-table woocommerce-MyAccount-orders shop_table shop_table_responsive">
            <thead>
                <tr>
                    <th><?php _e('Factura', 'wc-afip-facturacion'); ?></th>
                    <th><?php _e('Pedido', 'wc-afip-facturacion'); ?></th>
                    <th><?php _e('Fecha', 'wc-afip-facturacion'); ?></th>
                    <th><?php _e('Total', 'wc-afip-facturacion'); ?></th>
                    <th><?php _e('CAE', 'wc-afip-facturacion'); ?></th>
                    <th><?php _e('Acciones', 'wc-afip-facturacion'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($facturas as $factura): ?>
                    <?php
                    $letra = WCAFIP_WSFE::get_letra_comprobante($factura->tipo_comprobante);
                    $numero = str_pad($factura->punto_venta, 5, '0', STR_PAD_LEFT) . '-' . 
                              str_pad($factura->numero_comprobante, 8, '0', STR_PAD_LEFT);
                    ?>
                    <tr>
                        <td data-title="<?php _e('Factura', 'wc-afip-facturacion'); ?>">
                            <strong><?php echo esc_html($letra . ' ' . $numero); ?></strong>
                        </td>
                        <td data-title="<?php _e('Pedido', 'wc-afip-facturacion'); ?>">
                            <a href="<?php echo esc_url(wc_get_endpoint_url('view-order', $factura->order_id, wc_get_page_permalink('myaccount'))); ?>">
                                #<?php echo esc_html($factura->order_id); ?>
                            </a>
                        </td>
                        <td data-title="<?php _e('Fecha', 'wc-afip-facturacion'); ?>">
                            <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($factura->fecha_emision))); ?>
                        </td>
                        <td data-title="<?php _e('Total', 'wc-afip-facturacion'); ?>">
                            <?php echo wc_price($factura->importe_total); ?>
                        </td>
                        <td data-title="<?php _e('CAE', 'wc-afip-facturacion'); ?>">
                            <?php echo esc_html($factura->cae); ?>
                        </td>
                        <td data-title="<?php _e('Acciones', 'wc-afip-facturacion'); ?>">
                            <?php if (!empty($factura->pdf_path) && file_exists($factura->pdf_path)): ?>
                                <a href="<?php echo esc_url(add_query_arg(array('download_factura' => $factura->id, 'nonce' => wp_create_nonce('download_factura_' . $factura->id)))); ?>" class="woocommerce-button button">
                                    <?php _e('Descargar PDF', 'wc-afip-facturacion'); ?>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
    
    /**
     * Mostrar factura en detalle de pedido
     */
    public function mostrar_factura_pedido($order) {
        $facturador = WCAFIP_Facturador::get_instance();
        $factura = $facturador->get_factura_by_order($order->get_id());
        
        if (!$factura) {
            return;
        }
        
        $letra = WCAFIP_WSFE::get_letra_comprobante($factura->tipo_comprobante);
        $numero = str_pad($factura->punto_venta, 5, '0', STR_PAD_LEFT) . '-' . 
                  str_pad($factura->numero_comprobante, 8, '0', STR_PAD_LEFT);
        
        ?>
        <section class="woocommerce-factura-details">
            <h2><?php _e('Factura', 'wc-afip-facturacion'); ?></h2>
            
            <table class="woocommerce-table shop_table">
                <tbody>
                    <tr>
                        <th><?php _e('Tipo:', 'wc-afip-facturacion'); ?></th>
                        <td><?php echo esc_html('Factura ' . $letra); ?></td>
                    </tr>
                    <tr>
                        <th><?php _e('Número:', 'wc-afip-facturacion'); ?></th>
                        <td><?php echo esc_html($numero); ?></td>
                    </tr>
                    <tr>
                        <th><?php _e('CAE:', 'wc-afip-facturacion'); ?></th>
                        <td><?php echo esc_html($factura->cae); ?></td>
                    </tr>
                    <tr>
                        <th><?php _e('Vto. CAE:', 'wc-afip-facturacion'); ?></th>
                        <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($factura->cae_fecha_vto))); ?></td>
                    </tr>
                    <?php if (!empty($factura->pdf_path) && file_exists($factura->pdf_path)): ?>
                    <tr>
                        <th><?php _e('PDF:', 'wc-afip-facturacion'); ?></th>
                        <td>
                            <a href="<?php echo esc_url(add_query_arg(array('download_factura' => $factura->id, 'nonce' => wp_create_nonce('download_factura_' . $factura->id)))); ?>" class="button">
                                <?php _e('Descargar Factura', 'wc-afip-facturacion'); ?>
                            </a>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>
        <?php
    }
    
    /**
     * Manejar descarga de factura
     */
    public function handle_download() {
        if (!isset($_GET['download_factura']) || !isset($_GET['nonce'])) {
            return;
        }
        
        $factura_id = intval($_GET['download_factura']);
        $nonce = sanitize_text_field($_GET['nonce']);
        
        if (!wp_verify_nonce($nonce, 'download_factura_' . $factura_id)) {
            wp_die(__('Enlace inválido', 'wc-afip-facturacion'));
        }
        
        global $wpdb;
        
        $factura = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}afip_facturas WHERE id = %d",
            $factura_id
        ));
        
        if (!$factura) {
            wp_die(__('Factura no encontrada', 'wc-afip-facturacion'));
        }
        
        // Verificar que el usuario tenga acceso
        if (!current_user_can('manage_woocommerce')) {
            $order = wc_get_order($factura->order_id);
            if (!$order || $order->get_customer_id() != get_current_user_id()) {
                wp_die(__('No tienes acceso a esta factura', 'wc-afip-facturacion'));
            }
        }
        
        if (empty($factura->pdf_path) || !file_exists($factura->pdf_path)) {
            wp_die(__('El archivo PDF no está disponible', 'wc-afip-facturacion'));
        }
        
        $letra = WCAFIP_WSFE::get_letra_comprobante($factura->tipo_comprobante);
        $numero = str_pad($factura->punto_venta, 5, '0', STR_PAD_LEFT) . '-' . 
                  str_pad($factura->numero_comprobante, 8, '0', STR_PAD_LEFT);
        $filename = 'Factura_' . $letra . '_' . $numero . '.pdf';
        
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($factura->pdf_path));
        
        readfile($factura->pdf_path);
        exit;
    }
}
