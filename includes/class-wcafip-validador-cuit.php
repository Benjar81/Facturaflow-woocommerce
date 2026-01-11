<?php
/**
 * Clase Validador de CUIT/CUIL
 * 
 * @package WC_AFIP_Facturacion
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCAFIP_Validador_CUIT {
    
    /**
     * Validar formato de CUIT
     */
    public static function validar_formato($cuit) {
        // Limpiar CUIT
        $cuit = preg_replace('/[^0-9]/', '', $cuit);
        
        // Debe tener 11 dígitos
        if (strlen($cuit) !== 11) {
            return array(
                'valido' => false,
                'error' => __('El CUIT debe tener 11 dígitos', 'wc-afip-facturacion')
            );
        }
        
        // Validar dígito verificador
        $multiplicadores = array(5, 4, 3, 2, 7, 6, 5, 4, 3, 2);
        $suma = 0;
        
        for ($i = 0; $i < 10; $i++) {
            $suma += intval($cuit[$i]) * $multiplicadores[$i];
        }
        
        $resto = $suma % 11;
        $digito_calculado = $resto == 0 ? 0 : ($resto == 1 ? 9 : 11 - $resto);
        $digito_verificador = intval($cuit[10]);
        
        if ($digito_calculado !== $digito_verificador) {
            return array(
                'valido' => false,
                'error' => __('El CUIT tiene un dígito verificador inválido', 'wc-afip-facturacion')
            );
        }
        
        return array(
            'valido' => true,
            'cuit' => $cuit
        );
    }
    
    /**
     * Formatear CUIT (XX-XXXXXXXX-X)
     */
    public static function formatear($cuit) {
        $cuit = preg_replace('/[^0-9]/', '', $cuit);
        
        if (strlen($cuit) !== 11) {
            return $cuit;
        }
        
        return substr($cuit, 0, 2) . '-' . substr($cuit, 2, 8) . '-' . substr($cuit, 10, 1);
    }
    
    /**
     * Consultar padrón de AFIP
     */
    public static function consultar_padron($cuit) {
        // Validar formato primero
        $validacion = self::validar_formato($cuit);
        if (!$validacion['valido']) {
            return array(
                'encontrado' => false,
                'error' => $validacion['error']
            );
        }
        
        $cuit = $validacion['cuit'];
        
        // Consultar API externa (servicio de terceros)
        $url = "https://afip.tangofactura.com/Rest/GetContribuyenteFull?cuit=" . $cuit;
        
        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'sslverify' => false
        ));
        
        if (is_wp_error($response)) {
            return array(
                'encontrado' => false,
                'error' => __('No se pudo consultar el padrón de AFIP', 'wc-afip-facturacion'),
                'cuit' => self::formatear($cuit)
            );
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (empty($data) || !isset($data['Contribuyente'])) {
            return array(
                'encontrado' => false,
                'error' => __('CUIT no encontrado en el padrón', 'wc-afip-facturacion'),
                'cuit' => self::formatear($cuit)
            );
        }
        
        $contrib = $data['Contribuyente'];
        
        return array(
            'encontrado' => true,
            'cuit' => self::formatear($cuit),
            'razon_social' => isset($contrib['nombre']) ? $contrib['nombre'] : '',
            'tipo_persona' => isset($contrib['tipoPersona']) ? $contrib['tipoPersona'] : '',
            'condicion_iva' => self::mapear_condicion_iva($contrib),
            'condicion_iva_codigo' => self::obtener_codigo_condicion_iva($contrib),
            'domicilio' => self::formatear_domicilio($contrib),
            'estado' => isset($contrib['estadoClave']) ? $contrib['estadoClave'] : ''
        );
    }
    
    /**
     * Mapear condición de IVA
     */
    private static function mapear_condicion_iva($contribuyente) {
        $impuestos = isset($contribuyente['impuestos']) ? $contribuyente['impuestos'] : array();
        
        $tiene_iva = false;
        $tiene_monotributo = false;
        $tiene_exento = false;
        
        foreach ($impuestos as $impuesto) {
            if (isset($impuesto['idImpuesto'])) {
                if ($impuesto['idImpuesto'] == 30) $tiene_iva = true;
                if ($impuesto['idImpuesto'] == 20) $tiene_monotributo = true;
                if ($impuesto['idImpuesto'] == 32) $tiene_exento = true;
            }
        }
        
        if ($tiene_monotributo) return __('Responsable Monotributo', 'wc-afip-facturacion');
        if ($tiene_iva) return __('IVA Responsable Inscripto', 'wc-afip-facturacion');
        if ($tiene_exento) return __('IVA Exento', 'wc-afip-facturacion');
        
        return __('Consumidor Final', 'wc-afip-facturacion');
    }
    
    /**
     * Obtener código de condición de IVA para AFIP
     */
    private static function obtener_codigo_condicion_iva($contribuyente) {
        $impuestos = isset($contribuyente['impuestos']) ? $contribuyente['impuestos'] : array();
        
        foreach ($impuestos as $impuesto) {
            if (isset($impuesto['idImpuesto'])) {
                if ($impuesto['idImpuesto'] == 20) return WCAFIP_WSFE::IVA_MONOTRIBUTO;
                if ($impuesto['idImpuesto'] == 30) return WCAFIP_WSFE::IVA_RESPONSABLE_INSCRIPTO;
                if ($impuesto['idImpuesto'] == 32) return WCAFIP_WSFE::IVA_EXENTO;
            }
        }
        
        return WCAFIP_WSFE::IVA_CONSUMIDOR_FINAL;
    }
    
    /**
     * Formatear domicilio
     */
    private static function formatear_domicilio($contribuyente) {
        if (!isset($contribuyente['domicilioFiscal'])) {
            return '';
        }
        
        $dom = $contribuyente['domicilioFiscal'];
        $partes = array();
        
        if (!empty($dom['direccion'])) $partes[] = $dom['direccion'];
        if (!empty($dom['localidad'])) $partes[] = $dom['localidad'];
        if (!empty($dom['provincia'])) $partes[] = $dom['provincia'];
        if (!empty($dom['codPostal'])) $partes[] = 'CP ' . $dom['codPostal'];
        
        return implode(', ', $partes);
    }
    
    /**
     * Validar DNI
     */
    public static function validar_dni($dni) {
        $dni = preg_replace('/[^0-9]/', '', $dni);
        
        if (strlen($dni) < 7 || strlen($dni) > 8) {
            return array(
                'valido' => false,
                'error' => __('El DNI debe tener entre 7 y 8 dígitos', 'wc-afip-facturacion')
            );
        }
        
        return array(
            'valido' => true,
            'dni' => $dni
        );
    }
    
    /**
     * Detectar tipo de documento
     */
    public static function detectar_tipo_documento($documento) {
        $limpio = preg_replace('/[^0-9]/', '', $documento);
        
        if (strlen($limpio) === 11) {
            return 'CUIT';
        } elseif (strlen($limpio) >= 7 && strlen($limpio) <= 8) {
            return 'DNI';
        }
        
        return 'DESCONOCIDO';
    }
}
