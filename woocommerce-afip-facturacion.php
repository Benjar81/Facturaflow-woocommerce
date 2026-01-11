<?php
/**
 * Plugin Name: WooCommerce AFIP Facturación Electrónica
 * Plugin URI: https://tu-sitio.com/woocommerce-afip
 * Description: Facturación electrónica automática para Argentina. Emite facturas A, B y C directamente desde WooCommerce a AFIP.
 * Version: 1.0.0
 * Author: Tu Empresa
 * Author URI: https://tu-sitio.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wc-afip-facturacion
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Definir constantes del plugin
define('WCAFIP_VERSION', '1.0.0');
define('WCAFIP_PLUGIN_FILE', __FILE__);
define('WCAFIP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WCAFIP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WCAFIP_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Clase principal del plugin
 */
final class WC_AFIP_Facturacion {
    
    /**
     * Instancia única (Singleton)
     */
    private static $instance = null;
    
    /**
     * Obtener instancia
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Inicializar hooks
     */
    private function init_hooks() {
        // Verificar dependencias al activar
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Cargar plugin después de que WooCommerce esté listo
        add_action('plugins_loaded', array($this, 'init_plugin'));
        
        // Declarar compatibilidad con HPOS de WooCommerce
        add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));
    }
    
    /**
     * Activación del plugin
     */
    public function activate() {
        // Verificar que WooCommerce esté activo
        if (!class_exists('WooCommerce')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(
                __('Este plugin requiere WooCommerce. Por favor, instala y activa WooCommerce primero.', 'wc-afip-facturacion'),
                'Plugin Dependency Check',
                array('back_link' => true)
            );
        }
        
        // Crear tablas personalizadas
        $this->create_tables();
        
        // Crear directorio para certificados
        $this->create_certificates_dir();
        
        // Guardar versión
        update_option('wcafip_version', WCAFIP_VERSION);
        
        // Limpiar caché de rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Desactivación del plugin
     */
    public function deactivate() {
        // Limpiar eventos programados de licencia
        wp_clear_scheduled_hook('wcafip_license_check_event');

        flush_rewrite_rules();
    }
    
    /**
     * Crear tablas de base de datos
     */
    private function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Tabla de facturas
        $table_facturas = $wpdb->prefix . 'afip_facturas';
        $sql_facturas = "CREATE TABLE IF NOT EXISTS $table_facturas (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            tipo_comprobante int(3) NOT NULL,
            punto_venta int(5) NOT NULL,
            numero_comprobante int(8) NOT NULL,
            cae varchar(20) DEFAULT NULL,
            cae_fecha_vto date DEFAULT NULL,
            receptor_cuit varchar(13) DEFAULT NULL,
            receptor_dni varchar(10) DEFAULT NULL,
            receptor_nombre varchar(255) NOT NULL,
            receptor_condicion_iva int(2) NOT NULL,
            importe_neto decimal(12,2) NOT NULL,
            importe_iva decimal(12,2) DEFAULT 0,
            importe_total decimal(12,2) NOT NULL,
            estado varchar(20) DEFAULT 'pendiente',
            pdf_path varchar(255) DEFAULT NULL,
            error_mensaje text DEFAULT NULL,
            fecha_emision datetime NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY cae (cae),
            KEY estado (estado),
            KEY fecha_emision (fecha_emision)
        ) $charset_collate;";
        
        // Tabla de tickets AFIP (caché)
        $table_tickets = $wpdb->prefix . 'afip_tickets';
        $sql_tickets = "CREATE TABLE IF NOT EXISTS $table_tickets (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            cuit varchar(13) NOT NULL,
            token text NOT NULL,
            sign text NOT NULL,
            expiracion datetime NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY cuit (cuit),
            KEY expiracion (expiracion)
        ) $charset_collate;";
        
        // Tabla de log
        $table_log = $wpdb->prefix . 'afip_log';
        $sql_log = "CREATE TABLE IF NOT EXISTS $table_log (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned DEFAULT NULL,
            tipo varchar(50) NOT NULL,
            mensaje text NOT NULL,
            datos text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY tipo (tipo),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_facturas);
        dbDelta($sql_tickets);
        dbDelta($sql_log);
    }
    
    /**
     * Crear directorio para certificados
     */
    private function create_certificates_dir() {
        $upload_dir = wp_upload_dir();
        $certs_dir = $upload_dir['basedir'] . '/afip-certs';
        
        if (!file_exists($certs_dir)) {
            wp_mkdir_p($certs_dir);
            
            // Proteger directorio con .htaccess
            $htaccess = $certs_dir . '/.htaccess';
            file_put_contents($htaccess, "deny from all\n");
            
            // Archivo index.php vacío
            file_put_contents($certs_dir . '/index.php', '<?php // Silence is golden');
        }
    }
    
    /**
     * Inicializar plugin
     */
    public function init_plugin() {
        // Verificar WooCommerce
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        // Cargar traducciones
        load_plugin_textdomain('wc-afip-facturacion', false, dirname(WCAFIP_PLUGIN_BASENAME) . '/languages');

        // Cargar siempre la clase de licencia
        require_once WCAFIP_PLUGIN_DIR . 'includes/class-wcafip-license.php';
        $license = WCAFIP_License::get_instance();

        // Si no hay licencia válida, solo cargar funcionalidad básica de admin
        if (!$license->is_license_valid()) {
            if (is_admin()) {
                // Cargar admin básico para mostrar página de licencia
                require_once WCAFIP_PLUGIN_DIR . 'includes/admin/class-wcafip-admin.php';
                WCAFIP_Admin::get_instance();
            }
            return;
        }

        // Cargar clases completas (solo con licencia válida)
        $this->load_classes();

        // Inicializar componentes
        $this->init_components();
    }
    
    /**
     * Cargar clases del plugin
     */
    private function load_classes() {
        // Clases principales
        require_once WCAFIP_PLUGIN_DIR . 'includes/class-wcafip-logger.php';
        require_once WCAFIP_PLUGIN_DIR . 'includes/class-wcafip-wsaa.php';
        require_once WCAFIP_PLUGIN_DIR . 'includes/class-wcafip-wsfe.php';
        require_once WCAFIP_PLUGIN_DIR . 'includes/class-wcafip-validador-cuit.php';
        require_once WCAFIP_PLUGIN_DIR . 'includes/class-wcafip-pdf.php';
        require_once WCAFIP_PLUGIN_DIR . 'includes/class-wcafip-facturador.php';
        
        // Admin
        if (is_admin()) {
            require_once WCAFIP_PLUGIN_DIR . 'includes/admin/class-wcafip-admin.php';
            require_once WCAFIP_PLUGIN_DIR . 'includes/admin/class-wcafip-settings.php';
            require_once WCAFIP_PLUGIN_DIR . 'includes/admin/class-wcafip-meta-boxes.php';
        }
        
        // Frontend
        require_once WCAFIP_PLUGIN_DIR . 'includes/class-wcafip-checkout.php';
        require_once WCAFIP_PLUGIN_DIR . 'includes/class-wcafip-my-account.php';
    }
    
    /**
     * Inicializar componentes
     */
    private function init_components() {
        // Logger
        WCAFIP_Logger::get_instance();
        
        // Facturador automático
        WCAFIP_Facturador::get_instance();
        
        // Campos de checkout
        WCAFIP_Checkout::get_instance();
        
        // Mi cuenta
        WCAFIP_My_Account::get_instance();
        
        // Admin
        if (is_admin()) {
            WCAFIP_Admin::get_instance();
            WCAFIP_Settings::get_instance();
            WCAFIP_Meta_Boxes::get_instance();
        }
    }
    
    /**
     * Aviso de WooCommerce faltante
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p>
                <strong><?php _e('WooCommerce AFIP Facturación', 'wc-afip-facturacion'); ?></strong>
                <?php _e('requiere WooCommerce para funcionar. Por favor, instala y activa WooCommerce.', 'wc-afip-facturacion'); ?>
            </p>
        </div>
        <?php
    }
    
    /**
     * Declarar compatibilidad con HPOS
     */
    public function declare_hpos_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    }
}

/**
 * Función para obtener instancia del plugin
 */
function wcafip() {
    return WC_AFIP_Facturacion::get_instance();
}

// Iniciar plugin
wcafip();
