<?php
/**
 * Clase Checkout - Campos fiscales en el checkout
 * 
 * @package WC_AFIP_Facturacion
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCAFIP_Checkout {
    
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
        // Agregar campos al checkout
        add_filter('woocommerce_checkout_fields', array($this, 'agregar_campos_fiscales'));
        
        // Validar campos
        add_action('woocommerce_checkout_process', array($this, 'validar_campos_fiscales'));
        
        // Guardar campos
        add_action('woocommerce_checkout_update_order_meta', array($this, 'guardar_campos_fiscales'));
        
        // Mostrar en admin
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'mostrar_campos_admin'));
        
        // Scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    }
    
    /**
     * Agregar campos fiscales al checkout
     */
    public function agregar_campos_fiscales($fields) {
        // Condición de IVA
        $fields['billing']['billing_condicion_iva'] = array(
            'type' => 'select',
            'label' => __('Condición ante IVA', 'wc-afip-facturacion'),
            'required' => true,
            'class' => array('form-row-wide'),
            'priority' => 25,
            'options' => array(
                '' => __('Seleccionar...', 'wc-afip-facturacion'),
                WCAFIP_WSFE::IVA_CONSUMIDOR_FINAL => __('Consumidor Final', 'wc-afip-facturacion'),
                WCAFIP_WSFE::IVA_RESPONSABLE_INSCRIPTO => __('IVA Responsable Inscripto', 'wc-afip-facturacion'),
                WCAFIP_WSFE::IVA_MONOTRIBUTO => __('Responsable Monotributo', 'wc-afip-facturacion'),
                WCAFIP_WSFE::IVA_EXENTO => __('IVA Exento', 'wc-afip-facturacion'),
            )
        );
        
        // CUIT
        $fields['billing']['billing_cuit'] = array(
            'type' => 'text',
            'label' => __('CUIT', 'wc-afip-facturacion'),
            'placeholder' => __('XX-XXXXXXXX-X', 'wc-afip-facturacion'),
            'required' => false,
            'class' => array('form-row-first'),
            'priority' => 26,
        );
        
        // DNI
        $fields['billing']['billing_dni'] = array(
            'type' => 'text',
            'label' => __('DNI', 'wc-afip-facturacion'),
            'placeholder' => __('Solo números', 'wc-afip-facturacion'),
            'required' => false,
            'class' => array('form-row-last'),
            'priority' => 27,
        );
        
        // Razón Social (para empresas)
        $fields['billing']['billing_razon_social'] = array(
            'type' => 'text',
            'label' => __('Razón Social', 'wc-afip-facturacion'),
            'placeholder' => __('Solo si es empresa', 'wc-afip-facturacion'),
            'required' => false,
            'class' => array('form-row-wide', 'wcafip-razon-social-field'),
            'priority' => 28,
        );
        
        return $fields;
    }
    
    /**
     * Validar campos fiscales
     */
    public function validar_campos_fiscales() {
        $condicion_iva = isset($_POST['billing_condicion_iva']) ? intval($_POST['billing_condicion_iva']) : 0;
        $cuit = isset($_POST['billing_cuit']) ? sanitize_text_field($_POST['billing_cuit']) : '';
        $dni = isset($_POST['billing_dni']) ? sanitize_text_field($_POST['billing_dni']) : '';
        
        // Si es Responsable Inscripto, requiere CUIT
        if ($condicion_iva == WCAFIP_WSFE::IVA_RESPONSABLE_INSCRIPTO) {
            if (empty($cuit)) {
                wc_add_notice(__('Para Responsable Inscripto debe ingresar su CUIT.', 'wc-afip-facturacion'), 'error');
                return;
            }
            
            // Validar formato CUIT
            $validacion = WCAFIP_Validador_CUIT::validar_formato($cuit);
            if (!$validacion['valido']) {
                wc_add_notice($validacion['error'], 'error');
                return;
            }
        }
        
        // Si es Monotributista, requiere CUIT
        if ($condicion_iva == WCAFIP_WSFE::IVA_MONOTRIBUTO) {
            if (empty($cuit)) {
                wc_add_notice(__('Para Monotributista debe ingresar su CUIT.', 'wc-afip-facturacion'), 'error');
                return;
            }
            
            $validacion = WCAFIP_Validador_CUIT::validar_formato($cuit);
            if (!$validacion['valido']) {
                wc_add_notice($validacion['error'], 'error');
                return;
            }
        }
        
        // Validar CUIT si se ingresó
        if (!empty($cuit)) {
            $validacion = WCAFIP_Validador_CUIT::validar_formato($cuit);
            if (!$validacion['valido']) {
                wc_add_notice($validacion['error'], 'error');
                return;
            }
        }
        
        // Validar DNI si se ingresó
        if (!empty($dni)) {
            $validacion = WCAFIP_Validador_CUIT::validar_dni($dni);
            if (!$validacion['valido']) {
                wc_add_notice($validacion['error'], 'error');
                return;
            }
        }
        
        // Si es Consumidor Final y no tiene CUIT ni DNI, está OK
        // (se factura como Consumidor Final sin identificar)
    }
    
    /**
     * Guardar campos fiscales
     */
    public function guardar_campos_fiscales($order_id) {
        $order = wc_get_order($order_id);
        
        if (isset($_POST['billing_condicion_iva'])) {
            $order->update_meta_data('_billing_condicion_iva', intval($_POST['billing_condicion_iva']));
        }
        
        if (isset($_POST['billing_cuit']) && !empty($_POST['billing_cuit'])) {
            $cuit = sanitize_text_field($_POST['billing_cuit']);
            $cuit = preg_replace('/[^0-9]/', '', $cuit);
            $order->update_meta_data('_billing_cuit', $cuit);
        }
        
        if (isset($_POST['billing_dni']) && !empty($_POST['billing_dni'])) {
            $dni = sanitize_text_field($_POST['billing_dni']);
            $dni = preg_replace('/[^0-9]/', '', $dni);
            $order->update_meta_data('_billing_dni', $dni);
        }
        
        if (isset($_POST['billing_razon_social']) && !empty($_POST['billing_razon_social'])) {
            $order->update_meta_data('_billing_razon_social', sanitize_text_field($_POST['billing_razon_social']));
        }
        
        $order->save();
    }
    
    /**
     * Mostrar campos en admin
     */
    public function mostrar_campos_admin($order) {
        $condicion_iva = $order->get_meta('_billing_condicion_iva');
        $cuit = $order->get_meta('_billing_cuit');
        $dni = $order->get_meta('_billing_dni');
        $razon_social = $order->get_meta('_billing_razon_social');
        
        $condiciones = array(
            WCAFIP_WSFE::IVA_CONSUMIDOR_FINAL => __('Consumidor Final', 'wc-afip-facturacion'),
            WCAFIP_WSFE::IVA_RESPONSABLE_INSCRIPTO => __('IVA Responsable Inscripto', 'wc-afip-facturacion'),
            WCAFIP_WSFE::IVA_MONOTRIBUTO => __('Responsable Monotributo', 'wc-afip-facturacion'),
            WCAFIP_WSFE::IVA_EXENTO => __('IVA Exento', 'wc-afip-facturacion'),
        );
        
        echo '<div class="wcafip-billing-fields" style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #eee;">';
        echo '<h4 style="margin-bottom: 10px;">' . __('Datos Fiscales', 'wc-afip-facturacion') . '</h4>';
        
        if ($condicion_iva) {
            echo '<p><strong>' . __('Condición IVA:', 'wc-afip-facturacion') . '</strong> ';
            echo isset($condiciones[$condicion_iva]) ? esc_html($condiciones[$condicion_iva]) : $condicion_iva;
            echo '</p>';
        }
        
        if ($cuit) {
            echo '<p><strong>' . __('CUIT:', 'wc-afip-facturacion') . '</strong> ' . esc_html(WCAFIP_Validador_CUIT::formatear($cuit)) . '</p>';
        }
        
        if ($dni) {
            echo '<p><strong>' . __('DNI:', 'wc-afip-facturacion') . '</strong> ' . esc_html($dni) . '</p>';
        }
        
        if ($razon_social) {
            echo '<p><strong>' . __('Razón Social:', 'wc-afip-facturacion') . '</strong> ' . esc_html($razon_social) . '</p>';
        }
        
        echo '</div>';
    }
    
    /**
     * Enqueue scripts
     */
    public function enqueue_scripts() {
        if (!is_checkout()) {
            return;
        }
        
        wp_enqueue_script(
            'wcafip-checkout',
            WCAFIP_PLUGIN_URL . 'assets/js/checkout.js',
            array('jquery'),
            WCAFIP_VERSION,
            true
        );
        
        wp_localize_script('wcafip-checkout', 'wcafip_checkout', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'validando_cuit' => __('Validando CUIT...', 'wc-afip-facturacion'),
            'cuit_valido' => __('CUIT válido', 'wc-afip-facturacion'),
            'cuit_invalido' => __('CUIT inválido', 'wc-afip-facturacion'),
            'IVA_RI' => WCAFIP_WSFE::IVA_RESPONSABLE_INSCRIPTO,
            'IVA_MONO' => WCAFIP_WSFE::IVA_MONOTRIBUTO,
        ));
        
        wp_enqueue_style(
            'wcafip-checkout',
            WCAFIP_PLUGIN_URL . 'assets/css/checkout.css',
            array(),
            WCAFIP_VERSION
        );
    }
}
