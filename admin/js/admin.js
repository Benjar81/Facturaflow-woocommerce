/* Admin JS - WooCommerce AFIP Facturación */
jQuery(document).ready(function($) {
    $(document).on('click', '.wcafip-emitir-factura', function(e) {
        e.preventDefault();
        var $btn = $(this), orderId = $btn.data('order-id'), $result = $btn.siblings('.wcafip-emitir-result');
        if (!confirm('¿Emitir factura para este pedido?')) return;
        $btn.prop('disabled', true).text(wcafip_admin.emitiendo);
        $.post(wcafip_admin.ajax_url, { action: 'wcafip_emitir_factura', order_id: orderId, nonce: wcafip_admin.nonce }, function(response) {
            if (response.success) {
                $result.html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                $result.html('<div class="notice notice-error inline"><p>' + response.data.message + '</p></div>');
                $btn.prop('disabled', false).text('Emitir Factura');
            }
        });
    });
    $('#wcafip_cuit').on('blur', function() {
        var cuit = $(this).val().replace(/[^0-9]/g, '');
        if (cuit.length === 11) $(this).val(cuit.substr(0,2) + '-' + cuit.substr(2,8) + '-' + cuit.substr(10,1));
    });
});
