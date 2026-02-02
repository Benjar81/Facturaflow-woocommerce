/* Checkout JS - WooCommerce AFIP Facturación */
jQuery(document).ready(function($) {
    var $tipoDoc = $('#billing_tipo_doc');
    var $nroDocField = $('#billing_nro_doc_field');
    var $nroDocInput = $('#billing_nro_doc');
    var $razonSocialField = $('#billing_razon_social_field');
    var $condicionIvaField = $('#billing_condicion_iva_field');
    var validationTimeout;

    function toggleFields() {
        var tipo = $tipoDoc.val();

        if (tipo === 'cuit') {
            // Mostrar campos de CUIT
            $nroDocInput.attr('placeholder', 'XX-XXXXXXXX-X');
            $nroDocField.find('label').contents().first().replaceWith('CUIT ');
            $razonSocialField.show();
            if ($condicionIvaField.length) {
                $condicionIvaField.show();
            }
        } else {
            // Mostrar campos de DNI
            $nroDocInput.attr('placeholder', 'Solo números (7-8 dígitos)');
            $nroDocField.find('label').contents().first().replaceWith('DNI ');
            $razonSocialField.hide();
            if ($condicionIvaField.length) {
                $condicionIvaField.hide();
            }
        }
    }

    // Solo configurar toggle si el dropdown es visible
    if ($tipoDoc.length && $tipoDoc.attr('type') !== 'hidden') {
        $tipoDoc.on('change', toggleFields);
        toggleFields();
    } else {
        // Si no hay dropdown (factura A deshabilitada), ocultar campos de CUIT
        $razonSocialField.hide();
        if ($condicionIvaField.length) {
            $condicionIvaField.hide();
        }
    }

    // Validar CUIT en tiempo real (cuando el tipo es CUIT)
    $nroDocInput.on('blur', function() {
        var tipo = $tipoDoc.val() || 'dni';
        var value = $(this).val().replace(/[^0-9]/g, '');

        if (tipo === 'cuit') {
            if (value.length !== 11) return;

            // Formatear CUIT
            $(this).val(value.substr(0,2) + '-' + value.substr(2,8) + '-' + value.substr(10,1));

            // Validar contra padrón
            var $validation = $nroDocField.find('.wcafip-cuit-validation');
            if (!$validation.length) {
                $validation = $('<div class="wcafip-cuit-validation"></div>');
                $nroDocField.append($validation);
            }

            $validation.removeClass('valid invalid').addClass('loading').text(wcafip_checkout.validando_cuit);

            clearTimeout(validationTimeout);
            validationTimeout = setTimeout(function() {
                $.post(wcafip_checkout.ajax_url, {
                    action: 'wcafip_validar_cuit',
                    cuit: value
                }, function(response) {
                    if (response.success) {
                        $validation.removeClass('loading').addClass('valid');
                        $validation.html('✓ ' + response.data.razon_social + ' - ' + response.data.condicion_iva);
                        if (response.data.razon_social) {
                            $('#billing_razon_social').val(response.data.razon_social);
                        }
                    } else {
                        $validation.removeClass('loading').addClass('invalid');
                        $validation.html('✗ ' + (response.data.error || wcafip_checkout.cuit_invalido));
                    }
                });
            }, 500);
        } else {
            // DNI: solo limpiar caracteres no numéricos
            $(this).val(value);
            // Eliminar validación CUIT si existía
            $nroDocField.find('.wcafip-cuit-validation').remove();
        }
    });

    // Limpiar validación al cambiar tipo de documento
    $tipoDoc.on('change', function() {
        $nroDocInput.val('');
        $nroDocField.find('.wcafip-cuit-validation').remove();
    });
});
