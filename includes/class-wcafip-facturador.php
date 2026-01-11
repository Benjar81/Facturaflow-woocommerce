<?php
/**
 * Clase Facturador - Emisión automática de facturas
 * 
 * @package WC_AFIP_Facturacion
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCAFIP_Facturador {
    
    private static $instance = null;
    private $logger;
    private $wsfe;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->logger = WCAFIP_Logger::get_instance();
        $this->init_hooks();
    }
    
    private function init_hooks() {
        add_action('woocommerce_payment_complete', array($this, 'facturar_pedido'), 10, 1);
        add_action('woocommerce_order_status_completed', array($this, 'facturar_pedido'), 10, 1);
        add_action('woocommerce_order_status_processing', array($this, 'facturar_pedido'), 10, 1);
        add_action('wcafip_emitir_factura', array($this, 'facturar_pedido'), 10, 1);
        add_action('wp_ajax_wcafip_emitir_factura', array($this, 'ajax_emitir_factura'));
        add_action('wp_ajax_wcafip_regenerar_pdf', array($this, 'ajax_regenerar_pdf'));
        add_action('wp_ajax_wcafip_validar_cuit', array($this, 'ajax_validar_cuit'));
        add_action('wp_ajax_nopriv_wcafip_validar_cuit', array($this, 'ajax_validar_cuit'));
    }
    
    public function facturar_pedido($order_id) {
        if (get_option('wcafip_facturacion_automatica', 'no') !== 'yes') {
            return;
        }
        
        if (!$this->verificar_configuracion()) {
            $this->logger->error('Configuración incompleta', $order_id);
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) return;
        
        if ($this->pedido_tiene_factura($order_id)) return;
        
        $estados_facturar = get_option('wcafip_estados_facturar', array('completed', 'processing'));
        if (!in_array($order->get_status(), $estados_facturar)) return;
        
        try {
            $resultado = $this->emitir_factura($order);
            
            if ($resultado['exito']) {
                $this->logger->info('Factura emitida. CAE: ' . $resultado['cae'], $order_id);
                
                $order->add_order_note(sprintf(
                    __('Factura AFIP emitida. Tipo: %s, Número: %s-%s, CAE: %s', 'wc-afip-facturacion'),
                    WCAFIP_WSFE::get_letra_comprobante($resultado['tipo_comprobante']),
                    str_pad($resultado['punto_venta'], 5, '0', STR_PAD_LEFT),
                    str_pad($resultado['numero_comprobante'], 8, '0', STR_PAD_LEFT),
                    $resultado['cae']
                ));
                
                if (get_option('wcafip_enviar_email', 'yes') === 'yes') {
                    $this->enviar_email_factura($order, $resultado);
                }
            }
        } catch (Exception $e) {
            $this->logger->error('Error: ' . $e->getMessage(), $order_id);
            $order->add_order_note(__('Error al emitir factura AFIP: ', 'wc-afip-facturacion') . $e->getMessage());
        }
    }
    
    public function emitir_factura($order) {
        global $wpdb;

        // Verificar licencia antes de emitir factura
        if (class_exists('WCAFIP_License')) {
            $license = WCAFIP_License::get_instance();
            if (!$license->verify_license_remote()) {
                throw new Exception(__('Licencia inválida o expirada. Por favor verifica tu licencia en FacturaFlow.', 'wc-afip-facturacion'));
            }
        }

        $this->wsfe = new WCAFIP_WSFE();
        $datos_cliente = $this->get_datos_cliente($order);
        $condicion_emisor = get_option('wcafip_condicion_iva', 'responsable_inscripto');
        $tipo_comprobante = WCAFIP_WSFE::determinar_tipo_factura($condicion_emisor, $datos_cliente['condicion_iva']);
        $importes = $this->calcular_importes($order, $tipo_comprobante);

        $datos_factura = array(
            'tipo_comprobante' => $tipo_comprobante,
            'concepto' => $this->get_concepto($order),
            'cuit' => $datos_cliente['cuit'],
            'dni' => $datos_cliente['dni'],
            'condicion_iva_receptor' => $datos_cliente['condicion_iva'],
            'importe_neto' => $importes['neto'],
            'importe_iva' => $importes['iva'],
            'importe_total' => $importes['total']
        );

        $resultado = $this->wsfe->emitir_factura($datos_factura);
        $factura_id = $this->guardar_factura($order->get_id(), $resultado, $datos_cliente, $importes);

        $pdf = new WCAFIP_PDF();
        $factura = $this->get_factura($factura_id);
        $pdf_result = $pdf->generar($factura, $order);

        $wpdb->update(
            $wpdb->prefix . 'afip_facturas',
            array('pdf_path' => $pdf_result['path']),
            array('id' => $factura_id)
        );

        $resultado['factura_id'] = $factura_id;
        $resultado['pdf_url'] = $pdf_result['url'];
        $resultado['pdf_path'] = $pdf_result['path'];

        // Sincronizar factura con FacturaFlow
        $this->sync_invoice_to_facturaflow($factura, $order, $importes);

        return $resultado;
    }
    
    private function get_datos_cliente($order) {
        $cuit = $order->get_meta('_billing_cuit');
        $dni = $order->get_meta('_billing_dni');
        $condicion_iva = $order->get_meta('_billing_condicion_iva');
        
        if (!empty($cuit)) {
            $padron = WCAFIP_Validador_CUIT::consultar_padron($cuit);
            if ($padron['encontrado']) {
                return array(
                    'cuit' => str_replace('-', '', $cuit),
                    'dni' => null,
                    'nombre' => $padron['razon_social'],
                    'condicion_iva' => $padron['condicion_iva_codigo']
                );
            }
        }
        
        if (!empty($condicion_iva)) {
            return array(
                'cuit' => !empty($cuit) ? str_replace('-', '', $cuit) : null,
                'dni' => !empty($dni) ? $dni : null,
                'nombre' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'condicion_iva' => intval($condicion_iva)
            );
        }
        
        return array(
            'cuit' => null,
            'dni' => !empty($dni) ? $dni : null,
            'nombre' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'condicion_iva' => WCAFIP_WSFE::IVA_CONSUMIDOR_FINAL
        );
    }
    
    private function calcular_importes($order, $tipo_comprobante) {
        $total = floatval($order->get_total());
        
        // El IVA siempre está incluido en el precio
        // Total = Neto + IVA
        // Total = Neto * 1.21
        // Neto = Total / 1.21
        
        $neto = round($total / 1.21, 2);
        $iva = round($total - $neto, 2);
        
        return array(
            'neto' => $neto, 
            'iva' => $iva, 
            'total' => $total
        );
    }
    
    private function get_concepto($order) {
        $tiene_productos = false;
        $tiene_servicios = false;
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product) {
                if ($product->is_virtual() || $product->is_downloadable()) {
                    $tiene_servicios = true;
                } else {
                    $tiene_productos = true;
                }
            }
        }
        
        if ($tiene_productos && $tiene_servicios) return 3;
        if ($tiene_servicios) return 2;
        return 1;
    }
    
    private function guardar_factura($order_id, $resultado, $datos_cliente, $importes) {
        global $wpdb;
        
        $wpdb->insert(
            $wpdb->prefix . 'afip_facturas',
            array(
                'order_id' => $order_id,
                'tipo_comprobante' => $resultado['tipo_comprobante'],
                'punto_venta' => $resultado['punto_venta'],
                'numero_comprobante' => $resultado['numero_comprobante'],
                'cae' => $resultado['cae'],
                'cae_fecha_vto' => $resultado['cae_fecha_vto'],
                'receptor_cuit' => $datos_cliente['cuit'],
                'receptor_dni' => $datos_cliente['dni'],
                'receptor_nombre' => $datos_cliente['nombre'],
                'receptor_condicion_iva' => $datos_cliente['condicion_iva'],
                'importe_neto' => $importes['neto'],
                'importe_iva' => $importes['iva'],
                'importe_total' => $importes['total'],
                'estado' => 'emitida',
                'fecha_emision' => $resultado['fecha_emision']
            )
        );
        
        return $wpdb->insert_id;
    }
    
    public function get_factura($factura_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}afip_facturas WHERE id = %d", $factura_id
        ));
    }
    
    public function get_factura_by_order($order_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}afip_facturas WHERE order_id = %d AND estado = 'emitida' ORDER BY id DESC LIMIT 1", $order_id
        ));
    }
    
    public function pedido_tiene_factura($order_id) {
        global $wpdb;
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}afip_facturas WHERE order_id = %d AND estado = 'emitida'", $order_id
        ));
        return $count > 0;
    }
    
    private function verificar_configuracion() {
        $cuit = get_option('wcafip_cuit', '');
        $punto_venta = get_option('wcafip_punto_venta', '');
        if (empty($cuit) || empty($punto_venta)) return false;
        
        $upload_dir = wp_upload_dir();
        $certs_dir = $upload_dir['basedir'] . '/afip-certs';
        $cert_file = get_option('wcafip_cert_file', 'certificado.crt');
        $key_file = get_option('wcafip_key_file', 'clave.key');
        
        return file_exists($certs_dir . '/' . $cert_file) && file_exists($certs_dir . '/' . $key_file);
    }
    
    private function enviar_email_factura($order, $resultado) {
        $to = $order->get_billing_email();
        $letra = WCAFIP_WSFE::get_letra_comprobante($resultado['tipo_comprobante']);
        $numero = str_pad($resultado['punto_venta'], 5, '0', STR_PAD_LEFT) . '-' . str_pad($resultado['numero_comprobante'], 8, '0', STR_PAD_LEFT);
        
        $subject = sprintf(__('Tu factura del pedido #%s', 'wc-afip-facturacion'), $order->get_order_number());
        $message = sprintf(
            "Hola %s,\n\nAdjuntamos la factura de tu pedido #%s.\n\nFactura: %s %s\nCAE: %s\nVto CAE: %s\n\n¡Gracias!",
            $order->get_billing_first_name(), $order->get_order_number(), $letra, $numero, $resultado['cae'], $resultado['cae_fecha_vto']
        );
        
        $attachments = !empty($resultado['pdf_path']) && file_exists($resultado['pdf_path']) ? array($resultado['pdf_path']) : array();
        wp_mail($to, $subject, $message, array('Content-Type: text/plain; charset=UTF-8'), $attachments);
    }
    
    public function ajax_emitir_factura() {
        check_ajax_referer('wcafip_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Sin permisos', 'wc-afip-facturacion')));
        }
        
        $order_id = intval($_POST['order_id']);
        $order = wc_get_order($order_id);
        
        if (!$order) wp_send_json_error(array('message' => __('Pedido no encontrado', 'wc-afip-facturacion')));
        if ($this->pedido_tiene_factura($order_id)) wp_send_json_error(array('message' => __('Ya tiene factura', 'wc-afip-facturacion')));
        
        try {
            $resultado = $this->emitir_factura($order);
            $order->add_order_note(__('Factura emitida manualmente. CAE: ', 'wc-afip-facturacion') . $resultado['cae']);
            wp_send_json_success(array('message' => __('Factura emitida', 'wc-afip-facturacion'), 'cae' => $resultado['cae'], 'pdf_url' => $resultado['pdf_url']));
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
    
    public function ajax_validar_cuit() {
        $cuit = isset($_POST['cuit']) ? sanitize_text_field($_POST['cuit']) : '';
        if (empty($cuit)) wp_send_json_error(array('message' => __('CUIT requerido', 'wc-afip-facturacion')));
        
        $resultado = WCAFIP_Validador_CUIT::consultar_padron($cuit);
        $resultado['encontrado'] ? wp_send_json_success($resultado) : wp_send_json_error($resultado);
    }
    
    /**
     * AJAX: Regenerar PDF de factura existente
     */
    public function ajax_regenerar_pdf() {
        // Log para debug
        error_log('WCAFIP: ajax_regenerar_pdf iniciado');
        
        // Verificar nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wcafip_nonce')) {
            error_log('WCAFIP: Nonce inválido');
            wp_send_json_error(array('message' => 'Nonce inválido'));
            return;
        }
        
        if (!current_user_can('manage_woocommerce')) {
            error_log('WCAFIP: Sin permisos');
            wp_send_json_error(array('message' => 'Sin permisos'));
            return;
        }
        
        $factura_id = isset($_POST['factura_id']) ? intval($_POST['factura_id']) : 0;
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        
        error_log('WCAFIP: factura_id=' . $factura_id . ', order_id=' . $order_id);
        
        if (!$factura_id || !$order_id) {
            wp_send_json_error(array('message' => 'Datos incompletos: factura=' . $factura_id . ', order=' . $order_id));
            return;
        }
        
        $factura = $this->get_factura($factura_id);
        if (!$factura) {
            error_log('WCAFIP: Factura no encontrada');
            wp_send_json_error(array('message' => 'Factura no encontrada (ID: ' . $factura_id . ')'));
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            error_log('WCAFIP: Pedido no encontrado');
            wp_send_json_error(array('message' => 'Pedido no encontrado (ID: ' . $order_id . ')'));
            return;
        }
        
        try {
            error_log('WCAFIP: Generando PDF...');
            
            // Generar nuevo PDF
            $pdf = new WCAFIP_PDF();
            $pdf_result = $pdf->generar($factura, $order);
            
            error_log('WCAFIP: PDF generado: ' . print_r($pdf_result, true));
            
            if (empty($pdf_result['path'])) {
                wp_send_json_error(array('message' => 'Error: No se obtuvo ruta del PDF'));
                return;
            }
            
            if (!file_exists($pdf_result['path'])) {
                wp_send_json_error(array('message' => 'Error: El archivo PDF no existe en ' . $pdf_result['path']));
                return;
            }
            
            // Actualizar ruta en la base de datos
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'afip_facturas',
                array('pdf_path' => $pdf_result['path']),
                array('id' => $factura_id)
            );
            
            error_log('WCAFIP: PDF regenerado correctamente');
            
            wp_send_json_success(array(
                'message' => 'PDF regenerado correctamente',
                'pdf_url' => $pdf_result['url']
            ));
            
        } catch (Exception $e) {
            error_log('WCAFIP Error: ' . $e->getMessage());
            wp_send_json_error(array('message' => 'Error: ' . $e->getMessage()));
        }
    }

    /**
     * Sincronizar factura con FacturaFlow
     *
     * @param object $factura Datos de la factura desde la BD
     * @param WC_Order $order Objeto del pedido
     * @param array $importes Importes calculados
     */
    private function sync_invoice_to_facturaflow($factura, $order, $importes) {
        // Verificar si existe la clase de licencia
        if (!class_exists('WCAFIP_License')) {
            return;
        }

        $license = WCAFIP_License::get_instance();
        $license_key = $license->get_license_key();

        // No sincronizar si no hay licencia válida
        if (empty($license_key) || !$license->is_license_valid()) {
            return;
        }

        // URL de la API
        $api_url = 'https://facturaflow.net/api/license/invoice-sync';

        // Obtener dominio del sitio
        $domain = $this->get_site_domain();

        // Obtener letra del tipo de comprobante
        $tipo_comprobante_letra = WCAFIP_WSFE::get_letra_comprobante($factura->tipo_comprobante);

        // Mapear condición IVA a código corto
        $condicion_iva_map = array(
            1 => 'RI',  // Responsable Inscripto
            4 => 'EX',  // Exento
            5 => 'CF',  // Consumidor Final
            6 => 'MT',  // Monotributo
            8 => 'PC',  // Proveedor del Exterior
            9 => 'CE',  // Cliente del Exterior
            10 => 'LI', // Liberado
            13 => 'MT', // Monotributista Social
        );
        $condicion_iva_codigo = $condicion_iva_map[$factura->receptor_condicion_iva] ?? 'CF';

        // Preparar items del pedido
        $items = array();
        foreach ($order->get_items() as $item) {
            $items[] = array(
                'descripcion' => $item->get_name(),
                'cantidad' => $item->get_quantity(),
                'precioUnitario' => round($item->get_total() / $item->get_quantity(), 2),
                'subtotal' => round($item->get_total(), 2)
            );
        }

        // Preparar datos de la factura
        $invoice_data = array(
            'license_key' => $license_key,
            'domain' => $domain,
            'invoice' => array(
                'tipo_comprobante' => 'F' . $tipo_comprobante_letra, // FA, FB, FC
                'punto_venta' => intval($factura->punto_venta),
                'numero' => intval($factura->numero_comprobante),
                'cliente_nombre' => $factura->receptor_nombre,
                'cliente_cuit' => $factura->receptor_cuit,
                'cliente_dni' => $factura->receptor_dni,
                'cliente_email' => $order->get_billing_email(),
                'cliente_domicilio' => $order->get_billing_address_1() . ', ' . $order->get_billing_city(),
                'cliente_condicion_iva' => $condicion_iva_codigo,
                'descripcion' => sprintf(__('Pedido WooCommerce #%s', 'wc-afip-facturacion'), $order->get_order_number()),
                'subtotal' => floatval($factura->importe_neto),
                'iva' => floatval($factura->importe_iva),
                'total' => floatval($factura->importe_total),
                'cae' => $factura->cae,
                'cae_fecha_venta' => date('Y-m-d', strtotime($factura->fecha_emision)),
                'status' => 'approved',
                'external_id' => 'woo_order_' . $order->get_id(),
                'items' => $items
            )
        );

        // Enviar factura a FacturaFlow
        $response = wp_remote_post($api_url, array(
            'timeout' => 15,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'body' => json_encode($invoice_data)
        ));

        // Verificar respuesta
        if (!is_wp_error($response)) {
            $http_code = wp_remote_retrieve_response_code($response);
            if ($http_code === 200 || $http_code === 201) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                $this->logger->info(
                    sprintf('Factura sincronizada con FacturaFlow. ID: %s', $body['invoice_id'] ?? 'N/A'),
                    $order->get_id()
                );
                return;
            }
        }

        // Si falló, loguear el error
        $this->logger->warning('No se pudo sincronizar la factura con FacturaFlow', $order->get_id());
    }

    /**
     * Obtener dominio del sitio (sin protocolo)
     */
    private function get_site_domain() {
        $site_url = home_url();
        $parsed = parse_url($site_url);
        return isset($parsed['host']) ? $parsed['host'] : $site_url;
    }
}
