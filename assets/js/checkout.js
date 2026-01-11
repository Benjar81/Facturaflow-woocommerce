/* Checkout JS - WooCommerce AFIP Facturación */
jQuery(document).ready(function($) {
    var $condicionIva = $('#billing_condicion_iva');
    var $cuitField = $('#billing_cuit_field');
    var $dniField = $('#billing_dni_field');
    var $razonSocialField = $('.wcafip-razon-social-field');
    var $cuitInput = $('#billing_cuit');
    var validationTimeout;

    function toggleFields() {
        var condicion = parseInt($condicionIva.val());
        // RI o Monotributo requieren CUIT
        if (condicion === wcafip_checkout.IVA_RI || condicion === wcafip_checkout.IVA_MONO) {
            $cuitField.addClass('required').find('label').addClass('required');
            $razonSocialField.addClass('visible');
        } else {
            $cuitField.removeClass('required').find('label').removeClass('required');
            $razonSocialField.removeClass('visible');
        }
    }

    $condicionIva.on('change', toggleFields);
    toggleFields();

    // Validar CUIT en tiempo real
    $cuitInput.on('blur', function() {
        var cuit = $(this).val().replace(/[^0-9]/g, '');
        if (cuit.length !== 11) return;

        // Formatear
        $(this).val(cuit.substr(0,2) + '-' + cuit.substr(2,8) + '-' + cuit.substr(10,1));

        // Validar contra padrón
        var $validation = $cuitField.find('.wcafip-cuit-validation');
        if (!$validation.length) {
            $validation = $('<div class="wcafip-cuit-validation"></div>');
            $cuitField.append($validation);
        }

        $validation.removeClass('valid invalid').addClass('loading').text(wcafip_checkout.validando_cuit);

        clearTimeout(validationTimeout);
        validationTimeout = setTimeout(function() {
            $.post(wcafip_checkout.ajax_url, {
                action: 'wcafip_validar_cuit',
                cuit: cuit
            }, function(response) {
                if (response.success) {
                    $validation.removeClass('loading').addClass('valid');
                    $validation.html('✓ ' + response.data.razon_social + ' - ' + response.data.condicion_iva);
                    // Auto-completar razón social
                    if (response.data.razon_social) {
                        $('#billing_razon_social').val(response.data.razon_social);
                    }
                } else {
                    $validation.removeClass('loading').addClass('invalid');
                    $validation.html('✗ ' + (response.data.error || wcafip_checkout.cuit_invalido));
                }
            });
        }, 500);
    });

    // Formatear DNI
    $('#billing_dni').on('blur', function() {
        var dni = $(this).val().replace(/[^0-9]/g, '');
        $(this).val(dni);
    });
});
