<?php
/**
 * Clase Admin - Panel de administración
 * 
 * @package WC_AFIP_Facturacion
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCAFIP_Admin {
    
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
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_filter('plugin_action_links_' . WCAFIP_PLUGIN_BASENAME, array($this, 'add_action_links'));
    }
    
    /**
     * Agregar menú de administración
     */
    public function add_admin_menu() {
        add_menu_page(
            __('AFIP Facturación', 'wc-afip-facturacion'),
            __('AFIP Facturación', 'wc-afip-facturacion'),
            'manage_woocommerce',
            'wcafip-facturas',
            array($this, 'render_facturas_page'),
            'dashicons-media-spreadsheet',
            56
        );
        
        add_submenu_page(
            'wcafip-facturas',
            __('Facturas', 'wc-afip-facturacion'),
            __('Facturas', 'wc-afip-facturacion'),
            'manage_woocommerce',
            'wcafip-facturas',
            array($this, 'render_facturas_page')
        );
        
        add_submenu_page(
            'wcafip-facturas',
            __('Configuración', 'wc-afip-facturacion'),
            __('Configuración', 'wc-afip-facturacion'),
            'manage_woocommerce',
            'wcafip-settings',
            array($this, 'render_settings_page')
        );
        
        add_submenu_page(
            'wcafip-facturas',
            __('Logs', 'wc-afip-facturacion'),
            __('Logs', 'wc-afip-facturacion'),
            'manage_woocommerce',
            'wcafip-logs',
            array($this, 'render_logs_page')
        );
    }
    
    /**
     * Enqueue scripts
     */
    public function enqueue_scripts($hook) {
        if (strpos($hook, 'wcafip') === false && strpos($hook, 'woocommerce') === false) {
            return;
        }
        
        wp_enqueue_style(
            'wcafip-admin',
            WCAFIP_PLUGIN_URL . 'admin/css/admin.css',
            array(),
            WCAFIP_VERSION
        );
        
        wp_enqueue_script(
            'wcafip-admin',
            WCAFIP_PLUGIN_URL . 'admin/js/admin.js',
            array('jquery'),
            WCAFIP_VERSION,
            true
        );
        
        wp_localize_script('wcafip-admin', 'wcafip_admin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wcafip_nonce'),
            'emitiendo' => __('Emitiendo factura...', 'wc-afip-facturacion'),
            'exito' => __('Factura emitida correctamente', 'wc-afip-facturacion'),
            'error' => __('Error al emitir factura', 'wc-afip-facturacion'),
        ));
    }
    
    /**
     * Agregar enlaces de acción
     */
    public function add_action_links($links) {
        $plugin_links = array(
            '<a href="' . admin_url('admin.php?page=wcafip-settings') . '">' . __('Configuración', 'wc-afip-facturacion') . '</a>',
        );
        
        return array_merge($plugin_links, $links);
    }
    
    /**
     * Render página de facturas
     */
    public function render_facturas_page() {
        global $wpdb;
        
        // Paginación
        $per_page = 20;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;
        
        // Filtros
        $where = array('1=1');
        $values = array();
        
        if (!empty($_GET['estado'])) {
            $where[] = 'estado = %s';
            $values[] = sanitize_text_field($_GET['estado']);
        }
        
        if (!empty($_GET['fecha_desde'])) {
            $where[] = 'fecha_emision >= %s';
            $values[] = sanitize_text_field($_GET['fecha_desde']) . ' 00:00:00';
        }
        
        if (!empty($_GET['fecha_hasta'])) {
            $where[] = 'fecha_emision <= %s';
            $values[] = sanitize_text_field($_GET['fecha_hasta']) . ' 23:59:59';
        }
        
        $where_sql = implode(' AND ', $where);
        
        // Total de registros
        $total_query = "SELECT COUNT(*) FROM {$wpdb->prefix}afip_facturas WHERE $where_sql";
        if (!empty($values)) {
            $total = $wpdb->get_var($wpdb->prepare($total_query, $values));
        } else {
            $total = $wpdb->get_var($total_query);
        }
        
        // Obtener facturas
        $query = "SELECT * FROM {$wpdb->prefix}afip_facturas WHERE $where_sql ORDER BY fecha_emision DESC LIMIT %d OFFSET %d";
        $values[] = $per_page;
        $values[] = $offset;
        
        $facturas = $wpdb->get_results($wpdb->prepare($query, $values));
        
        $total_pages = ceil($total / $per_page);
        
        ?>
        <div class="wrap">
            <h1><?php _e('Facturas AFIP', 'wc-afip-facturacion'); ?></h1>
            
            <!-- Filtros -->
            <form method="get" class="wcafip-filters">
                <input type="hidden" name="page" value="wcafip-facturas">
                
                <select name="estado">
                    <option value=""><?php _e('Todos los estados', 'wc-afip-facturacion'); ?></option>
                    <option value="emitida" <?php selected(isset($_GET['estado']) && $_GET['estado'] == 'emitida'); ?>><?php _e('Emitida', 'wc-afip-facturacion'); ?></option>
                    <option value="pendiente" <?php selected(isset($_GET['estado']) && $_GET['estado'] == 'pendiente'); ?>><?php _e('Pendiente', 'wc-afip-facturacion'); ?></option>
                    <option value="error" <?php selected(isset($_GET['estado']) && $_GET['estado'] == 'error'); ?>><?php _e('Error', 'wc-afip-facturacion'); ?></option>
                </select>
                
                <input type="date" name="fecha_desde" value="<?php echo esc_attr(isset($_GET['fecha_desde']) ? $_GET['fecha_desde'] : ''); ?>" placeholder="<?php _e('Desde', 'wc-afip-facturacion'); ?>">
                <input type="date" name="fecha_hasta" value="<?php echo esc_attr(isset($_GET['fecha_hasta']) ? $_GET['fecha_hasta'] : ''); ?>" placeholder="<?php _e('Hasta', 'wc-afip-facturacion'); ?>">
                
                <button type="submit" class="button"><?php _e('Filtrar', 'wc-afip-facturacion'); ?></button>
                <a href="<?php echo admin_url('admin.php?page=wcafip-facturas'); ?>" class="button"><?php _e('Limpiar', 'wc-afip-facturacion'); ?></a>
            </form>
            
            <!-- Stats -->
            <div class="wcafip-stats">
                <div class="stat-box">
                    <span class="stat-number"><?php echo esc_html($total); ?></span>
                    <span class="stat-label"><?php _e('Total Facturas', 'wc-afip-facturacion'); ?></span>
                </div>
            </div>
            
            <!-- Tabla -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Factura', 'wc-afip-facturacion'); ?></th>
                        <th><?php _e('Pedido', 'wc-afip-facturacion'); ?></th>
                        <th><?php _e('Cliente', 'wc-afip-facturacion'); ?></th>
                        <th><?php _e('Total', 'wc-afip-facturacion'); ?></th>
                        <th><?php _e('CAE', 'wc-afip-facturacion'); ?></th>
                        <th><?php _e('Fecha', 'wc-afip-facturacion'); ?></th>
                        <th><?php _e('Estado', 'wc-afip-facturacion'); ?></th>
                        <th><?php _e('Acciones', 'wc-afip-facturacion'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($facturas)): ?>
                        <tr>
                            <td colspan="8"><?php _e('No hay facturas.', 'wc-afip-facturacion'); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($facturas as $factura): ?>
                            <?php
                            $letra = WCAFIP_WSFE::get_letra_comprobante($factura->tipo_comprobante);
                            $numero = str_pad($factura->punto_venta, 5, '0', STR_PAD_LEFT) . '-' . 
                                      str_pad($factura->numero_comprobante, 8, '0', STR_PAD_LEFT);
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($letra . ' ' . $numero); ?></strong>
                                </td>
                                <td>
                                    <a href="<?php echo admin_url('post.php?post=' . $factura->order_id . '&action=edit'); ?>">
                                        #<?php echo esc_html($factura->order_id); ?>
                                    </a>
                                </td>
                                <td>
                                    <?php echo esc_html($factura->receptor_nombre); ?>
                                    <?php if ($factura->receptor_cuit): ?>
                                        <br><small><?php echo esc_html(WCAFIP_Validador_CUIT::formatear($factura->receptor_cuit)); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo wc_price($factura->importe_total); ?></td>
                                <td>
                                    <?php echo esc_html($factura->cae); ?>
                                    <?php if ($factura->cae_fecha_vto): ?>
                                        <br><small>Vto: <?php echo esc_html(date('d/m/Y', strtotime($factura->cae_fecha_vto))); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html(date('d/m/Y H:i', strtotime($factura->fecha_emision))); ?></td>
                                <td>
                                    <span class="wcafip-status wcafip-status-<?php echo esc_attr($factura->estado); ?>">
                                        <?php echo esc_html(ucfirst($factura->estado)); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($factura->pdf_path) && file_exists($factura->pdf_path)): ?>
                                        <a href="<?php echo esc_url(add_query_arg(array('download_factura' => $factura->id, 'nonce' => wp_create_nonce('download_factura_' . $factura->id)))); ?>" class="button button-small">
                                            <?php _e('PDF', 'wc-afip-facturacion'); ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <!-- Paginación -->
            <?php if ($total_pages > 1): ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php
                        echo paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total' => $total_pages,
                            'current' => $current_page
                        ));
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render página de configuración
     */
    public function render_settings_page() {
        WCAFIP_Settings::get_instance()->render_page();
    }
    
    /**
     * Render página de logs
     */
    public function render_logs_page() {
        global $wpdb;
        
        $per_page = 50;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;
        
        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}afip_log ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $per_page, $offset
        ));
        
        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}afip_log");
        $total_pages = ceil($total / $per_page);
        
        ?>
        <div class="wrap">
            <h1><?php _e('Logs de AFIP', 'wc-afip-facturacion'); ?></h1>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th width="150"><?php _e('Fecha', 'wc-afip-facturacion'); ?></th>
                        <th width="80"><?php _e('Tipo', 'wc-afip-facturacion'); ?></th>
                        <th width="80"><?php _e('Pedido', 'wc-afip-facturacion'); ?></th>
                        <th><?php _e('Mensaje', 'wc-afip-facturacion'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo esc_html(date('d/m/Y H:i:s', strtotime($log->created_at))); ?></td>
                            <td>
                                <span class="wcafip-log-type wcafip-log-<?php echo esc_attr($log->tipo); ?>">
                                    <?php echo esc_html($log->tipo); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($log->order_id): ?>
                                    <a href="<?php echo admin_url('post.php?post=' . $log->order_id . '&action=edit'); ?>">
                                        #<?php echo esc_html($log->order_id); ?>
                                    </a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($log->mensaje); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <?php if ($total_pages > 1): ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php
                        echo paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'total' => $total_pages,
                            'current' => $current_page
                        ));
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
