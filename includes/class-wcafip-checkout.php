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
        add_filter('woocommerce_checkout_fields', array($this, 'agregar_campos_fiscales'));
        add_action('woocommerce_checkout_process', array($this, 'validar_campos_fiscales'));
        add_action('woocommerce_checkout_update_order_meta', array($this, 'guardar_campos_fiscales'));
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'mostrar_campos_admin'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    /**
     * Agregar campos fiscales al checkout
     */
    public function agregar_campos_fiscales($fields) {
        $permitir_factura_a = get_option('wcafip_permitir_factura_a', 'yes') === 'yes';

        // Tipo de documento: dropdown DNI / CUIT
        if ($permitir_factura_a) {
            $fields['billing']['billing_tipo_doc'] = array(
                'type' => 'select',
                'label' => __('Tipo de Documento', 'wc-afip-facturacion'),
                'required' => true,
                'class' => array('form-row-first', 'wcafip-tipo-doc-field'),
                'priority' => 25,
                'options' => array(
                    'dni' => __('DNI', 'wc-afip-facturacion'),
                    'cuit' => __('CUIT', 'wc-afip-facturacion'),
                ),
            );
        } else {
            // Si no se permite Factura A, campo oculto fijo en DNI
            $fields['billing']['billing_tipo_doc'] = array(
                'type' => 'hidden',
                'default' => 'dni',
                'priority' => 25,
            );
        }

        // Número de documento (el placeholder y validación cambian según el tipo elegido via JS)
        $fields['billing']['billing_nro_doc'] = array(
            'type' => 'text',
            'label' => $permitir_factura_a ? __('Número de Documento', 'wc-afip-facturacion') : __('DNI', 'wc-afip-facturacion'),
            'placeholder' => __('Ingresá tu número de documento', 'wc-afip-facturacion'),
            'required' => true,
            'class' => array($permitir_factura_a ? 'form-row-last' : 'form-row-wide', 'wcafip-nro-doc-field'),
            'priority' => 26,
        );

        // Razón Social (solo visible cuando se elige CUIT)
        $fields['billing']['billing_razon_social'] = array(
            'type' => 'text',
            'label' => __('Razón Social', 'wc-afip-facturacion'),
            'placeholder' => __('Solo si es empresa', 'wc-afip-facturacion'),
            'required' => false,
            'class' => array('form-row-wide', 'wcafip-razon-social-field'),
            'priority' => 27,
        );

        // Condición de IVA (solo visible cuando se elige CUIT)
        if ($permitir_factura_a) {
            $fields['billing']['billing_condicion_iva'] = array(
                'type' => 'select',
                'label' => __('Condición ante IVA', 'wc-afip-facturacion'),
                'required' => false,
                'class' => array('form-row-wide', 'wcafip-condicion-iva-field'),
                'priority' => 28,
                'options' => array(
                    '' => __('Seleccionar...', 'wc-afip-facturacion'),
                    WCAFIP_WSFE::IVA_RESPONSABLE_INSCRIPTO => __('IVA Responsable Inscripto', 'wc-afip-facturacion'),
                    WCAFIP_WSFE::IVA_MONOTRIBUTO => __('Responsable Monotributo', 'wc-afip-facturacion'),
                    WCAFIP_WSFE::IVA_EXENTO => __('IVA Exento', 'wc-afip-facturacion'),
                )
            );
        }

        return $fields;
    }

    /**
     * Validar campos fiscales
     */
    public function validar_campos_fiscales() {
        $tipo_doc = isset($_POST['billing_tipo_doc']) ? sanitize_text_field($_POST['billing_tipo_doc']) : 'dni';
        $nro_doc = isset($_POST['billing_nro_doc']) ? sanitize_text_field($_POST['billing_nro_doc']) : '';

        // El documento es obligatorio
        if (empty($nro_doc)) {
            wc_add_notice(__('Debe ingresar su número de documento.', 'wc-afip-facturacion'), 'error');
            return;
        }

        if ($tipo_doc === 'cuit') {
            // Validar formato CUIT
            $validacion = WCAFIP_Validador_CUIT::validar_formato($nro_doc);
            if (!$validacion['valido']) {
                wc_add_notice($validacion['error'], 'error');
                return;
            }

            // Condición IVA requerida cuando es CUIT
            $condicion_iva = isset($_POST['billing_condicion_iva']) ? intval($_POST['billing_condicion_iva']) : 0;
            if (empty($condicion_iva)) {
                wc_add_notice(__('Debe seleccionar su condición ante IVA.', 'wc-afip-facturacion'), 'error');
                return;
            }
        } else {
            // Validar DNI
            $validacion = WCAFIP_Validador_CUIT::validar_dni($nro_doc);
            if (!$validacion['valido']) {
                wc_add_notice($validacion['error'], 'error');
                return;
            }
        }
    }

    /**
     * Guardar campos fiscales
     */
    public function guardar_campos_fiscales($order_id) {
        $order = wc_get_order($order_id);
        $tipo_doc = isset($_POST['billing_tipo_doc']) ? sanitize_text_field($_POST['billing_tipo_doc']) : 'dni';
        $nro_doc = isset($_POST['billing_nro_doc']) ? sanitize_text_field($_POST['billing_nro_doc']) : '';
        $nro_doc_limpio = preg_replace('/[^0-9]/', '', $nro_doc);

        $order->update_meta_data('_billing_tipo_doc', $tipo_doc);

        if ($tipo_doc === 'cuit') {
            $order->update_meta_data('_billing_cuit', $nro_doc_limpio);
            $order->update_meta_data('_billing_dni', '');

            // Condición IVA del receptor
            if (isset($_POST['billing_condicion_iva'])) {
                $order->update_meta_data('_billing_condicion_iva', intval($_POST['billing_condicion_iva']));
            }
        } else {
            $order->update_meta_data('_billing_dni', $nro_doc_limpio);
            $order->update_meta_data('_billing_cuit', '');
            // Consumidor Final por defecto para DNI
            $order->update_meta_data('_billing_condicion_iva', WCAFIP_WSFE::IVA_CONSUMIDOR_FINAL);
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
        $tipo_doc = $order->get_meta('_billing_tipo_doc');
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

        if ($tipo_doc === 'cuit' && $cuit) {
            echo '<p><strong>' . __('CUIT:', 'wc-afip-facturacion') . '</strong> ' . esc_html(WCAFIP_Validador_CUIT::formatear($cuit)) . '</p>';
        } elseif ($dni) {
            echo '<p><strong>' . __('DNI:', 'wc-afip-facturacion') . '</strong> ' . esc_html($dni) . '</p>';
        } elseif ($cuit) {
            // Retrocompatibilidad con pedidos anteriores
            echo '<p><strong>' . __('CUIT:', 'wc-afip-facturacion') . '</strong> ' . esc_html(WCAFIP_Validador_CUIT::formatear($cuit)) . '</p>';
        }

        if ($condicion_iva) {
            echo '<p><strong>' . __('Condición IVA:', 'wc-afip-facturacion') . '</strong> ';
            echo isset($condiciones[$condicion_iva]) ? esc_html($condiciones[$condicion_iva]) : $condicion_iva;
            echo '</p>';
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
            'permitir_factura_a' => get_option('wcafip_permitir_factura_a', 'yes') === 'yes',
        ));

        wp_enqueue_style(
            'wcafip-checkout',
            WCAFIP_PLUGIN_URL . 'assets/css/checkout.css',
            array(),
            WCAFIP_VERSION
        );
    }
}
