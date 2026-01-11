<?php
/**
 * Clase WSFE - Facturación Electrónica AFIP
 * 
 * @package WC_AFIP_Facturacion
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCAFIP_WSFE {
    
    private $wsaa;
    private $cuit;
    private $punto_venta;
    private $client;
    private $logger;
    
    // URLs de AFIP
    const WSFE_URL_TESTING = 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx?WSDL';
    const WSFE_URL_PRODUCTION = 'https://servicios1.afip.gov.ar/wsfev1/service.asmx?WSDL';
    
    // Tipos de comprobante
    const FACTURA_A = 1;
    const FACTURA_B = 6;
    const FACTURA_C = 11;
    const NOTA_CREDITO_A = 3;
    const NOTA_CREDITO_B = 8;
    const NOTA_CREDITO_C = 13;
    
    // Tipos de documento
    const DOC_CUIT = 80;
    const DOC_CUIL = 86;
    const DOC_DNI = 96;
    const DOC_SIN_IDENTIFICAR = 99;
    
    // Condiciones de IVA
    const IVA_RESPONSABLE_INSCRIPTO = 1;
    const IVA_RESPONSABLE_NO_INSCRIPTO = 2;
    const IVA_EXENTO = 4;
    const IVA_CONSUMIDOR_FINAL = 5;
    const IVA_MONOTRIBUTO = 6;
    
    public function __construct() {
        $this->logger = WCAFIP_Logger::get_instance();
        $this->wsaa = new WCAFIP_WSAA();
        // Limpiar CUIT de guiones y espacios
        $cuit_raw = get_option('wcafip_cuit', '');
        $this->cuit = preg_replace('/[^0-9]/', '', $cuit_raw);
        $this->punto_venta = intval(get_option('wcafip_punto_venta', 1));
    }
    
    /**
     * Obtener URL según ambiente
     */
    private function get_wsfe_url() {
        $env = get_option('wcafip_ambiente', 'testing');
        return $env === 'production' ? self::WSFE_URL_PRODUCTION : self::WSFE_URL_TESTING;
    }
    
    /**
     * Inicializar cliente SOAP
     */
    private function init_client() {
        if ($this->client) {
            return;
        }
        
        $wsfe_url = $this->get_wsfe_url();
        
        // Configuración SSL mejorada para AFIP
        $context_options = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
                'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
                'ciphers' => 'DEFAULT@SECLEVEL=1'
            ),
            'http' => array(
                'timeout' => 120,
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            )
        );
        
        $context = stream_context_create($context_options);
        
        try {
            // Descargar el WSDL (con fallbacks automáticos)
            $wsdl_content = $this->download_wsdl($wsfe_url);
            
            if (!$wsdl_content) {
                throw new Exception('No se pudo obtener el WSDL de AFIP');
            }
            
            // Guardar WSDL localmente
            $upload_dir = wp_upload_dir();
            $wsdl_dir = $upload_dir['basedir'] . '/afip-certs';
            if (!file_exists($wsdl_dir)) {
                wp_mkdir_p($wsdl_dir);
            }
            $wsdl_file = $wsdl_dir . '/wsfe_' . md5($wsfe_url) . '.wsdl';
            file_put_contents($wsdl_file, $wsdl_content);
            
            $this->client = new SoapClient($wsdl_file, array(
                'soap_version' => SOAP_1_1,
                'trace' => true,
                'exceptions' => true,
                'cache_wsdl' => WSDL_CACHE_NONE,
                'stream_context' => $context,
                'connection_timeout' => 120,
                'location' => str_replace('?WSDL', '', $wsfe_url)
            ));
            
            $this->logger->info('Cliente SOAP inicializado correctamente');
            
        } catch (Exception $e) {
            $this->logger->error('Error inicializando cliente SOAP: ' . $e->getMessage());
            throw new Exception('Error conectando con AFIP: ' . $e->getMessage());
        }
    }
    
    /**
     * Descargar WSDL usando cURL con configuración SSL especial para AFIP
     */
    private function download_wsdl($url) {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            // Configuración especial para AFIP - resolver "dh key too small"
            CURLOPT_SSL_CIPHER_LIST => 'DEFAULT@SECLEVEL=1',
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            // Headers adicionales
            CURLOPT_HTTPHEADER => array(
                'Accept: text/xml,application/xml',
                'Cache-Control: no-cache'
            )
        ));
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($errno || $error) {
            $this->logger->error("Error cURL descargando WSDL: [$errno] $error - URL: $url");
            
            // Intentar método alternativo sin SECLEVEL
            return $this->download_wsdl_alternative($url);
        }
        
        if ($http_code !== 200) {
            $this->logger->error("Error HTTP $http_code descargando WSDL de: $url");
            return $this->download_wsdl_alternative($url);
        }
        
        // Verificar que sea XML válido
        if (strpos($response, '<?xml') === false && strpos($response, '<definitions') === false) {
            $this->logger->error("Respuesta no es WSDL válido de: $url");
            return $this->download_wsdl_alternative($url);
        }
        
        return $response;
    }
    
    /**
     * Método alternativo para descargar WSDL
     */
    private function download_wsdl_alternative($url) {
        $this->logger->info("Intentando método alternativo para descargar WSDL: $url");
        
        // Intentar con file_get_contents y contexto SSL
        $context = stream_context_create(array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
                'ciphers' => 'DEFAULT@SECLEVEL=1'
            ),
            'http' => array(
                'timeout' => 120,
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            )
        ));
        
        $response = @file_get_contents($url, false, $context);
        
        if ($response && (strpos($response, '<?xml') !== false || strpos($response, '<definitions') !== false)) {
            $this->logger->info("WSDL descargado con método alternativo");
            return $response;
        }
        
        // Último intento: usar WSDL embebido
        return $this->get_embedded_wsdl();
    }
    
    /**
     * Obtener WSDL embebido como último recurso
     */
    private function get_embedded_wsdl() {
        $env = get_option('wcafip_ambiente', 'testing');
        
        // WSDL simplificado para WSFE
        $location = $env === 'production' 
            ? 'https://servicios1.afip.gov.ar/wsfev1/service.asmx'
            : 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx';
        
        $this->logger->warning("Usando WSDL embebido para: $location");
        
        // WSDL mínimo funcional para WSFE
        $wsdl = '<?xml version="1.0" encoding="utf-8"?>
<definitions xmlns:soap="http://schemas.xmlsoap.org/wsdl/soap/" 
             xmlns:tns="http://ar.gov.afip.dif.FEV1/" 
             xmlns:s="http://www.w3.org/2001/XMLSchema" 
             xmlns="http://schemas.xmlsoap.org/wsdl/" 
             targetNamespace="http://ar.gov.afip.dif.FEV1/" 
             name="Service">
  <types>
    <s:schema elementFormDefault="qualified" targetNamespace="http://ar.gov.afip.dif.FEV1/">
      <s:element name="FEDummy"><s:complexType/></s:element>
      <s:element name="FEDummyResponse">
        <s:complexType><s:sequence>
          <s:element name="FEDummyResult" minOccurs="0"><s:complexType><s:sequence>
            <s:element name="AppServer" type="s:string" minOccurs="0"/>
            <s:element name="DbServer" type="s:string" minOccurs="0"/>
            <s:element name="AuthServer" type="s:string" minOccurs="0"/>
          </s:sequence></s:complexType></s:element>
        </s:sequence></s:complexType>
      </s:element>
      <s:element name="FECompUltimoAutorizado">
        <s:complexType><s:sequence>
          <s:element name="Auth" type="tns:FEAuthRequest" minOccurs="0"/>
          <s:element name="PtoVta" type="s:int"/>
          <s:element name="CbteTipo" type="s:int"/>
        </s:sequence></s:complexType>
      </s:element>
      <s:complexType name="FEAuthRequest">
        <s:sequence>
          <s:element name="Token" type="s:string" minOccurs="0"/>
          <s:element name="Sign" type="s:string" minOccurs="0"/>
          <s:element name="Cuit" type="s:long"/>
        </s:sequence>
      </s:complexType>
      <s:element name="FECompUltimoAutorizadoResponse">
        <s:complexType><s:sequence>
          <s:element name="FECompUltimoAutorizadoResult" minOccurs="0"><s:complexType><s:sequence>
            <s:element name="PtoVta" type="s:int"/>
            <s:element name="CbteTipo" type="s:int"/>
            <s:element name="CbteNro" type="s:int"/>
          </s:sequence></s:complexType></s:element>
        </s:sequence></s:complexType>
      </s:element>
      <s:element name="FECAESolicitar">
        <s:complexType><s:sequence>
          <s:element name="Auth" type="tns:FEAuthRequest" minOccurs="0"/>
          <s:element name="FeCAEReq" minOccurs="0"><s:complexType><s:sequence>
            <s:element name="FeCabReq" minOccurs="0"><s:complexType><s:sequence>
              <s:element name="CantReg" type="s:int"/>
              <s:element name="PtoVta" type="s:int"/>
              <s:element name="CbteTipo" type="s:int"/>
            </s:sequence></s:complexType></s:element>
            <s:element name="FeDetReq" minOccurs="0"><s:complexType><s:sequence>
              <s:element name="FECAEDetRequest" maxOccurs="unbounded" minOccurs="0"><s:complexType><s:sequence>
                <s:element name="Concepto" type="s:int"/>
                <s:element name="DocTipo" type="s:int"/>
                <s:element name="DocNro" type="s:long"/>
                <s:element name="CbteDesde" type="s:long"/>
                <s:element name="CbteHasta" type="s:long"/>
                <s:element name="CbteFch" type="s:string" minOccurs="0"/>
                <s:element name="ImpTotal" type="s:double"/>
                <s:element name="ImpTotConc" type="s:double"/>
                <s:element name="ImpNeto" type="s:double"/>
                <s:element name="ImpOpEx" type="s:double"/>
                <s:element name="ImpTrib" type="s:double"/>
                <s:element name="ImpIVA" type="s:double"/>
                <s:element name="FchServDesde" type="s:string" minOccurs="0"/>
                <s:element name="FchServHasta" type="s:string" minOccurs="0"/>
                <s:element name="FchVtoPago" type="s:string" minOccurs="0"/>
                <s:element name="MonId" type="s:string" minOccurs="0"/>
                <s:element name="MonCotiz" type="s:double"/>
                <s:element name="Iva" minOccurs="0"><s:complexType><s:sequence>
                  <s:element name="AlicIva" maxOccurs="unbounded" minOccurs="0"><s:complexType><s:sequence>
                    <s:element name="Id" type="s:int"/>
                    <s:element name="BaseImp" type="s:double"/>
                    <s:element name="Importe" type="s:double"/>
                  </s:sequence></s:complexType></s:element>
                </s:sequence></s:complexType></s:element>
              </s:sequence></s:complexType></s:element>
            </s:sequence></s:complexType></s:element>
          </s:sequence></s:complexType></s:element>
        </s:sequence></s:complexType>
      </s:element>
      <s:element name="FECAESolicitarResponse">
        <s:complexType><s:sequence>
          <s:element name="FECAESolicitarResult" minOccurs="0" type="tns:FECAEResponse"/>
        </s:sequence></s:complexType>
      </s:element>
      <s:complexType name="FECAEResponse">
        <s:sequence>
          <s:element name="FeCabResp" minOccurs="0"><s:complexType><s:sequence>
            <s:element name="Cuit" type="s:long"/>
            <s:element name="PtoVta" type="s:int"/>
            <s:element name="CbteTipo" type="s:int"/>
            <s:element name="FchProceso" type="s:string" minOccurs="0"/>
            <s:element name="CantReg" type="s:int"/>
            <s:element name="Resultado" type="s:string" minOccurs="0"/>
            <s:element name="Reproceso" type="s:string" minOccurs="0"/>
          </s:sequence></s:complexType></s:element>
          <s:element name="FeDetResp" minOccurs="0"><s:complexType><s:sequence>
            <s:element name="FECAEDetResponse" maxOccurs="unbounded" minOccurs="0"><s:complexType><s:sequence>
              <s:element name="Concepto" type="s:int"/>
              <s:element name="DocTipo" type="s:int"/>
              <s:element name="DocNro" type="s:long"/>
              <s:element name="CbteDesde" type="s:long"/>
              <s:element name="CbteHasta" type="s:long"/>
              <s:element name="CbteFch" type="s:string" minOccurs="0"/>
              <s:element name="Resultado" type="s:string" minOccurs="0"/>
              <s:element name="CAE" type="s:string" minOccurs="0"/>
              <s:element name="CAEFchVto" type="s:string" minOccurs="0"/>
            </s:sequence></s:complexType></s:element>
          </s:sequence></s:complexType></s:element>
          <s:element name="Errors" minOccurs="0"><s:complexType><s:sequence>
            <s:element name="Err" maxOccurs="unbounded" minOccurs="0"><s:complexType><s:sequence>
              <s:element name="Code" type="s:int"/>
              <s:element name="Msg" type="s:string" minOccurs="0"/>
            </s:sequence></s:complexType></s:element>
          </s:sequence></s:complexType></s:element>
        </s:sequence>
      </s:complexType>
    </s:schema>
  </types>
  <message name="FEDummySoapIn"><part name="parameters" element="tns:FEDummy"/></message>
  <message name="FEDummySoapOut"><part name="parameters" element="tns:FEDummyResponse"/></message>
  <message name="FECompUltimoAutorizadoSoapIn"><part name="parameters" element="tns:FECompUltimoAutorizado"/></message>
  <message name="FECompUltimoAutorizadoSoapOut"><part name="parameters" element="tns:FECompUltimoAutorizadoResponse"/></message>
  <message name="FECAESolicitarSoapIn"><part name="parameters" element="tns:FECAESolicitar"/></message>
  <message name="FECAESolicitarSoapOut"><part name="parameters" element="tns:FECAESolicitarResponse"/></message>
  <portType name="ServiceSoap">
    <operation name="FEDummy"><input message="tns:FEDummySoapIn"/><output message="tns:FEDummySoapOut"/></operation>
    <operation name="FECompUltimoAutorizado"><input message="tns:FECompUltimoAutorizadoSoapIn"/><output message="tns:FECompUltimoAutorizadoSoapOut"/></operation>
    <operation name="FECAESolicitar"><input message="tns:FECAESolicitarSoapIn"/><output message="tns:FECAESolicitarSoapOut"/></operation>
  </portType>
  <binding name="ServiceSoap" type="tns:ServiceSoap">
    <soap:binding transport="http://schemas.xmlsoap.org/soap/http"/>
    <operation name="FEDummy"><soap:operation soapAction="http://ar.gov.afip.dif.FEV1/FEDummy" style="document"/><input><soap:body use="literal"/></input><output><soap:body use="literal"/></output></operation>
    <operation name="FECompUltimoAutorizado"><soap:operation soapAction="http://ar.gov.afip.dif.FEV1/FECompUltimoAutorizado" style="document"/><input><soap:body use="literal"/></input><output><soap:body use="literal"/></output></operation>
    <operation name="FECAESolicitar"><soap:operation soapAction="http://ar.gov.afip.dif.FEV1/FECAESolicitar" style="document"/><input><soap:body use="literal"/></input><output><soap:body use="literal"/></output></operation>
  </binding>
  <service name="Service">
    <port name="ServiceSoap" binding="tns:ServiceSoap">
      <soap:address location="' . $location . '"/>
    </port>
  </service>
</definitions>';
        
        return $wsdl;
    }
    
    /**
     * Obtener auth para llamadas
     */
    private function get_auth() {
        $ticket = $this->wsaa->get_ticket();
        
        return array(
            'Token' => $ticket['token'],
            'Sign' => $ticket['sign'],
            'Cuit' => $this->cuit
        );
    }
    
    /**
     * Obtener último comprobante autorizado
     */
    public function get_ultimo_comprobante($tipo_comprobante) {
        $this->init_client();
        
        try {
            $response = $this->client->FECompUltimoAutorizado(array(
                'Auth' => $this->get_auth(),
                'PtoVta' => $this->punto_venta,
                'CbteTipo' => $tipo_comprobante
            ));
            
            $result = $response->FECompUltimoAutorizadoResult;
            
            if (isset($result->Errors)) {
                throw new Exception($result->Errors->Err->Msg);
            }
            
            return intval($result->CbteNro);
            
        } catch (Exception $e) {
            $this->logger->error('Error obteniendo último comprobante: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Emitir factura
     */
    public function emitir_factura($datos) {
        $this->init_client();
        
        try {
            // Obtener próximo número
            $ultimo_nro = $this->get_ultimo_comprobante($datos['tipo_comprobante']);
            $nuevo_nro = $ultimo_nro + 1;
            
            // Fecha de comprobante
            $fecha = date('Ymd');
            
            // Determinar tipo y número de documento
            $tipo_doc = self::DOC_SIN_IDENTIFICAR;
            $nro_doc = 0;
            
            if (!empty($datos['cuit'])) {
                $tipo_doc = self::DOC_CUIT;
                $nro_doc = intval(str_replace('-', '', $datos['cuit']));
            } elseif (!empty($datos['dni'])) {
                $tipo_doc = self::DOC_DNI;
                $nro_doc = intval($datos['dni']);
            }
            
            // Construir request
            $fe_cab_req = array(
                'CantReg' => 1,
                'PtoVta' => $this->punto_venta,
                'CbteTipo' => $datos['tipo_comprobante']
            );
            
            $fe_det_req = array(
                'FECAEDetRequest' => array(
                    'Concepto' => isset($datos['concepto']) ? $datos['concepto'] : 2, // Servicios
                    'DocTipo' => $tipo_doc,
                    'DocNro' => $nro_doc,
                    'CbteDesde' => $nuevo_nro,
                    'CbteHasta' => $nuevo_nro,
                    'CbteFch' => $fecha,
                    'ImpTotal' => number_format($datos['importe_total'], 2, '.', ''),
                    'ImpTotConc' => '0.00',
                    'ImpNeto' => number_format($datos['importe_neto'], 2, '.', ''),
                    'ImpOpEx' => '0.00',
                    'ImpIVA' => number_format($datos['importe_iva'], 2, '.', ''),
                    'ImpTrib' => '0.00',
                    'MonId' => 'PES',
                    'MonCotiz' => 1
                )
            );
            
            // Agregar fechas de servicio si aplica
            if ($datos['concepto'] == 2 || $datos['concepto'] == 3) {
                $fe_det_req['FECAEDetRequest']['FchServDesde'] = $fecha;
                $fe_det_req['FECAEDetRequest']['FchServHasta'] = $fecha;
                $fe_det_req['FECAEDetRequest']['FchVtoPago'] = $fecha;
            }
            
            // Agregar array de IVA siempre que haya importe neto
            if ($datos['importe_neto'] > 0) {
                $fe_det_req['FECAEDetRequest']['Iva'] = array(
                    'AlicIva' => array(
                        'Id' => 5, // 21%
                        'BaseImp' => number_format($datos['importe_neto'], 2, '.', ''),
                        'Importe' => number_format($datos['importe_iva'], 2, '.', '')
                    )
                );
            }
            
            $this->logger->info('Enviando factura a AFIP', null, array(
                'FeCabReq' => $fe_cab_req,
                'FeDetReq' => $fe_det_req
            ));
            
            // Llamar a WSFE
            $response = $this->client->FECAESolicitar(array(
                'Auth' => $this->get_auth(),
                'FeCAEReq' => array(
                    'FeCabReq' => $fe_cab_req,
                    'FeDetReq' => $fe_det_req
                )
            ));
            
            $result = $response->FECAESolicitarResult;
            
            // Verificar errores
            if (isset($result->Errors)) {
                $error_msg = is_array($result->Errors->Err) 
                    ? $result->Errors->Err[0]->Msg 
                    : $result->Errors->Err->Msg;
                throw new Exception('Error AFIP: ' . $error_msg);
            }
            
            // Obtener respuesta del comprobante
            $det_response = $result->FeDetResp->FECAEDetResponse;
            
            if ($det_response->Resultado !== 'A') {
                $obs = '';
                if (isset($det_response->Observaciones)) {
                    $obs = is_array($det_response->Observaciones->Obs)
                        ? $det_response->Observaciones->Obs[0]->Msg
                        : $det_response->Observaciones->Obs->Msg;
                }
                throw new Exception('Factura rechazada: ' . $obs);
            }
            
            $this->logger->info('Factura emitida exitosamente. CAE: ' . $det_response->CAE);
            
            return array(
                'exito' => true,
                'punto_venta' => $this->punto_venta,
                'numero_comprobante' => $nuevo_nro,
                'tipo_comprobante' => $datos['tipo_comprobante'],
                'cae' => $det_response->CAE,
                'cae_fecha_vto' => $this->parse_fecha($det_response->CAEFchVto),
                'fecha_emision' => current_time('mysql'),
                'importe_total' => $datos['importe_total']
            );
            
        } catch (Exception $e) {
            $this->logger->error('Error emitiendo factura: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Consultar comprobante
     */
    public function consultar_comprobante($tipo_comprobante, $numero_comprobante) {
        $this->init_client();
        
        try {
            $response = $this->client->FECompConsultar(array(
                'Auth' => $this->get_auth(),
                'FeCompConsReq' => array(
                    'CbteTipo' => $tipo_comprobante,
                    'CbteNro' => $numero_comprobante,
                    'PtoVta' => $this->punto_venta
                )
            ));
            
            $result = $response->FECompConsultarResult;
            
            if (isset($result->Errors)) {
                throw new Exception($result->Errors->Err->Msg);
            }
            
            return $result->ResultGet;
            
        } catch (Exception $e) {
            $this->logger->error('Error consultando comprobante: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Obtener tipos de comprobante habilitados
     */
    public function get_tipos_comprobante() {
        $this->init_client();
        
        $response = $this->client->FEParamGetTiposCbte(array(
            'Auth' => $this->get_auth()
        ));
        
        return $response->FEParamGetTiposCbteResult->ResultGet->CbteTipo;
    }
    
    /**
     * Obtener puntos de venta habilitados
     */
    public function get_puntos_venta() {
        $this->init_client();
        
        $response = $this->client->FEParamGetPtosVenta(array(
            'Auth' => $this->get_auth()
        ));
        
        $result = $response->FEParamGetPtosVentaResult;
        
        if (isset($result->ResultGet) && isset($result->ResultGet->PtoVenta)) {
            return $result->ResultGet->PtoVenta;
        }
        
        return array();
    }
    
    /**
     * Verificar estado del servicio
     */
    public function server_status() {
        try {
            $this->init_client();
            $response = $this->client->FEDummy();
            
            return array(
                'success' => true,
                'app_server' => $response->FEDummyResult->AppServer,
                'db_server' => $response->FEDummyResult->DbServer,
                'auth_server' => $response->FEDummyResult->AuthServer
            );
        } catch (SoapFault $e) {
            $message = $e->getMessage();
            
            // Detectar errores comunes
            if (stripos($message, 'Service Unavailable') !== false || stripos($message, '503') !== false) {
                return array(
                    'success' => false,
                    'error' => 'Los servidores de AFIP están temporalmente no disponibles. Por favor, intentá de nuevo en unos minutos.',
                    'app_server' => 'No disponible',
                    'db_server' => 'No disponible',
                    'auth_server' => 'No disponible'
                );
            }
            
            if (stripos($message, 'Could not connect') !== false || stripos($message, 'Connection') !== false) {
                return array(
                    'success' => false,
                    'error' => 'No se pudo conectar con AFIP. Verificá tu conexión a internet.',
                    'app_server' => 'Error',
                    'db_server' => 'Error',
                    'auth_server' => 'Error'
                );
            }
            
            return array(
                'success' => false,
                'error' => 'Error SOAP: ' . $message,
                'app_server' => 'Error',
                'db_server' => 'Error',
                'auth_server' => 'Error'
            );
        } catch (Exception $e) {
            return array(
                'success' => false,
                'error' => $e->getMessage(),
                'app_server' => 'Error',
                'db_server' => 'Error',
                'auth_server' => 'Error'
            );
        }
    }
    
    /**
     * Parsear fecha de formato YYYYMMDD
     */
    private function parse_fecha($fecha_str) {
        if (!$fecha_str) {
            return null;
        }
        
        $year = substr($fecha_str, 0, 4);
        $month = substr($fecha_str, 4, 2);
        $day = substr($fecha_str, 6, 2);
        
        return "$year-$month-$day";
    }
    
    /**
     * Determinar tipo de factura según condición del emisor y receptor
     */
    public static function determinar_tipo_factura($condicion_emisor, $condicion_receptor) {
        // Monotributista -> Factura C
        if ($condicion_emisor == 'monotributo') {
            return self::FACTURA_C;
        }
        
        // Responsable Inscripto
        if ($condicion_emisor == 'responsable_inscripto') {
            // Si el receptor es RI -> Factura A
            if ($condicion_receptor == self::IVA_RESPONSABLE_INSCRIPTO) {
                return self::FACTURA_A;
            }
            // Para todos los demás -> Factura B
            return self::FACTURA_B;
        }
        
        // Por defecto -> Factura B
        return self::FACTURA_B;
    }
    
    /**
     * Obtener letra del comprobante
     */
    public static function get_letra_comprobante($tipo) {
        $letras = array(
            self::FACTURA_A => 'A',
            self::FACTURA_B => 'B',
            self::FACTURA_C => 'C',
            self::NOTA_CREDITO_A => 'A',
            self::NOTA_CREDITO_B => 'B',
            self::NOTA_CREDITO_C => 'C'
        );
        
        return isset($letras[$tipo]) ? $letras[$tipo] : 'B';
    }
}
