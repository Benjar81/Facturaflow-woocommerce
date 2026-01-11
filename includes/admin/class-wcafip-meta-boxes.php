<?php
/**
 * Clase Meta Boxes - Cajas en el pedido
 * 
 * @package WC_AFIP_Facturacion
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCAFIP_Meta_Boxes {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
    }
    
    /**
     * Agregar meta boxes
     */
    public function add_meta_boxes() {
        $screen = class_exists('\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController') 
            && wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
            ? wc_get_page_screen_id('shop-order')
            : 'shop_order';
        
        add_meta_box(
            'wcafip_factura_box',
            __('Factura AFIP', 'wc-afip-facturacion'),
            array($this, 'render_factura_box'),
            $screen,
            'side',
            'high'
        );
    }
    
    /**
     * Render meta box de factura
     */
    public function render_factura_box($post_or_order) {
        $order = $post_or_order instanceof WP_Post ? wc_get_order($post_or_order->ID) : $post_or_order;
        
        if (!$order) {
            return;
        }
        
        $facturador = WCAFIP_Facturador::get_instance();
        $factura = $facturador->get_factura_by_order($order->get_id());
        
        if ($factura) {
            // Mostrar datos de la factura
            $letra = WCAFIP_WSFE::get_letra_comprobante($factura->tipo_comprobante);
            $numero = str_pad($factura->punto_venta, 5, '0', STR_PAD_LEFT) . '-' . 
                      str_pad($factura->numero_comprobante, 8, '0', STR_PAD_LEFT);
            ?>
            <div class="wcafip-factura-info">
                <p><strong><?php _e('Tipo:', 'wc-afip-facturacion'); ?></strong> Factura <?php echo esc_html($letra); ?></p>
                <p><strong><?php _e('Número:', 'wc-afip-facturacion'); ?></strong> <?php echo esc_html($numero); ?></p>
                <p><strong><?php _e('CAE:', 'wc-afip-facturacion'); ?></strong> <?php echo esc_html($factura->cae); ?></p>
                <p><strong><?php _e('Vto. CAE:', 'wc-afip-facturacion'); ?></strong> <?php echo esc_html(date('d/m/Y', strtotime($factura->cae_fecha_vto))); ?></p>
                <p><strong><?php _e('Total:', 'wc-afip-facturacion'); ?></strong> <?php echo wc_price($factura->importe_total); ?></p>
                <p><strong><?php _e('Estado:', 'wc-afip-facturacion'); ?></strong> 
                    <span class="wcafip-status wcafip-status-<?php echo esc_attr($factura->estado); ?>">
                        <?php echo esc_html(ucfirst($factura->estado)); ?>
                    </span>
                </p>
                
                <?php if (!empty($factura->pdf_path) && file_exists($factura->pdf_path)): ?>
                    <p>
                        <a href="<?php echo esc_url(add_query_arg(array('download_factura' => $factura->id, 'nonce' => wp_create_nonce('download_factura_' . $factura->id)))); ?>" class="button">
                            <?php _e('Descargar PDF', 'wc-afip-facturacion'); ?>
                        </a>
                        <button type="button" class="button wcafip-regenerar-pdf" 
                            data-factura-id="<?php echo esc_attr($factura->id); ?>" 
                            data-order-id="<?php echo esc_attr($order->get_id()); ?>"
                            data-nonce="<?php echo wp_create_nonce('wcafip_nonce'); ?>">
                            <?php _e('Regenerar PDF', 'wc-afip-facturacion'); ?>
                        </button>
                    </p>
                <?php else: ?>
                    <p>
                        <button type="button" class="button button-primary wcafip-regenerar-pdf" 
                            data-factura-id="<?php echo esc_attr($factura->id); ?>" 
                            data-order-id="<?php echo esc_attr($order->get_id()); ?>"
                            data-nonce="<?php echo wp_create_nonce('wcafip_nonce'); ?>">
                            <?php _e('Generar PDF', 'wc-afip-facturacion'); ?>
                        </button>
                        <span class="description" style="display: block; margin-top: 5px; color: #d63638;">
                            <?php _e('El PDF no existe o no se generó correctamente.', 'wc-afip-facturacion'); ?>
                        </span>
                    </p>
                <?php endif; ?>
                
                <div class="wcafip-regenerar-result" style="margin-top: 10px;"></div>
            </div>
            
            <script>
            jQuery(document).ready(function($) {
                $('.wcafip-regenerar-pdf').on('click', function(e) {
                    e.preventDefault();
                    var $btn = $(this);
                    var $result = $('.wcafip-regenerar-result');
                    var facturaId = $btn.data('factura-id');
                    var orderId = $btn.data('order-id');
                    var nonce = $btn.data('nonce');
                    var originalText = $btn.text();
                    
                    $btn.prop('disabled', true).text('Generando...');
                    $result.html('<p>Procesando...</p>');
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        timeout: 60000,
                        data: {
                            action: 'wcafip_regenerar_pdf',
                            factura_id: facturaId,
                            order_id: orderId,
                            nonce: nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                $result.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                                setTimeout(function() { location.reload(); }, 1500);
                            } else {
                                var msg = response.data && response.data.message ? response.data.message : 'Error desconocido';
                                $result.html('<div class="notice notice-error"><p>' + msg + '</p></div>');
                                $btn.prop('disabled', false).text(originalText);
                            }
                        },
                        error: function(xhr, status, error) {
                            var msg = 'Error: ' + status;
                            if (error) msg += ' - ' + error;
                            $result.html('<div class="notice notice-error"><p>' + msg + '</p></div>');
                            $btn.prop('disabled', false).text(originalText);
                        }
                    });
                });
            });
            </script>
            <?php
        } else {
            // Mostrar botón para emitir
            ?>
            <div class="wcafip-no-factura">
                <p><?php _e('Este pedido no tiene factura emitida.', 'wc-afip-facturacion'); ?></p>
                
                <button type="button" class="button button-primary wcafip-emitir-factura" data-order-id="<?php echo esc_attr($order->get_id()); ?>">
                    <?php _e('Emitir Factura', 'wc-afip-facturacion'); ?>
                </button>
                
                <div class="wcafip-emitir-result" style="margin-top: 10px;"></div>
            </div>
            
            <script>
            jQuery(document).ready(function($) {
                $('.wcafip-emitir-factura').on('click', function() {
                    var $btn = $(this);
                    var $result = $btn.siblings('.wcafip-emitir-result');
                    var orderId = $btn.data('order-id');
                    
                    if (!confirm('<?php _e('¿Emitir factura para este pedido?', 'wc-afip-facturacion'); ?>')) {
                        return;
                    }
                    
                    $btn.prop('disabled', true).text('<?php _e('Emitiendo...', 'wc-afip-facturacion'); ?>');
                    
                    $.post(ajaxurl, {
                        action: 'wcafip_emitir_factura',
                        order_id: orderId,
                        nonce: wcafip_admin.nonce
                    }, function(response) {
                        if (response.success) {
                            $result.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                            setTimeout(function() {
                                location.reload();
                            }, 1500);
                        } else {
                            $result.html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
                            $btn.prop('disabled', false).text('<?php _e('Emitir Factura', 'wc-afip-facturacion'); ?>');
                        }
                    }).fail(function() {
                        $result.html('<div class="notice notice-error"><p><?php _e('Error de conexión', 'wc-afip-facturacion'); ?></p></div>');
                        $btn.prop('disabled', false).text('<?php _e('Emitir Factura', 'wc-afip-facturacion'); ?>');
                    });
                });
            });
            </script>
            <?php
        }
    }
}
